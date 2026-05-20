<?php

namespace App\Models;

use Database\Factories\ExpectedTotalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpectedTotal extends Model
{
    /** @use HasFactory<ExpectedTotalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'report_date',
        'expected_net_revenue',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'expected_net_revenue' => 'decimal:2',
        ];
    }

    /**
     * The location this expected total belongs to.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
