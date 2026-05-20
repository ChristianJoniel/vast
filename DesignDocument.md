# Route Revenue Reconciliation Engine — Design Document

**Part A · Vast Staff Software Engineer Technical Assessment**
Author: Christian Negron · Date: 2026-05-16

---

## Context

Vast ingests **nightly revenue files from 200+ location partners**. Each file is a list of per-machine daily totals (`cash_in`, `voucher_in`, `voucher_out`, `net_revenue`, `report_date`). The data feeds operator dashboards and downstream financial reporting, so **incorrect or duplicated revenue is not a UX bug — it's a business-decision bug**.

Operational reality:

- Partners **resend** files (timezone confusion, "fixed a typo," manual re-runs).
- Background jobs **fail mid-batch and retry**.
- Many partners **submit concurrently** at the nightly window.
- The footprint goes from ~200 → ~1,000+ locations within a year.
- A future **AI call center** and **retail OS** will share the same financial core.

The design below optimizes for **financial correctness first**, **operational debuggability second**, and **scaling headroom third** — in that order. Trade-offs that buy speed at the cost of provable correctness are deferred until they're earned by measurement.

The provided fixtures (`sample_import.json`, `expected_totals.json`) drive Part B; `LOC-002 / 2026-03-01` carries an intentional **$79 discrepancy** against expected totals to exercise reconciliation.

---

## 1. Data Model

Three tables. Small surface area, hard guarantees in the DB.

### `locations`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | Surrogate |
| `code` | `varchar` **UNIQUE** | Partner-supplied business key (`"LOC-001"`) |
| `name` | `varchar` | Upserted on each import (partners can rename) |
| `created_at` / `updated_at` | timestamps | |

### `machines`

A machine is a first-class entity so that revenue facts can only attach to hardware the system knows about.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | Surrogate |
| `location_id` | `bigint FK → locations.id` | Machine's current location. Upserted on every import — see "Machine relocation" below. |
| `code` | `varchar` **UNIQUE** | Partner-supplied identifier (`"VGT-1001"`). Globally unique across the operator network — a machine retains its identity if it physically moves between locations. |
| `created_at` / `updated_at` | timestamps | |

### `revenue_records` (the fact table)

One row per **(machine, calendar day)** — the natural grain of the feed.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | Surrogate |
| `machine_id` | `bigint FK → machines.id` | FK enforces that the machine exists before any revenue can be booked against it. The location is resolved through `machines.location_id` (see relocation note for the trade-off). |
| `report_date` | `date` | Day-grain; partner timezone normalized to a single canonical TZ at ingest. |
| `cash_in`, `voucher_in`, `voucher_out`, `net_revenue` | `decimal(12, 2)` | **Never floats.** See "Money handling" below. |
| `source_batch_id` | `bigint FK → revenue_import_batches.id`, nullable | Traceability back to the import that wrote this row. Also load-bearing for the Layer 3 cache-integrity check — see §2. |
| `created_at` / `updated_at` | timestamps | |

**Constraints & indexes:**

- **`UNIQUE (machine_id, report_date)`** — the **idempotency key**. This is the single most important constraint in the schema and the only one shipped on this table.
- Supporting indexes — `(report_date)` for daily aggregates and a composite for dashboard date-range queries — are noted in §5 as "add when query latency demands it." MVP scale (200 locations × ~5 machines × 1 file/day) doesn't justify them yet.

### `expected_totals`

Per-location, per-day expected net revenue baseline (operator-loaded, e.g. from contract terms or partner attestations).

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | |
| `location_id` | `bigint FK → locations.id` | |
| `report_date` | `date` | |
| `expected_net_revenue` | `decimal(12, 2)` | |
| `notes` | `text`, nullable | Operator context (matches the JSON fixture) |
| `created_at` / `updated_at` | timestamps | |

- **`UNIQUE (location_id, report_date)`** — one expected number per location-day.

### `revenue_import_batches` (operational metadata)

Not strictly required for MVP correctness, but cheap to add and pays for itself the first time an import goes sideways.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | |
| `payload_hash` | `char(64)` **UNIQUE** | SHA-256 of the canonical JSON body. Lets us short-circuit byte-identical resubmits without re-parsing. |
| `status` | enum `pending / committed / rejected` | |
| `record_count` | `int` | |
| `imported_count`, `updated_count`, `skipped_count` | `int` | Stored response summary |
| `new_machines_count` | `int` | Count of previously-unseen machine codes registered during this batch — operator review signal, kept off the API response (assignment fixes that contract). |
| `error_payload` | `json`, nullable | Validation errors if rejected |
| `created_at` / `updated_at` | timestamps | |

### Why a `machines` table

