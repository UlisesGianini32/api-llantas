<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ShopifyGraphqlService
{
    public function __construct(
        protected ShopifyTokenService $tokenService
    ) {
    }

    public function query(string $query, array $variables = []): array
    {
        $shop = trim((string) env('SHOPIFY_STORE_DOMAIN'));
        $token = $this->tokenService->getAccessToken();

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(45)->post("https://{$shop}/admin/api/2026-01/graphql.json", [
            'query' => $query,
            'variables' => $variables,
        ]);

        if (!$response->successful()) {
            $this->tokenService->forgetToken();

            throw new \RuntimeException('Error Shopify GraphQL: ' . $response->body());
        }

        $json = $response->json();

        if (!empty($json['errors'])) {
            throw new \RuntimeException('GraphQL errors: ' . json_encode($json['errors'], JSON_UNESCAPED_UNICODE));
        }

        return $json['data'] ?? [];
    }
}