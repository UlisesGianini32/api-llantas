<?php

namespace App\Services\Profitability;

use App\Models\Llanta;
use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\MeliPublication;
use App\Models\ProductoCompuesto;
use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Models\User;
use App\Services\SyscomProductPricingService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class ProfitabilityService
{
    /** @var array<string, ?Llanta> */
    protected array $llantaCache = [];

    /** @var array<string, ?SyscomProduct> */
    protected array $syscomProductCache = [];

    /** @var array<string, string> */
    protected array $orderChannelCache = [];

    /** @var array<string, mixed> */
    protected array $preloadedData = [];

    /** @var ?float */
    protected ?float $cachedExchangeRate = null;

    /** @var int */
    protected int $remoteShippingLookupsCount = 0;

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

        // Calcular KPIs agregados para las órdenes visibles
        $metrics = $this->aggregateMetrics($processedOrders, $commissionPct, $commissionBase);

        // Sub-métricas por canal (SYSCOM vs Llantas) en el periodo
        $metrics['syscom'] = $this->aggregateChannelMetrics($data['periodSyscomOrders'] ?? collect());
        $metrics['llantas'] = $this->aggregateChannelMetrics($data['periodLlantasOrders'] ?? collect());
        $metrics['channel_counts'] = $data['channelCounts'] ?? [
            'all' => $processedOrders->count(),
            'syscom' => 0,
            'llantas' => 0,
            'otro' => 0,
            'todo' => $processedOrders->count(),
        ];

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

        // Obtener columnas existentes de forma segura por si alguna migración aún no se ha corrido en el servidor
        try {
            $existingCols = array_flip(Schema::getColumnListing('meli_orders'));
        } catch (\Throwable) {
            $existingCols = [];
        }

        $desiredCols = [
            'id',
            'meli_account_id',
            'order_id',
            'pack_id',
            'display_id',
            'shipping_id',
            'status',
            'shipping_status',
            'shipping_mode',
            'delivery_type',
            'syscom_order_folio',
            'syscom_order_synced_at',
            'syscom_order_cancelled_at',
            'syscom_order_raw',
            'shipping_raw',
            'raw',
            'created_at',
        ];

        $selectCols = ! empty($existingCols)
            ? array_values(array_filter($desiredCols, fn (string $c) => isset($existingCols[$c])))
            : ['id', 'order_id', 'status', 'shipping_status', 'syscom_order_folio', 'syscom_order_synced_at', 'syscom_order_cancelled_at', 'syscom_order_raw', 'raw', 'created_at'];

        $query = MeliOrder::query()
            ->select($selectCols)
            ->with([
                'items:id,meli_order_id,item_id,sku,title,quantity,unit_price',
                'claims:id,meli_order_id,type,stage,status',
            ])
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]);

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search, $existingCols) {
                $q->where('order_id', 'like', "%{$search}%")
                    ->orWhere('syscom_order_folio', 'like', "%{$search}%");

                foreach (['pack_id', 'display_id', 'shipping_id'] as $optCol) {
                    if (empty($existingCols) || isset($existingCols[$optCol])) {
                        $q->orWhere($optCol, 'like', "%{$search}%");
                    }
                }

                // Buscar también en el JSON raw por si pack_id o shipping_id viene dentro del payload de ML
                if (empty($existingCols) || isset($existingCols['raw'])) {
                    $q->orWhere('raw', 'like', "%{$search}%");
                }

                $q->orWhereHas('items', function (Builder $itemQuery) use ($search) {
                    $itemQuery->where('sku', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('item_id', 'like', "%{$search}%");
                });
            });
        }

        $allOrders = $query->orderByDesc('created_at')->get();

        // Precarga en bloque de productos, llantas y colas (elimina 100% el problema N+1)
        $this->preloadRelationsForOrders($allOrders);

        // Procesar y clasificar cada orden de forma segura
        $allProcessed = $allOrders->map(function (MeliOrder $order) use ($fallbackFeePct) {
            try {
                return $this->calculateOrderProfitability($order, $fallbackFeePct);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Error calculando rentabilidad para orden #{$order->order_id}: " . $e->getMessage());
                return $this->fallbackOrderProfitability($order, $fallbackFeePct);
            }
        });

        // Colecciones separadas por canal del periodo
        $syscomOrdersAll = $allProcessed->filter(fn (array $o) => $o['channel'] === 'syscom');
        $llantasOrdersAll = $allProcessed->filter(fn (array $o) => $o['channel'] === 'llantas');
        $bothOrdersAll = $allProcessed->filter(fn (array $o) => in_array($o['channel'], ['syscom', 'llantas'], true));
        $otrosOrdersAll = $allProcessed->filter(fn (array $o) => $o['channel'] === 'otro');

        // Filtrar según el canal seleccionado
        // Por defecto 'all' incluye ÚNICAMENTE SYSCOM y Llantas (excluyendo automáticamente productos de belleza SBS)
        if ($channelFilter === 'all') {
            $processedOrders = $bothOrdersAll;
        } elseif ($channelFilter === 'syscom') {
            $processedOrders = $syscomOrdersAll;
        } elseif ($channelFilter === 'llantas') {
            $processedOrders = $llantasOrdersAll;
        } elseif ($channelFilter === 'otro') {
            $processedOrders = $otrosOrdersAll;
        } elseif ($channelFilter === 'todo') {
            $processedOrders = $allProcessed;
        } else {
            $processedOrders = $bothOrdersAll;
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
            'channelCounts' => [
                'all' => $bothOrdersAll->count(),
                'syscom' => $syscomOrdersAll->count(),
                'llantas' => $llantasOrdersAll->count(),
                'otro' => $otrosOrdersAll->count(),
                'todo' => $allProcessed->count(),
            ],
            'periodSyscomOrders' => $syscomOrdersAll,
            'periodLlantasOrders' => $llantasOrdersAll,
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
        $grossSale = round($grossSale, 2);

        // 2. Cargo por venta (sale_fee) de Mercado Libre
        $meliFee = 0.0;
        $rawItems = data_get($raw, 'order_items', []);
        $exactFeeFound = false;

        if (is_array($rawItems) && ! empty($rawItems)) {
            foreach ($rawItems as $rItem) {
                $fee = data_get($rItem, 'sale_fee');
                if (is_numeric($fee) && (float) $fee > 0) {
                    $itemQty = max(1, (int) (data_get($rItem, 'quantity') ?? 1));
                    $meliFee += round(((float) $fee) * $itemQty, 2);
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
                    if (is_numeric($mpFee) && (float) $mpFee > 0) {
                        $meliFee += round((float) $mpFee, 2);
                        $exactFeeFound = true;
                    }
                }
            }
        }

        // Si no se encontró desglose exacto, aplicar porcentaje fallback sobre la venta bruta
        if (! $exactFeeFound && $grossSale > 0) {
            $meliFee = round($grossSale * ($fallbackFeePct / 100), 2);
        }
        $meliFee = round($meliFee, 2);
        $meliFeePct = $grossSale > 0 ? round(($meliFee / $grossSale) * 100, 2) : 0.0;

        // 3. Costo de envío absorbido por el vendedor (desde shipping_raw, /shipments/{id}/costs o raw)
        $shippingFee = $this->resolveSellerShippingFee($order, $grossSale);

        // 4. Impuestos retenidos por Mercado Libre (IVA 8.00% + ISR 2.50% sobre base sin IVA)
        $taxes = $this->resolveOrderTaxes($order, $grossSale);
        $isrFee = $taxes['isr_fee'];
        $ivaFee = $taxes['iva_fee'];
        $taxesFee = $taxes['taxes_fee'];

        // 5. Total a recibir en Mercado Libre ("Información del cobro -> Total a recibir")
        $meliNetReceived = round($grossSale - $meliFee - $shippingFee - $taxesFee, 2);
        $meliNetReceivedPct = $grossSale > 0 ? round(($meliNetReceived / $grossSale) * 100, 2) : 0.0;

        // 6. Costo del Producto (Costo de adquisición en SYSCOM / Llantas)
        $productCost = $this->resolveOrderCost($order, $channel);

        // 7. Ganancia Real Neta Libre (Total a recibir ML - Costo Compra)
        if ($isCancelledOrReturned) {
            // En devoluciones/cancelaciones, la venta se reembolsa, ganancia neta es 0
            $netSale = 0.0;
            $meliNetReceived = 0.0;
            $meliNetReceivedPct = 0.0;
            $netProfit = 0.0;
            $profitMarginPct = 0.0;
        } else {
            $netSale = $grossSale;
            // Ganancia Neta Real = Total a Recibir ML - Costo Producto
            $netProfit = round($meliNetReceived - $productCost, 2);
            $profitMarginPct = $grossSale > 0 ? round(($netProfit / $grossSale) * 100, 2) : 0.0;
        }

        // Descripción / Título principal de la orden e identificadores de venta/envío
        $firstItem = $order->items->first();
        $title = $firstItem?->title ?? 'Pedido #' . $order->order_id;
        $sku = $firstItem?->sku ?? '—';
        $quantity = (int) $order->items->sum('quantity');

        $packId = trim((string) ($order->pack_id ?: data_get($raw, 'pack_id') ?: ''));
        $displayId = trim((string) ($order->display_id ?: $packId ?: $order->order_id));
        $shippingId = trim((string) ($order->shipping_id ?: data_get($raw, 'shipping.id') ?: ''));

        return [
            'id' => $order->id,
            'order_id' => (string) $order->order_id,
            'pack_id' => $packId !== '' ? $packId : null,
            'display_id' => $displayId !== '' ? $displayId : (string) $order->order_id,
            'shipping_id' => $shippingId !== '' ? $shippingId : null,
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
            'meli_fee_pct' => $meliFeePct,
            'shipping_fee' => $shippingFee,
            'isr_fee' => $isrFee,
            'iva_fee' => $ivaFee,
            'taxes_fee' => $taxesFee,
            'meli_net_received' => $meliNetReceived,
            'meli_net_received_pct' => $meliNetReceivedPct,
            'total_deductions' => round($meliFee + $shippingFee + $taxesFee + $productCost, 2),
            'net_sale' => $netSale,
            'net_profit' => $netProfit,
            'profit_margin_pct' => $profitMarginPct,
            'has_syscom_raw' => ! empty($order->syscom_order_raw),
        ];
    }

    /**
     * Resuelve el costo de envío absorbido por el vendedor ("Envíos en Mercado Libre a tu cargo").
     */
    protected function resolveSellerShippingFee(MeliOrder $order, float $grossSale): float
    {
        $shippingRaw = is_array($order->shipping_raw) ? $order->shipping_raw : [];
        $raw = is_array($order->raw) ? $order->raw : [];
        $rawShipping = is_array($raw['shipping'] ?? null) ? $raw['shipping'] : [];

        // Si ya fue consultado y cacheado en shipping_raw
        if (array_key_exists('seller_shipping_cost', $shippingRaw) && is_numeric($shippingRaw['seller_shipping_cost'])) {
            return round(max(0.0, (float) $shippingRaw['seller_shipping_cost']), 2);
        }

        foreach ([$shippingRaw, $rawShipping] as $source) {
            if ($source === []) {
                continue;
            }

            // 1. Si viene el desglose explícito de senders (/shipments/{id}/costs)
            foreach (['senders.0.cost', 'costs.senders.0.cost', 'costs.sender.cost', 'sender_cost', 'cost_components.sender_cost'] as $senderKey) {
                $val = data_get($source, $senderKey);
                if (is_numeric($val) && (float) $val > 0) {
                    return round((float) $val, 2);
                }
            }

            // 2. Envíos con list_cost (shipping_option.list_cost o lead_time.list_cost)
            // En ML: list_cost es el cargo total con descuento por reputación, y cost es lo que pagó el comprador (0 cuando es envío gratis a cargo del vendedor).
            foreach (['shipping_option', 'lead_time'] as $optKey) {
                $listCost = data_get($source, "{$optKey}.list_cost");
                $buyerCost = data_get($source, "{$optKey}.cost");
                if (is_numeric($listCost) && (float) $listCost > 0) {
                    $buyerPaid = is_numeric($buyerCost) && (float) $buyerCost > 0 ? (float) $buyerCost : 0.0;
                    $sellerAbsorbed = round((float) $listCost - $buyerPaid, 2);
                    if ($sellerAbsorbed > 0) {
                        return $sellerAbsorbed;
                    }
                }
            }

            // 3. Fallback a base_cost o cost directo cuando es mayor a 0
            foreach (['base_cost', 'cost', 'shipping_option.cost', 'lead_time.cost'] as $fallbackKey) {
                $val = data_get($source, $fallbackKey);
                if (is_numeric($val) && (float) $val > 0) {
                    return round((float) $val, 2);
                }
            }
        }

        // 4. Si la orden tiene un shipping_id real de Mercado Envíos pero aún no tenía el costo del vendedor en shipping_raw,
        // consultar GET /shipments/{id}/costs y guardarlo en shipping_raw para futuras consultas.
        $shippingId = trim((string) ($order->shipping_id ?: data_get($raw, 'shipping.id') ?: ''));
        $isAgreedDelivery = ($order->delivery_type === 'agreed_delivery') || ($order->shipping_mode === 'custom');

        if ($shippingId !== '' && ! $isAgreedDelivery) {
            $remoteCost = $this->fetchRemoteSellerShippingCost($order, $shippingId);
            if ($remoteCost !== null && $remoteCost > 0) {
                return $remoteCost;
            }

            // Si la API no devolvió el costo (ej. token expirado o sin red) y la venta es >= $299 (envío gratis obligatorio en ML México),
            // aplicar el costo estándar de envío a cargo del vendedor ($95.00 MXN o el configurado).
            if ($remoteCost === null && $grossSale >= 299.0) {
                $configuredShip = (float) config('syscom.meli_recibes_estimate.shipping_absorb_mxn', 0);

                return $configuredShip > 0 ? round($configuredShip, 2) : 95.0;
            }
        }

        return 0.0;
    }

    /**
     * Consulta en Mercado Libre (GET /shipments/{id}/costs) el costo real absorbido por el vendedor
     * y lo persiste en shipping_raw para que no vuelva a requerir llamada HTTP.
     */
    protected function fetchRemoteSellerShippingCost(MeliOrder $order, string $shippingId): ?float
    {
        if (app()->runningUnitTests() || $this->remoteShippingLookupsCount >= 3) {
            return null;
        }

        $token = $this->resolveMeliAccessTokenForOrder($order);
        if (! $token) {
            return null;
        }

        $this->remoteShippingLookupsCount++;

        try {
            $resp = Http::withToken($token)
                ->withHeaders(['X-Format-New' => 'true'])
                ->acceptJson()
                ->timeout(2)
                ->get("https://api.mercadolibre.com/shipments/{$shippingId}/costs");

            if ($resp->successful()) {
                $json = $resp->json();
                $sellerCost = data_get($json, 'senders.0.cost')
                    ?? data_get($json, 'sender.cost')
                    ?? data_get($json, 'gross_amount');

                if (is_numeric($sellerCost)) {
                    $costVal = round(max(0.0, (float) $sellerCost), 2);
                    $shippingRaw = is_array($order->shipping_raw) ? $order->shipping_raw : [];
                    $shippingRaw['seller_shipping_cost'] = $costVal;
                    $order->shipping_raw = $shippingRaw;

                    try {
                        MeliOrder::query()->whereKey($order->id)->update([
                            'shipping_raw' => json_encode($shippingRaw),
                        ]);
                    } catch (\Throwable) {
                        // Ignorar si no se puede persistir en BD
                    }

                    return $costVal;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug("No se pudo consultar /shipments/{$shippingId}/costs: " . $e->getMessage());
        }

        return null;
    }

    protected function resolveMeliAccessTokenForOrder(MeliOrder $order): ?string
    {
        $accountId = (int) ($order->meli_account_id ?? 0);
        if ($accountId > 0 && isset($this->preloadedData['meliAccountsById'])) {
            $acc = $this->preloadedData['meliAccountsById']->get($accountId);
            if ($acc && filled($acc->access_token)) {
                return (string) $acc->access_token;
            }
        }

        return $this->preloadedData['defaultMeliToken'] ?? null;
    }

    /**
     * Calcula las retenciones fiscales exactas de Mercado Libre México:
     * Base sin IVA = Venta Bruta / 1.16
     * Retención IVA = 8.00% de la base sin IVA
     * Retención ISR = 2.50% de la base sin IVA
     *
     * @return array{isr_fee: float, iva_fee: float, taxes_fee: float}
     */
    protected function resolveOrderTaxes(MeliOrder $order, float $grossSale): array
    {
        if ($grossSale <= 0) {
            return [
                'isr_fee' => 0.0,
                'iva_fee' => 0.0,
                'taxes_fee' => 0.0,
            ];
        }

        $raw = is_array($order->raw) ? $order->raw : [];
        $explicitIva = data_get($raw, 'taxes.iva_fee') ?? data_get($raw, 'taxes.iva');
        $explicitIsr = data_get($raw, 'taxes.isr_fee') ?? data_get($raw, 'taxes.isr');

        if (is_numeric($explicitIva) && is_numeric($explicitIsr)) {
            $ivaFee = round(max(0.0, (float) $explicitIva), 2);
            $isrFee = round(max(0.0, (float) $explicitIsr), 2);

            return [
                'isr_fee' => $isrFee,
                'iva_fee' => $ivaFee,
                'taxes_fee' => round($isrFee + $ivaFee, 2),
            ];
        }

        // Regla fiscal validada de Mercado Libre México (Base = Precio / 1.16; IVA = 8.00%; ISR = 2.50%)
        $taxableBase = $grossSale / 1.16;
        $isrFee = round($taxableBase * 0.025, 2);
        $ivaFee = round($taxableBase * 0.08, 2);

        return [
            'isr_fee' => $isrFee,
            'iva_fee' => $ivaFee,
            'taxes_fee' => round($isrFee + $ivaFee, 2),
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
                $kitPieces = $this->extractSyscomKitPiecesFromSku($sku);
                $syscomProd = $this->findSyscomProduct($sku, (string) $item->item_id);

                if ($syscomProd) {
                    try {
                        $baseUsd = $this->syscomPricing->baseCosto($syscomProd);
                        $costMxn = $this->safeUsdToMxnWithIva($baseUsd);
                        $totalCost += ($costMxn * $kitPieces * $qty);
                    } catch (\Throwable) {
                        $unitPrice = (float) $item->unit_price;
                        $totalCost += round(($unitPrice / 1.12) * $qty, 2);
                    }
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
                    // Revisar si es producto compuesto preloaded o consultar
                    $compuesto = isset($this->preloadedData['compuestosByMlm'])
                        ? $this->preloadedData['compuestosByMlm']->get($mlm)
                        : ProductoCompuesto::query()->where('mlm', $mlm)->with('llanta')->first();

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

            // 1. Revisar si está en la cola o catálogo de SYSCOM (en memoria primero)
            if (isset($this->preloadedData['queuesByMlm'][$mlm]) || SyscomMeliQueue::query()->where('mlm', $mlm)->exists()) {
                $this->orderChannelCache[$orderId] = 'syscom';

                return 'syscom';
            }

            if ($this->findSyscomProduct($sku, $mlm) !== null) {
                $this->orderChannelCache[$orderId] = 'syscom';

                return 'syscom';
            }

            // 2. Revisar si es una Llanta (en memoria primero)
            if ($this->findLlanta($sku, $mlm) !== null) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }

            if (isset($this->preloadedData['compuestosByMlm'][$mlm]) || ProductoCompuesto::query()->where('mlm', $mlm)->exists()) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }

            if (isset($this->preloadedData['meliPubsWithLlanta'][$mlm]) || MeliPublication::query()->where('mlm', $mlm)->whereHas('llanta')->exists()) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }
        }

        // Clasificación por palabras clave en títulos o SKU de todos los items
        foreach ($order->items as $item) {
            $title = mb_strtolower((string) $item->title);
            $sku = mb_strtolower((string) $item->sku);

            // 1. Detección de Llantas / Neumáticos / Rodados
            if (
                str_contains($title, 'llanta') ||
                str_contains($title, 'llantas') ||
                str_contains($title, 'neumatico') ||
                str_contains($title, 'neumático') ||
                str_contains($title, 'neumaticos') ||
                str_contains($title, 'neumáticos') ||
                str_contains($title, 'rin ') ||
                preg_match('/\d{3}\/\d{2}\s*[rR]\s*\d{2}/', $title)
            ) {
                $this->orderChannelCache[$orderId] = 'llantas';

                return 'llantas';
            }

            // 2. Detección de SYSCOM (equipos de seguridad, redes, comunicación y marcas de Syscom)
            if (
                str_contains($title, 'syscom') ||
                str_contains($title, 'hikvision') ||
                str_contains($title, 'epcom') ||
                str_contains($title, 'ezviz') ||
                str_contains($title, 'ubiquiti') ||
                str_contains($title, 'hilook') ||
                str_contains($title, 'zkteco') ||
                str_contains($title, 'cámara de seguridad') ||
                str_contains($title, 'camara de seguridad') ||
                str_contains($title, 'videovigilancia') ||
                str_contains($title, 'intercom') ||
                str_contains($title, 'torniquete') ||
                str_contains($title, 'control de acceso') ||
                str_starts_with($sku, 'syscom-') ||
                str_starts_with($sku, 'sys-')
            ) {
                $this->orderChannelCache[$orderId] = 'syscom';

                return 'syscom';
            }
        }

        // Fallback definitivo: si no pertenece a Syscom ni a Llantas, es producto general (Belleza / Estética / SBS / Otro)
        $this->orderChannelCache[$orderId] = 'otro';

        return 'otro';
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

    /**
     * Precarga masiva en memoria de colas, productos SYSCOM, llantas y compuestos para eliminar N+1.
     *
     * @param Collection<int, MeliOrder> $orders
     */
    protected function preloadRelationsForOrders(Collection $orders): void
    {
        $allSkus = [];
        $allMlms = [];
        $allSyscomIds = [];
        $allAccountIds = [];

        foreach ($orders as $order) {
            if ($order->meli_account_id) {
                $allAccountIds[(int) $order->meli_account_id] = true;
            }
            if (! $order->relationLoaded('items')) {
                continue;
            }
            foreach ($order->items as $item) {
                $sku = trim((string) $item->sku);
                $mlm = trim((string) $item->item_id);
                if ($sku !== '') {
                    $allSkus[$sku] = true;
                    $syscomId = $this->extractSyscomIdFromSku($sku);
                    if ($syscomId !== null) {
                        $allSyscomIds[$syscomId] = true;
                    }
                }
                if ($mlm !== '') {
                    $allMlms[$mlm] = true;
                }
            }
        }

        $uniqueSkus = array_keys($allSkus);
        $uniqueMlms = array_keys($allMlms);
        $uniqueSyscomIds = array_keys($allSyscomIds);
        $uniqueAccountIds = array_keys($allAccountIds);

        $queues = ! empty($uniqueMlms)
            ? SyscomMeliQueue::query()
                ->whereIn('mlm', $uniqueMlms)
                ->select(['id', 'mlm', 'syscom_product_id'])
                ->get()
            : collect();

        $this->preloadedData['queuesByMlm'] = $queues->keyBy('mlm');

        $queueProdIds = $queues->pluck('syscom_product_id')->filter()->unique()->values()->all();

        $this->preloadedData['syscomProductsByModel'] = ! empty($uniqueSkus)
            ? SyscomProduct::query()->whereIn('modelo', $uniqueSkus)->get()->keyBy('modelo')
            : collect();

        try {
            $this->preloadedData['syscomProductsBySyscomId'] = ! empty($uniqueSyscomIds)
                ? SyscomProduct::query()
                    ->whereIn('syscom_producto_id', $uniqueSyscomIds)
                    ->get()
                    ->keyBy(fn (SyscomProduct $p) => (string) $p->syscom_producto_id)
                : collect();
        } catch (\Throwable) {
            $this->preloadedData['syscomProductsBySyscomId'] = collect();
        }

        $this->preloadedData['syscomProductsById'] = ! empty($queueProdIds)
            ? SyscomProduct::query()->whereIn('id', $queueProdIds)->get()->keyBy('id')
            : collect();

        $this->preloadedData['llantasBySku'] = ! empty($uniqueSkus)
            ? Llanta::query()->whereIn('sku', $uniqueSkus)->get()->keyBy('sku')
            : collect();

        $this->preloadedData['llantasByMlm'] = ! empty($uniqueMlms)
            ? Llanta::query()->whereIn('MLM', $uniqueMlms)->get()->keyBy('MLM')
            : collect();

        $this->preloadedData['compuestosByMlm'] = ! empty($uniqueMlms)
            ? ProductoCompuesto::query()->whereIn('mlm', $uniqueMlms)->with('llanta')->get()->keyBy('mlm')
            : collect();

        $this->preloadedData['meliPubsWithLlanta'] = ! empty($uniqueMlms)
            ? MeliPublication::query()->whereIn('mlm', $uniqueMlms)->whereHas('llanta')->pluck('mlm')->flip()->all()
            : [];

        try {
            $this->preloadedData['meliAccountsById'] = ! empty($uniqueAccountIds)
                ? MeliAccount::query()->whereIn('id', $uniqueAccountIds)->get()->keyBy('id')
                : collect();
            $this->preloadedData['defaultMeliToken'] = User::query()->whereNotNull('access_token')->value('access_token');
        } catch (\Throwable) {
            $this->preloadedData['meliAccountsById'] = collect();
            $this->preloadedData['defaultMeliToken'] = null;
        }
    }

    protected function extractSyscomIdFromSku(string $sku): ?string
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $prefix = (string) config('syscom.sku_prefix', 'SYSCOM');
        $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)(?:-(?:2|4))?$/i';
        if (preg_match($pattern, $sku, $m)) {
            return (string) $m[1];
        }

        return null;
    }

    protected function extractSyscomKitPiecesFromSku(string $sku): int
    {
        $sku = trim($sku);
        $prefix = (string) config('syscom.sku_prefix', 'SYSCOM');
        $pattern = '/^' . preg_quote($prefix, '/') . '-\d+-(2|4)$/i';
        if (preg_match($pattern, $sku, $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    /**
     * Resuelve el tipo de cambio USD/MXN con protección de timeout y fallback seguro.
     */
    protected function resolveExchangeRate(): float
    {
        if ($this->cachedExchangeRate !== null) {
            return $this->cachedExchangeRate;
        }

        try {
            $cached = \Illuminate\Support\Facades\Cache::get('syscom_tipo_cambio');
            if (is_numeric($cached) && (float) $cached > 0) {
                $this->cachedExchangeRate = (float) $cached;

                return $this->cachedExchangeRate;
            }

            // Intentar convertir 1 USD de forma segura sin bloquear el hilo
            $costMx = $this->syscomPricing->usdToMxnWithIva(1.0);
            $iva = max(0.0, (float) config('syscom.iva_pct', 16));
            $rate = $costMx / (1 + $iva / 100);
            if ($rate > 10.0 && $rate < 40.0) {
                $this->cachedExchangeRate = round($rate, 4);

                return $this->cachedExchangeRate;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SYSCOM tipo_cambio fallback en ProfitabilityService: ' . $e->getMessage());
        }

        $this->cachedExchangeRate = (float) config('syscom.tc_fallback', config('syscom.tipo_cambio_fallback', 20.0));

        return $this->cachedExchangeRate;
    }

    /**
     * Convierte USD a MXN con IVA usando el tipo de cambio cacheado seguro sin llamadas bloqueantes a la API.
     */
    protected function safeUsdToMxnWithIva(float $usd): float
    {
        if ($usd <= 0) {
            return 0.0;
        }

        $tc = $this->resolveExchangeRate();
        $iva = max(0.0, (float) config('syscom.iva_pct', 16));

        return round($usd * $tc * (1 + $iva / 100), 4);
    }

    /**
     * Genera datos financieros seguros de contingencia si una orden individual falla por datos corruptos.
     *
     * @return array<string, mixed>
     */
    protected function fallbackOrderProfitability(MeliOrder $order, float $fallbackFeePct = 15.0): array
    {
        $grossSale = 0.0;
        if ($order->relationLoaded('items')) {
            $grossSale = (float) $order->items->sum(fn ($i) => ((float) $i->unit_price) * ((int) $i->quantity));
        }
        $grossSale = round($grossSale, 2);

        $meliFee = round($grossSale * ($fallbackFeePct / 100), 2);
        $taxes = $this->resolveOrderTaxes($order, $grossSale);
        $isrFee = $taxes['isr_fee'];
        $ivaFee = $taxes['iva_fee'];
        $taxesFee = $taxes['taxes_fee'];
        $meliNetReceived = round($grossSale - $meliFee - $taxesFee, 2);
        $meliNetReceivedPct = $grossSale > 0 ? round(($meliNetReceived / $grossSale) * 100, 2) : 0.0;

        $productCost = round($grossSale * 0.75, 2);
        $netProfit = round($meliNetReceived - $productCost, 2);

        return [
            'id' => $order->id,
            'order_id' => (string) $order->order_id,
            'pack_id' => $order->pack_id ?: null,
            'display_id' => $order->display_id ?: (string) $order->order_id,
            'shipping_id' => $order->shipping_id ?: null,
            'created_at' => $order->created_at?->format('d/m/Y H:i'),
            'date_raw' => $order->created_at?->toDateString(),
            'channel' => 'otro',
            'channel_label' => 'Estimado',
            'status' => $order->status ?? 'closed',
            'shipping_status' => $order->shipping_status ?? '—',
            'is_cancelled_or_returned' => false,
            'cancellation_reason' => null,
            'syscom_order_folio' => $order->syscom_order_folio,
            'title' => $order->items->first()?->title ?? 'Pedido #' . $order->order_id,
            'sku' => $order->items->first()?->sku ?? '—',
            'quantity' => 1,
            'gross_sale' => $grossSale,
            'product_cost' => $productCost,
            'meli_fee' => $meliFee,
            'meli_fee_pct' => $grossSale > 0 ? round(($meliFee / $grossSale) * 100, 2) : 0.0,
            'shipping_fee' => 0.0,
            'isr_fee' => $isrFee,
            'iva_fee' => $ivaFee,
            'taxes_fee' => $taxesFee,
            'meli_net_received' => $meliNetReceived,
            'meli_net_received_pct' => $meliNetReceivedPct,
            'total_deductions' => round($meliFee + $taxesFee + $productCost, 2),
            'net_sale' => $grossSale,
            'net_profit' => $netProfit,
            'profit_margin_pct' => $grossSale > 0 ? round(($netProfit / $grossSale) * 100, 2) : 0.0,
            'has_syscom_raw' => false,
        ];
    }

    protected function findSyscomProduct(string $sku, string $mlm): ?SyscomProduct
    {
        $syscomId = $this->extractSyscomIdFromSku($sku);

        // 1. Buscar en datos precargados en lote
        if ($syscomId !== null && isset($this->preloadedData['syscomProductsBySyscomId'])) {
            $p = $this->preloadedData['syscomProductsBySyscomId']->get($syscomId);
            if ($p) {
                return $p;
            }
        }

        if ($sku !== '' && isset($this->preloadedData['syscomProductsByModel'])) {
            $p = $this->preloadedData['syscomProductsByModel']->get($sku);
            if ($p) {
                return $p;
            }
        }

        if ($mlm !== '' && isset($this->preloadedData['queuesByMlm'])) {
            $queue = $this->preloadedData['queuesByMlm']->get($mlm);
            if ($queue && $queue->syscom_product_id && isset($this->preloadedData['syscomProductsById'])) {
                $p = $this->preloadedData['syscomProductsById']->get($queue->syscom_product_id);
                if ($p) {
                    return $p;
                }
            }
        }

        // 2. Fallback con caché en memoria individual
        $cacheKey = "syscom:{$sku}:{$mlm}";
        if (array_key_exists($cacheKey, $this->syscomProductCache)) {
            return $this->syscomProductCache[$cacheKey];
        }

        $prod = null;
        if ($syscomId !== null) {
            try {
                $prod = SyscomProduct::query()->where('syscom_producto_id', $syscomId)->first();
            } catch (\Throwable) {
                $prod = null;
            }
        }

        if (! $prod && $sku !== '') {
            $prod = SyscomProduct::query()->where('modelo', $sku)->first();
        }

        if (! $prod && $mlm !== '') {
            $queue = SyscomMeliQueue::query()->where('mlm', $mlm)->with('product')->first();
            $prod = $queue?->product;
        }

        $this->syscomProductCache[$cacheKey] = $prod;

        return $prod;
    }

    protected function findLlanta(string $sku, string $mlm): ?Llanta
    {
        // 1. Buscar en datos precargados en lote
        if ($sku !== '' && isset($this->preloadedData['llantasBySku'])) {
            $l = $this->preloadedData['llantasBySku']->get($sku);
            if ($l) {
                return $l;
            }
        }

        if ($mlm !== '' && isset($this->preloadedData['llantasByMlm'])) {
            $l = $this->preloadedData['llantasByMlm']->get($mlm);
            if ($l) {
                return $l;
            }
        }

        // 2. Fallback con caché en memoria individual
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
        $isrFeesTotal = round($deliveredOrders->sum('isr_fee'), 2);
        $ivaFeesTotal = round($deliveredOrders->sum('iva_fee'), 2);
        $taxesFeesTotal = round($deliveredOrders->sum('taxes_fee'), 2);
        $meliNetReceivedTotal = round($deliveredOrders->sum('meli_net_received'), 2);
        $meliNetReceivedPct = $netSalesTotal > 0 ? round(($meliNetReceivedTotal / $netSalesTotal) * 100, 2) : 0.0;
        $totalDeductions = round($productCostTotal + $meliFeesTotal + $shippingFeesTotal + $taxesFeesTotal, 2);

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
            'total_isr_fees' => $isrFeesTotal,
            'total_iva_fees' => $ivaFeesTotal,
            'total_taxes_fees' => $taxesFeesTotal,
            'total_meli_net_received' => $meliNetReceivedTotal,
            'meli_net_received_pct' => $meliNetReceivedPct,
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
        $taxes = round($active->sum('taxes_fee'), 2);
        $meliReceived = round($active->sum('meli_net_received'), 2);
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
            'taxes_fees' => $taxes,
            'meli_net_received' => $meliReceived,
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

    /**
     * Resumen seguro de contingencia con métricas en cero en caso de fallo crítico en la consulta.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getEmptySummary(array $filters = [], string $errorMessage = ''): array
    {
        [$startDate, $endDate, $periodLabel] = $this->resolveDateRange($filters);

        return [
            'metrics' => [
                'total_orders_count' => 0,
                'delivered_orders_count' => 0,
                'cancelled_returned_count' => 0,
                'total_sales_gross' => 0.0,
                'total_returns_amount' => 0.0,
                'total_sales_net' => 0.0,
                'total_product_cost' => 0.0,
                'total_meli_fees' => 0.0,
                'total_shipping_fees' => 0.0,
                'total_isr_fees' => 0.0,
                'total_iva_fees' => 0.0,
                'total_taxes_fees' => 0.0,
                'total_meli_net_received' => 0.0,
                'meli_net_received_pct' => 0.0,
                'total_deductions' => 0.0,
                'total_net_profit' => 0.0,
                'profit_margin_pct' => 0.0,
                'commission' => [
                    'rate_pct' => (float) ($filters['commission_pct'] ?? 10.0),
                    'base' => $filters['commission_base'] ?? 'profit',
                    'base_label' => ($filters['commission_base'] ?? 'profit') === 'sales' ? 'Venta Neta' : 'Ganancia Neta Real',
                    'user_amount' => 0.0,
                    'company_amount' => 0.0,
                    'company_share_pct' => 100.0,
                ],
                'syscom' => [
                    'total_orders' => 0,
                    'active_orders' => 0,
                    'cancelled_orders' => 0,
                    'gross_sales' => 0.0,
                    'returns_amount' => 0.0,
                    'net_sales' => 0.0,
                    'product_cost' => 0.0,
                    'fees_and_shipping' => 0.0,
                    'taxes_fees' => 0.0,
                    'meli_net_received' => 0.0,
                    'net_profit' => 0.0,
                    'margin_pct' => 0.0,
                ],
                'llantas' => [
                    'total_orders' => 0,
                    'active_orders' => 0,
                    'cancelled_orders' => 0,
                    'gross_sales' => 0.0,
                    'returns_amount' => 0.0,
                    'net_sales' => 0.0,
                    'product_cost' => 0.0,
                    'fees_and_shipping' => 0.0,
                    'taxes_fees' => 0.0,
                    'meli_net_received' => 0.0,
                    'net_profit' => 0.0,
                    'margin_pct' => 0.0,
                ],
                'channel_counts' => [
                    'all' => 0,
                    'syscom' => 0,
                    'llantas' => 0,
                    'otro' => 0,
                    'todo' => 0,
                ],
                'scenarios' => $this->calculateCommissionScenarios(0.0, 0.0),
            ],
            'timeline' => [],
            'orders' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 30),
            'filters' => [
                'period' => $filters['period'] ?? 'este_mes',
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
                'channel' => $filters['channel'] ?? 'all',
                'status' => $filters['status'] ?? 'all',
                'search' => $filters['search'] ?? '',
                'commission_pct' => (float) ($filters['commission_pct'] ?? 10.0),
                'commission_base' => $filters['commission_base'] ?? 'profit',
                'fallback_fee_pct' => (float) ($filters['fallback_fee_pct'] ?? 15.0),
                'period_label' => $periodLabel,
            ],
            'error' => $errorMessage !== '' ? $errorMessage : null,
        ];
    }
}
