<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductMelisService
{
    protected string $clientId;
    protected string $clientSecret;

    /**
     * Si lo pones en true, volverá a pedir description a ML aunque ya exista.
     * Déjalo en false para no hacer la sincronización tan lenta.
     */
    protected bool $forceRefreshDescription = false;

    public function __construct(
        protected ShopifyCategoryResolverService $shopifyCategoryResolver,
        protected MeliOAuthService $meliOAuth,
    ) {
        $this->clientId = (string) config('services.meli.client_id', '');
        $this->clientSecret = (string) config('services.meli.client_secret', '');
    }

    public function sync(User $user): array
    {
        if (!$user->meli_id || !$user->access_token) {
            throw new \Exception('El usuario no tiene cuenta de MercadoLibre vinculada');
        }

        $this->ensureValidToken($user);

        $inserted = 0;
        $updated = 0;
        $scrollId = null;

        do {
            $params = [
                'search_type' => 'scan',
                'limit' => 200,
            ];

            if ($scrollId) {
                $params['scroll_id'] = $scrollId;
            }

            $response = Http::withToken($user->access_token)
                ->timeout(40)
                ->acceptJson()
                ->get("https://api.mercadolibre.com/users/{$user->meli_id}/items/search", $params);

            if (!$response->successful()) {
                Log::error('Error obteniendo página con scan', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 1000),
                    'scroll_id' => $scrollId ?? 'inicial',
                ]);
                break;
            }

            $data = $response->json();
            $items = $data['results'] ?? [];
            $scrollId = $data['scroll_id'] ?? null;

            Log::info('Página obtenida con scan', [
                'items' => count($items),
                'scroll_id' => $scrollId ?? 'final',
                'procesados' => $inserted + $updated,
            ]);

            if (empty($items)) {
                break;
            }

            foreach (array_chunk($items, 20) as $chunk) {
                $this->processChunk($user, $chunk, $inserted, $updated);
            }
        } while ($scrollId);

        Log::info('Sincronización finalizada', [
            'nuevos' => $inserted,
            'actualizados' => $updated,
            'total' => $inserted + $updated,
        ]);

        return [
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }

    protected function processChunk(User $user, array $chunk, int &$inserted, int &$updated): void
    {
        $ids = array_values(array_unique(array_filter($chunk)));
        if (empty($ids)) {
            return;
        }

        $idsString = implode(',', $ids);

        $response = Http::withToken($user->access_token)
            ->timeout(40)
            ->acceptJson()
            ->get('https://api.mercadolibre.com/items', ['ids' => $idsString]);

        if (!$response->successful()) {
            Log::error('Error obteniendo detalles de chunk', [
                'ids' => $idsString,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 1000),
            ]);
            return;
        }

        $details = $response->json();

        $existingProducts = Product::whereIn('ml', $ids)->get()->keyBy('ml');

        foreach ($details as $detail) {
            if (!isset($detail['body']) || !is_array($detail['body'])) {
                Log::warning('Respuesta inválida en chunk', ['detail' => $detail]);
                continue;
            }

            $item = $detail['body'];
            $mlId = (string) ($item['id'] ?? '');

            if ($mlId === '') {
                continue;
            }

            $existing = $existingProducts->get($mlId);

            $mapped = $this->mapItem($item, $user, $existing);
            $mapped['category_name'] = $this->fetchCategoryName($mapped['category_id'] ?? null);

            $mapped = array_merge(
                $mapped,
                $this->resolveShopifyCategoryData($mapped, $existing)
            );

            $product = Product::updateOrCreate(
                ['ml' => $mapped['ml']],
                $mapped
            );

            if ($product->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        }
    }

    protected function ensureValidToken(User $user): void
    {
        if (!$user->expires_at) {
            return;
        }

        $expiresAt = $user->expires_at instanceof Carbon
            ? $user->expires_at->copy()
            : Carbon::parse($user->expires_at);

        if (Carbon::now()->lt($expiresAt->subMinutes(10))) {
            return;
        }

        if ($this->clientId === '' || $this->clientSecret === '' || !$user->refresh_token) {
            throw new \Exception('No se pudo renovar el token: faltan credenciales MELI o refresh_token');
        }

        try {
            $data = $this->meliOAuth->refreshAccessToken(
                $this->clientId,
                $this->clientSecret,
                (string) $user->refresh_token,
            );
        } catch (\Throwable $e) {
            Log::error('Error renovando token', ['message' => $e->getMessage()]);
            throw new \Exception('No se pudo renovar el token: '.$e->getMessage(), 0, $e);
        }

        $user->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $user->refresh_token,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 0)),
        ]);
    }

    protected function mapItem(array $item, User $user, ?Product $existing = null): array
    {
        $attributes = $item['attributes'] ?? [];

        $sku = $this->extractAttributeValue($attributes, 'SELLER_SKU');
        $brand = $this->extractAttributeValue($attributes, 'BRAND');

        $pictures = $this->normalizePictures($item['pictures'] ?? []);

        $description = $existing?->description;
        if ($this->forceRefreshDescription || blank($description)) {
            $description = $this->fetchDescription($user, (string) ($item['id'] ?? ''));
        }

        return [
            'name' => $item['title'] ?? 'Sin título',
            'ml' => (string) ($item['id'] ?? ''),
            'sku' => $sku,
            'official_store_id' => isset($item['official_store_id']) ? (string) $item['official_store_id'] : null,
            'category_id' => $item['category_id'] ?? null,
            'price' => (float) ($item['price'] ?? 0),
            'stock' => (int) ($item['available_quantity'] ?? 0),
            'status_ml' => $item['status'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'brand' => $brand,
            'pictures' => $pictures,
            'description' => $description,
            'shopify_category_id' => $existing?->shopify_category_id,
            'shopify_category_name' => $existing?->shopify_category_name,
            'shopify_category_source' => $existing?->shopify_category_source,
        ];
    }

    protected function resolveShopifyCategoryData(array $mapped, ?Product $existing = null): array
    {
        $shop = trim((string) env('SHOPIFY_STORE_DOMAIN', ''));
        $clientId = trim((string) env('SHOPIFY_CLIENT_ID', ''));
        $clientSecret = trim((string) env('SHOPIFY_CLIENT_SECRET', ''));

        if ($shop === '' || $clientId === '' || $clientSecret === '') {
            return [
                'shopify_category_id' => $existing?->shopify_category_id,
                'shopify_category_name' => $existing?->shopify_category_name,
                'shopify_category_source' => $existing?->shopify_category_source,
            ];
        }

        $temp = new Product();
        $temp->fill($mapped);

        try {
            $resolved = $this->shopifyCategoryResolver->resolveForProduct($temp);

            if (!$resolved) {
                return [
                    'shopify_category_id' => $existing?->shopify_category_id,
                    'shopify_category_name' => $existing?->shopify_category_name,
                    'shopify_category_source' => $existing?->shopify_category_source,
                ];
            }

            return [
                'shopify_category_id' => $resolved['id'] ?? null,
                'shopify_category_name' => $resolved['name'] ?? null,
                'shopify_category_source' => $resolved['source'] ?? 'taxonomy_api',
            ];
        } catch (\Throwable $e) {
            Log::warning('No se pudo resolver categoría Shopify', [
                'name' => $mapped['name'] ?? null,
                'ml' => $mapped['ml'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'shopify_category_id' => $existing?->shopify_category_id,
                'shopify_category_name' => $existing?->shopify_category_name,
                'shopify_category_source' => $existing?->shopify_category_source,
            ];
        }
    }

    protected function fetchCategoryName(?string $categoryId): ?string
    {
        if (blank($categoryId)) {
            return null;
        }

        return Cache::remember("meli_category_name_{$categoryId}", now()->addDays(7), function () use ($categoryId) {
            $catResponse = Http::timeout(20)
                ->acceptJson()
                ->get("https://api.mercadolibre.com/categories/{$categoryId}");

            if (!$catResponse->successful()) {
                Log::warning('Error obteniendo nombre de categoría', [
                    'category_id' => $categoryId,
                    'status' => $catResponse->status(),
                ]);

                return null;
            }

            $catData = $catResponse->json();
            $path = $catData['path_from_root'] ?? [];

            if (count($path) >= 2) {
                $subCategory = $path[count($path) - 2]['name'] ?? '';
                $last = $path[count($path) - 1]['name'] ?? '';
                return trim($subCategory . ' - ' . $last);
            }

            if (!empty($path)) {
                return $path[0]['name'] ?? ($catData['name'] ?? null);
            }

            return $catData['name'] ?? null;
        });
    }

    protected function fetchDescription(User $user, string $itemId): ?string
    {
        if ($itemId === '') {
            return null;
        }

        try {
            $response = Http::withToken($user->access_token)
                ->timeout(20)
                ->acceptJson()
                ->get("https://api.mercadolibre.com/items/{$itemId}/description");

            if (!$response->successful()) {
                Log::warning('No se pudo obtener description de ML', [
                    'item_id' => $itemId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            $plainText = trim((string) ($data['plain_text'] ?? ''));
            if ($plainText !== '') {
                return $plainText;
            }

            $text = trim((string) ($data['text'] ?? ''));
            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('Excepción obteniendo description de ML', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function extractAttributeValue(array $attributes, string $attributeId): ?string
    {
        foreach ($attributes as $attr) {
            if (($attr['id'] ?? null) !== $attributeId) {
                continue;
            }

            $value = $attr['value_name']
                ?? $attr['value_struct']['name']
                ?? $attr['value_struct']['number']
                ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    protected function normalizePictures(array $pictures): array
    {
        $urls = [];

        foreach ($pictures as $picture) {
            $url = $picture['secure_url']
                ?? $picture['url']
                ?? null;

            if ($url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}