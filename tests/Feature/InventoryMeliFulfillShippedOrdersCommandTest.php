<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\InventoryStockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryMeliFulfillShippedOrdersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        config()->set('inventory.meli_order_reservations.automatic', true);
        config()->set('inventory.meli_order_reservations.automatic_after', '2000-01-01T00:00:00+00:00');

        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password');
            $table->string('role')->default('admin'); $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('meli_user_id');
            $table->string('nickname')->nullable(); $table->boolean('is_default')->default(false); $table->timestamps();
        });
        Schema::create('llantas', fn (Blueprint $table) => $table->id());

        foreach ([
            '2026_09_24_000001_create_inventory_products_table.php',
            '2026_09_24_000002_create_inventory_locations_table.php',
            '2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php',
            '2026_09_24_000004_create_inventory_movements_table.php',
            '2026_09_24_000005_create_inventory_reservations_table.php',
            '2026_09_24_000006_add_product_type_to_inventory_products_table.php',
            '2026_09_24_000007_create_inventory_kit_components_table.php',
            '2026_09_24_000008_create_inventory_kit_reservations_table.php',
            '2026_09_25_000001_create_inventory_channel_links_table.php',
            '2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php',
            '2026_09_25_000003_create_inventory_channel_stock_syncs_table.php',
            '2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id'); $table->string('status')->nullable();
            $table->string('shipping_status')->nullable();
            $table->json('shipping_raw')->nullable();
            $table->json('raw')->nullable(); $table->timestamps();
        });

        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id', 30); $table->string('sku')->nullable(); $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->nullable(); $table->timestamps();
            $table->unique(['meli_order_id', 'item_id']);
        });

        (require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach ([
            'inventory_channel_order_allocations', 'meli_order_items', 'meli_orders', 'inventory_channel_stock_syncs',
            'inventory_channel_links', 'inventory_kit_reservations', 'inventory_kit_components', 'inventory_reservations',
            'inventory_movements', 'inventory_products', 'inventory_locations', 'llantas', 'meli_accounts', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_command_fulfills_shipped_orders_and_deducts_stock(): void
    {
        $location = InventoryLocation::create(['code' => 'LOC-CMD', 'name' => 'Loc CMD', 'is_active' => true]);
        $product = InventoryProduct::create(['sku' => 'SKU-CMD', 'name' => 'Prod CMD', 'is_active' => true]);
        InventoryMovement::create([
            'inventory_product_id' => $product->id, 'inventory_location_id' => $location->id,
            'type' => InventoryMovement::RECEIPT, 'quantity' => 20, 'occurred_at' => now(),
        ]);

        InventoryChannelLink::create([
            'inventory_product_id' => $product->id, 'channel' => 'mercado_libre',
            'account_key' => '1', 'external_listing_id' => 'MLM111222333', 'identity_key' => '1:MLM111222333:simple:'.$product->id,
            'is_active' => true, 'order_reservation_enabled' => true,
        ]);

        $order = MeliOrder::create([
            'meli_account_id' => 1, 'order_id' => 5001, 'status' => 'paid',
            'shipping_status' => 'ready_to_ship',
            'raw' => ['date_created' => '2026-09-29T12:00:00-07:00'],
        ]);
        MeliOrderItem::create([
            'meli_order_id' => $order->id, 'item_id' => 'MLM111222333', 'remote_line_key' => 'line-cmd', 'quantity' => 3,
        ]);

        // 1. Crear la reserva
        $service = app(InventoryMeliOrderReservationService::class);
        $service->apply($order->load('items'));

        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(20, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(17, app(InventoryStockService::class)->availableStock($product));

        // 2. El paquete se despacha (shipped)
        $order->update(['shipping_status' => 'shipped']);

        // 3. Correr en modo dry-run
        $this->artisan('inventory:meli-fulfill-shipped', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('SIMULAR CUMPLIMIENTO');

        // Sigue activa porque fue dry-run
        $this->assertSame(1, InventoryReservation::active()->count());

        // 4. Correr para aplicar
        $this->artisan('inventory:meli-fulfill-shipped')
            ->assertExitCode(0)
            ->expectsOutputToContain('CUMPLIDA');

        // Ya no hay reservas activas y el stock físico se redujo a 17
        $this->assertSame(0, InventoryReservation::active()->count());
        $this->assertSame(17, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(17, app(InventoryStockService::class)->availableStock($product));

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $product->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -3,
        ]);
    }
}
