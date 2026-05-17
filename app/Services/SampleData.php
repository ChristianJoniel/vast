<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Machine;
use Illuminate\Support\Collection;

class SampleData
{
    protected Collection $data;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $sampleData = file_get_contents(base_path('sample_import.json'));
        $arrayData = json_decode($sampleData, true);
        $this->data = collect($arrayData);
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
}
