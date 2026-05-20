# Idempotency Demo

Each step below is a runnable command. Hit the play button on the code block to execute it.

The idempotency contract: re-importing the exact same payload must not duplicate records. The unique key on `(machine_id, report_date)` enforces this at the DB level; the import action distinguishes between **inserted**, **updated**, and **skipped** rows so the API can report the effect of the call.

---

## 1. Reset the database

Wipes everything and re-runs migrations + seeders. Run once at the start of the demo so the import counts are predictable.

```bash
php artisan migrate:fresh --seed
```

---

## 2. First import — fresh state

Posts the sample payload. Expect `"imported": N` matching the row count in `sample_import.json` (14), with `"updated": 0` and `"skipped": 0`.

```bash
curl -s -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  --data @sample_import.json | jq
```

---

## 3. Record count after first import

Should print `14`.

```bash
php artisan tinker --execute 'echo "records: ".App\Models\RevenueRecord::count().PHP_EOL;'
```

---

## 4. Second import — same payload, idempotent

Posts the **byte-identical** payload again. Expect the same response as step 2 — `"imported": 14`, `"skipped": 0`. The action computes a SHA-256 of the payload and short-circuits when the hash matches a committed batch, so the response is the *cached* summary of the original commit. The real idempotency proof is the unchanged DB row count in step 5, not the response shape.

```bash
curl -s -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  --data @sample_import.json | jq
```

---

## 5. Record count after second import

Still `14`. The unique key prevented duplication.

```bash
php artisan tinker --execute 'echo "records: ".App\Models\RevenueRecord::count().PHP_EOL;'
```

---

## 6. Mutated payload — proves the update path

Bumps `net_revenue` on the first record to `999.99`, then re-imports. Expect `"updated": 1, "imported": 0, "skipped": 13`. The row matched on `(machine_id, report_date)` and was updated in place — still no duplication.

```bash
jq '.[0].net_revenue = 999.99' sample_import.json > /tmp/mutated_import.json && \
curl -s -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  --data @/tmp/mutated_import.json | jq
```

---

## 7. Revert the mutation — re-import the original payload

Re-posts the unmodified `sample_import.json`. The payload's SHA-256 matches step 2's committed batch, but the cache short-circuit also checks that every row that batch wrote still attributes back to it — step 6's import re-set every row's `source_batch_id` to the new batch, so the check fails and the replay falls through to the per-row `updateOrCreate` path. Expect `"updated": 1, "imported": 0, "skipped": 13` — LOC-001 is updated back to `965.50`, the other 13 already match the DB and are skipped. This doubles as a second idempotency proof and resets the DB to the headline reconcile scenario.

```bash
curl -s -X POST http://vast.test/api/revenue/import \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  --data @sample_import.json | jq
```

---

## 8. Reconcile — surfaces the LOC-002 mismatch

Compares imported totals against `expected_totals`. With the seeded baseline and step 7's revert, exactly one row should come back: LOC-002 on 2026-03-01 with a `-79.00` diff and `"status": "mismatch"`.

```bash
curl -s http://vast.test/api/revenue/reconcile | jq '.[] | select(.status != "match")'
```

---

## 9. Pest tests — same guarantees under `RefreshDatabase`

Programmatic version of the above. Should report all revenue tests passing.

```bash
php artisan test --compact --filter=Revenue
```
