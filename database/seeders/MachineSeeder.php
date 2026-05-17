<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Machine;
use App\Services\SampleData;
use Illuminate\Database\Seeder;

class MachineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(SampleData $sampleData): void
    {
        $machines = $sampleData->getMachines();

        $locations = Location::all();

        $machines->each(function (Machine $machine) use ($locations): void {
            $location = $locations->firstWhere('code', $machine->location_id);

            Machine::updateOrCreate([
                'code' => $machine->code,
            ], [
                'location_id' => $location->id,
            ]);
        });
    }
}
