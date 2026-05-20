<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Models\Location;
use App\Models\Machine;
use App\Models\RevenueImportBatch;
use App\Models\RevenueRecord;

function samplePayload(): array
{
    return json_decode(file_get_contents(base_path('sample_import.json')), true);
}

it('imports a fresh payload and persists locations, machines, records, and a committed batch', function () {
    $payload = samplePayload();

    $response = $this->postJson('/api/revenue/import', $payload);

    $response->assertOk()->assertExactJson([
        'imported' => 14,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ]);

    expect(Location::count())->toBe(5)
        ->and(Machine::count())->toBe(7)
        ->and(RevenueRecord::count())->toBe(14);

    $batch = RevenueImportBatch::sole();

    expect($batch->status)->toBe(BatchStatus::COMMITTED)
        ->and($batch->record_count)->toBe(14)
        ->and($batch->imported_count)->toBe(14)
        ->and($batch->updated_count)->toBe(0)
        ->and($batch->skipped_count)->toBe(0)
        ->and($batch->new_machines_count)->toBe(7);
});

it('short-circuits a duplicate submission and returns the cached summary without writing a second batch', function () {
    $payload = samplePayload();

    $first = $this->postJson('/api/revenue/import', $payload)->assertOk();
    $second = $this->postJson('/api/revenue/import', $payload)->assertOk();

    expect($second->json())->toBe($first->json());

    expect(RevenueImportBatch::count())->toBe(1)
        ->and(RevenueRecord::count())->toBe(14)
        ->and(Location::count())->toBe(5)
        ->and(Machine::count())->toBe(7);
});

it('counts a money-field change as updated, not skipped', function () {
    $payload = samplePayload();

    $this->postJson('/api/revenue/import', $payload)->assertOk();

    $payload[0]['cash_in'] = 9999.99;

    $response = $this->postJson('/api/revenue/import', $payload)->assertOk();

    $response->assertExactJson([
        'imported' => 0,
        'updated' => 1,
        'skipped' => 13,
        'errors' => [],
    ]);

    expect(RevenueImportBatch::count())->toBe(2)
        ->and(RevenueRecord::count())->toBe(14);
});
