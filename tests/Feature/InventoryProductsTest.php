<?php

namespace Tests\Feature;

use App\Models\InventoryProduct;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryProductsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
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
            $table->string('role', 32)->default('operations');
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
        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
        });

        $migration = require database_path('migrations/2026_09_24_000001_create_inventory_products_table.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_admin_can_create_product_with_trimmed_sku_and_null_barcode(): void
    {
        $this->actingAs($this->admin());

        $response = $this->post(route('inventory.products.store'), [
            'sku' => '  BEAUTY-001  ',
            'barcode' => '',
            'name' => 'Shampoo profesional',
            'cost' => '25.50',
            'price_mercado_libre' => '60',
        ]);

        $response->assertRedirect(route('inventory.products.index'));
        $this->assertDatabaseHas('inventory_products', [
            'sku' => 'BEAUTY-001',
            'barcode' => null,
            'name' => 'Shampoo profesional',
        ]);
        $this->assertFalse(Schema::hasColumn('inventory_products', 'stock'));
    }

    public function test_duplicate_sku_and_barcode_are_rejected(): void
    {
        $this->actingAs($this->admin());
        InventoryProduct::create([
            'sku' => 'SKU-EXISTING',
            'barcode' => '000123',
            'name' => 'Producto existente',
        ]);

        $this->from(route('inventory.products.create'))
            ->post(route('inventory.products.store'), [
                'sku' => ' SKU-EXISTING ',
                'barcode' => '000124',
                'name' => 'Otro producto',
            ])
            ->assertSessionHasErrors('sku');

        $this->from(route('inventory.products.create'))
            ->post(route('inventory.products.store'), [
                'sku' => 'SKU-OTHER',
                'barcode' => '000123',
                'name' => 'Otro producto',
            ])
            ->assertSessionHasErrors('barcode');
    }

    public function test_barcode_keeps_leading_zeroes_as_a_string(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('inventory.products.store'), [
            'sku' => 'SKU-BARCODE',
            'barcode' => '000000123456',
            'name' => 'Producto con código',
        ])->assertRedirect();

        $product = InventoryProduct::query()->sole();
        $this->assertSame('000000123456', $product->barcode);
        $this->assertIsString($product->barcode);
    }

    public function test_negative_cost_and_prices_are_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('inventory.products.store'), [
            'sku' => 'SKU-NEGATIVE',
            'name' => 'Producto inválido',
            'cost' => -1,
            'price_mercado_libre' => -2,
            'price_amazon' => -3,
            'price_stylist' => -4,
            'price_public' => -5,
        ])->assertSessionHasErrors([
            'cost',
            'price_mercado_libre',
            'price_amazon',
            'price_stylist',
            'price_public',
        ]);
    }

    public function test_admin_can_edit_and_deactivate_a_product(): void
    {
        $this->actingAs($this->admin());
        $product = InventoryProduct::create([
            'sku' => 'SKU-EDIT',
            'name' => 'Nombre inicial',
            'is_active' => true,
        ]);

        $this->put(route('inventory.products.update', $product), [
            'sku' => ' SKU-EDIT-UPDATED ',
            'name' => 'Nombre actualizado',
            'is_active' => true,
        ])->assertRedirect(route('inventory.products.index'));

        $this->patch(route('inventory.products.toggle', $product->fresh()))
            ->assertRedirect();

        $this->assertDatabaseHas('inventory_products', [
            'id' => $product->id,
            'sku' => 'SKU-EDIT-UPDATED',
            'name' => 'Nombre actualizado',
            'is_active' => 0,
        ]);
    }

    public function test_search_matches_sku_barcode_and_name(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        InventoryProduct::create(['sku' => 'SKU-NAME', 'name' => 'Crema facial', 'barcode' => null]);
        InventoryProduct::create(['sku' => 'SKU-BAR', 'name' => 'Producto dos', 'barcode' => '7500000011111']);
        InventoryProduct::create(['sku' => 'SKU-OTHER', 'name' => 'Producto tres', 'barcode' => '7500000099999']);

        foreach ([
            ['search' => 'SKU-NAME', 'sku' => 'SKU-NAME'],
            ['search' => '7500000011111', 'sku' => 'SKU-BAR'],
            ['search' => 'Crema facial', 'sku' => 'SKU-NAME'],
        ] as $case) {
            $this->get(route('inventory.products.index', ['search' => $case['search']]))
                ->assertOk()
                ->assertInertia(fn (Assert $page): Assert => $page
                    ->where('filters.search', $case['search'])
                    ->where('products.data.0.sku', $case['sku']));
        }
    }

    public function test_inventory_migration_is_separate_from_existing_llantas_table(): void
    {
        $this->assertTrue(Schema::hasTable('llantas'));
        $this->assertTrue(Schema::hasTable('inventory_products'));
        $this->assertFalse(Schema::hasColumn('inventory_products', 'stock'));
        $this->assertFalse(Schema::hasColumn('inventory_products', 'llanta_id'));
    }

    public function test_operations_user_cannot_manage_inventory_catalog(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATIONS]));

        $this->get(route('inventory.products.index'))->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
