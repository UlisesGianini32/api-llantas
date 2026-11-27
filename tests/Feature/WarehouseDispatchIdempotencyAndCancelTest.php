<?php

namespace Tests\Feature;

use App\Exceptions\WarehouseDispatchConflictException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchIdempotencyAndCancelTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    public function test_create_idempotency_replays_same_payload_and_conflicts_on_different_payload_or_unauthorized_user(): void
    {
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'idem-wh');
        $posUser = $this->makeUser(User::ROLE_POS, 'idem-pos');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);
        $svc = app(WarehouseDispatchService::class);

        $payload = [
            'create_idempotency_key' => 'create-key-0001',
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => '2026-11-27 10:00:00',
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 3,
            ]],
        ];

        $first = $svc->createDraft($payload, $warehouse);
        $replay = $svc->createDraft($payload, $warehouse);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, InventoryDispatch::query()->count());

        // Different payload with same key -> 409 Conflict
        $differentPayload = $payload;
        $differentPayload['items'][0]['captured_quantity'] = 5;
        try {
            $svc->createDraft($differentPayload, $warehouse);
            $this->fail('Expected conflict on reused create_idempotency_key with different payload.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        // Unauthorized role attempting to replay key -> 403 AuthorizationException
        $this->expectException(AuthorizationException::class);
        $svc->createDraft($payload, $posUser);
    }

    public function test_confirm_idempotency_replays_after_commit_and_rejects_cross_document_reuse_and_version_mismatch(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'conf-ops');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 20,
            'occurred_at' => now('UTC')->subDay(),
        ], $ops);

        $svc = app(WarehouseDispatchService::class);
        $draft1 = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 4,
            ]],
        ], $ops);

        // Confirm with stale expected_draft_version fails with 409
        try {
            $svc->confirm($draft1, $ops, 'confirm-key-stale-ver', 99);
            $this->fail('Expected conflict on stale draft version.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        $confirmed1 = $svc->confirm($draft1, $ops, 'confirm-key-valid-1', $draft1->draft_version);
        // Replay identical confirmation (e.g. lost HTTP response) -> idempotent, still 1 movement!
        $replayed = $svc->confirm($draft1->fresh(), $ops, 'confirm-key-valid-1', $draft1->draft_version);

        $this->assertSame($confirmed1->id, $replayed->id);
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());

        // Different confirm key on already-confirmed dispatch -> 409
        try {
            $svc->confirm($draft1->fresh(), $ops, 'confirm-key-different-2', $draft1->draft_version);
            $this->fail('Expected conflict when confirming an already confirmed dispatch with a different key.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        // Reusing confirm key on a DIFFERENT dispatch -> 409
        $draft2 = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ], $ops);

        $this->expectException(WarehouseDispatchConflictException::class);
        $svc->confirm($draft2, $ops, 'confirm-key-valid-1', $draft2->draft_version);
    }

    public function test_insufficient_stock_logs_discrepancy_without_negative_stock_and_allows_item_1_to_apply(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'disc-ops');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prodOk = $this->makeProduct(['sku' => 'OK-SKU', 'primary_location_id' => $loc->id]);
        $prodShort = $this->makeProduct(['sku' => 'SHORT-SKU', 'primary_location_id' => $loc->id]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prodOk->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDay(),
        ], $ops);
        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prodShort->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 2,
            'occurred_at' => now('UTC')->subDay(),
        ], $ops);

        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $prodOk->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 4,
                ],
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $prodShort->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 5,
                ],
            ],
        ], $ops);

        $confirmed = $svc->confirm($draft, $ops, 'confirm-partial-short', $draft->draft_version);

        $itemOk = $confirmed->items->firstWhere('inventory_product_id', $prodOk->id);
        $itemShort = $confirmed->items->firstWhere('inventory_product_id', $prodShort->id);

        $this->assertSame(InventoryDispatchItem::APPLY_APPLIED, $itemOk->inventory_apply_status);
        $this->assertSame(InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK, $itemShort->inventory_apply_status);
        $this->assertSame(6, (int) InventoryMovement::where('inventory_product_id', $prodOk->id)->sum('quantity'));
        $this->assertSame(2, (int) InventoryMovement::where('inventory_product_id', $prodShort->id)->sum('quantity'));
    }

    public function test_h03_blocks_cancelling_confirmed_dispatch_with_applied_ledger_and_allows_without_ledger(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'h03-ops');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDay(),
        ], $ops);

        $svc = app(WarehouseDispatchService::class);
        $draftApplied = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 3,
            ]],
        ], $ops);
        $confirmedApplied = $svc->confirm($draftApplied, $ops, 'confirm-h03-applied', $draftApplied->draft_version);

        // Must BLOCK cancelling a confirmed dispatch that has APPLIED ledger movements (no DISPATCH_RETURN in Phase 1)
        try {
            $svc->cancelConfirmedWithoutLedger($confirmedApplied, $ops, 'Error de captura', InventoryDispatch::CANCEL_SITUATION_NEVER_DELIVERED);
            $this->fail('Expected conflict blocking compensatory DISPATCH_RETURN in Phase 1.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::DISPATCH_RETURN)->count());
        }

        // Provisional-only confirmed dispatch (0 ledger movements): reject DELIVERED_AND_RETURNED, allow NEVER_DELIVERED
        $draftProv = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'provisional',
                'captured_name' => 'Producto provisional',
                'captured_quantity' => 2,
            ]],
        ], $ops);
        $confirmedProv = $svc->confirm($draftProv, $ops, 'confirm-h03-prov', $draftProv->draft_version);

        try {
            $svc->cancelConfirmedWithoutLedger($confirmedProv, $ops, 'Salón lo regresó', 'DELIVERED_AND_RETURNED');
            $this->fail('Expected conflict when trying to disguise physical return as cancellation.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        $cancelled = $svc->cancelConfirmedWithoutLedger(
            $confirmedProv,
            $ops,
            'Duplicado de captura, mercancía nunca salió',
            InventoryDispatch::CANCEL_SITUATION_NEVER_DELIVERED,
        );
        $this->assertSame(InventoryDispatch::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(InventoryDispatchItem::APPLY_CANCELLED_NO_MOVEMENT, $cancelled->items->first()->inventory_apply_status);
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::DISPATCH_RETURN)->count());

        // Restrict-on-delete prevents physical deletion of dispatch with items
        $this->expectException(QueryException::class);
        $cancelled->delete();
    }
}
