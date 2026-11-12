<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomotivePosShift extends AutopartesModel
{
    protected $table = 'automotive_pos_shifts';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'folio',
        'branch_id',
        'cash_register_id',
        'automotive_location_id',
        'cashier_id',
        'status',
        'opened_at',
        'closed_at',
        'opening_cash',
        'closing_cash_expected',
        'closing_cash_counted',
        'difference',
        'total_sales_cash',
        'total_sales_card',
        'total_sales_transfer',
        'total_sales_amount',
        'total_sales_count',
        'total_cash_in',
        'total_cash_out',
        'total_refunds_cash',
        'closed_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'closing_cash_expected' => 'decimal:2',
            'closing_cash_counted' => 'decimal:2',
            'difference' => 'decimal:2',
            'total_sales_cash' => 'decimal:2',
            'total_sales_card' => 'decimal:2',
            'total_sales_transfer' => 'decimal:2',
            'total_sales_amount' => 'decimal:2',
            'total_sales_count' => 'integer',
            'total_cash_in' => 'decimal:2',
            'total_cash_out' => 'decimal:2',
            'total_refunds_cash' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCashRegister::class, 'cash_register_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'automotive_location_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(AutomotivePosSale::class, 'automotive_pos_shift_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AutomotivePosCashMovement::class, 'automotive_pos_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
