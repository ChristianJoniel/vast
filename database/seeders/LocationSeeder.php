<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Services\SampleData;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(SampleData $sampleData): void
    {
        $locations = $sampleData->getLocations();

        $locations->each(function (Location $location): void {
            Location::updateOrCreate([
                'code' => $location->code,
            ], [
                'name' => $location->name,
            ]);
        });
    }
}
