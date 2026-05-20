<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use App\Enums\BatchStatus;
use App\Models\Location;
use App\Models\Machine;
use App\Models\RevenueImportBatch;
use App\Models\RevenueRecord;
use Illuminate\Support\Facades\DB;

class ImportRevenuePayload
{
    /**
     * Import a validated nightly revenue payload.
     *
     * Idempotency uses three layers:
     *  - DB:  unique (machine_id, report_date) on revenue_records makes duplicates impossible.
     *         This is the real contract — everything else is optimization or convenience.
     *  - App: updateOrCreate upserts safely; wasChanged() on money fields distinguishes
     *         a real revision from a no-op replay.
     *  - Hash: a byte-identical replay (same payload bytes → same SHA-256) short-circuits
     *         the pipeline and returns the cached summary from a prior COMMITTED batch.
     *         The short-circuit additionally verifies that every record that batch wrote
     *         still has source_batch_id pointing at it — otherwise a later import has
     *         overwritten the cached batch's rows and the cached counts no longer describe
     *         current DB state, so the replay must fall through to the per-row path.
     *         Best-effort fast path only — semantically equivalent payloads with reordered
     *         keys or different decimal precision (965.5 vs 965.50) will miss this check
     *         and fall through to the per-row updateOrCreate / DB layer.
     *
     * Dimension data (Location.name, Machine.location_id) is treated as partner-authoritative
     * and overwritten on every import. A machine relocating to a different location is
     * silently absorbed — flag-and-quarantine semantics are out of scope for MVP.
     *
     * `errors` in the return shape is currently always empty: structural validation lives
     * in the FormRequest (422), and any row-level failure aborts the transaction (5xx).
     * The field is kept in the contract for future per-row business rules.
     *
     * @param  list<array{
     *     location_id: string,
     *     location_name: string,
     *     machine_id: string,
     *     cash_in: float|int|string,
     *     voucher_in: float|int|string,
     *     voucher_out: float|int|string,
     *     net_revenue: float|int|string,
     *     report_date: string,
     * }>  $payload
     * @return array{
     *     imported: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{index: int, errors: array<string, list<string>>}>,
     * }
     */
    public function execute(array $payload): array
    {
        $hash = hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        $cached = RevenueImportBatch::where('payload_hash', $hash)
            ->where('status', BatchStatus::COMMITTED)
            ->first();

        if ($cached && $this->cachedBatchIsIntact($cached)) {
            return [
                'imported' => $cached->imported_count,
                'updated' => $cached->updated_count,
                'skipped' => $cached->skipped_count,
                'errors' => [],
            ];
        }

        return DB::transaction(function () use ($payload, $hash): array {
            $batch = RevenueImportBatch::firstOrCreate(
                ['payload_hash' => $hash],
                [
                    'status' => BatchStatus::PENDING,
                    'record_count' => count($payload),
                ],
            );

            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $newMachines = 0;

            foreach ($payload as $row) {
                $location = Location::updateOrCreate(
                    ['code' => $row['location_id']],
                    ['name' => $row['location_name']],
                );

                $machine = Machine::updateOrCreate(
                    ['code' => $row['machine_id']],
                    ['location_id' => $location->id],
                );

                if ($machine->wasRecentlyCreated) {
                    $newMachines++;
                }

                $record = RevenueRecord::updateOrCreate(
                    [
                        'machine_id' => $machine->id,
                        'report_date' => $row['report_date'],
                    ],
                    [
                        'source_batch_id' => $batch->id,
                        'cash_in' => $row['cash_in'],
                        'voucher_in' => $row['voucher_in'],
                        'voucher_out' => $row['voucher_out'],
                        'net_revenue' => $row['net_revenue'],
                    ],
                );

                if ($record->wasRecentlyCreated) {
                    $imported++;
                } elseif ($record->wasChanged(['cash_in', 'voucher_in', 'voucher_out', 'net_revenue'])) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            // error_payload is cleared on the COMMITTED transition so a retry after a
            // prior REJECTED row with the same hash doesn't leave stale failure context.
            $batch->update([
                'status' => BatchStatus::COMMITTED,
                'imported_count' => $imported,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'new_machines_count' => $newMachines,
                'error_payload' => null,
            ]);

            return [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => [],
            ];
        });
    }

    /**
     * The cache short-circuit is only safe when every record this batch wrote still
     * attributes back to it. updateOrCreate sets source_batch_id on every row it
     * touches, so a later import that mutated even one record from this batch would
     * have changed that record's source_batch_id — making the cached counters stale.
     */
    private function cachedBatchIsIntact(RevenueImportBatch $batch): bool
    {
        return RevenueRecord::where('source_batch_id', $batch->id)->count() === $batch->record_count;
    }
}
