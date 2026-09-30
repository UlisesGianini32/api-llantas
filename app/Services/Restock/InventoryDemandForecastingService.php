<?php

namespace App\Services\Restock;

use App\Models\BrandRestockConfiguration;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Services\InventoryStockService;
use Carbon\Carbon;

class InventoryDemandForecastingService
{
    public const STATUS_CRITICAL = 'CRITICAL';

    public const STATUS_WARNING = 'WARNING';

    public const STATUS_OPTIMAL = 'OPTIMAL';

    public const STATUS_OVERSTOCK = 'OVERSTOCK';

    public function __construct(
        private readonly InventoryStockService $stockService
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function getProductForecast(InventoryProduct $product, array $options = []): array
    {
        $now = isset($options['as_of']) ? Carbon::parse($options['as_of']) : Carbon::now();

        // 1. Obtener salidas históricas del ledger (Mercado Libre, Shopify, Amazon, POS Mostrador)
        $sales30d = $this->getProductSalesInWindow($product->id, $now->copy()->subDays(30), $now);
        $sales60d = $this->getProductSalesInWindow($product->id, $now->copy()->subDays(60), $now);
        $sales90d = $this->getProductSalesInWindow($product->id, $now->copy()->subDays(90), $now);
        $sales180d = $this->getProductSalesInWindow($product->id, $now->copy()->subDays(180), $now);

        $dailyRate30d = $sales30d / 30;
        $dailyRate90d = $sales90d / 90;
        $dailyRate180d = $sales180d / 180;

        // Velocidad base ponderada: 50% últimos 30 días, 30% últimos 90 días, 20% últimos 180 días
        $baseDailyVelocity = ($dailyRate30d * 0.50) + ($dailyRate90d * 0.30) + ($dailyRate180d * 0.20);

        // 2. Factor de estacionalidad (mes del año anterior vs promedio del año anterior)
        $seasonalFactor = $this->calculateSeasonalFactor($product->id, $now);

        // Demanda diaria proyectada
        $expectedDailyDemand = round($baseDailyVelocity * $seasonalFactor, 4);

        // 3. Cadencia y parámetros de reabastecimiento (Configuración por marca / producto)
        $brandConfig = $product->brand ? BrandRestockConfiguration::forBrand($product->brand) : null;

        $cadencePreset = $options['cadence_preset']
            ?? $product->restock_cadence_days
            ?? $brandConfig?->cadence_preset
            ?? BrandRestockConfiguration::PRESET_BIWEEKLY_15_20;

        // Días de entrega (Lead time)
        $leadTimeDays = (int) ($options['lead_time_days']
            ?? $product->lead_time_days
            ?? $brandConfig?->lead_time_days
            ?? 7);

        // Días de cobertura objetivo (ej. 7-10d semanal, 15-20d quincenal, 30-45d mensual, 90-120d importación)
        $targetCoverageDays = (int) ($options['target_coverage_days']
            ?? $product->restock_cadence_days
            ?? $brandConfig?->target_coverage_days
            ?? 15);

        // Días de colchón / stock de seguridad
        $safetyStockDays = (int) ($options['safety_stock_days']
            ?? $brandConfig?->safety_stock_days
            ?? ($targetCoverageDays >= 90 ? 20 : 5));

        // 4. Existencias actuales
        $physicalStock = $this->stockService->physicalStock($product);
        $reservedStock = $this->stockService->reservedStock($product);
        $availableStock = max(0, $physicalStock - $reservedStock);

        // 5. Cálculos de stock objetivo y reorden
        // Stock de seguridad = demanda durante días de colchón (o min_stock manual)
        $safetyStockUnits = (int) max(
            (int) ceil($expectedDailyDemand * $safetyStockDays),
            (int) ($product->min_stock ?? 0)
        );

        // Punto de reorden (ROP) = Demanda durante tiempo de entrega + Stock de seguridad
        $reorderPoint = (int) ceil(($expectedDailyDemand * $leadTimeDays) + $safetyStockUnits);

        // Stock total deseado = Demanda durante (Tiempo de entrega + Cobertura) + Stock de seguridad
        $totalPlanningDays = $leadTimeDays + $targetCoverageDays;
        $targetStockUnits = (int) ceil(($expectedDailyDemand * $totalPlanningDays) + $safetyStockUnits);

        // Sugerencia de compra
        $suggestedQuantity = 0;
        if ($availableStock <= $reorderPoint || $availableStock < $targetStockUnits) {
            $suggestedQuantity = max(0, $targetStockUnits - $availableStock);
        }

        // Días de inventario restante
        $daysOfStockRemaining = 999;
        if ($expectedDailyDemand > 0) {
            $daysOfStockRemaining = round($availableStock / $expectedDailyDemand, 1);
        } elseif ($availableStock <= 0) {
            $daysOfStockRemaining = 0;
        }

        // 6. Semáforo de salud de inventario
        $status = self::STATUS_OPTIMAL;
        if ($availableStock <= 0 || ($expectedDailyDemand > 0 && $daysOfStockRemaining <= $leadTimeDays)) {
            $status = self::STATUS_CRITICAL;
        } elseif ($availableStock <= $reorderPoint || ($expectedDailyDemand > 0 && $daysOfStockRemaining <= ($leadTimeDays + $targetCoverageDays))) {
            $status = self::STATUS_WARNING;
        } elseif ($expectedDailyDemand > 0 && $availableStock > ($targetStockUnits * 1.5) && $daysOfStockRemaining > ($targetCoverageDays + 30)) {
            $status = self::STATUS_OVERSTOCK;
        }

        $cost = (float) ($product->cost ?? 0);
        $estimatedInvestment = round($suggestedQuantity * $cost, 2);

        return [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'brand' => $product->brand ?: 'Sin marca',
            'supplier' => $product->supplier ?: 'Sin proveedor',
            'cost' => $cost,
            'price_public' => (float) ($product->price_public ?? 0),
            'product_type' => $product->product_type,
            'is_kit' => $product->isKit(),

            // Ventas históricas
            'sales_30d' => $sales30d,
            'sales_60d' => $sales60d,
            'sales_90d' => $sales90d,
            'sales_180d' => $sales180d,
            'daily_velocity' => round($baseDailyVelocity, 4),
            'seasonal_factor' => $seasonalFactor,
            'expected_daily_demand' => $expectedDailyDemand,

            // Parámetros de planificación
            'cadence_preset' => $cadencePreset,
            'lead_time_days' => $leadTimeDays,
            'target_coverage_days' => $targetCoverageDays,
            'safety_stock_days' => $safetyStockDays,

            // Existencias
            'physical_stock' => $physicalStock,
            'reserved_stock' => $reservedStock,
            'available_stock' => $availableStock,
            'days_of_stock_remaining' => $daysOfStockRemaining,

            // Metas y sugerencias
            'safety_stock_units' => $safetyStockUnits,
            'reorder_point' => $reorderPoint,
            'target_stock_units' => $targetStockUnits,
            'suggested_quantity' => $suggestedQuantity,
            'estimated_investment' => $estimatedInvestment,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generateForecastReport(array $filters = []): array
    {
        $query = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE);

        if (! empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }

        if (! empty($filters['supplier'])) {
            $query->where('supplier', $filters['supplier']);
        }

        if (! empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $query->where(function ($q) use ($s) {
                $q->where('sku', 'like', "%{$s}%")
                    ->orWhere('barcode', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%");
            });
        }

        $products = $query->orderBy('brand')->orderBy('name')->get();

        $items = [];
        $totalSkus = 0;
        $criticalCount = 0;
        $warningCount = 0;
        $optimalCount = 0;
        $overstockCount = 0;
        $totalSuggestedUnits = 0;
        $totalEstimatedInvestment = 0.0;

        $targetPreset = $filters['cadence_preset'] ?? null;
        $overrideCoverage = ! empty($filters['coverage_days']) ? (int) $filters['coverage_days'] : null;

        foreach ($products as $prod) {
            $options = [];
            if ($targetPreset) {
                $options['cadence_preset'] = $targetPreset;
            }
            if ($overrideCoverage) {
                $options['target_coverage_days'] = $overrideCoverage;
            }

            $forecast = $this->getProductForecast($prod, $options);

            // Filtrar por estado de semáforo si aplica
            if (! empty($filters['status']) && $forecast['status'] !== strtoupper($filters['status'])) {
                continue;
            }

            $items[] = $forecast;
            $totalSkus++;

            match ($forecast['status']) {
                self::STATUS_CRITICAL => $criticalCount++,
                self::STATUS_WARNING => $warningCount++,
                self::STATUS_OPTIMAL => $optimalCount++,
                self::STATUS_OVERSTOCK => $overstockCount++,
                default => null,
            };

            $totalSuggestedUnits += $forecast['suggested_quantity'];
            $totalEstimatedInvestment += $forecast['estimated_investment'];
        }

        // Obtener marcas únicas y configuraciones
        $brands = InventoryProduct::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->values();

        $brandConfigs = BrandRestockConfiguration::all()->keyBy('brand');

        return [
            'summary' => [
                'total_skus' => $totalSkus,
                'critical_count' => $criticalCount,
                'warning_count' => $warningCount,
                'optimal_count' => $optimalCount,
                'overstock_count' => $overstockCount,
                'total_suggested_units' => $totalSuggestedUnits,
                'total_estimated_investment' => round($totalEstimatedInvestment, 2),
            ],
            'items' => $items,
            'brands' => $brands,
            'brand_configs' => $brandConfigs,
            'presets' => BrandRestockConfiguration::PRESETS,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveBrandConfiguration(array $data): BrandRestockConfiguration
    {
        $brand = trim((string) ($data['brand'] ?? ''));
        $preset = $data['cadence_preset'] ?? BrandRestockConfiguration::PRESET_BIWEEKLY_15_20;

        $presetDefaults = BrandRestockConfiguration::PRESETS[$preset] ?? BrandRestockConfiguration::PRESETS[BrandRestockConfiguration::PRESET_BIWEEKLY_15_20];

        $leadTime = isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : $presetDefaults['default_lead_time'];
        $coverage = isset($data['target_coverage_days']) ? (int) $data['target_coverage_days'] : $presetDefaults['default_coverage'];
        $safety = isset($data['safety_stock_days']) ? (int) $data['safety_stock_days'] : $presetDefaults['default_safety_stock'];

        return BrandRestockConfiguration::updateOrCreate(
            ['brand' => $brand],
            [
                'supplier' => $data['supplier'] ?? null,
                'cadence_preset' => $preset,
                'lead_time_days' => max(1, $leadTime),
                'target_coverage_days' => max(1, $coverage),
                'safety_stock_days' => max(0, $safety),
                'notes' => $data['notes'] ?? null,
            ]
        );
    }

    private function getProductSalesInWindow(int $productId, Carbon $from, Carbon $to): int
    {
        $sum = (int) InventoryMovement::query()
            ->where('inventory_product_id', $productId)
            ->where(function ($q) {
                $q->where('type', InventoryMovement::SALE)
                    ->orWhere('quantity', '<', 0);
            })
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('quantity');

        return abs($sum);
    }

    private function calculateSeasonalFactor(int $productId, Carbon $now): float
    {
        $lyMonthStart = $now->copy()->subYear()->startOfMonth();
        $lyMonthEnd = $now->copy()->subYear()->endOfMonth();

        $lyYearStart = $now->copy()->subYear()->startOfYear();
        $lyYearEnd = $now->copy()->subYear()->endOfYear();

        $salesSameMonthLastYear = $this->getProductSalesInWindow($productId, $lyMonthStart, $lyMonthEnd);
        $salesFullLastYear = $this->getProductSalesInWindow($productId, $lyYearStart, $lyYearEnd);

        if ($salesFullLastYear <= 0 || $salesSameMonthLastYear <= 0) {
            return 1.0;
        }

        $averageMonthlySales = $salesFullLastYear / 12;
        if ($averageMonthlySales <= 0) {
            return 1.0;
        }

        $factor = round($salesSameMonthLastYear / $averageMonthlySales, 2);

        // Acotar entre 0.5 y 2.5 para evitar picos o caídas atípicas extremas
        return max(0.5, min(2.5, $factor));
    }
}
