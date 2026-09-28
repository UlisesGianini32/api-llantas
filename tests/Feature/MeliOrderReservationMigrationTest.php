<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliOrderReservationMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->boolean('stock_sync_enabled')->default(false);
        });
        Schema::create('inventory_products', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id', 30);
            $table->timestamps();
            $table->unique(['meli_order_id', 'item_id'], 'meli_order_items_meli_order_id_item_id_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_channel_order_allocations');
        Schema::dropIfExists('meli_order_items');
        Schema::dropIfExists('meli_orders');
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_channel_links');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_clean_schema_runs_up_completely(): void
    {
        $this->migration()->up();

        $this->assertModernSchema();
    }

    public function test_partial_production_schema_is_resumable(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->boolean('order_reservation_enabled')->default(false);
        });
        Schema::table('meli_order_items', function (Blueprint $table): void {
            $table->string('variation_id', 64)->nullable();
            $table->string('remote_line_key', 128)->nullable();
        });

        $this->migration()->up();

        $this->assertModernSchema();
    }

    public function test_up_is_idempotent_when_run_again(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->up();

        $this->assertModernSchema();
        $this->assertSame(1, collect(Schema::getIndexes('meli_order_items'))
            ->where('name', 'meli_order_line_key_unique')->count());
        $this->assertSame(1, collect(Schema::getIndexes('meli_order_items'))
            ->where('name', 'meli_order_items_order_fk_idx')->count());
        $this->assertAllocationStructures();
    }

    public function test_partial_allocation_table_is_completed_without_recreation(): void
    {
        $this->prepareMigratedMeliOrderItems();
        $this->createPartialAllocationTable();
        DB::table('inventory_channel_order_allocations')->insert([
            'channel' => 'mercado_libre',
            'account_key' => '1',
            'remote_order_id' => '1001',
            'remote_line_key' => 'MLM-ITEM:',
            'identity_hash' => 'hash-one',
        ]);

        $this->migration()->up();

        $this->assertAllocationStructures();
        $this->assertSame(1, DB::table('inventory_channel_order_allocations')->count());
    }

    public function test_existing_foreign_key_is_not_duplicated_when_completing_the_other(): void
    {
        $this->prepareMigratedMeliOrderItems();
        $this->createPartialAllocationTable();
        Schema::table('inventory_channel_order_allocations', function (Blueprint $table): void {
            $table->index('inventory_channel_link_id', 'inv_order_alloc_link_idx');
            $table->foreign('inventory_channel_link_id', 'inv_order_alloc_link_fk')
                ->references('id')->on('inventory_channel_links')->nullOnDelete();
        });

        $this->migration()->up();

        $this->assertAllocationStructures();
        $foreignKeys = collect(Schema::getForeignKeys('inventory_channel_order_allocations'));
        $this->assertCount(1, $foreignKeys->filter(fn (array $foreignKey): bool => $foreignKey['columns'] === ['inventory_channel_link_id']));
        $this->assertCount(1, $foreignKeys->filter(fn (array $foreignKey): bool => $foreignKey['columns'] === ['inventory_product_id']));
    }

    public function test_duplicate_identity_hash_fails_before_unique_creation(): void
    {
        $this->prepareMigratedMeliOrderItems();
        $this->createPartialAllocationTable();
        DB::table('inventory_channel_order_allocations')->insert([
            ['channel' => 'mercado_libre', 'account_key' => '1', 'remote_order_id' => '1001', 'remote_line_key' => 'A', 'identity_hash' => 'duplicate'],
            ['channel' => 'mercado_libre', 'account_key' => '1', 'remote_order_id' => '1002', 'remote_line_key' => 'B', 'identity_hash' => 'duplicate'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identity_hash duplicados');
        try {
            $this->migration()->up();
        } finally {
            $this->assertFalse(Schema::hasIndex('inventory_channel_order_allocations', 'inv_order_alloc_identity_uq', 'unique'));
        }
    }

    public function test_foreign_key_retains_a_dedicated_index_after_up(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_items_order_fk_idx'));
        $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_line_key_unique'));
        $this->assertFalse(Schema::hasIndex('meli_order_items', 'meli_order_items_meli_order_id_item_id_unique'));
        $this->assertNotEmpty(Schema::getForeignKeys('meli_order_items'));
    }

    public function test_down_preflight_rejects_duplicates_before_schema_changes(): void
    {
        $migration = $this->migration();
        $migration->up();
        DB::table('meli_orders')->insert(['id' => 1]);
        DB::table('meli_order_items')->insert([
            ['meli_order_id' => 1, 'item_id' => 'MLM-DOWN', 'remote_line_key' => 'MLM-DOWN:one', 'created_at' => now(), 'updated_at' => now()],
            ['meli_order_id' => 1, 'item_id' => 'MLM-DOWN', 'remote_line_key' => 'MLM-DOWN:two', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('meli_order_id + item_id');
        try {
            $migration->down();
        } finally {
            $this->assertTrue(Schema::hasTable('inventory_channel_order_allocations'));
            $this->assertTrue(Schema::hasColumn('meli_order_items', 'remote_line_key'));
            $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_line_key_unique'));
            $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_items_order_fk_idx'));
        }
    }

    public function test_down_restores_old_identity_before_removing_fk_index(): void
    {
        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertFalse(Schema::hasTable('inventory_channel_order_allocations'));
        $this->assertFalse(Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled'));
        $this->assertFalse(Schema::hasColumn('meli_order_items', 'variation_id'));
        $this->assertFalse(Schema::hasColumn('meli_order_items', 'remote_line_key'));
        $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_items_meli_order_id_item_id_unique'));
        $this->assertFalse(Schema::hasIndex('meli_order_items', 'meli_order_line_key_unique'));
        $this->assertFalse(Schema::hasIndex('meli_order_items', 'meli_order_items_order_fk_idx'));
        $this->assertNotEmpty(Schema::getForeignKeys('meli_order_items'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php');
    }

    private function assertModernSchema(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_channel_order_allocations'));
        $this->assertTrue(Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled'));
        $this->assertTrue(Schema::hasColumn('meli_order_items', 'variation_id'));
        $this->assertTrue(Schema::hasColumn('meli_order_items', 'remote_line_key'));
        $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_line_key_unique'));
        $this->assertTrue(Schema::hasIndex('meli_order_items', 'meli_order_items_order_fk_idx'));
        $this->assertFalse(Schema::hasIndex('meli_order_items', 'meli_order_items_meli_order_id_item_id_unique'));
        $this->assertAllocationStructures();
    }

    private function assertAllocationStructures(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_channel_order_allocations'));
        $this->assertTrue(Schema::hasIndex('inventory_channel_order_allocations', 'inv_order_alloc_identity_uq', 'unique'));
        foreach ([
            'inv_order_alloc_link_idx',
            'inv_order_alloc_product_idx',
            'inv_order_alloc_order_idx',
            'inv_order_alloc_res_idx',
        ] as $index) {
            $this->assertTrue(Schema::hasIndex('inventory_channel_order_allocations', $index));
        }

        $foreignKeys = collect(Schema::getForeignKeys('inventory_channel_order_allocations'));
        foreach ([
            ['inventory_channel_link_id', 'inventory_channel_links'],
            ['inventory_product_id', 'inventory_products'],
        ] as [$column, $table]) {
            $foreignKey = $foreignKeys->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]
                && $foreignKey['foreign_table'] === $table
                && $foreignKey['foreign_columns'] === ['id']);
            $this->assertNotNull($foreignKey);
            $this->assertSame('set null', $foreignKey['on_delete']);
        }
    }

    private function prepareMigratedMeliOrderItems(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->boolean('order_reservation_enabled')->default(false);
        });
        Schema::table('meli_order_items', function (Blueprint $table): void {
            $table->string('variation_id', 64)->nullable();
            $table->string('remote_line_key', 128)->nullable();
            $table->index('meli_order_id', 'meli_order_items_order_fk_idx');
        });
        Schema::table('meli_order_items', function (Blueprint $table): void {
            $table->dropUnique('meli_order_items_meli_order_id_item_id_unique');
            $table->unique(['meli_order_id', 'remote_line_key'], 'meli_order_line_key_unique');
        });
    }

    private function createPartialAllocationTable(): void
    {
        Schema::create('inventory_channel_order_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 32);
            $table->string('account_key', 64);
            $table->string('remote_order_id', 64);
            $table->string('remote_line_key', 191);
            $table->string('identity_hash', 64);
            $table->unsignedBigInteger('inventory_channel_link_id')->nullable();
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->string('reservation_kind', 16)->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reservation_version')->default(0);
            $table->string('status', 32)->default('PENDING');
            $table->string('diagnostic_code', 64)->nullable();
            $table->json('diagnostic_metadata')->nullable();
            $table->timestamps();
        });
    }
}
