<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ORDERED = 'ORDERED';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ORDERED,
        self::STATUS_PARTIAL,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'order_number',
        'supplier_name',
        'brand',
        'status',
        'inventory_location_id',
        'user_id',
        'ordered_at',
        'expected_delivery_date',
        'received_at',
        'total_items_count',
        'total_units_ordered',
        'total_units_received',
        'subtotal',
        'tax_amount',
        'shipping_cost',
        'total_cost',
        'supplier_quote_reference',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'expected_delivery_date' => 'date',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_items_count' => 'integer',
            'total_units_ordered' => 'integer',
            'total_units_received' => 'integer',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class, 'purchase_order_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOrdered(): bool
    {
        return $this->status === self::STATUS_ORDERED;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canBeReceived(): bool
    {
        return in_array($this->status, [self::STATUS_ORDERED, self::STATUS_PARTIAL], true);
    }
}
