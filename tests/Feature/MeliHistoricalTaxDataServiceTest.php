<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\MeliAccountApiClient;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxDataService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class MeliHistoricalTaxDataServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_service_uses_only_the_official_get_endpoint_and_caches_the_normalized_result(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/billing/integration/group/ML/order/details*' => Http::response($this->fixture(101, 'MLM-ONE')),
        ]);
        $account = $this->account(10, 'token-one');

        $first = app(MeliHistoricalTaxDataService::class)->forOrders($account, [101, '102', 101]);
        $second = app(MeliHistoricalTaxDataService::class)->forOrders($account, ['101', 102]);

        $this->assertSame($first, $second);
        $this->assertTrue($first['available']);
        $this->assertSame('MLM-ONE', data_get($first, 'orders.0.item_ids.0'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/billing/integration/group/ML/order/details')
            && $request['order_ids'] === '101,102');
    }

    public function test_empty_billing_response_is_unavailable_and_empty_input_does_not_call_meli(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/billing/integration/group/ML/order/details*' => Http::response(['results' => []]),
        ]);
        $service = app(MeliHistoricalTaxDataService::class);
        $account = $this->account(11, 'token-two');

        $emptyInput = $service->forOrders($account, []);
        Http::assertNothingSent();
        $emptyBilling = $service->forOrders($account, [201]);

        $this->assertFalse($emptyInput['available']);
        $this->assertFalse($emptyBilling['available']);
        $this->assertNull($emptyBilling['source']);
        Http::assertSentCount(1);
    }

    public function test_cache_is_scoped_by_account_and_orders_do_not_contaminate_each_other(): void
    {
        Http::fakeSequence()
            ->push($this->fixture(301, 'MLM-ACCOUNT-A'))
            ->push($this->fixture(301, 'MLM-ACCOUNT-B'));

        $forFirstAccount = app(MeliHistoricalTaxDataService::class)->forOrders($this->account(21, 'token-a'), [301]);
        $forSecondAccount = app(MeliHistoricalTaxDataService::class)->forOrders($this->account(22, 'token-b'), [301]);

        $this->assertSame('MLM-ACCOUNT-A', data_get($forFirstAccount, 'orders.0.item_ids.0'));
        $this->assertSame('MLM-ACCOUNT-B', data_get($forSecondAccount, 'orders.0.item_ids.0'));
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_invalid_or_excessive_order_ids_are_rejected_without_http(): void
    {
        $service = app(MeliHistoricalTaxDataService::class);
        $account = $this->account(31, 'token-three');

        foreach ([[0], ['abc'], range(1, 61)] as $orderIds) {
            try {
                $service->forOrders($account, $orderIds);
                $this->fail('La consulta inválida debió rechazarse.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_unauthorized_read_never_attempts_an_oauth_post(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/billing/integration/group/ML/order/details*' => Http::response([
                'message' => 'invalid_token',
            ], 401),
        ]);

        try {
            app(MeliHistoricalTaxDataService::class)->forOrders($this->account(41, 'expired-token'), [401]);
            $this->fail('La lectura sin autorización debió fallar.');
        } catch (MeliApiRequestException $exception) {
            $this->assertSame(401, $exception->httpStatus());
        }

        Http::assertSentCount(1);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_already_expired_token_never_triggers_a_preemptive_oauth_post(): void
    {
        config()->set('services.meli.client_id', 'test-client-id');
        config()->set('services.meli.client_secret', 'test-client-secret');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/oauth/token')) {
                return Http::response([
                    'access_token' => 'unexpected-refreshed-token',
                    'expires_in' => 21600,
                ]);
            }

            return Http::response(['message' => 'invalid_token'], 401);
        });
        $account = $this->account(42, 'expired-before-call');
        $account->forceFill([
            'refresh_token' => 'refresh-token-that-must-not-be-used',
            'expires_at' => now()->subHour(),
        ]);

        try {
            app(MeliAccountApiClient::class)->getReadOnly(
                $account,
                '/billing/integration/group/ML/order/details',
                ['order_ids' => '402'],
            );
            $this->fail('La lectura con token vencido debió fallar sin renovarlo.');
        } catch (MeliApiRequestException $exception) {
            $this->assertSame(401, $exception->httpStatus());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/billing/integration/group/ML/order/details'));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertStringNotContainsString('/oauth/token', $request->url());
        }
    }

    private function account(int $id, string $token): MeliAccount
    {
        $account = new MeliAccount([
            'meli_user_id' => (string) $id,
            'access_token' => $token,
            'expires_at' => now()->addHour(),
        ]);
        $account->id = $id;
        $account->exists = true;

        return $account;
    }

    /** @return array<string, mixed> */
    private function fixture(int $orderId, string $itemId): array
    {
        return ['results' => [[
            'order_id' => $orderId,
            'payment_info' => [[
                'payment_id' => $orderId + 1,
                'tax_details' => [[
                    'mov_detail' => 'tax_withholding',
                    'mov_financial_entity' => 'retencion_iva',
                    'original_amount' => 63.27,
                    'refunded_amount' => 0,
                    'tax_status' => 'applied',
                ]],
            ]],
            'details' => [['items_info' => ['item_id' => $itemId]]],
        ]]];
    }
}
