<?php

namespace Database\Factories;

use App\Models\Machine;
use App\Models\RevenueImportBatch;
use App\Models\RevenueRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RevenueRecord>
 */
class RevenueRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cashIn = $this->faker->randomFloat(2, 100, 3000);
        $voucherIn = $this->faker->randomFloat(2, 50, 800);
        $voucherOut = $this->faker->randomFloat(2, 50, 800);

        return [
            'machine_id' => Machine::factory(),
            'source_batch_id' => null,
            'report_date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'cash_in' => $cashIn,
            'voucher_in' => $voucherIn,
            'voucher_out' => $voucherOut,
            'net_revenue' => round($cashIn + $voucherIn - $voucherOut, 2),
        ];
    }

    /**
     * Attach this record to a specific import batch (or a fresh one if omitted).
     */
    public function fromBatch(?RevenueImportBatch $batch = null): static
    {
        return $this->state(fn () => [
            'source_batch_id' => $batch?->id ?? RevenueImportBatch::factory(),
        ]);
    }
}
