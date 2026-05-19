<?php

declare(strict_types=1);

use App\Models\RevenueImportBatch;
use App\Models\RevenueRecord;

test('to array', function () {
    $revenueImportBatch = RevenueImportBatch::factory()->create()->fresh();

    expect(array_keys($revenueImportBatch->toArray()))
        ->toEqual([
            'id',
            'payload_hash',
            'status',
            'record_count',
            'imported_count',
            'updated_count',
            'skipped_count',
            'new_machines_count',
            'error_payload',
            'created_at',
            'updated_at',
        ]);
});

it('has many revenue records', function () {
    $batch = RevenueImportBatch::factory()->create();

    RevenueRecord::factory()->count(3)->fromBatch($batch)->create();

    expect($batch->revenueRecords)->toHaveCount(3);
});
