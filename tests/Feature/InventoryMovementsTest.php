<?php

namespace Tests\Feature;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryMovementsTest extends TestCase
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
    }

    protected function tearDown(): void
    {
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

    public function test_admin_can_register_initial_movement_with_positive_quantity(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();

        $this->actingAs($admin)
            ->post(route('inventory.movements.store'), [
                'inventory_product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'type' => InventoryMovement::INITIAL,
                'quantity' => 10,
                'reference' => 'AJUSTE-INICIAL-001',
            ])
            ->assertRedirect(route('inventory.movements.index'));

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'created_by' => $admin->id,
        ]);
    }

    public function test_receipt_increases_stock_and_damage_adjustment_out_and_sale_decrease_it(): void
    {
        $service = app(InventoryMovementService::class);
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();

        $service->recordManual($this->movementData($product, $location, InventoryMovement::INITIAL, 20), $admin);
        $service->recordManual($this->movementData($product, $location, InventoryMovement::RECEIPT, 5), $admin);
        $service->recordManual($this->movementData($product, $location, InventoryMovement::DAMAGE, 2), $admin);
        $service->recordManual($this->movementData($product, $location, InventoryMovement::ADJUSTMENT_OUT, 1), $admin);
        $service->recordManual($this->movementData($product, $location, InventoryMovement::SALE, 3), $admin);

        $this->assertSame(19, app(InventoryStockService::class)->productStock($product));
    }

    public function test_return_increases_stock(): void
    {
        $service = app(InventoryMovementService::class);
        [$product, $location] = $this->productAndLocation();

        $service->recordManual($this->movementData($product, $location, InventoryMovement::RETURN, 4));

        $this->assertSame(4, app(InventoryStockService::class)->productStock($product));
    }

    public function test_zero_and_invalid_manual_quantity_are_rejected(): void
    {
        [$product, $location] = $this->productAndLocation();

        $this->actingAs($this->admin())
            ->post(route('inventory.movements.store'), $this->movementData($product, $location, InventoryMovement::RECEIPT, 0))
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_nonexistent_product_and_location_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('inventory.movements.store'), [
                'inventory_product_id' => 999,
                'inventory_location_id' => 999,
                'type' => InventoryMovement::RECEIPT,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors([
                'inventory_product_id',
                'inventory_location_id',
            ]);
    }

    public function test_invalid_type_and_wrong_internal_sign_are_rejected(): void
    {
        [$product, $location] = $this->productAndLocation();
        $service = app(InventoryMovementService::class);

        $this->actingAs($this->admin())
            ->post(route('inventory.movements.store'), $this->movementData($product, $location, 'UNKNOWN', 1))
            ->assertSessionHasErrors('type');

        $this->expectException(InvalidArgumentException::class);
        $service->record([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => 2,
        ]);
    }

    public function test_product_and_location_stock_are_calculated_from_the_ledger(): void
    {
        $service = app(InventoryMovementService::class);
        $admin = $this->admin();
        $product = InventoryProduct::create(['sku' => 'SKU-STOCK', 'name' => 'Producto con stock']);
        $first = InventoryLocation::create(['code' => 'A1-1']);
        $second = InventoryLocation::create(['code' => 'A1-2']);

        $service->recordManual($this->movementData($product, $first, InventoryMovement::INITIAL, 15), $admin);
        $service->recordManual($this->movementData($product, $second, InventoryMovement::RECEIPT, 10), $admin);
        $service->recordManual($this->movementData($product, $first, InventoryMovement::SALE, 2), $admin);

        $stock = app(InventoryStockService::class);
        $this->assertSame(23, $stock->productStock($product));
        $byLocation = $stock->productStockByLocation($product)->keyBy('inventory_location_id');
        $this->assertSame(13, (int) $byLocation[$first->id]->quantity);
        $this->assertSame(10, (int) $byLocation[$second->id]->quantity);
    }

    public function test_product_without_movements_has_zero_stock(): void
    {
        [$product] = $this->productAndLocation();

        $this->assertSame(0, app(InventoryStockService::class)->productStock($product));
    }

    public function test_output_cannot_exceed_stock_at_the_selected_location(): void
    {
        $service = app(InventoryMovementService::class);
        $admin = $this->admin();
        $product = InventoryProduct::create(['sku' => 'SKU-LOCATION-STOCK', 'name' => 'Producto por ubicación']);
        $first = InventoryLocation::create(['code' => 'B1-1']);
        $second = InventoryLocation::create(['code' => 'B1-2']);

        $service->recordManual($this->movementData($product, $first, InventoryMovement::INITIAL, 2), $admin);
        $service->recordManual($this->movementData($product, $second, InventoryMovement::INITIAL, 10), $admin);

        $this->expectException(InventoryInsufficientStockException::class);
        $service->recordManual($this->movementData($product, $first, InventoryMovement::SALE, 5), $admin);
    }

    public function test_external_key_is_idempotent_and_null_keys_are_allowed(): void
    {
        $service = app(InventoryMovementService::class);
        [$product, $location] = $this->productAndLocation();
        $data = $this->movementData($product, $location, InventoryMovement::RECEIPT, 3);
        $data['external_key'] = 'manual:one';

        $first = $service->recordManual($data);
        $second = $service->recordManual($data);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('inventory_movements', 1);

        $nullKey = $this->movementData($product, $location, InventoryMovement::RECEIPT, 2);
        $service->recordManual($nullKey);
        $service->recordManual($nullKey);
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_movements_store_user_and_occurred_at(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $occurredAt = Carbon::parse('2026-09-24 10:30:00');

        $movement = app(InventoryMovementService::class)->recordManual([
            ...$this->movementData($product, $location, InventoryMovement::INITIAL, 7),
            'occurred_at' => $occurredAt,
        ], $admin);

        $this->assertSame($admin->id, $movement->created_by);
        $this->assertSame($occurredAt->toISOString(), $movement->occurred_at->toISOString());
    }

    public function test_movement_index_orders_newest_first_and_filters_by_sku_barcode_location_and_type(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation('SKU-FILTER', '7500000000011', 'C1-1');
        $service = app(InventoryMovementService::class);
        $old = $service->recordManual([
            ...$this->movementData($product, $location, InventoryMovement::RECEIPT, 1),
            'occurred_at' => Carbon::parse('2026-09-20'),
            'reference' => 'OLD',
        ], $admin);
        $new = $service->recordManual([
            ...$this->movementData($product, $location, InventoryMovement::RECEIPT, 1),
            'occurred_at' => Carbon::parse('2026-09-24'),
            'reference' => 'NEW',
        ], $admin);

        $this->actingAs($admin)->get(route('inventory.movements.index'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('movements.data.0.id', $new->id)
                ->where('movements.data.1.id', $old->id));

        foreach (['SKU-FILTER', '7500000000011', 'C1-1'] as $search) {
            $this->get(route('inventory.movements.index', ['search' => $search]))
                ->assertInertia(fn (Assert $page): Assert => $page->where('movements.data.0.product.sku', 'SKU-FILTER'));
        }

        $this->get(route('inventory.movements.index', ['type' => InventoryMovement::RECEIPT]))
            ->assertInertia(fn (Assert $page): Assert => $page->where('movements.data.0.type', InventoryMovement::RECEIPT));
    }

    public function test_product_views_expose_aggregated_stock_and_recent_movements(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        app(InventoryMovementService::class)->recordManual(
            $this->movementData($product, $location, InventoryMovement::INITIAL, 8),
            $admin,
        );

        $this->actingAs($admin)->get(route('inventory.products.index'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('products.data.0.physical_stock', 8));

        $this->get(route('inventory.products.show', $product))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('physicalStock', 8)
                ->where('stockByLocation.0.location.code', 'A1-1')
                ->where('movements.0.type', InventoryMovement::INITIAL));
    }

    public function test_non_admin_cannot_create_inventory_movements(): void
    {
        [$product, $location] = $this->productAndLocation();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATIONS]))
            ->post(route('inventory.movements.store'), $this->movementData($product, $location, InventoryMovement::RECEIPT, 1))
            ->assertForbidden();
    }

    public function test_movements_have_no_edit_update_or_delete_routes_and_no_stock_dependency(): void
    {
        $this->assertFalse(Route::has('inventory.movements.edit'));
        $this->assertFalse(Route::has('inventory.movements.update'));
        $this->assertFalse(Route::has('inventory.movements.destroy'));
        $this->assertFalse(Schema::hasColumn('inventory_movements', 'stock'));
        $this->assertFalse(Schema::hasColumn('inventory_movements', 'llanta_id'));
    }

    /** @return array{0: InventoryProduct, 1: InventoryLocation} */
    private function productAndLocation(
        string $sku = 'SKU-MOVEMENT',
        string $barcode = '7500000000001',
        string $code = 'A1-1',
    ): array {
        return [
            InventoryProduct::create([
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => 'Producto de movimientos',
            ]),
            InventoryLocation::create(['code' => $code]),
        ];
    }

    /** @return array<string, mixed> */
    private function movementData(
        InventoryProduct $product,
        InventoryLocation $location,
        string $type,
        int $quantity,
    ): array {
        return [
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => $type,
            'quantity' => $quantity,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
