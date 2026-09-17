<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliCategory;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Support\Facades\Schema;

class MeliCategorySyncService
{
    public function __construct(private readonly MeliAccountApiClient $api) {}

    public function sync(MeliAccount $account): int
    {
        if (! Schema::hasTable('meli_categories')) {
            return 0;
        }

        $this->api->ensureFreshAccessToken($account);
        $ttlDays = max(1, (int) config('meli_price_manager.focused_catalog.category_cache_ttl_days', 30));
        $known = MeliCategory::query()
            ->whereNotNull('last_synced_at')
            ->where('last_synced_at', '>=', now()->subDays($ttlDays))
            ->pluck('category_id');
        $categoryIds = MeliPriceManagerItem::query()
            ->where('meli_account_id', $account->id)
            ->whereNotNull('category_id')
            ->where('category_id', '!=', '')
            ->distinct()
            ->pluck('category_id')
            ->diff($known)
            ->values();

        $synced = 0;
        foreach ($categoryIds as $categoryId) {
            $response = $this->api->getReadOnly($account, '/categories/'.rawurlencode((string) $categoryId));
            $payload = (array) $response->json();
            $path = array_values(array_filter((array) ($payload['path_from_root'] ?? []), 'is_array'));
            $rootId = trim((string) data_get($path, '0.id')) ?: null;
            $parentId = count($path) > 1 ? trim((string) data_get($path, (count($path) - 2).'.id')) : null;

            MeliCategory::query()->updateOrCreate(
                ['category_id' => (string) $categoryId],
                [
                    'name' => trim((string) ($payload['name'] ?? $categoryId)) ?: (string) $categoryId,
                    'parent_id' => $parentId ?: null,
                    'root_category_id' => $rootId,
                    'path_from_root' => $path,
                    'last_synced_at' => now(),
                ],
            );
            $synced++;
        }

        return $synced;
    }
}
