<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchasingUsaOrderTest extends TestCase
{
    private User $admin;
    private InventoryLocation $location;
    private InventoryProduct $existingBiotin;
    private InventoryProduct $existingOmega;
    private InventoryProduct $existingNative;

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

        // Simular productos preexistentes dados de alta en catálogo con SKUs de Mercado Libre
        $this->existingBiotin = InventoryProduct::create([
            'sku' => 'ML-SV-BIOTIN-10K-60T',
            'name' => 'Spring Valley Biotin 10,000 mcg 60 tabletas',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 150.00,
            'brand' => 'Spring Valley',
        ]);

        $this->existingOmega = InventoryProduct::create([
            'sku' => 'ML-SV-OMEGA-1000-180S',
            'name' => 'Spring Valley Omega-3 1000 Mg 180 Softgels',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 220.00,
            'brand' => 'Spring Valley',
        ]);

        $this->existingNative = InventoryProduct::create([
            'sku' => 'ML-NAT-SHP-CEDRO',
            'name' => 'Shampoo Vegano 2 En 1 Native Hair Care Sal De Mar Y Cedro',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 180.00,
            'brand' => 'Native',
        ]);
    }

    public function test_purchasing_create_usa_order_excludes_unwanted_brands_and_preserves_existing_skus(): void
    {
        $exitCode = Artisan::call('purchasing:create-usa-order');
        $this->assertSame(0, $exitCode);

        // 1. Debe haber creado una orden de compra
        $order = PurchaseOrder::where('supplier_name', 'Compras USA')->first();
        $this->assertNotNull($order);

        // 2. Partidas totales deben ser 67 (sin las 18 partidas de las 5 marcas excluidas)
        $this->assertSame(67, $order->items()->count());

        // 3. Ningún item debe tener marcas prohibidas
        $prohibitedBrands = [
            "Nature's Bounty",
            "Puritan's Pride",
            "Goli Nutrition",
            "Natrol",
            "Siberian Green",
        ];
        foreach ($order->items as $item) {
            $product = $item->inventoryProduct;
            $this->assertFalse(in_array($product->brand, $prohibitedBrands, true), "Marca prohibida encontrada: {$product->brand}");
        }

        // 4. Los productos existentes DEBEN CONSERVAR SU SKU EXACTO
        $biotinItem = $order->items()->where('inventory_product_id', $this->existingBiotin->id)->first();
        $this->assertNotNull($biotinItem, 'El item de biotina debe vincularse al producto existente en catálogo');
        $this->assertSame('ML-SV-BIOTIN-10K-60T', $biotinItem->sku, 'El SKU en la orden debe ser el SKU original de catálogo');
        $this->assertSame('ML-SV-BIOTIN-10K-60T', $this->existingBiotin->fresh()->sku, 'El SKU del producto en catálogo NO debe ser modificado');

        $omegaItem = $order->items()->where('inventory_product_id', $this->existingOmega->id)->first();
        $this->assertNotNull($omegaItem);
        $this->assertSame('ML-SV-OMEGA-1000-180S', $omegaItem->sku);
        $this->assertSame('ML-SV-OMEGA-1000-180S', $this->existingOmega->fresh()->sku);

        $nativeItem = $order->items()->where('inventory_product_id', $this->existingNative->id)->first();
        $this->assertNotNull($nativeItem);
        $this->assertSame('ML-NAT-SHP-CEDRO', $nativeItem->sku);
        $this->assertSame('ML-NAT-SHP-CEDRO', $this->existingNative->fresh()->sku);
    }

    public function test_purchasing_create_usa_order_cleans_previous_drafts_on_rerun(): void
    {
        // Primera corrida
        Artisan::call('purchasing:create-usa-order');
        $firstCount = PurchaseOrder::where('supplier_name', 'Compras USA')->count();
        $this->assertSame(1, $firstCount);

        // Segunda corrida: debe limpiar la orden previa y dejar exactamente 1 orden
        Artisan::call('purchasing:create-usa-order');
        $secondCount = PurchaseOrder::where('supplier_name', 'Compras USA')->count();
        $this->assertSame(1, $secondCount);
    }
}
