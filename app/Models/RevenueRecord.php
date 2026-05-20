<?php

namespace App\Models;

use Database\Factories\RevenueRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueRecord extends Model
{
    /** @use HasFactory<RevenueRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'machine_id',
        'source_batch_id',
        'report_date',
        'cash_in',
        'voucher_in',
        'voucher_out',
        'net_revenue',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'cash_in' => 'decimal:2',
            'voucher_in' => 'decimal:2',
            'voucher_out' => 'decimal:2',
            'net_revenue' => 'decimal:2',
        ];
    }

    /**
     * The machine that produced this revenue record.
     *
     * @return BelongsTo<Machine, $this>
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * The import batch this record was written by, when known.
     *
     * @return BelongsTo<RevenueImportBatch, $this>
     */
    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(RevenueImportBatch::class, 'source_batch_id');
    }
}
