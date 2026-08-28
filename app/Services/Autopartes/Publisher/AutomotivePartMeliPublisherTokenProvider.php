<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\MeliAccount;
use App\Services\MeliOAuthService;
use Throwable;

class AutomotivePartMeliPublisherTokenProvider
{
    public function __construct(private MeliOAuthService $oauth) {}

    public function token(MeliAccount $account): string
    {
        if (filled($account->access_token) && ($account->expires_at === null || $account->expires_at->isAfter(now()->addMinute()))) {
            return (string) $account->access_token;
        }

        $clientId = trim((string) config('services.meli.client_id'));
        $clientSecret = trim((string) config('services.meli.client_secret'));
        if (! filled($account->refresh_token) || $clientId === '' || $clientSecret === '') {
            throw new AutomotivePartMeliPublisherException('La cuenta seleccionada no tiene un token vigente ni puede renovarlo.', 'missing_access_token');
        }

        try {
            $tokens = $this->oauth->refreshAccessToken($clientId, $clientSecret, (string) $account->refresh_token);
        } catch (Throwable) {
            throw new AutomotivePartMeliPublisherException(
                'No fue posible renovar el token de la cuenta seleccionada.',
                'oauth_token_refresh_failed',
                false,
                false,
                null,
                null,
                null,
                [],
            );
        }
        $account->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'expires_at' => now()->addSeconds(max(60, (int) ($tokens['expires_in'] ?? 21600))),
        ])->save();

        return (string) $tokens['access_token'];
    }
}
