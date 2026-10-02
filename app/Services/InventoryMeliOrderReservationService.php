<?php

namespace App\Services;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryMeliOrderReservationService
{
    public const RESERVE = 'RESERVE';
    public const RELEASE = 'RELEASE';
    public const FULFILL = 'FULFILL';

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
        return $order->items->map(fn (MeliOrderItem $item): array => $this->inspect($order, $item))->all();
    }

    /** Apply one known local order. */
    public function apply(MeliOrder $order): array
    {
        $results = [];
        foreach ($order->items as $item) {
            $results[] = $this->applyLine($order, $item);
        }
        return $results;
    }

    private function applyLine(MeliOrder $order, MeliOrderItem $item): array
    {
        $inspection = $this->inspect($order, $item);
        $identity = $this->identity($order, $item);
        if (! Schema::hasTable('inventory_channel_order_allocations')) {
            return $inspection;
        }
        if (in_array($inspection['action'], ['UNMATCHED', 'AMBIGUOUS', 'LEGACY_LINE_IDENTITY_UNKNOWN', 'IGNORED_STATUS', 'SKIPPED_ORDER_RESERVATION_DISABLED', 'SKIPPED_INACTIVE_LINK', 'SKIPPED_INACTIVE_PRODUCT', 'REMOTE_USER_PRODUCT_CONFLICT', 'RELEASE_NOTHING_TO_DO', 'FULFILL_NOTHING_TO_DO'], true)) {
            if (! in_array($inspection['action'], ['RELEASE_NOTHING_TO_DO', 'FULFILL_NOTHING_TO_DO', 'IGNORED_STATUS'], true)) {
                $this->saveDiagnostic($order, $item, $identity, $inspection);
            }
            return $inspection;
        }

        try {
            return DB::transaction(function () use ($order, $item, $identity, $inspection): array {
                DB::table('inventory_channel_order_allocations')->insertOrIgnore([
                    'channel' => InventoryChannelLink::MERCADO_LIBRE,
                    'account_key' => (string) $order->meli_account_id,
                    'remote_order_id' => (string) $order->order_id,
                    'remote_line_key' => $identity['line'],
                    'identity_hash' => $identity['hash'],
                    'status' => 'PENDING',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $allocation = InventoryChannelOrderAllocation::query()->where('identity_hash', $identity['hash'])->lockForUpdate()->firstOrFail();
                $action = $inspection['action'];

                if ($action === self::RELEASE) {
                    $this->releaseCurrent($allocation);
                    $allocation->forceFill(['status' => 'RELEASED', 'quantity' => 0, 'diagnostic_code' => null, 'diagnostic_metadata' => null])->save();
                    return $inspection + ['applied' => true];
                }

                if ($action === self::FULFILL) {
                    $this->fulfillCurrent($allocation, $order, $item);
                    $allocation->forceFill(['status' => 'FULFILLED', 'quantity' => 0, 'diagnostic_code' => null, 'diagnostic_metadata' => null])->save();
                    return $inspection + ['applied' => true];
                }

                $link = $inspection['link'];
                $product = $inspection['product'];
                $qty = (int) $item->quantity;
                $current = $this->currentReservation($allocation, true);
                if ($allocation->status === 'ACTIVE' && $current && $current->status === InventoryReservation::ACTIVE && $allocation->quantity === $qty) {
                    return array_merge($inspection, ['action' => 'NO_CHANGE', 'applied' => true]);
                }

                // Releasing and replacing in one transaction makes quantity reconciliation atomic.
                if ($current && $current->status === InventoryReservation::ACTIVE) {
                    $this->releaseCurrent($allocation);
                }
                $baseKey = 'meli-order:'.$identity['hash'];
                $version = ((int) $allocation->reservation_version) + 1;
                $externalKey = substr($baseKey.':'.$version, 0, 191);
                $reference = 'ML order '.$order->order_id.' line '.$identity['line'];
                $source = [
                    'source_type' => 'meli_order', 'source_id' => $order->id,
                    'reference' => $reference, 'external_key' => $externalKey,
                    'metadata' => ['channel' => 'mercado_libre', 'account_key' => (string) $order->meli_account_id,
                        'remote_order_id' => (string) $order->order_id, 'remote_line_key' => $identity['line'],
                        'listing_id' => $item->item_id, 'variation_id' => $item->variation_id],
                ];
                if ($product->isKit()) {
                    $reservation = $this->kits->reserve($source + ['kit_product_id' => $product->id, 'quantity' => $qty]);
                    $kind = 'KIT';
                } else {
                    $reservation = $this->reservations->create($source + ['inventory_product_id' => $product->id, 'quantity' => $qty]);
                    $kind = 'SIMPLE';
                }
                $allocation->forceFill([
                    'inventory_channel_link_id' => $link->id, 'inventory_product_id' => $product->id,
                    'reservation_kind' => $kind, 'reservation_id' => $reservation->id,
                    'quantity' => $qty, 'reservation_version' => $version, 'status' => 'ACTIVE', 'diagnostic_code' => null, 'diagnostic_metadata' => null,
                ])->save();
                return array_merge($inspection, ['applied' => true, 'reservation_id' => $reservation->id]);
            });
        } catch (InventoryInsufficientStockException $e) {
            $available = $e->available;
            $result = array_merge($inspection, ['action' => 'INSUFFICIENT_INVENTORY', 'available' => $available, 'diagnostic' => 'INSUFFICIENT_INVENTORY']);
            $this->saveDiagnostic($order, $item, $identity, $result);
            return $result;
        }
    }

    private function inspect(MeliOrder $order, MeliOrderItem $item): array
    {
        $result = ['account' => (string) $order->meli_account_id, 'order_id' => (string) $order->order_id,
            'mlm' => $item->item_id, 'variation' => $item->variation_id, 'quantity' => (int) $item->quantity,
            'order_status' => $order->status, 'product' => null, 'sku' => $item->sku, 'reservation_id' => null,
            'action' => 'UNMATCHED', 'link' => null];
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
            $result['action'] = 'IGNORED_STATUS'; return $result;
        }
        if ($category === InventoryMeliOrderReservationPolicy::RELEASE) {
            $allocation = Schema::hasTable('inventory_channel_order_allocations')
                ? InventoryChannelOrderAllocation::query()->where('identity_hash', $this->identity($order, $item)['hash'])->first() : null;
            $result['allocation_id'] = $allocation?->id;
            $result['reservation_id'] = $allocation?->reservation_id;
            $result['action'] = $allocation?->status === 'ACTIVE' ? self::RELEASE : 'RELEASE_NOTHING_TO_DO';
            return $result;
        }
        if ($category === InventoryMeliOrderReservationPolicy::FULFILL) {
            $allocation = Schema::hasTable('inventory_channel_order_allocations')
                ? InventoryChannelOrderAllocation::query()->where('identity_hash', $this->identity($order, $item)['hash'])->first() : null;
            $result['allocation_id'] = $allocation?->id;
            $result['reservation_id'] = $allocation?->reservation_id;
            if ($allocation && $allocation->status === 'ACTIVE') {
                $result['action'] = self::FULFILL;

                return $result;
            }

            $directKit = InventoryKitReservation::query()->active()
                ->where('source_type', 'meli_order')
                ->where('source_id', $order->id)
                ->first();
            if ($directKit) {
                $result['action'] = self::FULFILL;
                $result['reservation_id'] = $directKit->id;

                return $result;
            }

            $directReservation = InventoryReservation::query()->active()
                ->where('source_type', 'meli_order')
                ->where('source_id', $order->id)
                ->first();
            if ($directReservation) {
                $result['action'] = self::FULFILL;
                $result['reservation_id'] = $directReservation->id;

                return $result;
            }

            $result['action'] = 'FULFILL_NOTHING_TO_DO';

            return $result;
        }
        $query = InventoryChannelLink::query()->with('product')
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', (string) $order->meli_account_id)
            ->where('external_listing_id', (string) $item->item_id);
        $item->variation_id === null
            ? $query->whereNull('external_variant_id')
            : $query->where('external_variant_id', (string) $item->variation_id);
        $links = $query->get();
        if ($links->count() > 1) { $result['action'] = 'AMBIGUOUS'; return $result; }
        $link = $links->first();
        if (! $link) { $result['action'] = 'UNMATCHED'; return $result; }
        $result['link'] = $link;
        $result['product'] = $link->product;
        $result['sku'] = $link->product?->sku;
        if (! $link->is_active) { $result['action'] = 'SKIPPED_INACTIVE_LINK'; return $result; }
        if (! $link->product || ! $link->product->is_active) { $result['action'] = 'SKIPPED_INACTIVE_PRODUCT'; return $result; }
        if (! $link->order_reservation_enabled) { $result['action'] = 'SKIPPED_ORDER_RESERVATION_DISABLED'; return $result; }
        if ($this->hasSharedStockConflict($link)) { $result['action'] = 'REMOTE_USER_PRODUCT_CONFLICT'; return $result; }
        $result['action'] = $category === InventoryMeliOrderReservationPolicy::RELEASE ? self::RELEASE : self::RESERVE;
        $allocation = Schema::hasTable('inventory_channel_order_allocations')
            ? InventoryChannelOrderAllocation::query()->where('identity_hash', $this->identity($order, $item)['hash'])->first() : null;
        $result['allocation_id'] = $allocation?->id;
        $result['reservation_id'] = $allocation?->reservation_id;
        if ($allocation && $allocation->status === 'ACTIVE' && $allocation->quantity === (int) $item->quantity && $category === InventoryMeliOrderReservationPolicy::RESERVABLE) {
            $reservation = $this->currentReservation($allocation);
            if ($reservation?->status === InventoryReservation::ACTIVE) {
                $result['action'] = 'NO_CHANGE';
                return $result;
            }
            $result['action'] = 'STALE_ALLOCATION';
        }
        if ($category === InventoryMeliOrderReservationPolicy::RESERVABLE) {
            $available = $link->product->isKit() ? $this->kitStock->availableStock($link->product) : $this->stock->availableStock($link->product);
            if ($allocation?->status === 'ACTIVE' && $this->currentReservation($allocation)?->status === InventoryReservation::ACTIVE) {
                $available += (int) $allocation->quantity;
            }
            $result['available'] = $available;
            if ((int) $item->quantity > $available) $result['action'] = 'INSUFFICIENT_INVENTORY';
        }
        return $result;
    }

    private function hasSharedStockConflict(InventoryChannelLink $link): bool
    {
        return $this->sharedStockGroups->isGrouped($link)
            && $this->sharedStockGroups->conflict($link)['conflict'];
    }

    private function identity(MeliOrder $order, MeliOrderItem $item): array
    {
        $line = $item->remote_line_key ?: 'legacy-row:'.$item->getKey();
        $hash = hash('sha256', implode("\0", ['mercado_libre', (string) $order->meli_account_id, (string) $order->order_id, $line]));
        return ['line' => $line, 'hash' => $hash];
    }

    private function currentReservation(InventoryChannelOrderAllocation $allocation, bool $lock = false): InventoryReservation|InventoryKitReservation|null
    {
        if (! $allocation->reservation_id) return null;
        $query = $allocation->reservation_kind === 'KIT' ? InventoryKitReservation::query() : InventoryReservation::query();
        if ($lock) $query->lockForUpdate();
        return $query->find($allocation->reservation_id);
    }

    private function releaseCurrent(InventoryChannelOrderAllocation $allocation): void
    {
        $reservation = $this->currentReservation($allocation, true);
        if (! $reservation || $reservation->status !== 'ACTIVE') return;
        if ($allocation->reservation_kind === 'KIT') $this->kits->release($reservation);
        else $this->reservations->release($reservation);
    }

    private function fulfillCurrent(InventoryChannelOrderAllocation $allocation, MeliOrder $order, MeliOrderItem $item): void
    {
        $reservation = $this->currentReservation($allocation, true);
        if (! $reservation || $reservation->status !== 'ACTIVE') {
            $directKit = InventoryKitReservation::query()->active()
                ->where('source_type', 'meli_order')
                ->where('source_id', $order->id)
                ->lockForUpdate()
                ->first();
            if ($directKit) {
                $reference = 'ML orden '.$order->order_id.' línea '.($item->remote_line_key ?: $item->id);
                $this->kits->fulfill($directKit, [
                    'reference' => $reference,
                    'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
                ]);

                return;
            }

            $directReservation = InventoryReservation::query()->active()
                ->where('source_type', 'meli_order')
                ->where('source_id', $order->id)
                ->lockForUpdate()
                ->first();
            if ($directReservation) {
                $reference = 'ML orden '.$order->order_id.' línea '.($item->remote_line_key ?: $item->id);
                $locationId = $directReservation->inventory_location_id ?? $this->resolveLocationForProduct($directReservation->product ?? $directReservation->inventory_product_id);
                $this->reservations->fulfill($directReservation, [
                    'inventory_location_id' => $locationId,
                    'reference' => $reference,
                    'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
                ]);

                return;
            }

            return;
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
                'notes' => 'Cumplimiento automático por envío (Estado: '.($order->shipping_status ?? 'shipped').')',
            ]);
        }
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

    private function saveDiagnostic(MeliOrder $order, MeliOrderItem $item, array $identity, array $diagnostic): void
    {
        if (! Schema::hasTable('inventory_channel_order_allocations')) return;
        DB::table('inventory_channel_order_allocations')->insertOrIgnore([
            'channel' => 'mercado_libre', 'account_key' => (string) $order->meli_account_id,
            'remote_order_id' => (string) $order->order_id, 'remote_line_key' => $identity['line'],
            'identity_hash' => $identity['hash'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $allocation = InventoryChannelOrderAllocation::query()->where('identity_hash', $identity['hash'])->first();
        if ($allocation) {
            $allocation->forceFill([
                'status' => $allocation->status === 'ACTIVE'
                    && $this->currentReservation($allocation)?->status === InventoryReservation::ACTIVE
                    ? 'ACTIVE'
                    : 'DIAGNOSTIC',
                'diagnostic_code' => $diagnostic['action'] ?? 'UNMATCHED',
                'diagnostic_metadata' => ['product_id' => data_get($diagnostic, 'product.id'), 'sku' => $diagnostic['sku'] ?? null,
                    'requested_quantity' => (int) $item->quantity, 'available' => $diagnostic['available'] ?? null],
            ])->save();
        }
    }
}
