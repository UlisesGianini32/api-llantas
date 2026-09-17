<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliCategory;
use App\Models\MeliPriceManagerItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliPriceManagerManagedCatalogTest extends TestCase
{
    private object $foundationMigration;

    private MeliAccount $account;

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
        Schema::create('producto_compuestos', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('MLM')->nullable();
        });

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();
        $this->account = MeliAccount::factory()->create();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('meli_categories');
        Schema::dropIfExists('syscom_meli_queues');
        $this->foundationMigration->down();
        Schema::dropIfExists('producto_compuestos');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_tire_with_same_mlm_is_excluded(): void
    {
        $item = $this->item('MLM-TIRE-SAME', 'TIRE-SKU-1');
        DB::table('llantas')->insert(['MLM' => 'MLM-TIRE-SAME', 'sku' => 'OTHER-TIRE']);

        $this->assertExcluded($item);
    }

    public function test_republished_tire_with_same_sku_and_different_mlm_is_excluded(): void
    {
        $item = $this->item('MLM5201403642', '2056014MEMR166');
        DB::table('llantas')->insert(['MLM' => 'MLM2720548725', 'sku' => '2056014MEMR166']);

        $this->assertExcluded($item);
    }

    public function test_tire_with_same_sku_and_null_external_mlm_is_excluded(): void
    {
        $item = $this->item('MLM-TIRE-NEW', 'TIRE-SKU-NULL-MLM');
        DB::table('llantas')->insert(['MLM' => null, 'sku' => 'TIRE-SKU-NULL-MLM']);

        $this->assertExcluded($item);
    }

    public function test_composite_with_same_mlm_is_excluded(): void
    {
        $item = $this->item('MLM-COMPOSITE-SAME', 'COMPOSITE-SKU-1');
        DB::table('producto_compuestos')->insert(['MLM' => 'MLM-COMPOSITE-SAME', 'sku' => 'OTHER-COMPOSITE']);

        $this->assertExcluded($item);
    }

    public function test_republished_composite_with_same_sku_and_different_mlm_is_excluded(): void
    {
        $item = $this->item('MLM-COMPOSITE-NEW', '11225AMAF50818C-2');
        DB::table('producto_compuestos')->insert(['MLM' => 'MLM-COMPOSITE-OLD', 'sku' => '11225AMAF50818C-2']);

        $this->assertExcluded($item);
    }

    public function test_composite_with_same_sku_and_null_external_mlm_is_excluded(): void
    {
        $item = $this->item('MLM-COMPOSITE-NULL-MLM', '00541-4');
        DB::table('producto_compuestos')->insert(['MLM' => null, 'sku' => '00541-4']);

        $this->assertExcluded($item);
    }

    public function test_null_skus_do_not_create_accidental_exclusions(): void
    {
        $item = $this->item('MLM-NULL-SKU', null);
        DB::table('llantas')->insert(['MLM' => null, 'sku' => null]);
        DB::table('producto_compuestos')->insert(['MLM' => null, 'sku' => null]);

        $this->assertManaged($item);
    }

    public function test_empty_and_whitespace_skus_do_not_create_accidental_exclusions(): void
    {
        $empty = $this->item('MLM-EMPTY-SKU', '');
        $whitespace = $this->item('MLM-WHITESPACE-SKU', '   ');
        DB::table('llantas')->insert(['MLM' => null, 'sku' => '']);
        DB::table('producto_compuestos')->insert(['MLM' => null, 'sku' => '   ']);

        $this->assertManaged($empty);
        $this->assertManaged($whitespace);
    }

    public function test_legitimate_item_with_different_mlm_and_sku_remains_managed(): void
    {
        $item = $this->item('MLM-LEGITIMATE', 'PRICE-MANAGER-ONLY');
        DB::table('llantas')->insert(['MLM' => 'MLM-TIRE', 'sku' => 'TIRE-ONLY']);
        DB::table('producto_compuestos')->insert(['MLM' => 'MLM-COMPOSITE', 'sku' => 'COMPOSITE-ONLY']);

        $this->assertManaged($item);
    }

    public function test_scope_works_when_external_tables_do_not_exist(): void
    {
        Schema::drop('producto_compuestos');
        Schema::drop('llantas');
        $item = $this->item('MLM-WITHOUT-EXTERNAL-TABLES', 'SKU-WITHOUT-EXTERNAL-TABLES');

        $this->assertManaged($item);
    }

    public function test_focused_catalog_includes_configured_beauty_and_supplement_roots(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', [
            'MLM-TEST-BEAUTY-ROOT', 'MLM-TEST-SUPPLEMENTS-ROOT',
        ]);
        $beauty = $this->focusedItem('MLM-TEST-BEAUTY-ITEM', 'MLM-TEST-SHAMPOO', 'MLM-TEST-BEAUTY-ROOT', 'Shampoo');
        $supplement = $this->focusedItem('MLM-TEST-SUPPLEMENT-ITEM', 'MLM-TEST-VITAMINS', 'MLM-TEST-SUPPLEMENTS-ROOT', 'Vitaminas');

        $this->assertTrue(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($beauty)->exists());
        $this->assertTrue(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($supplement)->exists());
        $this->assertSame('Shampoo', $beauty->category()->firstOrFail()->name);
    }

    public function test_exact_allowed_category_is_included_and_unresolved_name_falls_back_to_its_id(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', ['MLM-TEST-UNRESOLVED']);
        $item = $this->item('MLM-TEST-UNRESOLVED-ITEM', null);
        $item->forceFill(['category_id' => 'MLM-TEST-UNRESOLVED'])->save();

        $this->assertTrue(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
        $this->assertNull($item->category()->first());
        $this->assertSame('MLM-TEST-UNRESOLVED', $item->category_id);
    }

    public function test_descendant_of_allowed_category_is_included_from_path(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', ['MLM-TEST-SUPPLEMENTS']);
        $item = $this->focusedItem(
            'MLM-TEST-VITAMIN-ITEM',
            'MLM-TEST-VITAMINS',
            'MLM-TEST-HEALTH-ROOT',
            'Vitaminas',
            [
                ['id' => 'MLM-TEST-HEALTH-ROOT', 'name' => 'Salud'],
                ['id' => 'MLM-TEST-SUPPLEMENTS', 'name' => 'Suplementos'],
                ['id' => 'MLM-TEST-VITAMINS', 'name' => 'Vitaminas'],
            ],
        );

        $this->assertTrue(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
    }

    public function test_sibling_of_allowed_category_is_not_included(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', ['MLM-TEST-SUPPLEMENTS']);
        $item = $this->focusedItem(
            'MLM-TEST-ORTHOPEDICS-ITEM',
            'MLM-TEST-ORTHOPEDICS',
            'MLM-TEST-HEALTH-ROOT',
            'Ortopedia',
            [
                ['id' => 'MLM-TEST-HEALTH-ROOT', 'name' => 'Salud'],
                ['id' => 'MLM-TEST-ORTHOPEDICS', 'name' => 'Ortopedia'],
            ],
        );

        $this->assertFalse(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
    }

    public function test_category_below_allowed_root_is_included(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        $item = $this->focusedItem('MLM-TEST-HAIR-ITEM', 'MLM-TEST-HAIR', 'MLM-TEST-BEAUTY-ROOT', 'Cuidado capilar');

        $this->assertTrue(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
    }

    public function test_category_below_non_allowed_root_is_not_included(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        $item = $this->focusedItem('MLM-TEST-MEDICAL-ITEM', 'MLM-TEST-MEDICAL', 'MLM-TEST-HEALTH-ROOT', 'Equipos médicos');

        $this->assertFalse(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
    }

    public function test_focused_catalog_falls_back_to_managed_catalog_when_both_allowlists_are_empty(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', []);
        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', []);
        $item = $this->focusedItem('MLM-TEST-FALLBACK', 'MLM-TEST-ANY-CATEGORY', 'MLM-TEST-ANY-ROOT', 'Cualquiera');

        $this->assertTrue(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
        $this->assertTrue(MeliPriceManagerItem::query()->managedCatalog()->whereKey($item)->exists());
    }

    public function test_focused_catalog_excludes_pool_and_beer_categories(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        $pool = $this->focusedItem('MLM-TEST-POOL-ITEM', 'MLM-TEST-POOLS', 'MLM-TEST-HOME-ROOT', 'Albercas');
        $beer = $this->focusedItem('MLM-TEST-BEER-ITEM', 'MLM-TEST-BEER', 'MLM-TEST-DRINKS-ROOT', 'Cervezas');

        $this->assertFalse(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($pool)->exists());
        $this->assertFalse(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($beer)->exists());
    }

    public function test_focused_catalog_still_applies_managed_catalog_exclusions(): void
    {
        $this->createCategoryCatalog();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        $tire = $this->focusedItem('MLM-TEST-TIRE', 'MLM-TEST-SHAMPOO', 'MLM-TEST-BEAUTY-ROOT', 'Shampoo');
        DB::table('llantas')->insert(['MLM' => $tire->meli_item_id, 'sku' => null]);

        $this->assertFalse(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($tire)->exists());
    }

    public function test_focused_catalog_still_excludes_syscom_items(): void
    {
        $this->createCategoryCatalog();
        Schema::create('syscom_meli_queues', function (Blueprint $table): void {
            $table->id();
            $table->string('mlm')->nullable();
        });
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        $item = $this->focusedItem('MLM-TEST-SYSCOM', 'MLM-TEST-SHAMPOO', 'MLM-TEST-BEAUTY-ROOT', 'Shampoo');
        DB::table('syscom_meli_queues')->insert(['mlm' => $item->meli_item_id]);

        $this->assertFalse(MeliPriceManagerItem::query()->focusedCatalog()->whereKey($item)->exists());
    }

    private function assertExcluded(MeliPriceManagerItem $item): void
    {
        $this->assertFalse(MeliPriceManagerItem::query()->managedCatalog()->whereKey($item)->exists());
    }

    private function assertManaged(MeliPriceManagerItem $item): void
    {
        $this->assertTrue(MeliPriceManagerItem::query()->managedCatalog()->whereKey($item)->exists());
    }

    private function item(string $meliItemId, ?string $sku): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::factory()->for($this->account, 'meliAccount')->create([
            'meli_item_id' => $meliItemId,
            'sku' => $sku,
        ]);
    }

    private function createCategoryCatalog(): void
    {
        if (Schema::hasTable('meli_categories')) {
            return;
        }

        $migration = require database_path('migrations/2026_08_28_000002_create_meli_categories_table.php');
        $migration->up();
    }

    /** @param list<array{id: string, name: string}> $path */
    private function focusedItem(
        string $itemId,
        string $categoryId,
        string $rootId,
        string $name,
        array $path = [],
    ): MeliPriceManagerItem
    {
        MeliCategory::query()->firstOrCreate(
            ['category_id' => $categoryId],
            ['name' => $name, 'root_category_id' => $rootId, 'path_from_root' => $path],
        );

        $item = $this->item($itemId, null);
        $item->forceFill(['category_id' => $categoryId])->save();

        return $item;
    }
}
