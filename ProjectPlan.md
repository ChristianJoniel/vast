# Project Plan — Vast Revenue Reconciliation Engine

> Working roadmap for the Staff Software Engineer technical assessment. This document organizes the work; the Part A deliverable lives at `docs/DESIGN.md`.

## Context

Vast receives nightly revenue files from 200+ gaming/vending machine locations. Files get resent, jobs retry, multiple locations submit simultaneously, and financial totals must be provably correct. The assessment asks for a focused build of the import pipeline (where idempotency and integrity matter most), backed by a short design document covering the broader architecture.

**Stack** (already scaffolded): Laravel 13 · Inertia v3 · Vue 3 (Composition API) · Tailwind v4 · SQLite (dev) · Pest 4 · PHP 8.5 · Fortify auth. Wayfinder generates typed route functions for the frontend. No existing revenue code — clean slate.

**Sample data** (committed at repo root): `sample_import.json` (14 records, 5 locations, 2 dates, 5 machines) and `expected_totals.json` (10 entries; **LOC-002 on 2026-03-01 has an intentional $79 discrepancy** — $3,100 expected vs $3,021 actual).

## Deliverables (priority order — top first)

| # | Item | Status |
|---|------|--------|
| 1 | `docs/DESIGN.md` — Part A architecture doc covering data model, idempotency, reconciliation, error handling, scaling, multi-product future | Pending |
| 2 | `POST /api/revenue/import` — idempotent, atomic, returns `{imported, updated, skipped, errors}` | Pending |
| 3 | `RevenueImportTest` — idempotency proof + partial-failure rollback | Pending |
| 4 | `GET /api/revenue/reconcile` — surfaces the $79 LOC-002 discrepancy | Pending |
| 5 | `GET /api/revenue/dashboard` — aggregations with `?from`, `?to`, `?location_id` | Pending |
| 6 | Vue dashboard page at `/revenue` — summary cards, reconciliation table, per-location breakdown, import action | Pending |
| 7 | `README.md` — how to run, key trade-offs, what to improve, AI usage disclosure | Pending |

## Architecture Decisions

### Data model — three tables, denormalized machine

- **`locations`**: `id`, `location_id` (unique string business key, e.g. "LOC-001"), `location_name`, timestamps.
- **`revenue_records`**: `id`, `location_id` FK, `machine_id` (string — no separate `machines` table), four money fields as **`decimal(12,2)`** for financial precision, `report_date` (date), timestamps. **Composite unique on `(machine_id, report_date)`** as the idempotency key, plus a non-unique index on `(location_id, report_date)` for reconciliation/dashboard queries.
- **`expected_totals`**: `id`, `location_id` FK, `report_date`, `expected_net_revenue` (decimal 12,2), `notes`, timestamps. Composite unique on `(location_id, report_date)`.

**Why no `machines` table**: zero machine-level metadata in the import, six distinct machine IDs in sample data. Punted to design doc as future work when machine telemetry/maintenance metadata arrives.

### Idempotency — two layers

- **DB**: composite unique index on `(machine_id, report_date)` — duplicates are impossible at the storage level.
- **App**: `RevenueRecord::updateOrCreate(['machine_id' => ..., 'report_date' => ...], $rest)` per record. Bucket counts by `wasRecentlyCreated` (→ `imported`), `wasChanged()` (→ `updated`), else `skipped`. Rejected `upsert()` because it bypasses these flags and can't distinguish inserted vs updated without a pre-query.

### Transactions — single atomic import

`DB::transaction()` wraps the whole loop. Partial failure rolls everything back. **Why**: "financial accuracy is non-negotiable" — atomicity beats throughput. The design doc articulates the 1000+ location scaling answer (per-location transactions + a `revenue_import_batches` table tracking each file as `accepted | partial | rejected`).

### Validation — shape, not arithmetic

`ImportRevenueRequest` validates types, ranges, date formats. **Does NOT recompute `net_revenue`** — partner's number is stored verbatim to preserve the audit trail. Mismatches surface in the reconciliation layer, not by silent overwrite.

### Reconciliation — DB-seeded expected totals

`ExpectedTotalsSeeder` loads `expected_totals.json` at seed time. Expected totals are *data*, not config — they evolve, need audit trails, and arrive via operator workflows in production. Reading JSON at request time would bake a dev-only assumption into a production path.

## Implementation Sequence

Each step compiles and is verifiable before moving to the next.

