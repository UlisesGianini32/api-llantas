<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AutomotivePosSale extends AutopartesModel
{
    protected $table = 'automotive_pos_sales';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PARTIALLY_RETURNED = 'partially_returned';

    public const STATUS_RETURNED = 'returned';

    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_CARD = 'card';

    public const PAYMENT_TRANSFER = 'transfer';

    public const PAYMENT_MIXED = 'mixed';

    public const PAYMENT_METHODS = [
        self::PAYMENT_CASH,
        self::PAYMENT_CARD,
        self::PAYMENT_TRANSFER,
        self::PAYMENT_MIXED,
    ];

    protected $fillable = [
        'folio',
        'branch_id',
        'cash_register_id',
        'automotive_pos_shift_id',
        'automotive_location_id',
        'cashier_id',
        'cashier_name_snapshot',
        'seller_id',
        'seller_code_snapshot',
        'seller_name_snapshot',
        'customer_name',
        'status',
        'subtotal',
        'wholesale_discount_amount',
        'manual_discount_amount',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'refunded_amount',
        'payment_method',
        'payment_status',
        'amount_tendered',
        'change_due',
        'payment_reference',
        'wholesale_rule_snapshot',
        'manual_discount_snapshot',
        'discount_approval_id',
        'idempotency_key',
        'payload_hash',
        'reprint_count',
        'last_reprinted_at',
        'last_reprinted_by',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'wholesale_discount_amount' => 'decimal:2',
            'manual_discount_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_due' => 'decimal:2',
            'wholesale_rule_snapshot' => 'array',
            'manual_discount_snapshot' => 'array',
            'reprint_count' => 'integer',
            'last_reprinted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Las ventas del POS de Autopartes no se pueden eliminar; utilice cancelación o devolución auditada.');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AutomotiveBranch::class, 'branch_id');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(AutomotiveCashRegister::class, 'cash_register_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AutomotivePosShift::class, 'automotive_pos_shift_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AutomotiveLocation::class, 'automotive_location_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(AutomotiveSeller::class, 'seller_id');
    }

    public function discountApproval(): BelongsTo
    {
        return $this->belongsTo(AutomotiveDiscountApproval::class, 'discount_approval_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AutomotivePosSaleItem::class, 'automotive_pos_sale_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AutomotivePosPayment::class, 'automotive_pos_sale_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(AutomotivePosReturn::class, 'automotive_pos_sale_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
