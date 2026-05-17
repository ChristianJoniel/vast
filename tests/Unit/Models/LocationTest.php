<?php

declare(strict_types=1);

use App\Models\Location;

test('to array', function () {
    $location = Location::factory()->create()->fresh();

    expect(array_keys($location->toArray()))
        ->toEqual([
            'id',
            'code',
            'name',
            'created_at',
            'updated_at',
        ]);
});

// it('may have todos', function () {
//     $user = User::factory()->create();

//     Todo::factory()->count(3)->create([
//         'user_id' => $user->id,
//     ]);

//     expect($user->todos)->toHaveCount(3);
// });
