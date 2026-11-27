<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryProvisionalItem extends Model
{
    public const STATUS_PENDING_LINK = 'PENDING_LINK';

    public const STATUS_AMBIGUOUS_REVIEW = 'AMBIGUOUS_REVIEW';

    public const STATUS_LINKED = 'LINKED';

    public const STATUS_MERGED = 'MERGED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING_LINK,
        self::STATUS_AMBIGUOUS_REVIEW,
        self::STATUS_LINKED,
        self::STATUS_MERGED,
        self::STATUS_ARCHIVED,
    ];

    public const UOM_UNIT = 'UNIT';

    public const UOM_TUBE = 'TUBE';

    public const UOM_BOTTLE = 'BOTTLE';

    public const UOM_SACHET = 'SACHET';

    public const UOM_BOX = 'BOX';

    public const UOM_PACK = 'PACK';

    /** @var list<string> */
    public const INDIVIDUAL_UNITS = [
        self::UOM_UNIT,
        self::UOM_TUBE,
        self::UOM_BOTTLE,
        self::UOM_SACHET,
    ];

    /** @var list<string> */
    public const CONTAINER_UNITS = [
        self::UOM_BOX,
        self::UOM_PACK,
    ];

    /** @var list<string> */
    public const UNITS_OF_MEASURE = [
        self::UOM_UNIT,
        self::UOM_TUBE,
        self::UOM_BOTTLE,
        self::UOM_SACHET,
        self::UOM_BOX,
        self::UOM_PACK,
    ];

    protected $fillable = [
        'barcode',
        'known_sku',
        'name',
        'brand',
        'line',
        'tone',
        'presentation',
        'unit_of_measure',
        'default_units_per_container',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_units_per_container' => 'integer',
            'linked_inventory_product_id' => 'integer',
            'linked_at' => 'datetime',
            'linked_by_user_id' => 'integer',
            'merged_into_provisional_id' => 'integer',
            'canonical_provisional_id' => 'integer',
            'merged_at' => 'datetime',
            'merged_by_user_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public static function isIndividualUnit(string $uom): bool
    {
        return in_array(strtoupper(trim($uom)), self::INDIVIDUAL_UNITS, true);
    }

    public static function isContainerUnit(string $uom): bool
    {
        return in_array(strtoupper(trim($uom)), self::CONTAINER_UNITS, true);
    }

    public function setBarcodeAttribute(?string $value): void
    {
        $trimmed = trim((string) $value);
        $this->attributes['barcode'] = $trimmed === '' ? null : $trimmed;
    }

    public function setKnownSkuAttribute(?string $value): void
    {
        $trimmed = trim((string) $value);
        $this->attributes['known_sku'] = $trimmed === '' ? null : $trimmed;
    }

    public function linkedProduct(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'linked_inventory_product_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by_user_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_provisional_id');
    }

    public function canonical(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_provisional_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dispatchItems(): HasMany
    {
        return $this->hasMany(InventoryDispatchItem::class, 'provisional_item_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InventoryDispatchEvent::class, 'provisional_item_id');
    }

    public function resolvedCanonicalId(): int
    {
        return (int) ($this->canonical_provisional_id ?: $this->getKey());
    }
}
