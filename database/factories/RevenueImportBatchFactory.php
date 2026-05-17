<?php

namespace Database\Factories;

use App\Enums\BatchStatus;
use App\Models\RevenueImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RevenueImportBatch>
 */
class RevenueImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recordCount = fake()->numberBetween(5, 50);

        return [
            'payload_hash' => hash('sha256', Str::random(64)),
            'status' => BatchStatus::PENDING,
            'record_count' => $recordCount,
            'imported_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'new_machines_count' => 0,
            'error_payload' => null,
        ];
    }

    /**
     * Batch finished cleanly. All records counted as imported by default;
     * pass overrides to model updated/skipped distributions.
     */
    public function committed(array $counts = []): static
    {
        return $this->state(function (array $attrs) use ($counts) {
            $defaults = [
                'imported_count' => $attrs['record_count'],
                'updated_count' => 0,
                'skipped_count' => 0,
                'new_machines_count' => 0,
            ];

            return [
                'status' => BatchStatus::COMMITTED,
                ...$defaults,
                ...$counts,
            ];
        });
    }

    /**
     * Batch failed validation before any writes. Counts stay at zero.
     */
    public function rejected(array $errors = []): static
    {
        return $this->state(fn () => [
            'status' => BatchStatus::REJECTED,
            'imported_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'new_machines_count' => 0,
            'error_payload' => $errors ?: [
                ['index' => 0, 'errors' => ['report_date' => ['The report date is invalid.']]],
            ],
        ]);
    }

    /**
     * Force the hash to match a specific payload — useful for asserting
     * that a re-submit of the SAME data short-circuits to a cached summary.
     */
    public function forPayload(array $payload): static
    {
        return $this->state(fn () => [
            'payload_hash' => hash('sha256', json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )),
        ]);
    }

    /**
     * Explicit count overrides without changing status. Handy for asserting
     * response-shape parity against the API contract.
     */
    public function withCounts(int $imported = 0, int $updated = 0, int $skipped = 0, int $newMachines =
    0): static
    {
        return $this->state(fn () => [
            'imported_count' => $imported,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'new_machines_count' => $newMachines,
            'record_count' => $imported + $updated + $skipped,
        ]);
    }
}
