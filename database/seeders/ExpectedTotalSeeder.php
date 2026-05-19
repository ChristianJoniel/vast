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

            ExpectedTotal::updateOrCreate([
                'location_id' => $location->id,
                'report_date' => $expectedTotal->report_date,
            ], [
                'expected_net_revenue' => $expectedTotal->expected_net_revenue,
                'notes' => $expectedTotal->notes,
            ]);
        });
    }
}
