<?php

namespace App\Services\Autopartes\Meli;

use App\Models\MeliAccount;
use App\Models\User;

class AutomotivePartMeliTokenProvider
{
    public function token(): string
    {
        $account = MeliAccount::query()
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinute()))
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (filled($account?->access_token)) {
            return (string) $account->access_token;
        }

        $legacyToken = User::query()
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->value('access_token');

        if (filled($legacyToken)) {
            return (string) $legacyToken;
        }

        throw new AutomotivePartMeliException(
            'No existe una cuenta de Mercado Libre con un access token vigente.',
            'missing_access_token',
        );
    }
}
