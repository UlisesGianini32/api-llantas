<?php

namespace Tests\Feature;

use App\Exceptions\WarehouseDispatchesDisabledException;
use App\Exceptions\WarehouseDispatchReadOnlyException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchFeatureFlagsAndRoutingTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
    }

    public function test_module_defaults_are_strictly_fail_closed(): void
    {
        $this->assertFalse((bool) config('inventory.warehouse_dispatches.enabled'));
        $this->assertFalse((bool) config('inventory.warehouse_dispatches.read_only'));
        $this->assertFalse((bool) config('inventory.warehouse_dispatches.apply_ledger'));
        $this->assertFalse((bool) config('inventory.warehouse_dispatches.meli_stock_sync_enabled'));
        $this->assertFalse((bool) config('inventory.warehouse_dispatches.allow_kits'));
        $this->assertSame(24, (int) config('inventory.warehouse_dispatches.retroactive_tolerance_hours'));
    }

    public function test_disabled_flag_blocks_all_endpoints_and_write_services(): void
    {
        config()->set('inventory.warehouse_dispatches.enabled', false);

        $admin = $this->makeUser(User::ROLE_ADMIN, 'ff-admin');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        $this->actingAs($admin)->get('/almacen/salidas')->assertNotFound();
        $this->actingAs($admin)->get('/almacen/salidas/crear')->assertNotFound();
        $this->actingAs($admin)->get('/almacen/salidas/destinos')->assertNotFound();
        $this->actingAs($admin)->get('/almacen/salidas/provisionales')->assertNotFound();
        $this->actingAs($admin)->get('/almacen/salidas/1')->assertNotFound();
        $this->actingAs($admin)->post('/almacen/salidas', [])->assertNotFound();

        $this->expectException(WarehouseDispatchesDisabledException::class);
        app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ], $admin);
    }

    public function test_read_only_mode_allows_get_and_blocks_all_writes(): void
    {
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.read_only', true);

        $admin = $this->makeUser(User::ROLE_ADMIN, 'ro-admin');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        $this->actingAs($admin)->get('/almacen/salidas')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/destinos')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/provisionales')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/buscar-productos?q=test')->assertOk();

        $this->actingAs($admin)->post('/almacen/salidas', [])->assertStatus(503);
        $this->actingAs($admin)->post('/almacen/salidas/destinos', [])->assertStatus(503);

        $this->expectException(WarehouseDispatchReadOnlyException::class);
        app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 1,
            ]],
        ], $admin);
    }

    public function test_record_only_mode_creates_zero_movements_and_later_enabling_apply_ledger_does_not_retroactively_deduct(): void
    {
        Queue::fake();
        Http::fake();

        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.read_only', false);
        config()->set('inventory.warehouse_dispatches.apply_ledger', false);

        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'rec-only');
        $dest = $this->makeDestination('SALON-CENTRO', 'Salón Centro');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['sku' => 'IGORA-0-22', 'primary_location_id' => $loc->id]);

        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 5,
                ],
                [
                    'kind' => 'provisional',
                    'captured_name' => 'Peróxido 20 Vol sin alta',
                    'captured_brand' => 'Schwarzkopf',
                    'captured_quantity' => 2,
                ],
            ],
        ], $warehouse);

        $confirmed = $svc->confirm($draft, $warehouse, 'confirm-key-record-only-1', $draft->draft_version);

        $this->assertSame(InventoryDispatch::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame(0, InventoryMovement::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $regItem = $confirmed->items->firstWhere('inventory_product_id', $prod->id);
        $provItem = $confirmed->items->firstWhere('inventory_product_id', null);

        $this->assertSame(InventoryDispatchItem::APPLY_SKIPPED_LEDGER_DISABLED, $regItem->inventory_apply_status);
        $this->assertStringContainsString('apply_ledger=false', (string) $regItem->inventory_resolution_notes);
        $this->assertSame(InventoryDispatchItem::APPLY_SKIPPED_PROVISIONAL, $provItem->inventory_apply_status);

        // Enabling apply_ledger later must NOT retroactively create movements for already-confirmed dispatches
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
        $this->actingAs($warehouse)->get("/almacen/salidas/{$confirmed->id}")->assertOk();
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(InventoryDispatchItem::APPLY_SKIPPED_LEDGER_DISABLED, $regItem->fresh()->inventory_apply_status);
    }

    public function test_static_routes_precede_parameterized_numeric_dispatch_routes(): void
    {
        config()->set('inventory.warehouse_dispatches.enabled', true);

        $admin = $this->makeUser(User::ROLE_ADMIN, 'route-admin');

        $this->actingAs($admin)->get('/almacen/salidas/destinos')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/provisionales')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/crear')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/buscar-productos?q=abc')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/resumen')->assertOk();
        $this->actingAs($admin)->get('/almacen/salidas/no-es-numero')->assertNotFound();
    }
}
