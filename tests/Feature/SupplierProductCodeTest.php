<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierProductCodeTest extends TestCase
{
    private User $admin;

    private InventoryLocation $location;

    private InventoryProduct $productA;

    private InventoryProduct $productB;

    private PurchaseOrderService $service;

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

        // Run migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
        (require database_path('migrations/2026_10_07_193000_create_suppliers_and_supplier_brands_tables.php'))->up();
        (require database_path('migrations/2026_10_21_160000_create_supplier_products_table.php'))->up();

        $this->admin = User::forceCreate([
            'name' => 'Admin Compras',
            'email' => 'compras@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'BOD-CENTRAL',
            'name' => 'Bodega Central SBS',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->productA = InventoryProduct::create([
            'sku' => 'JOICO-KPAK-SHP-1L',
            'name' => 'K-PAK Shampoo Limpieza 1 Lt',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 350.00,
            'brand' => 'JOICO',
            'supplier' => 'Joico México',
        ]);

        $this->productB = InventoryProduct::create([
            'sku' => 'ALFA-COLOR-WEAR-60',
            'name' => 'Color Wear Gloss Toner',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 120.00,
            'brand' => 'ALFAPARF',
            'supplier' => 'Alfaparf Milano',
        ]);

        $this->service = app(PurchaseOrderService::class);
    }

    public function test_creates_purchase_order_with_supplier_sku_and_persists_to_supplier_products(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(route('purchasing.orders.store'), [
            'supplier_name' => 'Joico México',
            'brand' => 'JOICO',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'supplier_sku' => 'J12505',
                    'quantity_ordered' => 12,
                    'unit_cost' => 350.00,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $orderId = $response->json('order.id');

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $orderId,
            'inventory_product_id' => $this->productA->id,
            'sku' => 'JOICO-KPAK-SHP-1L',
            'supplier_sku' => 'J12505',
            'quantity_ordered' => 12,
        ]);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_name' => 'Joico México',
            'inventory_product_id' => $this->productA->id,
            'supplier_sku' => 'J12505',
            'is_primary' => true,
        ]);
    }

    public function test_auto_resolves_supplier_sku_when_not_provided_in_order(): void
    {
        SupplierProduct::create([
            'supplier_name' => 'Alfaparf Milano',
            'inventory_product_id' => $this->productB->id,
            'supplier_sku' => 'PF022471',
            'supplier_product_name' => 'Color Wear Gloss Toner',
            'supplier_cost' => 120.00,
            'is_primary' => true,
        ]);

        $order = $this->service->createOrder([
            'supplier_name' => 'Alfaparf Milano',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 6,
                    'unit_cost' => 120.00,
                ],
            ],
        ], $this->admin);

        $firstItem = $order->items->first();
        $this->assertNotNull($firstItem);
        $this->assertEquals('PF022471', $firstItem->supplier_sku);
    }

    public function test_pdf_displays_supplier_sku_and_column(): void
    {
        $this->actingAs($this->admin);

        $order = $this->service->createOrder([
            'supplier_name' => 'Joico México',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'supplier_sku' => 'J15992',
                    'quantity_ordered' => 24,
                    'unit_cost' => 350.00,
                ],
            ],
        ], $this->admin);

        $response = $this->get(route('purchasing.orders.pdf', $order));

        $response->assertStatus(200);
        $response->assertSee('Cód. Proveedor');
        $response->assertSee('J15992');
        $response->assertSee($this->productA->name);
    }

    public function test_can_save_supplier_sku_endpoint(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(route('purchasing.orders.save-supplier-sku'), [
            'inventory_product_id' => $this->productA->id,
            'supplier_name' => 'Joico Distribuciones',
            'supplier_sku' => 'J112032',
            'supplier_cost' => 340.50,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true]);

        $this->assertDatabaseHas('supplier_products', [
            'supplier_name' => 'Joico Distribuciones',
            'inventory_product_id' => $this->productA->id,
            'supplier_sku' => 'J112032',
            'supplier_cost' => 340.50,
        ]);
    }

    public function test_search_products_by_supplier_sku(): void
    {
        $this->actingAs($this->admin);

        SupplierProduct::create([
            'supplier_name' => 'Tec Italy',
            'inventory_product_id' => $this->productA->id,
            'supplier_sku' => 'TEC-2723743',
            'supplier_product_name' => 'Due Faccetta',
            'is_primary' => true,
        ]);

        $response = $this->getJson(route('purchasing.orders.search-products', ['q' => '2723743']));

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $this->assertEquals($this->productA->id, $data[0]['id']);
    }

    public function test_artisan_import_ignores_fanola_olaplex_and_icon(): void
    {
        Artisan::call('purchasing:import-supplier-codes');

        // Fanola, Olaplex e Icon deben ser 100% ignoradas
        $this->assertDatabaseMissing('supplier_products', [
            'supplier_name' => 'Fanola',
        ]);
        $this->assertDatabaseMissing('supplier_products', [
            'supplier_name' => 'Olaplex',
        ]);
        $this->assertDatabaseMissing('supplier_products', [
            'supplier_name' => 'Icon',
        ]);
    }
}
