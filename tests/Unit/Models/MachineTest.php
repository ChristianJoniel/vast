<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Machine;
use App\Models\RevenueRecord;

test('to array', function () {
    $machine = Machine::factory()->create()->fresh();

    expect(array_keys($machine->toArray()))
        ->toEqual([
            'id',
            'code',
            'location_id',
            'created_at',
            'updated_at',
        ]);
});

it('belongs to location', function () {
    $machine = Machine::factory()->create()->fresh();

    expect($machine->location)->toBeInstanceOf(Location::class);
});

it('has many revenue records', function () {
    $machine = Machine::factory()->create();

    RevenueRecord::factory()->count(3)->create([
        'machine_id' => $machine->id,
    ]);

    expect($machine->revenueRecords)->toHaveCount(3);
});
