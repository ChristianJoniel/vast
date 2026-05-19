<?php

namespace App\Services;

use App\Models\ExpectedTotal;
use App\Models\Location;
use App\Models\Machine;
use Illuminate\Support\Collection;

class SampleData
{
    protected Collection $data;

    protected Collection $expectedData;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $sampleData = file_get_contents(base_path('sample_import.json'));
        $arrayData = json_decode($sampleData, true);
        $this->data = collect($arrayData);

        $expectedRaw = file_get_contents(base_path('expected_totals.json'));
        $expectedArray = json_decode($expectedRaw, true);
        $this->expectedData = collect($expectedArray['expected_totals'] ?? []);
    }

    public function getLocations(): Collection
    {
        return $this->data
            ->unique('location_id')
            ->values()
            ->map(function (array $item): Location {
                return new Location([
                    'code' => $item['location_id'],
                    'name' => $item['location_name'],
                ]);
            });
    }

    public function getMachines(): Collection
    {
        return $this->data
            ->unique('machine_id')
            ->values()
            ->map(function (array $item): Machine {
                return new Machine([
                    'code' => $item['machine_id'],
                    'location_id' => $item['location_id'],
                ]);
            });
    }

    public function getExpectedTotals(): Collection
    {
        return $this->expectedData->map(function (array $item): ExpectedTotal {
            return new ExpectedTotal([
                'location_id' => $item['location_id'],
                'report_date' => $item['report_date'],
                'expected_net_revenue' => $item['expected_net_revenue'],
                'notes' => $item['notes'] ?? null,
            ]);
        });
    }
}
