<?php

namespace App\Services;

use App\Jobs\SyncInventoryMeliStockLinkJob;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitComponent;
use App\Models\InventoryProduct;
use App\Models\MeliOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InventoryMeliAffectedStockDispatcher
{
    public function dispatchForOrder(MeliOrder $order): int
    {
        $linkIds = $this->resolveDispatchableLinkIdsForOrder($order);

        foreach ($linkIds as $linkId) {
            SyncInventoryMeliStockLinkJob::dispatch($linkId);
        }

        return count($linkIds);
    }

    /**
     * Resolves the deterministic deduplicated list of representative link IDs whose stock
     * should be synchronized for this order, allowing atomic same-DB queue handoff (H9).
     *
     * @return list<int>
     */
    public function resolveDispatchableLinkIdsForOrder(MeliOrder $order): array
    {
        $productIds = $this->orderProductIds($order);

        if ($productIds->isEmpty()) {
            return [];
        }

        return $this->resolveDispatchableLinkIdsForProducts($productIds->all());
    }

    /**
     * @param  list<int>  $productIds
     */
    public function dispatchForProducts(array $productIds): int
    {
        $linkIds = $this->resolveDispatchableLinkIdsForProducts($productIds);

        foreach ($linkIds as $linkId) {
            SyncInventoryMeliStockLinkJob::dispatch($linkId);
        }

        return count($linkIds);
    }

    /**
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public function resolveDispatchableLinkIdsForProducts(array $productIds): array
    {
        $affectedProductIds = $this->affectedProductIds($productIds);

        if ($affectedProductIds->isEmpty()) {
            return [];
        }

        $links = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->whereIn('inventory_product_id', $affectedProductIds->all())
            ->where('is_active', true)
            ->where('stock_sync_enabled', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();

        $dispatched = [];
        $linkIds = [];

        foreach ($links as $link) {
            $key = $this->dispatchKey($link);

            if (isset($dispatched[$key])) {
                continue;
            }

            $dispatched[$key] = true;
            $linkIds[] = (int) $link->getKey();
        }

        return $linkIds;
    }

    /**
     * Return every Inventory product whose available stock can be affected.
     *
     * SIMPLE:
     *   simple + every kit that uses it.
     *
     * KIT:
     *   kit + its simple components + every kit that uses those components.
     *
     * Nested kits are intentionally unsupported by InventoryKitService, so
     * one dependency expansion is sufficient.
     *
     * @param list<int> $productIds
     * @return Collection<int, int>
     */
    public function affectedProductIds(array $productIds): Collection
    {
        $seedIds = collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($seedIds->isEmpty()) {
            return collect();
        }

        $products = InventoryProduct::query()
            ->whereIn('id', $seedIds->all())
            ->get(['id', 'product_type']);

        if ($products->isEmpty()) {
            return collect();
        }

        $existingSeedIds = $products
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        $simpleSeedIds = $products
            ->filter(fn (InventoryProduct $product): bool => $product->isSimple())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        $seedKitIds = $products
            ->filter(fn (InventoryProduct $product): bool => $product->isKit())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        $componentIds = $seedKitIds->isEmpty()
            ? collect()
            : InventoryKitComponent::query()
                ->whereIn('kit_product_id', $seedKitIds->all())
                ->pluck('component_product_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

        $changedSimpleIds = $simpleSeedIds
            ->merge($componentIds)
            ->unique()
            ->values();

        $dependentKitIds = $changedSimpleIds->isEmpty()
            ? collect()
            : InventoryKitComponent::query()
                ->whereIn('component_product_id', $changedSimpleIds->all())
                ->pluck('kit_product_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

        return $existingSeedIds
            ->merge($componentIds)
            ->merge($dependentKitIds)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function orderProductIds(MeliOrder $order): Collection
    {
        if (
            ! Schema::hasTable('inventory_channel_order_allocations')
            || ! $order->meli_account_id
            || ! $order->order_id
        ) {
            return collect();
        }

        return InventoryChannelOrderAllocation::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', (string) $order->meli_account_id)
            ->where('remote_order_id', (string) $order->order_id)
            ->whereNotNull('inventory_product_id')
            ->pluck('inventory_product_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    private function dispatchKey(InventoryChannelLink $link): string
    {
        if (
            $link->external_variant_id === null
            && filled($link->remote_user_product_id)
        ) {
            return implode('|', [
                'group',
                (string) $link->account_key,
                (string) $link->remote_user_product_id,
            ]);
        }

        return 'link|'.$link->getKey();
    }
}