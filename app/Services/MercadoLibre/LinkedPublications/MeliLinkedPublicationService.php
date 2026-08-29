<?php

namespace App\Services\MercadoLibre\LinkedPublications;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Support\Collection;

class MeliLinkedPublicationService
{
    public function __construct(private readonly MeliAccountApiClient $api) {}

    /** @return array<string, mixed> */
    public function priceRelations(MeliPriceManagerItem $item, ?Collection $pool = null): array
    {
        $ids = $this->normalizedIds((array) $item->price_relation_ids);
        $detectedIds = $this->itemRelationIds((array) $item->raw_item);
        $members = $ids === [] ? collect() : ($pool !== null
            ? $pool->where('meli_account_id', $item->meli_account_id)->whereIn('meli_item_id', $ids)->values()
            : MeliPriceManagerItem::query()
                ->where('meli_account_id', $item->meli_account_id)
                ->whereIn('meli_item_id', $ids)
                ->get(['id', 'meli_account_id', 'meli_item_id', 'title', 'current_price', 'catalog_listing']));
        $items = collect([$item])->concat($members)->unique('meli_item_id')->values();

        return [
            'linked' => $item->price_sync_status === 'SYNC' && $members->isNotEmpty(),
            'detected' => $detectedIds !== [] || $ids !== [],
            'status' => $item->price_sync_status,
            'source' => 'meli_item_relations',
            'items' => $items->map(fn (MeliPriceManagerItem $member): array => $this->priceMember($member))->all(),
            'price_divergence' => $this->hasDivergence($items->pluck('current_price')),
        ];
    }

    /** @return array<string, mixed> */
    public function stockRelations(MeliPriceManagerItem $item, ?Collection $pool = null): array
    {
        $inventoryId = trim((string) $item->inventory_id);
        $members = $inventoryId === '' ? collect([$item]) : ($pool !== null
            ? $pool->where('meli_account_id', $item->meli_account_id)->where('inventory_id', $inventoryId)->sortBy('meli_item_id')->values()
            : MeliPriceManagerItem::query()
                ->where('meli_account_id', $item->meli_account_id)
                ->where('inventory_id', $inventoryId)
                ->orderBy('meli_item_id')
                ->get(['id', 'meli_account_id', 'meli_item_id', 'available_quantity', 'inventory_id', 'user_product_id']));

        return [
            'shared' => $inventoryId !== '' && $members->count() > 1,
            'source' => 'inventory_id',
            'inventory_id' => $inventoryId !== '' ? $inventoryId : null,
            'user_product_id' => $item->user_product_id,
            'items' => $members->map(fn (MeliPriceManagerItem $member): array => [
                'id' => (int) $member->id,
                'meli_item_id' => (string) $member->meli_item_id,
                'stock' => $member->available_quantity,
            ])->all(),
            'stock_divergence' => $this->hasDivergence($members->pluck('available_quantity')),
        ];
    }

    /** @return array<string, mixed> */
    public function refreshPriceRelations(MeliAccount $account, MeliPriceManagerItem $item): array
    {
        $declared = $this->itemRelationIds((array) $item->raw_item);
        if ($declared === []) {
            $item->forceFill([
                'price_sync_status' => null,
                'price_relation_ids' => [],
                'linked_synced_at' => now(),
            ])->save();

            return $this->priceRelations($item->refresh());
        }
        $response = $this->api->request(
            $account,
            'get',
            '/public/buybox/sync/'.rawurlencode((string) $item->meli_item_id),
            [],
            true,
            ['x-public' => 'True'],
        );
        $payload = (array) $response->json();
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));
        $official = $this->normalizedIds((array) ($payload['relations'] ?? []));
        $confirmed = $status === 'SYNC' ? array_values(array_intersect($declared, $official)) : [];

        $item->forceFill([
            'price_sync_status' => $status !== '' ? $status : null,
            'price_relation_ids' => $status === 'SYNC' ? $confirmed : $declared,
            'linked_synced_at' => now(),
        ])->save();

        return $this->priceRelations($item->refresh());
    }

    /** @return list<string> */
    private function itemRelationIds(array $rawItem): array
    {
        return $this->normalizedIds(array_map(
            static fn (mixed $relation): mixed => is_array($relation) ? ($relation['id'] ?? null) : $relation,
            (array) ($rawItem['item_relations'] ?? []),
        ));
    }

    /** @return list<string> */
    private function normalizedIds(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(static function (mixed $value): string {
            if (is_array($value)) {
                $value = $value['id'] ?? $value['item_id'] ?? null;
            }

            return mb_strtoupper(trim((string) $value), 'UTF-8');
        }, $values))));
    }

    /** @return array<string, mixed> */
    private function priceMember(MeliPriceManagerItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'meli_item_id' => (string) $item->meli_item_id,
            'title' => (string) $item->title,
            'price' => (float) $item->current_price,
            'catalog_listing' => (bool) $item->catalog_listing,
        ];
    }

    private function hasDivergence(Collection $values): bool
    {
        return $values->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): string => number_format((float) $value, 2, '.', ''))
            ->unique()->count() > 1;
    }
}
