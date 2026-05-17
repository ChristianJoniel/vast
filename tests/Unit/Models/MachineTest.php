<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Machine;

test('to array', function () {
    $machine = Machine::factory()->create()->fresh();

    expect(array_keys($machine->toArray()))
        ->toEqual([
            'id',
            'code',
            'location_id',
            'created_at',
            'updated_at',
        ]);
});

it('belongs to location', function () {
    $machine = Machine::factory()->create()->fresh();

    expect($machine->location)->toBeInstanceOf(Location::class);
});

// it('may have todos', function () {
//     $user = User::factory()->create();

//     Todo::factory()->count(3)->create([
//         'user_id' => $user->id,
//     ]);

//     expect($user->todos)->toHaveCount(3);
// });
