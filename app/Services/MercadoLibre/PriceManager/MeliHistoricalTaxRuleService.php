<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeliHistoricalTaxRuleService
{
    private const CACHE_SECONDS = 21600;

    public function __construct(
        private readonly MeliHistoricalTaxObservationService $observations,
        private readonly MeliHistoricalTaxRuleDetector $detector,
    ) {}

    /** @return array<string, mixed> */
    public function forAccount(MeliAccount $account): array
    {
        if (! $account->exists) {
            return $this->detector->detect(0, []);
        }

        return Cache::remember($this->cacheKey($account), self::CACHE_SECONDS, function () use ($account): array {
            try {
                return $this->detector->detect(
                    (int) $account->id,
                    $this->observations->forAccount($account),
                );
            } catch (Throwable $exception) {
                Log::warning('No fue posible resolver la regla fiscal histórica de Mercado Libre.', [
                    'meli_account_id' => (int) $account->id,
                    'exception_class' => $exception::class,
                ]);

                return $this->detector->detect((int) $account->id, []);
            }
        });
    }

    public function cacheKey(MeliAccount $account): string
    {
        return 'meli-price-manager:tax-rule:'.$account->getKey();
    }
}
