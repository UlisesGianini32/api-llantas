<?php

namespace App\Services\Amazon;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InventoryAmazonClient
{
    public function __construct(
        private readonly AmazonTokenService $tokens,
    ) {}

    public function getSellerId(): string
    {
        return $this->tokens->getSellerId();
    }

    public function getMarketplaceId(): string
    {
        return $this->tokens->getMarketplaceId();
    }

    /**
     * Actualiza la cantidad de inventario para un SKU del vendedor (MFN/FBM) mediante Listings Items API.
     *
     * @return array<string, mixed>
     */
    public function updateListingQuantity(
        string $sellerSku,
        int $quantity,
        ?string $marketplaceId = null,
        ?string $sellerId = null,
    ): array {
        $sellerId = filled($sellerId) ? $sellerId : $this->getSellerId();
        $marketplaceId = filled($marketplaceId) ? $marketplaceId : $this->getMarketplaceId();

        $path = 'listings/2021-08-01/items/'.rawurlencode($sellerId).'/'.rawurlencode($sellerSku);

        $payload = [
            'productType' => 'PRODUCT',
            'patches' => [
                [
                    'op' => 'replace',
                    'path' => '/attributes/fulfillment_availability',
                    'value' => [
                        [
                            'fulfillment_channel_code' => 'DEFAULT',
                            'quantity' => $quantity,
                        ],
                    ],
                ],
            ],
        ];

        return $this->request('PATCH', $path, ['marketplaceIds' => $marketplaceId], $payload);
    }

    /**
     * Obtiene el detalle de una publicación de Amazon mediante Listings Items API.
     *
     * @return array<string, mixed>
     */
    public function getListing(
        string $sellerSku,
        ?string $marketplaceId = null,
        ?string $sellerId = null,
    ): array {
        $sellerId = filled($sellerId) ? $sellerId : $this->getSellerId();
        $marketplaceId = filled($marketplaceId) ? $marketplaceId : $this->getMarketplaceId();

        $path = 'listings/2021-08-01/items/'.rawurlencode($sellerId).'/'.rawurlencode($sellerSku);

        return $this->request('GET', $path, [
            'marketplaceIds' => $marketplaceId,
            'includedData' => 'summaries,attributes,offers',
        ]);
    }

    /**
     * Consulta órdenes recientes en Amazon SP-API.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function getOrders(array $params = []): array
    {
        $params['MarketplaceIds'] ??= $this->getMarketplaceId();

        return $this->request('GET', 'orders/v0/orders', $params);
    }

    /**
     * Consulta los items pertenecientes a una orden de Amazon.
     *
     * @return array<string, mixed>
     */
    public function getOrderItems(string $orderId): array
    {
        return $this->request('GET', "orders/v0/orders/{$orderId}/orderItems");
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], array $json = []): array
    {
        $endpoint = $this->tokens->getEndpoint();
        $token = $this->tokens->getAccessToken();
        $url = "{$endpoint}/".ltrim($path, '/');

        $pending = Http::withHeaders([
            'x-amz-access-token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'ApiLlantas/2.0 (Language=PHP)',
        ])->timeout(30);

        if (! empty($query)) {
            $pending->withQueryParameters($query);
        }

        $response = match (strtoupper($method)) {
            'GET' => $pending->get($url),
            'POST' => $pending->post($url, $json),
            'PUT' => $pending->put($url, $json),
            'PATCH' => $pending->patch($url, $json),
            'DELETE' => $pending->delete($url, $json),
            default => throw new RuntimeException("Método HTTP no soportado: {$method}"),
        };

        if (! $response->successful()) {
            Log::warning('Error en llamada a Amazon SP-API', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException("Amazon SP-API error [{$response->status()}]: {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
