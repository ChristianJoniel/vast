<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\BatchStatus;

class RevenueImportBatch extends Model
{
    /** @use HasFactory<\Database\Factories\RevenueImportBatchFactory> */
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
}
