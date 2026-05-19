<?php

namespace App\Models;

use Database\Factories\MachineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    /** @use HasFactory<MachineFactory> */
    use HasFactory;

    /**
     * The location that the machine belongs to.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The nightly revenue records reported by this machine.
     *
     * @return HasMany<RevenueRecord, $this>
     */
    public function revenueRecords(): HasMany
    {
        return $this->hasMany(RevenueRecord::class);
    }
}
