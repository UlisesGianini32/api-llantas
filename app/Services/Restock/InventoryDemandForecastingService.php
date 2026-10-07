<?php

namespace App\Services\Restock;

use App\Models\BrandRestockConfiguration;
use App\Models\InventoryChannelLink;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliFullShipment;
use App\Models\MeliFullShipmentItem;
use App\Models\MeliFullStock;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * @param  array<string, mixed>|null  $batchData  Datos pre-agrupados opcionales para evitar N+1 queries masivos
     * @return array<string, mixed>
     */
    public function getProductForecast(InventoryProduct $product, array $options = [], ?array $batchData = null): array
    {
        $now = isset($options['as_of']) ? Carbon::parse($options['as_of']) : ($batchData['now'] ?? Carbon::now());

        if ($batchData !== null) {
            $pid = $product->id;
            $localRow = $batchData['local_movements'][$pid] ?? null;
            $fullRow  = $batchData['full_metrics'][$pid] ?? null;

            $salesLocal30d = (int) ($localRow?->sales_30d ?? 0);
            $salesFull30d  = (int) ($fullRow['qty_30d'] ?? 0);

            $sales30d = $salesLocal30d + $salesFull30d;
            $sales60d = (int) ($localRow?->sales_60d ?? 0) + (int) ($fullRow['qty_60d'] ?? 0);
            $sales90d = (int) ($localRow?->sales_90d ?? 0) + (int) ($fullRow['qty_90d'] ?? 0);
            $sales180d = (int) ($localRow?->sales_180d ?? 0) + (int) ($fullRow['qty_180d'] ?? 0);

            $dailyRate30d = $sales30d / 30;
            $dailyRate90d = $sales90d / 90;
            $dailyRate180d = $sales180d / 180;

            $baseDailyVelocity = ($dailyRate30d * 0.50) + ($dailyRate90d * 0.30) + ($dailyRate180d * 0.20);

            // Factor de estacionalidad desde batch
            $lyMonthTotal = (int) ($localRow?->sales_ly_month ?? 0) + (int) ($fullRow['qty_ly_month'] ?? 0);
            $lyYearTotal  = (int) ($localRow?->sales_ly_year ?? 0) + (int) ($fullRow['qty_ly_year'] ?? 0);

            if ($lyYearTotal <= 0 || $lyMonthTotal <= 0) {
                $seasonalFactor = 1.0;
            } else {
                $averageMonthlySales = $lyYearTotal / 12;
                if ($averageMonthlySales <= 0) {
                    $seasonalFactor = 1.0;
                } else {
                    $seasonalFactor = max(0.5, min(2.5, round($lyMonthTotal / $averageMonthlySales, 2)));
                }
            }

            $brandKey = mb_strtoupper(trim((string) $product->brand));
            $brandConfig = $batchData['brand_configs'][$brandKey] ?? null;

            $physicalStock = (int) ($batchData['physical_stocks'][$pid] ?? 0);
            $reservedStock = (int) ($batchData['reserved_stocks'][$pid] ?? 0);
            $availableStock = max(0, $physicalStock - $reservedStock);

            $sku = strtoupper(trim((string) $product->sku));
            $barcode = strtoupper(trim((string) $product->barcode));
            $barcodeSec = strtoupper(trim((string) ($product->barcode_secondary ?? '')));

            $fullStockAvailable = 0;
            $counted = [];
            if ($sku !== '' && isset($batchData['meli_full_stocks'][$sku])) {
                $fullStockAvailable += (int) $batchData['meli_full_stocks'][$sku];
                $counted[$sku] = true;
            }
            if ($barcode !== '' && ! isset($counted[$barcode]) && isset($batchData['meli_full_stocks'][$barcode])) {
                $fullStockAvailable += (int) $batchData['meli_full_stocks'][$barcode];
                $counted[$barcode] = true;
            }
            if ($barcodeSec !== '' && ! isset($counted[$barcodeSec]) && isset($batchData['meli_full_stocks'][$barcodeSec])) {
                $fullStockAvailable += (int) $batchData['meli_full_stocks'][$barcodeSec];
            }

            $fullStockInTransit = (int) ($batchData['meli_shipment_stocks'][$pid] ?? 0);
        } else {
            // Modo individual (para pruebas o consultas de un solo producto)
            $sales30dBreakdown = $this->getProductSalesBreakdownInWindow($product, $now->copy()->subDays(30), $now);
            $sales60dBreakdown = $this->getProductSalesBreakdownInWindow($product, $now->copy()->subDays(60), $now);
            $sales90dBreakdown = $this->getProductSalesBreakdownInWindow($product, $now->copy()->subDays(90), $now);
            $sales180dBreakdown = $this->getProductSalesBreakdownInWindow($product, $now->copy()->subDays(180), $now);

            $sales30d = $sales30dBreakdown['total'];
            $sales60d = $sales60dBreakdown['total'];
            $sales90d = $sales90dBreakdown['total'];
            $sales180d = $sales180dBreakdown['total'];

            $salesLocal30d = $sales30dBreakdown['local'];
            $salesFull30d = $sales30dBreakdown['full'];

            $dailyRate30d = $sales30d / 30;
            $dailyRate90d = $sales90d / 90;
            $dailyRate180d = $sales180d / 180;

            $baseDailyVelocity = ($dailyRate30d * 0.50) + ($dailyRate90d * 0.30) + ($dailyRate180d * 0.20);
            $seasonalFactor = $this->calculateSeasonalFactor($product, $now);

            $brandConfig = $product->brand ? BrandRestockConfiguration::forBrand($product->brand) : null;

            $physicalStock = $this->stockService->physicalStock($product);
            $reservedStock = $this->stockService->reservedStock($product);
            $availableStock = max(0, $physicalStock - $reservedStock);

            $sku = strtoupper(trim((string) $product->sku));
            $barcode = strtoupper(trim((string) $product->barcode));
            $barcodeSec = strtoupper(trim((string) ($product->barcode_secondary ?? '')));

            $fullStockAvailable = 0;
            if (Schema::hasTable('meli_full_stocks')) {
                $fullStockAvailable = (int) MeliFullStock::query()
                    ->where(function ($q) use ($sku, $barcode, $barcodeSec) {
                        if ($sku !== '') {
                            $q->where('sku', $sku);
                        }
                        if ($barcode !== '') {
                            $q->orWhere('sku', $barcode);
                        }
                        if ($barcodeSec !== '') {
                            $q->orWhere('sku', $barcodeSec);
                        }
                    })
                    ->sum('full_available_quantity');
            }

            $fullStockInTransit = 0;
            if (Schema::hasTable('meli_full_shipments') && Schema::hasTable('meli_full_shipment_items')) {
                $fullStockInTransit = (int) MeliFullShipmentItem::query()
                    ->where('inventory_product_id', $product->id)
                    ->whereHas('shipment', function ($q) {
                        $q->whereIn('status', [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_PACKED]);
                    })
                    ->sum('quantity_sent');
            }
        }

        // Demanda diaria proyectada
        $expectedDailyDemand = round($baseDailyVelocity * $seasonalFactor, 4);

        // Cadencia y parámetros de reabastecimiento
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

        // 5. Cálculos de stock objetivo y reorden
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

        // Días de inventario restante (local)
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
            'barcode_secondary' => $product->barcode_secondary,
            'name' => $product->name,
            'brand' => $product->brand ?: 'Sin marca',
            'supplier' => $product->supplier ?: 'Sin proveedor',
            'cost' => $cost,
            'price_public' => (float) ($product->price_public ?? 0),
            'product_type' => $product->product_type,
            'is_kit' => $product->isKit(),

            // Ventas históricas desglosadas
            'sales_30d' => $sales30d,
            'sales_local_30d' => $salesLocal30d,
            'sales_full_30d' => $salesFull30d,
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

            // Existencias locales y FULL
            'physical_stock' => $physicalStock,
            'reserved_stock' => $reservedStock,
            'available_stock' => $availableStock,
            'full_available_stock' => $fullStockAvailable,
            'full_in_transit_stock' => $fullStockInTransit,
            'total_combined_stock' => $availableStock + $fullStockAvailable,
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
                    ->orWhere('barcode_secondary', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%");
            });
        }

        $products = $query->orderBy('brand')->orderBy('name')->get();

        // Obtener marcas únicas y configuraciones
        $brands = InventoryProduct::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->values();

        $allBrandConfigs = BrandRestockConfiguration::all();
        $brandConfigs = $allBrandConfigs->keyBy('brand');

        if ($products->isEmpty()) {
            return [
                'summary' => [
                    'total_skus' => 0,
                    'critical_count' => 0,
                    'warning_count' => 0,
                    'optimal_count' => 0,
                    'overstock_count' => 0,
                    'total_suggested_units' => 0,
                    'total_estimated_investment' => 0.0,
                    'total_sales_full_30d' => 0,
                    'total_sales_local_30d' => 0,
                ],
                'items' => [],
                'brands' => $brands,
                'brand_configs' => $brandConfigs,
                'presets' => BrandRestockConfiguration::PRESETS,
            ];
        }

        // =========================================================================
        // PRECARGA BATCH (Elimina el problema N+1 que causaba lentitud en el reporte)
        // =========================================================================
        $productIds = $products->pluck('id')->all();
        $now = isset($filters['as_of']) ? Carbon::parse($filters['as_of']) : Carbon::now();

        $date180d = $now->copy()->subDays(180);
        $date90d  = $now->copy()->subDays(90);
        $date60d  = $now->copy()->subDays(60);
        $date30d  = $now->copy()->subDays(30);

        $lyMonthStart = $now->copy()->subYear()->startOfMonth();
        $lyMonthEnd   = $now->copy()->subYear()->endOfMonth();
        $lyYearStart  = $now->copy()->subYear()->startOfYear();
        $lyYearEnd    = $now->copy()->subYear()->endOfYear();

        $minDate = $date180d->lt($lyYearStart) ? $date180d : $lyYearStart;

        // 1. Existencias físicas locales en una sola consulta agrupada
        $physicalStocks = InventoryMovement::query()
            ->whereIn('inventory_product_id', $productIds)
            ->selectRaw('inventory_product_id, SUM(quantity) as qty')
            ->groupBy('inventory_product_id')
            ->pluck('qty', 'inventory_product_id')
            ->all();

        // 2. Existencias reservadas activas en una sola consulta agrupada
        $reservedStocks = InventoryReservation::query()
            ->active()
            ->whereIn('inventory_product_id', $productIds)
            ->selectRaw('inventory_product_id, SUM(quantity) as qty')
            ->groupBy('inventory_product_id')
            ->pluck('qty', 'inventory_product_id')
            ->all();

        // 3. Salidas locales agrupadas por ventanas de tiempo con SUM condicional
        $localMovements = DB::table('inventory_movements')
            ->select('inventory_product_id')
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at <= ? THEN ABS(quantity) ELSE 0 END) as sales_30d', [$date30d, $now])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at <= ? THEN ABS(quantity) ELSE 0 END) as sales_60d', [$date60d, $now])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at <= ? THEN ABS(quantity) ELSE 0 END) as sales_90d', [$date90d, $now])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at <= ? THEN ABS(quantity) ELSE 0 END) as sales_180d', [$date180d, $now])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at <= ? THEN ABS(quantity) ELSE 0 END) as sales_ly_month', [$lyMonthStart, $lyMonthEnd])
            ->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at <= ? THEN ABS(quantity) ELSE 0 END) as sales_ly_year', [$lyYearStart, $lyYearEnd])
            ->whereIn('inventory_product_id', $productIds)
            ->where(function ($q) {
                $q->where('type', InventoryMovement::SALE)
                    ->orWhere('quantity', '<', 0);
            })
            ->where('occurred_at', '>=', $minDate)
            ->where('occurred_at', '<=', $now)
            ->groupBy('inventory_product_id')
            ->get()
            ->keyBy('inventory_product_id');

        // 4. Mapeo de publicaciones MeLi e identificadores (SKUs / Barcodes)
        $mlmToProductIds = [];
        $skuToProductIds = [];

        if (Schema::hasTable('inventory_channel_links')) {
            $channelLinks = InventoryChannelLink::query()
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->whereIn('inventory_product_id', $productIds)
                ->get(['inventory_product_id', 'external_product_id']);

            foreach ($channelLinks as $link) {
                $extId = trim((string) $link->external_product_id);
                if ($extId !== '') {
                    $mlmToProductIds[$extId][] = (int) $link->inventory_product_id;
                }
            }
        }

        foreach ($products as $prod) {
            $sku = strtoupper(trim((string) $prod->sku));
            if ($sku !== '') {
                $skuToProductIds[$sku][] = (int) $prod->id;
            }
            $barcode = strtoupper(trim((string) $prod->barcode));
            if ($barcode !== '') {
                $skuToProductIds[$barcode][] = (int) $prod->id;
            }
            $barcodeSec = strtoupper(trim((string) ($prod->barcode_secondary ?? '')));
            if ($barcodeSec !== '') {
                $skuToProductIds[$barcodeSec][] = (int) $prod->id;
            }
        }

        // 5. Ventas Mercado Libre FULL agrupadas por publicación y SKU
        $fullMetricsByProduct = [];
        if (Schema::hasTable('meli_orders') && Schema::hasTable('meli_order_items')) {
            $allMlms = array_keys($mlmToProductIds);
            $allSkus = array_keys($skuToProductIds);

            if (! empty($allMlms) || ! empty($allSkus)) {
                $fullOrderItems = DB::table('meli_orders as o')
                    ->join('meli_order_items as i', 'i.meli_order_id', '=', 'o.id')
                    ->whereRaw("LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'invalid')")
                    ->where(function ($q) {
                        $q->whereRaw("LOWER(COALESCE(o.shipping_logistic_type, '')) = 'fulfillment'")
                            ->orWhereRaw("LOWER(COALESCE(o.shipping_mode, '')) = 'fulfillment'")
                            ->orWhereRaw("LOWER(COALESCE(o.shipping_type, '')) = 'fulfillment'");
                    })
                    ->where('o.created_at', '>=', $minDate)
                    ->where('o.created_at', '<=', $now)
                    ->where(function ($q) use ($allMlms, $allSkus) {
                        $hasCond = false;
                        if (! empty($allMlms)) {
                            $q->whereIn('i.item_id', $allMlms);
                            $hasCond = true;
                        }
                        if (! empty($allSkus)) {
                            if ($hasCond) {
                                $q->orWhereIn('i.sku', $allSkus);
                            } else {
                                $q->whereIn('i.sku', $allSkus);
                            }
                        }
                    })
                    ->select('i.item_id', 'i.sku')
                    ->selectRaw('SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN i.quantity ELSE 0 END) as qty_30d', [$date30d, $now])
                    ->selectRaw('SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN i.quantity ELSE 0 END) as qty_60d', [$date60d, $now])
                    ->selectRaw('SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN i.quantity ELSE 0 END) as qty_90d', [$date90d, $now])
                    ->selectRaw('SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN i.quantity ELSE 0 END) as qty_180d', [$date180d, $now])
                    ->selectRaw('SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN i.quantity ELSE 0 END) as qty_ly_month', [$lyMonthStart, $lyMonthEnd])
                    ->selectRaw('SUM(CASE WHEN o.created_at >= ? AND o.created_at <= ? THEN i.quantity ELSE 0 END) as qty_ly_year', [$lyYearStart, $lyYearEnd])
                    ->groupBy('i.item_id', 'i.sku')
                    ->get();

                foreach ($fullOrderItems as $row) {
                    $matchedProductIds = [];
                    $itemId = trim((string) $row->item_id);
                    if ($itemId !== '' && isset($mlmToProductIds[$itemId])) {
                        foreach ($mlmToProductIds[$itemId] as $pid) {
                            $matchedProductIds[$pid] = true;
                        }
                    }
                    $itemSku = strtoupper(trim((string) $row->sku));
                    if ($itemSku !== '' && isset($skuToProductIds[$itemSku])) {
                        foreach ($skuToProductIds[$itemSku] as $pid) {
                            $matchedProductIds[$pid] = true;
                        }
                    }

                    foreach (array_keys($matchedProductIds) as $pid) {
                        if (! isset($fullMetricsByProduct[$pid])) {
                            $fullMetricsByProduct[$pid] = [
                                'qty_30d' => 0,
                                'qty_60d' => 0,
                                'qty_90d' => 0,
                                'qty_180d' => 0,
                                'qty_ly_month' => 0,
                                'qty_ly_year' => 0,
                            ];
                        }
                        $fullMetricsByProduct[$pid]['qty_30d'] += (int) $row->qty_30d;
                        $fullMetricsByProduct[$pid]['qty_60d'] += (int) $row->qty_60d;
                        $fullMetricsByProduct[$pid]['qty_90d'] += (int) $row->qty_90d;
                        $fullMetricsByProduct[$pid]['qty_180d'] += (int) $row->qty_180d;
                        $fullMetricsByProduct[$pid]['qty_ly_month'] += (int) $row->qty_ly_month;
                        $fullMetricsByProduct[$pid]['qty_ly_year'] += (int) $row->qty_ly_year;
                    }
                }
            }
        }

        // 6. Stock disponible en bodega MeLi FULL agrupado
        $meliFullStockMap = [];
        if (Schema::hasTable('meli_full_stocks')) {
            $meliFullStockMap = DB::table('meli_full_stocks')
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->selectRaw('UPPER(TRIM(sku)) as clean_sku, SUM(full_available_quantity) as total_qty')
                ->groupBy(DB::raw('UPPER(TRIM(sku))'))
                ->pluck('total_qty', 'clean_sku')
                ->all();
        }

        // 7. Stock MeLi FULL en tránsito agrupado por producto
        $meliShipmentStockMap = [];
        if (Schema::hasTable('meli_full_shipments') && Schema::hasTable('meli_full_shipment_items')) {
            $meliShipmentStockMap = DB::table('meli_full_shipment_items as i')
                ->join('meli_full_shipments as s', 's.id', '=', 'i.meli_full_shipment_id')
                ->whereIn('s.status', [MeliFullShipment::STATUS_IN_TRANSIT, MeliFullShipment::STATUS_PACKED])
                ->whereIn('i.inventory_product_id', $productIds)
                ->select('i.inventory_product_id', DB::raw('SUM(i.quantity_sent) as total_qty'))
                ->groupBy('i.inventory_product_id')
                ->pluck('total_qty', 'i.inventory_product_id')
                ->all();
        }

        $brandConfigsMap = [];
        foreach ($allBrandConfigs as $cfg) {
            $brandConfigsMap[mb_strtoupper(trim((string) $cfg->brand))] = $cfg;
        }

        $batchData = [
            'now' => $now,
            'physical_stocks' => $physicalStocks,
            'reserved_stocks' => $reservedStocks,
            'brand_configs' => $brandConfigsMap,
            'local_movements' => $localMovements,
            'full_metrics' => $fullMetricsByProduct,
            'meli_full_stocks' => $meliFullStockMap,
            'meli_shipment_stocks' => $meliShipmentStockMap,
        ];

        $items = [];
        $totalSkus = 0;
        $criticalCount = 0;
        $warningCount = 0;
        $optimalCount = 0;
        $overstockCount = 0;
        $totalSuggestedUnits = 0;
        $totalEstimatedInvestment = 0.0;
        $totalSalesFull30d = 0;
        $totalSalesLocal30d = 0;

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
            if (isset($filters['as_of'])) {
                $options['as_of'] = $filters['as_of'];
            }

            $forecast = $this->getProductForecast($prod, $options, $batchData);

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
            $totalSalesFull30d += $forecast['sales_full_30d'];
            $totalSalesLocal30d += $forecast['sales_local_30d'];
        }

        return [
            'summary' => [
                'total_skus' => $totalSkus,
                'critical_count' => $criticalCount,
                'warning_count' => $warningCount,
                'optimal_count' => $optimalCount,
                'overstock_count' => $overstockCount,
                'total_suggested_units' => $totalSuggestedUnits,
                'total_estimated_investment' => round($totalEstimatedInvestment, 2),
                'total_sales_full_30d' => $totalSalesFull30d,
                'total_sales_local_30d' => $totalSalesLocal30d,
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

    /**
     * Obtener ventas desglosadas (Local POS + Mercado Libre FULL) en ventana de tiempo
     *
     * @return array{local: int, full: int, total: int}
     */
    public function getProductSalesBreakdownInWindow(InventoryProduct $product, Carbon $from, Carbon $to): array
    {
        // 1. Ventas en almacén físico local (mostrador POS, salidas)
        $localSales = (int) abs(InventoryMovement::query()
            ->where('inventory_product_id', $product->id)
            ->where(function ($q) {
                $q->where('type', InventoryMovement::SALE)
                    ->orWhere('quantity', '<', 0);
            })
            ->whereBetween('occurred_at', [$from, $to])
            ->sum('quantity'));

        // 2. Ventas despachadas directamente por Mercado Libre FULL
        $sku = strtoupper(trim((string) $product->sku));
        $barcode = strtoupper(trim((string) $product->barcode));
        $barcodeSec = strtoupper(trim((string) ($product->barcode_secondary ?? '')));

        $fullSales = 0;
        if (Schema::hasTable('meli_orders') && Schema::hasTable('meli_order_items')) {
            $linkedMlms = [];
            if (Schema::hasTable('inventory_channel_links')) {
                $linkedMlms = InventoryChannelLink::query()
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->where('inventory_product_id', $product->id)
                    ->pluck('external_product_id')
                    ->filter()
                    ->all();
            }

            if (! empty($linkedMlms) || $sku !== '' || $barcode !== '' || $barcodeSec !== '') {
                $fullQuery = DB::table('meli_orders as o')
                    ->join('meli_order_items as i', 'i.meli_order_id', '=', 'o.id')
                    ->where(function ($q) use ($linkedMlms, $sku, $barcode, $barcodeSec) {
                        $hasCondition = false;
                        if (! empty($linkedMlms)) {
                            $q->whereIn('i.item_id', $linkedMlms);
                            $hasCondition = true;
                        }
                        if ($sku !== '') {
                            if ($hasCondition) {
                                $q->orWhereRaw('UPPER(TRIM(i.sku)) = ?', [$sku]);
                            } else {
                                $q->whereRaw('UPPER(TRIM(i.sku)) = ?', [$sku]);
                                $hasCondition = true;
                            }
                        }
                        if ($barcode !== '') {
                            $q->orWhereRaw('UPPER(TRIM(i.sku)) = ?', [$barcode]);
                        }
                        if ($barcodeSec !== '') {
                            $q->orWhereRaw('UPPER(TRIM(i.sku)) = ?', [$barcodeSec]);
                        }
                    })
                    ->whereRaw("LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'invalid')")
                    ->where(function ($q) {
                        $q->whereRaw("LOWER(COALESCE(o.shipping_logistic_type, '')) = 'fulfillment'")
                            ->orWhereRaw("LOWER(COALESCE(o.shipping_mode, '')) = 'fulfillment'")
                            ->orWhereRaw("LOWER(COALESCE(o.shipping_type, '')) = 'fulfillment'");
                    })
                    ->whereBetween('o.created_at', [$from, $to]);

                $fullSales = (int) $fullQuery->sum('i.quantity');
            }
        }

        return [
            'local' => $localSales,
            'full'  => $fullSales,
            'total' => $localSales + $fullSales,
        ];
    }

    private function calculateSeasonalFactor(InventoryProduct $product, Carbon $now): float
    {
        $lyMonthStart = $now->copy()->subYear()->startOfMonth();
        $lyMonthEnd = $now->copy()->subYear()->endOfMonth();

        $lyYearStart = $now->copy()->subYear()->startOfYear();
        $lyYearEnd = $now->copy()->subYear()->endOfYear();

        $salesSameMonthLastYear = $this->getProductSalesBreakdownInWindow($product, $lyMonthStart, $lyMonthEnd)['total'];
        $salesFullLastYear = $this->getProductSalesBreakdownInWindow($product, $lyYearStart, $lyYearEnd)['total'];

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
