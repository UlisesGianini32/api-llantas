<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MeliPriceSimulationTokenService
{
    public const TTL_MINUTES = 10;

    /**
     * @param  array<string, bool|float|int|string|null>  $simulation
     * @return array{token: string, expires_at: string}
     */
    public function issue(
        int $userId,
        MeliAccount $account,
        MeliPriceManagerItem $item,
        array $simulation,
    ): array {
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        Cache::put($this->key($token), [
            'user_id' => $userId,
            'account_id' => (int) $account->id,
            'item_id' => (int) $item->id,
            'meli_item_id' => (string) $item->meli_item_id,
            'current_price' => round((float) $simulation['current_price'], 2),
            'proposed_price' => round((float) $simulation['proposed_price'], 2),
            'simulation' => $simulation,
            'created_at' => now()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ], $expiresAt);

        return ['token' => $token, 'expires_at' => $expiresAt->toISOString()];
    }

    /** @return array<string, mixed> */
    public function resolve(string $token): array
    {
        $snapshot = Cache::get($this->key($token));
        if (! is_array($snapshot)) {
            throw new MeliPriceUpdateException(
                'La simulación expiró o ya fue utilizada. Vuelve a calcular los cargos antes de confirmar.',
                'simulation_expired',
            );
        }

        $expiresAt = isset($snapshot['expires_at']) ? CarbonImmutable::parse((string) $snapshot['expires_at']) : null;
        if ($expiresAt === null || $expiresAt->isPast()) {
            Cache::forget($this->key($token));

            throw new MeliPriceUpdateException(
                'La simulación expiró. Vuelve a calcular los cargos antes de confirmar.',
                'simulation_expired',
            );
        }

        return $snapshot;
    }

    public function consume(string $token): void
    {
        Cache::forget($this->key($token));
    }

    public function cacheKey(string $token): string
    {
        return $this->key($token);
    }

    private function key(string $token): string
    {
        return 'meli-price-manager:simulation:'.hash('sha256', $token);
    }
}
