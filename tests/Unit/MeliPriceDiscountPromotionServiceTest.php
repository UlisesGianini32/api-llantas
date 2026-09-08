<?php

namespace Tests\Unit;

use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliPriceDiscountPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeliPriceDiscountPromotionServiceTest extends TestCase
{
    #[DataProvider('localPromotionWindows')]
    public function test_create_posts_local_calendar_dates(
        string $startsAt,
        string $endsAt,
        string $now,
        string $expectedStart,
        string $expectedFinish,
    ): void {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadolibre.com/seller-promotions/items/MLM4733828880?app_version=v2' => Http::sequence()
                ->push([], 201)
                ->push([['type' => 'PRICE_DISCOUNT', 'status' => 'started', 'original_price' => 299.00]]),
            'https://api.mercadolibre.com/items/MLM4733828880/prices?*' => Http::response([
                'prices' => [
                    ['type' => 'standard', 'amount' => 299.00],
                    ['type' => 'promotion', 'amount' => 269.10],
                ],
            ]),
            'https://api.mercadolibre.com/items/MLM4733828880/sale_price?*' => Http::response([
                'amount' => 269.10,
                'regular_amount' => 299.00,
            ]),
        ]);

        $rule = new MeliBeautyScheduledDiscount([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => 'America/Hermosillo',
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
        Http::assertSentCount(4);
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
}
