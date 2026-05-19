<?php

declare(strict_types=1);

use App\Models\Machine;
use App\Models\RevenueImportBatch;
use App\Models\RevenueRecord;

test('to array', function () {
    $revenueRecord = RevenueRecord::factory()->create()->fresh();

    expect(array_keys($revenueRecord->toArray()))
        ->toEqual([
            'id',
            'machine_id',
            'source_batch_id',
            'report_date',
            'cash_in',
            'voucher_in',
            'voucher_out',
            'net_revenue',
            'created_at',
            'updated_at',
        ]);
});

it('belongs to machine', function () {
    $revenueRecord = RevenueRecord::factory()->create()->fresh();

    expect($revenueRecord->machine)->toBeInstanceOf(Machine::class);
});

it('has no source batch by default', function () {
    $revenueRecord = RevenueRecord::factory()->create()->fresh();

    expect($revenueRecord->source_batch_id)->toBeNull()
        ->and($revenueRecord->sourceBatch)->toBeNull();
});

it('belongs to a source batch when attributed', function () {
    $batch = RevenueImportBatch::factory()->create();

    $revenueRecord = RevenueRecord::factory()->fromBatch($batch)->create()->fresh();

    expect($revenueRecord->sourceBatch)
        ->toBeInstanceOf(RevenueImportBatch::class)
        ->and($revenueRecord->sourceBatch->id)->toBe($batch->id);
});
