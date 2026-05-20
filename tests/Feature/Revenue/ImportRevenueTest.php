<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Models\Location;
use App\Models\Machine;
use App\Models\RevenueImportBatch;
use App\Models\RevenueRecord;

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

it('falls through the hash short-circuit when a later batch has overwritten the cached batch rows', function () {
    $payload = samplePayload();

    $this->postJson('/api/revenue/import', $payload)->assertOk();

    // A second import with a mutated row reattributes every record's source_batch_id
    // to the new batch, so the original batch's cached counts no longer describe DB state.
    $mutated = $payload;
    $mutated[0]['cash_in'] = 9999.99;
    $this->postJson('/api/revenue/import', $mutated)->assertOk();

    // Replaying the original byte-identical payload should NOT return the stale cached
    // counts — the short-circuit must detect the overwrite and run the per-row path,
    // restoring row 0's cash_in to its original value.
    $response = $this->postJson('/api/revenue/import', $payload)->assertOk();

    $response->assertExactJson([
        'imported' => 0,
        'updated' => 1,
        'skipped' => 13,
        'errors' => [],
    ]);

    expect(RevenueRecord::count())->toBe(14);

    $restoredRow = RevenueRecord::whereHas('machine', fn ($q) => $q->where('code', $payload[0]['machine_id']))
        ->where('report_date', $payload[0]['report_date'])
        ->sole();

    expect((float) $restoredRow->cash_in)->toBe((float) $payload[0]['cash_in']);
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
