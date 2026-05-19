<?php

declare(strict_types=1);

use App\Models\ExpectedTotal;
use App\Models\Location;

test('to array', function () {
    $expectedTotal = ExpectedTotal::factory()->create()->fresh();

    expect(array_keys($expectedTotal->toArray()))
        ->toEqual([
            'id',
            'location_id',
            'report_date',
            'expected_net_revenue',
            'notes',
            'created_at',
            'updated_at',
        ]);
});

it('belongs to location', function () {
    $expectedTotal = ExpectedTotal::factory()->create()->fresh();

    expect($expectedTotal->location)->toBeInstanceOf(Location::class);
});
