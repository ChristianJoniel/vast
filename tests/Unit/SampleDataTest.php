<?php

declare(strict_types=1);

use App\Models\Location;
use App\Services\SampleData;

test('getLocations yields unique hydrated Location instances', function (): void {
    $locations = (new SampleData)->getLocations();

    expect($locations)->toHaveCount(5);

    $locations->each(function (mixed $location): void {
        expect($location)->toBeInstanceOf(Location::class)->not->toBeNull();
        /** @var Location $location */
        expect($location->code)->not->toBeEmpty();
        expect($location->name)->not->toBeEmpty();
    });

    expect($locations->pluck('code')->values()->all())->toBe([
        'LOC-001',
        'LOC-002',
        'LOC-003',
        'LOC-004',
        'LOC-005',
    ]);
});
