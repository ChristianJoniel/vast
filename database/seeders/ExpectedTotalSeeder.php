<?php

namespace Database\Seeders;

use App\Models\ExpectedTotal;
use App\Models\Location;
use App\Services\SampleData;
use Illuminate\Database\Seeder;

class ExpectedTotalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(SampleData $sampleData): void
    {
        $expectedTotals = $sampleData->getExpectedTotals();

        $locations = Location::all();

        $expectedTotals->each(function (ExpectedTotal $expectedTotal) use ($locations): void {
            $location = $locations->firstWhere('code', $expectedTotal->location_id);

            // Format the date explicitly so storage stays as 'Y-m-d' and matches
            // revenue_records.report_date for reconciliation joins. Passing the cast
            // Carbon instance through would serialize via the grammar's default
            // 'Y-m-d H:i:s' format and break the join.
            ExpectedTotal::updateOrCreate([
                'location_id' => $location->id,
                'report_date' => $expectedTotal->report_date->format('Y-m-d'),
            ], [
                'expected_net_revenue' => $expectedTotal->expected_net_revenue,
                'notes' => $expectedTotal->notes,
            ]);
        });
    }
}
