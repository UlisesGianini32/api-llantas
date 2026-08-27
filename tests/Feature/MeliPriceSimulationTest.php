<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliPriceSimulationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class MeliPriceSimulationTest extends TestCase
{
    private object $foundationMigration;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'meli_user_id']);
        });
        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('MLM')->nullable();
        });

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->foundationMigration->down();
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_manageable_item_simulation_interprets_fees_shipping_dimensions_and_totals(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        $this->fakeSuccessfulResponses();

        $result = $this->service()->simulate($account, $item, 1531.20);

        $this->assertSame(237.34, $result['sale_fee']);
        $this->assertSame(15.5, $result['sale_fee_percentage']);
        $this->assertSame(0.0, $result['sale_fee_fixed']);
        $this->assertSame(74.5, $result['shipping_cost']);
        $this->assertSame(149.0, $result['shipping_original_cost']);
        $this->assertSame(0.5, $result['shipping_discount_rate']);
        $this->assertSame(311.84, $result['total_charges']);
        $this->assertSame(1219.36, $result['estimated_receivable']);
        $this->assertSame(79.63, $result['estimated_receivable_percentage']);
        $this->assertSame('Premium', $result['listing_type_name']);
        $this->assertSame('1531.20', $item->fresh()->current_price);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sites/MLM/listing_prices')
            && $request->method() === 'GET'
            && (float) $request['price'] === 1531.20
            && $request['category_id'] === 'MLM171894'
            && $request['listing_type_id'] === 'gold_pro'
            && $request['shipping_mode'] === 'me2'
            && $request['logistic_type'] === 'fulfillment');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/users/123456789/shipping_options/free')
            && $request->method() === 'GET'
            && $request['dimensions'] === '24x7x7,733'
            && $request['item_id'] === 'MLM1343389489');
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_excluded_item_cannot_be_simulated_and_sends_no_http_request(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        DB::table('llantas')->insert(['MLM' => $item->meli_item_id]);

        try {
            $this->service()->simulate($account, $item, 1500);
            $this->fail('La publicación excluida debió rechazarse.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('excluida', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_item_from_another_account_cannot_be_simulated(): void
    {
        $selectedAccount = $this->account();
        $foreignItem = $this->item(MeliAccount::factory()->create([
            'access_token' => 'foreign-token',
            'expires_at' => now()->addHour(),
        ]));

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->simulate($selectedAccount, $foreignItem, 1500);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_non_positive_price_is_rejected_before_any_http_request(): void
    {
        $account = $this->account();
        $item = $this->item($account);

        foreach ([0.0, -1.0] as $price) {
            try {
                $this->service()->simulate($account, $item, $price);
                $this->fail('El precio no positivo debió rechazarse.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    public function test_item_without_free_shipping_only_charges_sale_fee(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'raw_item' => [
                'condition' => 'new',
                'shipping' => ['free_shipping' => false, 'mode' => 'me2', 'logistic_type' => 'cross_docking'],
            ],
        ]);
        Http::fake([
            'https://api.mercadolibre.com/sites/MLM/listing_prices*' => Http::response([[
                'listing_type_id' => 'gold_pro',
                'listing_type_name' => 'Premium',
                'sale_fee_amount' => 232.50,
                'sale_fee_details' => ['percentage_fee' => 15.5, 'fixed_fee' => 0],
            ]]),
        ]);

        $result = $this->service()->simulate($account, $item, 1500);

        $this->assertFalse($result['free_shipping']);
        $this->assertSame(0.0, $result['shipping_cost']);
        $this->assertNull($result['shipping_original_cost']);
        $this->assertNull($result['shipping_discount_rate']);
        $this->assertSame(232.5, $result['total_charges']);
        $this->assertSame(1267.5, $result['estimated_receivable']);
        Http::assertSentCount(1);
    }

    public function test_gold_special_with_missing_dimensions_uses_item_id_without_inventing_dimensions(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'listing_type_id' => 'gold_special',
            'raw_attributes' => [],
            'raw_item' => [
                'condition' => 'new',
                'shipping' => ['free_shipping' => true, 'mode' => 'me2', 'logistic_type' => 'cross_docking'],
                'attributes' => [],
            ],
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/listing_prices')) {
                return Http::response([[
                    'listing_type_id' => 'gold_special',
                    'listing_type_name' => 'Clásica',
                    'sale_fee_amount' => 210,
                    'sale_fee_details' => ['percentage_fee' => 14, 'fixed_fee' => 0],
                ]]);
            }

            return Http::response(['coverage' => ['all_country' => ['list_cost' => 80]]]);
        });

        $result = $this->service()->simulate($account, $item, 1500);

        $this->assertSame('gold_special', $result['listing_type_id']);
        $this->assertSame('Clásica', $result['listing_type_name']);
        $this->assertSame(290.0, $result['total_charges']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/shipping_options/free')
            && $request['item_id'] === 'MLM1343389489'
            && ! array_key_exists('dimensions', $request->data()));
    }

    public function test_package_dimensions_are_used_when_seller_package_dimensions_do_not_exist(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'raw_attributes' => [],
            'raw_item' => [
                'condition' => 'new',
                'shipping' => ['free_shipping' => true, 'mode' => 'me2', 'logistic_type' => 'fulfillment'],
                'attributes' => [
                    ['id' => 'PACKAGE_HEIGHT', 'value_name' => '24.1 cm'],
                    ['id' => 'PACKAGE_WIDTH', 'value_name' => '7.2 cm'],
                    ['id' => 'PACKAGE_LENGTH', 'value_name' => '8.3 cm'],
                    ['id' => 'PACKAGE_WEIGHT', 'value_name' => '733.1 g'],
                ],
            ],
        ]);
        $this->fakeSuccessfulResponses();

        $this->service()->simulate($account, $item, 1531.20);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/shipping_options/free')
            && $request['dimensions'] === '25x8x9,734');
    }

    public function test_simulation_endpoint_requires_authentication_and_valid_price(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        auth()->logout();

        $this->postJson(route('meli-price-manager.items.price.simulate', $item), ['price' => 1500])->assertUnauthorized();

        $this->actingAs($this->user);
        foreach ([null, 0, -10, 'invalid'] as $price) {
            $this->postJson(route('meli-price-manager.items.price.simulate', $item), ['price' => $price])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('price');
        }

        Http::assertNothingSent();
    }

    public function test_simulation_endpoint_blocks_foreign_and_excluded_items(): void
    {
        $foreignAccount = MeliAccount::factory()->create([
            'access_token' => 'foreign-token',
            'expires_at' => now()->addHour(),
        ]);
        $foreignItem = $this->item($foreignAccount);

        $this->postJson(route('meli-price-manager.items.price.simulate', $foreignItem), ['price' => 1500])->assertNotFound();

        $ownItem = $this->item($this->account(), ['meli_item_id' => 'MLM-EXCLUDED']);
        DB::table('llantas')->insert(['MLM' => $ownItem->meli_item_id]);
        $this->postJson(route('meli-price-manager.items.price.simulate', $ownItem), ['price' => 1500])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_simulation_endpoint_returns_json_without_modifying_publication(): void
    {
        $item = $this->item($this->account());
        $this->fakeSuccessfulResponses();

        $this->postJson(route('meli-price-manager.items.price.simulate', $item), ['price' => 1600])
            ->assertOk()
            ->assertJsonPath('data.meli_item_id', 'MLM1343389489')
            ->assertJsonPath('data.proposed_price', 1600)
            ->assertJsonPath('data.sale_fee', 237.34)
            ->assertJsonPath('data.shipping_cost', 74.5)
            ->assertJsonPath('data.total_charges', 311.84)
            ->assertJsonPath('data.estimated_receivable', 1288.16)
            ->assertJsonPath('data.estimated_receivable_percentage', 80.51);

        $this->assertSame('1531.20', $item->fresh()->current_price);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    private function service(): MeliPriceSimulationService
    {
        return app(MeliPriceSimulationService::class);
    }

    private function account(): MeliAccount
    {
        return MeliAccount::factory()->for($this->user)->create([
            'meli_user_id' => '123456789',
            'access_token' => 'test-access-token',
            'expires_at' => now()->addHour(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function item(MeliAccount $account, array $overrides = []): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create([
            'meli_item_id' => 'MLM1343389489',
            'title' => 'Alfaparf Yellow Liss Mascarilla 500ml',
            'sku' => 'SKU-PRICE-1',
            'category_id' => 'MLM171894',
            'listing_type_id' => 'gold_pro',
            'currency_id' => 'MXN',
            'current_price' => '1531.20',
            'classification_status' => 'categorized',
            'raw_item' => [
                'condition' => 'new',
                'shipping' => ['free_shipping' => true, 'mode' => 'me2', 'logistic_type' => 'fulfillment'],
                'attributes' => [
                    ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_struct' => ['number' => 23.2, 'unit' => 'cm']],
                    ['id' => 'SELLER_PACKAGE_WIDTH', 'value_struct' => ['number' => 6.1, 'unit' => 'cm']],
                    ['id' => 'SELLER_PACKAGE_LENGTH', 'value_struct' => ['number' => 7, 'unit' => 'cm']],
                    ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_struct' => ['number' => 732.1, 'unit' => 'g']],
                ],
            ],
            ...$overrides,
        ]);
    }

    private function fakeSuccessfulResponses(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/sites/MLM/listing_prices')) {
                return Http::response([[
                    'listing_type_id' => 'gold_pro',
                    'listing_type_name' => 'Premium',
                    'sale_fee_amount' => 237.34,
                    'sale_fee_details' => ['percentage_fee' => 15.5, 'fixed_fee' => 0],
                ]]);
            }

            if (str_contains($request->url(), '/shipping_options/free')) {
                return Http::response([
                    'coverage' => [
                        'all_country' => [
                            'list_cost' => 74.5,
                            'discount' => ['rate' => 0.5, 'promoted_amount' => 149],
                        ],
                    ],
                ]);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });
    }
}