Modeling machines as first-class entities — rather than letting `machine_id` live as a free-form string on the fact table — pays for itself on day one:

1. **Existence validation.** `revenue_records.machine_id` is a FK; you cannot book revenue against a machine the system doesn't know about. Previously-unseen machine codes from a partner payload surface as an explicit ingest-time signal (counted on `revenue_import_batches.new_machines_count`), not as silent string drift in the fact table.
2. **Stable lifecycle anchor.** Future needs — install/decom dates, service history, cross-location moves, telemetry joins — attach cleanly to `machines.id`. The fact table never has to be backfilled to add them.

For the MVP, new machines are **upserted on first sight** during import (same pattern as locations) and surfaced on the batch record for operator review. Tightening the policy to "machines must be pre-registered, unknown codes reject the row" is a one-line change later if partners are expected to register hardware out-of-band.

#### Machine relocation — a known sharp edge

The MVP also **silently overwrites `machines.location_id`** when a partner payload reports a known machine code at a different location than we previously saw. This is the simplest behavior — partner data is treated as authoritative for dimension attributes — but it has a real failure mode: a typo in `location_id` on a single row will silently reparent a machine and quietly contaminate future location-level reconciliation rollups.

The defensible behaviors are a product call, not a code call:

- **Authoritative-overwrite (current MVP)** — fastest, smallest code, assumes partner systems are correct.
- **Flag-and-quarantine** — detect that `machine.location_id` changed, write the new fact, but raise a relocation event on the batch (or a separate `machine_movements` audit table) for operator review before subsequent reports are trusted.
- **Reject-on-conflict** — refuse the row if the (machine, location) pairing changed; require an explicit "relocate machine" admin action.

Picking among these depends on how partners actually communicate physical machine moves (out-of-band ticket? next-day payload? never?) — a question for product, not for the importer. The schema already supports any of the three; only the Action's upsert branch needs to change.

The downstream consequence shows up at **reconciliation** time: the reconcile query joins `revenue_records → machines → locations` via the machine's *current* `location_id`. A relocated machine retroactively re-attributes its historical revenue to the new location — wrong in a strict accounting sense, because the revenue was physically earned at the prior site. Two fixes available, both post-MVP:

- **Denormalize `location_id` onto `revenue_records` at write time** — the importer stamps the machine's location as of `report_date` directly on each fact row. Reconcile then joins on that stable column, immune to subsequent moves.
- **Maintain a `machine_movements` audit table** — `(machine_id, location_id, effective_from, effective_to)` history; reconcile resolves the location per record at query time.

Both are gated on whether physical machine moves are a real product event in the first place. The MVP joins through `machines.location_id` and accepts the retroactive-attribution behavior as the documented contract.

### Money handling

- All monetary columns are `decimal(12, 2)`. No floats anywhere — not in the DB, not in PHP arithmetic, not in API responses (serialize as strings if the consumer is JS-native).
- **The importer persists `net_revenue` verbatim from the partner**, even if `cash_in - voucher_out + voucher_in` disagrees. Silent recomputation destroys audit trails ("what did the partner actually claim?"). Discrepancies surface in reconciliation, not in ingest.

---

## 2. Idempotency Strategy

Idempotency is enforced at **three layers**, deliberately overlapping. The DB layer is the only one that can't be bypassed by a bug; the others exist for UX and performance.

### Layer 1 — Database (the hard guarantee)

**`UNIQUE (machine_id, report_date)` on `revenue_records`.**

This is the contract. Two workers racing on the same payload, a job that retried after a partial write, a partner that resent the file three times — none of them can create duplicate facts. If the application layer regresses tomorrow, the DB still holds the line.

### Layer 2 — Application (UX & determinism)

Each row is resolved in two steps. First, **machine lookup** — `updateOrCreate` keyed on `(location_id, machine_code)`; first-sighted machine codes increment `new_machines_count` on the batch for operator review. Then the **fact** resolves via **`updateOrCreate` keyed on `(machine_id, report_date)`**:

- **No matching row** → insert → counted as `imported`.
- **Matching row, attributes changed** → update → counted as `updated`.
- **Matching row, no attribute drift** → no-op → counted as `skipped`.

This gives the required response shape (`{ imported, updated, skipped, errors }`) and makes the **second import of an identical payload land entirely in `skipped` with zero writes** — which is exactly what an operator should see when they wonder "did I already run this?"

Why not a bulk `INSERT ... ON DUPLICATE KEY UPDATE`? It's faster but loses the imported-vs-updated distinction the API contract requires. At MVP scale (200 locations × ~5 machines × 1 file/day) the row-by-row cost is negligible. Bulk upsert becomes worth the API redesign at 10k+ rows per file — not today.

### Layer 3 — Batch dedup (optimization)

