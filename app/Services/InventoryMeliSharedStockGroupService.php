<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class InventoryMeliSharedStockGroupService
{
    public function isGrouped(InventoryChannelLink $link): bool
    {
        return $link->channel === InventoryChannelLink::MERCADO_LIBRE
            && filled($link->account_key)
            && $link->external_variant_id === null
            && filled($link->remote_user_product_id);
    }

    public function links(InventoryChannelLink $link, bool $eligibleOnly = false): Collection
    {
        if (! $this->isGrouped($link)) {
            return new Collection([$link]);
        }

        return InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', $link->account_key)
            ->where('remote_user_product_id', $link->remote_user_product_id)
            ->whereNull('external_variant_id')
            ->when($eligibleOnly, fn ($query) => $query->where('is_active', true)->where('stock_sync_enabled', true))
            ->orderBy('id')
            ->get();
    }

    public function representative(InventoryChannelLink $link): InventoryChannelLink
    {
        return $this->links($link, true)->first() ?? $link;
    }

    /** @return array{conflict:bool,link_ids:list<int>,product_ids:list<int>} */
    public function conflict(InventoryChannelLink $link): array
    {
        $links = $this->links($link, true);
        $linkIds = $links->pluck('id')->map(fn ($id) => (int) $id)->all();
        $productIds = $links->pluck('inventory_product_id')->unique()->map(fn ($id) => (int) $id)->values()->all();

        return ['conflict' => count($productIds) > 1, 'link_ids' => $linkIds, 'product_ids' => $productIds];
    }

    public function lockKey(InventoryChannelLink $link): string
    {
        if (! $this->isGrouped($link)) {
            return 'inventory-meli-stock-sync:link:'.$link->getKey();
        }

        return 'inventory:meli-stock-group:'.hash('sha256', $link->account_key."\0".$link->remote_user_product_id);
    }

    public function lock(InventoryChannelLink $link)
    {
        return Cache::lock($this->lockKey($link), 600);
    }
}
