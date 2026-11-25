<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Jobs\SyncInventoryMeliStockLinkJob;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitComponent;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryMeliOrderReconciliation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Services\InventoryMeliOrderReconciliationCoordinator;
use App\Services\InventoryMeliOrderReservationDispatcher;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\InventoryStockService;
use App\Services\MeliOrderStoreService;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueRecoveryDiskChecker;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InventoryMeliOrderReservationR3IdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'UTC'));

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', true);
        config()->set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        config()->set('inventory.meli_order_reservations.lease_seconds', 300);

        DB::purge('sqlite');
        $this->createFullTestSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('sqlite');
        parent::tearDown();
    }

    // =========================================================================
    // 1. Repeated sync without material changes generates 0 duplicate jobs
    // =========================================================================
    public function test_r3_01_repeated_sync_without_material_changes_generates_zero_duplicate_jobs(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-01', 10);
        $this->link($product, 1, 'MLM-R3-01', null, true);

        $store = app(MeliOrderStoreService::class);
        $basePayload = [
            'id' => 910001,
            'status' => 'paid',
            'date_created' => '2026-11-20T10:00:00-06:00',
            'last_updated' => '2026-11-20T10:05:00-06:00',
            'order_items' => [[
                'item' => ['id' => 'MLM-R3-01', 'seller_sku' => 'SKU-R3-01'],
                'quantity' => 2,
                'unit_price' => 150.00,
            ]],
        ];

        $order = $store->storeFromOrderApiResponse($basePayload);
        $order->update(['meli_account_id' => 1]);
        $this->assertSame(1, DB::table('jobs')->count());

        // Execute the 1 queued job so order becomes IDLE & reconciled.
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle(app(InventoryMeliOrderReservationService::class));
        DB::table('jobs')->truncate();

        // Sync 10 more times with ONLY non-material changes (last_updated, buyer metadata, unit_price).
        for ($i = 1; $i <= 10; $i++) {
            $noisyPayload = $basePayload;
            $noisyPayload['last_updated'] = sprintf('2026-11-20T10:%02d:00-06:00', 10 + $i);
            $noisyPayload['buyer'] = ['nickname' => 'buyer_'.$i];
            $noisyPayload['order_items'][0]['unit_price'] = 150.00 + $i;
            $store->storeFromOrderApiResponse($noisyPayload);
        }

        $this->assertSame(0, DB::table('jobs')->count(), 'Non-material syncs must enqueue 0 jobs once reconciled.');
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
    }

    // =========================================================================
    // 2. Legitimate update WHILE a job is PENDING coalesces without losing update
    // =========================================================================
    public function test_r3_02_material_update_while_job_is_pending_coalesces_and_reconciles_latest_state(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-02', 10);
        $this->link($product, 1, 'MLM-R3-02', null, true);

        $order = $this->order(1, 910002, 'paid', [['item_id' => 'MLM-R3-02', 'key' => 'MLM-R3-02:', 'qty' => 2]]);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // First sync -> enqueues 1 job (rev=1, PENDING).
        $dispatcher->dispatchAfterCommit($order);
        $this->assertSame(1, DB::table('jobs')->count());

        // Second sync while job #1 is STILL PENDING: order quantity changes 2 -> 4, then order is cancelled!
        $order->items()->update(['quantity' => 4]);
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $order->update(['status' => 'cancelled']);
        $dispatcher->dispatchAfterCommit($order->fresh('items'));

        // Still strictly 1 queued job in `jobs`, but requested_revision tracked every material change!
        $this->assertSame(1, DB::table('jobs')->count());
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);
        $this->assertSame(3, $state->requested_revision);

        // When that single queued job finally executes, it loads fresh cancelled state and reconciles revision 3!
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle(app(InventoryMeliOrderReservationService::class));

        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, $state->state);
        $this->assertSame(3, $state->processed_revision);
        $this->assertSame(0, InventoryReservation::active()->count());
    }

    // =========================================================================
    // 3. Legitimate update DURING execution triggers exactly 1 follow-up job
    // =========================================================================
    public function test_r3_03_material_update_during_job_execution_triggers_single_followup_job_with_zero_lost_updates(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-03', 10);
        $this->link($product, 1, 'MLM-R3-03', null, true);

        $order = $this->order(1, 910003, 'paid', [['item_id' => 'MLM-R3-03', 'key' => 'MLM-R3-03:', 'qty' => 3]]);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $dispatcher->dispatchAfterCommit($order);
        $this->assertSame(1, DB::table('jobs')->count());
        DB::table('jobs')->truncate(); // Worker popped job #1

        $realService = app(InventoryMeliOrderReservationService::class);

        // Wrap apply() to simulate a concurrent webhook updating the order to 'cancelled' MID-EXECUTION!
        $wrapped = Mockery::mock(InventoryMeliOrderReservationService::class);
        $wrapped->shouldReceive('apply')->once()->andReturnUsing(function (MeliOrder $runningOrder) use ($realService, $dispatcher): array {
            $res = $realService->apply($runningOrder);
            // Mid-flight update while job #1 holds the PROCESSING lease!
            $runningOrder->update(['status' => 'cancelled']);
            $dispatcher->dispatchAfterCommit($runningOrder->fresh('items'));

            // Must NOT have dispatched a concurrent overlapping job while PROCESSING!
            $this->assertSame(0, DB::table('jobs')->count());

            return $res;
        });

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($wrapped);

        // Reservation was created by job #1, AND job #1 deterministically scheduled 1 follow-up job!
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);
        $this->assertGreaterThan($state->processed_revision, $state->requested_revision);

        // Execute the follow-up job -> releases reservation and settles cleanly to IDLE!
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($realService);

        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, $state->state);
        $this->assertSame($state->requested_revision, $state->processed_revision);
        $this->assertSame(0, InventoryReservation::active()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // =========================================================================
    // 4. Workers stopped for SEVERAL DAYS (Supervisor FATAL) — zero TTL leak
    // =========================================================================
    public function test_r3_04_workers_stopped_for_five_days_keeps_strictly_one_pending_job_per_order(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-04', 20);
        $this->link($product, 1, 'MLM-R3-04', null, true);

        $order = $this->order(1, 910004, 'paid', [['item_id' => 'MLM-R3-04', 'key' => 'MLM-R3-04:', 'qty' => 1]]);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Simulate 5 days of Supervisor FATAL with syncs every 4 hours (30 syncs over 120 hours).
        for ($hour = 0; $hour <= 120; $hour += 4) {
            Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'UTC')->addHours($hour));
            $dispatcher->dispatchAfterCommit($order->fresh('items'));
        }

        // Regardless of 120 hours passing (far beyond any ShouldBeUnique TTL), strictly 1 job exists!
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // 5. Expired lease recovery (SIGKILL/OOM) + retry after transient exception
    // =========================================================================
    public function test_r3_05_expired_lease_is_reclaimed_and_exception_retry_recovers_cleanly(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-05', 10);
        $this->link($product, 1, 'MLM-R3-05', null, true);

        $order = $this->order(1, 910005, 'paid', [['item_id' => 'MLM-R3-05', 'key' => 'MLM-R3-05:', 'qty' => 2]]);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Simulate a crashed worker that claimed a lease and died (SIGKILL) without calling markFailed().
        $claim = $coordinator->claimForExecution($order->id, 'dead-worker-1', leaseSeconds: 300);
        $this->assertTrue($claim['claimed']);

        // While lease is still active (+100s), another worker CANNOT overlap and dispatcher won't duplicate.
        Carbon::setTestNow(Carbon::now()->addSeconds(100));
        $blockedClaim = $coordinator->claimForExecution($order->id, 'worker-2', leaseSeconds: 300);
        $this->assertFalse($blockedClaim['claimed']);
        $this->assertSame('active_lease_held_by_another_worker', $blockedClaim['reason']);

        // Advance past lease expiry (+301s total) -> dispatcher reclaims expired lease and enqueues 1 recovery job!
        Carbon::setTestNow(Carbon::now()->addSeconds(205));
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(1, DB::table('jobs')->count());

        // Now simulate transient failure on attempt #1 -> marks FAILED & rethrows.
        $failingService = Mockery::mock(InventoryMeliOrderReservationService::class);
        $failingService->shouldReceive('apply')->once()->andThrow(new RuntimeException('Transient DB error'));
        try {
            (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($failingService);
            $this->fail('Expected RuntimeException to propagate for queue retry.');
        } catch (RuntimeException) {
            // Expected
        }

        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_FAILED, $state->state);
        $this->assertStringContainsString('Transient DB error', (string) $state->last_error);

        // Attempt #2 succeeds and settles to IDLE with exact reservation!
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle(app(InventoryMeliOrderReservationService::class));

        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, $state->state);
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
    }

    // =========================================================================
    // 6. Terminal FULFILLED immutability: NEVER double-deduct inventory on replay/flap
    // =========================================================================
    public function test_r3_06_fulfilled_order_never_re_reserves_or_double_deducts_inventory_on_status_flap_or_replay(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-06', 10);
        $this->link($product, 1, 'MLM-R3-06', null, true);

        $order = $this->order(1, 910006, 'paid', [['item_id' => 'MLM-R3-06', 'key' => 'MLM-R3-06:', 'qty' => 3]], 'ready_to_ship');
        $service = app(InventoryMeliOrderReservationService::class);

        // Step 1: Reserve
        $service->apply($order);
        $this->assertSame(10, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(7, app(InventoryStockService::class)->availableStock($product));

        // Step 2: Ship -> Fulfill (deducts 3 physical stock)
        $order->update(['shipping_status' => 'shipped']);
        $service->apply($order->fresh('items'));
        $this->assertSame(7, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(0, InventoryReservation::active()->count());

        // Step 3: Replay fulfill multiple times -> 0 extra movements!
        $replay = $service->apply($order->fresh('items'));
        $this->assertSame('FULFILL_NOTHING_TO_DO', $replay[0]['action']);
        $this->assertSame(7, app(InventoryStockService::class)->physicalStock($product));

        // Step 4: Simulate transient ML /shipments/{id} failure reverting shipping_status to null while status='paid'!
        $order->update(['shipping_status' => null]);
        $flap = $service->apply($order->fresh('items'));
        $this->assertSame(InventoryMeliOrderReservationService::ALREADY_FULFILLED, $flap[0]['action']);
        $this->assertSame(0, InventoryReservation::active()->count(), 'Must NEVER re-reserve an already-fulfilled order!');

        // Step 5: Shipping status returns to 'delivered' -> still 0 double deduction!
        $order->update(['shipping_status' => 'delivered']);
        $service->apply($order->fresh('items'));
        $this->assertSame(7, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::SALE)->count());

        // Step 6: Link deactivated later -> must NOT overwrite FULFILLED allocation status with DIAGNOSTIC!
        InventoryChannelLink::query()->update(['is_active' => false]);
        $service->apply($order->fresh('items'));
        $this->assertSame('FULFILLED', InventoryChannelOrderAllocation::sole()->status);
    }

    // =========================================================================
    // 7. Shipped during outage WITHOUT prior reservation -> NEVER deducts retroactively
    // =========================================================================
    public function test_r3_07_shipped_during_outage_without_prior_reservation_never_deducts_stock(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-07', 10);
        $this->link($product, 1, 'MLM-R3-07', null, true);

        $order = $this->order(1, 910007, 'paid', [['item_id' => 'MLM-R3-07', 'key' => 'MLM-R3-07:', 'qty' => 2]], 'delivered');
        $service = app(InventoryMeliOrderReservationService::class);

        $res = $service->apply($order);
        $this->assertSame('FULFILL_NOTHING_TO_DO', $res[0]['action']);
        $this->assertSame(10, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(10, app(InventoryStockService::class)->availableStock($product));
        $this->assertSame(0, InventoryReservation::count());
        $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::SALE)->count());
    }

    // =========================================================================
    // 8. Manual adjustments & order-referenced manual movements protect ambiguous orders
    // =========================================================================
    public function test_r3_08_manual_adjustment_and_manual_order_movement_block_auto_reservation_while_receipts_and_unrelated_pos_sales_pass(): void
    {
        [$prodAdj, $loc] = $this->stockedProduct('SKU-R3-08-ADJ', 10);
        $this->link($prodAdj, 1, 'MLM-R3-08-ADJ', null, true);

        // Case A: Manual ADJUSTMENT_OUT after order creation -> MANUAL_MOVEMENT_REVIEW_REQUIRED!
        $orderAdj = $this->order(1, 910008, 'paid', [['item_id' => 'MLM-R3-08-ADJ', 'key' => 'MLM-R3-08-ADJ:', 'qty' => 1]], 'ready_to_ship', '2026-11-20T10:00:00-06:00');
        InventoryMovement::create([
            'inventory_product_id' => $prodAdj->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::ADJUSTMENT_OUT,
            'quantity' => -1,
            'reference_type' => 'manual_audit',
            'occurred_at' => Carbon::parse('2026-11-20 12:00:00', 'America/Mexico_City'),
        ]);

        $service = app(InventoryMeliOrderReservationService::class);
        $resAdj = $service->apply($orderAdj);
        $this->assertSame(InventoryMeliOrderReservationService::MANUAL_MOVEMENT_REVIEW_REQUIRED, $resAdj[0]['action']);
        $this->assertSame(0, InventoryReservation::active()->count());

        // Case B: Manual SALE explicitly referencing order_id -> MANUAL_MOVEMENT_REVIEW_REQUIRED!
        [$prodRef, $loc2] = $this->stockedProduct('SKU-R3-08-REF', 10);
        $this->link($prodRef, 1, 'MLM-R3-08-REF', null, true);
        $orderRef = $this->order(1, 910009, 'paid', [['item_id' => 'MLM-R3-08-REF', 'key' => 'MLM-R3-08-REF:', 'qty' => 1]], 'ready_to_ship', '2026-11-20T10:00:00-06:00');
        InventoryMovement::create([
            'inventory_product_id' => $prodRef->id,
            'inventory_location_id' => $loc2->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -1,
            'reference' => 'Salida manual por orden ML 910009',
            'occurred_at' => Carbon::parse('2026-11-20 11:00:00', 'America/Mexico_City'),
        ]);

        $resRef = $service->apply($orderRef);
        $this->assertSame(InventoryMeliOrderReservationService::MANUAL_MOVEMENT_REVIEW_REQUIRED, $resRef[0]['action']);
        $this->assertSame(0, InventoryReservation::active()->count());

        // Case C: Normal warehouse RECEIPT and unrelated POS SALE do NOT falsely block a live ML order!
        [$prodLive, $loc3] = $this->stockedProduct('SKU-R3-08-LIVE', 10);
        $this->link($prodLive, 1, 'MLM-R3-08-LIVE', null, true);
        $orderLive = $this->order(1, 910010, 'paid', [['item_id' => 'MLM-R3-08-LIVE', 'key' => 'MLM-R3-08-LIVE:', 'qty' => 2]], 'ready_to_ship', '2026-11-20T10:00:00-06:00');
        InventoryMovement::create([
            'inventory_product_id' => $prodLive->id,
            'inventory_location_id' => $loc3->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -1,
            'reference' => 'POS ticket #441',
            'occurred_at' => Carbon::parse('2026-11-20 10:05:00', 'America/Mexico_City'),
        ]);

        $resLive = $service->apply($orderLive);
        $this->assertSame(InventoryMeliOrderReservationService::RESERVE, $resLive[0]['action']);
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(2, InventoryReservation::active()->sum('quantity'));
    }

    // =========================================================================
    // 9. Kits & components: manual component adjustment blocks kit; normal kit fulfills once
    // =========================================================================
    public function test_r3_09_kits_and_components_protect_manual_component_adjustments_and_fulfill_idempotently(): void
    {
        [$comp1, $loc] = $this->stockedProduct('SKU-R3-KIT-C1', 10);
        [$comp2] = $this->stockedProduct('SKU-R3-KIT-C2', 10);

        $kit = InventoryProduct::create([
            'sku' => 'SKU-R3-KIT',
            'name' => 'Kit R3',
            'product_type' => InventoryProduct::KIT,
            'is_active' => true,
        ]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $comp1->id, 'quantity' => 1]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $comp2->id, 'quantity' => 2]);
        $this->link($kit, 1, 'MLM-R3-KIT', null, true);

        $service = app(InventoryMeliOrderReservationService::class);

        // Normal kit order reserves, fulfills once, and ignores replay!
        $orderOk = $this->order(1, 910011, 'paid', [['item_id' => 'MLM-R3-KIT', 'key' => 'MLM-R3-KIT:', 'qty' => 2]], 'ready_to_ship', '2026-11-20T09:00:00-06:00');
        $service->apply($orderOk);
        $this->assertSame(1, InventoryKitReservation::active()->count());
        $this->assertSame(6, InventoryReservation::active()->sum('quantity')); // 2*1 + 2*2

        $orderOk->update(['shipping_status' => 'shipped']);
        $service->apply($orderOk->fresh('items'));
        $service->apply($orderOk->fresh('items')); // Replay must be no-op!
        $this->assertSame(0, InventoryKitReservation::active()->count());
        $this->assertSame(8, app(InventoryStockService::class)->physicalStock($comp1));
        $this->assertSame(6, app(InventoryStockService::class)->physicalStock($comp2));

        // Second kit order where comp1 had a manual ADJUSTMENT_OUT after order creation -> blocked!
        $orderBlocked = $this->order(1, 910012, 'paid', [['item_id' => 'MLM-R3-KIT', 'key' => 'MLM-R3-KIT:', 'qty' => 1]], 'ready_to_ship', '2026-11-21T09:00:00-06:00');
        InventoryMovement::create([
            'inventory_product_id' => $comp1->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::ADJUSTMENT_OUT,
            'quantity' => -1,
            'reference_type' => 'manual_audit',
            'occurred_at' => Carbon::parse('2026-11-21 10:00:00', 'America/Mexico_City'),
        ]);
        $blockedRes = $service->apply($orderBlocked);
        $this->assertSame(InventoryMeliOrderReservationService::MANUAL_MOVEMENT_REVIEW_REQUIRED, $blockedRes[0]['action']);
        $this->assertSame(0, InventoryKitReservation::active()->count());
    }

    // =========================================================================
    // 10. Multiline order isolation: direct fallback NEVER steals another line's reservation
    // =========================================================================
    public function test_r3_10_multiline_order_fulfillment_never_steals_another_lines_reservation(): void
    {
        [$prodA] = $this->stockedProduct('SKU-R3-ML-A', 10);
        [$prodB] = $this->stockedProduct('SKU-R3-ML-B', 10);
        $this->link($prodA, 1, 'MLM-R3-ML', 'var-a', true);
        $this->link($prodB, 1, 'MLM-R3-ML', 'var-b', false); // Line B has reservation disabled!

        $order = $this->order(1, 910013, 'paid', [
            ['item_id' => 'MLM-R3-ML', 'variation' => 'var-a', 'key' => 'MLM-R3-ML:var-a', 'qty' => 2],
            ['item_id' => 'MLM-R3-ML', 'variation' => 'var-b', 'key' => 'MLM-R3-ML:var-b', 'qty' => 3],
        ], 'ready_to_ship');

        $service = app(InventoryMeliOrderReservationService::class);
        $service->apply($order);

        // Only Line A has an active reservation; Line B is SKIPPED_ORDER_RESERVATION_DISABLED.
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame($prodA->id, InventoryReservation::active()->sole()->inventory_product_id);

        // Now order ships: Line A fulfills its OWN reservation (2 units of prodA);
        // Line B MUST NOT steal Line A's reservation or deduct prodB!
        $order->update(['shipping_status' => 'shipped']);
        $results = $service->apply($order->fresh('items'));

        $actionsByLine = [];
        foreach ($results as $r) {
            $actionsByLine[$r['variation']] = $r['action'];
        }
        $this->assertSame(InventoryMeliOrderReservationService::FULFILL, $actionsByLine['var-a']);
        $this->assertSame('FULFILL_NOTHING_TO_DO', $actionsByLine['var-b']);
        $this->assertSame(8, app(InventoryStockService::class)->physicalStock($prodA));
        $this->assertSame(10, app(InventoryStockService::class)->physicalStock($prodB));
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::SALE)->count());
    }

    // =========================================================================
    // 11. Shared stock consistency + NO_CHANGE still dispatches stock sync for convergence
    // =========================================================================
    public function test_r3_11_shared_stock_consistency_and_stock_sync_convergence_on_no_change(): void
    {
        Bus::fake([SyncInventoryMeliStockLinkJob::class]);

        [$product] = $this->stockedProduct('SKU-R3-SH', 10);
        $repLink = $this->link($product, 1, 'MLM-R3-SH-1', null, true, true, true, 'UP-R3-SHARED');
        $this->link($product, 1, 'MLM-R3-SH-2', null, true, true, true, 'UP-R3-SHARED');

        $order = $this->order(1, 910014, 'paid', [['item_id' => 'MLM-R3-SH-1', 'key' => 'MLM-R3-SH-1:', 'qty' => 1]]);
        $service = app(InventoryMeliOrderReservationService::class);

        // Run #1: reserves and dispatches 1 SyncInventoryMeliStockLinkJob for the shared group.
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
        Bus::assertDispatchedTimes(SyncInventoryMeliStockLinkJob::class, 1);
        Bus::assertDispatched(SyncInventoryMeliStockLinkJob::class, fn (SyncInventoryMeliStockLinkJob $j): bool => $j->linkId === $repLink->id);

        // Run #2 (retry / manual convergence on NO_CHANGE): STILL dispatches 1 SyncInventoryMeliStockLinkJob!
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
        Bus::assertDispatchedTimes(SyncInventoryMeliStockLinkJob::class, 2);
        $this->assertSame(1, InventoryReservation::active()->count());
    }

    // =========================================================================
    // 12. Cutover enforcement preserved
    // =========================================================================
    public function test_r3_12_cutover_restrictions_remain_enforced_fail_closed(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-CUT', 5);
        $this->link($product, 1, 'MLM-R3-CUT', null, true);

        // Order strictly BEFORE cutover (2026-10-01T00:00:00-06:00).
        $oldOrder = $this->order(1, 910015, 'paid', [['item_id' => 'MLM-R3-CUT', 'key' => 'MLM-R3-CUT:', 'qty' => 1]], 'ready_to_ship', '2026-09-30T23:59:59-06:00');
        app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($oldOrder);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, InventoryMeliOrderReconciliation::count());
    }

    // =========================================================================
    // 13. Full compatibility with R2.1 QueuePayloadInspector & QueuePruneService
    // =========================================================================
    public function test_r3_13_r3_dispatched_jobs_remain_100_percent_compatible_with_r21_tools(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-R21', 5);
        $this->link($product, 1, 'MLM-R3-R21', null, true);

        $order = $this->order(1, 910016, 'paid', [['item_id' => 'MLM-R3-R21', 'key' => 'MLM-R3-R21:', 'qty' => 1]]);
        app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($order);

        $row = DB::table('jobs')->first();
        $this->assertNotNull($row);

        $inspection = app(QueuePayloadInspector::class)->inspect(
            (string) $row->payload,
            [QueuePayloadInspector::JOB_RESERVATIONS],
        );

        $this->assertSame(QueuePayloadInspector::CLASSIFICATION_CANDIDATE, $inspection['classification']);
        $this->assertSame(QueuePayloadInspector::JOB_RESERVATIONS, $inspection['job_class']);
        $this->assertSame('meli_order', $inspection['entity_type']);
        $this->assertSame($order->id, $inspection['entity_id']);

        $this->app->singleton(QueueRecoveryDiskChecker::class, fn () => new QueueRecoveryDiskChecker(
            static fn (): int => 20 * 1024 * 1024 * 1024,
        ));
        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true],
        ));

        $dry = app(QueuePruneService::class)->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-R3-R21-COMPAT',
        ]);
        $this->assertSame(1, $dry['manifest']['totals']['candidates']);
        $this->assertSame($order->id, $dry['manifest']['entries'][0]['entity_id']);
    }

    // =========================================================================
    // 14. Synthetic repeated-sync benchmark: BEFORE vs AFTER R3 job counts
    // =========================================================================
    public function test_r3_14_synthetic_repeated_syncs_before_vs_after_job_generation_comparison(): void
    {
        [$product] = $this->stockedProduct('SKU-R3-BENCH', 500);
        $this->link($product, 1, 'MLM-R3-BENCH', null, true);

        $orderCount = 50;
        $syncCycles = 48; // Simulates 24h of 30-min syncs (or burst of 5-min syncs)
        $totalSyncCalls = $orderCount * $syncCycles; // Baseline pre-R3 = 2,400 jobs!

        $orders = [];
        for ($i = 1; $i <= $orderCount; $i++) {
            $orders[] = $this->order(1, 950000 + $i, 'paid', [
                ['item_id' => 'MLM-R3-BENCH', 'key' => 'MLM-R3-BENCH:', 'qty' => 1],
            ]);
        }

        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Scenario A: Workers STOPPED (Supervisor FATAL) across all 48 sync cycles + 5 real status changes!
        for ($cycle = 1; $cycle <= $syncCycles; $cycle++) {
            foreach ($orders as $idx => $ord) {
                if ($cycle === 24 && $idx < 5) {
                    $ord->update(['status' => 'cancelled']);
                }
                $dispatcher->dispatchAfterCommit($ord->fresh('items'));
            }
        }
        $afterR3WorkersStoppedJobs = (int) DB::table('jobs')->count();
        $this->assertSame($orderCount, $afterR3WorkersStoppedJobs);

        // Scenario B: Reset queue & reconciliation state; simulate ACTIVE workers draining after each cycle
        // with 5 real transitions (paid -> shipped) mid-way across 48 sync cycles!
        DB::table('jobs')->truncate();
        InventoryMeliOrderReconciliation::query()->truncate();
        InventoryChannelOrderAllocation::query()->truncate();
        InventoryReservation::query()->truncate();
        foreach ($orders as $ord) {
            $ord->update(['status' => 'paid', 'shipping_status' => 'ready_to_ship']);
        }

        $service = app(InventoryMeliOrderReservationService::class);
        $totalDispatchedActiveWorkers = 0;

        for ($cycle = 1; $cycle <= $syncCycles; $cycle++) {
            foreach ($orders as $idx => $ord) {
                if ($cycle === 24 && $idx < 5) {
                    $ord->update(['shipping_status' => 'shipped']);
                }
                $dispatcher->dispatchAfterCommit($ord->fresh('items'));
            }

            $pendingRows = DB::table('jobs')->get();
            $totalDispatchedActiveWorkers += $pendingRows->count();
            DB::table('jobs')->truncate();

            foreach ($pendingRows as $jobRow) {
                $inspected = app(QueuePayloadInspector::class)->inspect((string) $jobRow->payload, [QueuePayloadInspector::JOB_RESERVATIONS]);
                (new ReconcileInventoryMeliOrderReservationsJob((int) $inspected['entity_id']))->handle($service);
            }
        }

        // Exactly 50 initial + 5 real material shipping transitions = 55 jobs instead of 2,400!
        $this->assertSame($orderCount + 5, $totalDispatchedActiveWorkers);
        $this->assertSame(5, InventoryChannelOrderAllocation::where('status', 'FULFILLED')->count());
        $this->assertSame(45, InventoryChannelOrderAllocation::where('status', 'ACTIVE')->count());

        $metrics = [
            'orders' => $orderCount,
            'sync_cycles' => $syncCycles,
            'baseline_pre_r3_jobs' => $totalSyncCalls,
            'after_r3_workers_stopped_jobs' => $afterR3WorkersStoppedJobs,
            'after_r3_workers_stopped_reduction_pct' => round((1 - ($afterR3WorkersStoppedJobs / $totalSyncCalls)) * 100, 2),
            'after_r3_workers_active_with_5_transitions_jobs' => $totalDispatchedActiveWorkers,
            'after_r3_workers_active_reduction_pct' => round((1 - ($totalDispatchedActiveWorkers / $totalSyncCalls)) * 100, 2),
        ];

        if (getenv('R3_PRINT_METRICS') === '1') {
            fwrite(STDERR, "\nR3_SYNTHETIC_METRICS=".json_encode($metrics, JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createFullTestSchema(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('admin');
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('llantas', fn (Blueprint $table) => $table->id());

        foreach ([
            '2026_09_24_000001_create_inventory_products_table.php',
            '2026_09_24_000002_create_inventory_locations_table.php',
            '2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php',
            '2026_09_24_000004_create_inventory_movements_table.php',
            '2026_09_24_000005_create_inventory_reservations_table.php',
            '2026_09_24_000006_add_product_type_to_inventory_products_table.php',
            '2026_09_24_000007_create_inventory_kit_components_table.php',
            '2026_09_24_000008_create_inventory_kit_reservations_table.php',
            '2026_09_25_000001_create_inventory_channel_links_table.php',
            '2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php',
            '2026_09_25_000003_create_inventory_channel_stock_syncs_table.php',
            '2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('topic')->nullable();
            $table->string('resource')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_status')->nullable();
            $table->json('shipping_raw')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id', 30);
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['meli_order_id', 'item_id']);
        });

        (require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php'))->up();
        (require database_path('migrations/2026_11_25_000001_create_inventory_meli_order_reconciliations_table.php'))->up();

        DB::table('meli_accounts')->insert([
            'id' => 1,
            'meli_user_id' => '111111',
            'nickname' => 'Cuenta 1',
            'is_default' => true,
        ]);
    }

    /** @return array{0: InventoryProduct, 1: InventoryLocation} */
    private function stockedProduct(string $sku, int $qty): array
    {
        $location = InventoryLocation::firstOrCreate(
            ['code' => 'MAIN-R3'],
            ['name' => 'Main R3', 'is_active' => true],
        );

        $product = InventoryProduct::create([
            'sku' => $sku,
            'name' => $sku,
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => $location->id,
            'is_active' => true,
        ]);

        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => $qty,
            'occurred_at' => Carbon::parse('2026-09-01 00:00:00', 'UTC'),
        ]);

        return [$product, $location];
    }

    private function link(
        InventoryProduct $product,
        int $accountId,
        string $mlm,
        ?string $variation = null,
        bool $reservationEnabled = true,
        bool $active = true,
        bool $stockSyncEnabled = false,
        ?string $remoteUserProductId = null,
    ): InventoryChannelLink {
        return InventoryChannelLink::create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $accountId,
            'external_listing_id' => $mlm,
            'external_variant_id' => $variation,
            'remote_user_product_id' => $remoteUserProductId,
            'identity_key' => implode(':', [$accountId, $mlm, $variation ?? 'simple', $product->id, bin2hex(random_bytes(3))]),
            'is_active' => $active,
            'stock_sync_enabled' => $stockSyncEnabled,
            'order_reservation_enabled' => $reservationEnabled,
        ]);
    }

    /** @param list<array{item_id: string, variation?: ?string, key: ?string, qty: int}> $lines */
    private function order(
        int $accountId,
        int $remoteOrderId,
        string $status,
        array $lines,
        ?string $shippingStatus = 'ready_to_ship',
        string $dateCreated = '2026-11-20T10:00:00-06:00',
    ): MeliOrder {
        $order = MeliOrder::create([
            'meli_account_id' => $accountId,
            'order_id' => $remoteOrderId,
            'status' => $status,
            'shipping_status' => $shippingStatus,
            'raw' => ['date_created' => $dateCreated],
        ]);

        foreach ($lines as $line) {
            MeliOrderItem::create([
                'meli_order_id' => $order->id,
                'item_id' => $line['item_id'],
                'variation_id' => $line['variation'] ?? null,
                'remote_line_key' => $line['key'],
                'quantity' => $line['qty'],
            ]);
        }

        return $order->load('items');
    }
}
