<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StaleReconciliationLeaseException;
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
use App\Services\InventoryMeliAffectedStockDispatcher;
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
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        InventoryMeliOrderReconciliationCoordinator::resetTestingHooks();
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

        // Scenario A: Workers STOPPED (Supervisor FATAL) across all 48 sync cycles with real transitions:
        //   - Orders 0..4 (5 orders): cancelled at cycle 24
        //   - Orders 5..9 (5 orders): quantity increased 1 -> 2 at cycle 24
        //   - Orders 10..49 (40 orders): remain paid qty=1
        for ($cycle = 1; $cycle <= $syncCycles; $cycle++) {
            foreach ($orders as $idx => $ord) {
                if ($cycle === 24 && $idx < 5) {
                    $ord->update(['status' => 'cancelled']);
                } elseif ($cycle === 24 && $idx >= 5 && $idx < 10) {
                    $ord->items()->update(['quantity' => 2]);
                }
                $dispatcher->dispatchAfterCommit($ord->fresh('items'));
            }
        }
        $afterR3WorkersStoppedJobs = (int) DB::table('jobs')->count();
        $this->assertSame($orderCount, $afterR3WorkersStoppedJobs);

        // Drain the 50 queued jobs after the outage and verify 0 lost updates across ALL 50 orders!
        $service = app(InventoryMeliOrderReservationService::class);
        $stoppedPendingRows = DB::table('jobs')->get();
        DB::table('jobs')->truncate();
        foreach ($stoppedPendingRows as $jobRow) {
            $inspected = app(QueuePayloadInspector::class)->inspect((string) $jobRow->payload, [QueuePayloadInspector::JOB_RESERVATIONS]);
            (new ReconcileInventoryMeliOrderReservationsJob((int) $inspected['entity_id']))->handle($service);
        }
        // 5 cancelled (0 reserved) + 5 qty=2 (10 reserved) + 40 qty=1 (40 reserved) = 50 reserved across 45 active orders!
        $this->assertSame(45, InventoryChannelOrderAllocation::where('status', 'ACTIVE')->count());
        $this->assertSame(50, (int) InventoryReservation::active()->sum('quantity'));
        $this->assertSame(0, InventoryMeliOrderReconciliation::where('state', '!=', InventoryMeliOrderReconciliation::STATE_IDLE)->count());

        // Scenario B: Reset queue & reconciliation state; simulate ACTIVE workers draining after each cycle
        // with 5 real transitions (paid -> shipped) + 5 cancellations mid-way across 48 sync cycles!
        DB::table('jobs')->truncate();
        InventoryMeliOrderReconciliation::query()->truncate();
        InventoryChannelOrderAllocation::query()->truncate();
        InventoryReservation::query()->truncate();
        foreach ($orders as $ord) {
            $ord->update(['status' => 'paid', 'shipping_status' => 'ready_to_ship']);
            $ord->items()->update(['quantity' => 1]);
        }

        $totalDispatchedActiveWorkers = 0;

        for ($cycle = 1; $cycle <= $syncCycles; $cycle++) {
            foreach ($orders as $idx => $ord) {
                if ($cycle === 24 && $idx < 5) {
                    $ord->update(['shipping_status' => 'shipped']);
                } elseif ($cycle === 36 && $idx >= 5 && $idx < 10) {
                    $ord->update(['status' => 'cancelled']);
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

        // Exactly 50 initial + 5 shipped + 5 cancelled = 60 jobs instead of 2,400, AND 0 lost updates!
        $this->assertSame($orderCount + 10, $totalDispatchedActiveWorkers);
        $this->assertSame(5, InventoryChannelOrderAllocation::where('status', 'FULFILLED')->count());
        $this->assertSame(5, InventoryChannelOrderAllocation::where('status', 'RELEASED')->count());
        $this->assertSame(40, InventoryChannelOrderAllocation::where('status', 'ACTIVE')->count());
        $this->assertSame(40, (int) InventoryReservation::active()->sum('quantity'));
        $this->assertSame(495, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(455, app(InventoryStockService::class)->availableStock($product));
        $this->assertSame(0, InventoryMeliOrderReconciliation::where('state', '!=', InventoryMeliOrderReconciliation::STATE_IDLE)->count());

        $metrics = [
            'orders' => $orderCount,
            'sync_cycles' => $syncCycles,
            'baseline_pre_r3_jobs' => $totalSyncCalls,
            'after_r3_workers_stopped_jobs' => $afterR3WorkersStoppedJobs,
            'after_r3_workers_stopped_reduction_pct' => round((1 - ($afterR3WorkersStoppedJobs / $totalSyncCalls)) * 100, 2),
            'after_r3_workers_stopped_lost_updates' => 0,
            'after_r3_workers_active_with_10_transitions_jobs' => $totalDispatchedActiveWorkers,
            'after_r3_workers_active_reduction_pct' => round((1 - ($totalDispatchedActiveWorkers / $totalSyncCalls)) * 100, 2),
            'after_r3_workers_active_lost_updates' => 0,
        ];

        if (getenv('R3_PRINT_METRICS') === '1') {
            fwrite(STDERR, "\nR3_SYNTHETIC_METRICS=".json_encode($metrics, JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    // =========================================================================
    // R3.1 Hardening Tests (H1 - H5 & R2.1 Compatibility)
    // =========================================================================

    public function test_r31_15_h1_same_db_atomic_enqueue_commits_and_rolls_back_with_outer_transaction(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-15', 10);
        $this->link($product, 1, 'MLM-R31-15', null, true);

        $order = $this->order(1, 931001, 'paid', [['item_id' => 'MLM-R31-15', 'key' => 'MLM-R31-15:', 'qty' => 2]]);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Outer transaction rollback must roll back BOTH inventory_meli_order_reconciliations AND jobs!
        try {
            DB::transaction(function () use ($dispatcher, $order): void {
                $dispatcher->dispatchAfterCommit($order);
                // Inside TX, both state and job row exist atomically on the same connection.
                $this->assertSame(1, DB::table('jobs')->count());
                $this->assertSame(1, InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->count());
                throw new RuntimeException('Simulated outer transaction failure');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // Expected rollback
        }

        $this->assertSame(0, DB::table('jobs')->count(), 'Outer rollback must leave 0 orphan jobs.');
        $this->assertSame(0, InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->count(), 'Outer rollback must leave 0 orphan PENDING state.');

        // Now commit cleanly -> state PENDING + pending_job_id + dispatch_confirmed_at + jobs row all persisted atomically!
        DB::transaction(function () use ($dispatcher, $order): void {
            $dispatcher->dispatchAfterCommit($order);
        });

        $jobRow = DB::table('jobs')->first();
        $this->assertNotNull($jobRow);
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);
        $this->assertSame((int) $jobRow->id, (int) $state->pending_job_id);
        $this->assertNotNull($state->dispatch_confirmed_at);
    }

    public function test_r31_16_h1_simulated_sigkill_between_commit_and_outbox_enqueue_is_deterministically_recovered(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-16', 10);
        $this->link($product, 1, 'MLM-R31-16', null, true);

        $order = $this->order(1, 931002, 'paid', [['item_id' => 'MLM-R31-16', 'key' => 'MLM-R31-16:', 'qty' => 2]]);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Simulate non-same-DB outbox mode + process SIGKILL right after COMMIT (before enqueue!).
        InventoryMeliOrderReconciliationCoordinator::setAllowSameDbAtomicPush(false);
        $decision = $coordinator->registerOrderState($order);
        $this->assertTrue($decision['should_dispatch_after_commit']);
        $this->assertFalse($decision['enqueued_atomically']);

        // Process "died" before afterCommit ran: row is PENDING with dispatch_confirmed_at = NULL and 0 rows in jobs!
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);
        $this->assertNull($state->dispatch_confirmed_at);
        $this->assertSame(0, DB::table('jobs')->count());

        // Advance past outbox grace window -> bounded outbox sweeper deterministically recovers it!
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $recovery = $coordinator->recoverUnconfirmedOutboxDispatches(limit: 10);
        $this->assertSame(1, $recovery['scanned']);
        $this->assertSame(1, $recovery['recovered']);
        $this->assertSame(1, DB::table('jobs')->count());

        $state->refresh();
        $this->assertNotNull($state->dispatch_confirmed_at);

        // Second run of sweeper is idempotent (0 duplicate jobs).
        $recovery2 = $coordinator->recoverUnconfirmedOutboxDispatches(limit: 10);
        $this->assertSame(0, $recovery2['scanned']);
        $this->assertSame(1, DB::table('jobs')->count());

        // Also verify O(1) self-healing when R2.1 prunes a PENDING row's `jobs` record:
        InventoryMeliOrderReconciliationCoordinator::setAllowSameDbAtomicPush(true);
        $order2 = $this->order(1, 931003, 'paid', [['item_id' => 'MLM-R31-16', 'key' => 'MLM-R31-16:', 'qty' => 1]]);
        $dispatcher->dispatchAfterCommit($order2);
        $state2 = InventoryMeliOrderReconciliation::where('meli_order_id', $order2->id)->firstOrFail();
        $prunedJobId = (int) $state2->pending_job_id;
        $this->assertGreaterThan(0, $prunedJobId);

        // Simulate R2.1 pruning that job row out of `jobs`:
        DB::table('jobs')->where('id', $prunedJobId)->delete();
        // Next sync detects missing PK in O(1) and re-enqueues cleanly!
        $dispatcher->dispatchAfterCommit($order2->fresh('items'));
        $state2->refresh();
        $this->assertNotNull($state2->pending_job_id);
        $this->assertNotSame($prunedJobId, (int) $state2->pending_job_id);
        $this->assertTrue(DB::table('jobs')->where('id', $state2->pending_job_id)->exists());
    }

    public function test_r31_17_h1_complete_execution_followup_is_atomic_on_same_db_and_recoverable_on_outbox_crash(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-17', 10);
        $this->link($product, 1, 'MLM-R31-17', null, true);

        $order = $this->order(1, 931004, 'paid', [['item_id' => 'MLM-R31-17', 'key' => 'MLM-R31-17:', 'qty' => 2]]);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);

        // Case A: Same-DB atomic follow-up inside completeExecution().
        $claim = $coordinator->claimForExecution($order->id, 'worker-atomic');
        $this->assertTrue($claim['claimed']);
        $paidFp = $claim['fingerprint'];

        // Order changes mid-flight!
        $order->update(['status' => 'cancelled']);
        $coordinator->registerOrderState($order->fresh('items'));
        $this->assertSame(0, DB::table('jobs')->count());

        $completion = $coordinator->completeExecution(
            $order->id,
            'worker-atomic',
            $claim['fencing_token'],
            $claim['starting_revision'],
            $paidFp,
        );
        $this->assertTrue($completion['needs_followup']);
        $this->assertTrue($completion['enqueued_atomically']);
        $this->assertFalse($completion['needs_after_commit_followup']);
        $this->assertSame(1, DB::table('jobs')->count());
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame((int) DB::table('jobs')->value('id'), (int) $state->pending_job_id);
        $this->assertNotNull($state->dispatch_confirmed_at);

        // Case B: Outbox mode simulated SIGKILL after completeExecution() commits -> sweeper recovers!
        DB::table('jobs')->truncate();
        InventoryMeliOrderReconciliationCoordinator::setAllowSameDbAtomicPush(false);
        $order2 = $this->order(1, 931005, 'paid', [['item_id' => 'MLM-R31-17', 'key' => 'MLM-R31-17:', 'qty' => 1]]);
        $claim2 = $coordinator->claimForExecution($order2->id, 'worker-outbox');
        $order2->update(['status' => 'cancelled']);
        $coordinator->registerOrderState($order2->fresh('items'));

        $completion2 = $coordinator->completeExecution(
            $order2->id,
            'worker-outbox',
            $claim2['fencing_token'],
            $claim2['starting_revision'],
            $claim2['fingerprint'],
        );
        $this->assertTrue($completion2['needs_after_commit_followup']);
        $this->assertSame(0, DB::table('jobs')->count());

        // Simulate worker dying before afterCommit dispatched -> sweeper recovers cleanly!
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        $rec = $coordinator->recoverUnconfirmedOutboxDispatches(limit: 10);
        $this->assertSame(1, $rec['recovered']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_r31_18_h2_fencing_token_blocks_stale_owner_from_mutating_inventory_and_stock_sync_while_active_owner_renews(): void
    {
        Bus::fake([SyncInventoryMeliStockLinkJob::class]);

        [$product] = $this->stockedProduct('SKU-R31-18', 10);
        $this->link($product, 1, 'MLM-R31-18', null, true, true, true);

        $order = $this->order(1, 931006, 'paid', [['item_id' => 'MLM-R31-18', 'key' => 'MLM-R31-18:', 'qty' => 3]]);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $service = app(InventoryMeliOrderReservationService::class);

        // Worker A claims lease (token = 1).
        $claimA = $coordinator->claimForExecution($order->id, 'worker-A', leaseSeconds: 300);
        $this->assertTrue($claimA['claimed']);
        $this->assertSame(1, $claimA['fencing_token']);

        // Verify lease renewal advances lease_expires_at while active!
        $initialExpiry = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->value('lease_expires_at');
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        $service->apply($order, ['lease_owner' => 'worker-A', 'fencing_token' => 1]);
        $renewedExpiry = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->value('lease_expires_at');
        $this->assertTrue(Carbon::parse($renewedExpiry)->gt(Carbon::parse($initialExpiry)));
        $this->assertSame(3, (int) InventoryReservation::active()->sum('quantity'));

        // Now Worker A hangs beyond lease expiry (+305s) while still alive!
        Carbon::setTestNow(Carbon::now()->addSeconds(305));

        // Order ships and Worker B claims lease (token = 2)!
        $order->update(['shipping_status' => 'shipped']);
        $claimB = $coordinator->claimForExecution($order->id, 'worker-B', leaseSeconds: 300);
        $this->assertTrue($claimB['claimed']);
        $this->assertSame(2, $claimB['fencing_token']);

        // Stale Worker A tries to call apply() with old token=1 -> MUST throw StaleReconciliationLeaseException and mutate NOTHING!
        try {
            $service->apply($order->fresh('items'), ['lease_owner' => 'worker-A', 'fencing_token' => 1]);
            $this->fail('Expected StaleReconciliationLeaseException for stale owner.');
        } catch (StaleReconciliationLeaseException $e) {
            $this->assertSame('worker-A', $e->attemptedOwner);
            $this->assertSame(1, $e->attemptedFencingToken);
        }

        // Stale Worker A tries to mark stock sync -> MUST throw StaleReconciliationLeaseException!
        try {
            $coordinator->markStockSyncRequiredAndRenewLease($order->id, 'worker-A', 1);
            $this->fail('Expected StaleReconciliationLeaseException before stock sync dispatch.');
        } catch (StaleReconciliationLeaseException) {
            // Expected
        }

        // Inventory is STILL untouched by Worker A (physical stock still 10, active reservation still 3)!
        $this->assertSame(10, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(3, (int) InventoryReservation::active()->sum('quantity'));

        // Worker B (token = 2) legitimately fulfills the order!
        $service->apply($order->fresh('items'), ['lease_owner' => 'worker-B', 'fencing_token' => 2]);
        $coordinator->markStockSyncRequiredAndRenewLease($order->id, 'worker-B', 2);
        $coordinator->completeExecution($order->id, 'worker-B', 2, $claimB['starting_revision'], $coordinator->fingerprint($order->fresh('items')));

        $this->assertSame(7, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(0, InventoryReservation::active()->count());

        // End-to-end job test: Worker A running handle() loses lease mid-flight -> catches StaleReconciliationLeaseException,
        // does NOT mark Worker B's state FAILED, and dispatches 0 stock syncs!
        $order2 = $this->order(1, 931007, 'paid', [['item_id' => 'MLM-R31-18', 'key' => 'MLM-R31-18:', 'qty' => 2]]);
        $hijackingService = Mockery::mock(InventoryMeliOrderReservationService::class);
        $hijackingService->shouldReceive('apply')->once()->andReturnUsing(function (MeliOrder $o) use ($coordinator, $service): array {
            // Simulate lease expiry + Worker B hijacking before Worker A executes applyLine!
            Carbon::setTestNow(Carbon::now()->addSeconds(305));
            $claimB2 = $coordinator->claimForExecution($o->id, 'worker-B2', leaseSeconds: 300);
            $this->assertTrue($claimB2['claimed']);

            return $service->apply($o); // Uses Worker A's scoped activeLeaseContext -> throws StaleReconciliationLeaseException!
        });

        Log::spy();
        (new ReconcileInventoryMeliOrderReservationsJob($order2->id))->handle($hijackingService);

        $state2 = InventoryMeliOrderReconciliation::where('meli_order_id', $order2->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PROCESSING, $state2->state);
        $this->assertSame('worker-B2', $state2->lease_owner);
        $this->assertSame(2, (int) $state2->fencing_token);
        $this->assertSame(0, InventoryReservation::active()->count());
        Bus::assertNotDispatched(SyncInventoryMeliStockLinkJob::class);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_r31_19_h3_stale_failed_callback_and_early_skips_never_corrupt_active_worker_or_newer_revision(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-19', 10);
        $this->link($product, 1, 'MLM-R31-19', null, true);

        $order = $this->order(1, 931008, 'paid', [['item_id' => 'MLM-R31-19', 'key' => 'MLM-R31-19:', 'qty' => 1]]);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);

        // Worker B holds active PROCESSING lease (token=2).
        $claimA = $coordinator->claimForExecution($order->id, 'worker-A', targetRevision: 1, leaseSeconds: 300);
        Carbon::setTestNow(Carbon::now()->addSeconds(305));
        $claimB = $coordinator->claimForExecution($order->id, 'worker-B', targetRevision: 2, leaseSeconds: 300);
        $this->assertSame(2, $claimB['fencing_token']);

        // 1. Stale Worker A invokes markFailedForOwner() -> MUST be rejected (false), Worker B untouched!
        $this->assertFalse($coordinator->markFailedForOwner($order->id, 'worker-A', $claimA['fencing_token'], 'stale failure'));

        // 2. Deserialized stale job (activeLeaseOwner = null, targetRevision = 1) invokes failed() -> Worker B untouched!
        $staleJob = new ReconcileInventoryMeliOrderReservationsJob($order->id, 1);
        $staleJob->failed(new RuntimeException('stale timeout callback with Bearer SECRET_ABC'));

        // 3. Early skip (e.g. automation toggled false momentarily) invokes releasePendingIfUnowned -> Worker B untouched!
        config()->set('inventory.meli_order_reservations.automatic', false);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id, 1))->handle(app(InventoryMeliOrderReservationService::class));
        config()->set('inventory.meli_order_reservations.automatic', true);

        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PROCESSING, $state->state);
        $this->assertSame('worker-B', $state->lease_owner);
        $this->assertSame(2, (int) $state->fencing_token);
        $this->assertNull($state->last_error);

        // 4. Stale dispatcher enqueue failure for older revision 1 NEVER marks revision 2 FAILED;
        //    real failure redacts Bearer / tokens / secrets in last_error!
        $coordinator->completeExecution($order->id, 'worker-B', 2, $claimB['starting_revision'], $claimB['fingerprint']);
        // Already IDLE: stale failed() MUST NOT overwrite IDLE!
        $staleJob->failed(new RuntimeException('late callback after IDLE'));
        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, $state->state);

        // Outbox mode revision 3 fails enqueue; revision 4 supersedes it -> revision 3 catch is NO-OP!
        InventoryMeliOrderReconciliationCoordinator::setAllowSameDbAtomicPush(false);
        $order->update(['status' => 'cancelled']);
        $dec3 = $coordinator->registerOrderState($order->fresh('items'));
        $order->update(['status' => 'paid']);
        $dec4 = $coordinator->registerOrderState($order->fresh('items'));
        $this->assertGreaterThan($dec3['revision'], $dec4['revision']);

        $this->assertFalse($coordinator->markEnqueueFailedForRevision($order->id, $dec3['revision'], 'old error'));
        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);

        // Real enqueue failure for latest revision redacts all secrets!
        $order3 = $this->order(1, 931009, 'paid', [['item_id' => 'MLM-R31-19', 'key' => 'MLM-R31-19:', 'qty' => 1]]);
        $decReal = $coordinator->registerOrderState($order3);
        $this->assertTrue($coordinator->markEnqueueFailedForRevision(
            $order3->id,
            $decReal['revision'],
            'HTTP 500 Authorization: Bearer TOP_SECRET_JWT_123 access_token=ACC_999 refresh_token=REF_888 password=PASS_777',
        ));
        $state3 = InventoryMeliOrderReconciliation::where('meli_order_id', $order3->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_FAILED, $state3->state);
        $this->assertStringNotContainsString('TOP_SECRET_JWT_123', (string) $state3->last_error);
        $this->assertStringNotContainsString('ACC_999', (string) $state3->last_error);
        $this->assertStringNotContainsString('REF_888', (string) $state3->last_error);
        $this->assertStringNotContainsString('PASS_777', (string) $state3->last_error);
        $this->assertStringContainsString('[redacted]', (string) $state3->last_error);
    }

    public function test_r31_20_h4_failed_job_with_pending_retry_in_jobs_coalesces_without_duplicate_while_terminal_failure_reenqueues(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-20', 10);
        $this->link($product, 1, 'MLM-R31-20', null, true);

        $order = $this->order(1, 931010, 'paid', [['item_id' => 'MLM-R31-20', 'key' => 'MLM-R31-20:', 'qty' => 2]]);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $dispatcher->dispatchAfterCommit($order);
        $retryJobId = (int) DB::table('jobs')->value('id');
        $this->assertGreaterThan(0, $retryJobId);

        // Simulate attempt 1 of 5 failing while the job stays in `jobs` for backoff retry!
        $fakeQueueJob = Mockery::mock(QueueJobContract::class);
        $fakeQueueJob->shouldReceive('attempts')->andReturn(1);
        $fakeQueueJob->shouldReceive('getJobId')->andReturn($retryJobId);

        $failingService = Mockery::mock(InventoryMeliOrderReservationService::class);
        $failingService->shouldReceive('apply')->once()->andThrow(new RuntimeException('Transient failure Bearer SECRET_RETRY'));

        $job = new ReconcileInventoryMeliOrderReservationsJob($order->id, 1);
        $job->setJob($fakeQueueJob);

        try {
            $job->handle($failingService);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // Expected
        }

        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_FAILED, $state->state);
        $this->assertSame($retryJobId, (int) $state->pending_job_id);
        $this->assertStringNotContainsString('SECRET_RETRY', (string) $state->last_error);

        // A new sync arrives while retry job #1 is STILL waiting in `jobs` -> coalesces with 0 duplicate jobs!
        $order->items()->update(['quantity' => 3]);
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(1, DB::table('jobs')->count(), 'Must coalesce into existing backoff retry job.');
        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);
        $this->assertSame($retryJobId, (int) $state->pending_job_id);

        // Now simulate terminal failure (job removed from `jobs` after attempt 5) -> next sync enqueues 1 fresh job!
        DB::table('jobs')->where('id', $retryJobId)->delete();
        $state->update(['state' => InventoryMeliOrderReconciliation::STATE_FAILED, 'pending_job_id' => null]);
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(1, DB::table('jobs')->count());
        $newJobId = (int) DB::table('jobs')->value('id');
        $this->assertNotSame($retryJobId, $newJobId);
    }

    public function test_r31_21_h4_historical_and_superseded_queued_replays_are_skipped_while_stock_sync_retry_still_converges(): void
    {
        Bus::fake([SyncInventoryMeliStockLinkJob::class]);

        [$product] = $this->stockedProduct('SKU-R31-21', 10);
        $this->link($product, 1, 'MLM-R31-21', null, true, true, true);

        $order = $this->order(1, 931011, 'paid', [['item_id' => 'MLM-R31-21', 'key' => 'MLM-R31-21:', 'qty' => 2]]);
        $realService = app(InventoryMeliOrderReservationService::class);

        // Part A: Stock-sync failure after reservation committed -> stock_sync_required stays true -> retry converges!
        $failingStockDispatcher = Mockery::mock(InventoryMeliAffectedStockDispatcher::class);
        $failingStockDispatcher->shouldReceive('dispatchForOrder')->once()->andThrow(new RuntimeException('Stock queue down'));
        $this->app->instance(InventoryMeliAffectedStockDispatcher::class, $failingStockDispatcher);

        try {
            (new ReconcileInventoryMeliOrderReservationsJob($order->id, 1))->handle($realService);
            $this->fail('Expected stock dispatch failure');
        } catch (RuntimeException) {
            // Expected
        }

        // Reservation IS active, state is FAILED, and stock_sync_required IS true!
        $this->assertSame(1, InventoryReservation::active()->count());
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_FAILED, $state->state);
        $this->assertTrue((bool) $state->stock_sync_required);

        // Restore real stock dispatcher -> retry runs even on NO_CHANGE, dispatches stock sync, clears stock_sync_required!
        $this->app->forgetInstance(InventoryMeliAffectedStockDispatcher::class);
        $retryQueueJob = Mockery::mock(QueueJobContract::class);
        $retryQueueJob->shouldReceive('attempts')->andReturn(2);
        $retryQueueJob->shouldReceive('getJobId')->andReturn(99);
        $retryJob = new ReconcileInventoryMeliOrderReservationsJob($order->id, 1);
        $retryJob->setJob($retryQueueJob);
        $retryJob->handle($realService);

        Bus::assertDispatchedTimes(SyncInventoryMeliStockLinkJob::class, 1);
        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, $state->state);
        $this->assertFalse((bool) $state->stock_sync_required);
        $this->assertSame(1, (int) $state->stock_sync_completed_revision);

        // Part B: Now order is IDLE & stock synced. Historical pre-R3 queued replay (targetRevision=null, queued)
        // AND superseded R3.1 queued replay (targetRevision=1) MUST be skipped with 0 apply() calls and 0 stock syncs!
        $spyService = Mockery::mock(InventoryMeliOrderReservationService::class);
        $spyService->shouldNotReceive('apply');

        $legacyQueuedJob = new ReconcileInventoryMeliOrderReservationsJob($order->id, null);
        $legacyQueuedJob->setJob($retryQueueJob);
        $legacyQueuedJob->handle($spyService);

        $supersededQueuedJob = new ReconcileInventoryMeliOrderReservationsJob($order->id, 1);
        $supersededQueuedJob->setJob($retryQueueJob);
        $supersededQueuedJob->handle($spyService);

        Bus::assertDispatchedTimes(SyncInventoryMeliStockLinkJob::class, 1);
    }

    public function test_r31_22_h4_out_of_order_stale_snapshot_never_regresses_fingerprint_and_force_reconcile_handles_link_changes(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-22', 10);
        $link = $this->link($product, 1, 'MLM-R31-22', null, reservationEnabled: false);

        // Start with paid order while link has reservation disabled -> reconciles to IDLE (SKIPPED).
        $order = $this->order(1, 931012, 'paid', [['item_id' => 'MLM-R31-22', 'key' => 'MLM-R31-22:', 'qty' => 2]]);
        $stalePaidInMemory = clone $order;
        $stalePaidInMemory->setRelation('items', $order->items);

        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $service = app(InventoryMeliOrderReservationService::class);

        $dispatcher->dispatchAfterCommit($order);
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id, 1))->handle($service);
        $this->assertSame(0, InventoryReservation::active()->count());

        // Part A: Admin enables order_reservation_enabled on the channel link (order row unchanged!).
        // Regular sync is a no-op, while explicit dispatchForceReconcile() enqueues 1 job and reserves!
        $link->update(['order_reservation_enabled' => true]);
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(0, DB::table('jobs')->count());

        $dispatcher->dispatchForceReconcile($order->fresh('items'));
        $this->assertSame(1, DB::table('jobs')->count());
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
        $this->assertSame(1, InventoryReservation::active()->count());

        // Part B: DB updates order to 'cancelled', then a stale worker passes its OLD in-memory 'paid' model!
        $order->update(['status' => 'cancelled']);
        $cancelledFingerprint = $coordinator->fingerprint($order->fresh('items'));

        // Pass stale 'paid' snapshot -> coordinator locks meli_orders, reloads DB 'cancelled', and computes cancelled fingerprint!
        $decision = $coordinator->registerOrderState($stalePaidInMemory);
        $this->assertSame($cancelledFingerprint, $decision['fingerprint']);

        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame($cancelledFingerprint, $state->last_state_fingerprint);
    }

    public function test_r31_23_h5_missing_reconciliation_table_fails_closed_for_both_dispatcher_and_job(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-23', 10);
        $this->link($product, 1, 'MLM-R31-23', null, true);
        $order = $this->order(1, 931013, 'paid', [['item_id' => 'MLM-R31-23', 'key' => 'MLM-R31-23:', 'qty' => 2]]);

        Schema::dropIfExists('inventory_meli_order_reconciliations');

        Log::spy();

        // 1. Dispatcher MUST fail-closed: 0 jobs dispatched + Log::critical!
        app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($order);
        $this->assertSame(0, DB::table('jobs')->count());

        // 2. Job handle() MUST fail-closed: 0 apply() calls + Log::critical!
        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->shouldNotReceive('apply');
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);

        Log::shouldHaveReceived('critical')
            ->twice()
            ->with(
                'Inventory Meli order reservation blocked: durable reconciliation table missing',
                Mockery::on(static fn (array $ctx): bool => ($ctx['diagnostic_code'] ?? null) === 'missing_reconciliation_state_table'),
            );
    }

    public function test_r31_24_pre_r3_serialized_job_deserializes_cleanly_and_r31_payload_passes_r21_inspector(): void
    {
        [$product] = $this->stockedProduct('SKU-R31-24', 10);
        $this->link($product, 1, 'MLM-R31-24', null, true);
        $order = $this->order(1, 931014, 'paid', [['item_id' => 'MLM-R31-24', 'key' => 'MLM-R31-24:', 'qty' => 2]]);

        // 1. Genuine pre-R3 serialized job payload (no targetRevision, no timeout, no activeLeaseOwner)!
        $className = ReconcileInventoryMeliOrderReservationsJob::class;
        $preR3Serialized = sprintf(
            'O:%d:"%s":2:{s:11:"meliOrderId";i:%d;s:5:"queue";s:4:"meli";}',
            strlen($className),
            $className,
            $order->id,
        );

        /** @var ReconcileInventoryMeliOrderReservationsJob $legacyJob */
        $legacyJob = unserialize($preR3Serialized);
        $this->assertInstanceOf(ReconcileInventoryMeliOrderReservationsJob::class, $legacyJob);
        $this->assertSame($order->id, $legacyJob->meliOrderId);
        $this->assertNull($legacyJob->targetRevision);

        $legacyJob->handle(app(InventoryMeliOrderReservationService::class));
        $this->assertSame(1, InventoryReservation::active()->count());
        $this->assertSame(2, (int) InventoryReservation::active()->sum('quantity'));

        // 2. Real R3.1 job pushed into `jobs` with targetRevision = 7 passes R2.1 QueuePayloadInspector cleanly!
        $order2 = $this->order(1, 931015, 'paid', [['item_id' => 'MLM-R31-24', 'key' => 'MLM-R31-24:', 'qty' => 1]]);
        app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($order2);
        $payload = (string) DB::table('jobs')->value('payload');

        $inspected = app(QueuePayloadInspector::class)->inspect($payload, [QueuePayloadInspector::JOB_RESERVATIONS]);
        $this->assertSame(QueuePayloadInspector::CLASSIFICATION_CANDIDATE, $inspected['classification']);
        $this->assertSame(QueuePayloadInspector::JOB_RESERVATIONS, $inspected['job_class']);
        $this->assertSame('meli_order', $inspected['entity_type']);
        $this->assertSame($order2->id, $inspected['entity_id']);
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
