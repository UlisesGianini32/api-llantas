<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitComponent;
use App\Models\MeliOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded, diagnostic-first administrative re-evaluation service for Mercado Libre order reservations (H10).
 *
 * Why administrative mutations (InventoryChannelLink, kits/components, manual stock receipts) must NOT
 * blindly scan all historical orders or enqueue jobs inside HTTP controllers:
 * - A single listing/kit/import change can reference thousands of historical orders.
 * - Ambiguous historical orders (MANUAL_MOVEMENT_REVIEW_REQUIRED, AMBIGUOUS, REMOTE_USER_PRODUCT_CONFLICT,
 *   LEGACY_LINE_IDENTITY_UNKNOWN) MUST NEVER be automatically reconstructed without human review.
 *
 * This service scopes strictly to post-cutover, open `paid` non-shipped orders affected by a specific
 * link/product/kit/order, previews every candidate, skips all ambiguous/terminal/consistent orders,
 * enforces a hard upper bound (default 100, max 500), and only dispatches `dispatchForceReconcile()`
 * on explicitly verified safe candidates when `$apply === true`.
 */
class InventoryMeliOrderAdministrativeReevaluationService
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 500;

    /** @var list<string> */
    private const AMBIGUOUS_ACTIONS = [
        InventoryMeliOrderReservationService::MANUAL_MOVEMENT_REVIEW_REQUIRED,
        InventoryMeliOrderReservationService::ALREADY_FULFILLED_REVIEW_REQUIRED,
        'AMBIGUOUS',
        'REMOTE_USER_PRODUCT_CONFLICT',
        'LEGACY_LINE_IDENTITY_UNKNOWN',
    ];

    /** @var list<string> */
    private const CLOSED_SHIPPING_STATUSES = [
        'shipped',
        'delivered',
        'in_transit',
        'cancelled',
        'returned',
        'not_delivered',
    ];

    public function __construct(
        private readonly InventoryMeliOrderReservationService $reservationService,
        private readonly InventoryMeliOrderReservationCutover $cutover,
        private readonly InventoryMeliOrderReservationDispatcher $dispatcher,
        private readonly InventoryMeliOrderReconciliationCoordinator $coordinator,
    ) {}

    /**
     * @param  array{
     *     link_id?: ?int,
     *     product_id?: ?int,
     *     kit_id?: ?int,
     *     order_id?: ?int,
     *     since?: ?string,
     *     limit?: ?int
     * }  $scope
     * @return array{
     *     mode: string,
     *     limit: int,
     *     truncated: bool,
     *     scanned_orders: int,
     *     eligible_safe_candidates: list<array{meli_order_id: int, remote_order_id: string, meli_account_id: ?int, actions: list<string>}>,
     *     skipped_ambiguous_requires_human_review: list<array{meli_order_id: int, remote_order_id: string, reasons: list<string>}>,
     *     skipped_already_consistent_or_fulfilled: int,
     *     skipped_outside_cutover_or_closed: int,
     *     skipped_not_reservable: int,
     *     dispatched_count: int
     * }
     */
    public function evaluate(array $scope, bool $apply = false): array
    {
        $this->coordinator->assertStateTableExists();

        $limit = max(1, min(self::MAX_LIMIT, (int) ($scope['limit'] ?? self::DEFAULT_LIMIT)));

        if ($apply && ! (bool) config('inventory.meli_order_reservations.automatic', false)) {
            throw new InvalidArgumentException(
                'No se puede ejecutar --apply mientras INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC sea false.',
            );
        }

        $linkId = isset($scope['link_id']) && (int) $scope['link_id'] > 0 ? (int) $scope['link_id'] : null;
        $productId = isset($scope['product_id']) && (int) $scope['product_id'] > 0 ? (int) $scope['product_id'] : null;
        $kitId = isset($scope['kit_id']) && (int) $scope['kit_id'] > 0 ? (int) $scope['kit_id'] : null;
        $orderId = isset($scope['order_id']) && (int) $scope['order_id'] > 0 ? (int) $scope['order_id'] : null;

        if ($linkId === null && $productId === null && $kitId === null && $orderId === null) {
            throw new InvalidArgumentException(
                'Debe especificar un alcance acotado (--link-id, --product-id, --kit-id o --order-id); se prohíbe recorrer todo el historial.',
            );
        }

        $sinceAt = $this->resolveSinceBound($scope['since'] ?? null);
        $targetOrders = $this->findScopedCandidateOrders($linkId, $productId, $kitId, $orderId, $sinceAt, $limit + 1);
        $truncated = $targetOrders->count() > $limit;
        $orders = $targetOrders->take($limit);

        $eligible = [];
        $ambiguous = [];
        $skippedConsistent = 0;
        $skippedOutsideOrClosed = 0;
        $skippedNotReservable = 0;
        $dispatched = 0;

        foreach ($orders as $order) {
            if (! $this->cutover->allows($order)) {
                $skippedOutsideOrClosed++;

                continue;
            }

            $status = strtolower(trim((string) ($order->status ?? '')));
            $shipStatus = strtolower(trim((string) ($order->shipping_status ?? '')));
            if ($status !== 'paid' || in_array($shipStatus, self::CLOSED_SHIPPING_STATUSES, true)) {
                $skippedOutsideOrClosed++;

                continue;
            }

            // Never auto-reconstruct orders with existing terminal FULFILLED or ambiguous DIAGNOSTIC allocations.
            $existingAllocs = Schema::hasTable('inventory_channel_order_allocations')
                ? InventoryChannelOrderAllocation::query()
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->where('account_key', (string) $order->meli_account_id)
                    ->where('remote_order_id', (string) $order->order_id)
                    ->get()
                : collect();

            $existingAmbiguousCodes = $existingAllocs
                ->filter(static fn (InventoryChannelOrderAllocation $a): bool => in_array((string) $a->diagnostic_code, self::AMBIGUOUS_ACTIONS, true))
                ->pluck('diagnostic_code')
                ->unique()
                ->values()
                ->all();

            if ($existingAmbiguousCodes !== []) {
                $ambiguous[] = [
                    'meli_order_id' => (int) $order->id,
                    'remote_order_id' => (string) $order->order_id,
                    'reasons' => $existingAmbiguousCodes,
                ];

                continue;
            }

            $previewLines = $this->reservationService->preview($order);
            $actions = array_values(array_map(static fn (array $r): string => (string) ($r['action'] ?? 'UNKNOWN'), $previewLines));

            $lineAmbiguities = array_values(array_unique(array_intersect($actions, self::AMBIGUOUS_ACTIONS)));
            if ($lineAmbiguities !== []) {
                $ambiguous[] = [
                    'meli_order_id' => (int) $order->id,
                    'remote_order_id' => (string) $order->order_id,
                    'reasons' => $lineAmbiguities,
                ];

                continue;
            }

            $hasReserveNeeded = in_array(InventoryMeliOrderReservationService::RESERVE, $actions, true)
                || in_array('STALE_ALLOCATION', $actions, true);

            if (! $hasReserveNeeded) {
                $allConsistent = $actions !== [] && collect($actions)->every(static fn (string $a): bool => in_array($a, [
                    'NO_CHANGE',
                    InventoryMeliOrderReservationService::ALREADY_FULFILLED,
                    'FULFILL_NOTHING_TO_DO',
                ], true));

                if ($allConsistent) {
                    $skippedConsistent++;
                } else {
                    $skippedNotReservable++;
                }

                continue;
            }

            $eligible[] = [
                'meli_order_id' => (int) $order->id,
                'remote_order_id' => (string) $order->order_id,
                'meli_account_id' => $order->meli_account_id !== null ? (int) $order->meli_account_id : null,
                'actions' => $actions,
            ];

            if ($apply) {
                $this->dispatcher->dispatchForceReconcile($order);
                $dispatched++;
            }
        }

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'limit' => $limit,
            'truncated' => $truncated,
            'scanned_orders' => $orders->count(),
            'eligible_safe_candidates' => $eligible,
            'skipped_ambiguous_requires_human_review' => $ambiguous,
            'skipped_already_consistent_or_fulfilled' => $skippedConsistent,
            'skipped_outside_cutover_or_closed' => $skippedOutsideOrClosed,
            'skipped_not_reservable' => $skippedNotReservable,
            'dispatched_count' => $dispatched,
        ];
    }

    private function resolveSinceBound(?string $since): ?CarbonImmutable
    {
        $raw = $since !== null && trim($since) !== ''
            ? trim($since)
            : trim((string) (config('inventory.meli_order_reservations.automatic_after') ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MeliOrder>
     */
    private function findScopedCandidateOrders(
        ?int $linkId,
        ?int $productId,
        ?int $kitId,
        ?int $orderId,
        ?CarbonImmutable $sinceAt,
        int $fetchLimit,
    ) {
        if ($orderId !== null) {
            return MeliOrder::query()
                ->with('items')
                ->whereKey($orderId)
                ->limit($fetchLimit)
                ->get();
        }

        $links = collect();

        if ($linkId !== null) {
            $link = InventoryChannelLink::query()
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->find($linkId);
            if ($link) {
                $links->push($link);
            }
        } else {
            $productIds = [];
            if ($kitId !== null) {
                $productIds[] = $kitId;
            }
            if ($productId !== null) {
                $productIds[] = $productId;
                // Also include kits that depend on this simple component.
                $dependentKits = InventoryKitComponent::query()
                    ->where('component_product_id', $productId)
                    ->pluck('kit_product_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                $productIds = array_values(array_unique(array_merge($productIds, $dependentKits)));
            }

            if ($productIds !== []) {
                $links = InventoryChannelLink::query()
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->whereIn('inventory_product_id', $productIds)
                    ->get();
            }
        }

        if ($links->isEmpty()) {
            return MeliOrder::query()->whereRaw('1 = 0')->get();
        }

        $listingIds = $links->pluck('external_listing_id')->filter()->unique()->values()->all();
        $accountIds = $links->pluck('account_key')
            ->filter(static fn ($k): bool => ctype_digit((string) $k))
            ->map(static fn ($k): int => (int) $k)
            ->unique()
            ->values()
            ->all();

        if ($listingIds === []) {
            return MeliOrder::query()->whereRaw('1 = 0')->get();
        }

        return MeliOrder::query()
            ->with('items')
            ->where('status', 'paid')
            ->when($accountIds !== [], fn ($q) => $q->whereIn('meli_account_id', $accountIds))
            ->when($sinceAt !== null, fn ($q) => $q->where('created_at', '>=', $sinceAt->subDay()))
            ->whereHas('items', fn ($q) => $q->whereIn('item_id', $listingIds))
            ->orderByDesc('id')
            ->limit($fetchLimit)
            ->get();
    }
}
