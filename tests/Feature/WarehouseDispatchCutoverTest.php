<?php

namespace Tests\Feature;

use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryMovement;
use App\Models\InventoryShelfAudit;
use App\Models\InventoryShelfAuditItem;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchCutoverTest extends TestCase
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

    public function test_mandatory_h01_scenario_0900_delivery_1200_count_1500_capture_prevents_double_deduction_within_24h(): void
    {
        // Operating timezone America/Hermosillo (UTC-7):
        // 09:00 local = 16:00 UTC | 12:00 local = 19:00 UTC | 15:00 local = 22:00 UTC
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'h01-wh');
        $admin = $this->makeUser(User::ROLE_ADMIN, 'h01-adm');
        $dest = $this->makeDestination('SALON-H01', 'Salón H01');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['sku' => 'IGORA-0-22', 'primary_location_id' => $loc->id]);

        // At 12:00 local (19:00 UTC): physical count registers 20 units and applies shelf audit
        Carbon::setTestNow(Carbon::parse('2026-11-27 19:00:00', 'UTC'));
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 20,
            'occurred_at' => now('UTC'),
        ], $admin);

        $audit = InventoryShelfAudit::create([
            'audit_number' => 'AUD-20261127-0001',
            'inventory_location_id' => $loc->id,
            'shelf_code' => 'D3',
            'audit_type' => 'shelf',
            'user_id' => $admin->id,
            'status' => InventoryShelfAudit::STATUS_APPLIED,
            'total_expected_products' => 1,
            'total_counted_products' => 1,
            'total_system_units' => 20,
            'total_counted_units' => 20,
            'total_difference_units' => 0,
            'applied_at' => now('UTC'),
            'applied_by' => $admin->id,
        ]);
        InventoryShelfAuditItem::create([
            'inventory_shelf_audit_id' => $audit->id,
            'inventory_product_id' => $prod->id,
            'system_quantity' => 20,
            'counted_quantity' => 20,
            'difference' => 0,
            'adjusted' => true,
        ]);

        // At 15:00 local (22:00 UTC): warehouse captures and confirms the 09:00 delivery (only 6h ago -> NOT retroactive!)
        Carbon::setTestNow(Carbon::parse('2026-11-27 22:00:00', 'UTC'));
        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-27 09:00:00', // America/Hermosillo -> 16:00 UTC
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 6,
            ]],
        ], $warehouse);

        $this->assertFalse($draft->is_retroactive);

        $confirmed = $svc->confirm($draft, $warehouse, 'confirm-h01-0900', $draft->draft_version);
        $item = $confirmed->items->first();

        $this->assertSame(InventoryDispatchItem::APPLY_SKIPPED_PRE_PHYSICAL_COUNT, $item->inventory_apply_status);
        $this->assertNull($item->inventory_movement_id);
        // Stock MUST remain 20 (NOT 14!)
        $this->assertSame(20, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));

        // Now capture a 13:00 delivery (20:00 UTC, strictly AFTER the 12:00 audit) at 15:00 -> MUST deduct 6 leaving 14
        $draftAfter = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-27 13:00:00',
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 6,
            ]],
        ], $warehouse);

        $confirmedAfter = $svc->confirm($draftAfter, $warehouse, 'confirm-h01-1300', $draftAfter->draft_version);
        $this->assertSame(InventoryDispatchItem::APPLY_APPLIED, $confirmedAfter->items->first()->inventory_apply_status);
        $this->assertSame(14, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
    }

    public function test_ambiguous_audit_window_started_before_1300_and_applied_after_1300_marks_needs_reconciliation(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'ambig-win');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        // Audit started at 12:00 local (19:00 UTC), applied at 14:00 local (21:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-11-27 19:00:00', 'UTC'));
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 20,
            'occurred_at' => now('UTC')->subHours(2),
        ], $admin);

        $audit = InventoryShelfAudit::create([
            'audit_number' => 'AUD-20261127-0002',
            'inventory_location_id' => $loc->id,
            'shelf_code' => 'D3',
            'audit_type' => 'shelf',
            'user_id' => $admin->id,
            'status' => InventoryShelfAudit::STATUS_APPLIED,
            'applied_at' => Carbon::parse('2026-11-27 21:00:00', 'UTC'),
            'applied_by' => $admin->id,
        ]);
        InventoryShelfAuditItem::create([
            'inventory_shelf_audit_id' => $audit->id,
            'inventory_product_id' => $prod->id,
            'system_quantity' => 20,
            'counted_quantity' => 20,
            'difference' => 0,
            'adjusted' => true,
        ]);

        // Delivery at 13:00 local (20:00 UTC) falls inside [19:00, 21:00] -> MUST be NEEDS_RECONCILIATION
        Carbon::setTestNow(Carbon::parse('2026-11-27 22:00:00', 'UTC'));
        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-27 13:00:00',
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 5,
            ]],
        ], $admin);

        $confirmed = $svc->confirm($draft, $admin, 'confirm-ambig-window', $draft->draft_version);
        $this->assertSame(InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION, $confirmed->items->first()->inventory_apply_status);
        $this->assertSame(20, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
    }

    public function test_unverified_initial_movement_and_exact_equality_mark_needs_reconciliation(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'init-cut');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-27 19:00:00', 'UTC'));
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 20,
            'occurred_at' => Carbon::parse('2026-11-27 19:00:00', 'UTC'),
        ], $admin);

        Carbon::setTestNow(Carbon::parse('2026-11-27 22:00:00', 'UTC'));
        $svc = app(WarehouseDispatchService::class);

        // Exact equality with unverified INITIAL -> NEEDS_RECONCILIATION (never auto-deduct, never blind skip)
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-27 12:00:00', // 19:00 UTC exact match
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 3,
            ]],
        ], $admin);

        $confirmed = $svc->confirm($draft, $admin, 'confirm-unverified-initial', $draft->draft_version);
        $this->assertSame(InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION, $confirmed->items->first()->inventory_apply_status);
        $this->assertSame(20, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
    }

    public function test_brand_audit_is_not_assumed_complete_for_all_locations(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'brand-aud');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['brand' => 'Schwarzkopf', 'primary_location_id' => $loc->id]);

        Carbon::setTestNow(Carbon::parse('2026-11-27 19:00:00', 'UTC'));
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 15,
            'occurred_at' => now('UTC')->subHours(5),
        ], $admin);

        $audit = InventoryShelfAudit::create([
            'audit_number' => 'AUD-BRAND-0001',
            'inventory_location_id' => $loc->id,
            'audit_type' => 'brand',
            'brand_name' => 'Schwarzkopf',
            'user_id' => $admin->id,
            'status' => InventoryShelfAudit::STATUS_APPLIED,
            'applied_at' => Carbon::parse('2026-11-27 19:00:00', 'UTC'),
            'applied_by' => $admin->id,
        ]);
        InventoryShelfAuditItem::create([
            'inventory_shelf_audit_id' => $audit->id,
            'inventory_product_id' => $prod->id,
            'system_quantity' => 15,
            'counted_quantity' => 15,
            'difference' => 0,
            'adjusted' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-27 22:00:00', 'UTC'));
        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-27 10:00:00',
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 4,
            ]],
        ], $admin);

        $confirmed = $svc->confirm($draft, $admin, 'confirm-brand-audit', $draft->draft_version);
        $this->assertSame(InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION, $confirmed->items->first()->inventory_apply_status);
        $this->assertSame(15, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
    }

    public function test_future_effective_date_is_rejected_and_retroactive_over_24h_requires_operations_or_admin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-27 20:00:00', 'UTC'));
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'retro-wh');
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'retro-ops');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);
        $svc = app(WarehouseDispatchService::class);

        // Future date rejected
        try {
            $svc->createDraft([
                'destination_id' => $dest->id,
                'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
                'effective_at' => '2026-11-28 15:00:00',
                'items' => [[
                    'kind' => 'registered',
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 1,
                ]],
            ], $ops);
            $this->fail('Expected future effective_at to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // >24h retroactive blocked for warehouse role
        try {
            $svc->createDraft([
                'destination_id' => $dest->id,
                'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
                'effective_at' => '2026-11-25 10:00:00',
                'retroactive_reason' => 'Captura tardía',
                'items' => [[
                    'kind' => 'registered',
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 1,
                ]],
            ], $warehouse);
            $this->fail('Expected warehouse role to be blocked from retroactive >24h dispatch.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        // Operations allowed with mandatory reason
        $retroDraft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-25 10:00:00',
            'retroactive_reason' => 'Nota en papel entregada el miércoles',
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 1,
            ]],
        ], $ops);

        $this->assertTrue($retroDraft->is_retroactive);
    }
}
