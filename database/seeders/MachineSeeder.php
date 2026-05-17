<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Services\SampleData;
use App\Models\Machine;

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
