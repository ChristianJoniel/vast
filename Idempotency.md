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

Posts the **identical** payload again. Expect `"imported": 0` and `"skipped": 14` — no new rows written, no values changed. This is the core idempotency proof.

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

## 7. Reconcile — surfaces the LOC-002 mismatch

Compares imported totals against `expected_totals`. With the seeded baseline, LOC-002 on 2026-03-01 should show a `-79.00` diff and `"status": "mismatch"`.

```bash
curl -s http://vast.test/api/revenue/reconcile | jq '.[] | select(.status != "match")'
```

---

## 8. Pest tests — same guarantees under `RefreshDatabase`

Programmatic version of the above. Should report all revenue tests passing.

```bash
php artisan test --compact --filter=Revenue
```
