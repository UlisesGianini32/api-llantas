<?php

namespace Tests\Unit;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxObservationService;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxRuleDetector;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxRuleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class MeliHistoricalTaxRuleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_valid_rule_is_stored_as_current_and_last_valid_and_marked_fresh(): void
    {
        $account = $this->account(1);
        $service = $this->service([$this->observations(1)]);

        $result = $service->forAccount($account);

        $this->assertTrue($result['available']);
        $this->assertFalse($result['stale']);
        $this->assertNull($result['fallback']);
        $this->assertSame($result, Cache::get($service->cacheKey($account)));
        $lastValid = Cache::get($service->lastValidCacheKey($account));
        $this->assertNotEmpty($lastValid['stored_at']);
        $this->assertSame($result, $lastValid['rule']);

        $serialized = strtolower(json_encode([$result, $lastValid], JSON_THROW_ON_ERROR));
        foreach (['buyer', 'email', 'phone', 'address', 'rfc', 'document', 'access_token', 'refresh_token'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $serialized);
        }
    }

    public function test_temporary_exception_does_not_overwrite_last_valid_and_uses_it_as_stale_fallback(): void
    {
        Log::spy();
        $account = $this->account(2);
        $service = $this->service([
            $this->observations(2),
            new RuntimeException('temporary billing failure'),
        ]);
        $service->forAccount($account);
        $lastValid = Cache::get($service->lastValidCacheKey($account));
        Cache::forget($service->cacheKey($account));

        $result = $service->forAccount($account);

        $this->assertTrue($result['available']);
        $this->assertTrue($result['stale']);
        $this->assertSame('last_valid_historical_rule', $result['fallback']);
        $this->assertSame($lastValid, Cache::get($service->lastValidCacheKey($account)));
        $this->assertFalse(Cache::has($service->cacheKey($account)));
        Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool =>
            $context === [
                'meli_account_id' => 2,
                'exception_class' => RuntimeException::class,
                'reason' => 'observation_error',
                'fallback' => 'last_valid_historical_rule',
            ]);
    }

    public function test_empty_and_temporarily_insufficient_samples_use_last_valid_without_caching_insufficient(): void
    {
        $account = $this->account(3);
        $valid = $this->observations(3);
        $service = $this->service([$valid, [], array_slice($valid, 0, 4)]);
        $service->forAccount($account);

        Cache::forget($service->cacheKey($account));
        $emptyFallback = $service->forAccount($account);
        $this->assertTrue($emptyFallback['stale']);
        $this->assertSame('last_valid_historical_rule', $emptyFallback['fallback']);
        $this->assertFalse(Cache::has($service->cacheKey($account)));

        $insufficientFallback = $service->forAccount($account);
        $this->assertTrue($insufficientFallback['stale']);
        $this->assertSame('last_valid_historical_rule', $insufficientFallback['fallback']);
        $this->assertFalse(Cache::has($service->cacheKey($account)));
    }

    public function test_exception_without_last_valid_is_unavailable(): void
    {
        $result = $this->service([new RuntimeException('temporary')])->forAccount($this->account(4));

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient', $result['confidence']);
        $this->assertFalse($result['stale']);
        $this->assertNull($result['fallback']);
    }

    public function test_expired_last_valid_is_rejected(): void
    {
        $account = $this->account(5);
        $service = $this->service([
            $this->observations(5),
            new RuntimeException('temporary'),
        ]);
        $service->forAccount($account);
        $lastValid = Cache::get($service->lastValidCacheKey($account));
        Cache::put($service->lastValidCacheKey($account), [
            ...$lastValid,
            'stored_at' => now()->subDays(8)->toISOString(),
        ], now()->addDays(7));
        Cache::forget($service->cacheKey($account));

        $result = $service->forAccount($account);

        $this->assertFalse($result['available']);
        $this->assertFalse($result['stale']);
        $this->assertFalse(Cache::has($service->lastValidCacheKey($account)));
    }

    public function test_sufficient_contradictory_evidence_never_uses_or_preserves_last_valid(): void
    {
        $account = $this->account(6);
        $valid = $this->observations(6);
        $contradictory = $valid;
        $contradictory[0]['vat_amount'] = 75.00;
        $service = $this->service([$valid, $contradictory]);
        $service->forAccount($account);
        Cache::forget($service->cacheKey($account));

        $result = $service->forAccount($account);

        $this->assertFalse($result['available']);
        $this->assertSame('contradictory_evidence', $result['failure_reason']);
        $this->assertFalse($result['stale']);
        $this->assertNull($result['fallback']);
        $this->assertFalse(Cache::has($service->lastValidCacheKey($account)));
        $this->assertSame($result, Cache::get($service->cacheKey($account)));
    }

    public function test_accounts_use_independent_current_and_last_valid_cache_keys(): void
    {
        $first = $this->account(7);
        $second = $this->account(8);
        $observations = Mockery::mock(MeliHistoricalTaxObservationService::class);
        $observations->shouldReceive('forAccount')->twice()->andReturnUsing(
            fn (MeliAccount $account): array => $this->observations((int) $account->id),
        );
        $service = new MeliHistoricalTaxRuleService($observations, new MeliHistoricalTaxRuleDetector);

        $service->forAccount($first);
        $service->forAccount($second);

        $this->assertNotSame($service->cacheKey($first), $service->cacheKey($second));
        $this->assertNotSame($service->lastValidCacheKey($first), $service->lastValidCacheKey($second));
        $this->assertSame(7, Cache::get($service->cacheKey($first))['sample_count']);
        $this->assertSame(7, Cache::get($service->cacheKey($second))['sample_count']);
    }

    public function test_legacy_insufficient_current_cache_is_discarded_and_recalculated(): void
    {
        $account = $this->account(9);
        $service = $this->service([$this->observations(9)]);
        Cache::put($service->cacheKey($account), [
            'available' => false,
            'confidence' => 'insufficient',
            'sample_count' => 0,
        ], now()->addHours(6));

        $result = $service->forAccount($account);

        $this->assertTrue($result['available']);
        $this->assertSame(7, $result['sample_count']);
        $this->assertFalse($result['stale']);
    }

    /** @param list<array<string, mixed>|Throwable> $responses */
    private function service(array $responses): MeliHistoricalTaxRuleService
    {
        $observations = Mockery::mock(MeliHistoricalTaxObservationService::class);
        $observations->shouldReceive('forAccount')->times(count($responses))->andReturnUsing(
            static function () use (&$responses): array {
                $response = array_shift($responses);
                if ($response instanceof Throwable) {
                    throw $response;
                }

                return $response;
            },
        );

        return new MeliHistoricalTaxRuleService($observations, new MeliHistoricalTaxRuleDetector);
    }

    private function account(int $id): MeliAccount
    {
        $account = new MeliAccount;
        $account->setRawAttributes(['id' => $id]);
        $account->exists = true;

        return $account;
    }

    /** @return list<array<string, mixed>> */
    private function observations(int $accountId): array
    {
        $amounts = [
            [1001.28, 69.05, 21.58],
            [199.00, 13.72, 4.29],
            [356.00, 24.55, 7.67],
            [298.00, 20.55, 6.42],
            [229.00, 15.79, 4.94],
            [660.00, 45.52, 14.22],
            [735.00, 50.69, 15.84],
        ];

        return array_map(static fn (array $amount, int $index): array => [
            'meli_account_id' => $accountId,
            'order_id' => (string) (($accountId * 1000) + $index),
            'item_id' => 'MLM-'.$accountId.'-'.$index,
            'gross_sale_amount' => $amount[0],
            'vat_amount' => $amount[1],
            'income_tax_amount' => $amount[2],
            'payment_status' => 'approved',
            'refunded' => false,
            'attribution_scope' => 'single_item',
            'observed_at' => sprintf('2026-08-%02dT12:00:00-06:00', 10 + $index),
        ], $amounts, array_keys($amounts));
    }
}
