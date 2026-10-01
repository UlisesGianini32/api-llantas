<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliFullShipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'meli_full_shipment_id',
        'meli_full_shipment_box_id',
        'inventory_product_id',
        'sku',
        'product_name',
        'mlm',
        'variation_id',
        'quantity_sent',
        'unit_weight_kg',
        'total_weight_kg',
        'quantity_received',
        'quantity_damaged',
        'quantity_missing',
        'notes',
    ];

    protected $casts = [
        'quantity_sent' => 'integer',
        'unit_weight_kg' => 'decimal:3',
        'total_weight_kg' => 'decimal:3',
        'quantity_received' => 'integer',
        'quantity_damaged' => 'integer',
        'quantity_missing' => 'integer',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(MeliFullShipment::class, 'meli_full_shipment_id');
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(MeliFullShipmentBox::class, 'meli_full_shipment_box_id');
    }

    public function inventoryProduct(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }
}
