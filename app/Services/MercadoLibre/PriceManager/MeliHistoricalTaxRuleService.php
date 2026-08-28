<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeliHistoricalTaxRuleService
{
    private const CURRENT_TTL_SECONDS = 21600;

    private const LAST_VALID_TTL_SECONDS = 604800;

    private const CONTRADICTORY_REASONS = [
        'contradictory_evidence',
        'ambiguous_evidence',
    ];

    public function __construct(
        private readonly MeliHistoricalTaxObservationService $observations,
        private readonly MeliHistoricalTaxRuleDetector $detector,
    ) {}

    /** @return array<string, mixed> */
    public function forAccount(MeliAccount $account): array
    {
        if (! $account->exists) {
            return $this->unavailable(0);
        }

        $cached = Cache::get($this->cacheKey($account));
        if (is_array($cached)) {
            if ($this->isValidRule($cached)) {
                $fresh = [
                    ...$cached,
                    'stale' => false,
                    'fallback' => null,
                ];
                if ($this->lastValid($account) === null) {
                    Cache::put($this->lastValidCacheKey($account), [
                        'stored_at' => now()->toISOString(),
                        'rule' => $fresh,
                    ], self::LAST_VALID_TTL_SECONDS);
                }

                return $fresh;
            }

            if (in_array($cached['failure_reason'] ?? null, self::CONTRADICTORY_REASONS, true)) {
                Cache::forget($this->lastValidCacheKey($account));

                return [
                    ...$cached,
                    'stale' => false,
                    'fallback' => null,
                ];
            }

            // Discard legacy/current insufficient results so a temporary empty sample
            // cannot hide newly available observations for the full current TTL.
            Cache::forget($this->cacheKey($account));
        }

        try {
            $detected = $this->detector->detect(
                (int) $account->id,
                $this->observations->forAccount($account),
            );
        } catch (Throwable $exception) {
            $fallback = $this->lastValid($account);
            Log::warning('No fue posible actualizar la regla fiscal histórica de Mercado Libre.', [
                'meli_account_id' => (int) $account->id,
                'exception_class' => $exception::class,
                'reason' => 'observation_error',
                'fallback' => $fallback !== null ? 'last_valid_historical_rule' : null,
            ]);

            return $fallback ?? $this->unavailable((int) $account->id);
        }

        if ($this->isValidRule($detected)) {
            $fresh = [
                ...$detected,
                'stale' => false,
                'fallback' => null,
            ];
            Cache::put($this->cacheKey($account), $fresh, self::CURRENT_TTL_SECONDS);
            Cache::put($this->lastValidCacheKey($account), [
                'stored_at' => now()->toISOString(),
                'rule' => $fresh,
            ], self::LAST_VALID_TTL_SECONDS);

            return $fresh;
        }

        if (in_array($detected['failure_reason'] ?? null, self::CONTRADICTORY_REASONS, true)) {
            $unreliable = [
                ...$detected,
                'stale' => false,
                'fallback' => null,
            ];
            Cache::forget($this->lastValidCacheKey($account));
            Cache::put($this->cacheKey($account), $unreliable, self::CURRENT_TTL_SECONDS);
            Log::warning('La evidencia fiscal histórica nueva es contradictoria o ambigua.', [
                'meli_account_id' => (int) $account->id,
                'reason' => $detected['failure_reason'],
                'sample_count' => (int) ($detected['sample_count'] ?? 0),
            ]);

            return $unreliable;
        }

        $fallback = $this->lastValid($account);
        if ($fallback !== null) {
            Log::info('Se conserva temporalmente la última regla fiscal histórica válida.', [
                'meli_account_id' => (int) $account->id,
                'reason' => $detected['failure_reason'] ?? 'insufficient_sample',
                'fallback' => 'last_valid_historical_rule',
            ]);

            return $fallback;
        }

        return [
            ...$detected,
            'stale' => false,
            'fallback' => null,
        ];
    }

    public function cacheKey(MeliAccount $account): string
    {
        return 'meli-price-manager:tax-rule:'.$account->getKey();
    }

    public function lastValidCacheKey(MeliAccount $account): string
    {
        return 'meli-price-manager:tax-rule:last-valid:'.$account->getKey();
    }

    /** @return array<string, mixed>|null */
    private function lastValid(MeliAccount $account): ?array
    {
        $cached = Cache::get($this->lastValidCacheKey($account));
        if (! is_array($cached)
            || ! is_array($cached['rule'] ?? null)
            || ! $this->isValidRule($cached['rule'])) {
            return null;
        }

        if (! filled($cached['stored_at'] ?? null)) {
            Cache::forget($this->lastValidCacheKey($account));

            return null;
        }

        try {
            $storedAt = CarbonImmutable::parse((string) ($cached['stored_at'] ?? ''));
        } catch (Throwable) {
            Cache::forget($this->lastValidCacheKey($account));

            return null;
        }

        if ($storedAt->addSeconds(self::LAST_VALID_TTL_SECONDS)->isPast()) {
            Cache::forget($this->lastValidCacheKey($account));

            return null;
        }

        return [
            ...$cached['rule'],
            'stale' => true,
            'fallback' => 'last_valid_historical_rule',
        ];
    }

    /** @param array<string, mixed> $rule */
    private function isValidRule(array $rule): bool
    {
        return ($rule['available'] ?? false) === true
            && ($rule['source'] ?? null) === 'historical_account_tax_rule'
            && ($rule['confidence'] ?? null) === 'high'
            && is_numeric($rule['vat_included_rate'] ?? null)
            && is_numeric($rule['vat_withholding_rate'] ?? null)
            && is_numeric($rule['income_tax_withholding_rate'] ?? null);
    }

    /** @return array<string, mixed> */
    private function unavailable(int $accountId): array
    {
        return [
            ...$this->detector->detect($accountId, []),
            'stale' => false,
            'fallback' => null,
        ];
    }
}
