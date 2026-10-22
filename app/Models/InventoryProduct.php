<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'inventory_product_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'inventory_product_id');
    }

    public function isKit(): bool
    {
        return $this->product_type === self::KIT;
    }

    public function isSimple(): bool
    {
        return ! $this->isKit();
    }

    /**
     * Determina si el producto es un paquete, combo, kit o dúo ensamblado internamente.
     */
    public function isBundleOrKit(): bool
    {
        if ($this->product_type === self::KIT) {
            return true;
        }

        $sku = mb_strtoupper((string) $this->sku);
        if (
            str_starts_with($sku, 'KIT') ||
            str_contains($sku, '-KIT') ||
            str_starts_with($sku, 'DUO') ||
            str_ends_with($sku, 'DUO') ||
            str_contains($sku, '-DUO') ||
            str_starts_with($sku, 'COMBO')
        ) {
            return true;
        }

        $name = mb_strtoupper((string) $this->name);
        if (
            str_starts_with($name, 'KIT ') ||
            str_contains($name, ' KIT ') ||
            str_starts_with($name, 'DUO ') ||
            str_starts_with($name, 'DÚO ') ||
            str_contains($name, ' DUO ') ||
            str_contains($name, ' DÚO ') ||
            str_starts_with($name, 'COMBO ') ||
            str_contains($name, ' COMBO ') ||
            str_starts_with($name, 'PAQUETE ') ||
            str_contains($name, ' PAQUETE ') ||
            str_starts_with($name, 'TRIO ') ||
            str_starts_with($name, 'TRÍO ')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Scope para excluir kits, combos, dúos y paquetes que se ensamblan internamente
     * y NO se compran directamente a proveedores.
     */
    public function scopePurchasableFromSupplier(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q) {
                $q->whereNull('product_type')
                  ->orWhere('product_type', '!=', self::KIT);
            })
            ->whereDoesntHave('kitComponents')
            ->where(function (Builder $q) {
                $q->where('sku', 'not like', 'KIT%')
                  ->where('sku', 'not like', '%-KIT%')
                  ->where('sku', 'not like', 'DUO%')
                  ->where('sku', 'not like', '%-DUO%')
                  ->where('sku', 'not like', '%DUO')
                  ->where('sku', 'not like', 'COMBO%')
                  ->where('sku', 'not like', '%-COMBO%');
            })
            ->where(function (Builder $q) {
                $q->where('name', 'not like', 'Kit %')
                  ->where('name', 'not like', 'KIT %')
                  ->where('name', 'not like', '% Kit %')
                  ->where('name', 'not like', '% KIT %')
                  ->where('name', 'not like', 'Duo %')
                  ->where('name', 'not like', 'DUO %')
                  ->where('name', 'not like', 'Dúo %')
                  ->where('name', 'not like', 'DÚO %')
                  ->where('name', 'not like', '% Duo %')
                  ->where('name', 'not like', '% Dúo %')
                  ->where('name', 'not like', 'Paquete %')
                  ->where('name', 'not like', 'PAQUETE %')
                  ->where('name', 'not like', '% Paquete %')
                  ->where('name', 'not like', 'Combo %')
                  ->where('name', 'not like', 'COMBO %')
                  ->where('name', 'not like', '% Combo %')
                  ->where('name', 'not like', 'Trío %')
                  ->where('name', 'not like', 'Trio %')
                  ->where('name', 'not like', 'TRÍO %')
                  ->where('name', 'not like', 'TRIO %')
                  ->where('name', 'not like', '% Trío %')
                  ->where('name', 'not like', '% Trio %');
            });
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
