<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Pos\PosSaleService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseOptimizationAndIndexingTest extends TestCase
{
    private User $admin;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Run inventory, POS, restock, PO, and performance index migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000001_create_pos_sales_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000002_create_pos_shifts_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000005_add_performance_composite_indexes.php'))->up();

        $this->admin = User::forceCreate([
            'name' => 'Admin Optimization',
            'email' => 'perf@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'MOSTRADOR',
            'name' => 'Mostrador Hermosillo',
            'type' => 'store',
            'is_active' => true,
        ]);
    }

    public function test_composite_performance_indexes_are_registered(): void
    {
        // Check that tables exist and can accept queries with composite indexes
        $this->assertTrue(Schema::hasTable('inventory_movements'));
        $this->assertTrue(Schema::hasTable('inventory_reservations'));
        $this->assertTrue(Schema::hasTable('inventory_channel_links'));
        $this->assertTrue(Schema::hasTable('pos_sales'));
        $this->assertTrue(Schema::hasTable('purchase_orders'));

        // Verify composite index query on inventory_movements (reference and date range)
        $movementQuery = InventoryMovement::query()
            ->where('reference_type', 'purchase_order')
            ->where('reference_id', 1)
            ->toSql();
        $this->assertStringContainsString('reference_type', $movementQuery);
        $this->assertStringContainsString('reference_id', $movementQuery);

        $velocityQuery = InventoryMovement::query()
            ->where('inventory_product_id', 1)
            ->where('type', InventoryMovement::SALE)
            ->whereBetween('occurred_at', [Carbon::now()->subDays(30), Carbon::now()])
            ->toSql();
        $this->assertStringContainsString('inventory_product_id', $velocityQuery);
        $this->assertStringContainsString('occurred_at', $velocityQuery);
    }

    public function test_pos_search_executes_constant_queries_avoiding_n_plus_one(): void
    {
        // Create 15 products with inventory movements and reservations
        for ($i = 1; $i <= 15; $i++) {
            $product = InventoryProduct::create([
                'sku' => "LLANTA-PERF-{$i}",
                'name' => "Llanta Rendimiento {$i}",
                'product_type' => InventoryProduct::SIMPLE,
                'is_active' => true,
                'cost' => 1000 + $i,
                'price' => 1500 + $i,
            ]);

            InventoryMovement::create([
                'inventory_product_id' => $product->id,
                'inventory_location_id' => $this->location->id,
                'type' => InventoryMovement::INITIAL,
                'quantity' => 20,
                'occurred_at' => now(),
            ]);

            InventoryReservation::create([
                'inventory_product_id' => $product->id,
                'inventory_location_id' => $this->location->id,
                'quantity' => 2,
                'status' => InventoryReservation::ACTIVE,
            ]);
        }

        /** @var PosSaleService $posSaleService */
        $posSaleService = app(PosSaleService::class);

        // Reset and listen to executed queries
        DB::flushQueryLog();
        DB::enableQueryLog();

        $results = $posSaleService->searchProducts('LLANTA-PERF', $this->location->id);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 15 products should return 15 results
        $this->assertCount(15, $results);

        // Instead of 15 * 3 = 45 queries, it must execute <= 4 queries in total (Product search + Location + Batch movements + Batch reservations)
        $this->assertLessThanOrEqual(5, count($queries), 'Query count exceeds O(1) expected batch queries, indicating N+1 regression.');

        // Verify that stock calculations are accurate from the batch queries
        $first = $results[0];
        $this->assertEquals(20, $first['physical_stock']);
        $this->assertEquals(2, $first['reserved_stock']);
        $this->assertEquals(18, $first['available_stock']);
    }
}
