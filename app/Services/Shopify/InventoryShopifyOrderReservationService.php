<?php

namespace App\Services\Shopify;

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

class InventoryShopifyOrderReservationService
{
    public const RESERVE = 'RESERVE';

    public const RELEASE = 'RELEASE';

    public function __construct(
        private readonly InventoryReservationService $reservations,
        private readonly InventoryKitService $kits,
        private readonly InventoryStockService $stock,
        private readonly InventoryKitStockService $kitStock,
        private readonly InventoryShopifyClient $client,
    ) {}

    /**
     * Procesa un webhook de orden de Shopify (create, update, paid).
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    public function processOrderPayload(array $order, ?string $shopDomain = null): array
    {
        $shopDomain = $this->resolveShopDomain($shopDomain);
        $isCancelled = ! empty($order['cancelled_at']);
        $lineItems = (array) ($order['line_items'] ?? []);
        $results = [];

        foreach ($lineItems as $item) {
            $results[] = $this->applyLine($order, $item, $shopDomain, $isCancelled);
        }

        return $results;
    }

    /**
     * Cancela y libera todas las reservas de una orden de Shopify.
     *
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    public function cancelOrderPayload(array $order, ?string $shopDomain = null): array
    {
        $shopDomain = $this->resolveShopDomain($shopDomain);
        $lineItems = (array) ($order['line_items'] ?? []);
        $results = [];

        foreach ($lineItems as $item) {
            $results[] = $this->applyLine($order, $item, $shopDomain, true);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function previewLine(array $order, array $item, ?string $shopDomain = null): array
    {
        $shopDomain = $this->resolveShopDomain($shopDomain);
        $isCancelled = ! empty($order['cancelled_at']);

        return $this->inspect($order, $item, $shopDomain, $isCancelled);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function applyLine(array $order, array $item, string $shopDomain, bool $isCancelled): array
    {
        $identity = $this->identity($order, $item, $shopDomain);
        $inspection = $this->inspect($order, $item, $shopDomain, $isCancelled);

        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return $inspection;
        }

        if (in_array($inspection['action'], [
            'UNMATCHED',
            'SKIPPED_ORDER_RESERVATION_DISABLED',
            'SKIPPED_INACTIVE_LINK',
            'SKIPPED_INACTIVE_PRODUCT',
            'RELEASE_NOTHING_TO_DO',
        ], true)) {
            if ($inspection['action'] !== 'RELEASE_NOTHING_TO_DO') {
                $this->saveDiagnostic($identity, $inspection, (int) ($item['quantity'] ?? 0));
            }

            return $inspection;
        }

        try {
            return DB::transaction(function () use ($order, $item, $identity, $inspection, $shopDomain): array {
                DB::table('inventory_channel_order_allocations')->insertOrIgnore([
                    'channel' => InventoryChannelLink::SHOPIFY,
                    'account_key' => $shopDomain,
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
                $qty = (int) ($item['quantity'] ?? 1);

                $current = $this->currentReservation($allocation, true);
                if ($allocation->status === 'ACTIVE'
                    && $current
                    && $current->status === InventoryReservation::ACTIVE
                    && $allocation->quantity === $qty) {
                    return array_merge($inspection, ['action' => 'NO_CHANGE', 'applied' => true]);
                }

                // Si la cantidad cambió o la reserva es previa, liberar antes de reservar el nuevo total
                if ($current && $current->status === InventoryReservation::ACTIVE) {
                    $this->releaseCurrent($allocation);
                }

                $baseKey = 'shopify-order:'.$identity['hash'];
                $version = ((int) $allocation->reservation_version) + 1;
                $externalKey = substr($baseKey.':'.$version, 0, 191);
                $orderName = $order['name'] ?? $identity['order_id'];
                $reference = "Shopify order {$orderName} line {$identity['line']}";

                $source = [
                    'source_type' => 'shopify_order',
                    'source_id' => null,
                    'reference' => $reference,
                    'external_key' => $externalKey,
                    'metadata' => [
                        'channel' => InventoryChannelLink::SHOPIFY,
                        'account_key' => $shopDomain,
                        'remote_order_id' => $identity['order_id'],
                        'remote_line_key' => $identity['line'],
                        'variant_id' => $item['variant_id'] ?? null,
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
            $this->saveDiagnostic($identity, $result, (int) ($item['quantity'] ?? 0));

            return $result;
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function inspect(array $order, array $item, string $shopDomain, bool $isCancelled): array
    {
        $orderId = (string) ($order['id'] ?? '');
        $variantId = (string) ($item['variant_id'] ?? '');
        $quantity = (int) ($item['quantity'] ?? 0);
        $lineId = (string) ($item['id'] ?? '');

        $identity = $this->identity($order, $item, $shopDomain);

        $result = [
            'account' => $shopDomain,
            'order_id' => $orderId,
            'variant_id' => $variantId,
            'line_id' => $lineId,
            'quantity' => $quantity,
            'order_status' => $order['financial_status'] ?? 'unknown',
            'product' => null,
            'sku' => $item['sku'] ?? null,
            'reservation_id' => null,
            'action' => 'UNMATCHED',
            'link' => null,
        ];

        if ($lineId === '') {
            $result['action'] = 'UNMATCHED';

            return $result;
        }

        if ($isCancelled) {
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
            ->where('channel', InventoryChannelLink::SHOPIFY)
            ->where('account_key', $shopDomain)
            ->where('external_variant_id', $variantId)
            ->first();

        if (! $link) {
            $result['action'] = 'UNMATCHED';

            return $result;
        }

        $result['link'] = $link;
        $result['product'] = $link->product;
        $result['sku'] = $link->product?->sku ?? $item['sku'] ?? null;

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
    private function identity(array $order, array $item, string $shopDomain): array
    {
        $orderId = (string) ($order['id'] ?? '');
        $lineId = (string) ($item['id'] ?? '');
        $hash = hash('sha256', implode("\0", [
            InventoryChannelLink::SHOPIFY,
            $shopDomain,
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
            'channel' => InventoryChannelLink::SHOPIFY,
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

    private function resolveShopDomain(?string $domain): string
    {
        if (filled($domain)) {
            return trim((string) $domain);
        }

        try {
            return $this->client->getStoreDomain();
        } catch (\Throwable) {
            return (string) config('services.shopify.store_domain', 'shopify');
        }
    }
}
