<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class InventoryProduct extends Model
{
    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'description',
        'cost',
        'price_mercado_libre',
        'price_amazon',
        'price_stylist',
        'price_public',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price_mercado_libre' => 'decimal:2',
            'price_amazon' => 'decimal:2',
            'price_stylist' => 'decimal:2',
            'price_public' => 'decimal:2',
            'is_active' => 'boolean',
        ];
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
}
