<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const INITIAL = 'INITIAL';

    public const RECEIPT = 'RECEIPT';

    public const SALE = 'SALE';

    public const RETURN = 'RETURN';

    public const ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    public const DAMAGE = 'DAMAGE';

    public const TRANSFER_IN = 'TRANSFER_IN';

    public const TRANSFER_OUT = 'TRANSFER_OUT';

    public const POSITIVE_TYPES = [
        self::INITIAL,
        self::RECEIPT,
        self::RETURN,
        self::ADJUSTMENT_IN,
        self::TRANSFER_IN,
    ];

    public const NEGATIVE_TYPES = [
        self::SALE,
        self::ADJUSTMENT_OUT,
        self::DAMAGE,
        self::TRANSFER_OUT,
    ];

    protected $fillable = [
        'inventory_product_id',
        'inventory_location_id',
        'type',
        'quantity',
        'reference_type',
        'reference_id',
        'reference',
        'notes',
        'metadata',
        'created_by',
        'occurred_at',
        'external_key',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reference_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
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

    public static function types(): array
    {
        return array_merge(self::POSITIVE_TYPES, self::NEGATIVE_TYPES);
    }
}
