<?php

namespace Tests\Feature;

use App\Exceptions\WarehouseDispatchConflictException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\InventoryShelfAudit;
use App\Models\InventoryShelfAuditItem;
use App\Models\User;
use App\Services\Inventory\Dispatches\ProvisionalItemLinkService;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchProvisionalIntegrityTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_day_1_3_5_6_provisional_linking_preserves_history_and_never_deducts_stock_or_touches_ml(): void
    {
        Queue::fake();
        Http::fake();

        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'prov-wh');
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'prov-ops');
        $dest = $this->makeDestination('SALON-PROV', 'Salón Provisional');
        $loc = $this->makeLocation('D3-7');

        $dispatchSvc = app(WarehouseDispatchService::class);
        $linkSvc = app(ProvisionalItemLinkService::class);

        // Day 1: deliver 6 provisional tints
        Carbon::setTestNow(Carbon::parse('2026-11-21 17:00:00', 'UTC'));
        $d1 = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'source_location_id' => $loc->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'provisional',
                'captured_name' => 'Tinte Igora Royal 0-22',
                'captured_brand' => 'Schwarzkopf',
                'captured_tone' => '0-22',
                'captured_presentation' => '60 ml',
                'captured_quantity' => 6,
            ]],
        ], $warehouse);
        $c1 = $dispatchSvc->confirm($d1, $warehouse, 'confirm-day-1', $d1->draft_version);
        $provItemId = $c1->items->first()->provisional_item_id;

        // Confirm NO master product was auto-created
        $this->assertSame(0, InventoryProduct::query()->count());

        // Day 3: deliver 4 more reusing the same provisional identity
        Carbon::setTestNow(Carbon::parse('2026-11-23 17:00:00', 'UTC'));
        $d3 = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'source_location_id' => $loc->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'provisional',
                'provisional_item_id' => $provItemId,
                'captured_quantity' => 4,
            ]],
        ], $warehouse);
        $c3 = $dispatchSvc->confirm($d3, $warehouse, 'confirm-day-3', $d3->draft_version);

        // Day 5: master product is created and physical count records 20 units
        Carbon::setTestNow(Carbon::parse('2026-11-25 18:00:00', 'UTC'));
        $master = $this->makeProduct([
            'sku' => 'IGORA-ROYAL-0-22',
            'name' => 'Schwarzkopf Igora Royal 0-22 60ml',
            'brand' => 'Schwarzkopf',
            'primary_location_id' => $loc->id,
        ]);
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $master->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 20,
            'occurred_at' => now('UTC'),
        ], $ops);

        $audit = InventoryShelfAudit::create([
            'audit_number' => 'AUD-DAY5-0001',
            'inventory_location_id' => $loc->id,
            'shelf_code' => 'D3',
            'audit_type' => 'shelf',
            'user_id' => $ops->id,
            'status' => InventoryShelfAudit::STATUS_APPLIED,
            'applied_at' => now('UTC'),
            'applied_by' => $ops->id,
        ]);
        InventoryShelfAuditItem::create([
            'inventory_shelf_audit_id' => $audit->id,
            'inventory_product_id' => $master->id,
            'system_quantity' => 20,
            'counted_quantity' => 20,
            'difference' => 0,
            'adjusted' => true,
        ]);

        // Day 6: link provisional item to master product
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'UTC'));
        $prov = InventoryProvisionalItem::findOrFail($provItemId);
        $linked = $linkSvc->linkToMasterProduct($prov, $master, $ops, 'Vinculado tras alta de catálogo');

        $this->assertSame(InventoryProvisionalItem::STATUS_LINKED, $linked->status);
        $this->assertSame($master->id, $linked->linked_inventory_product_id);

        // Stock MUST stay 20 (ZERO historical deduction) and ZERO ML jobs/HTTP!
        $this->assertSame(20, app(InventoryStockService::class)->physicalStockByLocation($master, $loc));
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        // Both historical items preserve original provisional_item_id & captured_name, linked post-dispatch, skipped pre-count
        $item1 = $c1->items->first()->fresh();
        $item3 = $c3->items->first()->fresh();

        $this->assertSame($provItemId, $item1->provisional_item_id);
        $this->assertSame($master->id, $item1->inventory_product_id);
        $this->assertSame('Tinte Igora Royal 0-22', $item1->captured_name);
        $this->assertSame(InventoryDispatchItem::ID_LINKED_POST_DISPATCH, $item1->identification_status);
        $this->assertSame(InventoryDispatchItem::APPLY_SKIPPED_PRE_PHYSICAL_COUNT, $item1->inventory_apply_status);

        $this->assertSame($provItemId, $item3->provisional_item_id);
        $this->assertSame($master->id, $item3->inventory_product_id);
        $this->assertSame(InventoryDispatchItem::APPLY_SKIPPED_PRE_PHYSICAL_COUNT, $item3->inventory_apply_status);

        // Total historical demand preserved = 6 + 4 = 10 units
        $historicalDemand = (int) InventoryDispatchItem::where('inventory_product_id', $master->id)->sum('normalized_physical_quantity');
        $this->assertSame(10, $historicalDemand);
    }

    public function test_merge_preserves_original_provisional_item_id_on_confirmed_items_and_rejects_invalid_merges(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'merge-ops');
        $dest = $this->makeDestination();
        $dispatchSvc = app(WarehouseDispatchService::class);
        $linkSvc = app(ProvisionalItemLinkService::class);

        $d1 = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'provisional',
                'captured_name' => 'Igora 7-77 captura A',
                'captured_brand' => 'Schwarzkopf',
                'captured_tone' => '7-77',
                'captured_presentation' => '60 ml',
                'captured_quantity' => 3,
            ]],
        ], $ops);
        $c1 = $dispatchSvc->confirm($d1, $ops, 'confirm-merge-a', $d1->draft_version);
        $provA = InventoryProvisionalItem::findOrFail($c1->items->first()->provisional_item_id);

        $d2 = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'provisional',
                'captured_name' => 'Igora 7/77 captura B',
                'captured_brand' => 'Schwarzkopf',
                'captured_tone' => '7/77',
                'captured_presentation' => '60 ml',
                'captured_quantity' => 2,
            ]],
        ], $ops);
        $c2 = $dispatchSvc->confirm($d2, $ops, 'confirm-merge-b', $d2->draft_version);
        $provB = InventoryProvisionalItem::findOrFail($c2->items->first()->provisional_item_id);

        // Incompatible tone item -> must be rejected
        $provDifferentTone = $linkSvc->createProvisional([
            'name' => 'Igora 0-22',
            'brand' => 'Schwarzkopf',
            'tone' => '0-22',
            'presentation' => '60 ml',
        ], $ops);

        try {
            $linkSvc->mergeProvisionalItems($provA, $provDifferentTone, $ops, 'Prueba tono incompatible');
            $this->fail('Expected conflict on incompatible tones.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        // Self-merge -> rejected
        try {
            $linkSvc->mergeProvisionalItems($provA, $provA, $ops, 'Auto fusión');
            $this->fail('Expected conflict on self-merge.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        // Valid merge: provA -> provB (tones 7-77 and 7/77 normalize identically)
        $linkSvc->mergeProvisionalItems($provA, $provB, $ops, 'Mismo tono capturado dos veces');

        $provA->refresh();
        $this->assertSame(InventoryProvisionalItem::STATUS_MERGED, $provA->status);
        $this->assertSame($provB->id, $provA->merged_into_provisional_id);
        $this->assertSame($provB->id, $provA->canonical_provisional_id);

        // CRITICAL H08: confirmed item keeps its ORIGINAL provisional_item_id ($provA->id), NEVER rewritten!
        $this->assertSame($provA->id, $c1->items->first()->fresh()->provisional_item_id);
        $this->assertSame('Igora 7-77 captura A', $c1->items->first()->fresh()->captured_name);

        // Attempting reverse merge (cycle) -> rejected
        $this->expectException(WarehouseDispatchConflictException::class);
        $linkSvc->mergeProvisionalItems($provB, $provA, $ops, 'Intento de ciclo');
    }
}
