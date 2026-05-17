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
| `location_id` | `varchar` **UNIQUE** | Partner-supplied business key (`"LOC-001"`) |
| `name` | `varchar` | Upserted on each import (partners can rename) |
| `created_at` / `updated_at` | timestamps | |

### `revenue_records` (the fact table)

One row per **(machine, calendar day)** — the natural grain of the feed.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint PK` | Surrogate |
| `location_id` | `bigint FK → locations.id` | |
| `machine_id` | `varchar` | Partner string (`"VGT-1001"`). No `machines` table yet — see below. |
| `report_date` | `date` | Day-grain; partner timezone normalized to a single canonical TZ at ingest. |
| `cash_in`, `voucher_in`, `voucher_out`, `net_revenue` | `decimal(12, 2)` | **Never floats.** See "Money handling" below. |
| `source_batch_id` | `bigint FK → revenue_import_batches.id`, nullable | Traceability back to the import that wrote this row. |
| `created_at` / `updated_at` | timestamps | |

**Constraints & indexes:**

- **`UNIQUE (machine_id, report_date)`** — the **idempotency key**. This is the single most important constraint in the schema.
- `INDEX (location_id, report_date)` — reconciliation rollups, dashboard date-range queries.
- `INDEX (report_date)` — global daily aggregates.

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
| `error_payload` | `json`, nullable | Validation errors if rejected |
| `created_at` / `updated_at` | timestamps | |

### Why no `machines` table (yet)

The payload exposes `machine_id` as a stable string with no machine-level metadata in scope. A separate `machines` table earns its place when we need machine lifecycle (install/decom dates), service history, or cross-location moves. Until then it's a join with no payload — **YAGNI**. Promoting `machine_id` to a FK later is a straightforward backfill since the string is already stable across rows.

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

Each incoming row resolves via **`updateOrCreate` keyed on `(machine_id, report_date)`**:

- **No matching row** → insert → counted as `imported`.
- **Matching row, attributes changed** → update → counted as `updated`.
- **Matching row, no attribute drift** → no-op → counted as `skipped`.

This gives the required response shape (`{ imported, updated, skipped, errors }`) and makes the **second import of an identical payload land entirely in `skipped` with zero writes** — which is exactly what an operator should see when they wonder "did I already run this?"

Why not a bulk `INSERT ... ON DUPLICATE KEY UPDATE`? It's faster but loses the imported-vs-updated distinction the API contract requires. At MVP scale (200 locations × ~5 machines × 1 file/day) the row-by-row cost is negligible. Bulk upsert becomes worth the API redesign at 10k+ rows per file — not today.

### Layer 3 — Batch dedup (optimization)

Compute `SHA-256` of the canonical payload. If `revenue_import_batches.payload_hash` already exists with status `committed`, return the stored summary without touching `revenue_records` at all. This is the cheap win for the common "partner clicked submit twice" case.

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

**Single SQL** does the work — no per-row loops in PHP:

```sql
SELECT
  e.location_id,
  e.report_date,
  e.expected_net_revenue AS expected,
  COALESCE(SUM(r.net_revenue), 0) AS actual,
  COALESCE(SUM(r.net_revenue), 0) - e.expected_net_revenue AS diff
FROM expected_totals e
LEFT JOIN revenue_records r
  ON r.location_id = e.location_id
 AND r.report_date  = e.report_date
GROUP BY e.location_id, e.report_date, e.expected_net_revenue;
```

`status` is derived in PHP with a configurable **tolerance** (default `$0.00` — exact match for financial data; can be loosened per-tenant later). `LEFT JOIN` ensures locations with expectations but no imports surface as `missing_actual` rather than being silently absent. A symmetric query (or `FULL OUTER` simulated via `UNION`) catches the inverse: imports with no expected baseline → `missing_expected`. Both are signals operators care about.

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

---

## Where I Used AI

This document was drafted with Claude (Opus) using the provided assignment and sample fixtures as input. Decisions — the three-layer idempotency model, the verbatim-`net_revenue` audit posture, the deferred `machines` table, the "scale by scheduling, not by schema" framing — are mine, sharpened in dialogue with the model. The same model will scaffold migrations and request validation in Part B; the idempotency contract and reconciliation query are the hand-written load-bearing pieces.
