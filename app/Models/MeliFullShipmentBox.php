<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliFullShipmentBox extends Model
{
    use HasFactory;

    public const STATUS_PACKING = 'PACKING';
    public const STATUS_PACKED = 'PACKED';
    public const STATUS_SEALED = 'SEALED';

    protected $fillable = [
        'meli_full_shipment_id',
        'box_number',
        'box_code',
        'capacity',
        'units_count',
        'weight_kg',
        'dimensions',
        'status',
    ];

    protected $casts = [
        'box_number' => 'integer',
        'capacity' => 'integer',
        'units_count' => 'integer',
        'weight_kg' => 'decimal:2',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(MeliFullShipment::class, 'meli_full_shipment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MeliFullShipmentItem::class, 'meli_full_shipment_box_id');
    }

    public function recalculateUnits(): void
    {
        $this->units_count = (int) $this->items()->sum('quantity_sent');
        $this->save();
    }
}
