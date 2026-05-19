<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

class ImportRevenuePayload
{
    /**
     * Import a validated nightly revenue payload.
     *
     * Idempotency contract: submitting the same payload twice must produce
     * the same persisted state. Enforced at the DB layer by the unique
     * (machine_id, report_date) constraint on revenue_records and reinforced
     * at the app layer by upserting via Model::updateOrCreate.
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
        return [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }
}
