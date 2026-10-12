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
        'MXCD06' => 'Centro logístico Panorama MX06 (CDMX / Edo. Mex)',
        'MXCD01' => 'CEDIS Cuautitlán Izcalli I (Edo. Mex)',
        'MXCD02' => 'CEDIS Cuautitlán Izcalli II (Edo. Mex)',
        'MXCD03' => 'CEDIS Tultitlán III (Edo. Mex)',
        'MXCD04' => 'CEDIS Tultitlán IV (Edo. Mex)',
        'MXCD05' => 'CEDIS Naucalpan V (Edo. Mex)',
        'MXRC01' => 'CEDIS Tepotzotlán I (Edo. Mex)',
        'MXRC02' => 'CEDIS Tepotzotlán II (Edo. Mex)',
        'MXNL01' => 'CEDIS Apodaca (Nuevo León)',
        'MXJA01' => 'CEDIS El Salto (Jalisco)',
        'MXTE01' => 'CEDIS Tula / Tepeji (Hidalgo)',
        'OTRO'   => 'Otra bodega / Por asignar',
    ];

    public const WAREHOUSE_PROFILES = [
        'MXCD06' => [
            'name' => 'Centro logístico Panorama MX06 - MXCD06',
            'state' => 'Estado de México',
            'address' => 'Carretera Cuautitlan - Tultepec lote 8 no. 2 y lote C1, Colonia Terremoto, Cuautitlan, Estado De Mexico. C.P.: 54803',
            'product_types' => 'Productos pequeños y medianos (Belleza, cosméticos, cuidado personal, suplementos alimenticios y consumo masivo).',
            'restrictions' => 'Tus productos únicamente se pueden enviar en bultos (cajas de hasta 30 kg), no podrás agruparlos en pallets. Destinatario: Mercado Libre.',
            'accepts_pallets' => false,
            'accepts_bultos' => true,
            'recommended_carriers' => ['Estafeta', 'DHL', 'FedEx', 'UPS'],
        ],
        'MXCD01' => [
            'name' => 'CEDIS Cuautitlán Izcalli I - MXCD01',
            'state' => 'Estado de México',
            'address' => 'Autopista México-Querétaro Km 37.5, Parque Industrial Cuautitlán Izcalli, Edo. Méx. C.P. 54730',
            'product_types' => 'Carga general, productos medianos y surtido multivariante.',
            'restrictions' => 'Acepta bultos (máx 30 kg) y pallets agrupados.',
            'accepts_pallets' => true,
            'accepts_bultos' => true,
            'recommended_carriers' => ['Estafeta', 'Paquetexpress', 'FedEx', 'DHL'],
        ],
        'MXRC01' => [
            'name' => 'CEDIS Tepotzotlán I - MXRC01',
            'state' => 'Estado de México',
            'address' => 'Parque Industrial Tepotzotlán, Libramiento Norte, Tepotzotlán, Edo. Méx.',
            'product_types' => 'Artículos voluminosos, pesados, llantas y carga sobredimensionada.',
            'restrictions' => 'Especializado en artículos de gran tamaño o peso elevado.',
            'accepts_pallets' => true,
            'accepts_bultos' => true,
            'recommended_carriers' => ['Paquetexpress', 'Castores', 'Tres Guerras', 'Estafeta'],
        ],
        'MXNL01' => [
            'name' => 'CEDIS Apodaca - MXNL01',
            'state' => 'Nuevo León',
            'address' => 'Parque Industrial Apodaca, Apodaca, Nuevo León',
            'product_types' => 'Zona Norte: Carga general y consumo masivo.',
            'restrictions' => 'Acepta bultos (máx 30 kg) y pallets.',
            'accepts_pallets' => true,
            'accepts_bultos' => true,
            'recommended_carriers' => ['Estafeta', 'Paquetexpress', 'FedEx', 'DHL'],
        ],
        'MXJA01' => [
            'name' => 'CEDIS El Salto - MXJA01',
            'state' => 'Jalisco',
            'address' => 'Corredor Industrial El Salto, Jalisco',
            'product_types' => 'Zona Occidente: Carga general y paquetería.',
            'restrictions' => 'Acepta bultos (máx 30 kg) y pallets.',
            'accepts_pallets' => true,
            'accepts_bultos' => true,
            'recommended_carriers' => ['Estafeta', 'Paquetexpress', 'FedEx', 'DHL'],
        ],
    ];

    public static function getWarehouseProfile(string $code): array
    {
        return self::WAREHOUSE_PROFILES[$code] ?? [
            'name' => self::WAREHOUSES[$code] ?? $code,
            'state' => 'México',
            'address' => 'Almacén Central Mercado Libre Full',
            'product_types' => 'Mercancía general.',
            'restrictions' => 'Bultos de hasta 30 kg.',
            'accepts_pallets' => true,
            'accepts_bultos' => true,
            'recommended_carriers' => ['Estafeta', 'DHL', 'FedEx'],
        ];
    }

    public const CARRIERS = [
        'Estafeta',
        'Paquetexpress',
        'FedEx',
        'DHL',
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
        'total_bultos',
        'total_units',
        'total_labeled_units',
        'total_weight_kg',
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
        'total_bultos' => 'integer',
        'total_units' => 'integer',
        'total_labeled_units' => 'integer',
        'total_weight_kg' => 'decimal:2',
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
        $boxes = $this->boxes()->get();
        $this->total_bultos = $boxes->pluck('bulto_number')->unique()->count() ?: 1;
        $this->total_boxes = (int) $boxes->sum(fn ($b) => max(1, (int) $b->boxes_in_bulto));
        $this->total_units = (int) $this->items()->sum('quantity_sent');
        $this->total_labeled_units = (int) $this->items()->where('requires_labeling', true)->sum('quantity_sent');
        $this->total_weight_kg = (float) $this->items()->sum('total_weight_kg');
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
