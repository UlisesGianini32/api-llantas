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

    public const DISPATCH_OUT = 'DISPATCH_OUT';

    public const DISPATCH_RETURN = 'DISPATCH_RETURN';

    /** @var list<string> */
    public const DISPATCH_RESERVED_TYPES = [
        self::DISPATCH_OUT,
        self::DISPATCH_RETURN,
    ];

    public const POSITIVE_TYPES = [
        self::INITIAL,
        self::RECEIPT,
        self::RETURN,
        self::ADJUSTMENT_IN,
        self::TRANSFER_IN,
        self::DISPATCH_RETURN,
    ];

    public const NEGATIVE_TYPES = [
        self::SALE,
        self::ADJUSTMENT_OUT,
        self::DAMAGE,
        self::TRANSFER_OUT,
        self::DISPATCH_OUT,
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

    /**
     * Movement types permitted from generic manual movement endpoints/forms.
     * Excludes TRANSFER_IN/TRANSFER_OUT (handled via TRANSFER) and dispatch-reserved types.
     *
     * @return list<string>
     */
    public static function manualTypes(): array
    {
        return array_values(array_diff(
            self::types(),
            [
                self::TRANSFER_IN,
                self::TRANSFER_OUT,
                ...self::DISPATCH_RESERVED_TYPES,
            ],
        ));
    }

    public static function isDispatchReservedType(string $type): bool
    {
        return in_array($type, self::DISPATCH_RESERVED_TYPES, true);
    }
}
