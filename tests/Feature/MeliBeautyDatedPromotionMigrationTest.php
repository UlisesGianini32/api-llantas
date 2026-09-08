<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MeliBeautyDatedPromotionMigrationTest extends TestCase
{
    private const ORIGINAL_UNIQUE = 'meli_beauty_discounts_account_brand_uq';

    private const NORMAL_INDEX = 'mbsd_account_brand_idx';

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

        DB::table('meli_accounts')->insert(['id' => 1]);
        DB::table('meli_brand_groups')->insert(['id' => 1]);
        DB::table('meli_price_manager_items')->insert(['id' => 1]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_up_disables_legacy_removes_unique_and_creates_child_constraints(): void
    {
        $legacyId = $this->insertLegacyPromotion();
        $this->assertIndex('meli_beauty_scheduled_discounts', self::ORIGINAL_UNIQUE, true);

        $this->datedMigration()->up();

        $legacy = DB::table('meli_beauty_scheduled_discounts')->find($legacyId);
        $this->assertSame(0, (int) $legacy->active);
        $this->assertNull($legacy->starts_on);
        $this->assertNull($legacy->ends_on);
        $this->assertFalse($this->hasIndex('meli_beauty_scheduled_discounts', self::ORIGINAL_UNIQUE));
        $this->assertIndex('meli_beauty_scheduled_discounts', self::NORMAL_INDEX, false);

        $secondId = $this->insertDatedPromotion(15);
        $this->assertDatabaseCount('meli_beauty_scheduled_discounts', 2);
        $this->assertNotSame($legacyId, $secondId);

        $this->assertTrue(Schema::hasTable('meli_beauty_scheduled_discount_items'));
        $this->assertTrue(Schema::hasColumns('meli_beauty_scheduled_discount_items', [
            'meli_beauty_scheduled_discount_id',
            'price_manager_item_id',
            'discount_percentage',
        ]));
        $this->assertIndex('meli_beauty_scheduled_discount_items', 'mbsdi_discount_item_uq', true);
        $this->assertIndex('meli_beauty_scheduled_discount_items', 'mbsdi_item_idx', false);

        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('meli_beauty_scheduled_discount_items')"))
            ->keyBy('table');
        $this->assertSame('CASCADE', strtoupper($foreignKeys['meli_beauty_scheduled_discounts']->on_delete));
        $this->assertSame('RESTRICT', strtoupper($foreignKeys['meli_price_manager_items']->on_delete));
        $this->assertShortConstraintNames();
    }

    public function test_mysql_up_creates_replacement_index_in_a_separate_statement_before_dropping_unique(): void
    {
        // SQLite cannot reproduce MySQL error 1553, so this regression verifies
        // the two explicit Schema statements and their source order.
        $source = file_get_contents(database_path('migrations/2026_09_08_000001_add_dates_and_items_to_meli_beauty_scheduled_discounts.php'));
        $upStart = strpos($source, 'public function up(): void');
        $downStart = strpos($source, 'public function down(): void');
        $this->assertNotFalse($upStart);
        $this->assertNotFalse($downStart);
        $upSource = substr($source, $upStart, $downStart - $upStart);

        $mysqlBranchStart = strpos($upSource, '} else {');
        $mysqlBranchEnd = strpos($upSource, "\n        }\n\n        Schema::create", $mysqlBranchStart);
        $this->assertNotFalse($mysqlBranchStart);
        $this->assertNotFalse($mysqlBranchEnd);
        $mysqlBranch = substr($upSource, $mysqlBranchStart, $mysqlBranchEnd - $mysqlBranchStart);

        $createIndex = strpos($mysqlBranch, "\$table->index(['meli_account_id', 'brand_group_id'], 'mbsd_account_brand_idx');");
        $dropUnique = strpos($mysqlBranch, "\$table->dropUnique('meli_beauty_discounts_account_brand_uq');");
        $this->assertNotFalse($createIndex);
        $this->assertNotFalse($dropUnique);
        $this->assertLessThan($dropUnique, $createIndex);
        $this->assertSame(2, substr_count($mysqlBranch, "Schema::table('meli_beauty_scheduled_discounts'"));
    }

    public function test_down_without_duplicates_restores_original_schema(): void
    {
        $promotionId = $this->insertLegacyPromotion();
        $migration = $this->datedMigration();
        $migration->up();
        DB::table('meli_beauty_scheduled_discount_items')->insert([
            'meli_beauty_scheduled_discount_id' => $promotionId,
            'price_manager_item_id' => 1,
            'discount_percentage' => 10,
        ]);

        $migration->down();

        $this->assertFalse(Schema::hasTable('meli_beauty_scheduled_discount_items'));
        $this->assertFalse(Schema::hasColumn('meli_beauty_scheduled_discounts', 'starts_on'));
        $this->assertFalse(Schema::hasColumn('meli_beauty_scheduled_discounts', 'ends_on'));
        $this->assertFalse($this->hasIndex('meli_beauty_scheduled_discounts', self::NORMAL_INDEX));
        $this->assertIndex('meli_beauty_scheduled_discounts', self::ORIGINAL_UNIQUE, true);
        $this->assertSame(0, (int) DB::table('meli_beauty_scheduled_discounts')->value('active'));

        try {
            $this->insertLegacyPromotion();
            $this->fail('The restored account/brand unique index must reject duplicates.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_down_with_duplicates_aborts_before_any_destructive_operation(): void
    {
        $firstId = $this->insertLegacyPromotion();
        $migration = $this->datedMigration();
        $migration->up();
        $this->insertDatedPromotion(15);
        DB::table('meli_beauty_scheduled_discount_items')->insert([
            'meli_beauty_scheduled_discount_id' => $firstId,
            'price_manager_item_id' => 1,
            'discount_percentage' => 10,
        ]);

        try {
            $migration->down();
            $this->fail('Rollback must fail before altering data when duplicate account/brand pairs exist.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'No se puede revertir la migración de promociones Beauty: existen varias promociones para la misma cuenta y marca.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('meli_beauty_scheduled_discount_items'));
        $this->assertDatabaseCount('meli_beauty_scheduled_discount_items', 1);
        $this->assertTrue(Schema::hasColumn('meli_beauty_scheduled_discounts', 'starts_on'));
        $this->assertTrue(Schema::hasColumn('meli_beauty_scheduled_discounts', 'ends_on'));
        $this->assertIndex('meli_beauty_scheduled_discounts', self::NORMAL_INDEX, false);
        $this->assertFalse($this->hasIndex('meli_beauty_scheduled_discounts', self::ORIGINAL_UNIQUE));
        $this->assertDatabaseCount('meli_beauty_scheduled_discounts', 2);
    }

    private function insertLegacyPromotion(): int
    {
        return (int) DB::table('meli_beauty_scheduled_discounts')->insertGetId([
            'meli_account_id' => 1,
            'brand_group_id' => 1,
            'discount_percentage' => 10,
            'starts_at' => '20:00',
            'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo',
            'active' => true,
        ]);
    }

    private function insertDatedPromotion(float $percentage): int
    {
        return (int) DB::table('meli_beauty_scheduled_discounts')->insertGetId([
            'meli_account_id' => 1,
            'brand_group_id' => 1,
            'discount_percentage' => $percentage,
            'starts_on' => '2028-10-01',
            'ends_on' => '2028-10-02',
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'timezone' => 'America/Mexico_City',
            'active' => false,
        ]);
    }

    private function datedMigration(): object
    {
        return require database_path('migrations/2026_09_08_000001_add_dates_and_items_to_meli_beauty_scheduled_discounts.php');
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))->contains('name', $name);
    }

    private function assertIndex(string $table, string $name, bool $unique): void
    {
        $index = collect(DB::select("PRAGMA index_list('{$table}')"))->firstWhere('name', $name);
        $this->assertNotNull($index, "Expected index {$name} on {$table}.");
        $this->assertSame($unique ? 1 : 0, (int) $index->unique);
    }

    private function assertShortConstraintNames(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_08_000001_add_dates_and_items_to_meli_beauty_scheduled_discounts.php'));
        $names = [
            self::ORIGINAL_UNIQUE,
            self::NORMAL_INDEX,
            'mbsdi_discount_fk',
            'mbsdi_item_fk',
            'mbsdi_discount_item_uq',
            'mbsdi_item_idx',
        ];

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
            $this->assertStringContainsString($name, $migration);
        }
    }
}
