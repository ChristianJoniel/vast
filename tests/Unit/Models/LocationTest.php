<?php

declare(strict_types=1);

use App\Models\ExpectedTotal;
use App\Models\Location;
use App\Models\Machine;

test('to array', function () {
    $location = Location::factory()->create()->fresh();

    expect(array_keys($location->toArray()))
        ->toEqual([
            'id',
            'code',
            'name',
            'created_at',
            'updated_at',
        ]);
});

it('has many machines', function () {
    $location = Location::factory()->create();

    Machine::factory()->count(3)->create([
        'location_id' => $location->id,
    ]);

    expect($location->machines)->toHaveCount(3);
});

it('has many expected totals', function () {
    $location = Location::factory()->create();

    ExpectedTotal::factory()
        ->count(2)
        ->sequence(
            ['report_date' => '2026-03-01'],
            ['report_date' => '2026-03-02'],
        )
        ->create(['location_id' => $location->id]);

    expect($location->expectedTotals)->toHaveCount(2);
});
