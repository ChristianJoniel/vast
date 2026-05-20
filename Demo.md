# Live Demo Script

This is the runnable script for the 25-minute code walkthrough portion of the technical review. The 20-minute design-doc walkthrough comes first (use `OpusDesignDocument.md`); the final 15 minutes are open Q&A with the cheat sheet at the bottom of this file.

Total runtime: **~16 minutes**, leaving buffer for natural pauses, reviewer questions mid-demo, and the Q&A round.

---

## Pre-meeting prep (10 min before call)

- [ ] Herd is running (`herd status`); `http://vast.test` resolves.
- [ ] Repo is clean: `git status` shows no uncommitted noise.
- [ ] Production assets built: `npm run build`. (Avoids `vite dev` chunk-load surprises mid-demo.)
- [ ] Open three terminal panes side-by-side:
  - **Pane A** — running commands (CWD: `/Users/christiannegron/Herd/vast`)
  - **Pane B** — `php artisan tinker` ready for DB inspection
  - **Pane C** — `tail -f storage/logs/laravel.log` (in case something goes sideways)
- [ ] Browser pre-warmed: `http://vast.test/login` open in a tab, logged in as `test@example.com` / `password`. `/dashboard` open in a second tab (will show empty state until step 6).
- [ ] Pre-save the mutated payload for step 4:
  ```bash
  jq '.[0].cash_in = 9999.99' sample_import.json > /tmp/sample_modified.json
  ```
- [ ] Editor open with four files in tabs:
  1. `app/Actions/Revenue/ImportRevenuePayload.php`
  2. `app/Actions/Revenue/ReconcileRevenue.php`
  3. `app/Actions/Revenue/BuildDashboard.php`
  4. `OpusDesignDocument.md`
- [ ] Have `expected_totals.json` and `sample_import.json` open in a viewer to reference the LOC-002 / -$79 fixture if asked.
- [ ] Run the demo once end-to-end immediately before the call to verify nothing has rotted.

---

## Step 0 — Test suite baseline (1 min)

Open with credibility. This is the strongest single artifact in the submission.

```bash
php artisan test --compact
```

**Expected:** `Tests: 65 passed (255 assertions)`. ~1.5 seconds.

> **Say:** "Before I walk through the code, here's the test suite — 65 tests, all green. Three of them are the feature tests that prove the idempotency contract end-to-end; four cover reconciliation against the fixture; three cover the dashboard aggregation."

---

## Step 1 — Reset to a known clean state (30 sec)

```bash
php artisan migrate:fresh
```

**Expected:** All tables dropped and re-created; no seed data.

> **Say:** "Fresh database. No locations, no machines, no records. I'll show three things in sequence: the import endpoint, the idempotency contract under replay, and the reconcile endpoint surfacing the intentional $79 discrepancy."

---

## Step 2 — Import the sample payload (2 min)

```bash
curl -sS -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  --data @sample_import.json
```

**Expected:**
```json
{"imported":14,"updated":0,"skipped":0,"errors":[]}
```

**Then switch to Pane B (tinker) to verify DB state:**

```php
App\Models\Location::count();           // 5
App\Models\Machine::count();            // 7
App\Models\RevenueRecord::count();      // 14
App\Models\RevenueImportBatch::first()->toArray();
```

**Expected on the batch:** `status: "committed"`, `payload_hash` populated (64-char hex), `imported_count: 14`, `new_machines_count: 7`, `error_payload: null`.

