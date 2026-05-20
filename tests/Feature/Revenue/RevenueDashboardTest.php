<?php

declare(strict_types=1);

use Database\Seeders\ExpectedTotalSeeder;

it('returns a zero-state payload when no records or expectations exist', function () {
    $response = $this->getJson('/api/revenue/dashboard')->assertOk();

    expect($response->json('totals'))->toEqual([
        'imported' => '0.00',
        'expected' => '0.00',
        'diff' => '0.00',
        'mismatches_count' => 0,
    ])
        ->and($response->json('daily_by_location'))->toBe([])
        ->and($response->json('reconciliation'))->toBe([]);
});

it('aggregates the full fixture state and surfaces the LOC-002 mismatch', function () {
    $this->postJson('/api/revenue/import', samplePayload())->assertOk();
    $this->seed(ExpectedTotalSeeder::class);

    $payload = $this->getJson('/api/revenue/dashboard')->assertOk()->json();

    expect($payload['totals']['diff'])->toBe('-79.00')
        ->and($payload['totals']['mismatches_count'])->toBe(1)
        ->and($payload['daily_by_location'])->toHaveCount(10)
        ->and($payload['reconciliation'])->toHaveCount(10);

    $mismatch = collect($payload['reconciliation'])->firstWhere(
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
});

it('totals sum every reconciliation row, not just matches', function () {
    $this->postJson('/api/revenue/import', samplePayload())->assertOk();
    $this->seed(ExpectedTotalSeeder::class);

    $totals = $this->getJson('/api/revenue/dashboard')->assertOk()->json('totals');

    // Sum of all imported net_revenue from sample_import.json = 15502.00
    // Sum of all expected_net_revenue from expected_totals.json = 15581.00
    // Diff is the LOC-002 / 2026-03-01 -$79 mismatch.
    expect($totals['imported'])->toBe('15502.00')
        ->and($totals['expected'])->toBe('15581.00')
        ->and($totals['diff'])->toBe('-79.00');
});
