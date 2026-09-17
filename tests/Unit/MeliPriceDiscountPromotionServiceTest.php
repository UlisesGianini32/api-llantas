<?php

namespace Tests\Unit;

use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliPriceDiscountPromotionService;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeliPriceDiscountPromotionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    #[DataProvider('localPromotionWindows')]
    public function test_create_posts_local_calendar_dates(
        string $startsAt,
        string $endsAt,
        string $now,
        string $expectedStart,
        string $expectedFinish,
    ): void {
        $this->fakeRemotePrices($this->restoredPrices(), ['status' => 'started']);

        $rule = new MeliBeautyScheduledDiscount([
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => 'America/Mexico_City',
        ]);

        CarbonImmutable::withTestNow(CarbonImmutable::parse($now, $rule->timezone), function () use ($rule): void {
            app(MeliPriceDiscountPromotionService::class)->create(
                new MeliAccount(['access_token' => 'test-token']),
                new MeliPriceManagerItem(['meli_item_id' => 'MLM4733828880']),
                $rule,
                299.00,
                269.10,
            );
        });

        Http::assertSent(function (Request $request) use ($expectedStart, $expectedFinish): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $this->assertSame('https://api.mercadolibre.com/seller-promotions/items/MLM4733828880?app_version=v2', $request->url());
            $this->assertSame([
                'deal_price' => 269.10,
                'start_date' => $expectedStart,
                'finish_date' => $expectedFinish,
                'promotion_type' => 'PRICE_DISCOUNT',
            ], $request->data());

            foreach (['start_date', 'finish_date'] as $field) {
                $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T00:00:00\z/', $request[$field]);
                $this->assertStringNotContainsString('Z', $request[$field]);
                $this->assertDoesNotMatchRegularExpression('/[+-]\d{2}:?\d{2}\z/', $request[$field]);
                $this->assertStringNotContainsString('.', $request[$field]);
            }

            return true;
        });
        Http::assertSentCount(7);
    }

    #[DataProvider('confirmedStatuses')]
    public function test_create_confirms_winning_prices_after_post(string $status): void
    {
        $this->fakeRemotePrices($this->restoredPrices(), ['status' => $status]);

        $confirmed = $this->createPromotion();

        $this->assertSame($status, $confirmed['promotion_status']);
        $this->assertSame(299.0, $confirmed['standard_base']);
        $this->assertSame(269.1, $confirmed['promotion_price']);
        $this->assertSame(269.1, $confirmed['sale_amount']);
        $this->assertSame(299.0, $confirmed['sale_regular_amount']);
        $this->assertSame('custom', $confirmed['sale_metadata']['promotion_type']);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Http::assertSentCount(7);
    }

    #[DataProvider('confirmedStatuses')]
    public function test_create_recovers_existing_winning_promotion_without_post(string $status): void
    {
        $this->fakeRemotePrices(['status' => $status]);

        $confirmed = $this->createPromotion();

        $this->assertSame($status, $confirmed['promotion_status']);
        $this->assertSame(299.0, $confirmed['standard_base']);
        $this->assertSame(269.1, $confirmed['promotion_price']);
        $this->assertSame(269.1, $confirmed['sale_amount']);
        $this->assertSame(299.0, $confirmed['sale_regular_amount']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::assertSentCount(3);
    }

    #[DataProvider('unconfirmedPrices')]
    public function test_create_rejects_incomplete_or_mismatched_price_evidence(array $prices): void
    {
        $this->fakeRemotePrices($prices);

        try {
            $this->createPromotion();
            $this->fail('Incomplete price evidence must not confirm a promotion.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame('promotion_not_confirmed', $exception->errorCode());
            $this->assertSame(502, $exception->httpStatus());
        }

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
        Http::assertSentCount(7);
    }

    #[DataProvider('confirmedStatuses')]
    public function test_remove_skips_delete_when_winning_prices_are_already_restored(string $status): void
    {
        $this->fakeRemotePrices([...$this->restoredPrices(), 'status' => $status]);

        $confirmed = app(MeliPriceDiscountPromotionService::class)->remove(
            new MeliAccount(['access_token' => 'test-token']),
            new MeliPriceManagerItem(['meli_item_id' => 'MLM4733828880']),
        );

        $this->assertSame($status, $confirmed['promotion_status']);
        $this->assertNull($confirmed['promotion_price']);
        $this->assertSame($confirmed['standard_base'], $confirmed['sale_amount']);
        $this->assertNull($confirmed['sale_regular_amount']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Http::assertSentCount(3);
        Sleep::assertNeverSlept();
    }

    #[DataProvider('restorePropagation')]
    public function test_remove_deletes_once_and_confirms_after_bounded_propagation(string $status, int $staleReads): void
    {
        $this->fakeRemotePrices([], afterDelete: [
            ...array_fill(0, $staleReads, []),
            [...$this->restoredPrices(), 'status' => $status],
        ]);

        $confirmed = $this->removePromotion();

        $this->assertSame($status, $confirmed['promotion_status']);
        $this->assertSame(299.0, $confirmed['sale_amount']);
        $this->assertNull($confirmed['sale_regular_amount']);
        $this->assertNull($confirmed['promotion_price']);
        $deletes = Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE');
        $this->assertCount(1, $deletes);
        $this->assertSame('https://api.mercadolibre.com/seller-promotions/items/MLM4733828880?promotion_type=PRICE_DISCOUNT&app_version=v2', $deletes->first()[0]->url());
        Http::assertSentCount(7 + 3 * $staleReads);
        if ($staleReads === 0) {
            Sleep::assertNeverSlept();
        } else {
            Sleep::assertSequence(array_fill(0, $staleReads, Sleep::for(500)->milliseconds()));
        }
    }

    public function test_remove_reports_final_prices_after_exhausting_reads_without_repeating_delete(): void
    {
        Log::spy();
        $this->fakeRemotePrices([]);

        try {
            $this->removePromotion();
            $this->fail('A winning promotion must not be marked restored.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame('promotion_restore_not_confirmed', $exception->errorCode());
        }

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'));
        Http::assertSentCount(19);
        Sleep::assertSequence(array_fill(0, 4, Sleep::for(500)->milliseconds()));
        Log::shouldHaveReceived('warning')->once()->with('PRICE_DISCOUNT restore not confirmed.', [
            'meli_item_id' => 'MLM4733828880',
            'standard_base' => 299.0,
            'sale_amount' => 269.1,
            'sale_regular_amount' => 299.0,
            'promotion_status' => 'candidate',
            'promotion_price' => 269.1,
        ]);
    }

    public function test_remove_cannot_confirm_without_a_numeric_standard_base(): void
    {
        $this->fakeRemotePrices([...$this->restoredPrices(), 'standard' => null]);

        try {
            $this->removePromotion();
            $this->fail('An unavailable standard base must not confirm restore.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame('ambiguous_standard_price', $exception->errorCode());
        }
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Sleep::assertNeverSlept();
    }

    #[DataProvider('unrestoredPrices')]
    public function test_restore_rejects_remaining_promotion_evidence(array $prices): void
    {
        $this->fakeRemotePrices($prices);

        try {
            app(MeliPriceDiscountPromotionService::class)->confirmRestored(
                new MeliAccount(['access_token' => 'test-token']),
                new MeliPriceManagerItem(['meli_item_id' => 'MLM4733828880']),
            );
            $this->fail('Remaining promotion evidence must prevent restore confirmation.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame('promotion_restore_not_confirmed', $exception->errorCode());
            $this->assertSame(502, $exception->httpStatus());
        }

        Http::assertSentCount(15);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Sleep::assertSleptTimes(4);
    }

    public static function restorePropagation(): array
    {
        $cases = [];
        foreach (['candidate', 'started', 'active'] as $status) {
            foreach ([0, 1, 4] as $staleReads) {
                $cases[$status.' after '.$staleReads.' stale reads'] = [$status, $staleReads];
            }
        }

        return $cases;
    }

    public static function confirmedStatuses(): array
    {
        return [
            'candidate with winning prices' => ['candidate'],
            'started with winning prices' => ['started'],
            'active with winning prices' => ['active'],
        ];
    }

    public static function unconfirmedPrices(): array
    {
        return [
            'candidate alone' => [['promotion' => null, 'sale' => 299.0, 'regular' => null]],
            'wrong strikethrough base' => [['regular' => 300.0]],
            'missing strikethrough base' => [['regular' => null]],
            'missing promotion with correct sale' => [['promotion' => null]],
            'wrong standard base' => [['standard' => 300.0]],
            'wrong sale amount' => [['sale' => 268.0]],
            'wrong winning price' => [['promotion' => 268.0, 'sale' => 268.0]],
            'unsupported status' => [['status' => 'finished']],
        ];
    }

    public static function unrestoredPrices(): array
    {
        return [
            'winning promotion remains' => [[]],
            'strikethrough remains without promotion' => [['promotion' => null, 'sale' => 299.0]],
            'sale differs from standard' => [['promotion' => null, 'regular' => null]],
            'sale unavailable' => [['promotion' => null, 'sale' => null, 'regular' => null]],
        ];
    }

    public static function localPromotionWindows(): array
    {
        return [
            'same local day' => [
                '17:00', '17:30', '2026-09-07 17:15:00',
                '2026-09-07T00:00:00', '2026-09-07T00:00:00',
            ],
            'overnight before midnight' => [
                '22:00', '06:00', '2026-09-07 23:00:00',
                '2026-09-07T00:00:00', '2026-09-08T00:00:00',
            ],
            'overnight after midnight' => [
                '22:00', '06:00', '2026-09-08 01:00:00',
                '2026-09-07T00:00:00', '2026-09-08T00:00:00',
            ],
        ];
    }

    private function createPromotion(): array
    {
        return CarbonImmutable::withTestNow(
            CarbonImmutable::parse('2026-09-07 17:15', 'America/Mexico_City'),
            fn (): array => app(MeliPriceDiscountPromotionService::class)->create(
                new MeliAccount(['access_token' => 'test-token']),
                new MeliPriceManagerItem(['meli_item_id' => 'MLM4733828880']),
                new MeliBeautyScheduledDiscount([
                    'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
                    'starts_at' => '17:00', 'ends_at' => '17:30', 'timezone' => 'America/Mexico_City',
                ]),
                299.00,
                269.10,
            ),
        );
    }

    private function restoredPrices(): array
    {
        return ['promotion' => null, 'sale' => 299.0, 'regular' => null];
    }

    private function removePromotion(): array
    {
        return app(MeliPriceDiscountPromotionService::class)->remove(
            new MeliAccount(['access_token' => 'test-token']),
            new MeliPriceManagerItem(['meli_item_id' => 'MLM4733828880']),
        );
    }

    private function fakeRemotePrices(array $before, ?array $afterPost = null, array $afterDelete = []): void
    {
        $posted = false;
        $deleted = false;
        $confirmationIndex = 0;
        Http::fake(function (Request $request) use ($before, $afterPost, $afterDelete, &$posted, &$deleted, &$confirmationIndex): mixed {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/seller-promotions/items/MLM4733828880' && $request->method() === 'POST') {
                $posted = true;

                return Http::response([], 201);
            }
            if ($path === '/seller-promotions/items/MLM4733828880' && $request->method() === 'DELETE') {
                $deleted = true;

                return Http::response([], 200);
            }

            $this->assertSame('GET', $request->method());
            $overrides = $posted ? ($afterPost ?? $before) : $before;
            if ($deleted && $afterDelete !== []) {
                $overrides = $afterDelete[min($confirmationIndex, count($afterDelete) - 1)];
                if ($path === '/seller-promotions/items/MLM4733828880') {
                    $confirmationIndex++;
                }
            }
            $prices = array_replace([
                'standard' => 299.0, 'promotion' => 269.1, 'sale' => 269.1,
                'regular' => 299.0, 'status' => 'candidate',
            ], $overrides);

            return match ($path) {
                '/items/MLM4733828880/prices' => Http::response(['prices' => array_values(array_filter([
                    ['type' => 'standard', 'amount' => $prices['standard']],
                    $prices['promotion'] === null ? null : [
                        'type' => 'promotion', 'amount' => $prices['promotion'], 'regular_amount' => 299.0,
                        'conditions' => ['context_restrictions' => ['channel_marketplace']],
                    ],
                ]))]),
                '/items/MLM4733828880/sale_price' => Http::response([
                    'amount' => $prices['sale'], 'regular_amount' => $prices['regular'],
                    'metadata' => $prices['promotion'] === null ? [] : [
                        'promotion_id' => 'OFFER-TEST', 'promotion_type' => 'custom',
                    ],
                ]),
                '/seller-promotions/items/MLM4733828880' => Http::response([
                    ['type' => 'PRICE_DISCOUNT', 'status' => $prices['status'], 'original_price' => 299.0],
                ]),
                default => $this->fail('Unexpected HTTP request: '.$request->url()),
            };
        });
    }
}
