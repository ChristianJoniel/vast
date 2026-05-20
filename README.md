# Route Revenue Reconciliation Engine

A Laravel implementation of the Vast Staff Software Engineer technical assessment. Processes nightly per-machine revenue payloads from location partners, persists them idempotently, and reconciles imported totals against operator-supplied expected baselines.

The full architectural rationale lives in [`DesignDocument.md`](./DesignDocument.md) (Part A). This README covers what's running and how to verify it.

---

## Stack

- **Backend:** Laravel 13, PHP 8.4
- **Database:** SQLite (dev), MySQL-compatible schema
- **Testing:** Pest 4
- **Linting:** Laravel Pint

Frontend: Inertia.js v3 + Vue 3 (Composition API) + Tailwind CSS v4. A real dashboard page at `/dashboard` (auth-gated) renders the revenue and reconciliation data via shadcn-vue charts (Unovis under the hood).

---

## Quick Start

```bash
# One-time setup
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh

# Optional: seed locations, machines, expected_totals, and a test user
php artisan db:seed

# Frontend assets (dev mode reloads on save)
npm install && npm run dev

# Serve the app (in a separate terminal)
php artisan serve
# → Server running on http://127.0.0.1:8000

# Run the test suite (66 tests, ~1.5s)
php artisan test --compact
```