Compute `SHA-256` of the canonical payload. If `revenue_import_batches.payload_hash` already exists with status `committed` **and** every record that batch wrote still attributes back to it (`source_batch_id` count matches `record_count`), return the stored summary without touching `revenue_records` at all. This is the cheap win for the common "partner clicked submit twice" case.

The state-integrity check matters because `updateOrCreate` resets `source_batch_id` on every row it touches. A later import that mutated even one record from the cached batch would have changed that record's `source_batch_id` — invalidating the cached counters' claim about current DB state. Without the check, replaying the original payload after a corrective edit would silently return stale counts and skip the work needed to restore the cached state. With it, the cache safely falls through to the per-row path. The fast path stays correct; the safety net stays load-bearing.

### What about cell-level edits (same payload, corrected `net_revenue`)?

That's a legitimate update, not a duplicate. The payload hash differs, the per-row hash differs, `updateOrCreate` writes the new value, the response shows `updated: N`. The audit trail (via `source_batch_id`) tells us which batch supplied the current value.

---

## 3. Reconciliation Approach

Expected totals are **data, not config** — operators revise baselines without redeploying. They live in `expected_totals` (seeded from `expected_totals.json` for the assessment).

`GET /api/revenue/reconcile` returns one row per `(location_id, report_date)` with the four numbers operators actually need:

```json
{
  "location_id": "LOC-002",
  "report_date": "2026-03-01",
  "expected": "3100.00",
  "actual": "3021.00",
  "diff": "-79.00",
  "status": "mismatch"
}
```

**Single SQL** does the work — no per-row loops in PHP. Because `revenue_records` carries `machine_id` (not a denormalized `location_id`), the JOIN goes through `machines`:

```sql
SELECT
  l.code                 AS location_id,
  e.report_date,
  e.expected_net_revenue AS expected,
  SUM(r.net_revenue)     AS actual,
  COUNT(r.id)            AS record_count
FROM expected_totals e
JOIN locations l         ON l.id = e.location_id
LEFT JOIN machines m     ON m.location_id = e.location_id
LEFT JOIN revenue_records r
  ON r.machine_id = m.id
 AND r.report_date = e.report_date
GROUP BY l.code, e.report_date, e.expected_net_revenue;
```

`status` is derived in PHP with `bcmath` arithmetic (decimal strings, never floats) and a configurable **tolerance** (default `$0.00` — exact match for financial data; can be loosened per-tenant later). `SUM(r.net_revenue)` is intentionally not wrapped in `COALESCE` so `NULL` cleanly distinguishes "no records exist" (`missing_actual`) from "records sum to zero" (`match` if expected is also zero). A symmetric query (or `FULL OUTER` simulated via `UNION ALL`) catches the inverse: imports with no expected baseline → `missing_expected`. Both are signals operators care about.

One assumption is baked into this JOIN: it resolves a machine's location to its *current* `location_id`, not the location at `report_date`. See §1's "Machine relocation" note — a machine that physically moves retroactively re-attributes its historical revenue at reconcile time. Fixing that is a post-MVP migration gated on whether relocation is a real product event.

### Why this shape

- **Set-based**, not row-by-row — same query whether it's 200 or 200,000 location-days.
- **Stateless** — recomputed on each request. Reconciliation is a *view* over facts, not a stored result. We're not denormalizing variance until query latency proves we need to.
- **Deterministic** — given the same facts and expectations, the answer is identical across requests, regions, and replicas.

---

## 4. Error Handling & Recovery

### MVP: one transaction per import

The endpoint wraps the entire batch in a single DB transaction. Validation runs first (in `FormRequest`), then row-by-row `updateOrCreate` inside the transaction:

- **Validation failure** → 422, nothing reached the DB. Partner's prior state intact.
- **Mid-batch DB failure** (record 15 of 20) → transaction rolls back, **all-or-nothing**, prior state intact. The retry of the same payload completes successfully and — because of idempotent keys — produces the same final state regardless of how many times it retried.
- **Hash-matched resubmit** → short-circuits at Layer 3, never opens a transaction.

This deliberately answers the assessment's "record 15 of 20 fails" prompt with **integrity over throughput**. Partial commits leave operators in a worse position than total rollback: they have to reason about *which* rows are "real" for the day, which gets very hard when concurrent resubmits overlap. The MVP picks the option where the worst outcome is "retry the import."

### Recovery posture

- **Idempotent retries** are first-class. Any failed job re-runs the same `POST` with no special "resume" path needed.
- **`revenue_import_batches`** captures every attempt (pending/rejected too), so an operator can answer "did this file ever land?" from a single table.
- **Logging discipline**: every rejected row carries `{ index, partner_payload, validation_errors }` in the `errors` array of the response and in `error_payload` on the batch record. No silent drops.

