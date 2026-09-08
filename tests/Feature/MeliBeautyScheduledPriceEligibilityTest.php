<?php

namespace Tests\Feature;

use App\Jobs\ProcessMeliBeautyScheduledPriceJob;
use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeliBeautyScheduledPriceEligibilityTest extends TestCase
{
    private object $foundationMigration;

    private User $user;

    private MeliAccount $account;

    private MeliBrandGroup $brand;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 12:00', 'America/Mexico_City'));
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 32)->default('admin');
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
        });
        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('MLM')->nullable();
            $table->string('sku')->nullable();
        });
        Schema::create('producto_compuestos', function (Blueprint $table): void {
            $table->id();
            $table->string('MLM')->nullable();
            $table->string('sku')->nullable();
        });
        Schema::create('syscom_meli_queues', function (Blueprint $table): void {
            $table->id();
            $table->string('mlm')->nullable();
        });
        Schema::create('automotive_part_meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('meli_item_id')->nullable();
        });
        Schema::create('meli_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('category_id')->unique();
            $table->string('root_category_id')->nullable();
            $table->json('path_from_root')->nullable();
        });

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();
        (require database_path('migrations/2026_08_29_000001_add_linked_publication_fields_to_meli_price_manager_items.php'))->up();
        $this->requirementMigrations();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->account = MeliAccount::factory()->for($this->user)->create();
        $this->brand = MeliBrandGroup::factory()->create(['active' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('meli_beauty_scheduled_discount_items');
        (require database_path('migrations/2026_08_29_000001_add_linked_publication_fields_to_meli_price_manager_items.php'))->down();
        require database_path('migrations/2026_09_07_000002_create_meli_scheduled_price_states_table.php');
        Schema::dropIfExists('meli_scheduled_price_states');
        Schema::dropIfExists('meli_beauty_scheduled_discounts');
        $this->foundationMigration->down();
        foreach (['automotive_part_meli_publications', 'syscom_meli_queues', 'producto_compuestos', 'llantas', 'meli_categories', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_multiple_promotions_for_the_same_account_and_brand_are_allowed(): void
    {
        $item = $this->beautyItem('MLM-BEAUTY-VALID');
        $rule = new MeliBeautyScheduledDiscount([
            'meli_account_id' => $this->account->id, 'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10, 'starts_on' => '2026-09-08', 'ends_on' => '2026-09-12',
            'starts_at' => '20:00', 'ends_at' => '06:00',
            'timezone' => 'America/Mexico_City', 'active' => true,
        ]);
        $this->assertTrue(app(MeliBeautyScheduledPriceService::class)->ruleHasEligibleItems($rule));
        MeliBeautyScheduledDiscount::query()->create($rule->getAttributes());
        MeliBeautyScheduledDiscount::query()->create($rule->getAttributes());
        $this->assertDatabaseCount('meli_beauty_scheduled_discounts', 2);
    }

    public function test_admin_endpoints_validate_percentage_hours_and_create_only_a_beauty_rule(): void
    {
        $item = $this->beautyItem('MLM-ENDPOINT-BEAUTY');
        $this->actingAs($this->user);
        $payload = [
            'meli_account_id' => $this->account->id,
            'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10,
            'starts_on' => '08/09/2026',
            'ends_on' => '12/09/2026',
            'starts_at' => '20:00',
            'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo',
            'active' => true,
            'items' => [['price_manager_item_id' => $item->id, 'discount_percentage' => 10]],
        ];

        $this->post(route('meli-price-manager.scheduled-discounts.store'), [...$payload, 'discount_percentage' => 0])
            ->assertSessionHasErrors('discount_percentage');
        $this->post(route('meli-price-manager.scheduled-discounts.store'), [...$payload, 'discount_percentage' => 100])
            ->assertSessionHasErrors('discount_percentage');
        $this->post(route('meli-price-manager.scheduled-discounts.store'), [...$payload, 'ends_at' => '20:00'])
            ->assertSessionHasErrors('ends_at');

        $this->post(route('meli-price-manager.scheduled-discounts.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('meli_beauty_scheduled_discounts', [
            'meli_account_id' => $this->account->id,
            'brand_group_id' => $this->brand->id,
            'discount_percentage' => '10.00',
        ]);
        $this->assertDatabaseHas('meli_beauty_scheduled_discount_items', [
            'price_manager_item_id' => $item->id,
            'discount_percentage' => '10.00',
        ]);

        $this->post(route('meli-price-manager.scheduled-discounts.store'), $payload)
            ->assertSessionHasErrors('items');
        $this->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MeliPriceManager/ScheduledDiscounts')
                ->has('rules', 1)
                ->has('brandOptions', 1)
                ->where('rules.0.selected_items_count', 1));
    }

    public function test_scheduled_discount_page_is_admin_only_and_exposes_only_eligible_beauty_brands(): void
    {
        $this->beautyItem('MLM-PAGE-BEAUTY');
        $supplementBrand = MeliBrandGroup::factory()->create(['active' => true]);
        $supplement = MeliPriceManagerItem::factory()
            ->for($this->account, 'meliAccount')
            ->for($supplementBrand, 'brandGroup')
            ->create([
                'meli_item_id' => 'MLM-PAGE-SUPPLEMENT',
                'category_id' => 'MLM-SUPPLEMENT-CATEGORY',
                'classification_status' => 'categorized',
            ]);
        DB::table('meli_categories')->insert([
            'category_id' => $supplement->category_id,
            'root_category_id' => 'MLM-SUPPLEMENTS',
        ]);

        $this->actingAs($this->user)
            ->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MeliPriceManager/ScheduledDiscounts')
                ->has('brandOptions', 1)
                ->where('automationEnabled', false)
                ->where('schedulerEnabled', false)
                ->where('brandOptions.0.id', $this->brand->id));

        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $this->actingAs($operations)
            ->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertForbidden();
    }

    public function test_publication_endpoint_filters_searches_sorts_kits_and_keeps_accounts_isolated(): void
    {
        $single = $this->beautyItem('MLM-SINGLE');
        $single->forceFill(['title' => 'Aceite individual', 'sku' => 'SINGLE-1', 'current_price' => 100])->save();
        $kit = $this->beautyItem('MLM-KIT');
        $kit->forceFill([
            'title' => 'Tratamiento profesional',
            'sku' => 'PACK-3',
            'current_price' => 300,
            'raw_attributes' => [['id' => 'UNITS_PER_PACK', 'value_name' => '3']],
        ])->save();

        $this->actingAs($this->user)
            ->getJson(route('meli-price-manager.scheduled-discounts.index', [
                'catalog' => 1,
                'meli_account_id' => $this->account->id,
                'brand_group_id' => $this->brand->id,
                'sort' => 'kits_first',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.meli_item_id', 'MLM-KIT')
            ->assertJsonPath('data.0.is_kit', true)
            ->assertJsonPath('data.1.meli_item_id', 'MLM-SINGLE');

        $this->getJson(route('meli-price-manager.scheduled-discounts.index', [
            'catalog' => 1,
            'meli_account_id' => $this->account->id,
            'brand_group_id' => $this->brand->id,
            'search' => 'SINGLE-1',
        ]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $single->id);

        foreach ([['price_asc', 'MLM-SINGLE'], ['price_desc', 'MLM-KIT']] as [$sort, $expected]) {
            $this->getJson(route('meli-price-manager.scheduled-discounts.index', [
                'catalog' => 1,
                'meli_account_id' => $this->account->id,
                'brand_group_id' => $this->brand->id,
                'sort' => $sort,
            ]))->assertOk()->assertJsonPath('data.0.meli_item_id', $expected);
        }
    }

    public function test_dates_item_percentages_and_overlapping_item_windows_are_validated(): void
    {
        $item = $this->beautyItem('MLM-CONFLICT');
        $this->actingAs($this->user);
        $payload = [
            'meli_account_id' => $this->account->id,
            'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10,
            'starts_on' => '08/09/2028',
            'ends_on' => '12/09/2028',
            'starts_at' => '20:00',
            'ends_at' => '06:00',
            'timezone' => 'UTC',
            'active' => true,
            'items' => [['price_manager_item_id' => $item->id, 'discount_percentage' => 10]],
        ];

        $this->post(route('meli-price-manager.scheduled-discounts.store'), [
            ...$payload, 'ends_on' => '08/09/2028',
        ])->assertSessionHasErrors('ends_on');
        $this->post(route('meli-price-manager.scheduled-discounts.store'), [
            ...$payload,
            'items' => [['price_manager_item_id' => $item->id, 'discount_percentage' => 10.123]],
        ])->assertSessionHasErrors('items.0.discount_percentage');

        $this->post(route('meli-price-manager.scheduled-discounts.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('meli_beauty_scheduled_discounts', [
            'timezone' => 'America/Mexico_City',
            'starts_on' => '2028-09-08',
            'ends_on' => '2028-09-12',
        ]);
        $this->post(route('meli-price-manager.scheduled-discounts.store'), $payload)
            ->assertSessionHasErrors('items');

        $this->post(route('meli-price-manager.scheduled-discounts.store'), [
            ...$payload,
            'starts_on' => '13/09/2028',
            'ends_on' => '14/09/2028',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meli_beauty_scheduled_discounts', 2);
    }

    public function test_non_beauty_catalogs_and_uncategorized_or_unassigned_items_are_excluded(): void
    {
        $service = app(MeliBeautyScheduledPriceService::class);
        $rule = $this->rule();
        $this->beautyItem('MLM-BEAUTY-BASE');
        $this->assertTrue($service->ruleHasEligibleItems($rule));

        foreach (['MLM-SYSCOM' => 'syscom_meli_queues', 'MLM-TIRE' => 'llantas', 'MLM-COMPOUND' => 'producto_compuestos', 'MLM-AUTO' => 'automotive_part_meli_publications'] as $itemId => $table) {
            $item = $this->beautyItem($itemId);
            $column = $table === 'syscom_meli_queues'
                ? 'mlm'
                : (in_array($table, ['llantas', 'producto_compuestos'], true) ? 'MLM' : 'meli_item_id');
            DB::table($table)->insert([$column => $item->meli_item_id]);
            $this->assertFalse($service->eligibleItemsQuery($rule)->whereKey($item)->exists());
        }

        $supplement = $this->beautyItem('MLM-SUPPLEMENT');
        DB::table('meli_categories')->where('category_id', $supplement->category_id)->update(['root_category_id' => 'MLM-SUPPLEMENTS']);
        $this->assertFalse($service->eligibleItemsQuery($rule)->whereKey($supplement)->exists());

        $uncategorized = $this->beautyItem('MLM-UNCATEGORIZED');
        $uncategorized->forceFill(['classification_status' => 'uncategorized'])->save();
        $this->assertFalse($service->eligibleItemsQuery($rule)->whereKey($uncategorized)->exists());

        $withoutBrand = $this->beautyItem('MLM-NO-BRAND');
        $withoutBrand->forceFill(['brand_group_id' => null])->save();
        $this->assertFalse($service->eligibleItemsQuery($rule)->whereKey($withoutBrand)->exists());
    }

    public function test_dry_run_uses_price_discount_and_apply_confirms_prices_and_sale_price_before_state(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-DRY-RUN');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);

        $promotionStarted = false;
        Http::fake(function (Request $request) use (&$promotionStarted): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                if (strtolower($request->method()) === 'post') {
                    $promotionStarted = true;

                    return Http::response(['status' => 'started'], 201);
                }

                return Http::response([$this->priceDiscount($promotionStarted ? 'started' : 'candidate', 2000, 360, 1800, 1700)], 200);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => array_values(array_filter([
                    $this->standardPrice(2000),
                    $promotionStarted ? $this->promotionPrice(1800, 2000) : null,
                ]))]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response($promotionStarted
                    ? ['amount' => 1800, 'regular_amount' => 2000, 'metadata' => ['promotion_id' => 'PRICE-DISCOUNT']]
                    : ['amount' => 2000, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $service = app(MeliBeautyScheduledPriceService::class);
        $dryRun = $service->processRule($rule->fresh(), null, true);
        $this->assertSame(1, $dryRun['apply']);
        $this->assertSame(0, $dryRun['failed']);
        $this->assertSame('MLM-DRY-RUN', $dryRun['details'][0]['meli_item_id']);
        $this->assertSame(2000.0, $dryRun['details'][0]['standard_base']);
        $this->assertSame(2000.0, $dryRun['details'][0]['promotion_original']);
        $this->assertSame(1800.0, $dryRun['details'][0]['desired_target']);
        $this->assertSame('price_discount', $dryRun['details'][0]['strategy']);
        $this->assertSame('apply', $dryRun['details'][0]['action']);
        Http::assertNotSent(fn (Request $request): bool => in_array(strtolower($request->method()), ['post', 'put', 'delete'], true));
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);

        $apply = $service->processRule($rule->fresh(), $item->meli_item_id, false);
        $this->assertSame(1, $apply['success'], json_encode($apply, JSON_PRETTY_PRINT));
        $this->assertSame('active', $item->fresh()->scheduledPriceState?->status);
        $this->assertSame('2000.00', $item->fresh()->current_price);
        $this->assertDatabaseHas('meli_price_changes', ['source' => 'scheduled_beauty', 'scheduled_action' => 'apply']);
        Http::assertSent(fn (Request $request): bool => strtolower($request->method()) === 'post'
            && str_contains($request->url(), '/seller-promotions/items/MLM-DRY-RUN')
            && $request['promotion_type'] === 'PRICE_DISCOUNT'
            && (float) $request['deal_price'] === 1800.0);
        Http::assertNotSent(fn (Request $request): bool => strtolower($request->method()) === 'put');
    }

    public function test_disabled_rule_with_active_state_restores_and_feature_flag_blocks_apply_command(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-RESTORE');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'active' => false,
        ]);
        $item->scheduledPriceState()->create([
            'meli_beauty_scheduled_discount_id' => $rule->id,
            'base_price' => 2000,
            'promotional_price' => 1800,
            'last_confirmed_remote_price' => 1800,
            'status' => 'active',
        ]);
        $promotionStarted = true;
        Http::fake(function (Request $request) use (&$promotionStarted): mixed {
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                if (strtolower($request->method()) === 'delete') {
                    $promotionStarted = false;

                    return Http::response([], 200);
                }

                return Http::response([$this->priceDiscount($promotionStarted ? 'started' : 'candidate', 2000, 360, 1800, 1700)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => array_values(array_filter([
                    $this->standardPrice(2000),
                    $promotionStarted ? $this->promotionPrice(1800, 2000) : null,
                ]))]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response($promotionStarted
                    ? ['amount' => 1800, 'regular_amount' => 2000, 'metadata' => ['promotion_id' => 'PRICE-DISCOUNT']]
                    : ['amount' => 2000, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id);
        $this->assertSame(1, $summary['success']);
        $this->assertSame('restored', $item->fresh()->scheduledPriceState?->status);
        Http::assertSent(fn (Request $request): bool => strtolower($request->method()) === 'delete'
            && str_contains($request->url(), 'promotion_type=PRICE_DISCOUNT'));
        Http::assertNotSent(fn (Request $request): bool => strtolower($request->method()) === 'put');

        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', false);
        $this->assertSame(1, Artisan::call('meli:beauty-scheduled-prices', ['--apply' => true, '--discount' => $rule->id]));
    }

    public function test_automation_is_blocked_and_no_state_is_created(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-AUTOMATION-BLOCKED');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response(['status' => 'active']);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(2000)]]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id, true);
        $this->assertSame(1, $summary['blocked']);
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        Http::assertNotSent(fn (Request $request): bool => strtolower($request->method()) === 'put');
    }

    public function test_runtime_blocks_overlapping_promotions_for_the_same_selected_item_without_remote_calls(): void
    {
        $item = $this->beautyItem('MLM-RUNTIME-CONFLICT');
        $attributes = [
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ];
        $first = MeliBeautyScheduledDiscount::query()->create($attributes);
        $second = MeliBeautyScheduledDiscount::query()->create($attributes);
        $this->selectItem($first, $item);
        $this->selectItem($second, $item);
        Http::fake();

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($first, $item->meli_item_id, true);

        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(['scheduled_promotion_conflict'], $summary['details'][0]['reasons']);
        Http::assertNothingSent();
    }

    public function test_removing_a_selected_item_restores_its_confirmed_active_state(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-REMOVED-SELECTION');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);
        $item->scheduledPriceState()->create([
            'meli_beauty_scheduled_discount_id' => $rule->id,
            'base_price' => 2000,
            'promotional_price' => 1800,
            'last_confirmed_remote_price' => 1800,
            'status' => 'active',
        ]);
        $rule->scheduledItems()->delete();
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                return Http::response([$this->priceDiscount('candidate', 2000, 360, 1800, 1700)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(2000)]]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(['amount' => 2000, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id);

        $this->assertSame(1, $summary['success']);
        $this->assertSame('restored', $item->fresh()->scheduledPriceState?->status);
        Http::assertNotSent(fn (Request $request): bool => in_array(strtolower($request->method()), ['post', 'put', 'delete'], true));
    }

    public function test_manual_apply_requires_explicit_scope_when_scheduler_is_disabled(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        Queue::fake();

        $this->assertSame(2, Artisan::call('meli:beauty-scheduled-prices', ['--apply' => true]));
        Queue::assertNothingPushed();
    }

    public function test_apply_and_job_are_blocked_by_promotional_prices_feature_flag(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false);
        Queue::fake();
        $rule = MeliBeautyScheduledDiscount::query()->create($this->rule()->getAttributes());

        $this->assertSame(1, Artisan::call('meli:beauty-scheduled-prices', [
            '--apply' => true,
            '--discount' => $rule->id,
        ]));
        Queue::assertNothingPushed();

        $service = $this->mock(MeliBeautyScheduledPriceService::class);
        $service->shouldNotReceive('processRule');
        (new ProcessMeliBeautyScheduledPriceJob($rule->id))->handle($service);
    }

    public function test_manual_item_apply_is_allowed_when_scheduler_is_disabled(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        Queue::fake();
        $rule = MeliBeautyScheduledDiscount::query()->create($this->rule()->getAttributes());

        $this->assertSame(0, Artisan::call('meli:beauty-scheduled-prices', [
            '--apply' => true,
            '--item' => 'MLM-CONTROLLED',
            '--discount' => $rule->id,
        ]));
        Queue::assertPushed(ProcessMeliBeautyScheduledPriceJob::class, fn ($job): bool => $job->discountId === $rule->id && $job->meliItemId === 'MLM-CONTROLLED');
    }

    public function test_dry_run_works_while_both_flags_are_disabled(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        MeliBeautyScheduledDiscount::query()->create($this->rule()->getAttributes());
        Queue::fake();
        Http::fake();

        $this->assertSame(0, Artisan::call('meli:beauty-scheduled-prices', ['--dry-run' => true]));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_global_apply_always_requires_explicit_all_even_when_scheduler_is_enabled(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', true);
        Queue::fake();

        $this->assertSame(2, Artisan::call('meli:beauty-scheduled-prices', ['--apply' => true]));
        Queue::assertNothingPushed();
    }

    public function test_explicit_all_queues_global_apply(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        Queue::fake();
        $rule = MeliBeautyScheduledDiscount::query()->create($this->rule()->getAttributes());

        $this->assertSame(0, Artisan::call('meli:beauty-scheduled-prices', ['--apply' => true, '--all' => true]));
        Queue::assertPushed(ProcessMeliBeautyScheduledPriceJob::class, fn ($job): bool => $job->discountId === $rule->id && $job->meliItemId === null);
    }

    public function test_job_never_processes_when_engine_is_disabled(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', true);
        $service = $this->mock(MeliBeautyScheduledPriceService::class);
        $service->shouldNotReceive('processRule');

        (new ProcessMeliBeautyScheduledPriceJob(999))->handle($service);
        $this->addToAssertionCount(1);
    }

    public function test_job_processes_with_engine_enabled_and_scheduler_disabled(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        $rule = MeliBeautyScheduledDiscount::query()->create($this->rule()->getAttributes());
        $service = $this->mock(MeliBeautyScheduledPriceService::class);
        $service->shouldReceive('processRule')->once()->withArgs(fn ($actualRule, $item): bool => $actualRule->is($rule) && $item === 'MLM-JOB')->andReturn(['processed' => 0]);

        (new ProcessMeliBeautyScheduledPriceJob($rule->id, 'MLM-JOB'))->handle($service);
    }

    public function test_ui_exposes_effective_engine_and_scheduler_states(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        $this->actingAs($this->user)->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('automationEnabled', true)
                ->where('promotionalPricesEnabled', true)
                ->where('schedulerEnabled', false));

        config()->set('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', true);
        $this->actingAs($this->user)->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('automationEnabled', false)
                ->where('promotionalPricesEnabled', false)
                ->where('schedulerEnabled', false));
    }

    public function test_standard_price_change_is_blocked_when_promotion_base_does_not_match_state(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-MANUAL-REBASING');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);
        $item->scheduledPriceState()->create([
            'meli_beauty_scheduled_discount_id' => $rule->id,
            'base_price' => 2000,
            'promotional_price' => 1800,
            'last_confirmed_remote_price' => 1800,
            'status' => 'active',
        ]);
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                return Http::response([$this->priceDiscount('candidate', 2300, 400, 2000, 1900)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(2300)]]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(['amount' => 2300, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $service = app(MeliBeautyScheduledPriceService::class);
        $result = $service->processRule($rule, $item->meli_item_id);

        $this->assertSame(1, $result['blocked']);
        $this->assertSame('blocked', $result['details'][0]['action']);
        $this->assertContains('promotion_base_mismatch', $result['details'][0]['reasons']);
        $this->assertSame('2000.00', $item->fresh()->scheduledPriceState?->base_price);
        Http::assertNotSent(fn (Request $request): bool => in_array(strtolower($request->method()), ['post', 'put', 'delete'], true));
    }

    public function test_scheduled_path_respects_the_same_lock_as_manual_price_updates(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-SCHEDULED-LOCK');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);
        $lock = Cache::lock('meli-price-manager:price-update:'.$this->account->id.':'.$item->id, 60);
        $this->assertTrue($lock->get());
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                return Http::response([$this->priceDiscount('candidate', 2000, 360, 1800, 1700)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(2000)]]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(['amount' => 2000, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 404);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id, false);

        $this->assertSame(1, $summary['blocked']);
        Http::assertNotSent(fn (Request $request): bool => in_array(strtolower($request->method()), ['post', 'put', 'delete'], true));
        $lock->release();
    }

    public function test_linked_publications_require_independent_price_discount_confirmation(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $first = $this->beautyItem('MLM-LINKED-A');
        $second = $this->beautyItem('MLM-LINKED-B');
        $this->beautyItem('MLM-NOT-SELECTED');
        $first->forceFill([
            'raw_item' => ['item_relations' => [['id' => $second->meli_item_id]]],
            'price_sync_status' => 'SYNC',
            'price_relation_ids' => [$second->meli_item_id],
        ])->save();
        $second->forceFill([
            'raw_item' => ['item_relations' => [['id' => $first->meli_item_id]]],
            'price_sync_status' => 'SYNC',
            'price_relation_ids' => [$first->meli_item_id],
        ])->save();
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $first);
        $this->selectItem($rule, $second, 20);
        $started = [];
        Http::fake(function (Request $request) use (&$started): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            preg_match('/items\/([^\/?]+)/', $request->url(), $matches);
            $itemId = $matches[1] ?? 'unknown';
            $target = $itemId === 'MLM-LINKED-B' ? 1600 : 1800;
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                if (strtolower($request->method()) === 'post') {
                    $started[$itemId] = true;

                    return Http::response(['status' => 'started'], 201);
                }

                return Http::response([$this->priceDiscount(($started[$itemId] ?? false) ? 'started' : 'candidate', 2000, 360, 1800, $target)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => array_values(array_filter([
                    $this->standardPrice(2000),
                    ($started[$itemId] ?? false) ? $this->promotionPrice($target, 2000) : null,
                ]))]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(($started[$itemId] ?? false)
                    ? ['amount' => $target, 'regular_amount' => 2000, 'metadata' => ['promotion_id' => 'PRICE-DISCOUNT']]
                    : ['amount' => 2000, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule);

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(2, $summary['success']);
        $this->assertSame(2, collect(Http::recorded())->filter(fn (array $pair): bool => strtolower($pair[0]->method()) === 'post')->count());
        $postedTargets = collect(Http::recorded())
            ->filter(fn (array $pair): bool => strtolower($pair[0]->method()) === 'post')
            ->map(fn (array $pair): float => (float) $pair[0]['deal_price'])
            ->sort()->values()->all();
        $this->assertSame([1600.0, 1800.0], $postedTargets);
        $this->assertSame(2, $rule->fresh()->priceStates()->count());
        Http::assertNotSent(fn (Request $request): bool => strtolower($request->method()) === 'put');
    }

    public function test_production_evidence_blocks_base_mismatch_and_target_outside_range_in_dry_run(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false);
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM3339232016');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                return Http::response([$this->priceDiscount('candidate', 180, 36, 162, 153)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(200)]]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(['amount' => 200, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id, true);
        $detail = $summary['details'][0];

        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(200.0, $detail['standard_base']);
        $this->assertSame(180.0, $detail['promotion_original']);
        $this->assertSame(10.0, $detail['configured_discount']);
        $this->assertSame(180.0, $detail['desired_target']);
        $this->assertSame(36.0, $detail['allowed_min']);
        $this->assertSame(162.0, $detail['allowed_max']);
        $this->assertSame(153.0, $detail['suggested']);
        $this->assertSame('price_discount', $detail['strategy']);
        $this->assertSame('blocked', $detail['action']);
        $this->assertSame('promotion_base_mismatch,target_outside_promotion_range', $detail['reason']);
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        Http::assertNotSent(fn (Request $request): bool => in_array(strtolower($request->method()), ['post', 'put', 'delete'], true));
    }

    public function test_apply_does_not_mark_active_when_sale_price_does_not_confirm_strikethrough(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-UNCONFIRMED-PROMOTION');
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$this->rule()->getAttributes(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
        ]);
        $this->selectItem($rule, $item);
        $promotionStarted = false;
        Http::fake(function (Request $request) use (&$promotionStarted): mixed {
            if (str_contains($request->url(), '/pricing-automation/')) {
                return Http::response([], 404);
            }
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                if (strtolower($request->method()) === 'post') {
                    $promotionStarted = true;

                    return Http::response(['status' => 'started'], 201);
                }

                return Http::response([$this->priceDiscount($promotionStarted ? 'started' : 'candidate', 2000, 360, 1800, 1700)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(2000), $this->promotionPrice(1800, 2000)]]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(['amount' => 2000, 'regular_amount' => null, 'metadata' => []]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id);

        $this->assertSame(1, $summary['failed']);
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        $this->assertDatabaseHas('meli_price_changes', ['scheduled_action' => 'apply', 'status' => 'failed']);
        Http::assertNotSent(fn (Request $request): bool => strtolower($request->method()) === 'put');
    }

    public function test_restore_stays_pending_until_remote_winner_is_removed(): void
    {
        $this->account->forceFill(['access_token' => 'token'])->save();
        $item = $this->beautyItem('MLM-RESTORE-UNCONFIRMED');
        $rule = MeliBeautyScheduledDiscount::query()->create([...$this->rule()->getAttributes(), 'active' => false]);
        $item->scheduledPriceState()->create([
            'meli_beauty_scheduled_discount_id' => $rule->id,
            'base_price' => 2000,
            'promotional_price' => 1800,
            'last_confirmed_remote_price' => 1800,
            'status' => 'active',
        ]);
        Http::fake(function (Request $request): mixed {
            if (str_contains($request->url(), '/seller-promotions/items/')) {
                return strtolower($request->method()) === 'delete'
                    ? Http::response([], 200)
                    : Http::response([$this->priceDiscount('started', 2000, 360, 1800, 1700)]);
            }
            if (str_contains($request->url(), '/prices')) {
                return Http::response(['prices' => [$this->standardPrice(2000), $this->promotionPrice(1800, 2000)]]);
            }
            if (str_contains($request->url(), '/sale_price')) {
                return Http::response(['amount' => 1800, 'regular_amount' => 2000, 'metadata' => ['promotion_id' => 'PRICE-DISCOUNT']]);
            }

            return Http::response([], 500);
        });

        $summary = app(MeliBeautyScheduledPriceService::class)->processRule($rule, $item->meli_item_id);

        $this->assertSame(1, $summary['failed']);
        $this->assertSame('restore_pending', $item->fresh()->scheduledPriceState?->status);
        $this->assertDatabaseHas('meli_price_changes', ['scheduled_action' => 'restore', 'status' => 'failed']);
        Http::assertNotSent(fn (Request $request): bool => strtolower($request->method()) === 'put');
    }

    private function standardPrice(float $amount): array
    {
        return ['type' => 'standard', 'amount' => $amount, 'conditions' => ['context_restrictions' => ['channel_marketplace']]];
    }

    private function promotionPrice(float $amount, float $regularAmount): array
    {
        return [
            'type' => 'promotion',
            'amount' => $amount,
            'regular_amount' => $regularAmount,
            'conditions' => ['context_restrictions' => ['channel_marketplace']],
        ];
    }

    private function priceDiscount(string $status, float $original, float $minimum, float $maximum, float $suggested): array
    {
        return [
            'type' => 'PRICE_DISCOUNT',
            'status' => $status,
            'original_price' => $original,
            'min_discounted_price' => $minimum,
            'max_discounted_price' => $maximum,
            'suggested_discounted_price' => $suggested,
        ];
    }

    private function requirementMigrations(): void
    {
        (require database_path('migrations/2026_09_07_000001_create_meli_beauty_scheduled_discounts_table.php'))->up();
        (require database_path('migrations/2026_09_07_000002_create_meli_scheduled_price_states_table.php'))->up();
        (require database_path('migrations/2026_09_07_000003_add_scheduled_source_to_meli_price_changes.php'))->up();
        (require database_path('migrations/2026_09_08_000001_add_dates_and_items_to_meli_beauty_scheduled_discounts.php'))->up();
    }

    private function rule(): MeliBeautyScheduledDiscount
    {
        return new MeliBeautyScheduledDiscount([
            'meli_account_id' => $this->account->id, 'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
            'starts_at' => '20:00', 'ends_at' => '06:00',
            'timezone' => 'America/Mexico_City', 'active' => true,
        ]);
    }

    private function selectItem(MeliBeautyScheduledDiscount $rule, MeliPriceManagerItem $item, float $percentage = 10): void
    {
        $rule->scheduledItems()->create([
            'price_manager_item_id' => $item->id,
            'discount_percentage' => $percentage,
        ]);
    }

    private function beautyItem(string $itemId): MeliPriceManagerItem
    {
        $item = MeliPriceManagerItem::factory()->for($this->account, 'meliAccount')->for($this->brand, 'brandGroup')->create([
            'meli_item_id' => $itemId, 'category_id' => 'MLM-BEAUTY-CATEGORY', 'classification_status' => 'categorized',
        ]);
        DB::table('meli_categories')->updateOrInsert(['category_id' => $item->category_id], ['root_category_id' => 'MLM1246']);

        return $item;
    }
}
