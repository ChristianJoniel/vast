<?php

declare(strict_types=1);

use App\Models\ExpectedTotal;
use App\Models\Location;
use Database\Seeders\ExpectedTotalSeeder;

it('returns an empty array when there are no expectations or records', function () {
    $this->getJson('/api/revenue/reconcile')
        ->assertOk()
        ->assertExactJson([]);
});

it('reconciles imported revenue against expected totals and flags the LOC-002 mismatch', function () {
    $this->postJson('/api/revenue/import', samplePayload())->assertOk();
    $this->seed(ExpectedTotalSeeder::class);

    $rows = $this->getJson('/api/revenue/reconcile')->assertOk()->json();

    expect($rows)->toHaveCount(10);

    $mismatch = collect($rows)->firstWhere(
        fn (array $r): bool => $r['location_id'] === 'LOC-002' && $r['report_date'] === '2026-03-01',
    );

    expect($mismatch)->toEqual([
        'location_id' => 'LOC-002',
        'report_date' => '2026-03-01',
        'expected' => '3100.00',
        'actual' => '3021.00',
        'diff' => '-79.00',
        'status' => 'mismatch',
    ]);

    $others = array_filter(
        $rows,
        fn (array $r): bool => ! ($r['location_id'] === 'LOC-002' && $r['report_date'] === '2026-03-01'),
    );

    expect($others)->toHaveCount(9);

    foreach ($others as $row) {
        expect($row['status'])->toBe('match');
    }
});

it('flags expected totals with no imported records as missing_actual', function () {
    $location = Location::factory()->create(['code' => 'LOC-999', 'name' => 'Phantom Site']);

    ExpectedTotal::factory()->create([
        'location_id' => $location->id,
        'report_date' => '2026-04-15',
        'expected_net_revenue' => '500.00',
    ]);

    $rows = $this->getJson('/api/revenue/reconcile')->assertOk()->json();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toEqual([
            'location_id' => 'LOC-999',
            'report_date' => '2026-04-15',
            'expected' => '500.00',
            'actual' => '0.00',
            'diff' => '-500.00',
            'status' => 'missing_actual',
        ]);
});

it('flags imported records with no expected baseline as missing_expected', function () {
    $this->postJson('/api/revenue/import', samplePayload())->assertOk();

    $rows = $this->getJson('/api/revenue/reconcile')->assertOk()->json();

    expect($rows)->toHaveCount(10);

    foreach ($rows as $row) {
        expect($row['status'])->toBe('missing_expected')
            ->and($row['expected'])->toBe('0.00');
    }
});
