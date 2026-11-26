<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Jobs\SyncInventoryMeliStockLinkJob;
use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;
use App\Models\InventoryLocation;
use App\Models\InventoryMeliOrderReconciliation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use App\Services\InventoryMeliOrderAdministrativeReevaluationService;
use App\Services\InventoryMeliOrderReconciliationCoordinator;
use App\Services\InventoryMeliOrderReconciliationPreflight;
use App\Services\InventoryMeliOrderReservationDispatcher;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\InventoryMeliStockSyncService;
use App\Services\MeliOrderStoreService;
use App\Services\MeliOrderSyncService;
use App\Services\MeliSharedStockOrderService;
use App\Services\MercadoLibre\Orders\MeliOrderCancellationService;
use App\Services\StockService;
use App\Services\SyscomOrderFromMeliService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * R3.2 Hardening verification suite covering H8 (remote chronological ordering across real
 * persistence flows), H9 (fencing + atomic stock-sync handoff + secondary failure honesty),
 * H10 (bounded diagnostic-first administrative re-evaluation), and H11 (stuck-work recovery
 * without future syncs + fail-closed runtime preflight).
 */
class InventoryMeliOrderReservationR32HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-26 12:00:00', 'UTC'));

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
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', false);

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
    // H8.1 — Stale `paid` received after recent `cancelled`, while legitimate newer transition succeeds
    // =========================================================================
    public function test_h8_01_stale_paid_after_recent_cancelled_is_rejected_and_newer_legitimate_transition_is_accepted(): void
    {
        $store = app(MeliOrderStoreService::class);

        // Newer cancelled snapshot commits first: 10:05:00-06:00 == 16:05:00Z.
        $order = $store->storeFromOrderApiResponse([
            'id' => 980001,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00-06:00',
            'last_updated' => '2026-11-20T10:05:00-06:00',
            'order_items' => [[
                'item' => ['id' => 'MLM-H8-01', 'seller_sku' => 'SKU-H8-01'],
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ]);
        $order->update(['meli_account_id' => 1]);
        DB::table('jobs')->truncate();

        // Stale paid snapshot arrives later with DIFFERENT timezone offset: 12:00:00-04:00 == 16:00:00Z (< 16:05:00Z)!
        $store->storeFromOrderApiResponse([
            'id' => 980001,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00-06:00',
            'last_updated' => '2026-11-20T12:00:00-04:00',
            'order_items' => [[
                'item' => ['id' => 'MLM-H8-01', 'seller_sku' => 'SKU-H8-01'],
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ]);

        $order->refresh();
        $this->assertSame('cancelled', $order->status, 'Stale paid must NEVER overwrite recent cancelled.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Rejected stale response must enqueue 0 jobs.');

        // Legitimate chronologically NEWER transition (16:10:00Z > 16:05:00Z) MUST be accepted (no rigid status hierarchy)!
        $store->storeFromOrderApiResponse([
            'id' => 980001,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00-06:00',
            'last_updated' => '2026-11-20T16:10:00Z',
            'order_items' => [[
                'item' => ['id' => 'MLM-H8-01', 'seller_sku' => 'SKU-H8-01'],
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ]);

        $order->refresh();
        $this->assertSame('paid', $order->status, 'Chronologically newer legitimate transition must be preserved.');
    }

    // =========================================================================
    // H8.2 — Stale response after shipped/delivered & independent order vs shipment versions
    // =========================================================================
    public function test_h8_02_stale_shipment_after_shipped_is_rejected_independently_of_order_version_in_sync_service(): void
    {
        $user = $this->seedSyncUserAndStubSideEffects();
        $sync = app(MeliOrderSyncService::class);

        Http::fake([
            'https://api.mercadolibre.com/users/me' => Http::response(['id' => 111111]),
            'https://api.mercadolibre.com/orders/980002' => Http::sequence()
                // Sync 1: order 16:05Z (paid)
                ->push([
                    'id' => 980002,
                    'status' => 'paid',
                    'date_created' => '2026-11-20T09:00:00Z',
                    'last_updated' => '2026-11-20T16:05:00Z',
                    'shipping' => ['id' => 770002],
                    'order_items' => [[
                        'item' => ['id' => 'MLM-H8-02', 'seller_sku' => 'SKU-H8-02'],
                        'quantity' => 2,
                        'unit_price' => 100,
                    ]],
                ])
                // Sync 2: ORDER newer (16:10Z) while SHIPMENT is STALE (16:00Z)
                ->push([
                    'id' => 980002,
                    'status' => 'paid',
                    'date_created' => '2026-11-20T09:00:00Z',
                    'last_updated' => '2026-11-20T16:10:00Z',
                    'tags' => ['paid', 'delivered_note'],
                    'shipping' => ['id' => 770002],
                    'order_items' => [[
                        'item' => ['id' => 'MLM-H8-02', 'seller_sku' => 'SKU-H8-02'],
                        'quantity' => 2,
                        'unit_price' => 100,
                    ]],
                ])
                // Sync 3: ORDER STALE (16:02Z, cancelled!) while SHIPMENT is NEWER (16:15Z, delivered)
                ->push([
                    'id' => 980002,
                    'status' => 'cancelled',
                    'date_created' => '2026-11-20T09:00:00Z',
                    'last_updated' => '2026-11-20T16:02:00Z',
                    'shipping' => ['id' => 770002],
                    'order_items' => [[
                        'item' => ['id' => 'MLM-H8-02', 'seller_sku' => 'SKU-H8-02'],
                        'quantity' => 1,
                        'unit_price' => 100,
                    ]],
                ]),
            'https://api.mercadolibre.com/shipments/770002' => Http::sequence()
                // Sync 1: shipped 16:05Z
                ->push([
                    'id' => 770002,
                    'status' => 'shipped',
                    'substatus' => 'in_transit',
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T16:05:00Z',
                ])
                // Sync 2: STALE ready_to_ship 16:00Z
                ->push([
                    'id' => 770002,
                    'status' => 'ready_to_ship',
                    'substatus' => 'ready_to_print',
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T16:00:00Z',
                ])
                // Sync 3: NEWER delivered 16:15Z
                ->push([
                    'id' => 770002,
                    'status' => 'delivered',
                    'substatus' => null,
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T16:15:00Z',
                ]),
        ]);

        // First sync: order at 16:05Z (paid), shipment at 16:05Z (shipped).
        $sync->syncOrderById($user, '980002');
        $order = MeliOrder::where('order_id', 980002)->firstOrFail();
        $this->assertSame('shipped', $order->shipping_status);

        // Second sync: ORDER is newer (16:10Z) while SHIPMENT response is STALE (16:00Z, ready_to_ship)!
        $sync->syncOrderById($user, '980002');
        $order->refresh();
        // Order updated to 16:10Z, YET newer shipment status `shipped` was preserved independently!
        $this->assertSame('2026-11-20T16:10:00Z', $order->raw['last_updated']);
        $this->assertSame('shipped', $order->shipping_status);
        $this->assertSame('2026-11-20T16:05:00Z', $order->shipping_raw['last_updated']);

        // Third sync: ORDER is STALE (16:02Z, cancelled!) while SHIPMENT is NEWER (16:15Z, delivered)!
        $sync->syncOrderById($user, '980002');
        $order->refresh()->load('items');
        // Stale order was rejected (status stays paid, qty stays 2), while newer shipment `delivered` WAS applied!
        $this->assertSame('paid', $order->status);
        $this->assertSame(2, (int) $order->items->sole()->quantity);
        $this->assertSame('delivered', $order->shipping_status);
    }

    // =========================================================================
    // H8.3 — Stale quantities and lines received after recent changes
    // =========================================================================
    public function test_h8_03_stale_quantities_and_lines_cannot_regress_recent_order_items(): void
    {
        $store = app(MeliOrderStoreService::class);

        // Recent order at 16:05Z with 2 lines and qty=4.
        $order = $store->storeFromOrderApiResponse([
            'id' => 980003,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:05:00Z',
            'order_items' => [
                ['item' => ['id' => 'MLM-LINE-1', 'variation_id' => 'V1'], 'quantity' => 4, 'unit_price' => 50],
                ['item' => ['id' => 'MLM-LINE-2', 'variation_id' => null], 'quantity' => 2, 'unit_price' => 75],
            ],
        ]);
        $order->update(['meli_account_id' => 1]);
        DB::table('jobs')->truncate();

        // Stale response at 16:00Z with only 1 line and old qty=1!
        $store->storeFromOrderApiResponse([
            'id' => 980003,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:00:00Z',
            'order_items' => [
                ['item' => ['id' => 'MLM-LINE-1', 'variation_id' => 'V1'], 'quantity' => 1, 'unit_price' => 50],
            ],
        ]);

        $items = MeliOrderItem::where('meli_order_id', $order->id)->orderBy('item_id')->get();
        $this->assertCount(2, $items, 'Stale payload must NEVER delete newer order lines.');
        $this->assertSame(4, (int) $items[0]->quantity);
        $this->assertSame(2, (int) $items[1]->quantity);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // =========================================================================
    // H8.4 — Two out-of-order shipment refreshes via refreshShipmentsByShippingIds()
    // =========================================================================
    public function test_h8_04_two_out_of_order_shipment_responses_in_refresh_shipments_do_not_regress(): void
    {
        $user = $this->seedSyncUserAndStubSideEffects();
        $sync = app(MeliOrderSyncService::class);

        $order = $this->order(1, 980004, 'paid', [['item_id' => 'MLM-H8-04', 'key' => 'MLM-H8-04:', 'qty' => 1]], 'ready_to_ship');
        $order->update(['shipping_id' => '770004']);
        DB::table('jobs')->truncate();

        // Sequence: Response #1 (newer: shipped at 16:05Z), then Response #2 (older: ready_to_ship at 16:00Z).
        Http::fake([
            'https://api.mercadolibre.com/shipments/770004' => Http::sequence()
                ->push([
                    'id' => 770004,
                    'status' => 'shipped',
                    'substatus' => 'in_transit',
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T16:05:00Z',
                ])
                ->push([
                    'id' => 770004,
                    'status' => 'ready_to_ship',
                    'substatus' => 'ready_to_print',
                    'mode' => 'me2',
                    'last_updated' => '2026-11-20T12:00:00-04:00',
                ]),
        ]);

        $touched1 = $sync->refreshShipmentsByShippingIds($user, ['770004']);
        $this->assertSame(1, $touched1);
        $this->assertSame('shipped', $order->fresh()->shipping_status);
        DB::table('jobs')->truncate();

        $touched2 = $sync->refreshShipmentsByShippingIds($user, ['770004']);
        $this->assertSame(0, $touched2, 'Stale shipment refresh must update 0 rows.');
        $this->assertSame('shipped', $order->fresh()->shipping_status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // =========================================================================
    // H8.5 — Missing, invalid, or equal UTC timestamps with contradictory data
    // =========================================================================
    public function test_h8_05_missing_invalid_or_equal_timestamps_with_contradictory_data_are_rejected_conservatively(): void
    {
        $store = app(MeliOrderStoreService::class);

        $order = $store->storeFromOrderApiResponse([
            'id' => 980005,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T10:05:00-06:00', // 16:05:00Z
            'order_items' => [
                ['item' => ['id' => 'MLM-H8-05'], 'quantity' => 3, 'unit_price' => 100],
            ],
        ]);
        $order->update(['meli_account_id' => 1]);
        DB::table('jobs')->truncate();

        // 5a: Equal UTC timestamp (12:05:00-04:00 == 16:05:00Z) with contradictory status (`paid` vs `cancelled`) -> REJECTED!
        $store->storeFromOrderApiResponse([
            'id' => 980005,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T12:05:00-04:00',
            'order_items' => [
                ['item' => ['id' => 'MLM-H8-05'], 'quantity' => 3, 'unit_price' => 100],
            ],
        ]);
        $this->assertSame('cancelled', $order->fresh()->status);

        // 5b: Missing/invalid timestamp (`"not-a-valid-date"`) overwriting timestamped snapshot -> REJECTED!
        $store->storeFromOrderApiResponse([
            'id' => 980005,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => 'not-a-valid-date',
            'order_items' => [
                ['item' => ['id' => 'MLM-H8-05'], 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(3, (int) MeliOrderItem::where('meli_order_id', $order->id)->value('quantity'));

        // 5c: Both untimestamped with contradictory status -> REJECTED!
        $untimestamped = $store->storeFromOrderApiResponse([
            'id' => 980006,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'order_items' => [
                ['item' => ['id' => 'MLM-H8-06'], 'quantity' => 2, 'unit_price' => 50],
            ],
        ]);
        $store->storeFromOrderApiResponse([
            'id' => 980006,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'order_items' => [
                ['item' => ['id' => 'MLM-H8-06'], 'quantity' => 2, 'unit_price' => 50],
            ],
        ]);
        $this->assertSame('cancelled', $untimestamped->fresh()->status);

        // 5d: CancellationService::persistRemote() respects freshness guard AND dispatches reconciliation on valid cancel!
        $cancelOrder = $this->order(1, 980007, 'paid', [['item_id' => 'MLM-H8-07', 'key' => 'MLM-H8-07:', 'qty' => 1]], 'ready_to_ship');
        $cancelOrder->update(['raw' => ['date_created' => '2026-11-20T09:00:00Z', 'last_updated' => '2026-11-20T16:05:00Z']]);
        DB::table('jobs')->truncate();

        $cancelService = app(MeliOrderCancellationService::class);
        // Stale remoteOrder (16:00Z) rejected!
        $cancelService->persistRemote($cancelOrder, [
            'id' => 980007,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:00:00Z',
        ]);
        $this->assertSame('paid', $cancelOrder->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());

        // Newer remoteOrder (16:10Z) accepted AND enqueues reconciliation!
        $cancelService->persistRemote($cancelOrder, [
            'id' => 980007,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:10:00Z',
        ]);
        $this->assertSame('cancelled', $cancelOrder->fresh()->status);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // H9 — Atomic stock_sync_required on line commit, atomic multi-link handoff,
    //      and honest secondary failure (no false remote convergence claim)
    // =========================================================================
    public function test_h9_atomic_stock_sync_flag_on_line_commit_atomic_multi_link_handoff_and_secondary_failure_honesty(): void
    {
        [$prodA] = $this->stockedProduct('SKU-H9-A', 10);
        [$prodB] = $this->stockedProduct('SKU-H9-B', 10);
        $linkA = $this->link($prodA, 1, 'MLM-H9-A', null, true, true, true);
        $linkB = $this->link($prodB, 1, 'MLM-H9-B', null, true, true, true);

        $order = $this->order(1, 990001, 'paid', [
            ['item_id' => 'MLM-H9-A', 'key' => 'MLM-H9-A:', 'qty' => 2],
            ['item_id' => 'MLM-H9-B', 'key' => 'MLM-H9-B:', 'qty' => 3],
        ]);

        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $service = app(InventoryMeliOrderReservationService::class);

        // Part A: Simulate SIGKILL immediately after apply() commits inventory (BEFORE markStockSyncRequiredAndRenewLease)!
        $claim = $coordinator->claimForExecution($order->id, 'worker-crash-after-apply', leaseSeconds: 300);
        $service->apply($order, ['lease_owner' => 'worker-crash-after-apply', 'fencing_token' => $claim['fencing_token']]);

        // Even though handle() never reached markStockSyncRequiredAndRenewLease(), stock_sync_required IS ALREADY TRUE!
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertTrue((bool) $state->stock_sync_required, 'Inventory commit MUST atomically set stock_sync_required=true.');
        $this->assertSame(2, InventoryReservation::active()->count());

        // Part B: Recovery runs full handle() -> enqueues BOTH SyncInventoryMeliStockLinkJob rows ATOMICALLY inside completeExecution()!
        Carbon::setTestNow(Carbon::now()->addSeconds(305));
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);

        $stockJobs = DB::table('jobs')
            ->where('payload', 'like', '%SyncInventoryMeliStockLinkJob%')
            ->get();
        $this->assertCount(2, $stockJobs, 'Both affected links must be atomically handed off to `jobs` in completeExecution().');
        $state->refresh();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_IDLE, $state->state);
        $this->assertFalse((bool) $state->stock_sync_required);

        // Part C: Secondary SyncInventoryMeliStockLinkJob recalculates LIVE stock (not stale snapshot)
        //         AND when ML returns HTTP 500, audit records FAILED — NEVER falsely claiming remote convergence!
        MeliAccount::where('id', 1)->update(['access_token' => 'test-token', 'expires_at' => now()->addHour()]);
        Http::fake([
            'https://api.mercadolibre.com/items/MLM-H9-A' => Http::sequence()
                ->push(['id' => 'MLM-H9-A', 'available_quantity' => 10], 200) // GET current remote
                ->push(['error' => 'internal_server_error'], 500),           // PUT fails!
        ]);

        $syncJob = new SyncInventoryMeliStockLinkJob($linkA->id);
        $syncJob->handle(app(InventoryMeliStockSyncService::class));

        $audit = InventoryChannelStockSync::where('inventory_channel_link_id', $linkA->id)->latest('id')->firstOrFail();
        $this->assertSame(InventoryChannelStockSync::FAILED, $audit->status, 'Secondary job failure must be audited as FAILED.');
        $this->assertSame(8, (int) $audit->target_quantity, 'Secondary job must recalculate live available stock (10 - 2 = 8).');
        $this->assertNull($linkA->fresh()->last_synced_at, 'Failed secondary sync must NOT mark link as remotely synced.');
    }

    // =========================================================================
    // H10 — Bounded, diagnostic-first administrative re-evaluation
    // =========================================================================
    public function test_h10_admin_reevaluation_is_scoped_bounded_and_never_reconstructs_ambiguous_or_closed_orders(): void
    {
        [$prod, $loc] = $this->stockedProduct('SKU-H10', 20);
        $link = $this->link($prod, 1, 'MLM-H10', null, reservationEnabled: false);

        // 1. Safe open post-cutover paid order (initially skipped because link had reservation disabled).
        $safeOrder = $this->order(1, 991001, 'paid', [['item_id' => 'MLM-H10', 'key' => 'MLM-H10:', 'qty' => 2]], 'ready_to_ship', '2026-11-20T10:00:00-06:00');
        // 2. Ambiguous order with manual adjustment after creation -> MUST NEVER be auto-reconstructed!
        $ambiguousOrder = $this->order(1, 991002, 'paid', [['item_id' => 'MLM-H10', 'key' => 'MLM-H10:', 'qty' => 1]], 'ready_to_ship', '2026-11-19T10:00:00-06:00');
        // 3. Closed shipped order -> MUST be skipped!
        $shippedOrder = $this->order(1, 991003, 'paid', [['item_id' => 'MLM-H10', 'key' => 'MLM-H10:', 'qty' => 1]], 'shipped', '2026-11-20T10:00:00-06:00');
        // 4. Pre-cutover order -> MUST be skipped!
        $preCutoverOrder = $this->order(1, 991004, 'paid', [['item_id' => 'MLM-H10', 'key' => 'MLM-H10:', 'qty' => 1]], 'ready_to_ship', '2026-09-15T10:00:00-06:00');

        // Add manual negative adjustment specifically between ambiguousOrder (Nov 19) and safeOrder (Nov 20) — wait:
        // ADJUSTMENT_OUT at Nov 19 12:00 affects only orders created <= Nov 19 12:00 (ambiguousOrder) and NOT safeOrder (Nov 20 10:00)!
        InventoryMovement::create([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::ADJUSTMENT_OUT,
            'quantity' => -1,
            'reference_type' => 'manual_audit',
            'occurred_at' => Carbon::parse('2026-11-19 12:00:00', 'America/Mexico_City'),
        ]);

        // Admin now enables order_reservation_enabled on the link!
        $link->update(['order_reservation_enabled' => true]);
        DB::table('jobs')->truncate();

        $reeval = app(InventoryMeliOrderAdministrativeReevaluationService::class);

        // Unscoped call MUST be refused!
        try {
            $reeval->evaluate([]);
            $this->fail('Unscoped full-history evaluation must be rejected.');
        } catch (\InvalidArgumentException) {
            // Expected
        }

        // Dry-run identifies ONLY safeOrder as eligible, flags ambiguousOrder for human review, skips shipped & pre-cutover, dispatches 0 jobs!
        $dry = $reeval->evaluate(['link_id' => $link->id, 'limit' => 50], apply: false);
        $this->assertSame('dry-run', $dry['mode']);
        $this->assertCount(1, $dry['eligible_safe_candidates']);
        $this->assertSame($safeOrder->id, $dry['eligible_safe_candidates'][0]['meli_order_id']);
        $this->assertCount(1, $dry['skipped_ambiguous_requires_human_review']);
        $this->assertSame($ambiguousOrder->id, $dry['skipped_ambiguous_requires_human_review'][0]['meli_order_id']);
        $this->assertSame(0, $dry['dispatched_count']);
        $this->assertSame(0, DB::table('jobs')->count());

        // Artisan --apply enqueues ONLY safeOrder (1 job) and leaves ambiguousOrder untouched!
        $exit = Artisan::call('ops:inventory-meli-orders-reevaluate', [
            '--apply' => true,
            '--link-id' => $link->id,
            '--limit' => 50,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, InventoryMeliOrderReconciliation::where('meli_order_id', $safeOrder->id)->count());
        $this->assertSame(0, InventoryMeliOrderReconciliation::where('meli_order_id', $ambiguousOrder->id)->count());
    }

    // =========================================================================
    // H11 — Stuck work recovery (SIGKILL after line 1, SIGKILL after IDLE before delete,
    //       expired PROCESSING with original job still reserved & 0 syncs for 3 days,
    //       vanished pending_job_id in PENDING & FAILED) + Preflight Fail-Closed
    // =========================================================================
    public function test_h11_stuck_work_recovery_without_future_syncs_and_sigkill_mid_order_and_after_complete(): void
    {
        [$prodA] = $this->stockedProduct('SKU-H11-A', 10);
        [$prodB] = $this->stockedProduct('SKU-H11-B', 10);
        $this->link($prodA, 1, 'MLM-H11-A', null, true, true, true);
        $this->link($prodB, 1, 'MLM-H11-B', null, true, true, true);

        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $service = app(InventoryMeliOrderReservationService::class);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Case 1: PROCESSING expired with original job STILL reserved in `jobs` (retry_after=7300s) and 0 syncs for 3 DAYS!
        $orderExp = $this->order(1, 992001, 'paid', [['item_id' => 'MLM-H11-A', 'key' => 'MLM-H11-A:', 'qty' => 2]]);
        $dispatcher->dispatchAfterCommit($orderExp);
        $origJobId = (int) DB::table('jobs')->value('id');
        // Simulate worker reserving original job in `jobs`, claiming lease, and dying (SIGKILL) with NO syncs for 3 days!
        DB::table('jobs')->where('id', $origJobId)->update(['reserved_at' => now()->timestamp]);
        $claim1 = $coordinator->claimForExecution($orderExp->id, 'dead-worker', targetRevision: 1, isQueuedExecution: true, leaseSeconds: 300);
        $this->assertTrue($claim1['claimed']);

        // 3 days pass with ZERO syncs!
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $rec = $coordinator->recoverStuckReconciliations(limit: 50);
        $this->assertSame(1, $rec['recovered_expired_processing']);

        // Recovery worker executes the newly enqueued recovery job -> reserves 2 units and settles to IDLE!
        (new ReconcileInventoryMeliOrderReservationsJob($orderExp->id, 1))->handle($service);
        $this->assertSame(2, (int) InventoryReservation::where('inventory_product_id', $prodA->id)->active()->sum('quantity'));

        // Later (at retry_after=7300s), Laravel re-delivers the ORIGINAL reserved job -> skipped via already_reconciled_replay!
        $fakeOriginalQueueJob = Mockery::mock(QueueJobContract::class);
        $fakeOriginalQueueJob->shouldReceive('attempts')->andReturn(2);
        $fakeOriginalQueueJob->shouldReceive('getJobId')->andReturn($origJobId);
        $spyService = Mockery::mock(InventoryMeliOrderReservationService::class);
        $spyService->shouldNotReceive('apply');
        $replayedOriginal = new ReconcileInventoryMeliOrderReservationsJob($orderExp->id, 1);
        $replayedOriginal->setJob($fakeOriginalQueueJob);
        $replayedOriginal->handle($spyService);

        // Case 2: SIGKILL after committing Line 1 of a 2-line order (before Line 2!) -> recovery finishes Line 2 cleanly!
        $orderTwoLines = $this->order(1, 992002, 'paid', [
            ['item_id' => 'MLM-H11-A', 'key' => 'MLM-H11-A:', 'qty' => 1],
            ['item_id' => 'MLM-H11-B', 'key' => 'MLM-H11-B:', 'qty' => 3],
        ]);
        $claimPartial = $coordinator->claimForExecution($orderTwoLines->id, 'worker-partial-kill', leaseSeconds: 300);
        // Apply ONLY line 1 (simulating SIGKILL before line 2 commits!).
        $oneLineOrder = clone $orderTwoLines;
        $oneLineOrder->setRelation('items', $orderTwoLines->items->take(1));
        $service->apply($oneLineOrder, ['lease_owner' => 'worker-partial-kill', 'fencing_token' => $claimPartial['fencing_token']]);
        $this->assertTrue((bool) InventoryMeliOrderReconciliation::where('meli_order_id', $orderTwoLines->id)->value('stock_sync_required'));

        // Lease expires -> sweeper recovers -> next worker reconciles both lines without duplicating line 1!
        Carbon::setTestNow(Carbon::now()->addSeconds(305));
        $rec2 = $coordinator->recoverStuckReconciliations(limit: 50);
        $this->assertSame(1, $rec2['recovered_expired_processing']);
        (new ReconcileInventoryMeliOrderReservationsJob($orderTwoLines->id))->handle($service);
        $this->assertSame(3, (int) InventoryReservation::where('inventory_product_id', $prodA->id)->active()->sum('quantity')); // 2 + 1
        $this->assertSame(3, (int) InventoryReservation::where('inventory_product_id', $prodB->id)->active()->sum('quantity')); // 3

        // Case 3: SIGKILL AFTER completeExecution() committed IDLE, BEFORE Laravel deleted the job from `jobs`:
        // Re-delivery sees IDLE + matching fingerprint + !stock_sync_required -> 0 apply() calls!
        $redeliveredAfterComplete = new ReconcileInventoryMeliOrderReservationsJob($orderTwoLines->id, 1);
        $redeliveredAfterComplete->setJob($fakeOriginalQueueJob);
        $redeliveredAfterComplete->handle($spyService);

        // Case 4: PENDING and FAILED whose `pending_job_id` vanished from `jobs` (0 future syncs) -> sweeper recovers both,
        //         AND FAILED exceeding maxAutoRecoveryAttempts is NOT infinitely re-enqueued!
        DB::table('jobs')->truncate();
        $orderVanishedPending = $this->order(1, 992003, 'paid', [['item_id' => 'MLM-H11-A', 'key' => 'MLM-H11-A:', 'qty' => 1]]);
        $dispatcher->dispatchAfterCommit($orderVanishedPending);
        DB::table('jobs')->truncate(); // pending_job_id vanished!

        $orderVanishedFailed = $this->order(1, 992004, 'paid', [['item_id' => 'MLM-H11-B', 'key' => 'MLM-H11-B:', 'qty' => 1]]);
        InventoryMeliOrderReconciliation::create([
            'meli_order_id' => $orderVanishedFailed->id,
            'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
            'requested_revision' => 1,
            'processed_revision' => 0,
            'pending_job_id' => 999999, // vanished!
            'attempts' => 2,
            'stock_sync_required' => true,
        ]);

        $orderExhaustedFailed = $this->order(1, 992005, 'paid', [['item_id' => 'MLM-H11-B', 'key' => 'MLM-H11-B:', 'qty' => 1]]);
        InventoryMeliOrderReconciliation::create([
            'meli_order_id' => $orderExhaustedFailed->id,
            'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
            'requested_revision' => 1,
            'processed_revision' => 0,
            'pending_job_id' => null,
            'attempts' => 8, // reached maxAutoRecoveryAttempts ceiling!
            'stock_sync_required' => true,
        ]);

        $rec3 = $coordinator->recoverStuckReconciliations(limit: 50, maxAutoRecoveryAttempts: 8);
        $this->assertSame(1, $rec3['recovered_pending']);
        $this->assertSame(1, $rec3['recovered_failed']);
        $this->assertSame(2, DB::table('jobs')->count(), 'Exhausted FAILED order must NOT cause infinite retry loop.');

        // Running sweeper again immediately is a clean no-op (0 duplicates!).
        $rec4 = $coordinator->recoverStuckReconciliations(limit: 50, maxAutoRecoveryAttempts: 8);
        $this->assertSame(0, $rec4['recovered_pending']);
        $this->assertSame(0, $rec4['recovered_failed']);
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_h11_preflight_fails_closed_on_incompatible_queue_config_and_passes_on_valid_config(): void
    {
        $preflight = app(InventoryMeliOrderReconciliationPreflight::class);

        // 1. Valid database queue config (same DB, after_commit=false, 120 < 300 < 7300) -> PASS!
        $ok = $preflight->check();
        $this->assertTrue($ok['passed']);
        $this->assertSame(0, Artisan::call('ops:inventory-meli-reconciliations-preflight', ['--json' => true]));

        // 2. Incompatible driver (`redis` / `sync`) -> FAIL-CLOSED!
        config()->set('queue.default', 'redis');
        $badDriver = $preflight->check();
        $this->assertFalse($badDriver['passed']);
        $this->assertSame(1, Artisan::call('ops:inventory-meli-reconciliations-preflight'));

        // strict_atomic_queue=true also blocks registerOrderState() fail-closed!
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', true);
        $order = $this->order(1, 992010, 'paid', [['item_id' => 'MLM-H11-PRE', 'key' => 'MLM-H11-PRE:', 'qty' => 1]]);
        try {
            app(InventoryMeliOrderReconciliationCoordinator::class)->registerOrderState($order);
            $this->fail('strict_atomic_queue=true must reject non-database queue driver.');
        } catch (RuntimeException) {
            // Expected
        }
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', false);

        // 3. after_commit = true -> FAIL-CLOSED!
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.after_commit', true);
        $this->assertFalse($preflight->check()['passed']);
        config()->set('queue.connections.database.after_commit', false);

        // 4. Temporal violation (retry_after <= lease_seconds) -> FAIL-CLOSED!
        config()->set('queue.connections.database.retry_after', 200);
        $this->assertFalse($preflight->check()['passed']);
        config()->set('queue.connections.database.retry_after', 7300);

        // 5. Missing durable state table -> FAIL-CLOSED!
        Schema::dropIfExists('inventory_meli_order_reconciliations');
        $this->assertFalse($preflight->check()['passed']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedSyncUserAndStubSideEffects(): User
    {
        $user = User::create([
            'name' => 'Sync User',
            'email' => 'sync-h8@example.com',
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
            ['code' => 'MAIN-R32'],
            ['name' => 'Main R32', 'is_active' => true],
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
