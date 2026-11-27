<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryDispatch extends Model
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_PARTIALLY_RETURNED = 'PARTIALLY_RETURNED';

    public const STATUS_FULLY_RETURNED = 'FULLY_RETURNED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_CONFIRMED,
        self::STATUS_PARTIALLY_RETURNED,
        self::STATUS_FULLY_RETURNED,
        self::STATUS_CANCELLED,
    ];

    public const OP_INTERNAL_CONSUMPTION = 'INTERNAL_CONSUMPTION';

    public const OP_SALON_SALE = 'SALON_SALE';

    public const OP_TRANSFER = 'TRANSFER';

    /** @var list<string> */
    public const OPERATION_TYPES = [
        self::OP_INTERNAL_CONSUMPTION,
        self::OP_SALON_SALE,
        self::OP_TRANSFER,
    ];

    public const COMMERCIAL_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const COMMERCIAL_REFERENCE_ONLY = 'REFERENCE_ONLY';

    public const CANCEL_SITUATION_DRAFT = 'DRAFT_DISCARDED';

    public const CANCEL_SITUATION_NEVER_DELIVERED = 'NEVER_DELIVERED';

    protected $fillable = [
        'notes',
        'received_by_name',
        'retroactive_reason',
    ];

    protected function casts(): array
    {
        return [
            'draft_version' => 'integer',
            'destination_id' => 'integer',
            'source_location_id' => 'integer',
            'effective_at' => 'datetime',
            'is_retroactive' => 'boolean',
            'captured_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_by_user_id' => 'integer',
            'received_by_user_id' => 'integer',
            'confirmed_by_user_id' => 'integer',
            'cancelled_by_user_id' => 'integer',
            'total_lines_count' => 'integer',
            'total_physical_units' => 'integer',
            'unconverted_containers_count' => 'integer',
            'total_units_returned' => 'integer',
            'reference_subtotal_cost' => 'decimal:2',
            'reference_cost_complete' => 'boolean',
            'reference_subtotal_stylist' => 'decimal:2',
            'reference_stylist_complete' => 'boolean',
            'metadata' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(InventoryDispatchDestination::class, 'destination_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'source_location_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryDispatchItem::class, 'inventory_dispatch_id')
            ->orderBy('line_order')
            ->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InventoryDispatchEvent::class, 'inventory_dispatch_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }
}
