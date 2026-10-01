<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliFullShipment extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PACKED = 'PACKED';
    public const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_DISCREPANCY = 'DISCREPANCY';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const WAREHOUSES = [
        'MXCD01' => 'CEDIS Cuautitlán Izcalli I (Edo. Mex)',
        'MXCD02' => 'CEDIS Cuautitlán Izcalli II (Edo. Mex)',
        'MXRC01' => 'CEDIS Tepotzotlán I (Edo. Mex)',
        'MXRC02' => 'CEDIS Tepotzotlán II (Edo. Mex)',
        'MXNL01' => 'CEDIS Apodaca (Nuevo León)',
        'MXJA01' => 'CEDIS El Salto (Jalisco)',
        'MXTE01' => 'CEDIS Tula / Tepeji (Hidalgo)',
        'OTRO'   => 'Otra bodega / Por asignar',
    ];

    public const CARRIERS = [
        'Paquetexpress',
        'FedEx',
        'DHL',
        'Estafeta',
        'Redpack',
        'Tres Guerras',
        'Castores',
        'Sendex',
        'UPS',
        'Otro',
    ];

    protected $fillable = [
        'user_id',
        'shipment_code',
        'status',
        'meli_warehouse_code',
        'meli_warehouse_name',
        'meli_shipment_id',
        'envia_carrier',
        'envia_tracking_number',
        'envia_tracking_url',
        'envia_cost',
        'total_boxes',
        'total_units',
        'total_units_received',
        'total_units_damaged',
        'total_units_missing',
        'shipped_at',
        'delivered_at',
        'received_at',
        'notes',
    ];

    protected $casts = [
        'envia_cost' => 'decimal:2',
        'total_boxes' => 'integer',
        'total_units' => 'integer',
        'total_units_received' => 'integer',
        'total_units_damaged' => 'integer',
        'total_units_missing' => 'integer',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(MeliFullShipmentBox::class, 'meli_full_shipment_id')->orderBy('box_number');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MeliFullShipmentItem::class, 'meli_full_shipment_id');
    }

    public function recalculateTotals(): void
    {
        $this->total_boxes = $this->boxes()->count();
        $this->total_units = (int) $this->items()->sum('quantity_sent');
        $this->total_units_received = (int) $this->items()->sum('quantity_received');
        $this->total_units_damaged = (int) $this->items()->sum('quantity_damaged');
        $this->total_units_missing = (int) $this->items()->sum('quantity_missing');

        if ($this->total_units_damaged > 0 || $this->total_units_missing > 0) {
            if ($this->status === self::STATUS_RECEIVED) {
                $this->status = self::STATUS_DISCREPANCY;
            }
        }

        $this->save();
    }
}
