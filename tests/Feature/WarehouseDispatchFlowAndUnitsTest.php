<?php

namespace Tests\Feature;

use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchDestination;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchDestinationService;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchFlowAndUnitsTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    public function test_same_product_withdrawn_from_two_different_locations_stays_separate_and_deducts_each_location(): void
    {
        $user = $this->makeUser(User::ROLE_WAREHOUSE, 'multi-loc');
        $dest = $this->makeDestination('SALON-01', 'Salón Uno');
        $loc1 = $this->makeLocation('D3-7');
        $loc2 = $this->makeLocation('D3-8');
        $product = $this->makeProduct(['sku' => 'IGORA-0-22', 'name' => 'Igora Royal 0-22', 'primary_location_id' => $loc1->id]);

        $movementSvc = app(InventoryMovementService::class);
        $movementSvc->recordManual([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $loc1->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDays(2),
        ], $user);
        $movementSvc->recordManual([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $loc2->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDays(2),
        ], $user);

        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $product->id,
                    'inventory_location_id' => $loc1->id,
                    'captured_quantity' => 4,
                    'captured_unit_of_measure' => 'TUBE',
                ],
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $product->id,
                    'inventory_location_id' => $loc2->id,
                    'captured_quantity' => 3,
                    'captured_unit_of_measure' => 'TUBE',
                ],
            ],
        ], $user);

        // Draft must not deduct inventory
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());

        $confirmed = $svc->confirm($draft, $user, 'confirm-two-locations-1', $draft->draft_version);

        $this->assertSame(2, $confirmed->items->count());
        $this->assertSame(7, $confirmed->total_physical_units);

        $stockLoc1 = (int) InventoryMovement::where('inventory_product_id', $product->id)
            ->where('inventory_location_id', $loc1->id)->sum('quantity');
        $stockLoc2 = (int) InventoryMovement::where('inventory_product_id', $product->id)
            ->where('inventory_location_id', $loc2->id)->sum('quantity');

        $this->assertSame(6, $stockLoc1);
        $this->assertSame(7, $stockLoc2);
    }

    public function test_kits_are_blocked_in_scan_and_in_dispatch_creation(): void
    {
        $user = $this->makeUser(User::ROLE_OPERATIONS, 'kit-check');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $kit = $this->makeProduct([
            'sku' => 'KIT-IGORA-0-22',
            'barcode' => '7501112223334',
            'name' => 'Kit Igora Royal 0-22 con Peróxido',
            'product_type' => InventoryProduct::KIT,
        ]);

        $this->actingAs($user)
            ->getJson('/almacen/salidas/buscar-productos?mode=scan&q=7501112223334')
            ->assertOk()
            ->assertJsonPath('match_status', 'BLOCKED_KIT');

        $this->expectException(InvalidArgumentException::class);
        app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $kit->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 1,
            ]],
        ], $user);
    }

    public function test_ambiguous_barcode_secondary_scan_never_auto_picks(): void
    {
        $user = $this->makeUser(User::ROLE_WAREHOUSE, 'ambig-scan');
        $this->makeProduct(['sku' => 'SKU-A', 'barcode_secondary' => '7709998887771', 'name' => 'Tinte A']);
        $this->makeProduct(['sku' => 'SKU-B', 'barcode_secondary' => '7709998887771', 'name' => 'Tinte B']);

        $this->actingAs($user)
            ->getJson('/almacen/salidas/buscar-productos?mode=scan&q=7709998887771')
            ->assertOk()
            ->assertJsonPath('match_status', 'AMBIGUOUS')
            ->assertJsonPath('item', null)
            ->assertJsonCount(2, 'candidates');
    }

    public function test_container_units_without_conversion_are_never_summed_with_physical_pieces(): void
    {
        $user = $this->makeUser(User::ROLE_WAREHOUSE, 'uom-check');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        // Registered product with BOX must be rejected in Phase 1
        try {
            app(WarehouseDispatchService::class)->createDraft([
                'destination_id' => $dest->id,
                'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
                'items' => [[
                    'kind' => 'registered',
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 2,
                    'captured_unit_of_measure' => InventoryProvisionalItem::UOM_BOX,
                ]],
            ], $user);
            $this->fail('Expected InvalidArgumentException for registered product with BOX unit.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // Provisional: 3 individual tubes + 2 unconverted boxes + 1 converted box of 12 = 15 physical units + 1 unconverted container line
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [
                [
                    'kind' => 'provisional',
                    'captured_name' => 'Tinte suelto',
                    'captured_quantity' => 3,
                    'captured_unit_of_measure' => 'TUBE',
                ],
                [
                    'kind' => 'provisional',
                    'captured_name' => 'Caja sin equivalencia',
                    'captured_quantity' => 2,
                    'captured_unit_of_measure' => 'BOX',
                    'unit_conversion_factor' => null,
                ],
                [
                    'kind' => 'provisional',
                    'captured_name' => 'Caja de 12 tubos',
                    'captured_quantity' => 1,
                    'captured_unit_of_measure' => 'BOX',
                    'unit_conversion_factor' => 12,
                ],
            ],
        ], $user);

        $confirmed = app(WarehouseDispatchService::class)->confirm($draft, $user, 'confirm-uom-test-1', $draft->draft_version);

        $this->assertSame(15, $confirmed->total_physical_units);
        $this->assertSame(1, $confirmed->unconverted_containers_count);
    }

    public function test_salon_sale_records_reference_prices_without_touching_pos_and_preserves_pdf_snapshots_after_edits(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'ops-sale');
        $dest = $this->makeDestination('SALON-VIP', 'Salón VIP Original');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct([
            'sku' => 'SHAMPOO-1L',
            'name' => 'Shampoo Original 1L',
            'cost' => 120.00,
            'price_stylist' => 210.00,
            'primary_location_id' => $loc->id,
        ]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDay(),
        ], $ops);

        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'received_by_name' => 'Laura Estilista',
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ], $ops);

        $confirmed = $svc->confirm($draft, $ops, 'confirm-salon-sale-1', $draft->draft_version);

        // Zero POS sales created
        $this->assertSame(0, DB::table('pos_sales')->count());
        $this->assertSame(InventoryDispatch::COMMERCIAL_REFERENCE_ONLY, $confirmed->commercial_status);
        $this->assertEquals(240.00, (float) $confirmed->reference_subtotal_cost);
        $this->assertEquals(420.00, (float) $confirmed->reference_subtotal_stylist);

        // Rename destination & product later -> PDF must preserve original confirmed snapshots
        app(WarehouseDispatchDestinationService::class)->update($dest, [
            'code' => 'SALON-VIP-NEW',
            'name' => 'Salón Renombrado',
            'destination_type' => InventoryDispatchDestination::TYPE_SALON,
        ]);
        $prod->update(['name' => 'Shampoo Renombrado 1L']);

        $response = $this->actingAs($ops)
            ->get("/almacen/salidas/{$confirmed->id}/vale-pdf?include_prices=1")
            ->assertOk();

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->assertSee('Salón VIP Original');
        $response->assertSee('Shampoo Original 1L');
        $response->assertSee('420.00');
        $response->assertDontSee('Salón Renombrado');
        $response->assertDontSee('Shampoo Renombrado 1L');
        // Cost must NEVER appear on PDF
        $response->assertDontSee('240.00');
    }

    public function test_inactive_destination_cannot_be_used_in_new_dispatch(): void
    {
        $user = $this->makeUser(User::ROLE_OPERATIONS, 'inact-dest');
        $dest = $this->makeDestination('SALON-OFF', 'Salón Cerrado', false);
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        $this->expectException(InvalidArgumentException::class);
        app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 1,
            ]],
        ], $user);
    }
}
