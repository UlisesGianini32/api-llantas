<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliPriceChange;
use App\Models\MeliPriceChangeBatch;
use App\Models\MeliCategory;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliPriceSimulationTokenService;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateException;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeliPriceUpdateTest extends TestCase
{
    private const FOCUSED_CATEGORY_ID = 'MLM438195';

    private object $foundationMigration;

    private object $taxProfileMigration;

    private object $linkedPublicationsMigration;

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
        Sleep::fake();

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
            $table->string('meli_id')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
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
        Schema::create('producto_compuestos', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('MLM')->nullable();
        });
        Schema::create('syscom_meli_queues', function (Blueprint $table): void {
            $table->id();
            $table->string('mlm')->nullable();
        });
        Schema::create('automotive_part_meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('meli_item_id')->nullable();
        });

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();
        $this->linkedPublicationsMigration = require database_path('migrations/2026_08_29_000001_add_linked_publication_fields_to_meli_price_manager_items.php');
        $this->linkedPublicationsMigration->up();
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
        $this->linkedPublicationsMigration->down();
        $this->foundationMigration->down();
        Schema::dropIfExists('automotive_part_meli_publications');
        Schema::dropIfExists('syscom_meli_queues');
        Schema::dropIfExists('producto_compuestos');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_successful_update_sends_only_price_verifies_remote_result_and_persists_audit(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        $token = $this->token($account, $item, 1600.00);
        $this->fakeSuccessfulUpdate(1531.20, 1600.00);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertOk()
            ->assertJsonPath('message', 'Cambios actualizados correctamente en Mercado Libre.')
            ->assertJsonPath('data.meli_item_id', 'MLM1343389489')
            ->assertJsonPath('data.old_price', 1531.2)
            ->assertJsonPath('data.new_price', 1600);

        $this->assertSame('1600.00', $item->fresh()->current_price);
        $this->assertSame('gold_pro', $item->fresh()->listing_type_id);
        $this->assertSame('1270.25', $item->fresh()->estimated_receivable);
        $this->assertSame('1600.00', $item->fresh()->estimated_receivable_price);
        $this->assertNotNull($item->fresh()->estimated_receivable_calculated_at);
        $this->assertFalse(Cache::has(app(MeliPriceSimulationTokenService::class)->cacheKey($token)));

        $batch = MeliPriceChangeBatch::query()->sole();
        $change = MeliPriceChange::query()->sole();
        $this->assertSame('individual', $batch->type);
        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->total_items);
        $this->assertSame(1, $batch->successful_items);
        $this->assertSame(0, $batch->failed_items);
        $this->assertSame($this->user->id, $batch->created_by);
        $this->assertSame('success', $change->status);
        $this->assertSame('1531.20', $change->old_price);
        $this->assertSame('1600.00', $change->new_price);
        $this->assertSame('248.00', $change->selling_fee);
        $this->assertSame('74.50', $change->shipping_cost);
        $this->assertNull($change->tax_withholding);
        $this->assertSame('7.25', $change->other_charges);
        $this->assertSame('1270.25', $change->estimated_net);
        $this->assertNotNull($change->changed_at);
        $batchNotes = json_decode((string) $batch->notes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(7.25, data_get($batchNotes, 'simulation_snapshot.charges.listing_fee.amount'));
        $this->assertNull(data_get($batchNotes, 'simulation_snapshot.charges.taxes.amount'));
        $this->assertSame(329.75, data_get($batchNotes, 'simulation_snapshot.confirmed_charges_total'));

        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/items/MLM1343389489')
                && $request->data() === ['price' => 1600.0];
        });
    }

    public function test_price_update_rejects_item_outside_focused_catalog_before_calling_mercado_libre(): void
    {
        $migration = require database_path('migrations/2026_08_28_000002_create_meli_categories_table.php');
        $migration->up();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        MeliCategory::query()->create([
            'category_id' => 'MLM-TEST-POOL',
            'name' => 'Albercas',
            'root_category_id' => 'MLM-TEST-HOME-ROOT',
        ]);
        $account = $this->account();
        $item = $this->item($account, ['category_id' => 'MLM-TEST-POOL']);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => str_repeat('x', 64),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_real_single_standard_without_context_resolves_599_and_update_sends_only_price_600(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'meli_item_id' => 'MLM1577724953',
            'sku' => 'REAL-STANDARD-PRICE',
            'current_price' => 599,
        ]);
        $priceReads = 0;
        Http::fake(function (Request $request) use (&$priceReads) {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response(['message' => 'automation_not_found'], 404);
            }
            if (str_contains($request->url(), '/prices?display_version=true')) {
                $priceReads++;

                return Http::response([[
                    'id' => '2',
                    'type' => 'standard',
                    'amount' => $priceReads === 1 ? 599 : 600,
                    'regular_amount' => null,
                    'currency_id' => 'MXN',
                    'conditions' => ['context_restrictions' => []],
                ]]);
            }
            if ($request->method() === 'PUT') {
                return Http::response(['price' => $request['price'], 'warnings' => []]);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 600),
            'price' => 600,
            'listing_type_id' => 'gold_pro',
        ])->assertOk()->assertJsonPath('data.old_price', 599)->assertJsonPath('data.new_price', 600);

        $this->assertSame(2, $priceReads);
        $this->assertSame('600.00', $item->fresh()->current_price);
        $puts = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT');
        $this->assertCount(1, $puts);
        $this->assertSame(['price' => 600.0], $puts->first()[0]->data());
    }

    #[DataProvider('remoteStandardPricePayloadProvider')]
    public function test_remote_standard_price_selection_is_conservative(array $payload, ?float $expected): void
    {
        $account = $this->account();
        $item = $this->item($account);
        Http::fake([
            'https://api.mercadolibre.com/items/*/prices*' => Http::response($payload),
        ]);
        $method = new \ReflectionMethod(MeliPriceUpdateService::class, 'remoteStandardPrice');

        try {
            $resolved = $method->invoke(app(MeliPriceUpdateService::class), $account, $item);
            if ($expected === null) {
                $this->fail('La respuesta ambigua debiÃ³ bloquearse.');
            }

            $this->assertSame($expected, $resolved);
        } catch (MeliPriceUpdateException $exception) {
            if ($expected !== null) {
                throw $exception;
            }

            $this->assertSame('ambiguous_standard_price', $exception->errorCode());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/items/MLM1343389489/prices?display_version=true'));
    }

    /** @return iterable<string, array{array<string|int, mixed>, float|null}> */
    public static function remoteStandardPricePayloadProvider(): iterable
    {
        yield 'unique standard with marketplace context' => [[
            'prices' => [[
                'type' => 'standard',
                'amount' => 599,
                'conditions' => ['context_restrictions' => ['channel_marketplace']],
            ]],
        ], 599.0];

        yield 'multiple standards with exactly one marketplace price' => [[
            ['type' => 'standard', 'amount' => 580, 'conditions' => ['context_restrictions' => []]],
            ['type' => 'standard', 'amount' => 599, 'conditions' => ['context_restrictions' => ['channel_marketplace']]],
        ], 599.0];

        yield 'marketplace 420 wins over mshops 376' => [[
            ['type' => 'standard', 'amount' => 376, 'conditions' => ['context_restrictions' => ['channel_mshops']]],
            ['type' => 'standard', 'amount' => 420, 'conditions' => ['context_restrictions' => ['channel_marketplace']]],
        ], 420.0];

        yield 'general standard 420 is accepted' => [[
            ['type' => 'standard', 'amount' => 420, 'conditions' => ['context_restrictions' => []]],
        ], 420.0];

        yield 'multiple standards with two marketplace prices' => [[
            ['type' => 'standard', 'amount' => 580, 'conditions' => ['context_restrictions' => ['channel_marketplace']]],
            ['type' => 'standard', 'amount' => 599, 'conditions' => ['context_restrictions' => ['channel_marketplace']]],
        ], null];

        yield 'multiple standards without marketplace price' => [[
            ['type' => 'standard', 'amount' => 580, 'conditions' => ['context_restrictions' => []]],
            ['type' => 'standard', 'amount' => 599, 'conditions' => ['context_restrictions' => []]],
        ], null];

        yield 'no standard price' => [[
            ['type' => 'promotion', 'amount' => 499, 'conditions' => ['context_restrictions' => ['channel_marketplace']]],
        ], null];

        yield 'unique standard with non numeric amount does not use regular amount' => [[
            ['type' => 'standard', 'amount' => 'not-numeric', 'regular_amount' => 599, 'conditions' => ['context_restrictions' => []]],
        ], null];
    }

    public function test_audit_preserves_the_historical_tax_rule_snapshot_and_the_699_net_amount(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['current_price' => 698]);
        $simulation = $this->taxSimulation();
        $simulation['current_price'] = 698;
        $token = app(MeliPriceSimulationTokenService::class)->issue(
            $this->user->id,
            $account,
            $item,
            $simulation,
        )['token'];
        $this->fakeSuccessfulUpdate(698, 699);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 699,
            'listing_type_id' => 'gold_pro',
        ])->assertOk();

        $change = MeliPriceChange::query()->sole();
        $this->assertSame('101.36', $change->selling_fee);
        $this->assertSame('70.00', $change->shipping_cost);
        $this->assertSame('63.27', $change->tax_withholding);
        $this->assertSame('0.00', $change->other_charges);
        $this->assertSame('464.37', $change->estimated_net);

        $notes = json_decode((string) MeliPriceChangeBatch::query()->sole()->notes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(234.63, $notes['estimated_total_charges']);
        $this->assertNull($notes['tax_profile_snapshot']);
        $this->assertSame('historical_account_tax_rule', data_get($notes, 'tax_rule_snapshot.source'));
        $this->assertSame('high', data_get($notes, 'tax_rule_snapshot.confidence'));
        $this->assertTrue(data_get($notes, 'tax_rule_snapshot.stale'));
        $this->assertSame('last_valid_historical_rule', data_get($notes, 'tax_rule_snapshot.fallback'));
        $this->assertSame(7, data_get($notes, 'tax_rule_snapshot.sample_count'));
        $this->assertSame(16, data_get($notes, 'tax_rule_snapshot.vat_included_rate'));
        $this->assertSame(8, data_get($notes, 'tax_rule_snapshot.vat_withholding_rate'));
        $this->assertSame(2.5, data_get($notes, 'tax_rule_snapshot.income_tax_withholding_rate'));
        $this->assertSame(7, data_get($notes, 'tax_rule_snapshot.evidence.distinct_items'));
        $this->assertSame(63.27, data_get($notes, 'simulation_snapshot.taxes_total'));
        $this->assertSame(464.37, data_get($notes, 'simulation_snapshot.estimated_receivable'));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/items/MLM1343389489')
                && $request->data() === ['price' => 699.0];
        });
    }

    public function test_every_external_catalog_ownership_rule_blocks_the_endpoint_without_http(): void
    {
        Http::fake();
        $account = $this->account();
        $cases = [
            ['item' => $this->item($account, ['meli_item_id' => 'MLM-TIRE', 'sku' => 'TIRE-1']), 'table' => 'llantas', 'row' => ['MLM' => 'MLM-TIRE', 'sku' => null]],
            ['item' => $this->item($account, ['meli_item_id' => 'MLM-REPUBLISHED', 'sku' => 'TIRE-REPUBLICATION']), 'table' => 'llantas', 'row' => ['MLM' => 'MLM-OLD', 'sku' => 'TIRE-REPUBLICATION']],
            ['item' => $this->item($account, ['meli_item_id' => 'MLM-COMPOUND', 'sku' => 'COMPOUND-1']), 'table' => 'producto_compuestos', 'row' => ['MLM' => 'MLM-OLD-COMPOUND', 'sku' => 'COMPOUND-1']],
            ['item' => $this->item($account, ['meli_item_id' => 'MLM-SYSCOM', 'sku' => 'SYSCOM-1']), 'table' => 'syscom_meli_queues', 'row' => ['mlm' => 'MLM-SYSCOM']],
            ['item' => $this->item($account, ['meli_item_id' => 'MLM-AUTOPART', 'sku' => 'AUTO-1']), 'table' => 'automotive_part_meli_publications', 'row' => ['meli_item_id' => 'MLM-AUTOPART']],
        ];

        foreach ($cases as $case) {
            DB::table($case['table'])->insert($case['row']);
            $this->assertFalse(MeliPriceManagerItem::query()->managedCatalog()->whereKey($case['item']->id)->exists());
            $this->putJson(route('meli-price-manager.items.price.update', $case['item']), [
                'simulation_token' => $this->token($account, $case['item'], 1600),
                'price' => 1600,
                'listing_type_id' => 'gold_pro',
            ])->assertNotFound();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('meli_price_changes', 0);
    }

    public function test_second_catalog_barrier_blocks_a_new_exclusion_immediately_before_put(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        $token = $this->token($account, $item, 1600);
        Http::fake(function (Request $request) use ($item) {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response(['message' => 'automation_not_found'], 404);
            }

            if (str_contains($request->url(), '/prices?display_version=true')) {
                DB::table('llantas')->insert(['MLM' => $item->meli_item_id, 'sku' => null]);

                return Http::response($this->prices(1531.20));
            }

            return Http::response(['message' => 'PUT must not be sent'], 500);
        });

        $this->assertUpdateException(
            fn () => app(MeliPriceUpdateService::class)->update($this->user->id, $account, $item, $token, 1600),
            'excluded_catalog_item',
        );

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertSame('1531.20', $item->fresh()->current_price);
        $this->assertSame('failed', MeliPriceChange::query()->sole()->status);
    }

    public function test_invalid_expired_or_mismatched_simulations_never_reach_meli(): void
    {
        Http::fake();
        $account = $this->account();
        $item = $this->item($account);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => str_repeat('x', 64),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()->assertJsonPath('code', 'simulation_expired');

        $expiredToken = $this->token($account, $item, 1600);
        $this->travel(11)->minutes();
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $expiredToken,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()->assertJsonPath('code', 'simulation_expired');
        $this->travelBack();

        $otherUserToken = app(MeliPriceSimulationTokenService::class)->issue(
            User::factory()->create()->id,
            $account,
            $item,
            $this->simulation(1531.20, 1600),
        )['token'];
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $otherUserToken,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertForbidden()->assertJsonPath('code', 'simulation_user_mismatch');

        $otherAccount = $this->account(['meli_user_id' => '987654321']);
        $otherAccountItem = $this->item($otherAccount, ['meli_item_id' => 'MLM-OTHER-ACCOUNT', 'sku' => 'OTHER-ACCOUNT']);
        $otherAccountToken = $this->token($otherAccount, $otherAccountItem, 1600);
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $otherAccountToken,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertForbidden()->assertJsonPath('code', 'simulation_account_mismatch');

        $otherItem = $this->item($account, ['meli_item_id' => 'MLM-OTHER-ITEM', 'sku' => 'OTHER-ITEM']);
        $otherItemToken = $this->token($account, $otherItem, 1600);
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $otherItemToken,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertForbidden()->assertJsonPath('code', 'simulation_item_mismatch');

        $manipulatedToken = $this->token($account, $item, 1600);
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $manipulatedToken,
            'price' => 1900,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()->assertJsonPath('code', 'simulation_price_mismatch');

        $manipulatedListingTypeToken = $this->token($account, $item, 1600, 'gold_special');
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $manipulatedListingTypeToken,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()->assertJsonPath('code', 'simulation_listing_type_mismatch');

        Http::assertNothingSent();
        $this->assertDatabaseCount('meli_price_changes', 0);
    }

    public function test_remote_price_changed_since_simulation_forces_a_new_calculation(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }

            return Http::response($this->prices(1540.00));
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertConflict()->assertJsonPath('code', 'concurrent_price_change');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertFailedAudit($item, '1531.20');
    }

    public function test_active_or_tagged_automation_blocks_and_automation_check_errors_fail_closed(): void
    {
        $account = $this->account();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'MLM-AUTOMATION-ERROR')) {
                return Http::response(['message' => 'temporary'], 503);
            }

            return Http::response(['status' => 'ACTIVE']);
        });

        $tagged = $this->item($account, [
            'meli_item_id' => 'MLM-DYNAMIC-TAG',
            'sku' => 'DYNAMIC-TAG',
            'raw_item' => ['tags' => ['dynamic_standard_price']],
        ]);
        $this->putJson(route('meli-price-manager.items.price.update', $tagged), [
            'simulation_token' => $this->token($account, $tagged, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertConflict()->assertJsonPath('code', 'pricing_automation_active');
        Http::assertNothingSent();

        $active = $this->item($account, ['meli_item_id' => 'MLM-AUTOMATION', 'sku' => 'AUTOMATION']);
        $this->putJson(route('meli-price-manager.items.price.update', $active), [
            'simulation_token' => $this->token($account, $active, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertConflict()->assertJsonPath('code', 'pricing_automation_active');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

        $unknown = $this->item($account, ['meli_item_id' => 'MLM-AUTOMATION-ERROR', 'sku' => 'AUTOMATION-ERROR']);
        $this->putJson(route('meli-price-manager.items.price.update', $unknown), [
            'simulation_token' => $this->token($account, $unknown, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertStatus(502)->assertJsonPath('code', 'pricing_automation_check_failed');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $this->assertSame(5, collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'MLM-AUTOMATION-ERROR'))->count());
    }

    public function test_price_not_modifiable_in_400_or_success_warning_is_never_success(): void
    {
        $priceReads = [400 => 0, 200 => 0];
        Http::fake(function (Request $request) use (&$priceReads) {
            $status = str_contains($request->url(), '-400') ? 400 : 200;
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/prices?display_version=true')) {
                $priceReads[$status]++;

                return Http::response($this->prices(1531.20));
            }
            if ($request->method() === 'PUT') {
                return Http::response([
                    'message' => 'Cannot modify price on items with dynamic pricing',
                    'warnings' => [['code' => 'item.price.not_modifiable']],
                ], $status);
            }

            return Http::response([], 500);
        });

        foreach ([400, 200] as $status) {
            $account = $this->account(['meli_user_id' => 'USER-'.$status]);
            $item = $this->item($account, ['meli_item_id' => 'MLM-NOT-MODIFIABLE-'.$status, 'sku' => 'NOT-MOD-'.$status]);

            $this->putJson(route('meli-price-manager.items.price.update', $item), [
                'simulation_token' => $this->token($account, $item, 1600),
                'price' => 1600,
                'listing_type_id' => 'gold_pro',
            ])->assertConflict()->assertJsonPath('code', 'pricing_automation_active');
            $this->assertSame(1, $priceReads[$status]);
            $this->assertSame('1531.20', $item->fresh()->current_price);
            $this->assertSame('failed', MeliPriceChange::query()->latest('id')->firstOrFail()->status);
        }
    }

    public function test_ambiguous_prices_and_unconfirmed_200_response_are_failed_without_local_overwrite(): void
    {
        $account = $this->account();
        $ambiguous = $this->item($account, ['meli_item_id' => 'MLM-AMBIGUOUS', 'sku' => 'AMBIGUOUS']);
        $unconfirmedReads = 0;
        Http::fake(function (Request $request) use (&$unconfirmedReads) {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), 'MLM-AMBIGUOUS')) {
                return Http::response(['prices' => [
                    $this->standardPrice(1531.20),
                    $this->standardPrice(1520.00),
                    ['type' => 'promotion', 'amount' => 1400, 'conditions' => ['context_restrictions' => ['channel_marketplace']]],
                ]]);
            }
            if (str_contains($request->url(), '/prices?display_version=true')) {
                $unconfirmedReads++;

                return Http::response($this->prices(1531.20));
            }
            if ($request->method() === 'PUT') {
                return Http::response(['price' => 1600, 'warnings' => []]);
            }

            return Http::response([], 500);
        });
        $this->putJson(route('meli-price-manager.items.price.update', $ambiguous), [
            'simulation_token' => $this->token($account, $ambiguous, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertConflict()->assertJsonPath('code', 'ambiguous_standard_price');
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

        $unconfirmed = $this->item($account, ['meli_item_id' => 'MLM-UNCONFIRMED', 'sku' => 'UNCONFIRMED']);
        $this->putJson(route('meli-price-manager.items.price.update', $unconfirmed), [
            'simulation_token' => $this->token($account, $unconfirmed, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertStatus(502)->assertJsonPath('code', 'remote_price_not_updated');
        $this->assertSame('1531.20', $unconfirmed->fresh()->current_price);
        $this->assertSame('failed', MeliPriceChange::query()->latest('id')->firstOrFail()->status);
    }

    public function test_client_refreshes_401_and_retries_429_and_5xx_before_success(): void
    {
        config()->set('services.meli.client_id', 'client-id');
        config()->set('services.meli.client_secret', 'client-secret');
        $account = $this->account(['refresh_token' => 'old-refresh']);
        $item = $this->item($account);
        $priceReads = 0;
        $putCalls = 0;
        Http::fake(function (Request $request) use (&$priceReads, &$putCalls) {
            if ($request->url() === 'https://api.mercadolibre.com/oauth/token') {
                return Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 21600]);
            }
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/prices?display_version=true')) {
                $priceReads++;

                return $priceReads === 1
                    ? Http::response(['message' => 'unauthorized'], 401)
                    : Http::response($this->prices($priceReads === 2 ? 1531.20 : 1600.00));
            }
            if ($request->method() === 'PUT') {
                $putCalls++;

                return match ($putCalls) {
                    1 => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '0']),
                    2 => Http::response(['message' => 'temporary'], 503),
                    default => Http::response(['price' => 1600, 'warnings' => []]),
                };
            }

            return Http::response([], 500);
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertOk();

        $this->assertSame('new-access', $account->fresh()->access_token);
        $this->assertSame('new-refresh', $account->fresh()->refresh_token);
        $this->assertSame(3, $priceReads);
        $this->assertSame(3, $putCalls);
        Sleep::assertSequence([Sleep::for(0)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_400_5xx_and_timeout_are_audited_and_never_change_local_price(): void
    {
        $putCalls = ['400' => 0, '5xx' => 0, 'timeout' => 0];
        Http::fake(function (Request $request) use (&$putCalls) {
            $failure = str_contains($request->url(), 'MLM-400')
                ? '400'
                : (str_contains($request->url(), 'MLM-5xx') ? '5xx' : 'timeout');
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/prices?display_version=true')) {
                return Http::response($this->prices(1531.20));
            }
            if ($request->method() === 'PUT') {
                $putCalls[$failure]++;
                if ($failure === '400') {
                    return Http::response(['message' => 'invalid price access_token=APP_USR-secret'], 400);
                }
                if ($failure === '5xx') {
                    return Http::response(['message' => 'temporary server failure'], 503);
                }

                return Http::failedConnection('connection timed out')($request);
            }

            return Http::response([], 500);
        });

        foreach (['400', '5xx', 'timeout'] as $failure) {
            $account = $this->account(['meli_user_id' => 'USER-'.$failure]);
            $item = $this->item($account, ['meli_item_id' => 'MLM-'.$failure, 'sku' => 'SKU-'.$failure]);

            $response = $this->putJson(route('meli-price-manager.items.price.update', $item), [
                'simulation_token' => $this->token($account, $item, 1600),
                'price' => 1600,
                'listing_type_id' => 'gold_pro',
            ])->assertStatus(502)->assertJsonPath('code', 'meli_api_error');

            $this->assertSame($failure === '400' ? 1 : 5, $putCalls[$failure]);
            $this->assertSame('1531.20', $item->fresh()->current_price);
            $change = MeliPriceChange::query()->latest('id')->firstOrFail();
            $this->assertSame('failed', $change->status);
            $this->assertStringNotContainsString('APP_USR-secret', (string) $change->error_message);
            $this->assertStringNotContainsString('APP_USR-secret', (string) $response->json('message'));
        }
    }

    public function test_lock_prevents_double_click_and_successful_token_cannot_be_reused(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        $token = $this->token($account, $item, 1600);
        $lock = Cache::lock('meli-price-manager:price-update:'.$account->id.':'.$item->id, 60);
        $this->assertTrue($lock->get());
        $this->fakeSuccessfulUpdate(1531.20, 1600.00);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertConflict()->assertJsonPath('code', 'update_in_progress');
        Http::assertNothingSent();
        $lock->release();

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertOk();
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()->assertJsonPath('code', 'simulation_expired');

        $this->assertSame(1, collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT')->count());
        $this->assertDatabaseCount('meli_price_changes', 1);
    }

    public function test_endpoint_requires_authentication_ownership_valid_payload_and_writable_status(): void
    {
        Http::fake();
        $account = $this->account();
        $item = $this->item($account);
        auth()->logout();
        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => str_repeat('x', 64),
        ])->assertUnauthorized();

        $this->actingAs($this->user);
        foreach ([[], ['simulation_token' => 'short'], ['simulation_token' => str_repeat('x', 64), 'price' => 0]] as $payload) {
            $this->putJson(route('meli-price-manager.items.price.update', $item), $payload)->assertUnprocessable();
        }

        $foreignUser = User::factory()->create();
        $foreignAccount = MeliAccount::factory()->for($foreignUser)->create([
            'meli_user_id' => 'FOREIGN',
            'access_token' => 'foreign-token',
            'expires_at' => now()->addHour(),
        ]);
        $foreignItem = $this->item($foreignAccount, ['meli_item_id' => 'MLM-FOREIGN', 'sku' => 'FOREIGN']);
        $this->putJson(route('meli-price-manager.items.price.update', $foreignItem), [
            'simulation_token' => str_repeat('x', 64),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertNotFound();

        $closed = $this->item($account, ['meli_item_id' => 'MLM-CLOSED', 'sku' => 'CLOSED', 'status' => 'closed']);
        $this->putJson(route('meli-price-manager.items.price.update', $closed), [
            'simulation_token' => $this->token($account, $closed, 1600),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertConflict()->assertJsonPath('code', 'item_status_not_writable');

        Http::assertNothingSent();
    }

    public function test_listing_type_only_uses_one_post_and_updates_only_listing_type_after_remote_confirmation(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'current_price' => 198,
            'listing_type_id' => 'gold_special',
        ]);
        $token = $this->token($account, $item, 198, 'gold_pro');
        $listingReads = 0;
        Http::fake(function (Request $request) use (&$listingReads) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/items/MLM1343389489')) {
                $listingReads++;

                return Http::response([
                    'id' => 'MLM1343389489',
                    'listing_type_id' => $listingReads === 1 ? 'gold_special' : 'gold_pro',
                ]);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/items/MLM1343389489/listing_type')) {
                return Http::response(['id' => 'MLM1343389489', 'listing_type_id' => 'gold_pro']);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 198,
            'listing_type_id' => 'gold_pro',
        ])->assertOk()
            ->assertJsonPath('data.price_changed', false)
            ->assertJsonPath('data.listing_type_changed', true)
            ->assertJsonPath('data.old_listing_type_id', 'gold_special')
            ->assertJsonPath('data.new_listing_type_id', 'gold_pro');

        $item->refresh();
        $this->assertSame('198.00', $item->current_price);
        $this->assertSame('gold_pro', $item->listing_type_id);
        $this->assertSame(1, $listingReads);
        $posts = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertStringEndsWith('/items/MLM1343389489/listing_type', $posts->first()[0]->url());
        $this->assertSame(['id' => 'gold_pro'], $posts->first()[0]->data());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_rejected_listing_type_change_preserves_local_type_and_price(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'current_price' => 198,
            'listing_type_id' => 'gold_special',
        ]);
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => 'MLM1343389489', 'listing_type_id' => 'gold_special']);
            }
            if ($request->method() === 'POST') {
                return Http::response(['message' => 'listing type not allowed'], 400);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 198, 'gold_pro'),
            'price' => 198,
            'listing_type_id' => 'gold_pro',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'listing_type_change_rejected');

        $item->refresh();
        $this->assertSame('198.00', $item->current_price);
        $this->assertSame('gold_special', $item->listing_type_id);
        $this->assertSame('failed', MeliPriceChange::query()->sole()->status);
        $this->assertCount(1, collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_listing_type_change_with_missing_confirmation_is_not_retried_or_persisted_and_consumes_token(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'current_price' => 198,
            'listing_type_id' => 'gold_special',
            'estimated_receivable' => 150,
            'estimated_receivable_price' => 198,
            'estimated_receivable_calculated_at' => now(),
        ]);
        $token = $this->token($account, $item, 198, 'gold_pro');

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/items/MLM1343389489')) {
                return Http::response([
                    'id' => 'MLM1343389489',
                    'listing_type_id' => 'gold_special',
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/items/MLM1343389489/listing_type')) {
                return Http::response([
                    'id' => 'MLM1343389489',
                ]);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 198,
            'listing_type_id' => 'gold_pro',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'listing_type_change_unconfirmed');

        $item->refresh();
        $this->assertSame('198.00', $item->current_price);
        $this->assertSame('gold_special', $item->listing_type_id);
        $this->assertSame('150.00', $item->estimated_receivable);
        $this->assertSame('198.00', $item->estimated_receivable_price);
        $this->assertNotNull($item->estimated_receivable_calculated_at);
        $this->assertSame('failed', MeliPriceChange::query()->sole()->status);
        $this->assertFalse(Cache::has(app(MeliPriceSimulationTokenService::class)->cacheKey($token)));

        $posts = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST');
        $this->assertCount(1, $posts);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 198,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'simulation_expired');

        $this->assertCount(
            1,
            collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'),
        );
        $this->assertDatabaseCount('meli_price_changes', 1);
    }

    public function test_listing_type_change_with_mismatched_confirmation_is_not_retried_or_persisted_and_consumes_token(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'current_price' => 198,
            'listing_type_id' => 'gold_special',
            'estimated_receivable' => 150,
            'estimated_receivable_price' => 198,
            'estimated_receivable_calculated_at' => now(),
        ]);
        $token = $this->token($account, $item, 198, 'gold_pro');

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/items/MLM1343389489')) {
                return Http::response([
                    'id' => 'MLM1343389489',
                    'listing_type_id' => 'gold_special',
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/items/MLM1343389489/listing_type')) {
                return Http::response([
                    'id' => 'MLM1343389489',
                    'listing_type_id' => 'gold_special',
                ]);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 198,
            'listing_type_id' => 'gold_pro',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'listing_type_change_unconfirmed');

        $item->refresh();
        $this->assertSame('198.00', $item->current_price);
        $this->assertSame('gold_special', $item->listing_type_id);
        $this->assertSame('150.00', $item->estimated_receivable);
        $this->assertSame('198.00', $item->estimated_receivable_price);
        $this->assertNotNull($item->estimated_receivable_calculated_at);
        $this->assertSame('failed', MeliPriceChange::query()->sole()->status);
        $this->assertFalse(Cache::has(app(MeliPriceSimulationTokenService::class)->cacheKey($token)));

        $posts = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST');
        $this->assertCount(1, $posts);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $token,
            'price' => 198,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'simulation_expired');

        $this->assertCount(
            1,
            collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'),
        );
        $this->assertDatabaseCount('meli_price_changes', 1);
    }

    public function test_combined_change_is_blocked_before_any_http_or_local_modification(): void
    {
        Http::fake();
        $account = $this->account();
        $item = $this->item($account, [
            'current_price' => 198,
            'listing_type_id' => 'gold_special',
        ]);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 220, 'gold_pro'),
            'price' => 220,
            'listing_type_id' => 'gold_pro',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'combined_update_not_supported');

        $item->refresh();
        $this->assertSame('198.00', $item->current_price);
        $this->assertSame('gold_special', $item->listing_type_id);
        $this->assertDatabaseCount('meli_price_changes', 0);
        Http::assertNothingSent();
    }

    public function test_no_op_returns_message_without_http_audit_or_local_modification(): void
    {
        Http::fake();
        $account = $this->account();
        $item = $this->item($account, [
            'current_price' => 198,
            'listing_type_id' => 'gold_special',
        ]);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 198),
            'price' => 198,
            'listing_type_id' => 'gold_special',
        ])->assertOk()
            ->assertJsonPath('message', 'No hay cambios por aplicar.')
            ->assertJsonPath('data.no_op', true);

        $item->refresh();
        $this->assertSame('198.00', $item->current_price);
        $this->assertSame('gold_special', $item->listing_type_id);
        $this->assertDatabaseCount('meli_price_changes', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_listing_type_and_operations_are_rejected_without_http_or_changes(): void
    {
        Http::fake();
        $account = $this->account();
        $item = $this->item($account, ['listing_type_id' => 'gold_special']);

        $this->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => $this->token($account, $item, 1600, 'gold_pro'),
            'price' => 1600,
            'listing_type_id' => 'inventado',
        ])->assertUnprocessable()->assertJsonValidationErrors('listing_type_id');

        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $this->actingAs($operations)->putJson(route('meli-price-manager.items.price.update', $item), [
            'simulation_token' => str_repeat('x', 64),
            'price' => 1600,
            'listing_type_id' => 'gold_pro',
        ])->assertForbidden();

        $item->refresh();
        $this->assertSame('1531.20', $item->current_price);
        $this->assertSame('gold_special', $item->listing_type_id);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    public function test_real_two_pairs_use_one_put_and_never_touch_the_other_pair(): void
    {
        [$account, $a, $b, $c, $d] = $this->realLinkedPairs();
        $this->fakeLinkedPriceUpdate(420, 420, 'SYNC');

        $response = $this->putJson(route('meli-price-manager.items.price.update', $a), [
            'simulation_token' => $this->token($account, $a, 420),
            'price' => 420,
            'listing_type_id' => 'gold_pro',
        ])->assertOk()->assertJsonPath('data.price_propagated', true);

        $this->assertSame('420.00', $a->fresh()->current_price);
        $this->assertSame('420.00', $b->fresh()->current_price);
        $this->assertSame('361.00', $c->fresh()->current_price);
        $this->assertSame('361.00', $d->fresh()->current_price);
        $writes = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT');
        $this->assertCount(1, $writes);
        $this->assertStringEndsWith('/items/MLM1400075673', $writes->sole()[0]->url());
        foreach (['MLM1910233204', 'MLM1904073073', 'MLM2237586680'] as $untouched) {
            $this->assertFalse($writes->contains(fn (array $pair): bool => str_contains($pair[0]->url(), $untouched)));
        }
        foreach (['MLM1400075673', 'MLM1910233204'] as $readId) {
            Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/items/'.$readId);
        }
        $response->assertJsonPath('data.price_relations.status', 'SYNC');
    }

    public function test_linked_price_propagation_failure_persists_each_remote_price_without_putting_related_item(): void
    {
        [$account, $a, $b] = $this->realLinkedPairs();
        $this->fakeLinkedPriceUpdate(420, 399, 'SYNC');

        $this->putJson(route('meli-price-manager.items.price.update', $a), [
            'simulation_token' => $this->token($account, $a, 420),
            'price' => 420,
            'listing_type_id' => 'gold_pro',
        ])->assertOk()->assertJsonPath('data.price_propagated', false);

        $this->assertSame('420.00', $a->fresh()->current_price);
        $this->assertSame('399.00', $b->fresh()->current_price);
        $writes = collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT');
        $this->assertCount(1, $writes);
        $this->assertStringEndsWith('/items/MLM1400075673', $writes->sole()[0]->url());
    }

    public function test_non_sync_relation_updates_only_selected_item_and_keeps_detected_relation(): void
    {
        [$account, $a, $b] = $this->realLinkedPairs();
        $this->fakeLinkedPriceUpdate(420, 399, 'OUT_OF_SYNC');

        $this->putJson(route('meli-price-manager.items.price.update', $a), [
            'simulation_token' => $this->token($account, $a, 420),
            'price' => 420,
            'listing_type_id' => 'gold_pro',
        ])->assertOk()->assertJsonPath('data.price_relations.linked', false)
            ->assertJsonPath('data.price_relations.detected', true);

        $this->assertSame('420.00', $a->fresh()->current_price);
        $this->assertSame('399.00', $b->fresh()->current_price);
        $this->assertSame('OUT_OF_SYNC', $a->fresh()->price_sync_status);
        $this->assertSame(['MLM1910233204'], $a->fresh()->price_relation_ids);
        $this->assertCount(1, collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'PUT'));
    }

    /** @return array{MeliAccount, MeliPriceManagerItem, MeliPriceManagerItem, MeliPriceManagerItem, MeliPriceManagerItem} */
    private function realLinkedPairs(): array
    {
        $account = $this->account();
        $make = fn (string $id, float $price, string $relation): MeliPriceManagerItem => $this->item($account, [
            'meli_item_id' => $id,
            'current_price' => $price,
            'user_product_id' => 'MLMU881417340',
            'inventory_id' => 'ZIIL82640',
            'price_sync_status' => 'SYNC',
            'price_relation_ids' => [$relation],
            'raw_item' => ['item_relations' => [['id' => $relation]]],
        ]);

        return [$account,
            $make('MLM1400075673', 399, 'MLM1910233204'),
            $make('MLM1910233204', 399, 'MLM1400075673'),
            $make('MLM1904073073', 361, 'MLM2237586680'),
            $make('MLM2237586680', 361, 'MLM1904073073'),
        ];
    }

    private function fakeLinkedPriceUpdate(float $aPrice, float $bPrice, string $status): void
    {
        $aPriceReads = 0;
        Http::fake(function (Request $request) use ($aPrice, $bPrice, $status, &$aPriceReads) {
            $url = $request->url();
            if (str_contains($url, '/pricing-automation/')) return Http::response(['message' => 'not found'], 404);
            if (str_contains($url, '/public/buybox/sync/')) return Http::response(['status' => $status, 'relations' => [['id' => 'MLM1910233204']]]);
            if (str_contains($url, '/items/MLM1400075673/prices')) {
                $aPriceReads++;
                return Http::response($this->prices($aPriceReads === 1 ? 399 : $aPrice));
            }
            if (str_contains($url, '/items/MLM1910233204/prices')) return Http::response($this->prices($bPrice));
            if ($request->method() === 'PUT') return Http::response(['price' => 420]);
            if (parse_url($url, PHP_URL_PATH) === '/items/MLM1400075673') return Http::response(['id' => 'MLM1400075673', 'price' => $aPrice, 'item_relations' => [['id' => 'MLM1910233204']]]);
            if (parse_url($url, PHP_URL_PATH) === '/items/MLM1910233204') return Http::response(['id' => 'MLM1910233204', 'price' => $bPrice, 'item_relations' => [['id' => 'MLM1400075673']]]);
            return Http::response(['message' => 'unexpected'], 500);
        });
    }

    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->for($this->user)->create([
            'meli_user_id' => '123456789-'.fake()->unique()->numerify('####'),
            'access_token' => 'test-access-token',
            'expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function item(MeliAccount $account, array $overrides = []): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create([
            'meli_item_id' => 'MLM1343389489',
            'title' => 'Alfaparf Yellow Liss Mascarilla 500ml',
            'sku' => 'SKU-PRICE-'.fake()->unique()->numerify('####'),
            'category_id' => self::FOCUSED_CATEGORY_ID,
            'listing_type_id' => 'gold_pro',
            'currency_id' => 'MXN',
            'current_price' => '1531.20',
            'status' => 'active',
            'classification_status' => 'categorized',
            'raw_item' => ['condition' => 'new', 'tags' => []],
            ...$overrides,
        ]);
    }

    private function token(
        MeliAccount $account,
        MeliPriceManagerItem $item,
        float $proposedPrice,
        ?string $proposedListingTypeId = null,
    ): string
    {
        return app(MeliPriceSimulationTokenService::class)->issue(
            $this->user->id,
            $account,
            $item,
            $this->simulation(
                (float) $item->current_price,
                $proposedPrice,
                (string) $item->listing_type_id,
                $proposedListingTypeId ?? (string) $item->listing_type_id,
            ),
        )['token'];
    }

    /** @return array<string, mixed> */
    private function simulation(
        float $currentPrice,
        float $proposedPrice,
        string $currentListingTypeId = 'gold_pro',
        string $proposedListingTypeId = 'gold_pro',
    ): array
    {
        return [
            'meli_item_id' => 'MLM1343389489',
            'current_price' => $currentPrice,
            'proposed_price' => $proposedPrice,
            'current_listing_type_id' => $currentListingTypeId,
            'current_listing_type_name' => MeliPriceManagerItem::listingTypeName($currentListingTypeId),
            'listing_type_id' => $proposedListingTypeId,
            'listing_type_name' => MeliPriceManagerItem::listingTypeName($proposedListingTypeId),
            'sale_fee' => 248.00,
            'shipping_cost' => 74.50,
            'listing_fee' => 7.25,
            'charges' => [
                'sale_fee' => [
                    'amount' => 248.00,
                    'percentage' => 15.5,
                    'meli_percentage' => 14,
                    'fixed_fee' => 0,
                    'financing_add_on_fee' => 1.5,
                    'gross_amount' => 248.00,
                ],
                'listing_fee' => [
                    'available' => true,
                    'amount' => 7.25,
                    'fixed_fee' => 7.25,
                    'gross_amount' => 7.25,
                ],
                'shipping' => [
                    'available' => true,
                    'seller_cost' => 74.50,
                    'original_cost' => 149.00,
                    'discount_rate' => 0.5,
                    'discount_amount' => 74.50,
                    'billable_weight' => 733,
                ],
                'taxes' => [
                    'available' => false,
                    'amount' => null,
                    'iva' => null,
                    'isr' => null,
                    'withholdings' => null,
                    'other' => null,
                    'message' => 'Se determinan al procesarse la venta.',
                ],
                'other' => [],
            ],
            'confirmed_charges_total' => 329.75,
            'total_charges' => 329.75,
            'estimated_receivable' => 1270.25,
            'estimated_receivable_is_final' => false,
            'calculated_at' => now()->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function taxSimulation(): array
    {
        return [
            'meli_item_id' => 'MLM1343389489',
            'current_price' => 699,
            'proposed_price' => 699,
            'current_listing_type_id' => 'gold_pro',
            'current_listing_type_name' => 'Premium',
            'listing_type_id' => 'gold_pro',
            'listing_type_name' => 'Premium',
            'sale_fee' => 101.36,
            'shipping_cost' => 70,
            'listing_fee' => 0,
            'charges' => [
                'sale_fee' => ['amount' => 101.36, 'gross_amount' => 150],
                'listing_fee' => ['available' => true, 'amount' => 0],
                'shipping' => ['available' => true, 'seller_cost' => 70, 'original_cost' => 140],
                'taxes' => [
                    'available' => true,
                    'source' => 'historical_account_tax_rule',
                    'confidence' => 'high',
                    'stale' => true,
                    'fallback' => 'last_valid_historical_rule',
                    'sample_count' => 7,
                    'taxable_base' => 602.59,
                    'vat' => ['included_rate' => 16, 'withholding_rate' => 8, 'amount' => 48.21],
                    'income_tax' => ['withholding_rate' => 2.5, 'amount' => 15.06],
                    'amount' => 63.27,
                    'profile' => null,
                    'rule' => [
                        'source' => 'historical_account_tax_rule',
                        'confidence' => 'high',
                        'stale' => true,
                        'fallback' => 'last_valid_historical_rule',
                        'sample_count' => 7,
                        'vat_included_rate' => 16,
                        'vat_withholding_rate' => 8,
                        'income_tax_withholding_rate' => 2.5,
                        'first_observed_at' => '2026-08-10T18:00:00.000000Z',
                        'last_observed_at' => '2026-08-16T18:00:00.000000Z',
                        'evidence' => ['distinct_items' => 7, 'money_tolerance_cents' => 1],
                    ],
                ],
                'other' => [],
            ],
            'meli_charges_total' => 171.36,
            'confirmed_charges_total' => 171.36,
            'taxes_total' => 63.27,
            'total_charges' => 234.63,
            'estimated_receivable' => 464.37,
            'estimated_receivable_is_final' => false,
            'calculated_at' => now()->toISOString(),
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function prices(float $amount): array
    {
        return ['prices' => [$this->standardPrice($amount)]];
    }

    /** @return array<string, mixed> */
    private function standardPrice(float $amount): array
    {
        return [
            'type' => 'standard',
            'amount' => $amount,
            'conditions' => ['context_restrictions' => ['channel_marketplace']],
        ];
    }

    private function fakeSuccessfulUpdate(float $oldPrice, float $confirmedPrice): void
    {
        $priceReads = 0;
        Http::fake(function (Request $request) use ($oldPrice, $confirmedPrice, &$priceReads) {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response(['message' => 'automation_not_found'], 404);
            }
            if (str_contains($request->url(), '/prices?display_version=true')) {
                $priceReads++;

                return Http::response($this->prices($priceReads === 1 ? $oldPrice : $confirmedPrice));
            }
            if ($request->method() === 'PUT') {
                return Http::response(['price' => $request['price'], 'warnings' => []]);
            }

            return Http::response(['message' => 'Unexpected test request'], 500);
        });
    }

    private function assertUpdateException(callable $callback, string $errorCode): void
    {
        try {
            $callback();
            $this->fail('Se esperaba que la actualizaciÃ³n fuera rechazada.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame($errorCode, $exception->errorCode());
        }
    }

    private function assertFailedAudit(MeliPriceManagerItem $item, string $expectedLocalPrice): void
    {
        $this->assertSame($expectedLocalPrice, $item->fresh()->current_price);
        $this->assertSame('failed', MeliPriceChangeBatch::query()->latest('id')->firstOrFail()->status);
        $this->assertSame('failed', MeliPriceChange::query()->latest('id')->firstOrFail()->status);
    }
}
