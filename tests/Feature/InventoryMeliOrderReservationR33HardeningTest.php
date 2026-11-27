<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryLocation;
use App\Models\InventoryMeliOrderReconciliation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use App\Services\InventoryMeliOrderReconciliationCoordinator;
use App\Services\InventoryMeliOrderReconciliationPreflight;
use App\Services\InventoryMeliOrderReservationDispatcher;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\MeliOrderRemoteFreshnessGuard;
use App\Services\MeliOrderStoreService;
use App\Services\MeliOrderSyncService;
use App\Services\MeliSharedStockOrderService;
use App\Services\MercadoLibre\Orders\MeliOrderCancellationService;
use App\Services\StockService;
use App\Services\SyscomOrderFromMeliService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * R3.3 targeted review fixes for PR #16:
 * - H13: Starvation-free recoverStuckReconciliations(), operational guards, and retry ceiling enforcement.
 * - H14: Partial/invalid payload protection, empty items contract, partial cancellation timestamp preservation,
 *        account/order/shipment identity validation, authorized shipping_id changes, and strict date parsing.
 * - H15: Exhaustive 13-table transactional preflight (schema drift, critical indexes, strict_atomic_queue).
 */
class InventoryMeliOrderReservationR33HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-26 14:00:00', 'UTC'));

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('queue.connections.database.after_commit', false);
        config()->set('queue.connections.database.retry_after', 7300);
        config()->set('inventory.meli_order_reservations.automatic', true);
        config()->set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        config()->set('inventory.meli_order_reservations.lease_seconds', 300);
        config()->set('inventory.meli_order_reservations.job_timeout_seconds', 120);
        config()->set('inventory.meli_order_reservations.max_auto_recovery_attempts', 8);
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', true);

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
    // H13.1 — >100 healthy lower-ID PENDING jobs NEVER starve higher-ID stuck rows
    // =========================================================================
    public function test_h13_01_over_100_healthy_lower_id_pending_jobs_do_not_starve_higher_id_stuck_orders(): void
    {
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);

        // Seed 115 HEALTHY lower-ID PENDING orders (each has a real live row in `jobs`!).
        for ($i = 1; $i <= 115; $i++) {
            $ord = $this->order(1, 933000 + $i, 'paid', [
                ['item_id' => 'MLM-H13-OK', 'key' => 'MLM-H13-OK:', 'qty' => 1],
            ]);
            $dispatcher->dispatchAfterCommit($ord);
        }
        $this->assertSame(115, DB::table('jobs')->count());

        // Seed 3 genuinely STUCK higher-ID orders AFTER the 115 healthy orders:
        // (A) Expired PROCESSING lease
        $expiredProc = $this->order(1, 933501, 'paid', [['item_id' => 'MLM-H13-STUCK', 'key' => 'MLM-H13-STUCK:', 'qty' => 1]]);
        $coordinator->claimForExecution($expiredProc->id, 'dead-worker', leaseSeconds: 300);

        // (B) Orphaned PENDING whose pending_job_id vanished from `jobs`
        $orphanPending = $this->order(1, 933502, 'paid', [['item_id' => 'MLM-H13-STUCK', 'key' => 'MLM-H13-STUCK:', 'qty' => 1]]);
        $dispatcher->dispatchAfterCommit($orphanPending);
        $vanishedJobId = (int) InventoryMeliOrderReconciliation::where('meli_order_id', $orphanPending->id)->value('pending_job_id');
        DB::table('jobs')->where('id', $vanishedJobId)->delete();

        // (C) Recoverable FAILED within attempt budget (no job in `jobs`)
        $recoverableFailed = $this->order(1, 933503, 'paid', [['item_id' => 'MLM-H13-STUCK', 'key' => 'MLM-H13-STUCK:', 'qty' => 1]]);
        InventoryMeliOrderReconciliation::create([
            'meli_order_id' => $recoverableFailed->id,
            'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
            'requested_revision' => 1,
            'processed_revision' => 0,
            'pending_job_id' => null,
            'attempts' => 2,
            'stock_sync_required' => true,
        ]);

        // Advance time past lease expiry (305s).
        Carbon::setTestNow(Carbon::now()->addSeconds(305));

        // Run a SINGLE bounded recovery pass (limit=10 << 115 healthy rows)!
        // Must reach ALL 3 higher-ID stuck rows immediately with ZERO starvation and ZERO duplicate healthy jobs!
        $res = $coordinator->recoverStuckReconciliations(limit: 10);

        $this->assertNull($res['blocked_reason']);
        $this->assertSame(3, $res['scanned']);
        $this->assertSame(1, $res['recovered_expired_processing']);
        $this->assertSame(1, $res['recovered_pending']);
        $this->assertSame(1, $res['recovered_failed']);
        $this->assertSame(0, $res['skipped']);
        $this->assertFalse($res['has_more']);
        $this->assertSame(118, DB::table('jobs')->count(), '115 healthy + 3 recovered = exactly 118 jobs.');

        // Also prove cursor pagination works when stuck rows exceed limit (e.g. limit=1 over 2 new stuck rows)!
        $stuck4 = $this->order(1, 933504, 'paid', [['item_id' => 'MLM-H13-STUCK', 'key' => 'MLM-H13-STUCK:', 'qty' => 1]]);
        $stuck5 = $this->order(1, 933505, 'paid', [['item_id' => 'MLM-H13-STUCK', 'key' => 'MLM-H13-STUCK:', 'qty' => 1]]);
        $coordinator->claimForExecution($stuck4->id, 'dead-4', leaseSeconds: 300);
        $coordinator->claimForExecution($stuck5->id, 'dead-5', leaseSeconds: 300);
        Carbon::setTestNow(Carbon::now()->addSeconds(305));

        $page1 = $coordinator->recoverStuckReconciliations(limit: 1);
        $this->assertSame(1, $page1['recovered_expired_processing']);
        $this->assertTrue($page1['has_more']);
        $this->assertNotNull($page1['next_after_id']);

        $page2 = $coordinator->recoverStuckReconciliations(limit: 1, afterId: $page1['next_after_id']);
        $this->assertSame(1, $page2['recovered_expired_processing']);
        $this->assertFalse($page2['has_more']);
    }

    // =========================================================================
    // H13.2 — Operational guards (automatic=false, cutover, strict_atomic_queue)
    //         AND ordinary syncs CANNOT bypass the FAILED retry ceiling
    // =========================================================================
    public function test_h13_02_operational_guards_and_failed_retry_ceiling_cannot_be_bypassed_by_ordinary_syncs(): void
    {
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // 1. Expired PROCESSING + automatic=false -> blocked fail-closed, 0 jobs!
        $ord = $this->order(1, 933601, 'paid', [['item_id' => 'MLM-H13-G', 'key' => 'MLM-H13-G:', 'qty' => 1]]);
        $coordinator->claimForExecution($ord->id, 'dead-g', leaseSeconds: 300);
        Carbon::setTestNow(Carbon::now()->addSeconds(305));

        config()->set('inventory.meli_order_reservations.automatic', false);
        $blocked = $coordinator->recoverStuckReconciliations(limit: 10);
        $this->assertSame('automation_disabled', $blocked['blocked_reason']);
        $this->assertSame(0, DB::table('jobs')->count());
        config()->set('inventory.meli_order_reservations.automatic', true);

        // 2. Pre-cutover stuck order -> released to IDLE, 0 jobs!
        $preCutover = $this->order(1, 933602, 'paid', [['item_id' => 'MLM-H13-G', 'key' => 'MLM-H13-G:', 'qty' => 1]], 'ready_to_ship', '2026-09-15T10:00:00-06:00');
        InventoryMeliOrderReconciliation::create([
            'meli_order_id' => $preCutover->id,
            'state' => InventoryMeliOrderReconciliation::STATE_PROCESSING,
            'requested_revision' => 1,
            'lease_owner' => 'old-pre-cutover',
            'lease_expires_at' => now()->subMinutes(10),
        ]);
        $coordinator->recoverStuckReconciliations(limit: 10);
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, InventoryMeliOrderReconciliation::where('meli_order_id', $preCutover->id)->value('state'));

        // 3. FAILED order at retry ceiling (attempts=8): ordinary repeated syncs CANNOT bypass the ceiling!
        InventoryMeliOrderReconciliation::query()->delete();
        DB::table('jobs')->truncate();
        $failedOrd = $this->order(1, 933603, 'paid', [['item_id' => 'MLM-H13-CAP', 'key' => 'MLM-H13-CAP:', 'qty' => 1]]);
        $fp = $coordinator->fingerprint($failedOrd);
        InventoryMeliOrderReconciliation::create([
            'meli_order_id' => $failedOrd->id,
            'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
            'requested_revision' => 1,
            'processed_revision' => 0,
            'last_state_fingerprint' => $fp,
            'pending_job_id' => null,
            'attempts' => 8,
            'last_error' => 'Persistent error',
        ]);

        // 5 ordinary non-material syncs must enqueue 0 jobs!
        for ($i = 0; $i < 5; $i++) {
            $dec = $coordinator->registerOrderState($failedOrd->fresh('items'));
            $this->assertFalse($dec['should_dispatch']);
            $this->assertSame('failed_retry_ceiling_reached', $dec['reason']);
        }
        $this->assertSame(0, DB::table('jobs')->count());

        // Sweeper also respects the ceiling (0 jobs)!
        $sweep = $coordinator->recoverStuckReconciliations(limit: 10);
        $this->assertSame(0, $sweep['recovered_failed']);
        $this->assertSame(0, DB::table('jobs')->count());

        // A genuine material change (paid -> cancelled) OR explicit forceReconcile resets budget and enqueues 1 job!
        $failedOrd->update(['status' => 'cancelled']);
        $dispatcher->dispatchAfterCommit($failedOrd->fresh('items'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, (int) InventoryMeliOrderReconciliation::where('meli_order_id', $failedOrd->id)->value('attempts'));

        // 4. strict_atomic_queue=true + incompatible driver (`redis`) blocks recovery, completeExecution, AND job handle()!
        config()->set('queue.default', 'redis');
        try {
            $coordinator->recoverStuckReconciliations(limit: 10);
            $this->fail('recoverStuckReconciliations() must fail-closed when strict_atomic_queue=true and driver=redis.');
        } catch (RuntimeException) {
            // Expected
        }

        Log::spy();
        $spyService = Mockery::mock(InventoryMeliOrderReservationService::class);
        $spyService->shouldNotReceive('apply');
        (new ReconcileInventoryMeliOrderReservationsJob($failedOrd->id))->handle($spyService);
        Log::shouldHaveReceived('critical')
            ->once()
            ->with(
                'Inventory Meli order reservation blocked: incompatible atomic queue configuration',
                Mockery::on(static fn (array $ctx): bool => ($ctx['diagnostic_code'] ?? null) === 'incompatible_atomic_queue_config'),
            );
    }

    // =========================================================================
    // H14.1 — Partial, empty-items, and invalid payloads NEVER delete existing lines/reservations
    // =========================================================================
    public function test_h14_01_partial_empty_and_malformed_payloads_preserve_lines_allocations_and_reservations(): void
    {
        [$prodA] = $this->stockedProduct('SKU-H14-A', 10);
        [$prodB] = $this->stockedProduct('SKU-H14-B', 10);
        $this->link($prodA, 1, 'MLM-H14-A', null, true);
        $this->link($prodB, 1, 'MLM-H14-B', null, true);

        $store = app(MeliOrderStoreService::class);
        $service = app(InventoryMeliOrderReservationService::class);

        // 1. Seed complete order at 16:00Z with 2 lines and create real active reservations!
        $order = $store->storeFromOrderApiResponse([
            'id' => 934001,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:00:00Z',
            'order_items' => [
                ['item' => ['id' => 'MLM-H14-A'], 'quantity' => 2, 'unit_price' => 100],
                ['item' => ['id' => 'MLM-H14-B'], 'quantity' => 3, 'unit_price' => 50],
            ],
        ]);
        $order->update(['meli_account_id' => 1]);
        $service->apply($order->fresh('items'));
        $this->assertSame(2, InventoryReservation::active()->count());
        $this->assertSame(5, (int) InventoryReservation::active()->sum('quantity'));
        $this->assertSame(2, InventoryChannelOrderAllocation::where('status', 'ACTIVE')->count());

        // 2. Newer PARTIAL payload (16:05Z) OMITTING `order_items` and `status` -> MUST preserve lines, status, and reservations!
        $store->storeFromOrderApiResponse([
            'id' => 934001,
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:05:00Z',
            'tags' => ['paid', 'partial_note'],
        ]);
        $fresh = $order->fresh('items');
        $this->assertSame('paid', $fresh->status, 'Omitted status in partial payload must be preserved.');
        $this->assertCount(2, $fresh->items, 'Omitted order_items in partial payload must NEVER delete existing lines.');
        $this->assertSame(2, InventoryReservation::active()->count());
        $this->assertSame(5, (int) InventoryReservation::active()->sum('quantity'));

        // 3. Newer payload (16:06Z) with EXPLICIT `order_items: []` -> conservative contract rejects wiping existing lines!
        $store->storeFromOrderApiResponse([
            'id' => 934001,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:06:00Z',
            'order_items' => [],
        ]);
        $this->assertCount(2, $order->fresh('items')->items, 'Explicit empty order_items must NEVER wipe materialized lines.');

        // 4. Newer MALFORMED payloads (non-array items, missing item.id, quantity=0, negative quantity, blank status) -> ALL rejected!
        foreach ([
            ['order_items' => 'corrupt-string'],
            ['order_items' => [['item' => ['id' => ''], 'quantity' => 2]]],
            ['order_items' => [['item' => ['id' => 'MLM-H14-A'], 'quantity' => 0]]],
            ['order_items' => [['item' => ['id' => 'MLM-H14-A'], 'quantity' => -2]]],
            ['order_items' => [['item' => ['id' => 'MLM-H14-A']]]], // missing quantity
            ['status' => '   ', 'order_items' => [['item' => ['id' => 'MLM-H14-A'], 'quantity' => 1]]],
        ] as $idx => $badOverride) {
            $store->storeFromOrderApiResponse(array_merge([
                'id' => 934001,
                'status' => 'paid',
                'date_created' => '2026-11-20T09:00:00Z',
                'last_updated' => sprintf('2026-11-20T16:1%d:00Z', $idx),
            ], $badOverride));
        }

        $afterBad = $order->fresh('items');
        $this->assertSame('paid', $afterBad->status);
        $this->assertCount(2, $afterBad->items);
        $this->assertSame(5, (int) InventoryReservation::active()->sum('quantity'));
    }

    // =========================================================================
    // H14.2 — Partial cancellation does NOT block later full sync at same timestamp,
    //         account/shipment identity validation & legitimate shipping_id replacement
    // =========================================================================
    public function test_h14_02_partial_cancellation_timestamp_preservation_identity_checks_and_shipping_id_change(): void
    {
        $user = $this->seedSyncUserAndStubSideEffects();
        $sync = app(MeliOrderSyncService::class);
        $store = app(MeliOrderStoreService::class);

        // 1. Seed full order at 16:00Z (paid, qty=2, shipping_id=770100, ready_to_ship).
        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response(['id' => 111111]),
            'https://api.mercadolibre.com/orders/934002' => Http::sequence()
                ->push([
                    'id' => 934002,
                    'status' => 'paid',
                    'date_created' => '2026-11-20T09:00:00Z',
                    'last_updated' => '2026-11-20T16:00:00Z',
                    'shipping' => ['id' => 770100],
                    'order_items' => [['item' => ['id' => 'MLM-H14-02'], 'quantity' => 2, 'unit_price' => 100]],
                ])
                // Later sync: legitimate NEW shipping_id=770200 on newer order (16:20Z)
                ->push([
                    'id' => 934002,
                    'status' => 'cancelled',
                    'date_created' => '2026-11-20T09:00:00Z',
                    'last_updated' => '2026-11-20T16:20:00Z',
                    'shipping' => ['id' => 770200],
                    'order_items' => [['item' => ['id' => 'MLM-H14-02'], 'quantity' => 3, 'unit_price' => 100]],
                ]),
            'https://api.mercadolibre.com/shipments/770100' => Http::sequence()
                ->push([
                    'id' => 770100,
                    'status' => 'ready_to_ship',
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T16:00:00Z',
                ])
                // Mismatched shipment ID inside /shipments/770100 response (999999 != 770100) -> MUST reject!
                ->push([
                    'id' => 999999,
                    'status' => 'shipped',
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T16:15:00Z',
                ]),
            'https://api.mercadolibre.com/shipments/770200' => Http::response([
                'id' => 770200,
                'status' => 'pending',
                'mode' => 'me2',
                // Notice: shipment B's own timestamp can even be older than old shipment A's, yet must be accepted cleanly!
                'last_updated' => '2026-11-20T15:59:00Z',
            ]),
        ]);

        $sync->syncOrderById($user, '934002');
        $order = MeliOrder::where('order_id', 934002)->with('items')->firstOrFail();
        $this->assertSame('770100', (string) $order->shipping_id);
        $this->assertSame(2, (int) $order->items->sole()->quantity);

        // 2. Partial cancellation via MeliOrderCancellationService::persistRemote() at 16:05Z (NO order_items)!
        app(MeliOrderCancellationService::class)->persistRemote($order, [
            'id' => 934002,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:05:00Z',
        ]);
        $order->refresh()->load('items');
        $this->assertSame('cancelled', $order->status);
        $this->assertCount(1, $order->items, 'Partial cancellation must preserve materialized items.');
        // raw.last_updated stayed at 16:00Z (last full payload), while _partial_status_last_updated tracked 16:05Z!
        $this->assertSame('2026-11-20T16:00:00Z', $order->raw['last_updated']);
        $this->assertSame('2026-11-20T16:05:00.000000Z', $order->raw['_partial_status_last_updated']);

        // 3. Stale 16:02Z complete `paid` payload (< _partial_status_last_updated 16:05Z) MUST be rejected!
        $store->storeFromOrderApiResponse([
            'id' => 934002,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:02:00Z',
            'order_items' => [['item' => ['id' => 'MLM-H14-02'], 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $this->assertSame('cancelled', $order->fresh()->status);

        // 4. Complete 16:05Z payload WITH updated items (qty=3) IS ACCEPTED because full raw wasn't prematurely advanced!
        $store->storeFromOrderApiResponse([
            'id' => 934002,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:05:00Z',
            'order_items' => [['item' => ['id' => 'MLM-H14-02'], 'quantity' => 3, 'unit_price' => 100]],
        ]);
        $this->assertSame(3, (int) $order->fresh('items')->items->sole()->quantity);
        $this->assertSame('2026-11-20T16:05:00Z', $order->fresh()->raw['last_updated']);

        // 5. Account identity mismatch is rejected!
        $guard = app(MeliOrderRemoteFreshnessGuard::class);
        $acctMismatch = $guard->evaluateOrderUpdate($order->fresh('items'), [
            'id' => 934002,
            'status' => 'paid',
            'last_updated' => '2026-11-20T16:10:00Z',
            'order_items' => [['item' => ['id' => 'MLM-H14-02'], 'quantity' => 3]],
        ], incomingAccountId: 999);
        $this->assertFalse($acctMismatch['accept']);
        $this->assertSame('account_identity_mismatch', $acctMismatch['reason']);

        // 6. Shipment identity mismatch in refreshShipmentsByShippingIds() (payload id 999999 != order shipping_id 770100) -> REJECTED!
        $touchedMismatch = $sync->refreshShipmentsByShippingIds($user, ['770100']);
        $this->assertSame(0, $touchedMismatch);
        $this->assertSame('ready_to_ship', $order->fresh()->shipping_status);

        // 7. Legitimate shipping_id change (770100 -> 770200) authorized by newer accepted order (16:20Z) -> ACCEPTED cleanly!
        $sync->syncOrderById($user, '934002');
        $order->refresh();
        $this->assertSame('770200', (string) $order->shipping_id);
        $this->assertSame('pending', $order->shipping_status);
    }

    // =========================================================================
    // H14.3 — Impossible dates & partially parseable strings strictly rejected
    // =========================================================================
    public function test_h14_03_impossible_calendar_dates_and_partially_parseable_timestamps_are_rejected(): void
    {
        $guard = app(MeliOrderRemoteFreshnessGuard::class);

        foreach ([
            '2026-02-30T10:00:00Z',           // Feb 30 impossible date (PHP Carbon::parse overflows to Mar 2!)
            '2026-02-29T10:00:00Z',           // 2026 is not a leap year
            '2026-13-01T00:00:00Z',           // month 13
            '2026-11-20T25:00:00Z',           // hour 25
            '2026-11-20T10:00:00Z garbage',   // trailing garbage
            '2026-11-20 10:00:00',            // missing timezone
            '2026-11-20',                     // date-only ambiguous
            'not-a-date',
            '',
        ] as $badTs) {
            $this->assertNull($guard->parseUtc($badTs), "Timestamp '{$badTs}' must be strictly rejected.");
        }

        // Valid ISO-8601 timestamps with timezone offsets normalize cleanly to UTC.
        $this->assertSame('2026-11-20T16:05:00.000000Z', $guard->parseUtc('2026-11-20T10:05:00-06:00')?->toIso8601ZuluString('microsecond'));
        $this->assertSame('2026-11-20T16:05:00.123000Z', $guard->parseUtc('2026-11-20T12:05:00.123-04:00')?->toIso8601ZuluString('microsecond'));

        // Attempting to overwrite a timestamped order with impossible date '2026-02-30T10:00:00Z' is REJECTED!
        $store = app(MeliOrderStoreService::class);
        $order = $store->storeFromOrderApiResponse([
            'id' => 934003,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:00:00Z',
            'order_items' => [['item' => ['id' => 'MLM-H14-03'], 'quantity' => 2, 'unit_price' => 100]],
        ]);
        $store->storeFromOrderApiResponse([
            'id' => 934003,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-02-30T10:00:00Z',
            'order_items' => [['item' => ['id' => 'MLM-H14-03'], 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    // =========================================================================
    // H15 — Exhaustive 13-table preflight: strict_atomic_queue mandatory,
    //       all 13 tables, schema drift, and critical indexes
    // =========================================================================
    public function test_h15_01_exhaustive_preflight_checks_strict_mode_all_13_tables_schema_drift_and_indexes(): void
    {
        $preflight = app(InventoryMeliOrderReconciliationPreflight::class);

        // 1. strict_atomic_queue=false MUST FAIL preflight!
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', false);
        $failStrict = $preflight->check();
        $this->assertFalse($failStrict['passed']);
        $this->assertSame(1, Artisan::call('ops:inventory-meli-reconciliations-preflight'));

        // 2. strict_atomic_queue=true + all 13 tables + valid columns/indexes/bounds -> PASS!
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', true);
        $pass = $preflight->check();
        $this->assertTrue($pass['passed']);
        $this->assertSame(13, $pass['runtime']['tables_verified']);
        $this->assertSame(0, Artisan::call('ops:inventory-meli-reconciliations-preflight', ['--json' => true]));

        // 3. Missing critical index (drop inv_meli_order_rec_outbox_idx) -> FAIL!
        Schema::table('inventory_meli_order_reconciliations', function (Blueprint $table): void {
            $table->dropIndex('inv_meli_order_rec_outbox_idx');
        });
        $missingIdx = $preflight->check();
        $this->assertFalse($missingIdx['passed']);
        $idxCheck = collect($missingIdx['checks'])->firstWhere('name', 'critical_indexes_present');
        $this->assertFalse($idxCheck['passed']);

        // Restore index -> PASS again.
        Schema::table('inventory_meli_order_reconciliations', function (Blueprint $table): void {
            $table->index(['state', 'dispatch_confirmed_at'], 'inv_meli_order_rec_outbox_idx');
        });
        $this->assertTrue($preflight->check()['passed']);

        // 4. Schema drift (missing required column on inventory_meli_order_reconciliations) -> FAIL!
        Schema::dropIfExists('inventory_meli_order_reconciliations');
        Schema::create('inventory_meli_order_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id')->unique('inv_meli_order_rec_order_uq');
            $table->string('state', 24)->default('IDLE');
            // Intentionally missing fencing_token, pending_job_id, dispatch_confirmed_at, etc.!
            $table->timestamps();
        });
        $drift = $preflight->check();
        $this->assertFalse($drift['passed']);
        $colCheck = collect($drift['checks'])->firstWhere('name', 'required_schema_columns_present');
        $this->assertFalse($colCheck['passed']);
        $this->assertContains('fencing_token', $colCheck['details']['missing_columns']['inventory_meli_order_reconciliations']);

        // 5. Missing transactional inventory table (inventory_kit_reservations or inventory_movements) -> FAIL!
        Schema::dropIfExists('inventory_kit_reservations');
        Schema::dropIfExists('inventory_movements');
        $missingTables = $preflight->check();
        $this->assertFalse($missingTables['passed']);
        $tableCheck = collect($missingTables['checks'])->firstWhere('name', 'required_tables_exist');
        $this->assertContains('inventory_kit_reservations', $tableCheck['details']['missing']);
        $this->assertContains('inventory_movements', $tableCheck['details']['missing']);
    }

    // =========================================================================
    // PR #16 Final Fix — persistRemote() complete payload preserves materialized
    // version so subsequent storeOrder() at T2 materializes qty=4 & reconciles,
    // AND never mixes shipping_id with mismatched/stale shipping_raw
    // =========================================================================
    public function test_pr16_final_persist_remote_complete_payload_allows_subsequent_store_order_to_materialize_qty_and_preserves_shipping_coherence(): void
    {
        [$product] = $this->stockedProduct('SKU-PR16-FINAL', 10);
        $this->link($product, 1, 'MLM-PR16-FINAL', null, true);

        $user = $this->seedSyncUserAndStubSideEffects();
        $sync = app(MeliOrderSyncService::class);
        $cancelService = app(MeliOrderCancellationService::class);
        $resService = app(InventoryMeliOrderReservationService::class);

        $t1 = '2026-11-20T16:00:00Z';
        $t2 = '2026-11-20T16:05:00Z';

        $completePayloadT2 = [
            'id' => 934900,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => $t2,
            'shipping' => ['id' => 770500],
            'order_items' => [[
                'item' => ['id' => 'MLM-PR16-FINAL', 'seller_sku' => 'SKU-PR16-FINAL'],
                'quantity' => 4,
                'unit_price' => 100,
            ]],
        ];

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response(['id' => 111111]),
            'https://api.mercadolibre.com/orders/934900' => Http::sequence()
                // Step 1: initial full sync at T1 with qty=2
                ->push([
                    'id' => 934900,
                    'status' => 'paid',
                    'date_created' => '2026-11-20T09:00:00Z',
                    'last_updated' => $t1,
                    'shipping' => ['id' => 770500],
                    'order_items' => [[
                        'item' => ['id' => 'MLM-PR16-FINAL', 'seller_sku' => 'SKU-PR16-FINAL'],
                        'quantity' => 2,
                        'unit_price' => 100,
                    ]],
                ])
                // Step 3: subsequent full sync receiving the EXACT same complete payload T2 (qty=4)
                ->push($completePayloadT2),
            'https://api.mercadolibre.com/shipments/770500' => Http::response([
                'id' => 770500,
                'status' => 'ready_to_ship',
                'substatus' => 'ready_to_print',
                'mode' => 'me2',
                'last_updated' => $t1,
            ]),
        ]);

        // Step 1: Persist order at T1 (qty=2) and reconcile initial reservation (2 units).
        $sync->syncOrderById($user, '934900');
        $order = MeliOrder::where('order_id', 934900)->with('items')->firstOrFail();
        $this->assertSame(2, (int) $order->items->sole()->quantity);
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($resService);
        $this->assertSame(2, (int) InventoryReservation::active()->sum('quantity'));

        // Step 2a: persistRemote() receives complete payload T2 (qty=4) with a DIFFERENT shipping.id (770600)
        //          and NO matching shipment (null) -> must NOT mix shipping_id=770600 with shipping_raw of 770500,
        //          AND must preserve raw.last_updated = T1 because lines were not materialized!
        $payloadT2DifferentShip = $completePayloadT2;
        $payloadT2DifferentShip['shipping'] = ['id' => 770600];
        $cancelService->persistRemote($order, $payloadT2DifferentShip, null);

        $order->refresh();
        $this->assertSame('770500', (string) $order->shipping_id, 'Must NOT mix new shipping_id with old shipping_raw when shipment is absent.');
        $this->assertSame(770500, (int) ($order->shipping_raw['id'] ?? 0));
        $this->assertSame($t1, $order->raw['last_updated'], 'persistRemote() must preserve materialized raw.last_updated=T1.');
        $this->assertSame('2026-11-20T16:05:00.000000Z', $order->raw['_partial_status_last_updated']);

        // Step 2b: persistRemote() with mismatched shipment object (order says 770600, shipment says 999999) -> must NOT mix!
        $cancelService->persistRemote($order, $payloadT2DifferentShip, [
            'id' => 999999,
            'status' => 'shipped',
            'last_updated' => '2026-11-20T16:10:00Z',
        ]);
        $order->refresh();
        $this->assertSame('770500', (string) $order->shipping_id);
        $this->assertSame('ready_to_ship', $order->shipping_status);
        $this->assertSame(770500, (int) ($order->shipping_raw['id'] ?? 0));

        // Step 3: storeOrder() subsequently receives the EXACT same complete payload T2 (qty=4)!
        //         Must NOT be rejected as ambiguous_equal_timestamp_order_conflict; MUST materialize qty=4 and enqueue reconciliation!
        DB::table('jobs')->truncate();
        $sync->syncOrderById($user, '934900');

        $order->refresh()->load('items');
        $this->assertSame(4, (int) $order->items->sole()->quantity, 'Materialized line must now reflect quantity=4.');
        $this->assertSame($t2, $order->raw['last_updated'], 'Full sync advances raw.last_updated to T2.');
        $this->assertSame(1, DB::table('jobs')->count(), 'Material qty change 2->4 must enqueue reconciliation job.');

        // Execute queued reconciliation -> active reservation converges from 2 to 4 (0 lost obligations)!
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($resService);
        $this->assertSame(4, (int) InventoryReservation::active()->sum('quantity'));

        // Step 4: Coherent replacement of shipping_id (770500 -> 770600) when BOTH newer order (T3) AND matching shipment 770600 arrive:
        $t3 = '2026-11-20T16:15:00Z';
        $payloadT3 = $completePayloadT2;
        $payloadT3['last_updated'] = $t3;
        $payloadT3['shipping'] = ['id' => 770600];
        $cancelService->persistRemote($order->fresh(), $payloadT3, [
            'id' => 770600,
            'status' => 'pending',
            'substatus' => null,
            'last_updated' => '2026-11-20T16:14:00Z',
        ]);
        $order->refresh();
        $this->assertSame('770600', (string) $order->shipping_id);
        $this->assertSame('pending', $order->shipping_status);
        $this->assertNull($order->shipping_substatus, 'Old substatus from shipment 770500 must NOT leak onto shipment 770600.');
        $this->assertSame(770600, (int) ($order->shipping_raw['id'] ?? 0));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedSyncUserAndStubSideEffects(): User
    {
        $user = User::create([
            'name' => 'Sync User R33',
            'email' => 'sync-r33@example.com',
            'password' => 'secret',
            'role' => 'admin',
            'meli_id' => '111111',
            'access_token' => 'test-token',
        ]);
        DB::table('meli_accounts')->where('id', 1)->update(['user_id' => $user->id]);

        $stockMock = Mockery::mock(StockService::class);
        $stockMock->shouldReceive('applyStockFromMeliOrderIfNeeded')->andReturnNull();
        $this->app->instance(StockService::class, $stockMock);

        $sharedMock = Mockery::mock(MeliSharedStockOrderService::class);
        $sharedMock->shouldReceive('reconcile')->andReturnNull();
        $this->app->instance(MeliSharedStockOrderService::class, $sharedMock);

        $syscomMock = Mockery::mock(SyscomOrderFromMeliService::class);
        $syscomMock->shouldReceive('handleAfterMeliSync')->andReturnNull();
        $this->app->instance(SyscomOrderFromMeliService::class, $syscomMock);

        return $user;
    }

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
            $table->string('meli_id')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
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
            $table->string('shipping_id')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('shipping_mode')->nullable();
            $table->string('shipping_type')->nullable();
            $table->string('shipping_status')->nullable();
            $table->string('shipping_substatus')->nullable();
            $table->string('shipping_logistic_type')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_classification_reason')->nullable();
            $table->timestamp('delivery_classified_at')->nullable();
            $table->boolean('needs_shipping_review')->default(false);
            $table->date('shipping_process_date')->nullable();
            $table->string('display_id')->nullable();
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
            $table->string('title')->nullable();
            $table->string('variation_text')->nullable();
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
            ['code' => 'MAIN-R33'],
            ['name' => 'Main R33', 'is_active' => true],
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
    ): InventoryChannelLink {
        return InventoryChannelLink::create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $accountId,
            'external_listing_id' => $mlm,
            'external_variant_id' => $variation,
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
