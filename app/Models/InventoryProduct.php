<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class InventoryProduct extends Model
{
    public const SIMPLE = 'SIMPLE';

    public const KIT = 'KIT';

    public const TYPES = [self::SIMPLE, self::KIT];

    protected $fillable = [
        'sku',
        'product_type',
        'barcode',
        'barcode_secondary',
        'name',
        'brand',
        'supplier',
        'description',
        'cost',
        'min_stock',
        'lead_time_days',
        'restock_cadence_days',
        'price_mercado_libre',
        'price_amazon',
        'price_stylist',
        'price_public',
        'weight_kg',
        'requires_meli_labeling',
        'is_active',
        'primary_location_id',
        'secondary_location_id',
        'reserve_notes',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'min_stock' => 'integer',
            'lead_time_days' => 'integer',
            'restock_cadence_days' => 'integer',
            'price_mercado_libre' => 'decimal:2',
            'price_amazon' => 'decimal:2',
            'price_stylist' => 'decimal:2',
            'price_public' => 'decimal:2',
            'weight_kg' => 'decimal:3',
            'requires_meli_labeling' => 'boolean',
            'is_active' => 'boolean',
            'primary_location_id' => 'integer',
            'secondary_location_id' => 'integer',
            'reserve_notes' => 'string',
        ];
    }

    public function primaryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'primary_location_id');
    }

    public function secondaryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'secondary_location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_product_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'inventory_product_id');
    }

    public function kitComponents(): HasMany
    {
        return $this->hasMany(InventoryKitComponent::class, 'kit_product_id');
    }

    public function usedInKits(): HasMany
    {
        return $this->hasMany(InventoryKitComponent::class, 'component_product_id');
    }

    public function kitReservations(): HasMany
    {
        return $this->hasMany(InventoryKitReservation::class, 'kit_product_id');
    }

    public function channelLinks(): HasMany
    {
        return $this->hasMany(InventoryChannelLink::class, 'inventory_product_id');
    }

    public function isKit(): bool
    {
        return $this->product_type === self::KIT;
    }

    public function isSimple(): bool
    {
        return ! $this->isKit();
    }

    public function physicalStock(): int
    {
        if ($this->isKit()) {
            return 0;
        }

        return (int) $this->movements()->sum('quantity');
    }

    public function setSkuAttribute($value): void
    {
        $sku = trim((string) $value);
        if ($sku === '') {
            throw new InvalidArgumentException('El SKU no puede estar vacío.');
        }

        $this->attributes['sku'] = $sku;
    }

    public function setBarcodeAttribute($value): void
    {
        $barcode = trim((string) ($value ?? ''));
        $this->attributes['barcode'] = $barcode === '' ? null : $barcode;
    }

    public function setBarcodeSecondaryAttribute($value): void
    {
        $barcode = trim((string) ($value ?? ''));
        $this->attributes['barcode_secondary'] = $barcode === '' ? null : $barcode;
    }
}
