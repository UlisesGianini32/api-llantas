<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use App\Models\InventoryChannelLink;
use App\Models\MeliBeautyScheduledDiscount;
use App\Services\InventoryMeliOrderReservationCutover;
use App\Services\MercadoLibre\PriceManager\MeliBeautyPromotionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strictly READ-ONLY reconstruction diagnostics inventory for R2.
 * Never mutates any database table, never executes jobs, and never makes HTTP calls to Mercado Libre.
 */
class QueueReconstructionDiagnosticsService
{
    /** @var list<string> */
    private const FULFILLED_SHIPPING_STATUSES = ['shipped', 'delivered', 'in_transit'];

    public function __construct(
        private readonly QueueRecoveryManifest $manifestManager,
        private readonly QueuePayloadInspector $inspector,
        private readonly InventoryMeliOrderReservationCutover $cutover,
        private readonly MeliBeautyPromotionWindow $beautyWindow,
    ) {}

    /**
     * Generates a complete read-only inventory distinguishing all 11 R2 categories.
     *
     * @param  array{
     *     manifest_path?: ?string,
     *     account_id?: ?int,
     *     cutover?: ?string,
     *     chunk?: int,
     *     connection?: ?string
     * }  $options
     * @return array<string, mixed>
     */
    public function diagnose(array $options = []): array
    {
        $connName = is_string($options['connection'] ?? null) && trim($options['connection']) !== ''
            ? trim($options['connection'])
            : (string) config('database.default');
        $db = DB::connection($connName);
        $schema = Schema::connection($connName);
        $chunk = max(50, min(500, (int) ($options['chunk'] ?? 500)));
        $accountFilter = isset($options['account_id']) && $options['account_id'] !== null && $options['account_id'] !== ''
            ? (int) $options['account_id']
            : null;

        $scopedOrderIds = null;
        $scopedDiscountIds = null;
        $manifestMeta = null;

        if (isset($options['manifest_path']) && trim((string) $options['manifest_path']) !== '') {
            $manifest = $this->manifestManager->loadAndVerify(trim((string) $options['manifest_path']), $this->inspector);
            $scopedOrderIds = [];
            $scopedDiscountIds = [];
            foreach ((array) $manifest['entries'] as $entry) {
                if (($entry['entity_type'] ?? null) === 'meli_order') {
                    $scopedOrderIds[(int) $entry['entity_id']] = true;
                } elseif (($entry['entity_type'] ?? null) === 'beauty_discount') {
                    $scopedDiscountIds[(int) $entry['entity_id']] = true;
                }
            }
            $scopedOrderIds = array_keys($scopedOrderIds);
            sort($scopedOrderIds);
            $scopedDiscountIds = array_keys($scopedDiscountIds);
            sort($scopedDiscountIds);

            $manifestMeta = [
                'incident_id' => $manifest['incident_id'],
                'manifest_sha256' => $manifest['manifest_sha256'],
                'distinct_orders_in_manifest' => count($scopedOrderIds),
                'distinct_discounts_in_manifest' => count($scopedDiscountIds),
            ];
        }

        $cutoverAt = $this->resolveCutover($options['cutover'] ?? null);

        $tables = [
            'meli_orders' => $schema->hasTable('meli_orders'),
            'meli_order_items' => $schema->hasTable('meli_order_items'),
            'inventory_channel_links' => $schema->hasTable('inventory_channel_links'),
            'inventory_channel_order_allocations' => $schema->hasTable('inventory_channel_order_allocations'),
            'inventory_reservations' => $schema->hasTable('inventory_reservations'),
            'inventory_kit_reservations' => $schema->hasTable('inventory_kit_reservations'),
            'inventory_kit_components' => $schema->hasTable('inventory_kit_components'),
            'inventory_products' => $schema->hasTable('inventory_products'),
            'inventory_movements' => $schema->hasTable('inventory_movements'),
            'meli_beauty_scheduled_discounts' => $schema->hasTable('meli_beauty_scheduled_discounts'),
            'meli_scheduled_price_states' => $schema->hasTable('meli_scheduled_price_states'),
            'meli_price_changes' => $schema->hasTable('meli_price_changes'),
            'meli_price_manager_items' => $schema->hasTable('meli_price_manager_items'),
        ];

        $categories = [
            'cancelled_orders_with_active_reservations' => [],
            'shipped_orders_with_active_reservations' => [],
            'paid_orders_pending_reservation' => [],
            'shipped_during_outage_never_reserved' => [],
            'orders_with_manual_movements' => [],
            'multiline_orders' => [],
            'kits_and_components' => [],
            'active_beauty_promotions' => [],
            'beauty_promotions_pending_restore' => [],
            'expired_beauty_promotions' => [],
            'remote_states_requiring_verification' => [],
        ];

        if ($tables['meli_orders']) {
            $this->diagnoseOrders(
                $db,
                $tables,
                $scopedOrderIds,
                $accountFilter,
                $cutoverAt,
                $chunk,
                $categories,
            );
        }

        if ($tables['meli_beauty_scheduled_discounts']) {
            $this->diagnoseBeautyPromotions(
                $db,
                $tables,
                $scopedDiscountIds,
                $accountFilter,
                $categories,
            );
        }

        $counts = [];
        foreach ($categories as $key => $items) {
            $counts[$key] = count($items);
        }

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'mode' => 'read_only_diagnosis',
            'connection' => $connName,
            'database' => $db->getDatabaseName(),
            'cutover_at' => $cutoverAt?->toIso8601String(),
            'account_filter' => $accountFilter,
            'manifest_scope' => $manifestMeta,
            'tables_present' => $tables,
            'reconstructibility_policy' => [
                'automatic_reconstructibility_assumed' => false,
                'categories_requiring_manual_or_remote_verification' => [
                    'shipped_during_outage_never_reserved',
                    'orders_with_manual_movements',
                    'multiline_orders',
                    'kits_and_components',
                    'remote_states_requiring_verification',
                ],
            ],
            'counts' => $counts,
            'categories' => $categories,
        ];
    }

    /**
     * @param  array<string, bool>  $tables
     * @param  list<int>|null  $scopedOrderIds
     * @param  array<string, list<array<string, mixed>>>  $categories
     */
    private function diagnoseOrders(
        ConnectionInterface $db,
        array $tables,
        ?array $scopedOrderIds,
        ?int $accountFilter,
        ?CarbonImmutable $cutoverAt,
        int $chunk,
        array &$categories,
    ): void {
        // Pre-index kit product IDs and component product IDs if available (read-only).
        $kitProductIds = [];
        $componentProductIds = [];
        if ($tables['inventory_products']) {
            foreach ($db->table('inventory_products')->where('product_type', 'kit')->pluck('id') as $pid) {
                $kitProductIds[(int) $pid] = true;
            }
        }
        if ($tables['inventory_kit_components']) {
            foreach ($db->table('inventory_kit_components')->select(['kit_product_id', 'component_product_id'])->get() as $kc) {
                $kitProductIds[(int) $kc->kit_product_id] = true;
                $componentProductIds[(int) $kc->component_product_id] = true;
            }
        }

        // Pre-index active ML channel links with order_reservation_enabled = true.
        $reservationLinksByKey = [];
        if ($tables['inventory_channel_links']) {
            $hasOrderResCol = Schema::connection($db->getName())->hasColumn('inventory_channel_links', 'order_reservation_enabled');
            $linkQuery = $db->table('inventory_channel_links')
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->where('is_active', true);

            if ($hasOrderResCol) {
                $linkQuery->where('order_reservation_enabled', true);
            }

            foreach ($linkQuery->get(['id', 'account_key', 'external_listing_id', 'external_variant_id', 'inventory_product_id']) as $link) {
                $key = $link->account_key.'|'.$link->external_listing_id.'|'.($link->external_variant_id ?? '');
                $reservationLinksByKey[$key][] = [
                    'link_id' => (int) $link->id,
                    'inventory_product_id' => (int) $link->inventory_product_id,
                ];
            }
        }

        $cursor = 0;
        $scopedChunks = $scopedOrderIds !== null ? array_chunk($scopedOrderIds, $chunk) : null;
        $scopedIndex = 0;

        while (true) {
            if ($scopedChunks !== null) {
                if (! isset($scopedChunks[$scopedIndex])) {
                    break;
                }
                $orders = $db->table('meli_orders')
                    ->select(['id', 'meli_account_id', 'order_id', 'status', 'shipping_status', 'raw', 'created_at'])
                    ->whereIn('id', $scopedChunks[$scopedIndex])
                    ->when($accountFilter !== null, fn ($q) => $q->where('meli_account_id', $accountFilter))
                    ->orderBy('id')
                    ->get();
                $scopedIndex++;
            } else {
                $orders = $db->table('meli_orders')
                    ->select(['id', 'meli_account_id', 'order_id', 'status', 'shipping_status', 'raw', 'created_at'])
                    ->where('id', '>', $cursor)
                    ->when($accountFilter !== null, fn ($q) => $q->where('meli_account_id', $accountFilter))
                    ->orderBy('id')
                    ->limit($chunk)
                    ->get();

                if ($orders->isEmpty()) {
                    break;
                }
                $cursor = (int) $orders->last()->id;
            }

            if ($orders->isEmpty()) {
                continue;
            }

            $batchOrderIds = $orders->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $batchRemoteIds = $orders->pluck('order_id')->filter()->map(static fn ($id): string => (string) $id)->unique()->values()->all();

            // Load items for this batch.
            $itemsByOrderId = [];
            if ($tables['meli_order_items']) {
                $hasVarCol = Schema::connection($db->getName())->hasColumn('meli_order_items', 'variation_id');
                $hasLineKeyCol = Schema::connection($db->getName())->hasColumn('meli_order_items', 'remote_line_key');
                $cols = ['id', 'meli_order_id', 'item_id', 'sku', 'quantity'];
                if ($hasVarCol) {
                    $cols[] = 'variation_id';
                }
                if ($hasLineKeyCol) {
                    $cols[] = 'remote_line_key';
                }

                foreach ($db->table('meli_order_items')->whereIn('meli_order_id', $batchOrderIds)->get($cols) as $item) {
                    $itemsByOrderId[(int) $item->meli_order_id][] = $item;
                }
            }

            // Load allocations for this batch.
            $allocsByRemoteKey = [];
            if ($tables['inventory_channel_order_allocations'] && $batchRemoteIds !== []) {
                $allocs = $db->table('inventory_channel_order_allocations')
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->whereIn('remote_order_id', $batchRemoteIds)
                    ->get();

                foreach ($allocs as $alloc) {
                    $key = $alloc->account_key.'|'.$alloc->remote_order_id;
                    $allocsByRemoteKey[$key][] = $alloc;
                }
            }

            // Load direct active reservations / kit reservations by source_id for this batch.
            $activeResByOrderId = [];
            if ($tables['inventory_reservations']) {
                $rows = $db->table('inventory_reservations')
                    ->where('source_type', 'meli_order')
                    ->where('status', 'ACTIVE')
                    ->whereIn('source_id', $batchOrderIds)
                    ->get(['id', 'source_id', 'inventory_product_id', 'quantity']);
                foreach ($rows as $r) {
                    $activeResByOrderId[(int) $r->source_id][] = $r;
                }
            }

            $activeKitResByOrderId = [];
            if ($tables['inventory_kit_reservations']) {
                $rows = $db->table('inventory_kit_reservations')
                    ->where('source_type', 'meli_order')
                    ->where('status', 'ACTIVE')
                    ->whereIn('source_id', $batchOrderIds)
                    ->get(['id', 'source_id', 'kit_product_id', 'quantity']);
                foreach ($rows as $r) {
                    $activeKitResByOrderId[(int) $r->source_id][] = $r;
                }
            }

            // Collect affected product IDs in this batch to detect manual movements.
            $productIdsByOrderId = [];
            $allBatchProductIds = [];
            foreach ($orders as $order) {
                $oid = (int) $order->id;
                $orderItems = $itemsByOrderId[$oid] ?? [];
                $allocs = $allocsByRemoteKey[((string) $order->meli_account_id).'|'.((string) $order->order_id)] ?? [];
                $pids = [];

                foreach ($allocs as $a) {
                    if ($a->inventory_product_id !== null) {
                        $pids[(int) $a->inventory_product_id] = true;
                    }
                }
                foreach ($orderItems as $oi) {
                    $var = property_exists($oi, 'variation_id') && $oi->variation_id !== null ? (string) $oi->variation_id : '';
                    $lk = ((string) $order->meli_account_id).'|'.((string) $oi->item_id).'|'.$var;
                    foreach ($reservationLinksByKey[$lk] ?? [] as $matchedLink) {
                        $pids[$matchedLink['inventory_product_id']] = true;
                    }
                }
                $productIdsByOrderId[$oid] = array_keys($pids);
                foreach ($pids as $pid => $_) {
                    $allBatchProductIds[$pid] = true;
                }
            }

            // Detect manual movements for affected products.
            $manualMovementsByProduct = [];
            if ($tables['inventory_movements'] && $allBatchProductIds !== []) {
                $movs = $db->table('inventory_movements')
                    ->whereIn('inventory_product_id', array_keys($allBatchProductIds))
                    ->where(function ($q): void {
                        $q->whereNull('reference_type')
                            ->orWhereNotIn('reference_type', ['inventory_reservation', 'inventory_kit_reservation']);
                    })
                    ->orderByDesc('id')
                    ->get(['id', 'inventory_product_id', 'type', 'quantity', 'reference_type', 'created_at']);

                foreach ($movs as $m) {
                    $manualMovementsByProduct[(int) $m->inventory_product_id][] = $m;
                }
            }

            foreach ($orders as $order) {
                $oid = (int) $order->id;
                $status = strtolower(trim((string) ($order->status ?? '')));
                $shippingStatus = strtolower(trim((string) ($order->shipping_status ?? '')));
                $orderItems = $itemsByOrderId[$oid] ?? [];
                $allocs = $allocsByRemoteKey[((string) $order->meli_account_id).'|'.((string) $order->order_id)] ?? [];
                $activeAllocs = array_values(array_filter($allocs, static fn ($a): bool => (string) $a->status === 'ACTIVE'));
                $fulfilledAllocs = array_values(array_filter($allocs, static fn ($a): bool => (string) $a->status === 'FULFILLED'));
                $hasActiveReservation = $activeAllocs !== []
                    || isset($activeResByOrderId[$oid])
                    || isset($activeKitResByOrderId[$oid]);

                $afterCutover = $this->orderIsAfterCutover($order, $cutoverAt);

                $hasReservationEnabledLink = false;
                foreach ($orderItems as $oi) {
                    $var = property_exists($oi, 'variation_id') && $oi->variation_id !== null ? (string) $oi->variation_id : '';
                    $lk = ((string) $order->meli_account_id).'|'.((string) $oi->item_id).'|'.$var;
                    if (isset($reservationLinksByKey[$lk])) {
                        $hasReservationEnabledLink = true;
                        break;
                    }
                }

                $summary = [
                    'meli_order_id' => $oid,
                    'meli_account_id' => $order->meli_account_id !== null ? (int) $order->meli_account_id : null,
                    'remote_order_id' => (string) $order->order_id,
                    'status' => $status,
                    'shipping_status' => $shippingStatus !== '' ? $shippingStatus : null,
                    'items_count' => count($orderItems),
                    'active_allocations' => count($activeAllocs),
                ];

                // 1. Cancelled with active reservations
                if ($status === 'cancelled' && $hasActiveReservation) {
                    $categories['cancelled_orders_with_active_reservations'][] = $summary;
                }

                // 2. Shipped/delivered/in_transit with active reservations
                if ($status !== 'cancelled' && in_array($shippingStatus, self::FULFILLED_SHIPPING_STATUSES, true) && $hasActiveReservation) {
                    $categories['shipped_orders_with_active_reservations'][] = $summary;
                }

                // 3. Paid pending reservation (open, after cutover, enabled link, no active alloc)
                if ($status === 'paid'
                    && ! in_array($shippingStatus, self::FULFILLED_SHIPPING_STATUSES, true)
                    && $afterCutover
                    && $hasReservationEnabledLink
                    && ! $hasActiveReservation) {
                    $categories['paid_orders_pending_reservation'][] = $summary;
                }

                // 4. Shipped during outage that NEVER had reservations
                if ($status !== 'cancelled'
                    && in_array($shippingStatus, self::FULFILLED_SHIPPING_STATUSES, true)
                    && $afterCutover
                    && $hasReservationEnabledLink
                    && ! $hasActiveReservation
                    && $fulfilledAllocs === []) {
                    $categories['shipped_during_outage_never_reserved'][] = [
                        ...$summary,
                        'requires_manual_verification' => true,
                    ];
                }

                // 5. Orders with manual movements on affected products (after order creation)
                $orderCreatedAt = $this->parseOrderTimestamp($order);
                $matchedManualMovements = [];
                foreach ($productIdsByOrderId[$oid] ?? [] as $pid) {
                    foreach ($manualMovementsByProduct[$pid] ?? [] as $mov) {
                        $movAt = $mov->created_at ? CarbonImmutable::parse((string) $mov->created_at) : null;
                        if ($orderCreatedAt === null || $movAt === null || $movAt->greaterThanOrEqualTo($orderCreatedAt)) {
                            $matchedManualMovements[] = [
                                'movement_id' => (int) $mov->id,
                                'inventory_product_id' => $pid,
                                'type' => (string) $mov->type,
                                'quantity' => (int) $mov->quantity,
                            ];
                        }
                    }
                }
                if ($matchedManualMovements !== []) {
                    $categories['orders_with_manual_movements'][] = [
                        ...$summary,
                        'manual_movements_count' => count($matchedManualMovements),
                        'manual_movements_sample' => array_slice($matchedManualMovements, 0, 5),
                        'requires_manual_verification' => true,
                    ];
                }

                // 6. Multiline orders (relevant if has active reservation or enabled link)
                if (count($orderItems) > 1 && ($hasActiveReservation || $hasReservationEnabledLink)) {
                    $categories['multiline_orders'][] = [
                        ...$summary,
                        'requires_manual_verification' => true,
                    ];
                }

                // 7. Kits and components
                $kitPids = [];
                $componentPids = [];
                foreach ($productIdsByOrderId[$oid] ?? [] as $pid) {
                    if (isset($kitProductIds[$pid])) {
                        $kitPids[] = $pid;
                    }
                    if (isset($componentProductIds[$pid])) {
                        $componentPids[] = $pid;
                    }
                }
                $hasKitAllocation = collect($allocs)->contains(static fn ($a): bool => (string) $a->reservation_kind === 'KIT');
                if ($kitPids !== [] || $componentPids !== [] || $hasKitAllocation || isset($activeKitResByOrderId[$oid])) {
                    $categories['kits_and_components'][] = [
                        ...$summary,
                        'kit_product_ids' => array_values(array_unique($kitPids)),
                        'component_product_ids' => array_values(array_unique($componentPids)),
                        'has_active_kit_reservation' => isset($activeKitResByOrderId[$oid]),
                        'requires_manual_verification' => true,
                    ];
                }
            }
        }
    }

    /**
     * @param  array<string, bool>  $tables
     * @param  list<int>|null  $scopedDiscountIds
     * @param  array<string, list<array<string, mixed>>>  $categories
     */
    private function diagnoseBeautyPromotions(
        ConnectionInterface $db,
        array $tables,
        ?array $scopedDiscountIds,
        ?int $accountFilter,
        array &$categories,
    ): void {
        $rulesQuery = $db->table('meli_beauty_scheduled_discounts')->orderBy('id');
        if ($scopedDiscountIds !== null) {
            if ($scopedDiscountIds === []) {
                return;
            }
            $rulesQuery->whereIn('id', $scopedDiscountIds);
        }
        if ($accountFilter !== null) {
            $rulesQuery->where('meli_account_id', $accountFilter);
        }

        $rules = $rulesQuery->get();
        if ($rules->isEmpty()) {
            return;
        }

        $ruleIds = $rules->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $statesByRule = [];
        if ($tables['meli_scheduled_price_states']) {
            $states = $db->table('meli_scheduled_price_states')
                ->whereIn('meli_beauty_scheduled_discount_id', $ruleIds)
                ->get();
            foreach ($states as $s) {
                $statesByRule[(int) $s->meli_beauty_scheduled_discount_id][] = $s;
            }
        }

        $itemsById = [];
        if ($tables['meli_price_manager_items'] && $tables['meli_scheduled_price_states']) {
            $itemIds = [];
            foreach ($statesByRule as $ruleStates) {
                foreach ($ruleStates as $s) {
                    $itemIds[(int) $s->price_manager_item_id] = true;
                }
            }
            if ($itemIds !== []) {
                foreach ($db->table('meli_price_manager_items')->whereIn('id', array_keys($itemIds))->get(['id', 'meli_item_id', 'sku']) as $it) {
                    $itemsById[(int) $it->id] = [
                        'meli_item_id' => (string) $it->meli_item_id,
                        'sku' => $it->sku !== null ? (string) $it->sku : null,
                    ];
                }
            }
        }

        $recentFailedChangesByItem = [];
        if ($tables['meli_price_changes'] && $tables['meli_price_manager_items']) {
            $failedChanges = $db->table('meli_price_changes')
                ->where('source', 'scheduled_beauty')
                ->where('status', 'failed')
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'price_manager_item_id', 'scheduled_action', 'error_message', 'changed_at']);
            foreach ($failedChanges as $fc) {
                $recentFailedChangesByItem[(int) $fc->price_manager_item_id][] = $fc;
            }
        }

        foreach ($rules as $row) {
            $model = new MeliBeautyScheduledDiscount([
                'meli_account_id' => $row->meli_account_id,
                'brand_group_id' => $row->brand_group_id ?? null,
                'discount_percentage' => $row->discount_percentage ?? 0,
                'starts_on' => $row->starts_on ?? null,
                'ends_on' => $row->ends_on ?? null,
                'starts_at' => $row->starts_at ?? null,
                'ends_at' => $row->ends_at ?? null,
                'all_day' => (bool) ($row->all_day ?? false),
                'timezone' => $row->timezone ?? MeliBeautyPromotionWindow::TIMEZONE,
                'active' => (bool) ($row->active ?? false),
            ]);
            $model->id = (int) $row->id;

            $windowStatus = $this->beautyWindow->status($model);
            $ruleStates = $statesByRule[$model->id] ?? [];

            $activeStates = array_values(array_filter($ruleStates, static fn ($s): bool => (string) $s->status === 'active'));
            $restorePendingStates = array_values(array_filter($ruleStates, static fn ($s): bool => (string) $s->status === 'restore_pending'));
            $failedStates = array_values(array_filter($ruleStates, static fn ($s): bool => (string) $s->status === 'failed'));

            $ruleSummary = [
                'discount_id' => $model->id,
                'meli_account_id' => (int) $row->meli_account_id,
                'active_flag' => (bool) ($row->active ?? false),
                'all_day' => (bool) ($row->all_day ?? false),
                'starts_on' => $row->starts_on ?? null,
                'ends_on' => $row->ends_on ?? null,
                'window_status' => $windowStatus,
                'states_active' => count($activeStates),
                'states_restore_pending' => count($restorePendingStates),
                'states_failed' => count($failedStates),
            ];

            // 8. Active promotions (enabled and in_window or scheduled, OR having active remote state)
            if (((bool) ($row->active ?? false) && in_array($windowStatus, ['in_window', 'outside_hours', 'scheduled'], true))
                || $activeStates !== []) {
                $categories['active_beauty_promotions'][] = $ruleSummary;
            }

            // 9. Promotions pending restore (explicit restore_pending OR active states on disabled/finished/outside_hours rule)
            $needsRestore = $restorePendingStates !== []
                || ($activeStates !== [] && in_array($windowStatus, ['disabled', 'finished', 'outside_hours'], true));
            if ($needsRestore) {
                $categories['beauty_promotions_pending_restore'][] = [
                    ...$ruleSummary,
                    'items_needing_restore' => count($restorePendingStates) + ($windowStatus !== 'in_window' ? count($activeStates) : 0),
                ];
            }

            // 10. Expired promotions
            if ($windowStatus === 'finished') {
                $categories['expired_beauty_promotions'][] = $ruleSummary;
            }

            // 11. Remote states requiring verification (active, restore_pending, failed states, or recent failed price changes)
            foreach (array_merge($activeStates, $restorePendingStates, $failedStates) as $st) {
                $itemInfo = $itemsById[(int) $st->price_manager_item_id] ?? ['meli_item_id' => null, 'sku' => null];
                $categories['remote_states_requiring_verification'][] = [
                    'discount_id' => $model->id,
                    'meli_account_id' => (int) $row->meli_account_id,
                    'price_manager_item_id' => (int) $st->price_manager_item_id,
                    'meli_item_id' => $itemInfo['meli_item_id'],
                    'sku' => $itemInfo['sku'],
                    'local_state_status' => (string) $st->status,
                    'window_status' => $windowStatus,
                    'base_price' => $st->base_price !== null ? (float) $st->base_price : null,
                    'promotional_price' => $st->promotional_price !== null ? (float) $st->promotional_price : null,
                    'failure_message' => $st->failure_message !== null ? mb_substr((string) $st->failure_message, 0, 200) : null,
                    'recent_failed_changes' => count($recentFailedChangesByItem[(int) $st->price_manager_item_id] ?? []),
                    'requires_remote_verification' => true,
                ];
            }
        }
    }

    private function resolveCutover(?string $override): ?CarbonImmutable
    {
        if ($override !== null && trim($override) !== '') {
            try {
                return CarbonImmutable::parse(trim($override));
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->cutover->configuredAt();
    }

    private function orderIsAfterCutover(object $order, ?CarbonImmutable $cutoverAt): bool
    {
        if ($cutoverAt === null) {
            return true;
        }

        $created = $this->parseOrderTimestamp($order);
        if ($created === null) {
            return false;
        }

        return $created->greaterThanOrEqualTo($cutoverAt);
    }

    private function parseOrderTimestamp(object $order): ?CarbonImmutable
    {
        $raw = $order->raw ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_string($decoded['date_created'] ?? null) && trim($decoded['date_created']) !== '') {
                try {
                    return CarbonImmutable::parse(trim($decoded['date_created']));
                } catch (\Throwable) {
                    // Fallback to column below.
                }
            }
        }

        if (! empty($order->created_at)) {
            try {
                return CarbonImmutable::parse((string) $order->created_at);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
