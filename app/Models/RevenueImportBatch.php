<?php

namespace App\Models;

use App\Enums\BatchStatus;
use Database\Factories\RevenueImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueImportBatch extends Model
{
    /** @use HasFactory<RevenueImportBatchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'error_payload' => 'array',
        ];
    }

    /**
     * The revenue records written by this import batch.
     *
     * @return HasMany<RevenueRecord, $this>
     */
    public function revenueRecords(): HasMany
    {
        return $this->hasMany(RevenueRecord::class, 'source_batch_id');
    }
}
