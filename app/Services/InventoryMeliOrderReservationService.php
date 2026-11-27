<?php

namespace App\Services;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitComponent;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class InventoryMeliOrderReservationService
{
    public const RESERVE = 'RESERVE';

    public const RELEASE = 'RELEASE';

    public const FULFILL = 'FULFILL';

    public const ALREADY_FULFILLED = 'ALREADY_FULFILLED';

    public const ALREADY_FULFILLED_REVIEW_REQUIRED = 'ALREADY_FULFILLED_REVIEW_REQUIRED';

    public const MANUAL_MOVEMENT_REVIEW_REQUIRED = 'MANUAL_MOVEMENT_REVIEW_REQUIRED';

    public function __construct(
        private readonly InventoryMeliOrderReservationPolicy $policy,
        private readonly InventoryReservationService $reservations,
        private readonly InventoryKitService $kits,
        private readonly InventoryStockService $stock,
        private readonly InventoryKitStockService $kitStock,
        private readonly InventoryMeliSharedStockGroupService $sharedStockGroups,
    ) {}

    /** @return list<array<string,mixed>> */
    public function preview(MeliOrder $order): array
    {
        return $order->items
            ->sortBy(static fn (MeliOrderItem $item): string => (string) ($item->remote_line_key ?? '').'|'.(int) $item->getKey())
            ->values()
            ->map(fn (MeliOrderItem $item): array => $this->inspect($order, $item))
            ->all();
    }

    /**
     * Apply one known local order deterministically with per-order & per-line row locks.
     *
     * @param  array{lease_owner: string, fencing_token: int}|null  $leaseContext
     */
    public function apply(MeliOrder $order, ?array $leaseContext = null): array
    {
        $items = $order->items
            ->sortBy(static fn (MeliOrderItem $item): string => (string) ($item->remote_line_key ?? '').'|'.(int) $item->getKey())
            ->values();

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->applyLine($order, $item, $leaseContext);
        }

