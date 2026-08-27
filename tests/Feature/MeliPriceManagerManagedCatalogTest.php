<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
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
}
