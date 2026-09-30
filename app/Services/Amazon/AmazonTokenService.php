<?php

namespace App\Services\Amazon;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AmazonTokenService
{
    public function getClientId(): string
    {
        $clientId = trim((string) config('services.amazon.lwa_client_id'));
        if ($clientId === '') {
            throw new RuntimeException('Falta AMAZON_SP_API_CLIENT_ID en el entorno.');
        }

        return $clientId;
    }

    public function getClientSecret(): string
    {
        $clientSecret = trim((string) config('services.amazon.lwa_client_secret'));
        if ($clientSecret === '') {
            throw new RuntimeException('Falta AMAZON_SP_API_CLIENT_SECRET en el entorno.');
        }

        return $clientSecret;
    }

    public function getRefreshToken(): string
    {
        $token = trim((string) config('services.amazon.lwa_refresh_token'));
        if ($token === '') {
            throw new RuntimeException('Falta AMAZON_SP_API_REFRESH_TOKEN en el entorno.');
        }

        return $token;
    }

    public function getSellerId(): string
    {
        return trim((string) config('services.amazon.seller_id', ''));
    }

    public function getMarketplaceId(): string
    {
        return trim((string) config('services.amazon.marketplace_id', 'A1AM78C64UM0Y8'));
    }

    public function getEndpoint(): string
    {
        return rtrim((string) config('services.amazon.endpoint', 'https://sellingpartnerapi-na.amazon.com'), '/');
    }

    public function getAccessToken(): string
    {
        $clientId = $this->getClientId();
        $clientSecret = $this->getClientSecret();
        $refreshToken = $this->getRefreshToken();

        $cacheKey = 'amazon_sp_api_lwa_token_'.md5($clientId);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientId, $clientSecret, $refreshToken) {
            $response = Http::asForm()
                ->timeout(30)
                ->retry(2, 500)
                ->post('https://api.amazon.com/auth/o2/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ]);

            if (! $response->successful()) {
                Log::error('Error solicitando LWA token de Amazon SP-API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw new RuntimeException("No se pudo obtener el token de Amazon SP-API: {$response->body()}");
            }

            $token = $response->json('access_token');
            if (! $token) {
                throw new RuntimeException('Respuesta de Amazon SP-API no incluyó access_token.');
            }

            return $token;
        });
    }

    public function forgetToken(): void
    {
        try {
            $clientId = $this->getClientId();
            Cache::forget('amazon_sp_api_lwa_token_'.md5($clientId));
        } catch (\Throwable) {
            // Ignorar si no está configurado
        }
    }
}