        return $results;
    }

    /**
     * @param  array{lease_owner: string, fencing_token: int}|null  $leaseContext
     */
    private function applyLine(MeliOrder $order, MeliOrderItem $item, ?array $leaseContext = null): array
    {
        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return $this->inspect($order, $item);
        }

        $fallbackInspection = null;
        $fallbackIdentity = null;
        $fallbackOrder = $order;
        $fallbackItem = $item;

        try {
            return DB::transaction(function () use (
                $order,
                $item,
                $leaseContext,
                &$fallbackInspection,
                &$fallbackIdentity,
                &$fallbackOrder,
                &$fallbackItem,
            ): array {
                // Global lock ordering:
                // 1) meli_orders -> 2) inventory_meli_order_reconciliations (fencing)
                // -> 3) inventory_channel_order_allocations -> 4) inventory_products / reservations.
                $freshOrder = MeliOrder::query()->lockForUpdate()->find($order->getKey()) ?? $order;

                $this->assertFencingLeaseInsideTransaction($freshOrder, $leaseContext);

                $freshItem = $this->resolveFreshOrderItem($freshOrder, $item);

                if ($freshItem === null) {
                    return [
                        'account' => (string) $freshOrder->meli_account_id,
                        'order_id' => (string) $freshOrder->order_id,
                        'mlm' => $item->item_id,
                        'variation' => $item->variation_id,
                        'quantity' => (int) $item->quantity,
                        'order_status' => $freshOrder->status,
                        'action' => 'LINE_REMOVED_NOOP',
                        'applied' => false,
                    ];
                }

                $identity = $this->identity($freshOrder, $freshItem);
                $existingAllocation = InventoryChannelOrderAllocation::query()
                    ->where('identity_hash', $identity['hash'])
                    ->lockForUpdate()
                    ->first();

                $inspection = $this->inspectForLine($freshOrder, $freshItem, $identity, $existingAllocation, locked: true);
                $fallbackInspection = $inspection;
                $fallbackIdentity = $identity;
                $fallbackOrder = $freshOrder;
                $fallbackItem = $freshItem;

                $action = (string) $inspection['action'];

                if (in_array($action, [
                    'IGNORED_STATUS',
                    'RELEASE_NOTHING_TO_DO',
                    'FULFILL_NOTHING_TO_DO',
                    self::ALREADY_FULFILLED,
                    self::ALREADY_FULFILLED_REVIEW_REQUIRED,
                ], true)) {
                    return $inspection + ['applied' => false];
                }

                if (in_array($action, [
                    'UNMATCHED',
                    'AMBIGUOUS',
                    'LEGACY_LINE_IDENTITY_UNKNOWN',
                    'SKIPPED_ORDER_RESERVATION_DISABLED',
                    'SKIPPED_INACTIVE_LINK',
                    'SKIPPED_INACTIVE_PRODUCT',
                    'REMOTE_USER_PRODUCT_CONFLICT',
                    self::MANUAL_MOVEMENT_REVIEW_REQUIRED,
                ], true)) {
                    $this->saveDiagnosticInsideTransaction($freshOrder, $freshItem, $identity, $inspection);

                    return $inspection + ['applied' => false];
                }

                $allocation = $this->ensureLockedAllocation($freshOrder, $identity);

                // Re-verify terminal FULFILLED immutability under lock (never double-deduct or re-reserve).
                if ($allocation->status === 'FULFILLED') {
                    return array_merge($inspection, [
                        'action' => $action === self::FULFILL ? 'FULFILL_NOTHING_TO_DO' : self::ALREADY_FULFILLED,
                        'applied' => false,
                    ]);
                }

                if ($action === self::RELEASE) {
                    $this->releaseCurrent($allocation);
                    $allocation->forceFill([
                        'status' => 'RELEASED',
                        'quantity' => 0,
                        'diagnostic_code' => null,
                        'diagnostic_metadata' => null,
                    ])->save();
                    $this->markStockSyncRequiredInsideTransaction($freshOrder, $leaseContext);

                    return $inspection + ['applied' => true];
                }

                if ($action === self::FULFILL) {
                    $fulfilled = $this->fulfillCurrent($allocation, $freshOrder, $freshItem, $identity);
                    if (! $fulfilled) {
                        return array_merge($inspection, ['action' => 'FULFILL_NOTHING_TO_DO', 'applied' => false]);
                    }

                    $allocation->forceFill([
                        'status' => 'FULFILLED',
                        'quantity' => 0,
                        'diagnostic_code' => null,
                        'diagnostic_metadata' => null,
                    ])->save();
                    $this->markStockSyncRequiredInsideTransaction($freshOrder, $leaseContext);

                    return $inspection + ['applied' => true];
                }

                $link = $inspection['link'];
                $product = $inspection['product'];
                $qty = (int) $freshItem->quantity;
                $current = $this->currentReservation($allocation, true);

                if ($allocation->status === 'ACTIVE'
                    && $current
                    && $current->status === InventoryReservation::ACTIVE
                    && (int) $allocation->quantity === $qty
                    && (int) $allocation->inventory_product_id === (int) $product->id) {
                    return array_merge($inspection, ['action' => 'NO_CHANGE', 'applied' => true]);
                }

                // Releasing and replacing in one transaction makes quantity reconciliation atomic.
                if ($current && $current->status === InventoryReservation::ACTIVE) {
                    $this->releaseCurrent($allocation);
                }

                $baseKey = 'meli-order:'.$identity['hash'];
                $version = ((int) $allocation->reservation_version) + 1;
                $externalKey = substr($baseKey.':'.$version, 0, 191);
                $reference = 'ML order '.$freshOrder->order_id.' line '.$identity['line'];
                $source = [
                    'source_type' => 'meli_order',
                    'source_id' => $freshOrder->id,
                    'reference' => $reference,
                    'external_key' => $externalKey,
                    'metadata' => [
                        'channel' => 'mercado_libre',
                        'account_key' => (string) $freshOrder->meli_account_id,
                        'remote_order_id' => (string) $freshOrder->order_id,
                        'remote_line_key' => $identity['line'],
                        'listing_id' => $freshItem->item_id,
                        'variation_id' => $freshItem->variation_id,
                    ],
                ];

                if ($product->isKit()) {
                    $reservation = $this->kits->reserve($source + ['kit_product_id' => $product->id, 'quantity' => $qty]);
                    $kind = 'KIT';
                } else {
                    $reservation = $this->reservations->create($source + ['inventory_product_id' => $product->id, 'quantity' => $qty]);
                    $kind = 'SIMPLE';
                }

                $allocation->forceFill([
                    'inventory_channel_link_id' => $link->id,
                    'inventory_product_id' => $product->id,
                    'reservation_kind' => $kind,
                    'reservation_id' => $reservation->id,
                    'quantity' => $qty,
                    'reservation_version' => $version,
                    'status' => 'ACTIVE',
                    'diagnostic_code' => null,
                    'diagnostic_metadata' => null,
                ])->save();
                $this->markStockSyncRequiredInsideTransaction($freshOrder, $leaseContext);

                return array_merge($inspection, ['applied' => true, 'reservation_id' => $reservation->id]);
            });
        } catch (InventoryInsufficientStockException $e) {
            $available = $e->available;
            $base = $fallbackInspection ?? $this->inspect($fallbackOrder, $fallbackItem);
            $identity = $fallbackIdentity ?? $this->identity($fallbackOrder, $fallbackItem);
            $result = array_merge($base, [
                'action' => 'INSUFFICIENT_INVENTORY',
                'available' => $available,
                'diagnostic' => 'INSUFFICIENT_INVENTORY',
            ]);
            $this->saveDiagnostic($fallbackOrder, $fallbackItem, $identity, $result, $leaseContext);

            return $result;
        }
    }

    /**
     * @param  array{lease_owner: string, fencing_token: int}|null  $leaseContext
     */
    private function assertFencingLeaseInsideTransaction(MeliOrder $order, ?array $leaseContext): void
    {
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $effectiveLease = $leaseContext ?? $coordinator->activeLeaseContext((int) $order->getKey());

        if ($effectiveLease === null) {
            return;
        }

        $coordinator->assertAndRenewLeaseInTransaction(
            (int) $order->getKey(),
            (string) $effectiveLease['lease_owner'],
            (int) $effectiveLease['fencing_token'],
        );
    }

    /**
     * Persists `stock_sync_required = true` inside the EXACT same DB transaction as the inventory
     * mutation so a SIGKILL right after line commit never loses the obligation to synchronize stock (H9).
     *
     * @param  array{lease_owner: string, fencing_token: int}|null  $leaseContext
     */
    private function markStockSyncRequiredInsideTransaction(MeliOrder $order, ?array $leaseContext): void
    {
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $effectiveLease = $leaseContext ?? $coordinator->activeLeaseContext((int) $order->getKey());

        if ($effectiveLease === null) {
            return;
        }

        $coordinator->markStockSyncRequiredInTransaction(
            (int) $order->getKey(),
            (string) $effectiveLease['lease_owner'],
            (int) $effectiveLease['fencing_token'],
        );
    }

    private function inspect(MeliOrder $order, MeliOrderItem $item): array
    {
        $identity = $this->identity($order, $item);
        $allocation = Schema::hasTable('inventory_channel_order_allocations')
            ? InventoryChannelOrderAllocation::query()->where('identity_hash', $identity['hash'])->first()
            : null;

        return $this->inspectForLine($order, $item, $identity, $allocation, locked: false);
    }

    /**
     * Evaluates the exact line action either read-only (preview) or under row locks (applyLine).
     *
     * @param  array{line: string, hash: string}  $identity
     * @return array<string, mixed>
     */
    private function inspectForLine(
        MeliOrder $order,
        MeliOrderItem $item,
        array $identity,
        ?InventoryChannelOrderAllocation $allocation,
        bool $locked,
    ): array {
        $result = [
            'account' => (string) $order->meli_account_id,
            'order_id' => (string) $order->order_id,
            'mlm' => $item->item_id,
            'variation' => $item->variation_id,
            'quantity' => (int) $item->quantity,
            'order_status' => $order->status,
            'product' => null,
            'sku' => $item->sku,
            'reservation_id' => $allocation?->reservation_id,
            'allocation_id' => $allocation?->id,
            'action' => 'UNMATCHED',
            'link' => null,
        ];

        if (blank($item->remote_line_key)) {
            $result['action'] = 'LEGACY_LINE_IDENTITY_UNKNOWN';

            return $result;
        }

        if (blank($item->item_id)) {
            $result['action'] = 'UNMATCHED';

            return $result;
        }

        $category = $this->policy->classify($order->status, $order->shipping_status);
        if ($category === InventoryMeliOrderReservationPolicy::IGNORE) {
            $result['action'] = 'IGNORED_STATUS';

            return $result;
        }

        // Terminal FULFILLED protection: an already-fulfilled line must never re-reserve,
        // double-fulfill, or be automatically released without manual return review.
        if ($allocation?->status === 'FULFILLED' || $this->hasExistingFulfillmentMovement($order, $item, $identity)) {
            $result['action'] = match ($category) {
                InventoryMeliOrderReservationPolicy::FULFILL => 'FULFILL_NOTHING_TO_DO',
                InventoryMeliOrderReservationPolicy::RELEASE => self::ALREADY_FULFILLED_REVIEW_REQUIRED,
                default => self::ALREADY_FULFILLED,
            };

            return $result;
        }

        if ($category === InventoryMeliOrderReservationPolicy::RELEASE) {
            $result['action'] = $allocation?->status === 'ACTIVE' ? self::RELEASE : 'RELEASE_NOTHING_TO_DO';

            return $result;
        }

        if ($category === InventoryMeliOrderReservationPolicy::FULFILL) {
            if ($allocation && $allocation->status === 'ACTIVE') {
                $current = $this->currentReservation($allocation, $locked);
                if ($current && $current->status === InventoryReservation::ACTIVE) {
                    $result['action'] = self::FULFILL;

                    return $result;
                }
            }

            // Never fall back to direct reservations if this line already had a finalized allocation.
            if ($allocation && in_array($allocation->status, ['FULFILLED', 'RELEASED'], true)) {
                $result['action'] = 'FULFILL_NOTHING_TO_DO';

                return $result;
            }

            // Legacy direct reservation fallback — strictly scoped to unclaimed reservations matching this line/product!
            $resolvedProductId = $this->resolveProductIdForItem($order, $item);
            $direct = $this->findUnclaimedDirectReservation($order, $item, $resolvedProductId, $locked);
            if ($direct !== null) {
                $result['action'] = self::FULFILL;
                $result['reservation_id'] = $direct['reservation']->getKey();

                return $result;
            }

            // Shipped during outage without any prior active reservation: NEVER deduct stock retroactively!
            $result['action'] = 'FULFILL_NOTHING_TO_DO';

            return $result;
        }

        // RESERVABLE path
        $query = InventoryChannelLink::query()->with('product')
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', (string) $order->meli_account_id)
            ->where('external_listing_id', (string) $item->item_id);
        $item->variation_id === null
            ? $query->whereNull('external_variant_id')
            : $query->where('external_variant_id', (string) $item->variation_id);
        $links = $query->get();

        if ($links->count() > 1) {
            $result['action'] = 'AMBIGUOUS';

            return $result;
        }

        $link = $links->first();
        if (! $link) {
            $result['action'] = 'UNMATCHED';

            return $result;
        }

        $result['link'] = $link;
        $result['product'] = $link->product;
        $result['sku'] = $link->product?->sku;

        if (! $link->is_active) {
            $result['action'] = 'SKIPPED_INACTIVE_LINK';

            return $result;
        }
        if (! $link->product || ! $link->product->is_active) {
            $result['action'] = 'SKIPPED_INACTIVE_PRODUCT';

            return $result;
        }
        if (! $link->order_reservation_enabled) {
            $result['action'] = 'SKIPPED_ORDER_RESERVATION_DISABLED';

            return $result;
        }
        if ($this->hasSharedStockConflict($link)) {
            $result['action'] = 'REMOTE_USER_PRODUCT_CONFLICT';

            return $result;
        }

        $result['action'] = self::RESERVE;

        $activeReservation = null;
        if ($allocation && $allocation->status === 'ACTIVE') {
            $reservation = $this->currentReservation($allocation, $locked);
            if ($reservation?->status === InventoryReservation::ACTIVE) {
                $activeReservation = $reservation;
                if ((int) $allocation->quantity === (int) $item->quantity
                    && (int) $allocation->inventory_product_id === (int) $link->product->id) {
                    $result['action'] = 'NO_CHANGE';

                    return $result;
                }
            } else {
                $result['action'] = 'STALE_ALLOCATION';
            }
        }

        // Guard against ambiguous historical / manual-adjustment cases BEFORE creating a new reservation
        // when this line has no active reservation yet.
        if ($activeReservation === null) {
            $manualMovement = $this->detectBlockingManualMovement($order, $link->product);
            if ($manualMovement !== null) {
                $result['action'] = self::MANUAL_MOVEMENT_REVIEW_REQUIRED;
                $result['manual_movement_id'] = (int) $manualMovement->id;
                $result['manual_movement_type'] = (string) $manualMovement->type;

                return $result;
            }
        }

        $available = $link->product->isKit()
            ? $this->kitStock->availableStock($link->product)
            : $this->stock->availableStock($link->product);

        if ($activeReservation !== null && $allocation) {
            $available += (int) $allocation->quantity;
        }

        $result['available'] = $available;
        if ((int) $item->quantity > $available) {
            $result['action'] = 'INSUFFICIENT_INVENTORY';
        }

        return $result;
    }

    private function resolveFreshOrderItem(MeliOrder $order, MeliOrderItem $item): ?MeliOrderItem
    {
        if (filled($item->remote_line_key)) {
            $fresh = MeliOrderItem::query()
                ->where('meli_order_id', $order->getKey())
                ->where('remote_line_key', $item->remote_line_key)
                ->first();

            if ($fresh !== null) {
                return $fresh;
            }

            // If items exist for this order in DB but this line key is gone, the line was removed.
            if (MeliOrderItem::query()->where('meli_order_id', $order->getKey())->exists()) {
                return null;
            }
        }

        return MeliOrderItem::query()->find($item->getKey()) ?? $item;
    }

    /** @param array{line: string, hash: string} $identity */
    private function ensureLockedAllocation(MeliOrder $order, array $identity): InventoryChannelOrderAllocation
    {
        DB::table('inventory_channel_order_allocations')->insertOrIgnore([
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $order->meli_account_id,
            'remote_order_id' => (string) $order->order_id,
            'remote_line_key' => $identity['line'],
            'identity_hash' => $identity['hash'],
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryChannelOrderAllocation::query()
            ->where('identity_hash', $identity['hash'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Protects orders that already have a manual inventory adjustment or explicit manual movement
     * referencing the order from being automatically reserved without human review.
     */
    private function detectBlockingManualMovement(MeliOrder $order, InventoryProduct $product): ?InventoryMovement
    {
        if (! Schema::hasTable('inventory_movements')) {
            return null;
        }

        $targetProductIds = $product->isKit()
            ? InventoryKitComponent::query()
                ->where('kit_product_id', $product->getKey())
                ->pluck('component_product_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [(int) $product->getKey()];

        if ($targetProductIds === []) {
            return null;
        }

        $orderCreatedAt = $this->resolveOrderCreatedAt($order);
        $remoteOrderId = trim((string) ($order->order_id ?? ''));
        $localOrderId = (int) $order->getKey();

        // 1. Any non-reservation movement explicitly referencing this ML order (e.g. manual sale/deduction).
        if ($remoteOrderId !== '') {
            $directRef = InventoryMovement::query()
                ->whereIn('inventory_product_id', $targetProductIds)
                ->where(function ($q): void {
                    $q->whereNull('reference_type')
                        ->orWhereNotIn('reference_type', ['inventory_reservation', InventoryKitReservation::SOURCE_TYPE]);
                })
                ->where(function ($q) use ($remoteOrderId, $localOrderId): void {
                    $q->where(function ($sub) use ($localOrderId): void {
                        $sub->where('reference_type', 'meli_order')
                            ->where('reference_id', $localOrderId);
                    })
                        ->orWhere('reference', 'like', '%'.$remoteOrderId.'%')
                        ->orWhere('notes', 'like', '%'.$remoteOrderId.'%');
                })
                ->orderByDesc('id')
                ->first();

            if ($directRef !== null) {
                return $directRef;
            }
        }

        // 2. Manual negative adjustment (ADJUSTMENT_OUT / DAMAGE) on affected product/components
        // occurring at or after order creation when no active reservation exists yet.
        if ($orderCreatedAt !== null) {
            $timestampCol = Schema::hasColumn('inventory_movements', 'occurred_at') ? 'occurred_at' : 'created_at';

            return InventoryMovement::query()
                ->whereIn('inventory_product_id', $targetProductIds)
                ->whereIn('type', [InventoryMovement::ADJUSTMENT_OUT, InventoryMovement::DAMAGE])
                ->where(function ($q): void {
                    $q->whereNull('reference_type')
                        ->orWhereNotIn('reference_type', ['inventory_reservation', InventoryKitReservation::SOURCE_TYPE]);
                })
                ->where($timestampCol, '>=', $orderCreatedAt)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    private function resolveOrderCreatedAt(MeliOrder $order): ?CarbonImmutable
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        $dateCreated = is_string($raw['date_created'] ?? null) ? trim((string) $raw['date_created']) : '';
        if ($dateCreated !== '') {
            try {
                return CarbonImmutable::parse($dateCreated);
            } catch (Throwable) {
                // Fall through to created_at.
            }
        }

        if ($order->created_at !== null) {
            try {
                return CarbonImmutable::parse($order->created_at);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /** @param array{line: string, hash: string} $identity */
    private function hasExistingFulfillmentMovement(MeliOrder $order, MeliOrderItem $item, array $identity): bool
    {
        if (! Schema::hasTable('inventory_movements')) {
            return false;
        }

        $keys = [
            $this->fulfillmentMovementKey($identity),
            $this->legacyFulfillmentMovementKey($order, $item),
        ];

        return InventoryMovement::query()
            ->whereIn('external_key', $keys)
            ->exists();
    }

    /** @param array{line: string, hash: string} $identity */
    private function fulfillmentMovementKey(array $identity): string
    {
        return 'meli-order:'.$identity['hash'].':fulfill';
    }

    private function legacyFulfillmentMovementKey(MeliOrder $order, MeliOrderItem $item): string
    {
        $lineKey = $item->remote_line_key ?: (string) $item->id;

        return "meli-order:{$order->order_id}:line:{$lineKey}:fulfill";
    }

    private function resolveProductIdForItem(MeliOrder $order, MeliOrderItem $item): ?int
    {
        if (blank($item->item_id)) {
            return null;
        }

        $query = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', (string) $order->meli_account_id)
            ->where('external_listing_id', (string) $item->item_id);
        $item->variation_id === null
            ? $query->whereNull('external_variant_id')
            : $query->where('external_variant_id', (string) $item->variation_id);

        $links = $query->get(['inventory_product_id']);

        return $links->count() === 1 ? (int) $links->first()->inventory_product_id : null;
    }

    /**
     * Finds a legacy direct reservation ONLY if it is NOT already claimed by another allocation
     * and matches this line's product or remote_line_key (preventing cross-line theft on multiline orders).
     *
     * @return array{kind: string, reservation: InventoryReservation|InventoryKitReservation}|null
     */
    private function findUnclaimedDirectReservation(
        MeliOrder $order,
        MeliOrderItem $item,
        ?int $resolvedProductId,
        bool $lock,
    ): ?array {
        $claimedSimpleIds = [];
        $claimedKitIds = [];

        if (Schema::hasTable('inventory_channel_order_allocations')) {
            $claimedRows = InventoryChannelOrderAllocation::query()
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->where('account_key', (string) $order->meli_account_id)
                ->where('remote_order_id', (string) $order->order_id)
                ->whereNotNull('reservation_id')
                ->get(['reservation_kind', 'reservation_id']);

            foreach ($claimedRows as $row) {
                if ($row->reservation_kind === 'KIT') {
                    $claimedKitIds[] = (int) $row->reservation_id;
                } else {
                    $claimedSimpleIds[] = (int) $row->reservation_id;
                }
            }
        }

        $kitQuery = InventoryKitReservation::query()
            ->active()
            ->where('source_type', 'meli_order')
            ->where('source_id', $order->id)
            ->when($claimedKitIds !== [], fn ($q) => $q->whereNotIn('id', $claimedKitIds))
            ->when($resolvedProductId !== null, fn ($q) => $q->where('kit_product_id', $resolvedProductId))
            ->orderBy('id');

        if ($lock) {
            $kitQuery->lockForUpdate();
        }

        foreach ($kitQuery->get() as $directKit) {
            $metaLine = data_get($directKit->metadata, 'remote_line_key');
            if ($metaLine !== null && filled($item->remote_line_key) && (string) $metaLine !== (string) $item->remote_line_key) {
                continue;
            }

            return ['kind' => 'KIT', 'reservation' => $directKit];
        }

        $resQuery = InventoryReservation::query()
            ->active()
            ->where('source_type', 'meli_order')
            ->where('source_id', $order->id)
            ->when($claimedSimpleIds !== [], fn ($q) => $q->whereNotIn('id', $claimedSimpleIds))
            ->when($resolvedProductId !== null, fn ($q) => $q->where('inventory_product_id', $resolvedProductId))
            ->orderBy('id');

        if ($lock) {
            $resQuery->lockForUpdate();
        }

        foreach ($resQuery->get() as $directReservation) {
            $metaLine = data_get($directReservation->metadata, 'remote_line_key');
            if ($metaLine !== null && filled($item->remote_line_key) && (string) $metaLine !== (string) $item->remote_line_key) {
                continue;
            }

            return ['kind' => 'SIMPLE', 'reservation' => $directReservation];
        }

        return null;
    }

    private function hasSharedStockConflict(InventoryChannelLink $link): bool
    {
        return $this->sharedStockGroups->isGrouped($link)
            && $this->sharedStockGroups->conflict($link)['conflict'];
    }

    /** @return array{line: string, hash: string} */
    private function identity(MeliOrder $order, MeliOrderItem $item): array
    {
        $line = $item->remote_line_key ?: 'legacy-row:'.$item->getKey();
        $hash = hash('sha256', implode("\0", ['mercado_libre', (string) $order->meli_account_id, (string) $order->order_id, $line]));

        return ['line' => $line, 'hash' => $hash];
    }

    private function currentReservation(InventoryChannelOrderAllocation $allocation, bool $lock = false): InventoryReservation|InventoryKitReservation|null
    {
        if (! $allocation->reservation_id) {
            return null;
        }
        $query = $allocation->reservation_kind === 'KIT' ? InventoryKitReservation::query() : InventoryReservation::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($allocation->reservation_id);
    }

    private function releaseCurrent(InventoryChannelOrderAllocation $allocation): void
    {
        $reservation = $this->currentReservation($allocation, true);
        if (! $reservation || $reservation->status !== 'ACTIVE') {
            return;
        }
        if ($allocation->reservation_kind === 'KIT') {
            $this->kits->release($reservation);
        } else {
            $this->reservations->release($reservation);
        }
    }

    /** @param array{line: string, hash: string} $identity */
    private function fulfillCurrent(
        InventoryChannelOrderAllocation $allocation,
        MeliOrder $order,
        MeliOrderItem $item,
        array $identity,
    ): bool {
        $reservation = $this->currentReservation($allocation, true);

        if (! $reservation || $reservation->status !== 'ACTIVE') {
            // Only use unclaimed direct fallback when this allocation has never owned a finalized reservation.
            if ($allocation->reservation_id !== null) {
                return false;
            }

            $resolvedProductId = $this->resolveProductIdForItem($order, $item);
            $direct = $this->findUnclaimedDirectReservation($order, $item, $resolvedProductId, lock: true);
            if ($direct === null) {
                return false;
            }

            $reference = 'ML orden '.$order->order_id.' línea '.($item->remote_line_key ?: $item->id);

            if ($direct['kind'] === 'KIT') {
                /** @var InventoryKitReservation $kitRes */
                $kitRes = $direct['reservation'];
                $this->kits->fulfill($kitRes, [
                    'reference' => $reference,
                    'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
                ]);
                $allocation->reservation_kind = 'KIT';
                $allocation->reservation_id = $kitRes->getKey();
                $allocation->inventory_product_id = $kitRes->kit_product_id;

                return true;
            }

            /** @var InventoryReservation $simpleRes */
            $simpleRes = $direct['reservation'];
            $locationId = $simpleRes->inventory_location_id
                ?? $this->resolveLocationForProduct($simpleRes->product ?? $simpleRes->inventory_product_id);

            $this->reservations->fulfill($simpleRes, [
                'inventory_location_id' => $locationId,
                'reference' => $reference,
                'external_key' => $this->fulfillmentMovementKey($identity),
                'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
            ]);
            $allocation->reservation_kind = 'SIMPLE';
            $allocation->reservation_id = $simpleRes->getKey();
            $allocation->inventory_product_id = $simpleRes->inventory_product_id;

            return true;
        }

        $reference = 'ML orden '.$order->order_id.' línea '.($item->remote_line_key ?: $item->id);

        if ($allocation->reservation_kind === 'KIT') {
            $this->kits->fulfill($reservation, [
                'reference' => $reference,
                'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
            ]);
        } else {
            $locationId = $reservation->inventory_location_id;
            if ($locationId === null) {
                $locationId = $this->resolveLocationForProduct($reservation->product ?? $reservation->inventory_product_id);
            }

            $this->reservations->fulfill($reservation, [
                'inventory_location_id' => $locationId,
                'reference' => $reference,
                'external_key' => $this->fulfillmentMovementKey($identity),
                'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
            ]);
        }

        return true;
    }

    private function resolveLocationForProduct(InventoryProduct|int $product): int
    {
        $prod = $product instanceof InventoryProduct ? $product : InventoryProduct::find($product);
        if ($prod?->primary_location_id) {
            return (int) $prod->primary_location_id;
        }

        $locationWithStock = DB::table('inventory_movements')
            ->select('inventory_location_id')
            ->selectRaw('SUM(quantity) as stock')
            ->where('inventory_product_id', $prod?->id)
            ->groupBy('inventory_location_id')
            ->having('stock', '>', 0)
            ->orderByDesc('stock')
            ->value('inventory_location_id');

        if ($locationWithStock) {
            return (int) $locationWithStock;
        }

        return (int) (InventoryLocation::query()
            ->where('is_active', true)
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id') ?? 1);
    }

    /**
     * @param  array{line: string, hash: string}  $identity
     * @param  array<string, mixed>  $diagnostic
     * @param  array{lease_owner: string, fencing_token: int}|null  $leaseContext
     */
    private function saveDiagnostic(
        MeliOrder $order,
        MeliOrderItem $item,
        array $identity,
        array $diagnostic,
        ?array $leaseContext = null,
    ): void {
        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return;
        }

        DB::transaction(function () use ($order, $item, $identity, $diagnostic, $leaseContext): void {
            $freshOrder = MeliOrder::query()->lockForUpdate()->find($order->getKey()) ?? $order;
            $this->assertFencingLeaseInsideTransaction($freshOrder, $leaseContext);
            $this->saveDiagnosticInsideTransaction($freshOrder, $item, $identity, $diagnostic);
        });
    }

    /**
     * @param  array{line: string, hash: string}  $identity
     * @param  array<string, mixed>  $diagnostic
     */
    private function saveDiagnosticInsideTransaction(
        MeliOrder $order,
        MeliOrderItem $item,
        array $identity,
        array $diagnostic,
    ): void {
        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return;
        }

        $allocation = $this->ensureLockedAllocation($order, $identity);

        // Preserve terminal FULFILLED / RELEASED and live ACTIVE statuses — never overwrite them with DIAGNOSTIC.
        $preserveStatus = in_array($allocation->status, ['FULFILLED', 'RELEASED'], true)
            || ($allocation->status === 'ACTIVE' && $this->currentReservation($allocation, true)?->status === InventoryReservation::ACTIVE);

        $allocation->forceFill([
            'status' => $preserveStatus ? $allocation->status : 'DIAGNOSTIC',
            'diagnostic_code' => $diagnostic['action'] ?? 'UNMATCHED',
            'diagnostic_metadata' => array_filter([
                'product_id' => data_get($diagnostic, 'product.id'),
                'sku' => $diagnostic['sku'] ?? null,
                'requested_quantity' => (int) $item->quantity,
                'available' => $diagnostic['available'] ?? null,
                'manual_movement_id' => $diagnostic['manual_movement_id'] ?? null,
                'manual_movement_type' => $diagnostic['manual_movement_type'] ?? null,
            ], static fn (mixed $v): bool => $v !== null),
        ])->save();
    }
}
