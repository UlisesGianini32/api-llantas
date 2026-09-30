<?php

namespace App\Services\Amazon;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitReservation;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Services\InventoryKitService;
use App\Services\InventoryKitStockService;
use App\Services\InventoryReservationService;
use App\Services\InventoryStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryAmazonOrderReservationService
{
    public const RESERVE = 'RESERVE';

    public const RELEASE = 'RELEASE';

    public const SKIPPED_FBA = 'SKIPPED_FBA_FULFILLMENT';

    public function __construct(
        private readonly InventoryReservationService $reservations,
        private readonly InventoryKitService $kits,
        private readonly InventoryStockService $stock,
        private readonly InventoryKitStockService $kitStock,
        private readonly InventoryAmazonClient $client,
    ) {}

    /**
     * Procesa un payload de orden de Amazon (Orders API o notificación SQS/EventBridge).
     *
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>|null  $orderItems
     * @return list<array<string, mixed>>
     */
    public function processOrderPayload(array $order, ?array $orderItems = null, ?string $sellerId = null): array
    {
        $sellerId = $this->resolveSellerId($sellerId);
        $items = $orderItems ?? (array) ($order['order_items'] ?? $order['OrderItems'] ?? []);
        $results = [];

        foreach ($items as $item) {
            $results[] = $this->applyLine($order, $item, $sellerId);
        }

        return $results;
    }

    /**
     * Cancela y libera las reservas asociadas a una orden de Amazon.
     *
     * @param  array<string, mixed>  $order
     * @param  list<array<string, mixed>>|null  $orderItems
     * @return list<array<string, mixed>>
     */
    public function cancelOrderPayload(array $order, ?array $orderItems = null, ?string $sellerId = null): array
    {
        $sellerId = $this->resolveSellerId($sellerId);
        $items = $orderItems ?? (array) ($order['order_items'] ?? $order['OrderItems'] ?? []);
        $results = [];

        foreach ($items as $item) {
            $results[] = $this->applyLine($order, $item, $sellerId, true);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyLine(array $order, array $item, string $sellerId, bool $forceRelease = false): array
    {
        $identity = $this->identity($order, $item, $sellerId);
        $inspection = $this->inspect($order, $item, $sellerId, $forceRelease);

        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return $inspection;
        }

        if (in_array($inspection['action'], [
            self::SKIPPED_FBA,
            'UNMATCHED',
            'SKIPPED_ORDER_RESERVATION_DISABLED',
            'SKIPPED_INACTIVE_LINK',
            'SKIPPED_INACTIVE_PRODUCT',
            'RELEASE_NOTHING_TO_DO',
        ], true)) {
            if (! in_array($inspection['action'], ['RELEASE_NOTHING_TO_DO', self::SKIPPED_FBA], true)) {
                $qty = (int) ($item['QuantityOrdered'] ?? $item['quantity'] ?? 0);
                $this->saveDiagnostic($identity, $inspection, $qty);
            }

            return $inspection;
        }

        try {
            return DB::transaction(function () use ($item, $identity, $inspection, $sellerId): array {
                DB::table('inventory_channel_order_allocations')->insertOrIgnore([
                    'channel' => InventoryChannelLink::AMAZON,
                    'account_key' => $sellerId,
                    'remote_order_id' => $identity['order_id'],
                    'remote_line_key' => $identity['line'],
                    'identity_hash' => $identity['hash'],
                    'status' => 'PENDING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                /** @var InventoryChannelOrderAllocation $allocation */
                $allocation = InventoryChannelOrderAllocation::query()
                    ->where('identity_hash', $identity['hash'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $action = $inspection['action'];

                if ($action === self::RELEASE) {
                    $this->releaseCurrent($allocation);
                    $allocation->forceFill([
                        'status' => 'RELEASED',
                        'quantity' => 0,
                        'diagnostic_code' => null,
                        'diagnostic_metadata' => null,
                    ])->save();

                    return array_merge($inspection, ['applied' => true]);
                }

                /** @var InventoryChannelLink $link */
                $link = $inspection['link'];
                /** @var InventoryProduct $product */
                $product = $inspection['product'];
                $qty = (int) ($item['QuantityOrdered'] ?? $item['quantity'] ?? 1);

                $current = $this->currentReservation($allocation, true);
                if ($allocation->status === 'ACTIVE'
                    && $current
                    && $current->status === InventoryReservation::ACTIVE
                    && $allocation->quantity === $qty) {
                    return array_merge($inspection, ['action' => 'NO_CHANGE', 'applied' => true]);
                }

                if ($current && $current->status === InventoryReservation::ACTIVE) {
                    $this->releaseCurrent($allocation);
                }

                $baseKey = 'amazon-order:'.$identity['hash'];
                $version = ((int) $allocation->reservation_version) + 1;
                $externalKey = substr($baseKey.':'.$version, 0, 191);
                $reference = "Amazon order {$identity['order_id']} item {$identity['line']}";

                $source = [
                    'source_type' => 'amazon_order',
                    'source_id' => null,
                    'reference' => $reference,
                    'external_key' => $externalKey,
                    'metadata' => [
                        'channel' => InventoryChannelLink::AMAZON,
                        'account_key' => $sellerId,
                        'remote_order_id' => $identity['order_id'],
                        'remote_line_key' => $identity['line'],
                        'seller_sku' => $inspection['seller_sku'],
                        'asin' => $inspection['asin'],
                    ],
                ];

                if ($product->isKit()) {
                    $reservation = $this->kits->reserve($source + [
                        'kit_product_id' => $product->id,
                        'quantity' => $qty,
                    ]);
                    $kind = 'KIT';
                } else {
                    $reservation = $this->reservations->create($source + [
                        'inventory_product_id' => $product->id,
                        'quantity' => $qty,
                    ]);
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

                return array_merge($inspection, [
                    'applied' => true,
                    'reservation_id' => $reservation->id,
                ]);
            });
        } catch (InventoryInsufficientStockException $e) {
            $available = $e->available;
            $result = array_merge($inspection, [
                'action' => 'INSUFFICIENT_INVENTORY',
                'available' => $available,
                'diagnostic' => 'INSUFFICIENT_INVENTORY',
            ]);
            $qty = (int) ($item['QuantityOrdered'] ?? $item['quantity'] ?? 0);
            $this->saveDiagnostic($identity, $result, $qty);

            return $result;
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function inspect(array $order, array $item, string $sellerId, bool $forceRelease): array
    {
        $orderId = (string) ($order['AmazonOrderId'] ?? $order['id'] ?? '');
        $orderItemId = (string) ($item['OrderItemId'] ?? $item['id'] ?? '');
        $sellerSku = trim((string) ($item['SellerSKU'] ?? $item['seller_sku'] ?? $item['sku'] ?? ''));
        $asin = trim((string) ($item['ASIN'] ?? $item['asin'] ?? ''));
        $quantity = (int) ($item['QuantityOrdered'] ?? $item['quantity'] ?? 0);
        $orderStatus = (string) ($order['OrderStatus'] ?? $order['status'] ?? 'Unshipped');

        // Aislamiento FBA: AFN = Amazon Fulfilled Network (FBA), MFN = Merchant Fulfilled Network (FBM)
        $fulfillmentChannel = (string) ($order['FulfillmentChannel'] ?? $item['FulfillmentChannel'] ?? 'MFN');
        $isFba = strtoupper($fulfillmentChannel) === 'AFN';

        $identity = $this->identity($order, $item, $sellerId);

        $result = [
            'account' => $sellerId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'seller_sku' => $sellerSku,
            'asin' => $asin,
            'quantity' => $quantity,
            'order_status' => $orderStatus,
            'fulfillment_channel' => $fulfillmentChannel,
            'product' => null,
            'reservation_id' => null,
            'action' => 'UNMATCHED',
            'link' => null,
        ];

        if ($isFba) {
            $result['action'] = self::SKIPPED_FBA;
            $result['reason'] = 'La orden es gestionada por la red logística de Amazon (FBA / AFN); no compromete inventario propio.';

            return $result;
        }

        $isCanceled = $forceRelease || strtolower($orderStatus) === 'canceled';

        if ($isCanceled) {
            $allocation = Schema::hasTable('inventory_channel_order_allocations')
                ? InventoryChannelOrderAllocation::query()->where('identity_hash', $identity['hash'])->first()
                : null;

            $result['allocation_id'] = $allocation?->id;
            $result['reservation_id'] = $allocation?->reservation_id;
            $result['action'] = $allocation?->status === 'ACTIVE' ? self::RELEASE : 'RELEASE_NOTHING_TO_DO';

            return $result;
        }

        /** @var InventoryChannelLink|null $link */
        $link = InventoryChannelLink::query()
            ->with('product')
            ->where('channel', InventoryChannelLink::AMAZON)
            ->where('account_key', $sellerId)
            ->where(function ($q) use ($sellerSku, $asin) {
                if ($sellerSku !== '') {
                    $q->where('external_listing_id', $sellerSku);
                }
                if ($asin !== '') {
                    $q->orWhere('external_product_id', $asin);
                }
            })
            ->first();

        if (! $link) {
            $result['action'] = 'UNMATCHED';

            return $result;
        }

        $result['link'] = $link;
        $result['product'] = $link->product;
        $result['sku'] = $link->product?->sku ?? $sellerSku;

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

        $result['action'] = self::RESERVE;

        $allocation = Schema::hasTable('inventory_channel_order_allocations')
            ? InventoryChannelOrderAllocation::query()->where('identity_hash', $identity['hash'])->first()
            : null;

        $result['allocation_id'] = $allocation?->id;
        $result['reservation_id'] = $allocation?->reservation_id;

        if ($allocation && $allocation->status === 'ACTIVE' && $allocation->quantity === $quantity) {
            $reservation = $this->currentReservation($allocation);
            if ($reservation && $reservation->status === InventoryReservation::ACTIVE) {
                $result['action'] = 'NO_CHANGE';

                return $result;
            }
        }

        $available = $link->product->isKit()
            ? $this->kitStock->availableStock($link->product)
            : $this->stock->availableStock($link->product);

        if ($allocation?->status === 'ACTIVE' && $this->currentReservation($allocation)?->status === InventoryReservation::ACTIVE) {
            $available += (int) $allocation->quantity;
        }

        $result['available'] = $available;
        if ($quantity > $available) {
            $result['action'] = 'INSUFFICIENT_INVENTORY';
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array{order_id:string,line:string,hash:string}
     */
    private function identity(array $order, array $item, string $sellerId): array
    {
        $orderId = (string) ($order['AmazonOrderId'] ?? $order['id'] ?? '');
        $lineId = (string) ($item['OrderItemId'] ?? $item['id'] ?? '');
        $hash = hash('sha256', implode("\0", [
            InventoryChannelLink::AMAZON,
            $sellerId,
            $orderId,
            $lineId,
        ]));

        return [
            'order_id' => $orderId,
            'line' => $lineId,
            'hash' => $hash,
        ];
    }

    private function currentReservation(InventoryChannelOrderAllocation $allocation, bool $lock = false): InventoryReservation|InventoryKitReservation|null
    {
        if (! $allocation->reservation_id) {
            return null;
        }

        $query = $allocation->reservation_kind === 'KIT'
            ? InventoryKitReservation::query()
            : InventoryReservation::query();

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
            /** @var InventoryKitReservation $reservation */
            $this->kits->release($reservation);
        } else {
            /** @var InventoryReservation $reservation */
            $this->reservations->release($reservation);
        }
    }

    /**
     * @param  array{order_id:string,line:string,hash:string}  $identity
     * @param  array<string, mixed>  $diagnostic
     */
    private function saveDiagnostic(array $identity, array $diagnostic, int $quantity): void
    {
        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return;
        }

        DB::table('inventory_channel_order_allocations')->insertOrIgnore([
            'channel' => InventoryChannelLink::AMAZON,
            'account_key' => $diagnostic['account'] ?? '',
            'remote_order_id' => $identity['order_id'],
            'remote_line_key' => $identity['line'],
            'identity_hash' => $identity['hash'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allocation = InventoryChannelOrderAllocation::query()->where('identity_hash', $identity['hash'])->first();
        if ($allocation) {
            $allocation->forceFill([
                'status' => $allocation->status === 'ACTIVE'
                    && $this->currentReservation($allocation)?->status === InventoryReservation::ACTIVE
                    ? 'ACTIVE'
                    : 'DIAGNOSTIC',
                'diagnostic_code' => $diagnostic['action'] ?? 'UNMATCHED',
                'diagnostic_metadata' => [
                    'product_id' => data_get($diagnostic, 'product.id'),
                    'sku' => $diagnostic['sku'] ?? null,
                    'requested_quantity' => $quantity,
                    'available' => $diagnostic['available'] ?? null,
                ],
            ])->save();
        }
    }

    private function resolveSellerId(?string $sellerId): string
    {
        if (filled($sellerId)) {
            return trim((string) $sellerId);
        }

        $fromClient = $this->client->getSellerId();
        if ($fromClient !== '') {
            return $fromClient;
        }

        return (string) config('services.amazon.seller_id', 'default_seller');
    }
}
