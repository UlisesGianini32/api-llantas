<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyTokenService
{
    public function getStoreDomain(): string
    {
        $shop = trim((string) config('services.shopify.store_domain'));

        if ($shop === '') {
            throw new \RuntimeException('Falta SHOPIFY_STORE_DOMAIN en el .env');
        }

        return $shop;
    }

    public function getClientId(): string
    {
        $clientId = trim((string) config('services.shopify.client_id'));

        if ($clientId === '') {
            throw new \RuntimeException('Falta SHOPIFY_CLIENT_ID en el .env');
        }

        return $clientId;
    }

    public function getClientSecret(): string
    {
        $clientSecret = trim((string) config('services.shopify.client_secret'));

        if ($clientSecret === '') {
            throw new \RuntimeException('Falta SHOPIFY_CLIENT_SECRET en el .env');
        }

        return $clientSecret;
    }

    public function getApiVersion(): string
    {
        return trim((string) config('services.shopify.api_version', '2025-01'));
    }

    public function getAccessToken(): string
    {
        $shop = $this->getStoreDomain();
        $clientId = $this->getClientId();
        $clientSecret = $this->getClientSecret();

        $cacheKey = 'shopify_admin_access_token_' . md5($shop . '|' . $clientId);

        return Cache::remember($cacheKey, now()->addHours(23), function () use ($shop, $clientId, $clientSecret) {
            $response = Http::asForm()
                ->connectTimeout(10)
                ->timeout(30)
                ->retry(2, 700)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (!$response->successful()) {
                Log::error('Shopify client credentials token request failed', [
                    'shop' => $shop,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new \RuntimeException('No se pudo obtener el token de Shopify: ' . $response->body());
            }

            $data = $response->json();

            $token = $data['access_token'] ?? null;

            if (!$token) {
                Log::error('Shopify token response missing access_token', [
                    'shop' => $shop,
                    'response' => $data,
                ]);

                throw new \RuntimeException('Shopify no devolvió access_token');
            }

            return $token;
        });
    }

    public function forgetToken(): void
    {
        $shop = trim((string) config('services.shopify.store_domain'));
        $clientId = trim((string) config('services.shopify.client_id'));

        if ($shop !== '' && $clientId !== '') {
            $cacheKey = 'shopify_admin_access_token_' . md5($shop . '|' . $clientId);
            Cache::forget($cacheKey);
        }
    }

    public function getBaseGraphqlUrl(): string
    {
        $shop = $this->getStoreDomain();
        $version = $this->getApiVersion();

        return "https://{$shop}/admin/api/{$version}/graphql.json";
    }

    public function getBaseRestUrl(): string
    {
        $shop = $this->getStoreDomain();
        $version = $this->getApiVersion();

        return "https://{$shop}/admin/api/{$version}";
    }
}