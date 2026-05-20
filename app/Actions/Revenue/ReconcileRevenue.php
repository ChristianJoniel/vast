<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use Illuminate\Support\Facades\DB;

class ReconcileRevenue
{
    /**
     * Compare imported revenue against expected daily totals.
     *
     * One set-based SQL query produces a FULL-OUTER-JOIN-like view over the union of
     * expected_totals and revenue_records (SQLite has no native FULL OUTER, so two
     * GROUP BY queries are stitched with UNION ALL):
     *
     *  - Side 1: every (location, date) that has an expected baseline. `actual`
     *            comes from SUM aggregated through machines; NULL if no records exist.
     *  - Side 2: every (location, date) that has imported records but NO baseline.
     *
     * Money arithmetic is done in bcmath, never floats, to preserve decimal precision.
     *
     * @return list<array{
     *     location_id: string,
     *     report_date: string,
     *     expected: string,
     *     actual: string,
     *     diff: string,
     *     status: 'match'|'mismatch'|'missing_actual'|'missing_expected',
     * }>
     */
    public function execute(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                l.code AS location_code,
                e.report_date AS report_date,
                e.expected_net_revenue AS expected,
                SUM(r.net_revenue) AS actual,
                COUNT(r.id) AS record_count
            FROM expected_totals e
            JOIN locations l ON l.id = e.location_id
            LEFT JOIN machines m ON m.location_id = e.location_id
            LEFT JOIN revenue_records r
                ON r.machine_id = m.id
                AND r.report_date = e.report_date
            GROUP BY l.code, e.report_date, e.expected_net_revenue

            UNION ALL

            SELECT
                l.code AS location_code,
                r.report_date AS report_date,
                NULL AS expected,
                SUM(r.net_revenue) AS actual,
                COUNT(r.id) AS record_count
            FROM revenue_records r
            JOIN machines m ON r.machine_id = m.id
            JOIN locations l ON l.id = m.location_id
            LEFT JOIN expected_totals e
                ON e.location_id = m.location_id
                AND e.report_date = r.report_date
            WHERE e.id IS NULL
            GROUP BY l.code, r.report_date

            ORDER BY location_code, report_date
        SQL);

        return array_map(fn (object $row): array => $this->reconcileRow($row), $rows);
    }

    /**
     * @return array{
     *     location_id: string,
     *     report_date: string,
     *     expected: string,
     *     actual: string,
     *     diff: string,
     *     status: 'match'|'mismatch'|'missing_actual'|'missing_expected',
     * }
     */
    private function reconcileRow(object $row): array
    {
        $hasExpected = $row->expected !== null;
        $hasRecords = (int) $row->record_count > 0;

        $expectedStr = $hasExpected ? bcadd((string) $row->expected, '0', 2) : '0.00';
        $actualStr = $hasRecords ? bcadd((string) $row->actual, '0', 2) : '0.00';
        $diffStr = bcsub($actualStr, $expectedStr, 2);

        $status = match (true) {
            ! $hasExpected => 'missing_expected',
            ! $hasRecords => 'missing_actual',
            bccomp($diffStr, '0.00', 2) === 0 => 'match',
            default => 'mismatch',
        };

        return [
            'location_id' => $row->location_code,
            'report_date' => $row->report_date,
            'expected' => $expectedStr,
            'actual' => $actualStr,
            'diff' => $diffStr,
            'status' => $status,
        ];
    }
}
