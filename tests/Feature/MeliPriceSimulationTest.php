<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliAccountTaxProfile;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliPriceSimulationService;
use App\Services\MercadoLibre\PriceManager\MeliPriceSimulationTokenService;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxRuleService;
use App\Services\MercadoLibre\PriceManager\MeliEstimatedReceivableSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class MeliPriceSimulationTest extends TestCase
{
    private object $foundationMigration;

    private object $taxProfileMigration;

    private object $receivableSnapshotMigration;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('cache.default', 'array');
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', []);
        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', []);
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        Cache::flush();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 32)->default('operations');
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
            $table->string('sku')->nullable();
            $table->string('MLM')->nullable();
        });

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();
        $this->receivableSnapshotMigration = require database_path('migrations/2026_08_29_000002_add_estimated_receivable_snapshot_to_meli_price_manager_items.php');
        $this->receivableSnapshotMigration->up();
        $this->taxProfileMigration = require database_path('migrations/2026_08_27_000002_create_meli_account_tax_profiles_table.php');
        $this->taxProfileMigration->up();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        $this->taxProfileMigration->down();
        $this->receivableSnapshotMigration->down();
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
            && $request['dimensions'] === '24x7x7,732'
            && $request['item_id'] === 'MLM1343389489');
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_account_tax_profile_reproduces_the_observed_699_receivable_without_double_counting(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['current_price' => 699]);
        MeliAccountTaxProfile::query()->create([
            'meli_account_id' => $account->id,
            'enabled' => true,
            'vat_included_rate' => 16,
            'vat_withholding_rate' => 8,
            'income_tax_withholding_rate' => 2.5,
            'notes' => 'Caso fiscal validado',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/listing_prices')) {
                return Http::response([[
                    'listing_type_id' => 'gold_pro',
                    'listing_type_name' => 'Premium',
                    'listing_fee_amount' => 0,
                    'sale_fee_amount' => 101.36,
                    'sale_fee_details' => ['percentage_fee' => 14.5, 'fixed_fee' => 0, 'gross_amount' => 150],
                ]]);
            }

            return Http::response(['coverage' => ['all_country' => [
                'list_cost' => 70,
                'discount' => ['rate' => 0.5, 'promoted_amount' => 140],
            ]]]);
        });

        $result = $this->service()->simulate($account, $item, 699);

        $this->assertSame(602.59, data_get($result, 'charges.taxes.taxable_base'));
        $this->assertSame(48.21, data_get($result, 'charges.taxes.vat.amount'));
        $this->assertSame(15.06, data_get($result, 'charges.taxes.income_tax.amount'));
        $this->assertSame(63.27, data_get($result, 'charges.taxes.amount'));
        $this->assertSame(171.36, $result['meli_charges_total']);
        $this->assertSame(171.36, $result['confirmed_charges_total']);
        $this->assertSame(63.27, $result['taxes_total']);
        $this->assertSame(234.63, $result['total_charges']);
        $this->assertSame(464.37, $result['estimated_receivable']);
        $this->assertSame('account_tax_profile', data_get($result, 'charges.taxes.source'));

        $issued = app(MeliPriceSimulationTokenService::class)->issue($this->user->id, $account, $item, $result);
        $snapshot = app(MeliPriceSimulationTokenService::class)->resolve($issued['token']);
        $this->assertSame(16.0, data_get($snapshot, 'simulation.charges.taxes.profile.vat_included_rate'));
        $this->assertSame(8.0, data_get($snapshot, 'simulation.charges.taxes.profile.vat_withholding_rate'));
        $this->assertSame(2.5, data_get($snapshot, 'simulation.charges.taxes.profile.income_tax_withholding_rate'));
        $this->assertSame(464.37, data_get($snapshot, 'simulation.estimated_receivable'));

        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_historical_rule_reproduces_the_699_benchmark_and_is_preserved_in_the_token(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['current_price' => 699]);
        $rules = Mockery::mock(MeliHistoricalTaxRuleService::class);
        $rules->shouldReceive('forAccount')->once()->with($account)->andReturn([
            'available' => true,
            'source' => 'historical_account_tax_rule',
            'confidence' => 'high',
            'stale' => true,
            'fallback' => 'last_valid_historical_rule',
            'sample_count' => 7,
            'vat_included_rate' => 16.0,
            'vat_withholding_rate' => 8.0,
            'income_tax_withholding_rate' => 2.5,
            'first_observed_at' => '2026-08-10T18:00:00.000000Z',
            'last_observed_at' => '2026-08-16T18:00:00.000000Z',
            'evidence' => ['distinct_items' => 7, 'money_tolerance_cents' => 1],
        ]);
        $this->app->instance(MeliHistoricalTaxRuleService::class, $rules);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/listing_prices')) {
                return Http::response([[
                    'listing_type_id' => 'gold_pro',
                    'listing_type_name' => 'Premium',
                    'listing_fee_amount' => 0,
                    'sale_fee_amount' => 101.36,
                ]]);
            }

            return Http::response(['coverage' => ['all_country' => [
                'list_cost' => 70,
                'discount' => ['rate' => 0.5, 'promoted_amount' => 140],
            ]]]);
        });

        $result = $this->service()->simulate($account, $item, 699);

        $this->assertSame('historical_account_tax_rule', data_get($result, 'charges.taxes.source'));
        $this->assertSame('high', data_get($result, 'charges.taxes.confidence'));
        $this->assertTrue(data_get($result, 'charges.taxes.stale'));
        $this->assertSame('last_valid_historical_rule', data_get($result, 'charges.taxes.fallback'));
        $this->assertSame(48.21, data_get($result, 'charges.taxes.vat.amount'));
        $this->assertSame(15.06, data_get($result, 'charges.taxes.income_tax.amount'));
        $this->assertSame(63.27, $result['taxes_total']);
        $this->assertSame(234.63, $result['total_charges']);
        $this->assertSame(464.37, $result['estimated_receivable']);

        $issued = app(MeliPriceSimulationTokenService::class)->issue($this->user->id, $account, $item, $result);
        $snapshot = app(MeliPriceSimulationTokenService::class)->resolve($issued['token']);
        $this->assertSame(7, data_get($snapshot, 'simulation.charges.taxes.rule.sample_count'));
        $this->assertSame(7, data_get($snapshot, 'simulation.charges.taxes.rule.evidence.distinct_items'));
        $this->assertSame(16.0, data_get($snapshot, 'simulation.charges.taxes.rule.vat_included_rate'));
        $this->assertTrue(data_get($snapshot, 'simulation.charges.taxes.rule.stale'));
        $this->assertSame('last_valid_historical_rule', data_get($snapshot, 'simulation.charges.taxes.rule.fallback'));
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR);
        foreach (['buyer', 'email', 'phone', 'address', 'rfc', 'document', 'access_token', 'refresh_token'] as $piiKey) {
            $this->assertStringNotContainsString($piiKey, strtolower($snapshotJson));
        }

        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_complete_charge_summary_preserves_official_details_without_counting_informational_values(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/listing_prices')) {
                return Http::response([[
                    'listing_type_id' => 'gold_pro',
                    'listing_type_name' => 'Premium',
                    'listing_fee_amount' => 7.25,
                    'listing_fee_details' => ['fixed_fee' => 7.25, 'gross_amount' => 10],
                    'sale_fee_amount' => 115.28,
                    'sale_fee_details' => [
                        'percentage_fee' => 14.5,
                        'meli_percentage_fee' => 13,
                        'financing_add_on_fee' => 1.5,
                        'fixed_fee' => 0,
                        'gross_amount' => 120,
                    ],
                    'service_charge_amount' => 999,
                ]]);
            }

            return Http::response([
                'coverage' => [
                    'all_country' => [
                        'list_cost' => 65.5,
                        'currency_id' => 'MXN',
                        'billable_weight' => 733,
                        'discount' => ['rate' => 0.5, 'type' => 'loyalty', 'promoted_amount' => 131],
                    ],
                ],
            ]);
        });

        $result = $this->service()->simulate($account, $item, 795);

        $this->assertSame(115.28, data_get($result, 'charges.sale_fee.amount'));
        $this->assertSame(14.5, data_get($result, 'charges.sale_fee.percentage'));
        $this->assertSame(13.0, data_get($result, 'charges.sale_fee.meli_percentage'));
        $this->assertSame(0.0, data_get($result, 'charges.sale_fee.fixed_fee'));
        $this->assertSame(1.5, data_get($result, 'charges.sale_fee.financing_add_on_fee'));
        $this->assertSame(120.0, data_get($result, 'charges.sale_fee.gross_amount'));
        $this->assertSame(7.25, data_get($result, 'charges.listing_fee.amount'));
        $this->assertSame(10.0, data_get($result, 'charges.listing_fee.gross_amount'));
        $this->assertSame(65.5, data_get($result, 'charges.shipping.seller_cost'));
        $this->assertTrue(data_get($result, 'charges.shipping.available'));
        $this->assertSame(131.0, data_get($result, 'charges.shipping.original_cost'));
        $this->assertSame(0.5, data_get($result, 'charges.shipping.discount_rate'));
        $this->assertNull(data_get($result, 'charges.shipping.discount_amount'));
        $this->assertSame(733.0, data_get($result, 'charges.shipping.billable_weight'));
        $this->assertFalse(data_get($result, 'charges.taxes.available'));
        $this->assertNull(data_get($result, 'charges.taxes.amount'));
        $this->assertNull(data_get($result, 'charges.taxes.iva'));
        $this->assertNull(data_get($result, 'charges.taxes.isr'));
        $this->assertSame(999.0, data_get($result, 'charges.other.0.value'));
        $this->assertFalse(data_get($result, 'charges.other.0.included_in_total'));
        $this->assertSame(188.03, $result['confirmed_charges_total']);
        $this->assertSame(606.97, $result['estimated_receivable']);
        $this->assertFalse($result['estimated_receivable_is_final']);
        $this->assertStringContainsString('sin retenciones fiscales', $result['estimated_receivable_label']);

        $issued = app(MeliPriceSimulationTokenService::class)->issue($this->user->id, $account, $item, $result);
        $snapshot = app(MeliPriceSimulationTokenService::class)->resolve($issued['token']);
        $this->assertSame($result['charges'], data_get($snapshot, 'simulation.charges'));
        $this->assertSame(188.03, data_get($snapshot, 'simulation.confirmed_charges_total'));

        Http::assertSentCount(2);
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

    public function test_shipping_quote_is_deducted_and_receives_complete_local_context(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/listing_prices')) {
                return Http::response([['listing_type_id' => 'gold_pro', 'sale_fee_amount' => 100]]);
            }

            return Http::response(['coverage' => ['all_country' => [
                'list_cost' => 79, 'currency_id' => 'MXN', 'billable_weight' => 733,
                'discount' => ['rate' => 0.25, 'promoted_amount' => 105, 'save' => 26],
            ]]]);
        });

        $result = $this->service()->simulate($account, $item, 1000);

        $this->assertTrue(data_get($result, 'charges.shipping.available'));
        $this->assertSame(79.0, data_get($result, 'charges.shipping.cost'));
        $this->assertSame('meli_shipping_options_free', data_get($result, 'charges.shipping.source'));
        $this->assertSame(100.0, $result['platform_charges_total']);
        $this->assertSame(179.0, $result['total_charges']);
        $this->assertSame(821.0, $result['estimated_receivable']);
        $this->assertSame(
            $result['platform_charges_total'] + $result['shipping_cost'] + ($result['taxes_total'] ?? 0),
            $result['total_charges'],
            'El envío debe sumarse exactamente una vez a las deducciones.',
        );
        $this->assertSame($result['proposed_price'] - $result['total_charges'], $result['estimated_receivable']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/users/123456789/shipping_options/free')
            && $request->method() === 'GET'
            && $request['item_id'] === 'MLM1343389489'
            && (float) $request['item_price'] === 1000.0
            && $request['listing_type_id'] === 'gold_pro'
            && $request['mode'] === 'me2'
            && $request['logistic_type'] === 'fulfillment'
            && $request['condition'] === 'new'
            && $request['free_shipping'] === 'true');
    }

    public function test_zero_shipping_quote_is_available_and_not_confused_with_missing_cost(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/listing_prices')
            ? Http::response([['listing_type_id' => 'gold_pro', 'sale_fee_amount' => 100]])
            : Http::response(['coverage' => ['all_country' => ['list_cost' => 0, 'currency_id' => 'MXN']]]));

        $result = $this->service()->simulate($account, $item, 1000);

        $this->assertTrue(data_get($result, 'charges.shipping.available'));
        $this->assertSame(0.0, $result['shipping_cost']);
        $this->assertSame(900.0, $result['estimated_receivable']);
        $this->assertSame('Recibes estimado sin retenciones fiscales', $result['estimated_receivable_label']);
    }

    public function test_missing_or_failed_shipping_quote_keeps_other_charges_and_marks_net_before_shipping(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        MeliAccountTaxProfile::query()->create([
            'meli_account_id' => $account->id,
            'enabled' => true,
            'vat_included_rate' => 16,
            'vat_withholding_rate' => 8,
            'income_tax_withholding_rate' => 2.5,
        ]);

        foreach (['missing_cost', 'http_error'] as $case) {
            Http::fake(function (Request $request) use ($case) {
                if (str_contains($request->url(), '/listing_prices')) {
                    return Http::response([['listing_type_id' => 'gold_pro', 'sale_fee_amount' => 100]]);
                }

                return $case === 'http_error'
                    ? Http::response(['message' => 'shipping unavailable'], 400)
                    : Http::response(['coverage' => ['all_country' => ['currency_id' => 'MXN']]]);
            });

            $result = $this->service()->simulate($account, $item, 1000);

            $this->assertFalse(data_get($result, 'charges.shipping.available'));
            $this->assertNull($result['shipping_cost']);
            $this->assertSame(90.52, $result['taxes_total']);
            $this->assertSame(190.52, $result['total_charges']);
            $this->assertSame(809.48, $result['estimated_receivable']);
            $this->assertSame('Recibes antes de envío', $result['estimated_receivable_label']);
            $this->assertStringContainsString('no descuenta el envío', $result['estimated_receivable_message']);
        }
    }

    public function test_incompatible_shipping_currency_is_not_subtracted(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/listing_prices')
            ? Http::response([['listing_type_id' => 'gold_pro', 'sale_fee_amount' => 100]])
            : Http::response(['coverage' => ['all_country' => ['list_cost' => 79, 'currency_id' => 'USD']]]));

        $result = $this->service()->simulate($account, $item, 1000);

        $this->assertFalse(data_get($result, 'charges.shipping.available'));
        $this->assertNull($result['shipping_cost']);
        $this->assertSame('USD', data_get($result, 'charges.shipping.currency_id'));
        $this->assertSame(900.0, $result['estimated_receivable']);
        $this->assertStringContainsString('moneda', data_get($result, 'charges.shipping.error'));
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
            'https://api.mercadolibre.com/users/*/shipping_options/free*' => Http::response([
                'coverage' => ['all_country' => ['list_cost' => 0, 'currency_id' => 'MXN']],
            ]),
        ]);

        $result = $this->service()->simulate($account, $item, 1500);

        $this->assertFalse($result['free_shipping']);
        $this->assertSame(0.0, $result['shipping_cost']);
        $this->assertNull($result['shipping_original_cost']);
        $this->assertNull($result['shipping_discount_rate']);
        $this->assertSame(232.5, $result['total_charges']);
        $this->assertSame(1267.5, $result['estimated_receivable']);
        Http::assertSentCount(2);
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
            && $request['dimensions'] === '25x8x9,733');
    }

    public function test_dimensions_are_normalized_to_centimeters_and_integer_grams_or_omitted(): void
    {
        $account = $this->account();
        $cases = [
            'cm_g' => [
                'attributes' => $this->packageAttributes([6, 'cm'], [25, 'cm'], [31, 'cm'], [214, 'g']),
                'expected' => '6x25x31,214',
            ],
            'fractional_kg' => [
                'attributes' => $this->packageAttributes([6, 'cm'], [25, 'cm'], [31, 'cm'], [0.7605, 'kg']),
                'expected' => '6x25x31,761',
            ],
            'one_kg_value_name' => [
                'attributes' => [
                    ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_name' => '6 cm'],
                    ['id' => 'SELLER_PACKAGE_WIDTH', 'value_name' => '25 cm'],
                    ['id' => 'SELLER_PACKAGE_LENGTH', 'value_name' => '31 cm'],
                    ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_name' => '1 kg'],
                ],
                'expected' => '6x25x31,1000',
            ],
            'meters' => [
                'attributes' => $this->packageAttributes([0.25, 'm'], [0.1, 'm'], [0.31, 'm'], [214, 'g']),
                'expected' => '25x10x31,214',
            ],
            'unknown_unit' => [
                'attributes' => $this->packageAttributes([6, 'cm'], [25, 'in'], [31, 'cm'], [214, 'g']),
                'expected' => null,
            ],
            'incomplete' => [
                'attributes' => array_slice($this->packageAttributes([6, 'cm'], [25, 'cm'], [31, 'cm'], [214, 'g']), 0, 3),
                'expected' => null,
            ],
        ];
        Http::fake(fn (Request $request) => str_contains($request->url(), '/listing_prices')
            ? Http::response([['listing_type_id' => 'gold_pro', 'sale_fee_amount' => 100]])
            : Http::response(['coverage' => ['all_country' => ['list_cost' => 79, 'currency_id' => 'MXN']]]));

        foreach ($cases as $name => $case) {
            $item = $this->item($account, [
                'meli_item_id' => 'MLM-DIM-'.strtoupper($name),
                'raw_attributes' => [],
                'raw_item' => [
                    'condition' => 'new',
                    'shipping' => ['free_shipping' => true, 'mode' => 'me2', 'logistic_type' => 'fulfillment'],
                    'attributes' => $case['attributes'],
                ],
            ]);

            $this->service()->simulate($account, $item, 1000);
            $shippingRequest = collect(Http::recorded())->last(fn (array $pair): bool => str_contains($pair[0]->url(), '/shipping_options/free'))[0];

            if ($case['expected'] === null) {
                $this->assertArrayNotHasKey('dimensions', $shippingRequest->data(), $name);
            } else {
                $this->assertSame($case['expected'], $shippingRequest['dimensions'], $name);
            }
        }
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

    public function test_simulation_endpoint_blocks_republished_tire_detected_only_by_sku(): void
    {
        $item = $this->item($this->account(), [
            'meli_item_id' => 'MLM5201403642',
            'sku' => '2056014MEMR166',
        ]);
        DB::table('llantas')->insert([
            'MLM' => 'MLM2720548725',
            'sku' => '2056014MEMR166',
        ]);

        $this->assertFalse(MeliPriceManagerItem::query()->managedCatalog()->whereKey($item)->exists());
        $this->postJson(route('meli-price-manager.items.price.simulate', $item), ['price' => 1500])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_simulation_endpoint_returns_json_without_modifying_publication(): void
    {
        $item = $this->item($this->account());
        $this->fakeSuccessfulResponses();

        $response = $this->postJson(route('meli-price-manager.items.price.simulate', $item), ['price' => 1600]);
        $response->assertOk()
            ->assertJsonPath('data.meli_item_id', 'MLM1343389489')
            ->assertJsonPath('data.proposed_price', 1600)
            ->assertJsonPath('data.sale_fee', 237.34)
            ->assertJsonPath('data.shipping_cost', 74.5)
            ->assertJsonPath('data.total_charges', 311.84)
            ->assertJsonPath('data.estimated_receivable', 1288.16)
            ->assertJsonPath('data.estimated_receivable_percentage', 80.51)
            ->assertJsonStructure(['data' => ['simulation_token', 'simulation_expires_at']]);

        $this->assertStringNotContainsString('test-access-token', $response->getContent());

        $this->assertSame('1531.20', $item->fresh()->current_price);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_current_price_simulation_persists_the_exact_complete_receivable_without_put(): void
    {
        $item = $this->item($this->account());
        $this->fakeSuccessfulResponses();

        $response = $this->postJson(route('meli-price-manager.items.price.simulate', $item), ['price' => 1531.20])
            ->assertOk();
        $receivable = (float) $response->json('data.estimated_receivable');

        $item->refresh();
        $this->assertSame('1531.20', $item->current_price);
        $this->assertSame($receivable, (float) $item->estimated_receivable);
        $this->assertSame('1531.20', $item->estimated_receivable_price);
        $this->assertNotNull($item->estimated_receivable_calculated_at);
        $this->assertSame($receivable, (float) $response->json('data.receivable_snapshot.amount'));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
        }
    }

    public function test_receivable_snapshot_is_stale_at_a_different_price_and_rejects_missing_shipping(): void
    {
        $item = $this->item($this->account(), [
            'estimated_receivable' => 113.90,
            'estimated_receivable_price' => 200,
        ]);
        $snapshots = app(MeliEstimatedReceivableSnapshotService::class);

        $this->assertNull($snapshots->currentAmount($item));
        $item->forceFill(['current_price' => 200])->save();
        $this->assertSame(113.90, $snapshots->currentAmount($item->refresh()));
        $this->assertNull($snapshots->storeForCurrentPrice($item, [
            'proposed_price' => 200,
            'estimated_receivable' => 150,
            'charges' => ['shipping' => ['available' => false, 'cost' => null]],
        ]));
        $this->assertSame('113.90', $item->fresh()->estimated_receivable);
        $stored = $snapshots->storeForCurrentPrice($item, [
            'proposed_price' => 200,
            'estimated_receivable' => 120,
            'charges' => ['shipping' => ['available' => true, 'cost' => 0]],
        ]);
        $this->assertSame(120.0, $stored['amount']);
        $this->assertSame('120.00', $item->fresh()->estimated_receivable);
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

    /** @return list<array<string, mixed>> */
    private function packageAttributes(array $height, array $width, array $length, array $weight): array
    {
        return collect([
            'SELLER_PACKAGE_HEIGHT' => $height,
            'SELLER_PACKAGE_WIDTH' => $width,
            'SELLER_PACKAGE_LENGTH' => $length,
            'SELLER_PACKAGE_WEIGHT' => $weight,
        ])->map(fn (array $measurement, string $id): array => [
            'id' => $id,
            'value_struct' => ['number' => $measurement[0], 'unit' => $measurement[1]],
        ])->values()->all();
    }
}
