<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
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

        $productsMigration = require database_path('migrations/2026_09_24_000001_create_inventory_products_table.php');
        $productsMigration->up();
        $locationsMigration = require database_path('migrations/2026_09_24_000002_create_inventory_locations_table.php');
        $locationsMigration->up();
        $primaryLocationMigration = require database_path('migrations/2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php');
        $primaryLocationMigration->up();
        $movementsMigration = require database_path('migrations/2026_09_24_000004_create_inventory_movements_table.php');
        $movementsMigration->up();
        $reservationsMigration = require database_path('migrations/2026_09_24_000005_create_inventory_reservations_table.php');
        $reservationsMigration->up();
        $productTypeMigration = require database_path('migrations/2026_09_24_000006_add_product_type_to_inventory_products_table.php');
        $productTypeMigration->up();
        $kitComponentsMigration = require database_path('migrations/2026_09_24_000007_create_inventory_kit_components_table.php');
        $kitComponentsMigration->up();
        $kitReservationsMigration = require database_path('migrations/2026_09_24_000008_create_inventory_kit_reservations_table.php');
        $kitReservationsMigration->up();
        $channelLinksMigration = require database_path('migrations/2026_09_25_000001_create_inventory_channel_links_table.php');
        $channelLinksMigration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_channel_links');
        Schema::dropIfExists('inventory_kit_reservations');
        Schema::dropIfExists('inventory_kit_components');
        Schema::dropIfExists('inventory_reservations');
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropIndex(['product_type']);
        });
        Schema::dropIfExists('inventory_movements');
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropForeign(['primary_location_id']);
        });
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
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

    public function test_admin_can_create_and_normalize_an_inventory_location(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('inventory.locations.store'), [
            'code' => '  pasillo-a-1  ',
            'name' => 'Pasillo A, Estante 1',
            'sort_order' => null,
        ])->assertRedirect(route('inventory.locations.index'));

        $this->assertDatabaseHas('inventory_locations', [
            'code' => 'PASILLO-A-1',
            'name' => 'Pasillo A, Estante 1',
            'sort_order' => null,
        ]);
    }

    public function test_location_code_is_unique_and_sort_order_cannot_be_negative(): void
    {
        $this->actingAs($this->admin());
        InventoryLocation::create(['code' => 'A1-1']);

        $this->post(route('inventory.locations.store'), [
            'code' => ' a1-1 ',
            'name' => 'Duplicada',
        ])->assertSessionHasErrors('code');

        $this->post(route('inventory.locations.store'), [
            'code' => 'A1-2',
            'sort_order' => -1,
        ])->assertSessionHasErrors('sort_order');
    }

    public function test_location_can_be_edited_and_deactivated(): void
    {
        $this->actingAs($this->admin());
        $location = InventoryLocation::create(['code' => 'A1-1', 'is_active' => true]);

        $this->put(route('inventory.locations.update', $location), [
            'code' => ' a2-1 ',
            'name' => 'Pasillo A',
            'sort_order' => 0,
            'is_active' => true,
        ])->assertRedirect(route('inventory.locations.index'));

        $this->patch(route('inventory.locations.toggle', $location->fresh()))->assertRedirect();

        $this->assertDatabaseHas('inventory_locations', [
            'id' => $location->id,
            'code' => 'A2-1',
            'is_active' => 0,
        ]);
    }

    public function test_product_can_be_unassigned_or_assigned_to_an_existing_location(): void
    {
        $this->actingAs($this->admin());
        $location = InventoryLocation::create(['code' => 'B2-3']);

        $this->post(route('inventory.products.store'), [
            'sku' => 'SKU-LOCATION',
            'name' => 'Producto ubicado',
            'primary_location_id' => $location->id,
        ])->assertRedirect();

        $product = InventoryProduct::query()->sole();
        $this->assertSame($location->id, $product->primary_location_id);
        $this->assertSame($location->id, $product->primaryLocation->id);
        $this->assertTrue($location->fresh()->products->contains($product));

        $this->put(route('inventory.products.update', $product), [
            'sku' => $product->sku,
            'name' => $product->name,
            'primary_location_id' => null,
        ])->assertRedirect();
        $this->assertNull($product->fresh()->primary_location_id);
    }

    public function test_product_rejects_a_nonexistent_location(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('inventory.products.store'), [
            'sku' => 'SKU-BAD-LOCATION',
            'name' => 'Producto sin ubicación válida',
            'primary_location_id' => 99999,
        ])->assertSessionHasErrors('primary_location_id');
    }

    public function test_location_list_search_and_product_count_work_without_n_plus_one_data_shape(): void
    {
        $this->actingAs($this->admin());
        $location = InventoryLocation::create(['code' => 'A1-1', 'name' => 'Pasillo Alpha']);
        InventoryProduct::create(['sku' => 'SKU-COUNT', 'name' => 'Producto contado', 'primary_location_id' => $location->id]);

        $this->get(route('inventory.locations.index', ['search' => 'A1-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('locations.data.0.code', 'A1-1')
                ->where('locations.data.0.products_count', 1));

        $this->get(route('inventory.locations.index', ['search' => 'Pasillo Alpha']))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->where('locations.data.0.code', 'A1-1'));
    }

    public function test_location_detail_includes_its_primary_products(): void
    {
        $this->actingAs($this->admin());
        $location = InventoryLocation::create(['code' => 'D4-2', 'name' => 'Detalle']);
        $product = InventoryProduct::create([
            'sku' => 'SKU-DETAIL-LOCATION',
            'name' => 'Producto del detalle',
            'barcode' => '7500000012345',
            'primary_location_id' => $location->id,
        ]);

        $this->get(route('inventory.locations.show', $location))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('location.code', 'D4-2')
                ->where('location.products.0.id', $product->id)
                ->where('location.products.0.sku', 'SKU-DETAIL-LOCATION')
                ->where('location.products.0.barcode', '7500000012345'));
    }

    public function test_location_list_orders_defined_sort_order_before_null_then_code(): void
    {
        $this->actingAs($this->admin());
        InventoryLocation::create(['code' => 'Z9-9', 'sort_order' => null]);
        InventoryLocation::create(['code' => 'B2-1', 'sort_order' => 2]);
        InventoryLocation::create(['code' => 'A1-1', 'sort_order' => 1]);

        $this->get(route('inventory.locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('locations.data.0.code', 'A1-1')
                ->where('locations.data.1.code', 'B2-1')
                ->where('locations.data.2.code', 'Z9-9'));
    }

    public function test_product_search_by_location_code_and_inactive_location_remains_assigned(): void
    {
        $this->actingAs($this->admin());
        $location = InventoryLocation::create(['code' => 'C3-4', 'is_active' => true]);
        $product = InventoryProduct::create(['sku' => 'SKU-SEARCH-LOCATION', 'name' => 'Producto por ubicación', 'primary_location_id' => $location->id]);

        $this->patch(route('inventory.locations.toggle', $location))->assertRedirect();
        $this->assertSame($location->id, $product->fresh()->primary_location_id);

        $this->get(route('inventory.products.index', ['search' => 'C3-4']))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->where('products.data.0.sku', $product->sku));

        $this->get(route('inventory.products.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->where('locations', []));

        $this->get(route('inventory.products.edit', $product))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->where('locations.0.id', $location->id));
    }

    public function test_operations_user_cannot_manage_inventory_locations(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATIONS]));

        $this->get(route('inventory.locations.index'))->assertForbidden();
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
