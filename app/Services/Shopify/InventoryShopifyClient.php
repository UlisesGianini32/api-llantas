<?php

namespace App\Services\Shopify;

use App\Services\ShopifyTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InventoryShopifyClient
{
    public function __construct(
        private readonly ShopifyTokenService $tokenService
    ) {}

    public function getStoreDomain(): string
    {
        return $this->tokenService->getStoreDomain();
    }

    public function getApiVersion(): string
    {
        return $this->tokenService->getApiVersion();
    }

    /**
     * @return array<string, mixed>
     */
    public function getLocations(): array
    {
        return $this->request('GET', 'locations.json');
    }

    public function getPrimaryLocationId(): ?string
    {
        $locations = $this->getLocations()['locations'] ?? [];
        foreach ($locations as $loc) {
            if (! empty($loc['active'])) {
                return (string) $loc['id'];
            }
        }

        return ! empty($locations[0]['id']) ? (string) $locations[0]['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getProducts(array $params = []): array
    {
        return $this->request('GET', 'products.json', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProduct(string|int $productId): array
    {
        return $this->request('GET', "products/{$productId}.json");
    }

    /**
     * @return array<string, mixed>
     */
    public function getVariant(string|int $variantId): array
    {
        return $this->request('GET', "variants/{$variantId}.json");
    }

    /**
     * @return array<string, mixed>
     */
    public function getInventoryLevels(string $inventoryItemId): array
    {
        return $this->request('GET', 'inventory_levels.json', [
            'inventory_item_ids' => $inventoryItemId,
        ]);
    }

    /**
     * Sets available inventory level at a specific location.
     *
     * @return array<string, mixed>
     */
    public function setInventoryLevel(string $inventoryItemId, string $locationId, int $available): array
    {
        return $this->request('POST', 'inventory_levels/set.json', [
            'location_id' => $locationId,
            'inventory_item_id' => $inventoryItemId,
            'available' => $available,
        ]);
    }

    /**
     * Connects an inventory item to a location if not connected.
     *
     * @return array<string, mixed>
     */
    public function connectInventoryLevel(string $inventoryItemId, string $locationId): array
    {
        return $this->request('POST', 'inventory_levels/connect.json', [
            'location_id' => $locationId,
            'inventory_item_id' => $inventoryItemId,
        ]);
    }

    /**
     * Verifies the HMAC-SHA256 signature from a Shopify webhook request.
     */
    public function verifyHmac(string $data, ?string $hmacHeader): bool
    {
        if (blank($hmacHeader)) {
            return false;
        }

        try {
            $secret = $this->tokenService->getClientSecret();
        } catch (\Throwable) {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $data, $secret, true));

        return hash_equals($calculated, $hmacHeader);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $shop = $this->getStoreDomain();
        $version = $this->getApiVersion();
        $token = $this->tokenService->getAccessToken();

        $url = "https://{$shop}/admin/api/{$version}/{$endpoint}";

        $pending = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30);

        $response = $method === 'GET'
            ? $pending->get($url, $data)
            : $pending->post($url, $data);

        if (! $response->successful()) {
            Log::warning('Error en llamada a Shopify API', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("Shopify API error [{$response->status()}]: {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