1. **Wire `routes/api.php`** — add `api: __DIR__.'/../routes/api.php'` to `bootstrap/app.php` `->withRouting()`. Create empty `routes/api.php`. Verify with `php artisan route:list --path=api`. The `api` middleware group (no CSRF, no sessions, `/api` prefix) is wired automatically.
2. **Three migrations** in one pass — `locations`, `revenue_records`, `expected_totals`. Get FKs and composite unique indexes right before writing any model.
3. **Three models with factories** — `Location`, `RevenueRecord`, `ExpectedTotal`. Match the `User.php` convention: `#[Fillable]` attribute, `casts()` method returning array. Cast money columns to `decimal:2`, `report_date` to `date`. Relationships: `Location hasMany RevenueRecord/ExpectedTotal`.
4. **`ImportRevenueRequest`** — `authorize(): true`. Rules with `*.field` syntax: `location_id` string|max:50, `location_name` string|max:255, `machine_id` string|max:50, four money fields `numeric|min:0|decimal:0,2`, `report_date` `date_format:Y-m-d`.
5. **`ImportRevenueAction`** — single `execute(array $records): array`. Inside `DB::transaction()`: loop, `Location::firstOrCreate(...)`, then `RevenueRecord::updateOrCreate(...)`. Bucket via `wasRecentlyCreated` / `wasChanged()`. Return `['imported' => n, 'updated' => n, 'skipped' => n, 'errors' => []]`.
6. **`RevenueImportController`** — single `__invoke(ImportRevenueRequest $request, ImportRevenueAction $action)` returning `response()->json($action->execute($request->validated()))`.
7. **Register route** — `Route::post('revenue/import', RevenueImportController::class)` in `routes/api.php`.
8. **`RevenueImportTest`** (the critical proof):
   - **Idempotency**: load `sample_import.json` via `File::get(base_path(...))`, POST, assert 14 imported. POST again, assert 0 imported / 14 skipped, and `RevenueRecord::count() === 14`.
   - **Partial failure rollback**: POST with one bad row, assert 422 and `RevenueRecord::count() === 0`.
   - **Update detection**: POST sample, mutate one `net_revenue`, POST again, assert 1 updated / 13 skipped.
9. **`ExpectedTotalsSeeder`** — read `base_path('expected_totals.json')`, decode the `.expected_totals` key, bulk insert. Hook into `DatabaseSeeder`.
10. **`RevenueReconcileController`** — load all `ExpectedTotal`, compute the actual SUM of `net_revenue` per `(location_id, report_date)`, return `{location_id, report_date, expected, actual, diff, status: 'match'|'mismatch'}`. Note in design doc: at 1000+ locations switch to a single `GROUP BY` aggregation query joined to `expected_totals`.
11. **`RevenueDashboardController`** — accept `?from`, `?to`, `?location_id` query params; return aggregated revenue grouped by date and location.
12. **`docs/DESIGN.md`** — Part A design document, ~1–3 pages covering all six required areas.
13. **Gate check** — only proceed to Vue work if every above step is tested, linted (`vendor/bin/pint --dirty --format agent`), and green. If time is tight, the Vue UI is the first cut.
14. **Vue dashboard page**:
    - New web route: `Route::inertia('revenue', 'Revenue/Dashboard')->name('revenue.dashboard')` (public, no auth).
    - New page: `resources/js/pages/Revenue/Dashboard.vue`:
      - **Summary cards** — total revenue, locations, machines, mismatch count
      - **Reconciliation table** — rows from `/api/revenue/reconcile`, mismatches highlighted (red badge for LOC-002)
      - **Per-location breakdown** — from `/api/revenue/dashboard`
      - **Import button** — POSTs `sample_import.json` to `/api/revenue/import`, shows the `{imported, updated, skipped, errors}` response inline
    - Initial data via Inertia props (server-side fetch in the controller backing the inertia route). Manual import action via `useHttp`.
    - Reuse existing `AppShell`, `Card`, `Button` from `resources/js/components/`.
    - Run `php artisan wayfinder:generate` after adding routes.
15. **`README.md`** — replace the Laravel starter README with: setup steps, run commands, key design decisions, "what I'd improve with more time" list (queued imports, per-location batch transactions with `revenue_import_batches` table, machine normalization, role-based access, structured audit trail, OpenAPI spec), and an explicit **AI usage** section disclosing where Claude was used vs hand-written.
16. **Final pass** — `vendor/bin/pint --dirty --format agent`, full test run, `php artisan migrate:fresh --seed` smoke test, browser check of `/revenue` page.

## File Manifest

