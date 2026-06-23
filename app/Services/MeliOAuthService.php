<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OAuth 2.0 de Mercado Libre: Authorization Code (server-side) y refresh token.
 *
 * @see https://developers.mercadolibre.com.mx/es_ar/autenticacion-y-autorizacion
 */
class MeliOAuthService
{
    public function tokenEndpoint(): string
    {
        return rtrim((string) config('services.meli.oauth_token_url', 'https://api.mercadolibre.com/oauth/token'), '/');
    }

    /**
     * POST /oauth/token como en la documentación (body x-www-form-urlencoded, Accept: application/json).
     *
     * @param  array<string, string>  $formFields
     * @return array<string, mixed>
     */
    public function requestToken(array $formFields): array
    {
        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->asForm()
            ->post($this->tokenEndpoint(), $formFields);

        $data = $response->json();
        if (!is_array($data)) {
            $data = [];
        }

        if (!$response->successful() || empty($data['access_token'])) {
            $error = (string) ($data['error'] ?? 'http_'.$response->status());
            $desc = (string) ($data['error_description'] ?? $response->body());
            throw new RuntimeException("Mercado Libre oauth/token: {$error} — {$desc}");
        }

        return $data;
    }

    /**
     * Paso 2: intercambiar `code` por access_token (y refresh_token si scope incluye offline_access).
     *
     * @return array<string, mixed>
     */
    public function exchangeAuthorizationCode(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        ?string $codeVerifier,
    ): array {
        $body = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null && $codeVerifier !== '') {
            $body['code_verifier'] = $codeVerifier;
        }

        return $this->requestToken($body);
    }

    /**
     * Renovar access_token con el último refresh_token válido.
     *
     * @return array<string, mixed>
     */
    public function refreshAccessToken(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
    ): array {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);
    }
}
