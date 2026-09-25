<?php

namespace Tests\Feature;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\InventoryKitService;
use App\Services\InventoryKitStockService;
use App\Services\InventoryMovementService;
use App\Services\InventoryReservationService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class InventoryKitsTest extends TestCase
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
            $table->text('two_factor_confirmed_at')->nullable();
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
            '2026_09_24_000006_add_product_type_to_inventory_products_table.php',
            '2026_09_24_000007_create_inventory_kit_components_table.php',
            '2026_09_24_000008_create_inventory_kit_reservations_table.php',
        ] as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_kit_reservations');
        Schema::dropIfExists('inventory_kit_components');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_movements');
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropForeign(['primary_location_id']);
            $table->dropIndex(['product_type']);
        });
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_existing_products_are_simple_and_kits_can_be_defined(): void
    {
        $simple = InventoryProduct::create(['sku' => 'SIMPLE-1', 'name' => 'Simple']);
        $kit = InventoryProduct::create(['sku' => 'KIT-1', 'name' => 'Kit', 'product_type' => InventoryProduct::KIT]);
        $this->assertTrue($simple->isSimple());
        $this->assertTrue($kit->isKit());

        app(InventoryKitService::class)->replaceComponents($kit, [
            ['component_product_id' => $simple->id, 'quantity' => 2],
        ]);
        $this->assertDatabaseHas('inventory_kit_components', ['kit_product_id' => $kit->id, 'component_product_id' => $simple->id, 'quantity' => 2]);
    }

    public function test_component_validation_rejects_duplicates_self_and_nested_kits(): void
    {
        $kit = $this->product('KIT-A', InventoryProduct::KIT);
        $simple = $this->product('SIMPLE-A');
        $nested = $this->product('KIT-B', InventoryProduct::KIT);
        $service = app(InventoryKitService::class);

        foreach ([
            [['component_product_id' => $kit->id, 'quantity' => 1]],
            [['component_product_id' => $simple->id, 'quantity' => 1], ['component_product_id' => $simple->id, 'quantity' => 2]],
            [['component_product_id' => $nested->id, 'quantity' => 1]],
            [['component_product_id' => $simple->id, 'quantity' => 0]],
        ] as $components) {
            try {
                $service->replaceComponents($kit, $components);
                $this->fail('Expected component validation to fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_kit_without_components_has_zero_stock(): void
    {
        $kit = $this->product('KIT-EMPTY', InventoryProduct::KIT);
        $stock = app(InventoryKitStockService::class);
        $this->assertSame(0, $stock->physicalStock($kit));
        $this->assertSame(0, $stock->availableStock($kit));
    }

    public function test_kit_physical_and_available_stock_use_limiting_component_and_quantity(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 12);
        $this->seedInventoryFixture($conditioner, $second, 7);
        app(InventoryKitService::class)->replaceComponents($kit, [
            ['component_product_id' => $shampoo->id, 'quantity' => 2],
            ['component_product_id' => $conditioner->id, 'quantity' => 1],
        ]);
        $stock = app(InventoryKitStockService::class);
        $this->assertSame(6, $stock->physicalStock($kit));
        $this->assertSame(6, $stock->availableStock($kit));
        app(InventoryReservationService::class)->create(['inventory_product_id' => $shampoo->id, 'inventory_location_id' => $first->id, 'quantity' => 4]);
        $this->assertSame(4, $stock->availableStock($kit));
    }

    public function test_reserving_kit_creates_component_reservations_and_uses_primary_then_other_locations(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $shampoo->update(['primary_location_id' => $first->id]);
        $this->seedInventoryFixture($shampoo, $first, 2);
        $this->seedInventoryFixture($shampoo, $second, 4);
        $this->seedInventoryFixture($conditioner, $second, 3);
        app(InventoryKitService::class)->replaceComponents($kit, [
            ['component_product_id' => $shampoo->id, 'quantity' => 2],
            ['component_product_id' => $conditioner->id, 'quantity' => 1],
        ]);

        $reservation = app(InventoryKitService::class)->reserve(['kit_product_id' => $kit->id, 'quantity' => 3, 'reference' => 'KIT-SALE']);
        $this->assertSame(InventoryKitReservation::ACTIVE, $reservation->status);
        $children = InventoryReservation::query()->where('source_id', $reservation->id)->orderBy('inventory_location_id')->get();
        $shampooChildren = $children->where('inventory_product_id', $shampoo->id);
        $this->assertSame(6, $shampooChildren->sum('quantity'));
        $this->assertSame(2, $shampooChildren->where('inventory_location_id', $first->id)->sum('quantity'));
        $this->assertSame(4, $shampooChildren->where('inventory_location_id', $second->id)->sum('quantity'));
        $this->assertSame(3, $children->where('inventory_product_id', $conditioner->id)->sum('quantity'));
    }

    public function test_kit_reservation_rolls_back_when_any_component_is_insufficient_and_second_respects_first(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 2);
        $this->seedInventoryFixture($conditioner, $second, 1);
        app(InventoryKitService::class)->replaceComponents($kit, [
            ['component_product_id' => $shampoo->id, 'quantity' => 1],
            ['component_product_id' => $conditioner->id, 'quantity' => 1],
        ]);
        $service = app(InventoryKitService::class);
        $this->expectException(InventoryInsufficientStockException::class);
        try {
            $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 2]);
        } finally {
            $this->assertDatabaseCount('inventory_kit_reservations', 0);
            $this->assertDatabaseCount('inventory_reservations', 0);
        }
    }

    public function test_kit_external_key_is_idempotent_and_null_is_allowed(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 10);
        $this->seedInventoryFixture($conditioner, $second, 10);
        app(InventoryKitService::class)->replaceComponents($kit, [
            ['component_product_id' => $shampoo->id, 'quantity' => 1], ['component_product_id' => $conditioner->id, 'quantity' => 1],
        ]);
        $service = app(InventoryKitService::class);
        $firstReservation = $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 2, 'external_key' => 'kit:one']);
        $same = $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 2, 'external_key' => 'kit:one']);
        $this->assertSame($firstReservation->id, $same->id);
        $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 1]);
        $this->assertDatabaseCount('inventory_kit_reservations', 2);
    }

    public function test_release_cancel_and_expire_release_all_children(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 10);
        $this->seedInventoryFixture($conditioner, $second, 10);
        app(InventoryKitService::class)->replaceComponents($kit, [['component_product_id' => $shampoo->id, 'quantity' => 1], ['component_product_id' => $conditioner->id, 'quantity' => 1]]);
        $service = app(InventoryKitService::class);
        $released = $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 1]);
        $service->release($released);
        $cancelled = $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 1]);
        $service->cancel($cancelled);
        $expired = $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 1, 'expires_at' => Carbon::now()->subMinute()]);
        $service->expire($expired);
        $this->assertSame(InventoryKitReservation::RELEASED, $released->fresh()->status);
        $this->assertSame(InventoryKitReservation::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(InventoryKitReservation::EXPIRED, $expired->fresh()->status);
        $this->assertSame(0, InventoryReservation::active()->count());
    }

    public function test_fulfill_creates_component_movements_only_and_keeps_traceability(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 10);
        $this->seedInventoryFixture($conditioner, $second, 10);
        app(InventoryKitService::class)->replaceComponents($kit, [['component_product_id' => $shampoo->id, 'quantity' => 2], ['component_product_id' => $conditioner->id, 'quantity' => 1]]);
        $reservation = app(InventoryKitService::class)->reserve(['kit_product_id' => $kit->id, 'quantity' => 2]);
        app(InventoryKitService::class)->fulfill($reservation, ['reference' => 'SALE-KIT']);
        $this->assertSame(InventoryKitReservation::FULFILLED, $reservation->fresh()->status);
        $this->assertSame(-4, InventoryMovement::query()
            ->where('inventory_product_id', $shampoo->id)
            ->where('reference_type', InventoryKitReservation::SOURCE_TYPE)
            ->where('reference_id', $reservation->id)
            ->sum('quantity'));
        $this->assertSame(-2, InventoryMovement::query()
            ->where('inventory_product_id', $conditioner->id)
            ->where('reference_type', InventoryKitReservation::SOURCE_TYPE)
            ->where('reference_id', $reservation->id)
            ->sum('quantity'));
        $this->assertSame(6, app(InventoryStockService::class)->physicalStock($shampoo));
        $this->assertSame(8, app(InventoryStockService::class)->physicalStock($conditioner));
        $this->assertDatabaseMissing('inventory_movements', ['inventory_product_id' => $kit->id]);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'inventory_kit_reservation', 'reference_id' => $reservation->id]);
    }

    public function test_kit_fulfill_rolls_back_all_children_when_a_later_child_fails(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 10);
        $this->seedInventoryFixture($conditioner, $second, 10);
        app(InventoryKitService::class)->replaceComponents($kit, [
            ['component_product_id' => $shampoo->id, 'quantity' => 1],
            ['component_product_id' => $conditioner->id, 'quantity' => 1],
        ]);

        $parent = app(InventoryKitService::class)->reserve([
            'kit_product_id' => $kit->id,
            'quantity' => 1,
            'reference' => 'SALE-ROLLBACK',
        ]);
        $children = InventoryReservation::query()
            ->where('source_type', InventoryKitReservation::SOURCE_TYPE)
            ->where('source_id', $parent->id)
            ->orderBy('inventory_product_id')
            ->orderBy('inventory_location_id')
            ->get();
        $this->assertGreaterThanOrEqual(2, $children->count());

        $firstChild = $children->first();
        $laterChild = $children->skip(1)->first();
        $this->assertSame(InventoryReservation::ACTIVE, $firstChild->status);
        $stockBefore = app(InventoryStockService::class)->physicalStock($firstChild->product);
        $this->assertGreaterThanOrEqual($firstChild->quantity, $stockBefore);
        $movementsBefore = InventoryMovement::query()
            ->where('reference_type', InventoryKitReservation::SOURCE_TYPE)
            ->where('reference_id', $parent->id)
            ->count();

        $laterChild->forceFill([
            'status' => InventoryReservation::RELEASED,
            'released_at' => now(),
        ])->save();

        try {
            app(InventoryKitService::class)->fulfill($parent);
            $this->fail('Se esperaba que el fulfillment fallara.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('finalizada', $exception->getMessage());
        }

        $this->assertSame(InventoryKitReservation::ACTIVE, $parent->fresh()->status);
        $this->assertSame(InventoryReservation::ACTIVE, $firstChild->fresh()->status);
        $this->assertSame($stockBefore, app(InventoryStockService::class)->physicalStock($firstChild->product));
        $this->assertSame($movementsBefore, InventoryMovement::query()
            ->where('reference_type', InventoryKitReservation::SOURCE_TYPE)
            ->where('reference_id', $parent->id)
            ->count());
        $this->assertSame(InventoryReservation::RELEASED, $laterChild->fresh()->status);
        $this->assertDatabaseMissing('inventory_movements', [
            'inventory_product_id' => $kit->id,
        ]);
    }

    public function test_direct_movement_and_normal_reservation_on_kit_are_rejected(): void
    {
        [$kit, , , $first] = $this->kitFixture();
        try {
            app(InventoryMovementService::class)->record(['inventory_product_id' => $kit->id, 'inventory_location_id' => $first->id, 'type' => InventoryMovement::INITIAL, 'quantity' => 1]);
            $this->fail('A kit movement should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('kits', $exception->getMessage());
        }
        try {
            app(InventoryReservationService::class)->create(['inventory_product_id' => $kit->id, 'inventory_location_id' => $first->id, 'quantity' => 1]);
            $this->fail('A direct kit reservation should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('kits', $exception->getMessage());
        }
    }

    public function test_active_kit_reservation_blocks_composition_change_and_finished_one_allows_it(): void
    {
        [$kit, $shampoo, $conditioner, $first, $second] = $this->kitFixture();
        $this->seedInventoryFixture($shampoo, $first, 2);
        $this->seedInventoryFixture($conditioner, $second, 2);
        $service = app(InventoryKitService::class);
        $service->replaceComponents($kit, [['component_product_id' => $shampoo->id, 'quantity' => 1]]);
        $reservation = $service->reserve(['kit_product_id' => $kit->id, 'quantity' => 1]);
        $this->expectException(InvalidArgumentException::class);
        try {
            $service->replaceComponents($kit, [['component_product_id' => $conditioner->id, 'quantity' => 1]]);
        } finally {
            $service->release($reservation);
            $service->replaceComponents($kit, [['component_product_id' => $conditioner->id, 'quantity' => 1]]);
            $this->assertDatabaseHas('inventory_kit_components', ['kit_product_id' => $kit->id, 'component_product_id' => $conditioner->id]);
        }
    }

    public function test_kit_routes_show_calculated_stock_and_non_admin_cannot_manage_it(): void
    {
        [$kit] = $this->kitFixture();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_OPERATIONS]))
            ->get(route('inventory.kits.show', $kit))->assertOk();
        $this->post(route('inventory.kits.reservations.store', $kit), ['quantity' => 1])->assertForbidden();
        $this->assertTrue(Route::has('inventory.kits.index'));
    }

    public function test_product_type_conversion_rules_protect_existing_inventory_and_composition(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $simple = $this->product('SIMPLE-CONVERT');
        $location = InventoryLocation::create(['code' => 'CONVERT-1']);
        $this->seedInventoryFixture($simple, $location, 1);
        $payload = ['sku' => $simple->sku, 'name' => $simple->name, 'product_type' => InventoryProduct::KIT];
        $this->actingAs($admin)->put(route('inventory.products.update', $simple), $payload)->assertSessionHasErrors('product_type');

        $kit = $this->product('KIT-CONVERT', InventoryProduct::KIT);
        app(InventoryKitService::class)->replaceComponents($kit, [['component_product_id' => $simple->id, 'quantity' => 1]]);
        $payload = ['sku' => $kit->sku, 'name' => $kit->name, 'product_type' => InventoryProduct::SIMPLE];
        $this->actingAs($admin)->put(route('inventory.products.update', $kit), $payload)->assertSessionHasErrors('product_type');
    }

    /** @return array{0:InventoryProduct,1:InventoryProduct,2:InventoryProduct,3:InventoryLocation,4:InventoryLocation} */
    private function kitFixture(): array
    {
        return [
            $this->product('KIT-DUO', InventoryProduct::KIT),
            $this->product('SHAMPOO'),
            $this->product('CONDITIONER'),
            InventoryLocation::create(['code' => 'A1-1', 'sort_order' => 1]),
            InventoryLocation::create(['code' => 'A1-2', 'sort_order' => 2]),
        ];
    }

    private function product(string $sku, string $type = InventoryProduct::SIMPLE): InventoryProduct
    {
        return InventoryProduct::create(['sku' => $sku, 'name' => $sku, 'product_type' => $type]);
    }

    private function seedInventoryFixture(InventoryProduct $product, InventoryLocation $location, int $quantity): void
    {
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => $quantity,
        ]);
    }
}
