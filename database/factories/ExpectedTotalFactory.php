<?php

namespace Database\Factories;

use App\Models\ExpectedTotal;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpectedTotal>
 */
class ExpectedTotalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'report_date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'expected_net_revenue' => $this->faker->randomFloat(2, 100, 5000),
            'notes' => null,
        ];
    }
}
