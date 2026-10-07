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
}
