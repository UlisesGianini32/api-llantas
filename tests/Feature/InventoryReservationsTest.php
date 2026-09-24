<?php

namespace Tests\Feature;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\InventoryReservationService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryReservationsTest extends TestCase
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

        foreach ([
            '2026_09_24_000001_create_inventory_products_table.php',
            '2026_09_24_000002_create_inventory_locations_table.php',
            '2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php',
            '2026_09_24_000004_create_inventory_movements_table.php',
            '2026_09_24_000005_create_inventory_reservations_table.php',
        ] as $migrationFile) {
            $migration = require database_path('migrations/'.$migrationFile);
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_reservations');
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

    public function test_admin_can_create_reservation_without_changing_physical_stock(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 10, $admin);

        $this->actingAs($admin)
            ->post(route('inventory.reservations.store'), [
                'inventory_product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity' => 3,
                'reference' => 'RES-001',
            ])
            ->assertRedirect(route('inventory.reservations.index'));

        $reservation = InventoryReservation::query()->sole();
        $this->assertSame(3, $reservation->quantity);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);
        $this->assertSame(10, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(3, app(InventoryStockService::class)->reservedStock($product));
    }

    public function test_quantity_must_be_positive(): void
    {
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 5);

        $this->actingAs($this->admin())
            ->post(route('inventory.reservations.store'), [
                'inventory_product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity' => 0,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_only_active_reservations_count_as_reserved(): void
    {
        $service = app(InventoryReservationService::class);
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 20, $admin);

        $released = $service->create($this->reservationData($product, $location, 1), $admin);
        $service->release($released);
        $cancelled = $service->create($this->reservationData($product, $location, 1), $admin);
        $service->cancel($cancelled);
        $fulfilled = $service->create($this->reservationData($product, $location, 1), $admin);
        $service->fulfill($fulfilled, [], $admin);
        $expired = $service->create([
            ...$this->reservationData($product, $location, 1),
            'expires_at' => Carbon::now()->subMinute(),
        ], $admin);
        $service->expire($expired);

        $this->assertSame(0, app(InventoryStockService::class)->reservedStock($product));
        $this->assertSame(InventoryReservation::RELEASED, $released->fresh()->status);
        $this->assertSame(InventoryReservation::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(InventoryReservation::FULFILLED, $fulfilled->fresh()->status);
        $this->assertSame(InventoryReservation::EXPIRED, $expired->fresh()->status);
    }

    public function test_available_stock_is_physical_minus_reserved_and_exact_available_can_be_reserved(): void
    {
        $service = app(InventoryReservationService::class);
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 10, $admin);
        $first = $service->create($this->reservationData($product, $location, 4), $admin);

        $stock = app(InventoryStockService::class);
        $this->assertSame(10, $stock->physicalStock($product));
        $this->assertSame(4, $stock->reservedStock($product));
        $this->assertSame(6, $stock->availableStock($product));

        $service->create($this->reservationData($product, $location, 6), $admin);
        $this->assertSame(10, $stock->reservedStock($product));
        $this->assertSame(0, $stock->availableStock($product));
        $this->assertSame(4, $first->quantity);
    }

    public function test_reservation_cannot_exceed_available_or_use_stock_from_another_location(): void
    {
        $service = app(InventoryReservationService::class);
        $admin = $this->admin();
        $product = InventoryProduct::create(['sku' => 'SKU-RESERVE-LOCATION', 'name' => 'Producto por ubicación']);
        $first = InventoryLocation::create(['code' => 'R1-1']);
        $second = InventoryLocation::create(['code' => 'R1-2']);
        $this->seedInitial($product, $first, 2, $admin);
        $this->seedInitial($product, $second, 10, $admin);

        $this->expectException(InventoryInsufficientStockException::class);
        $service->create($this->reservationData($product, $first, 3), $admin);
    }

    public function test_second_reservation_respects_the_first_reservation(): void
    {
        $service = app(InventoryReservationService::class);
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 5, $admin);
        $service->create($this->reservationData($product, $location, 4), $admin);

        $this->expectException(InventoryInsufficientStockException::class);
        $service->create($this->reservationData($product, $location, 2), $admin);
    }

    public function test_active_reservation_blocks_an_unrelated_outbound_movement(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 5, $admin);
        app(InventoryReservationService::class)->create(
            $this->reservationData($product, $location, 3),
            $admin,
        );

        $this->expectException(InventoryInsufficientStockException::class);
        app(InventoryMovementService::class)->record([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -3,
        ], $admin);
    }

    public function test_product_without_reservations_has_zero_reserved_and_available_equals_physical(): void
    {
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 7);
        $stock = app(InventoryStockService::class);

        $this->assertSame(0, $stock->reservedStock($product));
        $this->assertSame(7, $stock->availableStock($product));
        $this->assertSame(7, $stock->availableStockByLocation($product, $location));
    }

    public function test_external_key_is_idempotent_and_null_is_allowed(): void
    {
        $service = app(InventoryReservationService::class);
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 10);
        $data = [...$this->reservationData($product, $location, 3), 'external_key' => 'reservation:one'];

        $first = $service->create($data);
        $second = $service->create($data);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('inventory_reservations', 1);

        $service->create($this->reservationData($product, $location, 1));
        $service->create($this->reservationData($product, $location, 1));
        $this->assertDatabaseCount('inventory_reservations', 3);
    }

    public function test_release_and_cancel_keep_history_and_invalid_transitions_are_rejected(): void
    {
        $service = app(InventoryReservationService::class);
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 10);
        $reservation = $service->create($this->reservationData($product, $location, 2));
        $service->release($reservation);
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $reservation->id,
            'status' => InventoryReservation::RELEASED,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $service->cancel($reservation);
    }

    public function test_fulfill_is_atomic_creates_sale_reduces_physical_and_releases_reservation(): void
    {
        $service = app(InventoryReservationService::class);
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 10, $admin);
        $reservation = $service->create($this->reservationData($product, $location, 3), $admin);

        $service->fulfill($reservation, ['reference' => 'SALE-001'], $admin);

        $this->assertSame(InventoryReservation::FULFILLED, $reservation->fresh()->status);
        $this->assertSame(7, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(0, app(InventoryStockService::class)->reservedStock($product));
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -3,
        ]);
    }

    public function test_fulfill_failure_rolls_back_reservation_state(): void
    {
        $service = app(InventoryReservationService::class);
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 5, $admin);
        $reservation = $service->create($this->reservationData($product, $location, 2), $admin);
        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -5,
            'occurred_at' => now(),
        ]);

        $this->expectException(InventoryInsufficientStockException::class);
        try {
            $service->fulfill($reservation, [], $admin);
        } finally {
            $this->assertSame(InventoryReservation::ACTIVE, $reservation->fresh()->status);
            $this->assertDatabaseCount('inventory_movements', 2);
        }
    }

    public function test_expire_only_works_for_an_expired_active_reservation(): void
    {
        $service = app(InventoryReservationService::class);
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 5);
        $reservation = $service->create([
            ...$this->reservationData($product, $location, 1),
            'expires_at' => Carbon::now()->addHour(),
        ]);
        $this->assertNotNull($reservation->expires_at);

        $this->expectException(InvalidArgumentException::class);
        $service->expire($reservation);
    }

    public function test_reservation_list_filters_by_status_sku_barcode_and_location(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation('SKU-RESERVE-FILTER', '7500000000099', 'R9-9');
        $this->seedInitial($product, $location, 5, $admin);
        app(InventoryReservationService::class)->create([
            ...$this->reservationData($product, $location, 1),
            'reference' => 'FILTER-REF',
        ], $admin);

        $this->actingAs($admin)->get(route('inventory.reservations.index', ['status' => InventoryReservation::ACTIVE]))
            ->assertInertia(fn (Assert $page): Assert => $page->where('reservations.data.0.status', InventoryReservation::ACTIVE));
        foreach (['SKU-RESERVE-FILTER', '7500000000099', 'R9-9'] as $search) {
            $this->get(route('inventory.reservations.index', ['search' => $search]))
                ->assertInertia(fn (Assert $page): Assert => $page->where('reservations.data.0.product.sku', 'SKU-RESERVE-FILTER'));
        }
    }

    public function test_product_views_physical_reserved_available_and_active_reservations(): void
    {
        $admin = $this->admin();
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 10, $admin);
        app(InventoryReservationService::class)->create($this->reservationData($product, $location, 3), $admin);

        $this->actingAs($admin)->get(route('inventory.products.show', $product))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('physicalStock', 10)
                ->where('reservedStock', 3)
                ->where('availableStock', 7)
                ->where('stockByLocation.0.physical_stock', 10)
                ->where('stockByLocation.0.reserved_stock', 3)
                ->where('stockByLocation.0.available_stock', 7)
                ->where('reservations.0.quantity', 3));
    }

    public function test_non_admin_cannot_create_or_change_reservations(): void
    {
        [$product, $location] = $this->productAndLocation();
        $this->seedInitial($product, $location, 2);
        $reservation = app(InventoryReservationService::class)->create(
            $this->reservationData($product, $location, 1),
        );
        $user = User::factory()->create(['role' => User::ROLE_OPERATIONS]);

        $this->actingAs($user)
            ->post(route('inventory.reservations.store'), $this->reservationData($product, $location, 1))
            ->assertForbidden();
        $this->post(route('inventory.reservations.release', $reservation))->assertForbidden();
    }

    public function test_reservations_have_no_generic_edit_update_destroy_routes_or_llanta_dependency(): void
    {
        $this->assertFalse(Route::has('inventory.reservations.edit'));
        $this->assertFalse(Route::has('inventory.reservations.update'));
        $this->assertFalse(Route::has('inventory.reservations.destroy'));
        $this->assertFalse(Schema::hasColumn('inventory_reservations', 'stock'));
        $this->assertFalse(Schema::hasColumn('inventory_reservations', 'llanta_id'));
    }

    /** @return array{0: InventoryProduct, 1: InventoryLocation} */
    private function productAndLocation(
        string $sku = 'SKU-RESERVATION',
        string $barcode = '7500000000002',
        string $code = 'A2-1',
    ): array {
        return [
            InventoryProduct::create(['sku' => $sku, 'barcode' => $barcode, 'name' => 'Producto de reservas']),
            InventoryLocation::create(['code' => $code]),
        ];
    }

    private function seedInitial(
        InventoryProduct $product,
        InventoryLocation $location,
        int $quantity,
        ?User $user = null,
    ): void {
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => $quantity,
        ], $user);
    }

    /** @return array<string, mixed> */
    private function reservationData(
        InventoryProduct $product,
        ?InventoryLocation $location,
        int $quantity,
    ): array {
        return [
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location?->id,
            'quantity' => $quantity,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
