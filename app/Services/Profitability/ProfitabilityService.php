<?php

namespace App\Services\Profitability;

use App\Models\Llanta;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\MeliPublication;
use App\Models\ProductoCompuesto;
use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Services\SyscomProductPricingService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProfitabilityService
{
    /** @var array<string, ?Llanta> */
    protected array $llantaCache = [];

    /** @var array<string, ?SyscomProduct> */
    protected array $syscomProductCache = [];

    /** @var array<string, string> */
    protected array $orderChannelCache = [];

    public function __construct(
        protected SyscomProductPricingService $syscomPricing
    ) {}

    /**
     * Resumen general y cálculos de rentabilidad y comisiones.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getSummary(array $filters = []): array
    {
        $data = $this->getProcessedOrders($filters);
        $processedOrders = $data['orders'];
        $startDate = $data['startDate'];
        $endDate = $data['endDate'];
        $periodLabel = $data['periodLabel'];
        $commissionPct = $data['commissionPct'];
        $commissionBase = $data['commissionBase'];
        $channelFilter = $data['channelFilter'];
        $statusFilter = $data['statusFilter'];
        $search = $data['search'];
        $fallbackFeePct = $data['fallbackFeePct'];

        // Calcular KPIs agregados
        $metrics = $this->aggregateMetrics($processedOrders, $commissionPct, $commissionBase);

        // Sub-métricas por canal (SYSCOM vs Llantas)
        $syscomOrders = $processedOrders->filter(fn (array $o) => $o['channel'] === 'syscom');
        $llantasOrders = $processedOrders->filter(fn (array $o) => $o['channel'] === 'llantas');

        $metrics['syscom'] = $this->aggregateChannelMetrics($syscomOrders);
        $metrics['llantas'] = $this->aggregateChannelMetrics($llantasOrders);

        // Escenarios de propuesta de comisión para presentar al jefe
        $metrics['scenarios'] = $this->calculateCommissionScenarios($metrics['total_net_profit'], $metrics['total_sales_net']);

        // Línea temporal (Timeline para gráficas diarias)
        $timeline = $this->buildTimeline($processedOrders, $startDate, $endDate);

        // Paginación manual de la colección filtrada
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 30)));
        $paginatedOrders = new \Illuminate\Pagination\LengthAwarePaginator(
            $processedOrders->forPage($page, $perPage)->values(),
            $processedOrders->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return [
            'metrics' => $metrics,
            'timeline' => $timeline,
            'orders' => $paginatedOrders,
            'filters' => [
                'period' => $filters['period'] ?? 'este_mes',
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
                'channel' => $channelFilter,
                'status' => $statusFilter,
                'search' => $search,
                'commission_pct' => $commissionPct,
                'commission_base' => $commissionBase,
                'fallback_fee_pct' => $fallbackFeePct,
                'period_label' => $periodLabel,
            ],
        ];
    }

    /**
     * Consulta y procesa todas las órdenes que coinciden con los filtros (sin paginar).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getProcessedOrders(array $filters = []): array
    {
        [$startDate, $endDate, $periodLabel] = $this->resolveDateRange($filters);
        $channelFilter = trim((string) ($filters['channel'] ?? 'all'));
        $statusFilter = trim((string) ($filters['status'] ?? 'all'));
        $search = trim((string) ($filters['search'] ?? ''));
        $commissionPct = max(0, (float) ($filters['commission_pct'] ?? 10.0));
        $commissionBase = in_array($filters['commission_base'] ?? '', ['profit', 'sales'], true)
            ? $filters['commission_base']
            : 'profit';
        $fallbackFeePct = max(0, (float) ($filters['fallback_fee_pct'] ?? 15.0));

        // Obtener órdenes en el rango de fechas
        $query = MeliOrder::query()
            ->with(['items', 'claims'])
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]);

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('order_id', 'like', "%{$search}%")
                    ->orWhere('syscom_order_folio', 'like', "%{$search}%")
                    ->orWhereHas('items', function (Builder $itemQuery) use ($search) {
                        $itemQuery->where('sku', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%")
                            ->orWhere('item_id', 'like', "%{$search}%");
                    });
            });
        }

        $allOrders = $query->orderByDesc('created_at')->get();

        // Procesar y clasificar cada orden
        $processedOrders = $allOrders->map(function (MeliOrder $order) use ($fallbackFeePct) {
            return $this->calculateOrderProfitability($order, $fallbackFeePct);
        });

        // Filtrar por canal
        if ($channelFilter !== 'all') {
            $processedOrders = $processedOrders->filter(fn (array $o) => $o['channel'] === $channelFilter);
        }

        // Filtrar por estado
        if ($statusFilter === 'ok') {
            $processedOrders = $processedOrders->filter(fn (array $o) => ! $o['is_cancelled_or_returned']);
        } elseif ($statusFilter === 'cancelled_returned') {
            $processedOrders = $processedOrders->filter(fn (array $o) => $o['is_cancelled_or_returned']);
        }

        return [
            'orders' => $processedOrders->values(),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'periodLabel' => $periodLabel,
            'channelFilter' => $channelFilter,
            'statusFilter' => $statusFilter,
            'search' => $search,
            'commissionPct' => $commissionPct,
            'commissionBase' => $commissionBase,
            'fallbackFeePct' => $fallbackFeePct,
        ];
    }

    /**
     * Calcula los datos financieros de una orden específica de Mercado Libre.
     *
     * @return array<string, mixed>
     */
    public function calculateOrderProfitability(MeliOrder $order, float $fallbackFeePct = 15.0): array
    {
        $channel = $this->resolveOrderChannel($order);
        $isCancelledOrReturned = $this->isCancelledOrReturned($order);

        // 1. Venta Bruta
        $raw = is_array($order->raw) ? $order->raw : [];
        $grossSale = (float) data_get($raw, 'total_amount', 0);
        if ($grossSale <= 0 && $order->items->isNotEmpty()) {
            $grossSale = (float) $order->items->sum(fn (MeliOrderItem $i) => ((float) $i->unit_price) * ((int) $i->quantity));
        }

        // 2. Comisiones y Envíos de Mercado Libre
        $meliFee = 0.0;
        $shippingFee = 0.0;
        $taxesFee = (float) data_get($raw, 'taxes.amount', 0);

        // Extraer cargo por venta (sale_fee) exacto de items en raw
        $rawItems = data_get($raw, 'order_items', []);
        $exactFeeFound = false;

        if (is_array($rawItems) && ! empty($rawItems)) {
            foreach ($rawItems as $rItem) {
                $fee = data_get($rItem, 'sale_fee');
                if (is_numeric($fee) && $fee > 0) {
                    $meliFee += (float) $fee;
                    $exactFeeFound = true;
                }
            }
        }

        // Si no vino sale_fee por ítem, revisar payments
        if (! $exactFeeFound) {
            $payments = data_get($raw, 'payments', []);
            if (is_array($payments)) {
                foreach ($payments as $p) {
                    $mpFee = data_get($p, 'marketplace_fee');
                    if (is_numeric($mpFee) && $mpFee > 0) {
                        $meliFee += (float) $mpFee;
                        $exactFeeFound = true;
                    }
                }
            }
        }

        // Si no se encontró desglose exacto, aplicar porcentaje fallback sobre la venta bruta
        if (! $exactFeeFound && $grossSale > 0) {
            $meliFee = round($grossSale * ($fallbackFeePct / 100), 2);
        }

        // Costo de envío absorbido por el vendedor
        $shipCost = data_get($raw, 'shipping.cost')
            ?? data_get($raw, 'shipping.base_cost')
            ?? data_get($raw, 'shipping.shipping_option.cost')
            ?? data_get($raw, 'shipping.shipping_option.list_cost');
        if (is_numeric($shipCost) && $shipCost > 0) {
            $shippingFee = (float) $shipCost;
        }

        // 3. Costo del Producto (Costo de adquisición / mercancía)
        $productCost = $this->resolveOrderCost($order, $channel);

        // 4. Ganancia Real Neta
        if ($isCancelledOrReturned) {
            // En devoluciones/cancelaciones, la venta se reembolsa, ganancia neta es 0
            $netSale = 0.0;
            $netProfit = 0.0;
            $profitMarginPct = 0.0;
        } else {
            $netSale = $grossSale;
            // Ganancia Neta Real = Venta Bruta - Comisión ML - Envío - Costo Producto
            $netProfit = round($grossSale - $meliFee - $shippingFee - $productCost, 2);
            $profitMarginPct = $grossSale > 0 ? round(($netProfit / $grossSale) * 100, 2) : 0.0;
        }

        // Descripción / Título principal de la orden
        $firstItem = $order->items->first();
        $title = $firstItem?->title ?? 'Pedido #' . $order->order_id;
        $sku = $firstItem?->sku ?? '—';
        $quantity = (int) $order->items->sum('quantity');

        return [
            'id' => $order->id,
            'order_id' => (string) $order->order_id,
            'created_at' => $order->created_at?->format('d/m/Y H:i'),
            'date_raw' => $order->created_at?->toDateString(),
            'channel' => $channel, // 'syscom' | 'llantas' | 'otro'
            'channel_label' => match ($channel) {
                'syscom' => 'SYSCOM',
                'llantas' => 'Llantas',
                default => 'Otro',
            },
            'status' => $order->status,
            'shipping_status' => $order->shipping_status ?? '—',
            'is_cancelled_or_returned' => $isCancelledOrReturned,
            'cancellation_reason' => $this->resolveCancellationReason($order),
            'syscom_order_folio' => $order->syscom_order_folio,
            'title' => $title,
            'sku' => $sku,
            'quantity' => max(1, $quantity),
            'gross_sale' => $grossSale,
            'product_cost' => $productCost,
            'meli_fee' => $meliFee,
            'shipping_fee' => $shippingFee,
            'taxes_fee' => $taxesFee,
            'total_deductions' => round($meliFee + $shippingFee + $productCost, 2),
            'net_sale' => $netSale,
            'net_profit' => $netProfit,
            'profit_margin_pct' => $profitMarginPct,
            'has_syscom_raw' => ! empty($order->syscom_order_raw),
        ];
    }

    /**
     * Resuelve el costo de compra / adquisición para la orden según su canal.
     */
    protected function resolveOrderCost(MeliOrder $order, string $channel): float
    {
        // 1. Canal SYSCOM
        if ($channel === 'syscom') {
            // Intentar extraer el total exacto pagado a SYSCOM desde syscom_order_raw
            if (! empty($order->syscom_order_raw) && is_array($order->syscom_order_raw)) {
                $rawCost = data_get($order->syscom_order_raw, 'resumen.total')
                    ?? data_get($order->syscom_order_raw, 'total');
                if (is_numeric($rawCost) && $rawCost > 0) {
                    return round((float) $rawCost, 2);
                }

                // O sumar los productos del carrito SYSCOM
                $syscomProds = data_get($order->syscom_order_raw, 'productos', []);
                if (is_array($syscomProds) && ! empty($syscomProds)) {
                    $sum = 0.0;
                    foreach ($syscomProds as $sp) {
                        $p = data_get($sp, 'precio') ?? data_get($sp, 'precio_unitario');
                        $cant = (int) (data_get($sp, 'cantidad') ?? 1);
                        if (is_numeric($p)) {
                            $sum += ((float) $p) * $cant;
                        }
                    }
                    if ($sum > 0) {
                        return round($sum, 2);
                    }
                }
            }

            // Fallback: calcular costo buscando los productos en SyscomProduct
            $totalCost = 0.0;
            foreach ($order->items as $item) {
                $qty = max(1, (int) $item->quantity);
                $sku = trim((string) $item->sku);
                $syscomProd = $this->findSyscomProduct($sku, (string) $item->item_id);

                if ($syscomProd) {
                    $baseUsd = $this->syscomPricing->baseCosto($syscomProd);
                    $costMxn = $this->syscomPricing->usdToMxnWithIva($baseUsd);
                    $totalCost += ($costMxn * $qty);
                } else {
                    // Si no se encuentra en catálogo, estimar usando la fórmula estándar de SYSCOM (costo * 1.12)
                    $unitPrice = (float) $item->unit_price;
                    $totalCost += round(($unitPrice / 1.12) * $qty, 2);
                }
            }

            return round($totalCost, 2);
        }

        // 2. Canal LLANTAS
        if ($channel === 'llantas') {
            $totalCost = 0.0;
            foreach ($order->items as $item) {
                $qty = max(1, (int) $item->quantity);
                $sku = trim((string) $item->sku);
                $mlm = trim((string) $item->item_id);

                $llanta = $this->findLlanta($sku, $mlm);
                if ($llanta && (float) $llanta->costo > 0) {
                    $totalCost += (((float) $llanta->costo) * $qty);
                } else {
                    // Revisar si es producto compuesto (ej. par o juego de llantas)
                    $compuesto = ProductoCompuesto::query()->where('mlm', $mlm)->with('llanta')->first();
                    if ($compuesto && $compuesto->llanta && (float) $compuesto->llanta->costo > 0) {
                        $totalCost += (((float) $compuesto->llanta->costo) * (int) ($compuesto->cantidad ?? 1) * $qty);
                    } else {
                        // Si no tiene costo explícito, estimar usando la fórmula estándar (costo * 1.5)
                        $unitPrice = (float) $item->unit_price;
                        $totalCost += round(($unitPrice / 1.5) * $qty, 2);
                    }
                }
            }

            return round($totalCost, 2);
        }

        // Canal genérico / otro: estimar 70% del valor de venta si no hay dato
        $gross = (float) $order->items->sum(fn ($i) => ((float) $i->unit_price) * ((int) $i->quantity));

        return round($gross * 0.70, 2);
    }

    /**
     * Determina el canal de la orden (syscom, llantas u otro).
     */
    public function resolveOrderChannel(MeliOrder $order): string
    {
        $orderId = (string) $order->id;
        if (isset($this->orderChannelCache[$orderId])) {
            return $this->orderChannelCache[$orderId];
        }

        // Si tiene folio o sincronización con SYSCOM
        if (filled($order->syscom_order_folio) || $order->syscom_order_synced_at !== null || ! empty($order->syscom_order_raw)) {
            $this->orderChannelCache[$orderId] = 'syscom';

            return 'syscom';
        }

        // Verificar por items de la orden
        foreach ($order->items as $item) {
            $mlm = trim((string) $item->item_id);
            $sku = trim((string) $item->sku);

            // 1. Revisar si está en la cola o catálogo de SYSCOM
            if (SyscomMeliQueue::query()->where('mlm', $mlm)->exists()) {
                $this->orderChannelCache[$orderId] = 'syscom';

                return 'syscom';
            }

            if ($this->findSyscomProduct($sku, $mlm) !== null) {
                $this->orderChannelCache[$orderId] = 'syscom';

                return 'syscom';
            }

            // 2. Revisar si es una Llanta
            if ($this->findLlanta($sku, $mlm) !== null) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }

            if (ProductoCompuesto::query()->where('mlm', $mlm)->exists()) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }

            if (MeliPublication::query()->where('mlm', $mlm)->whereHas('llanta')->exists()) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }
        }

        // Clasificación por palabras clave en títulos o SKU
        $firstItemTitle = mb_strtolower((string) $order->items->first()?->title);
        if (str_contains($firstItemTitle, 'llanta') || str_contains($firstItemTitle, 'neumatico') || preg_match('/\d{3}\/\d{2}[rR]\d{2}/', $firstItemTitle)) {
            $this->orderChannelCache[$orderId] = 'llantas';

            return 'llantas';
        }

        $this->orderChannelCache[$orderId] = 'syscom';

        return 'syscom';
    }

    /**
     * Verifica si una orden fue cancelada o devuelta.
     */
    protected function isCancelledOrReturned(MeliOrder $order): bool
    {
        $status = mb_strtolower(trim((string) $order->status));
        if (in_array($status, ['cancelled', 'canceled', 'invalid', 'expired'], true)) {
            return true;
        }

        if ($order->syscom_order_cancelled_at !== null) {
            return true;
        }

        $shipStatus = mb_strtolower(trim((string) ($order->shipping_status ?? '')));
        if (in_array($shipStatus, ['cancelled', 'canceled', 'returned', 'returning_to_sender'], true)) {
            return true;
        }

        // Revisar reclamos con devolución
        if ($order->relationLoaded('claims') && $order->claims->isNotEmpty()) {
            foreach ($order->claims as $claim) {
                $type = mb_strtolower((string) ($claim->type ?? ''));
                $claimStage = mb_strtolower((string) ($claim->stage ?? ''));
                if (str_contains($type, 'return') || str_contains($claimStage, 'return') || str_contains($type, 'devolucion')) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function resolveCancellationReason(MeliOrder $order): ?string
    {
        if ($order->syscom_order_cancelled_at !== null) {
            return 'Cancelado en SYSCOM (' . $order->syscom_order_cancelled_at->format('d/m/Y') . ')';
        }

        $status = mb_strtolower(trim((string) $order->status));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return 'Cancelado en Mercado Libre';
        }

        $shipStatus = mb_strtolower(trim((string) ($order->shipping_status ?? '')));
        if (in_array($shipStatus, ['returned', 'returning_to_sender'], true)) {
            return 'Paquete devuelto por paquetería';
        }

        return null;
    }

    protected function findSyscomProduct(string $sku, string $mlm): ?SyscomProduct
    {
        $cacheKey = "syscom:{$sku}:{$mlm}";
        if (array_key_exists($cacheKey, $this->syscomProductCache)) {
            return $this->syscomProductCache[$cacheKey];
        }

        $prod = null;
        if ($sku !== '') {
            $prod = SyscomProduct::query()->where('modelo', $sku)->first();
        }

        if (! $prod && $mlm !== '') {
            $queue = SyscomMeliQueue::query()->where('mlm', $mlm)->with('syscomProduct')->first();
            $prod = $queue?->syscomProduct;
        }

        $this->syscomProductCache[$cacheKey] = $prod;

        return $prod;
    }

    protected function findLlanta(string $sku, string $mlm): ?Llanta
    {
        $cacheKey = "llanta:{$sku}:{$mlm}";
        if (array_key_exists($cacheKey, $this->llantaCache)) {
            return $this->llantaCache[$cacheKey];
        }

        $llanta = null;
        if ($sku !== '') {
            $llanta = Llanta::query()->where('sku', $sku)->first();
        }

        if (! $llanta && $mlm !== '') {
            $llanta = Llanta::query()->where('MLM', $mlm)->first();
        }

        $this->llantaCache[$cacheKey] = $llanta;

        return $llanta;
    }

    /**
     * @param Collection<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    protected function aggregateMetrics(Collection $orders, float $commissionPct, string $commissionBase): array
    {
        $totalOrders = $orders->count();
        $cancelledOrders = $orders->filter(fn (array $o) => $o['is_cancelled_or_returned']);
        $deliveredOrders = $orders->filter(fn (array $o) => ! $o['is_cancelled_or_returned']);

        $grossSalesTotal = round($orders->sum('gross_sale'), 2);
        $returnsAmountTotal = round($cancelledOrders->sum('gross_sale'), 2);
        $netSalesTotal = round($deliveredOrders->sum('gross_sale'), 2);

        $productCostTotal = round($deliveredOrders->sum('product_cost'), 2);
        $meliFeesTotal = round($deliveredOrders->sum('meli_fee'), 2);
        $shippingFeesTotal = round($deliveredOrders->sum('shipping_fee'), 2);
        $totalDeductions = round($productCostTotal + $meliFeesTotal + $shippingFeesTotal, 2);

        $netProfitTotal = round($deliveredOrders->sum('net_profit'), 2);
        $overallMarginPct = $netSalesTotal > 0 ? round(($netProfitTotal / $netSalesTotal) * 100, 2) : 0.0;

        // Cálculo de Comisión Propuesta para el usuario
        $baseAmount = $commissionBase === 'sales' ? $netSalesTotal : max(0, $netProfitTotal);
        $userCommission = round($baseAmount * ($commissionPct / 100), 2);
        $companyProfit = round($netProfitTotal - $userCommission, 2);

        return [
            'total_orders_count' => $totalOrders,
            'delivered_orders_count' => $deliveredOrders->count(),
            'cancelled_returned_count' => $cancelledOrders->count(),
            'total_sales_gross' => $grossSalesTotal,
            'total_returns_amount' => $returnsAmountTotal,
            'total_sales_net' => $netSalesTotal,
            'total_product_cost' => $productCostTotal,
            'total_meli_fees' => $meliFeesTotal,
            'total_shipping_fees' => $shippingFeesTotal,
            'total_deductions' => $totalDeductions,
            'total_net_profit' => $netProfitTotal,
            'profit_margin_pct' => $overallMarginPct,
            'commission' => [
                'rate_pct' => $commissionPct,
                'base' => $commissionBase,
                'base_label' => $commissionBase === 'sales' ? 'Venta Neta' : 'Ganancia Neta Real',
                'user_amount' => $userCommission,
                'company_amount' => $companyProfit,
                'company_share_pct' => $netProfitTotal > 0 ? round(($companyProfit / $netProfitTotal) * 100, 2) : 100.0,
            ],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    protected function aggregateChannelMetrics(Collection $orders): array
    {
        $total = $orders->count();
        $cancelled = $orders->filter(fn (array $o) => $o['is_cancelled_or_returned']);
        $active = $orders->filter(fn (array $o) => ! $o['is_cancelled_or_returned']);

        $gross = round($orders->sum('gross_sale'), 2);
        $returns = round($cancelled->sum('gross_sale'), 2);
        $netSales = round($active->sum('gross_sale'), 2);
        $cost = round($active->sum('product_cost'), 2);
        $fees = round($active->sum('meli_fee') + $active->sum('shipping_fee'), 2);
        $profit = round($active->sum('net_profit'), 2);
        $margin = $netSales > 0 ? round(($profit / $netSales) * 100, 2) : 0.0;

        return [
            'total_orders' => $total,
            'active_orders' => $active->count(),
            'cancelled_orders' => $cancelled->count(),
            'gross_sales' => $gross,
            'returns_amount' => $returns,
            'net_sales' => $netSales,
            'product_cost' => $cost,
            'fees_and_shipping' => $fees,
            'net_profit' => $profit,
            'margin_pct' => $margin,
        ];
    }

    /**
     * Escenarios precalculados (ej. 3%, 5%, 8%, 10%, 12%, 15%, 20%) para presentar al jefe.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function calculateCommissionScenarios(float $netProfit, float $netSales): array
    {
        $rates = [3, 5, 8, 10, 12, 15, 20];
        $scenarios = [];

        foreach ($rates as $rate) {
            $userOnProfit = round(max(0, $netProfit) * ($rate / 100), 2);
            $companyOnProfit = round($netProfit - $userOnProfit, 2);

            $userOnSales = round(max(0, $netSales) * ($rate / 100), 2);
            $companyOnSales = round($netProfit - $userOnSales, 2);

            $scenarios[] = [
                'rate_pct' => $rate,
                'on_profit' => [
                    'user_commission' => $userOnProfit,
                    'company_net' => $companyOnProfit,
                ],
                'on_sales' => [
                    'user_commission' => $userOnSales,
                    'company_net' => $companyOnSales,
                ],
            ];
        }

        return $scenarios;
    }

    /**
     * Agrupa las ventas y ganancias por día para la gráfica temporal.
     *
     * @param Collection<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    protected function buildTimeline(Collection $orders, Carbon $start, Carbon $end): array
    {
        $grouped = $orders->groupBy('date_raw');
        $timeline = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->toDateString();
            $dayOrders = $grouped->get($dateKey, collect());

            $dayDelivered = $dayOrders->filter(fn (array $o) => ! $o['is_cancelled_or_returned']);
            $dayCancelled = $dayOrders->filter(fn (array $o) => $o['is_cancelled_or_returned']);

            $timeline[] = [
                'date' => $cursor->format('d/m'),
                'date_full' => $dateKey,
                'sales' => round($dayDelivered->sum('gross_sale'), 2),
                'returns' => round($dayCancelled->sum('gross_sale'), 2),
                'cost' => round($dayDelivered->sum('product_cost'), 2),
                'profit' => round($dayDelivered->sum('net_profit'), 2),
                'orders_count' => $dayOrders->count(),
            ];

            $cursor->addDay();
        }

        return $timeline;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function resolveDateRange(array $filters): array
    {
        $period = trim((string) ($filters['period'] ?? 'este_mes'));

        return match ($period) {
            'hoy' => [
                now()->startOfDay(),
                now()->endOfDay(),
                'Hoy (' . now()->format('d/m/Y') . ')',
            ],
            'esta_semana' => [
                now()->startOfWeek(),
                now()->endOfWeek(),
                'Esta semana (' . now()->startOfWeek()->format('d/m') . ' - ' . now()->endOfWeek()->format('d/m') . ')',
            ],
            'mes_pasado' => [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
                'Mes pasado (' . now()->subMonth()->translatedFormat('F Y') . ')',
            ],
            'ultimos_30' => [
                now()->subDays(29)->startOfDay(),
                now()->endOfDay(),
                'Últimos 30 días',
            ],
            'ultimos_90' => [
                now()->subDays(89)->startOfDay(),
                now()->endOfDay(),
                'Últimos 90 días',
            ],
            'anio_actual' => [
                now()->startOfYear(),
                now()->endOfYear(),
                'Año ' . now()->year,
            ],
            'custom' => [
                ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfMonth(),
                ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay(),
                'Periodo personalizado',
            ],
            default => [
                now()->startOfMonth(),
                now()->endOfMonth(),
                'Este mes (' . now()->translatedFormat('F Y') . ')',
            ],
        };
    }
}
