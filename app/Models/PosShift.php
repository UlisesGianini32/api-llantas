<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    protected $fillable = [
        'inventory_location_id',
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
        'total_sales_other',
        'total_sales_amount',
        'total_sales_count',
        'total_cash_in',
        'total_cash_out',
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
            'total_sales_other' => 'decimal:2',
            'total_sales_amount' => 'decimal:2',
            'total_sales_count' => 'integer',
            'total_cash_in' => 'decimal:2',
            'total_cash_out' => 'decimal:2',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'pos_shift_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'pos_shift_id');
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
