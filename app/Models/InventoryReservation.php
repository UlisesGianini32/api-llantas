<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservation extends Model
{
    public const ACTIVE = 'ACTIVE';

    public const RELEASED = 'RELEASED';

    public const FULFILLED = 'FULFILLED';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    public const STATUSES = [
        self::ACTIVE,
        self::RELEASED,
        self::FULFILLED,
        self::CANCELLED,
        self::EXPIRED,
    ];

    protected $fillable = [
        'inventory_product_id',
        'inventory_location_id',
        'quantity',
        'status',
        'source_type',
        'source_id',
        'reference',
        'external_key',
        'expires_at',
        'metadata',
        'created_by',
        'released_at',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'source_id' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }
}