The examples below assume **`http://127.0.0.1:8000`** (the default `php artisan serve` address). If you use [Laravel Herd](https://herd.laravel.com/) instead, the app is reachable at `http://vast.test` — substitute that host in the curl commands.

**Dashboard login** (auth-gated): `test@example.com` / `password` (created by `DatabaseSeeder`).

### Smoke test the endpoints

```bash
# Fresh DB
php artisan migrate:fresh

# Import the 14-row sample
curl -sS -X POST http://127.0.0.1:8000/api/revenue/import \
  -H 'Content-Type: application/json' \
  --data @sample_import.json
# → {"imported":14,"updated":0,"skipped":0,"errors":[]}

# Seed expected_totals (10 rows for reconciliation)
php artisan db:seed --class=ExpectedTotalSeeder

# Reconcile
curl -sS http://127.0.0.1:8000/api/revenue/reconcile | python3 -m json.tool
# → 10 rows; LOC-002 / 2026-03-01 shows status: "mismatch", diff: "-79.00"

# Dashboard (aggregated KPIs + per-location daily + full reconciliation in one payload)
curl -sS http://127.0.0.1:8000/api/revenue/dashboard | python3 -m json.tool
# → { totals: {...}, daily_by_location: [...], reconciliation: [...] }
```

For the visual dashboard, sign in at `http://127.0.0.1:8000` with `test@example.com` / `password` and navigate to `/dashboard`.

---

## What's Built

Assignment requirements:

- **`POST /api/revenue/import`** — validates, upserts locations/machines/records, returns the required `{ imported, updated, skipped, errors }` summary.
- **Idempotency** — DB unique constraint + app-level `updateOrCreate` with `wasChanged` discrimination + SHA-256 payload-hash short-circuit for replays.
- **At least one Pest test proving idempotency** — actually 3 tests in `tests/Feature/Revenue/ImportRevenueTest.php` (fresh, idempotent replay, money-field update vs skip).
- **`GET /api/revenue/reconcile`** *(optional #1)* — surfaces the intentional `LOC-002 / 2026-03-01` $79 mismatch from `expected_totals.json`. Covered by 4 Pest tests including `missing_actual` / `missing_expected` edge cases.
- **`GET /api/revenue/dashboard`** *(optional #2)* — aggregated KPI totals + per-location daily revenue + full reconciliation in one payload. Backed by a `BuildDashboard` action that composes the existing `ReconcileRevenue` action. 3 Pest tests.
- **Vue frontend** *(optional #3)* — `/dashboard` page consumes the same `BuildDashboard` action via Inertia props. Renders a KPI stat row + reconciliation-variance grouped bar chart + per-location revenue bar chart via shadcn-vue chart components (Unovis-backed).

---

## Architecture

```
Browser/Partner
    │
    ▼
POST /api/revenue/import ─► ImportRevenueRequest (FormRequest validation)
                              │
                              ▼
                          RevenueImportController
                              │
                              ▼
                          ImportRevenuePayload (Action — single DB transaction)
                              │
                              ├── SHA-256 hash → cached COMMITTED batch? short-circuit
                              ├── firstOrCreate RevenueImportBatch (PENDING)
                              ├── per-row: upsert Location → Machine → RevenueRecord
                              └── batch.update(counters, status=COMMITTED)

GET /api/revenue/reconcile ─► RevenueReconcileController
                              │
                              ▼
                          ReconcileRevenue (Action — single SQL with UNION ALL)
                              │
                              └── derive status (match / mismatch / missing_*) in PHP via bcmath

GET /api/revenue/dashboard ──┐
                             ▼
GET /dashboard ──► DashboardController (Inertia) ──► BuildDashboard (Action)
(auth+verified)                                       │
                                                      ├── ReconcileRevenue::execute()  (reused)
                                                      ├── dailyByLocation()            (new SQL)
                                                      └── totalsFrom(reconciliation)   (bcmath)
```

Five tables: `locations`, `machines`, `revenue_records`, `expected_totals`, `revenue_import_batches`. All FK-constrained. See the design doc §1 for the schema rationale.

---

## API Contract

### `POST /api/revenue/import`

Accepts the exact shape of `sample_import.json` — a bare JSON array of records.

```json
[
  {
    "location_id": "LOC-001",
    "location_name": "Lucky's Tavern",
    "machine_id": "VGT-1001",
    "cash_in": 1280.50,
    "voucher_in": 245.00,
    "voucher_out": 560.00,
    "net_revenue": 965.50,
    "report_date": "2026-03-01"
  }
]
```

**Response (200):**

```json
{ "imported": 14, "updated": 0, "skipped": 0, "errors": [] }
```

**Validation failure (422):** `errors` keyed by `0.field_name` per offending row.

### `GET /api/revenue/reconcile`

No request body, no query params.

**Response (200):** bare array, one row per `(location, report_date)`.

```json
[
  {
    "location_id": "LOC-002",
    "report_date": "2026-03-01",
    "expected": "3100.00",
    "actual": "3021.00",
    "diff": "-79.00",
    "status": "mismatch"
  }
]
```

All money values are decimal strings to avoid JS float precision drift. `status` values: `match`, `mismatch`, `missing_actual`, `missing_expected`.

### `GET /api/revenue/dashboard`

No request body, no query params. Returns the full operator dashboard payload — KPI totals, per-location daily aggregates, and the reconciliation rollup — in a single call.

**Response (200):**

```json
{
  "totals": {
    "imported": "15502.00",
    "expected": "15581.00",
    "diff": "-79.00",
    "mismatches_count": 1
  },
  "daily_by_location": [
    { "location_id": "LOC-001", "report_date": "2026-03-01", "net_revenue": "1665.25" }
  ],
  "reconciliation": [
    { "location_id": "LOC-002", "report_date": "2026-03-01",
      "expected": "3100.00", "actual": "3021.00", "diff": "-79.00",
      "status": "mismatch" }
  ]
}
```

The same payload is also passed as Inertia props to the `/dashboard` Vue page (`DashboardController` and `RevenueDashboardController` both call `BuildDashboard::execute()`).

---

## Key Design Decisions

**Three-layer idempotency** (defense in depth):
1. **DB:** `UNIQUE (machine_id, report_date)` on `revenue_records` — the unbypassable guarantee.
2. **App:** `updateOrCreate` + `wasChanged([cash_in, voucher_in, voucher_out, net_revenue])` cleanly separates `imported` / `updated` / `skipped`.
3. **Hash:** SHA-256 of the validated payload short-circuits byte-identical replays against a prior COMMITTED batch.

The hash is a best-effort fast path — semantically equivalent payloads with reordered keys or different decimal precision miss the cache and fall through to the per-row layer, which is the real contract.

**One transaction per import.** The full pipeline runs inside `DB::transaction(...)`. Any failure rolls back the batch row and every record write — answers the assignment's "record 15 of 20 fails" scenario with integrity over throughput.

**Money is `decimal(12,2)` everywhere.** No floats in the DB, no floats in arithmetic (reconciliation uses `bcmath`), no floats in API responses (serialized as strings).

**`net_revenue` is persisted verbatim** even when it disagrees with `cash_in + voucher_in - voucher_out`. Silent recomputation would destroy the audit trail of what the partner actually claimed. Discrepancies surface in reconciliation, not at ingest.

**Scale by scheduling, not by schema.** The same data model carries from today's 200 locations to the assignment's 12-month target of 1,000+. The load-bearing decisions — `UNIQUE (machine_id, report_date)`, `decimal(12,2)`, `source_batch_id` audit trail — don't get bigger when partner count grows. What evolves is *how* work is scheduled (sync `POST` → queued job) and *where* reads land (writer DB → read replica). Speculative scaling abstractions (materialized rollups, partitioning, async ingestion) are deferred until measurement justifies them. Design doc §5 has the full trigger table.

**Machine relocation is a documented sharp edge.** The MVP silently overwrites `machines.location_id` when a partner reports a known machine at a different location. Three defensible policies (authoritative-overwrite / flag-and-quarantine / reject-on-conflict) are spelled out in the design doc §1; picking among them is a product call, not a code call.

---

## Testing

```bash
php artisan test --compact            # all 66 tests
php artisan test --compact --filter=ImportRevenueTest
php artisan test --compact --filter=ReconcileRevenueTest
php artisan test --compact --filter=RevenueDashboardTest
```

**Idempotency coverage:**
- Fresh import → 14 imported, all dimensions created, batch COMMITTED.
- Re-submit identical payload → cached short-circuit returns same summary; `RevenueImportBatch::count() === 1`.
- Mutated payload (one `cash_in` changed) → `updated: 1, skipped: 13`.

**Reconcile coverage:**
- Empty state → empty array.
- Full fixture reconciliation → 10 rows, 9 `match`, 1 `mismatch` (LOC-002 / 2026-03-01 / -$79.00 exactly).
- `missing_actual` — expected exists, no records imported.
- `missing_expected` — records exist, no baseline.

Plus 14 unit tests covering model shape and relationships (`to_array`, BelongsTo, HasMany).

---

## What I'd Improve With More Time

In rough priority order:

- **Dashboard date-range + location filters** — currently the dashboard returns everything. Query params on the JSON endpoint plus a small filter strip on the Vue page would scale to real-world datasets (a year of data across 1k locations is otherwise unreadable).
- **OpenAPI spec for the three endpoints** — declared in the assignment stack; cheap with `dedoc/scramble` or hand-rolled YAML.
- **Per-row error capture in the `errors` array** — currently FormRequest catches structural errors with a 422 and the transaction rolls back on system errors. The `errors` field is reserved for future per-row business rules (e.g., "negative net_revenue exceeds tolerance — quarantine").
- **Machine relocation policy** — pick one of the three documented behaviors in design doc §1. Likely flag-and-quarantine. Also fixes the retroactive-attribution caveat in §3.
- **Async import via queued job** — when payloads grow past ~5k rows / multi-second processing. Trigger documented in design doc §5.
- **Reconciliation tolerance per tenant** — currently exact-match. Make `tolerance` configurable via `config/revenue.php`.
- **Drill-down on the dashboard** — click a mismatched bar → opens the underlying reconciliation row(s), then click further → opens the individual `revenue_records` summing to the actual. Trivial once filters land.
- **Browser tests for the dashboard** — Pest 4 supports `visit()` / `click()`; currently the UI is verified manually. Worth ~30 min of test scaffolding.

The full "defer until measurement justifies it" table is in design doc §5.

---

## Where I Used AI

Per the assignment's expectation that AI is a force-multiplier, here's the honest accounting:

**Design doc (Part A):** Drafted with Claude (Opus). The architectural decisions — three-layer idempotency, verbatim `net_revenue`, deferred async ingestion, the "scale by scheduling not by schema" framing, the machine relocation sharp-edge analysis — were mine, sharpened through Socratic dialogue with the model. I rejected or modified several Claude suggestions (notably: keeping the `machines` table when initial drafts proposed deferring it; tightening the response shape per the assignment spec instead of wrapping in a `data` envelope).

**Schema & migrations:** Scaffolded via `php artisan make:model -mf`. Migration bodies and FK/index choices were hand-shaped against the design doc.

**Models, factories, seeders:** AI scaffolded the stubs (Laravel boilerplate); I wrote fillable lists, casts, relationships, and seeder logic with model lookup maps.

**The `ImportRevenuePayload` Action:** Designed collaboratively. The three-layer flow, transaction boundary, and `wasChanged` discrimination logic emerged from back-and-forth. I caught and fixed two real bugs during implementation: (1) `date` cast serializing with `Y-m-d H:i:s` instead of the `Y-m-d` we passed, causing `updateOrCreate` lookups to miss on replay; (2) `RevenueImportBatch` and `Machine` missing `$fillable` (seeders bypass guards via `Model::unguard()` internally, masking the issue until the controller path exercised it).

**The `ReconcileRevenue` Action:** SQL drafted by me from the design doc, AI proposed using `UNION ALL` to simulate `FULL OUTER JOIN` (SQLite lacks it natively). bcmath usage for money arithmetic was my decision per the "never floats" principle.

**The `BuildDashboard` Action + Vue dashboard:** The decision to share one Action between the JSON endpoint (`/api/revenue/dashboard`) and the Inertia controller (`/dashboard`) was mine — avoids duplicate aggregation, gives reviewers two entry points to the same data. AI scaffolded the chart-related TypeScript boilerplate (Unovis `VisGroupedBar` props, `ChartConfig` shapes) and Vue template structure; I shaped the data flow (decimal strings on the wire, conversion to JS numbers only at chart-render time) and chose which two charts to include (reconciliation variance with the visible LOC-002 gap, total-by-location for size context).

**Tests:** AI scaffolded with `php artisan make:test`. I wrote the assertions to match the assignment's contract — especially the LOC-002 `-79.00` exact-match assertion, which is the assignment's reason for shipping `expected_totals.json` with that intentional mismatch.

**README + this design doc itself:** Drafted with Claude, edited by me. The "What I'd Improve" priorities, the AI-usage accounting, and the design decisions are calibrated to my actual choices during the build.

I used AI to move fast on boilerplate so I could spend disproportionate time on idempotency design, transaction semantics, and the storage-format bug. That's the leverage I'd want any engineer on my team using AI to find.
