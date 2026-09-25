<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryKitReservation extends Model
{
    public const ACTIVE = 'ACTIVE';

    public const RELEASED = 'RELEASED';

    public const FULFILLED = 'FULFILLED';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    public const SOURCE_TYPE = 'inventory_kit_reservation';

    public const STATUSES = [self::ACTIVE, self::RELEASED, self::FULFILLED, self::CANCELLED, self::EXPIRED];

    protected $fillable = [
        'kit_product_id', 'quantity', 'status', 'source_type', 'source_id', 'reference',
        'external_key', 'expires_at', 'metadata', 'created_by', 'released_at', 'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'source_id' => 'integer',
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'released_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'kit_product_id');
    }

    public function componentReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'source_id')
            ->where('source_type', self::SOURCE_TYPE);
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
