<?php

declare(strict_types=1);

namespace App\Actions\Revenue;

use Illuminate\Support\Facades\DB;

class BuildDashboard
{
    public function __construct(private readonly ReconcileRevenue $reconcile) {}

    /**
     * Compose the full operator dashboard payload: KPI totals, per-location
     * daily revenue, and the reconciliation rollup. Money values are bcmath
     * decimal strings end-to-end; the frontend converts to JS numbers only
     * when handing data to Unovis for chart rendering.
     *
     * @return array{
     *     totals: array{
     *         imported: string,
     *         expected: string,
     *         diff: string,
     *         mismatches_count: int,
     *     },
     *     daily_by_location: list<array{
     *         location_id: string,
     *         report_date: string,
     *         net_revenue: string,
     *     }>,
     *     reconciliation: list<array{
     *         location_id: string,
     *         report_date: string,
     *         expected: string,
     *         actual: string,
     *         diff: string,
     *         status: 'match'|'mismatch'|'missing_actual'|'missing_expected',
     *     }>,
     * }
     */
    public function execute(): array
    {
        $reconciliation = $this->reconcile->execute();

        return [
            'totals' => $this->totalsFrom($reconciliation),
            'daily_by_location' => $this->dailyByLocation(),
            'reconciliation' => $reconciliation,
        ];
    }

    /**
     * Per-(location, report_date) net revenue rollup over revenue_records.
     * Joins through machines because the fact table doesn't denormalize
     * location_id — see DESIGN doc §1 on machine relocation.
     *
     * @return list<array{location_id: string, report_date: string, net_revenue: string}>
     */
    private function dailyByLocation(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                l.code AS location_id,
                r.report_date,
                SUM(r.net_revenue) AS net_revenue
            FROM revenue_records r
            JOIN machines m ON r.machine_id = m.id
            JOIN locations l ON l.id = m.location_id
            GROUP BY l.code, r.report_date
            ORDER BY l.code, r.report_date
        SQL);

        return array_map(fn (object $row): array => [
            'location_id' => $row->location_id,
            'report_date' => $row->report_date,
            'net_revenue' => bcadd((string) $row->net_revenue, '0', 2),
        ], $rows);
    }

    /**
     * KPI totals derived from the reconciliation rollup. `imported` is the sum
     * of all `actual`s, `expected` is the sum of all `expected`s, `diff` is
     * imported minus expected (always 2-decimal bcmath), `mismatches_count`
     * counts rows whose status is explicitly 'mismatch' (not missing_*).
     *
     * @param  list<array{actual: string, expected: string, status: string}>  $reconciliation
     * @return array{imported: string, expected: string, diff: string, mismatches_count: int}
     */
    private function totalsFrom(array $reconciliation): array
    {
        $imported = '0.00';
        $expected = '0.00';
        $mismatches = 0;

        foreach ($reconciliation as $row) {
            $imported = bcadd($imported, $row['actual'], 2);
            $expected = bcadd($expected, $row['expected'], 2);

            if ($row['status'] === 'mismatch') {
                $mismatches++;
            }
        }

        return [
            'imported' => $imported,
            'expected' => $expected,
            'diff' => bcsub($imported, $expected, 2),
            'mismatches_count' => $mismatches,
        ];
    }
}
