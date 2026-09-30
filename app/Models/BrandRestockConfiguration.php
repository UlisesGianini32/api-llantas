<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandRestockConfiguration extends Model
{
    public const PRESET_WEEKLY_7_10 = 'WEEKLY_7_10';

    public const PRESET_BIWEEKLY_15_20 = 'BIWEEKLY_15_20';

    public const PRESET_MONTHLY_30_45 = 'MONTHLY_30_45';

    public const PRESET_IMPORT_90_120 = 'IMPORT_90_120';

    public const PRESET_CUSTOM = 'CUSTOM';

    public const PRESETS = [
        self::PRESET_WEEKLY_7_10 => [
            'label' => 'Semanal (7 - 10 días)',
            'description' => 'Proveedores locales o reposición rápida de alta frecuencia.',
            'default_lead_time' => 3,
            'default_coverage' => 7,
            'default_safety_stock' => 3,
        ],
        self::PRESET_BIWEEKLY_15_20 => [
            'label' => 'Quincenal (15 - 20 días)',
            'description' => 'Cadencia estándar de distribuidores nacionales.',
            'default_lead_time' => 7,
            'default_coverage' => 15,
            'default_safety_stock' => 5,
        ],
        self::PRESET_MONTHLY_30_45 => [
            'label' => 'Mensual (30 - 45 días)',
            'description' => 'Pedidos mensuales consolidados o marcas de rotación moderada.',
            'default_lead_time' => 10,
            'default_coverage' => 30,
            'default_safety_stock' => 10,
        ],
        self::PRESET_IMPORT_90_120 => [
            'label' => 'Largo Plazo / Importación (90 - 120 días)',
            'description' => 'Pedidos de importación marítima, fabricación por volumen o compras trimestrales.',
            'default_lead_time' => 30,
            'default_coverage' => 90,
            'default_safety_stock' => 20,
        ],
        self::PRESET_CUSTOM => [
            'label' => 'Personalizado',
            'description' => 'Parámetros definidos a la medida del proveedor.',
            'default_lead_time' => 7,
            'default_coverage' => 15,
            'default_safety_stock' => 5,
        ],
    ];

    protected $fillable = [
        'brand',
        'supplier',
        'cadence_preset',
        'lead_time_days',
        'target_coverage_days',
        'safety_stock_days',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'target_coverage_days' => 'integer',
            'safety_stock_days' => 'integer',
        ];
    }

    public static function forBrand(string $brand): ?self
    {
        return self::where('brand', trim($brand))->first();
    }
}
