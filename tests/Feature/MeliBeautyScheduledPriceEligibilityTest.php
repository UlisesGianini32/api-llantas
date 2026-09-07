<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $this->requirementMigrations();
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->account = MeliAccount::factory()->for($this->user)->create();
        $this->brand = MeliBrandGroup::factory()->create(['active' => true]);
    }

    protected function tearDown(): void
    {
        require database_path('migrations/2026_09_07_000002_create_meli_scheduled_price_states_table.php');
        Schema::dropIfExists('meli_scheduled_price_states');
        Schema::dropIfExists('meli_beauty_scheduled_discounts');
        $this->foundationMigration->down();
        foreach (['automotive_part_meli_publications', 'syscom_meli_queues', 'producto_compuestos', 'llantas', 'meli_categories', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_valid_beauty_rule_is_created_and_duplicate_account_brand_is_rejected(): void
    {
        $item = $this->beautyItem('MLM-BEAUTY-VALID');
        $rule = new MeliBeautyScheduledDiscount([
            'meli_account_id' => $this->account->id, 'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10, 'starts_at' => '20:00', 'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo', 'active' => true,
        ]);
        $this->assertTrue(app(MeliBeautyScheduledPriceService::class)->ruleHasEligibleItems($rule));
        MeliBeautyScheduledDiscount::query()->create($rule->getAttributes());

        $this->expectException(QueryException::class);
        MeliBeautyScheduledDiscount::query()->create($rule->getAttributes());
    }

    public function test_admin_endpoints_validate_percentage_hours_and_create_only_a_beauty_rule(): void
    {
        $this->beautyItem('MLM-ENDPOINT-BEAUTY');
        $this->actingAs($this->user);
        $payload = [
            'meli_account_id' => $this->account->id,
            'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10,
            'starts_at' => '20:00',
            'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo',
            'active' => true,
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

        $this->post(route('meli-price-manager.scheduled-discounts.store'), $payload)
            ->assertSessionHasErrors('brand_group_id');
        $this->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('MeliPriceManager/ScheduledDiscounts')
                ->has('rules', 1)
                ->has('brandOptions', 1)
                ->where('rules.0.eligible_items_count', 1));
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
                ->where('brandOptions.0.id', $this->brand->id));

        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $this->actingAs($operations)
            ->get(route('meli-price-manager.scheduled-discounts.index'))
            ->assertForbidden();
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

    private function requirementMigrations(): void
    {
        (require database_path('migrations/2026_09_07_000001_create_meli_beauty_scheduled_discounts_table.php'))->up();
        (require database_path('migrations/2026_09_07_000002_create_meli_scheduled_price_states_table.php'))->up();
    }

    private function rule(): MeliBeautyScheduledDiscount
    {
        return new MeliBeautyScheduledDiscount([
            'meli_account_id' => $this->account->id, 'brand_group_id' => $this->brand->id,
            'discount_percentage' => 10, 'starts_at' => '20:00', 'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo', 'active' => true,
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