> **Talking points (don't read all of them — pick the ones the reviewers seem hungry for):**
>
> - One transaction wrapped the whole import. If row 14 had failed, rows 1-13 would have rolled back too.
> - The batch row is the audit trail. `source_batch_id` on every record points back to it.
> - `new_machines_count = 7` because all 5 locations and 7 machines were created during this import. That's a signal worth surfacing to operators — partners shouldn't typically be introducing 7 new machines a night.

---

## Step 3 — Idempotency replay (1.5 min)

The heart of the assessment. Run the **exact same curl** again:

```bash
curl -sS -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  --data @sample_import.json
```

**Expected:** **byte-identical response** to step 2.
```json
{"imported":14,"updated":0,"skipped":0,"errors":[]}
```

**Verify in tinker:**
```php
App\Models\RevenueImportBatch::count();   // 1, not 2
App\Models\RevenueRecord::count();        // 14, not 28
```

> **Say:** "Same payload, same response. One batch, 14 records. Internally this hit the SHA-256 short-circuit — the action saw a committed batch with this exact payload hash and returned its cached counters without opening a transaction. Layer 3 of the three-layer idempotency."
>
> "If the hash short-circuit didn't exist, the DB unique constraint on `(machine_id, report_date)` would still hold the line — that's Layer 1. And `updateOrCreate` with `wasChanged()` on the money fields gives the right `skipped: 14` even if Layer 3 missed. That's Layer 2."

---

## Step 4 — Update vs skip discrimination (1.5 min)

Prove the action distinguishes a real revision from a no-op.

```bash
curl -sS -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  --data @/tmp/sample_modified.json
```

(The mutated file has `cash_in: 9999.99` on the first row.)

**Expected:**
```json
{"imported":0,"updated":1,"skipped":13,"errors":[]}
```

**In tinker:**
```php
App\Models\RevenueImportBatch::count();   // 2 — different hash, new batch
App\Models\RevenueRecord::count();        // 14 — no duplicates
```

> **Say:** "Different bytes → different SHA-256 → no short-circuit. The pipeline ran. Eloquent's `wasChanged()` told us exactly one row had a money-field delta; the other 13 were untouched. The `source_batch_id` on the updated row now points at the new batch — that's the audit trail."

---

## Step 5 — Reconcile endpoint (2 min)

```bash
php artisan db:seed --class=ExpectedTotalSeeder
curl -sS http://vast.test/api/revenue/reconcile | python3 -m json.tool
```

**Expected:** 10 rows. **Scroll/point at the LOC-002 / 2026-03-01 row:**

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

> **Say:** "There it is — the intentional $79 discrepancy from `expected_totals.json`. Every other row reconciles to zero diff. The whole thing is one set-based SQL using a `UNION ALL` to simulate `FULL OUTER JOIN` on SQLite, plus `bcmath` for the diff arithmetic — never floats for money. Reconciliation is stateless — it's a view over facts, not a stored result. Recomputed on every request."

> **(If asked about scaling reconcile to 1M rows):** "Add an index on `(report_date)` and a materialized daily rollup table the moment p95 crosses ~500ms. Not before. Speculative materialized views are technical debt I'm not going to pay until measurement justifies it."

---

## Step 6 — Dashboard endpoint + visual UI (3 min)

The visual closer. Switch to Pane A:

```bash
curl -sS http://vast.test/api/revenue/dashboard | python3 -m json.tool | head -15
```

**Expected:** the same reconciliation rows plus a `totals` block (`imported: 15502.00`, `expected: 15581.00`, `diff: -79.00`, `mismatches_count: 1`) and a `daily_by_location` rollup.

> **Say:** "Same data, different shape. The JSON endpoint at `/api/revenue/dashboard` aggregates everything an operator wants on one screen — KPI totals, per-location revenue, full reconciliation — in a single call. One `BuildDashboard` action composes the reconcile action and adds two derived rollups."

Now switch to the browser tab on `/dashboard` and **refresh** (you've already imported + seeded above, so the page now has data):

> **Talking points (point at the screen):**
>
> - **KPI cards:** $15,502 imported, $15,581 expected, **-$79 variance in red**, 1 mismatch flagged amber. Same numbers we just saw via curl — Inertia passes them as props from the *same* `BuildDashboard` action.
> - **Reconciliation Variance chart:** every (location, date) pair, actual vs expected side by side. Point at LOC-002 / 03-01 — visibly shorter blue bar than the orange expected. Hover for the tooltip.
> - **Total Revenue by Location:** size-of-business view; LOC-004 is roughly 4x LOC-005.

> **Say (closing):** "Two entry points to the same aggregation logic — JSON for machines, props for humans. Money stays as bcmath strings end-to-end; they get converted to JS numbers only at the chart-render boundary."

---

## Step 7 — Code walkthrough (3 min)

Open `app/Actions/Revenue/ImportRevenuePayload.php` and walk the **three layers** structurally:

1. **Lines ~52-66 (Pre-pipeline)** — "SHA-256 hash, then check for a prior COMMITTED batch. Layer 3. Best-effort fast path; the docblock is explicit that this misses on semantically-equivalent-but-byte-different payloads, and that the DB unique is the real contract."

2. **Lines ~68-78 (Transaction open + batch row)** — "Single `DB::transaction`. `firstOrCreate` keyed on the unique `payload_hash` is concurrency-safe — two identical concurrent submissions converge on one batch row instead of racing."

3. **Lines ~85-119 (Per-row loop)** — "Each row: upsert Location, upsert Machine, then `updateOrCreate` the fact keyed on `(machine_id, report_date)`. `wasRecentlyCreated` ↔ `imported`; `wasChanged(money fields only)` ↔ `updated`; otherwise `skipped`. Note `source_batch_id` is excluded from `wasChanged` so a no-op replay still counts as `skipped`, not `updated`."

4. **Lines ~121-130 (Commit)** — "Counters and `COMMITTED` status get written atomically with the records. If anything in the loop throws, the batch row rolls back too — no orphan PENDING rows."

Then briefly flash `app/Actions/Revenue/ReconcileRevenue.php` and `app/Actions/Revenue/BuildDashboard.php`:

> **Say:** "Reconcile is intentionally smaller — one SQL query that left-joins expected against records via machines, unioned with a symmetric query for the inverse case (records with no expected baseline). Status derivation in PHP uses bcmath so the diff is a decimal string all the way to the wire."
>
> "And `BuildDashboard` composes that — constructor-injects `ReconcileRevenue`, adds two rollups (totals derived from the recon rows, daily-by-location via its own SQL). The JSON controller and the Inertia controller both call this one action — same data, two entry points."

---

## Step 8 — Closing the demo (30 sec)

> **Say:** "That's the import contract, the idempotency proof, the reconcile path, and the dashboard. Tests cover the same flows programmatically — happy to run them again or step through any specific test if it'd help."

Pause for reviewer questions. Don't fill silence.

---

## Q&A Cheat Sheet (final 15 min)

**Anticipated questions** with one-liner answers — expand only as the reviewer probes.

**"What if a partner sends files 3x/day instead of 1x?"**
> Nothing breaks for correctness — every submission is idempotent. The `revenue_import_batches` table grows linearly; reconcile output is unchanged. If we wanted to dedup the operator-facing "batches per night," we'd add a `report_date` column on the batch and aggregate. Defer until ops asks.

**"What if record 15 of 20 fails mid-import?"**
> Transaction rolls back — rows 1-14 are gone, partner's prior state is intact. The partner retries the same POST and idempotency lands them correctly. The MVP picks integrity over throughput; per-row sub-transactions would be the post-MVP move when single-transaction commits start blocking concurrent partners.

**"How do you scale this from 200 to 1,000 locations?"**
> Schema doesn't change — the load-bearing decisions (unique key, decimal precision, batch audit) carry. What changes is how work is scheduled and where reads land. Design doc §5 has the trigger table — async ingestion when files routinely exceed ~2s, read replica when reconcile/dashboard reads measurably impact writer p95, partitioning at ~50M rows.

**"What happens when a machine moves between locations?"**
> Today: silent overwrite of `machines.location_id`. Documented as a known sharp edge in design doc §1. Three defensible policies — authoritative-overwrite (current), flag-and-quarantine, reject-on-conflict — and picking among them is a product call about how partners actually communicate physical moves.

**"What about historical reconciliation after a machine moves?"**
> The current schema joins reconciliation through the machine's *current* location, so historical revenue would re-attribute to the new location. That's wrong in a strict accounting sense. The fix is either denormalizing `location_id` onto `revenue_records` at write time (so the join uses the machine's location *as of `report_date`*) or maintaining a `machine_movements` audit table. Both are post-MVP per design doc §1.

**"Why no `machines` API endpoint?"**
> Out of scope for this assessment — machines surface through `new_machines_count` on the batch record so operators can see new hardware appearing. Promoting machines to a first-class API resource is a quick follow-up if telemetry or service-history features come into scope.

**"What if the partner's `net_revenue` disagrees with `cash_in + voucher_in - voucher_out`?"**
> We persist verbatim and don't recompute. Silent recomputation destroys the audit trail of what the partner claimed. The arithmetic mismatch becomes a reconciliation signal, not an ingest-time rejection. The principle is "ingest is honest about what arrived; reconciliation is honest about whether it adds up."

**"Why not use bulk INSERT ... ON DUPLICATE KEY UPDATE?"**
> Faster but loses the imported-vs-updated distinction the API contract requires. At MVP scale (200 locations × ~5 machines × 1 file/day) per-row `updateOrCreate` is negligible. Bulk upsert earns its place at 10k+ rows per file; the API would have to grow a counters-via-side-channel mechanism to keep the response shape.

**"What's the failure mode if the hash short-circuit has a bug?"**
> Same as if it didn't exist — the pipeline runs and `updateOrCreate` produces correct results via Layer 2. The short-circuit is purely an optimization; it can't corrupt state, only fail to optimize.

**"Could you show me the test for idempotency?"**
> Open `tests/Feature/Revenue/ImportRevenueTest.php` — the `short-circuits a duplicate submission` test POSTs the same payload twice and asserts the response is byte-identical and `RevenueImportBatch::count() === 1`. The `counts a money-field change as updated` test mutates one row and asserts `updated: 1, skipped: 13` to prove the discrimination works.

---

## Fallback notes

**If `curl` fails** — Herd may have stopped. Run `herd start` in another pane.

**If `tinker` is slow on first invocation** — it's bootstrapping; just wait. Don't reach for a workaround mid-demo.

**If a test you didn't expect fails live** — own it, don't hide it. "Interesting — let me show you what's there." The reviewers want to see how you handle the unexpected as much as the rehearsed parts.

**If you forget a number** — `php artisan tinker` and look it up. Better than guessing.

**If a reviewer goes deep on something you didn't anticipate** — say "good question, I haven't thought through that specifically — my instinct is X, but let me reason through it." Honesty beats fabrication.
