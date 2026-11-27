<?php

namespace Tests\Feature;

use App\Models\InventoryDispatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use App\Services\InventoryReservationService;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchRbacAndSecurityTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    public function test_five_roles_access_control_and_backend_stripping_of_costs_and_prices(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'rbac-admin');
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'rbac-ops');
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'rbac-wh');
        $pos = $this->makeUser(User::ROLE_POS, 'rbac-pos');
        $branchRestricted = $this->makeUser(User::ROLE_BRANCH_RESTRICTED, 'rbac-br');

        $dest = $this->makeDestination('SALON-SEC', 'Salón Seguridad');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct([
            'sku' => 'SEC-SKU-1',
            'barcode' => '7500001112223',
            'cost' => 88.50,
            'price_stylist' => 149.00,
            'primary_location_id' => $loc->id,
        ]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDay(),
        ], $admin);

        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ], $warehouse);
        $confirmed = $svc->confirm($draft, $warehouse, 'confirm-sec-1', $draft->draft_version);

        // pos & branch_restricted strictly blocked (403)
        $this->actingAs($pos)->get('/almacen/salidas')->assertForbidden();
        $this->actingAs($branchRestricted)->get('/almacen/salidas')->assertForbidden();
        $this->actingAs($pos)->get("/almacen/salidas/{$confirmed->id}/vale-pdf")->assertForbidden();
        $this->actingAs($branchRestricted)->get("/almacen/salidas/{$confirmed->id}/vale-pdf")->assertForbidden();

        // warehouse cannot create/edit destinations or link/merge provisional items
        $this->actingAs($warehouse)->post('/almacen/salidas/destinos', [
            'code' => 'NOPE',
            'name' => 'No permitido',
            'destination_type' => 'SALON',
        ])->assertForbidden();

        // H06: Warehouse MUST NOT receive cost or stylist price in Inertia Show, Search JSON, or PDF!
        $this->actingAs($warehouse)
            ->get("/almacen/salidas/{$confirmed->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Dispatches/Show')
                ->missing('dispatch.reference_subtotal_cost')
                ->missing('dispatch.reference_subtotal_stylist')
                ->missing('dispatch.items.0.unit_cost_snapshot')
                ->missing('dispatch.items.0.unit_price_stylist_snapshot')
            );

        $searchWh = $this->actingAs($warehouse)
            ->getJson('/almacen/salidas/buscar-productos?mode=scan&q=7500001112223')
            ->assertOk()
            ->json();
        $this->assertArrayNotHasKey('cost', $searchWh['item']);
        $this->assertArrayNotHasKey('price_stylist', $searchWh['item']);

        // Warehouse attempting ?include_prices=1 on PDF -> 403 Forbidden!
        $this->actingAs($warehouse)
            ->get("/almacen/salidas/{$confirmed->id}/vale-pdf?include_prices=1")
            ->assertForbidden();

        // Operations sees stylist price but NOT acquisition cost
        $this->actingAs($ops)
            ->get("/almacen/salidas/{$confirmed->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('dispatch.reference_subtotal_cost')
                ->missing('dispatch.items.0.unit_cost_snapshot')
                ->where('dispatch.reference_subtotal_stylist', 298)
            );

        // Admin sees both cost and stylist price in Show (never cost in PDF)
        $this->actingAs($admin)
            ->get("/almacen/salidas/{$confirmed->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dispatch.reference_subtotal_cost', 177)
                ->where('dispatch.reference_subtotal_stylist', 298)
            );

        $pdfAdmin = $this->actingAs($admin)
            ->get("/almacen/salidas/{$confirmed->id}/vale-pdf?include_prices=1")
            ->assertOk();
        $pdfAdmin->assertSee('298.00');
        $pdfAdmin->assertDontSee('177.00');
        $pdfAdmin->assertDontSee('88.50');
    }

    public function test_h10_blocks_forged_dispatch_movement_types_across_all_four_layers(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'h10-admin');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDay(),
        ], $admin);

        // Layer 1: Crafted HTTP POST to /almacen/movimientos with DISPATCH_OUT / DISPATCH_RETURN -> 422 validation error
        $this->actingAs($admin)->post('/almacen/movimientos', [
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::DISPATCH_OUT,
            'quantity' => 1,
        ])->assertSessionHasErrors('type');

        $this->actingAs($admin)->post('/almacen/movimientos', [
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::DISPATCH_RETURN,
            'quantity' => 1,
        ])->assertSessionHasErrors('type');

        // Layer 2: Direct InventoryMovementService::recordManual() rejects reserved types
        $movementSvc = app(InventoryMovementService::class);
        foreach ([InventoryMovement::DISPATCH_OUT, InventoryMovement::DISPATCH_RETURN] as $reservedType) {
            try {
                $movementSvc->recordManual([
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'type' => $reservedType,
                    'quantity' => 1,
                ], $admin);
                $this->fail("Expected recordManual() to reject {$reservedType}.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        // Layer 3: Direct generic InventoryMovementService::record() rejects reserved types even with forged prefix!
        try {
            $movementSvc->record([
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'type' => InventoryMovement::DISPATCH_OUT,
                'quantity' => -1,
                'reference_type' => 'inventory_dispatch',
                'reference_id' => 999,
                'external_key' => 'warehouse-dispatch:999:item:999:out',
            ], $admin);
            $this->fail('Expected generic record() to reject forged DISPATCH_OUT.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // Layer 4: InventoryReservationService::fulfill() rejects DISPATCH_OUT
        $resSvc = app(InventoryReservationService::class);
        $reservation = $resSvc->create([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'quantity' => 1,
            'reference' => 'TEST-RES-H10',
        ], $admin);

        $this->expectException(InvalidArgumentException::class);
        $resSvc->fulfill($reservation, ['type' => InventoryMovement::DISPATCH_OUT], $admin);
    }

    public function test_mass_assignment_and_manipulated_cost_or_status_payloads_are_ignored(): void
    {
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'mass-wh');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['cost' => 100.00, 'price_stylist' => 180.00, 'primary_location_id' => $loc->id]);

        $response = $this->actingAs($warehouse)->postJson('/almacen/salidas', [
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'status' => InventoryDispatch::STATUS_CONFIRMED,
            'reference_subtotal_cost' => 0.01,
            'reference_subtotal_stylist' => 0.01,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
                'unit_cost_snapshot' => 0.01,
                'unit_price_stylist_snapshot' => 0.01,
                'inventory_apply_status' => 'APPLIED',
            ]],
        ])->assertCreated();

        $created = InventoryDispatch::with('items')->findOrFail($response->json('dispatch.id'));
        $this->assertSame(InventoryDispatch::STATUS_DRAFT, $created->status);
        $this->assertNull($created->reference_subtotal_cost);
        $this->assertNull($created->items->first()->unit_cost_snapshot);
        $this->assertSame('PENDING', $created->items->first()->inventory_apply_status);
    }
}
