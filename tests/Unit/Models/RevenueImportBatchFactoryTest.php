<?php

declare(strict_types=1);

use App\Models\RevenueImportBatch;

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

// it('belongs to location', function () {
//     $machine = Machine::factory()->create()->fresh();

//     expect($machine->location)->toBeInstanceOf(Location::class);
// });

// it('may have todos', function () {
//     $user = User::factory()->create();

//     Todo::factory()->count(3)->create([
//         'user_id' => $user->id,
//     ]);

//     expect($user->todos)->toHaveCount(3);
// });
