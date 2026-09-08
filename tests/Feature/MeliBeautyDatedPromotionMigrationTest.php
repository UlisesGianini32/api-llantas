<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliBeautyDatedPromotionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('meli_accounts', fn (Blueprint $table) => $table->id());
        Schema::create('meli_brand_groups', fn (Blueprint $table) => $table->id());
        Schema::create('meli_price_manager_items', fn (Blueprint $table) => $table->id());
        (require database_path('migrations/2026_09_07_000001_create_meli_beauty_scheduled_discounts_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_legacy_rows_are_preserved_disabled_and_without_invented_dates_or_items(): void
    {
        DB::table('meli_accounts')->insert(['id' => 1]);
        DB::table('meli_brand_groups')->insert(['id' => 1]);
        DB::table('meli_price_manager_items')->insert(['id' => 1]);
        DB::table('meli_beauty_scheduled_discounts')->insert([
            'meli_account_id' => 1,
            'brand_group_id' => 1,
            'discount_percentage' => 10,
            'starts_at' => '20:00',
            'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo',
            'active' => true,
        ]);

        (require database_path('migrations/2026_09_08_000001_add_dates_and_items_to_meli_beauty_scheduled_discounts.php'))->up();

        $legacy = DB::table('meli_beauty_scheduled_discounts')->first();
        $this->assertSame(0, (int) $legacy->active);
        $this->assertNull($legacy->starts_on);
        $this->assertNull($legacy->ends_on);
        $this->assertDatabaseCount('meli_beauty_scheduled_discount_items', 0);

        DB::table('meli_beauty_scheduled_discounts')->insert([
            'meli_account_id' => 1,
            'brand_group_id' => 1,
            'discount_percentage' => 15,
            'starts_on' => '2028-10-01',
            'ends_on' => '2028-10-02',
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'timezone' => 'America/Mexico_City',
            'active' => false,
        ]);
        $this->assertDatabaseCount('meli_beauty_scheduled_discounts', 2);
    }
}
