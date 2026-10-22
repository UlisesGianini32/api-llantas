<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\Supplier;
use App\Models\SupplierBrand;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SuppliersTest extends TestCase
{
    private User $admin;

    private User $operations;

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
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('role', 32)->default('admin');
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Run inventory and purchase orders migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
        (require database_path('migrations/2026_10_07_193000_create_suppliers_and_supplier_brands_tables.php'))->up();
        (require database_path('migrations/2026_10_21_160000_create_supplier_products_table.php'))->up();

        $this->admin = User::forceCreate([
            'name' => 'Admin Ulises',
            'email' => 'admin@test.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->operations = User::forceCreate([
            'name' => 'Operaciones',
            'email' => 'ops@test.com',
            'role' => User::ROLE_OPERATIONS,
        ]);
    }

    public function test_admin_can_view_suppliers_index_and_see_summary(): void
    {
        $supplier = Supplier::create([
            'name' => 'Distribuidora Barber Pro',
            'rfc' => 'DBP200101XYZ',
            'contact_name' => 'Carlos Mendoza',
            'phone' => '6621234567',
            'email' => 'ventas@barberpro.com',
            'lead_time_days' => 5,
            'credit_days' => 30,
            'credit_limit' => 50000,
        ]);

        $supplier->syncBrands(['WAHL', 'BABYLISS']);

        $res = $this->actingAs($this->admin)->get(route('purchasing.suppliers.index'));

        $res->assertOk();
        $res->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Suppliers/Index')
            ->has('suppliers', 1)
            ->where('suppliers.0.name', 'Distribuidora Barber Pro')
            ->where('suppliers.0.brands_count', 2)
            ->where('summary.total_suppliers', 1)
            ->where('summary.active_suppliers', 1)
            ->where('summary.credit_suppliers', 1)
            ->where('summary.total_mapped_brands', 2)
        );
    }

    public function test_admin_can_register_supplier_with_assigned_brands(): void
    {
        // Registrar producto existente de esa marca
        InventoryProduct::create([
            'sku' => 'WAHL-CLIP-01',
            'name' => 'Wahl Magic Clip',
            'brand' => 'WAHL',
            'product_type' => 'SIMPLE',
            'cost' => 1200,
            'price_public' => 1800,
        ]);

        $payload = [
            'name' => 'Grupo Distribuidor Belleza',
            'rfc' => 'GDB123456ABC',
            'contact_name' => 'Laura Garza',
            'phone' => '6629876543',
            'email' => 'pedidos@bellezagroup.com',
            'lead_time_days' => 7,
            'credit_days' => 15,
            'credit_limit' => 25000,
            'payment_method_preferred' => 'Transferencia',
            'notes' => 'Atiende antes de las 3pm',
            'brands' => [
                ['brand' => 'WAHL', 'is_primary' => true],
                ['brand' => 'ANDIS', 'is_primary' => false],
            ],
            'update_catalog_products' => true,
        ];

        $res = $this->actingAs($this->admin)->post(route('purchasing.suppliers.store'), $payload);

        $res->assertRedirect(route('purchasing.suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Grupo Distribuidor Belleza',
            'rfc' => 'GDB123456ABC',
            'contact_name' => 'Laura Garza',
            'lead_time_days' => 7,
            'credit_days' => 15,
        ]);

        $supplier = Supplier::where('name', 'Grupo Distribuidor Belleza')->first();
        $this->assertNotNull($supplier);

        $this->assertDatabaseHas('supplier_brands', [
            'supplier_id' => $supplier->id,
            'brand' => 'WAHL',
            'is_primary' => 1,
        ]);

        $this->assertDatabaseHas('supplier_brands', [
            'supplier_id' => $supplier->id,
            'brand' => 'ANDIS',
            'is_primary' => 0,
        ]);

        // Verificar que actualizó el proveedor en los productos existentes de la marca WAHL
        $this->assertDatabaseHas('inventory_products', [
            'sku' => 'WAHL-CLIP-01',
            'supplier' => 'Grupo Distribuidor Belleza',
        ]);
    }

    public function test_admin_can_view_supplier_detail_with_brands_and_products(): void
    {
        $supplier = Supplier::create([
            'name' => 'Distribuidor Alfa',
            'lead_time_days' => 4,
            'credit_days' => 7,
        ]);

        $supplier->syncBrands(['REVLON']);

        InventoryProduct::create([
            'sku' => 'REV-SECADOR-01',
            'name' => 'Secadora Revlon One-Step',
            'brand' => 'REVLON',
            'product_type' => 'SIMPLE',
            'cost' => 600,
            'price_public' => 950,
        ]);

        $res = $this->actingAs($this->admin)->get(route('purchasing.suppliers.show', $supplier));

        $res->assertOk();
        $res->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Suppliers/Show')
            ->where('supplier.name', 'Distribuidor Alfa')
            ->where('supplier.brands.0.brand', 'REVLON')
            ->where('supplier.brands.0.catalog_products_count', 1)
            ->has('products', 1)
            ->where('products.0.sku', 'REV-SECADOR-01')
        );
    }

    public function test_admin_can_update_supplier_and_sync_brands(): void
    {
        $supplier = Supplier::create([
            'name' => 'Importadora Norte',
            'lead_time_days' => 10,
            'credit_days' => 0,
        ]);

        $supplier->syncBrands(['OSTER', 'WAHL']);

        $updatePayload = [
            'name' => 'Importadora Norte S.A.',
            'lead_time_days' => 12,
            'credit_days' => 15,
            'credit_limit' => 30000,
            'brands' => [
                ['brand' => 'WAHL', 'is_primary' => true],
                ['brand' => 'BABYLISS', 'is_primary' => true], // Agregada Babyliss, quitada Oster
            ],
        ];

        $res = $this->actingAs($this->admin)->put(route('purchasing.suppliers.update', $supplier), $updatePayload);

        $res->assertRedirect();

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Importadora Norte S.A.',
            'credit_days' => 15,
        ]);

        $this->assertDatabaseHas('supplier_brands', [
            'supplier_id' => $supplier->id,
            'brand' => 'BABYLISS',
        ]);

        $this->assertDatabaseMissing('supplier_brands', [
            'supplier_id' => $supplier->id,
            'brand' => 'OSTER',
        ]);
    }

    public function test_admin_can_toggle_supplier_active_status(): void
    {
        $supplier = Supplier::create([
            'name' => 'Distribuidor Beta',
            'is_active' => true,
        ]);

        $res = $this->actingAs($this->admin)->patch(route('purchasing.suppliers.toggle', $supplier));

        $res->assertRedirect();
        $this->assertFalse($supplier->fresh()->is_active);

        $res2 = $this->actingAs($this->admin)->patch(route('purchasing.suppliers.toggle', $supplier));
        $res2->assertRedirect();
        $this->assertTrue($supplier->fresh()->is_active);
    }

    public function test_supplier_search_api_returns_matching_suppliers(): void
    {
        $sup1 = Supplier::create(['name' => 'Proveedor Global Hair']);
        $sup1->syncBrands(['LOREAL', 'KERASTASE']);

        $sup2 = Supplier::create(['name' => 'Proveedor Barbero']);
        $sup2->syncBrands(['WAHL']);

        // Buscar por marca LOREAL
        $resBrand = $this->actingAs($this->admin)->getJson(route('purchasing.suppliers.search', ['brand' => 'LOREAL']));
        $resBrand->assertOk();
        $this->assertCount(1, $resBrand->json('suppliers'));
        $this->assertSame('Proveedor Global Hair', $resBrand->json('suppliers.0.name'));

        // Buscar por texto 'Barbero'
        $resText = $this->actingAs($this->admin)->getJson(route('purchasing.suppliers.search', ['q' => 'Barbero']));
        $resText->assertOk();
        $this->assertCount(1, $resText->json('suppliers'));
        $this->assertSame('Proveedor Barbero', $resText->json('suppliers.0.name'));
    }

    public function test_admin_can_view_supplier_codes_index(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Wella']);
        $supplier->syncBrands(['WELLA']);

        $product = InventoryProduct::create([
            'sku' => 'WEL-SHP-250',
            'name' => 'Wella Invigo Shampoo 250ml',
            'brand' => 'WELLA',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 200.00,
        ]);

        $res = $this->actingAs($this->admin)->get(route('purchasing.suppliers.codes', ['supplier_id' => $supplier->id]));

        $res->assertOk();
        $res->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Purchasing/Suppliers/Codes')
            ->where('selectedSupplier.name', 'Proveedor Wella')
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'WEL-SHP-250')
        );
    }

    public function test_admin_can_save_and_update_supplier_product_code(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Joico']);

        $product = InventoryProduct::create([
            'sku' => 'JOI-KPAK-1L',
            'name' => 'Joico K-Pak Shampoo 1000ml',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 380.00,
        ]);

        // Guardar código inicial
        $res = $this->actingAs($this->admin)->postJson(route('purchasing.suppliers.codes.save'), [
            'inventory_product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'supplier_sku' => '60515',
            'supplier_cost' => 350.00,
        ]);

        $res->assertOk();
        $this->assertTrue($res->json('ok'));

        $this->assertDatabaseHas('supplier_products', [
            'inventory_product_id' => $product->id,
            'supplier_name' => 'Proveedor Joico',
            'supplier_sku' => '60515',
            'supplier_cost' => 350.00,
        ]);

        // Actualizar código y costo
        $resUpdate = $this->actingAs($this->admin)->postJson(route('purchasing.suppliers.codes.save'), [
            'inventory_product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'supplier_sku' => '60515-REV2',
            'supplier_cost' => 360.00,
        ]);

        $resUpdate->assertOk();
        $this->assertDatabaseHas('supplier_products', [
            'inventory_product_id' => $product->id,
            'supplier_name' => 'Proveedor Joico',
            'supplier_sku' => '60515-REV2',
            'supplier_cost' => 360.00,
        ]);
        $this->assertSame(1, \App\Models\SupplierProduct::where('inventory_product_id', $product->id)->count());
    }

    public function test_admin_can_delete_supplier_product_code(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Alfaparf']);

        $product = InventoryProduct::create([
            'sku' => 'ALF-SE-1L',
            'name' => 'Semi Di Lino Diamond 1000ml',
            'brand' => 'ALFAPARF',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 250.00,
        ]);

        \App\Models\SupplierProduct::create([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'inventory_product_id' => $product->id,
            'supplier_sku' => 'ALF-9901',
            'is_primary' => true,
        ]);

        $res = $this->actingAs($this->admin)->postJson(route('purchasing.suppliers.codes.delete'), [
            'inventory_product_id' => $product->id,
            'supplier_name' => $supplier->name,
        ]);

        $res->assertOk();
        $this->assertDatabaseMissing('supplier_products', [
            'inventory_product_id' => $product->id,
            'supplier_name' => 'Proveedor Alfaparf',
        ]);
    }

    public function test_admin_can_search_products_to_link_to_supplier(): void
    {
        $product = InventoryProduct::create([
            'sku' => 'SCHW-OSIS-DUST',
            'name' => 'Schwarzkopf Osis+ Dust It 10g',
            'barcode' => '733257000123',
            'brand' => 'SCHWARZKOPF',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 180.00,
        ]);

        $res = $this->actingAs($this->admin)->getJson(route('purchasing.suppliers.codes.search-products', ['q' => 'Dust It']));

        $res->assertOk();
        $this->assertCount(1, $res->json('products'));
        $this->assertSame('SCHW-OSIS-DUST', $res->json('products.0.sku'));
    }

    public function test_codes_index_and_search_exclude_kits_and_duos(): void
    {
        $supplier = Supplier::create(['name' => 'Proveedor Joico']);
        $supplier->syncBrands(['JOICO']);

        // Producto regular comprable
        $regular = InventoryProduct::create([
            'sku' => 'JOIC-DEFY-SHAMP',
            'name' => 'Joico Defy Damage Shampoo 300ml',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        // Kit por SKU
        InventoryProduct::create([
            'sku' => 'KITJOIC0333',
            'name' => 'Defy Damage Shampoo + Acondicionador',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        // Duo por SKU
        InventoryProduct::create([
            'sku' => 'JOIC-KPAK-DUO',
            'name' => 'K-Pak Shampoo 300ml + Mascarilla',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        // Kit por Nombre
        InventoryProduct::create([
            'sku' => 'JOIC015',
            'name' => 'Kit JOICO Defy Damage Completo',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        // Duo por Nombre
        InventoryProduct::create([
            'sku' => 'JOIC999',
            'name' => 'Dúo Joico Color Therapy',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        // Tipo KIT explícito
        InventoryProduct::create([
            'sku' => 'JOIC-BUNDLE-1',
            'name' => 'Tratamiento Reconstructor',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::KIT,
            'is_active' => true,
        ]);

        // Verificar en codesIndex
        $res = $this->actingAs($this->admin)->get(route('purchasing.suppliers.codes', ['supplier_id' => $supplier->id]));
        $res->assertOk();
        $res->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Suppliers/Codes')
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'JOIC-DEFY-SHAMP')
            ->where('summary.total', 1)
        );

        // Verificar en búsqueda de productos
        $searchRes = $this->actingAs($this->admin)->getJson(route('purchasing.suppliers.codes.search-products', ['q' => 'JOIC']));
        $searchRes->assertOk();
        $products = collect($searchRes->json('products'));
        $this->assertTrue($products->contains('sku', 'JOIC-DEFY-SHAMP'));
        $this->assertFalse($products->contains('sku', 'KITJOIC0333'));
        $this->assertFalse($products->contains('sku', 'JOIC-KPAK-DUO'));
        $this->assertFalse($products->contains('sku', 'JOIC015'));
        $this->assertFalse($products->contains('sku', 'JOIC999'));
        $this->assertFalse($products->contains('sku', 'JOIC-BUNDLE-1'));
    }

    public function test_cleanup_migration_deletes_supplier_products_for_kits_and_sets_kit_type(): void
    {
        $supplier = Supplier::create(['name' => 'Joico Proveedor Limpieza']);

        $kit = InventoryProduct::create([
            'sku' => 'KITJOIC022',
            'name' => 'Kit Joico Especial',
            'brand' => 'JOICO',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        \App\Models\SupplierProduct::create([
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'inventory_product_id' => $kit->id,
            'supplier_sku' => 'SUP-KIT-01',
            'is_primary' => true,
        ]);

        $this->assertDatabaseHas('supplier_products', ['inventory_product_id' => $kit->id]);

        // Ejecutar la migración de limpieza
        (require database_path('migrations/2026_10_21_210000_cleanup_kits_and_duos_from_suppliers.php'))->up();

        // El producto debe ser marcado como KIT
        $this->assertSame(InventoryProduct::KIT, $kit->fresh()->product_type);

        // El registro en supplier_products debe haber sido eliminado
        $this->assertDatabaseMissing('supplier_products', ['inventory_product_id' => $kit->id]);
    }
}