### Where this evolves (post-MVP)

When batches grow past comfortable single-transaction size (10k+ rows, multiple seconds), split into **per-location sub-transactions** so a single bad partner doesn't block the rest of the night. The natural key still holds; only the failure-isolation boundary changes.

---

## 5. Scaling Considerations (200 → 1,000+ locations)

The current design carries from 200 to 1,000+ on the same data model. The shifts are in **how work is scheduled** and **where reads land**, not the schema.

### Do now (cheap, prevents future pain)

- **Composite unique key + supporting indexes** as specified above.
- **`decimal` throughout** — fixing precision later is migration hell.
- **`source_batch_id` on facts** — costs one column today, makes the first incident triagable.
- **One Pest test that imports the same payload twice and asserts identical state.** Single most valuable test in the suite.

### Defer until measurement justifies it

| Change | Trigger to add it |
|---|---|
| **Async ingestion via queued jobs** (`ProcessRevenueImport`) | Files routinely exceed ~2s synchronous processing, or partners need 202-Accepted semantics |
| **Per-location transaction boundaries** | Single-transaction commits exceed ~5s lock window or block concurrent partners |
| **Read replica for dashboard/reconcile** | Dashboard reads measurably impact writer p95 |
| **Materialized daily rollups** | Reconciliation query crosses ~500ms at p95 |
| **Partitioning `revenue_records` by `report_date`** | Table crosses ~50M rows; pruning old partitions becomes operationally cheaper than maintenance windows |
| **Webhooks / S3-drop ingestion** | Partners ask for it, or POST-based ingestion can't keep up with peak fan-in |

The point is: **most of these are unnecessary until they're necessary**, and adding them speculatively poisons the architecture with abstractions that don't pay rent. The schema and the unique key are the load-bearing decisions; almost everything else is reversible.

---

## 6. Multi-Product Future (AI Call Center, Retail OS)

The financial core has to survive becoming a dependency of products that don't exist yet. Two principles:

### Treat revenue ingestion as a bounded context, not a shared module

Other products consume **canonical financial facts** through a **stable contract**, not by reaching into `revenue_records` directly. Concretely:

- A `FinancialFact` read API (or domain events emitted on commit: `RevenueRecordsImported`, `ReconciliationCompleted`) is the only seam other products use.
- Product-specific logic — "the AI call center wants weekly rollups by partner contact" — lives **in the consumer**, not in the ingest path. The ingest path stays narrowly focused on "facts arrived, facts reconciled."
- Versioned APIs and schemas. When the retail OS needs a richer fact shape, we version forward; we don't mutate the existing contract under the call center's feet.

### Centralize money policy once

Precision, rounding, currency, timezone normalization — one module owns these rules across all products. The day the retail OS introduces multi-currency or the call center starts surfacing dollar amounts to end-users, every product gets the same answer for "what does $1234.56 look like, rounded, in the partner's local TZ?" because there's only one implementation.

What I'd avoid:

- **Cross-product joins in SQL** — couples products at the DB layer, kills independent deploys.
- **A "shared services" PHP package** that every product imports and extends — turns into a god module within 18 months. Prefer event/API contracts.
- **Premature event sourcing or CQRS** — the ingest path is already idempotent and auditable via `source_batch_id`. Event-sourcing the financial core is a large lift; defer until a consuming product genuinely needs to replay history independently.

---

## Open Questions & Future Work

- **Currency / multi-currency** — out of scope today; `decimal(12,2)` + a future `currency_code` column covers it without schema rework.
- **Timezone canonicalization** — pick one (probably UTC at storage, partner-local at ingest validation) and document it loudly.
- **RBAC + immutable audit log** — once operator headcount grows, every mutation needs a `who/when/why`. The `source_batch_id` trail is the foundation, but doesn't yet record *which user* triggered the batch.
- **OpenAPI surface** — assignment-required eventually; cheap to add once endpoints stabilize.
- **Scheduled reconciliation runs** — push, don't pull. Operators get alerted on mismatches instead of having to remember to check.
- **Machine relocation policy** — see §1, "Machine relocation — a known sharp edge." MVP silently absorbs `machine.location_id` changes from partner payloads; product needs to decide whether physical machine moves should require an explicit admin action or stay implicit.

---

## Where I Used AI

This document was drafted with Claude (Opus) using the provided assignment and sample fixtures as input. Decisions — the three-layer idempotency model, the verbatim-`net_revenue` audit posture, the deferred `machines` table, the "scale by scheduling, not by schema" framing — are mine, sharpened in dialogue with the model. The same model will scaffold migrations and request validation in Part B; the idempotency contract and reconciliation query are the hand-written load-bearing pieces.
