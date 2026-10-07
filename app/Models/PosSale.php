<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosSale extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_CARD = 'card';

    public const PAYMENT_TRANSFER = 'transfer';

    public const PAYMENT_CREDIT = 'credit';

    public const PAYMENT_MIXED = 'mixed';

    public const PAYMENT_STATUS_PAID = 'paid';

    public const PAYMENT_STATUS_CREDIT_PENDING = 'credit_pending';

    public const PAYMENT_STATUS_CREDIT_PARTIAL = 'credit_partial';

    public const PAYMENT_STATUS_CANCELLED = 'cancelled';

    public const CUSTOMER_PUBLIC = 'public';

    public const CUSTOMER_STYLIST = 'stylist';

    protected $fillable = [
        'sale_number',
        'user_id',
        'inventory_location_id',
        'pos_shift_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_type',
        'payment_method',
        'payment_status',
        'credit_days',
        'credit_due_date',
        'balance_due',
        'amount_paid',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'amount_tendered',
        'change_due',
        'status',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_due' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'credit_days' => 'integer',
            'credit_due_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosSaleItem::class, 'pos_sale_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class, 'pos_sale_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCredit(): bool
    {
        return $this->payment_method === self::PAYMENT_CREDIT;
    }

    public function isOverdue(): bool
    {
        if (! $this->isCredit() || $this->balance_due <= 0 || ! $this->credit_due_date) {
            return false;
        }

        return $this->credit_due_date->lt(Carbon::today());
    }

    public function isDueSoon(int $days = 3): bool
    {
        if (! $this->isCredit() || $this->balance_due <= 0 || ! $this->credit_due_date) {
            return false;
        }

        $today = Carbon::today();
        $target = Carbon::today()->addDays($days);

        return $this->credit_due_date->gte($today) && $this->credit_due_date->lte($target);
    }

    public function scopePendingCredit(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_CANCELLED)
            ->whereIn('payment_status', [self::PAYMENT_STATUS_CREDIT_PENDING, self::PAYMENT_STATUS_CREDIT_PARTIAL])
            ->where('balance_due', '>', 0);
    }

    public function scopeOverdueCredit(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->pendingCredit()
            ->whereNotNull('credit_due_date')
            ->where('credit_due_date', '<', $today);
    }

    public function scopeDueSoonCredit(Builder $query, int $days = 3): Builder
    {
        $today = Carbon::today()->toDateString();
        $limit = Carbon::today()->addDays($days)->toDateString();

        return $query->pendingCredit()
            ->whereNotNull('credit_due_date')
            ->whereBetween('credit_due_date', [$today, $limit]);
    }
}