**Modified**:
- `bootstrap/app.php` — register `api:` in `withRouting`
- `database/seeders/DatabaseSeeder.php` — call `ExpectedTotalsSeeder`
- `routes/web.php` — add `revenue` Inertia route
- `README.md` — replace with assessment-specific content

**Created — Backend**:
- `routes/api.php`
- `database/migrations/{ts}_create_locations_table.php`
- `database/migrations/{ts}_create_revenue_records_table.php`
- `database/migrations/{ts}_create_expected_totals_table.php`
- `app/Models/Location.php`
- `app/Models/RevenueRecord.php`
- `app/Models/ExpectedTotal.php`
- `database/factories/LocationFactory.php`
- `database/factories/RevenueRecordFactory.php`
- `database/factories/ExpectedTotalFactory.php`
- `app/Http/Requests/Api/ImportRevenueRequest.php`
- `app/Actions/Revenue/ImportRevenueAction.php`
- `app/Http/Controllers/Api/RevenueImportController.php`
- `app/Http/Controllers/Api/RevenueReconcileController.php`
- `app/Http/Controllers/Api/RevenueDashboardController.php`
- `database/seeders/ExpectedTotalsSeeder.php`

**Created — Tests**:
- `tests/Feature/RevenueImportTest.php`
- `tests/Feature/RevenueReconcileTest.php`

**Created — Frontend**:
- `resources/js/pages/Revenue/Dashboard.vue`

**Created — Docs**:
- `docs/DESIGN.md`

## Testing Strategy

The required test is **idempotency** — POSTing the same payload twice must produce identical DB state. Additional tests prove the harder edge cases:

| Test | What it proves |
|------|---------------|
| `RevenueImportTest::idempotency` | Re-importing the same file does not double-write records or corrupt totals |
| `RevenueImportTest::partial_failure_rollback` | A single malformed row aborts the entire import — no half-imported state |
| `RevenueImportTest::update_detection` | A changed row is correctly bucketed as `updated`, not `imported` |
| `RevenueImportTest::location_upsert` | Locations are deduplicated by `location_id` across multiple imports |
| `RevenueReconcileTest::flags_the_intentional_mismatch` | The seeded $79 discrepancy for LOC-002 on 2026-03-01 is correctly surfaced |

Run with: `php artisan test --compact --filter=Revenue`.

## Verification End-to-End

After implementation:
- `php artisan migrate:fresh --seed` — clean DB with expected totals seeded
- `php artisan test --compact --filter=Revenue` — all tests green
- `curl -X POST http://vast.test/api/revenue/import -H 'Content-Type: application/json' -d @sample_import.json` — first: `{"imported":14,...}`. Second: `{"imported":0,"updated":0,"skipped":14,...}`
- `curl http://vast.test/api/revenue/reconcile` — LOC-002 on 2026-03-01 returns `{"expected":3100.00,"actual":3021.00,"diff":79.00,"status":"mismatch"}`
- `curl 'http://vast.test/api/revenue/dashboard?location_id=LOC-001'` — aggregated revenue for Lucky's Tavern
- `php artisan route:list --path=api` — shows the three revenue routes
- `vendor/bin/pint --dirty --format agent` — clean
- Browser: `http://vast.test/revenue` renders the dashboard with the LOC-002 mismatch highlighted

## Risk Gates

- **Step 13 is non-negotiable**: Vue work does not begin until backend is green, linted, and tested. The assignment explicitly warns: *"A well-architected import with clear idempotency and one strong test beats a full-stack feature with shallow foundations."*
- **Decimal precision**: all four money columns must be `decimal(12,2)`, never `float`. The `casts()` method must cast to `decimal:2`.
- **Transaction wrapping**: the `ImportRevenueAction::execute()` loop must be inside `DB::transaction()` — verify by writing the partial-failure test before declaring the import "done."
- **Design doc time-box**: ~1 hour. The build matters more, but architectural judgment is what they evaluate first in the review.

## What's Out of Scope (for the design doc's "future work")

- A `machines` table with telemetry/maintenance metadata
- A `revenue_import_batches` table tracking each submission as a unit with `accepted | partial | rejected` status
- Queued imports (`ProcessRevenueImport` job) for large files
- Per-location transactions instead of single global transaction (for the 1000+ location scaling answer)
- Role-based access control (operators vs partners vs auditors)
- Structured audit log of every revenue mutation
- OpenAPI spec generation
- A re-reconciliation cron that re-checks discrepancies after a window
- Webhook ingest for partners (vs synchronous POST)
