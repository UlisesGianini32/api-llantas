<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\StaleReconciliationLeaseException;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryMeliOrderReconciliation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Services\InventoryMeliOrderReconciliationCoordinator;
use App\Services\InventoryMeliOrderReconciliationPreflight;
use App\Services\InventoryMeliOrderReservationDispatcher;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\InventoryStockService;
use App\Services\MeliOrderStoreService;
use App\Services\MercadoLibre\Orders\MeliOrderCancellationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Real MariaDB 10.5.29 / InnoDB (REPEATABLE-READ) concurrency, migration, locking,
 * atomic same-DB enqueue, fencing token, stale callback, and double-deduction tests for R3/R3.1.
 *
 * Opt-in via R3_MARIADB_TEST_HOST so standard SQLite runs stay isolated.
 */
class InventoryMeliOrderReservationR3MariaDbTest extends TestCase
{
    private string $host = '';

    private int $port = 3309;

    private string $database = 'r3_reservation_test';

    private string $username = 'root';

    private string $password = 'r3_test_root_pass';

    private bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        $host = trim((string) (getenv('R3_MARIADB_TEST_HOST') ?: ''));
        if ($host === '') {
            $this->markTestSkipped('R3 MariaDB/InnoDB tests require R3_MARIADB_TEST_HOST.');
        }

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'UTC'));

        $this->host = $host;
        $this->port = (int) (getenv('R3_MARIADB_TEST_PORT') ?: 3309);
        $this->database = (string) (getenv('R3_MARIADB_TEST_DATABASE') ?: 'r3_reservation_test');
        $this->username = (string) (getenv('R3_MARIADB_TEST_USERNAME') ?: 'root');
        $this->password = (string) (getenv('R3_MARIADB_TEST_PASSWORD') ?: 'r3_test_root_pass');

        $this->resetDatabase();

        config()->set('database.connections.r3_mariadb', [
            'driver' => 'mariadb',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);
        config()->set('database.default', 'r3_mariadb');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'r3_mariadb');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', true);
        config()->set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        config()->set('inventory.meli_order_reservations.lease_seconds', 300);

        DB::purge('r3_mariadb');
        $this->runMigrationsOnMariaDb();
        $this->bootstrapped = true;
    }

    protected function tearDown(): void
    {
        InventoryMeliOrderReconciliationCoordinator::resetTestingHooks();
        Carbon::setTestNow();
        if ($this->bootstrapped) {
            DB::purge('r3_mariadb');
            $this->bootstrapped = false;
        }
        parent::tearDown();
    }

    public function test_mariadb_migration_is_additive_idempotent_and_uses_innodb_r31_indexes(): void
    {
        $conn = DB::connection('r3_mariadb');
        $tableInfo = $conn->selectOne(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_meli_order_reconciliations'",
            [$this->database],
        );
        $this->assertSame('InnoDB', $tableInfo->ENGINE);

        // Verify MariaDB isolation level is REPEATABLE-READ (matches production).
        $iso = $conn->selectOne('SELECT @@tx_isolation AS iso');
        $this->assertSame('REPEATABLE-READ', $iso->iso);

        // Re-running migration up() must be a safe idempotent no-op.
        $migration = require database_path('migrations/2026_11_25_000001_create_inventory_meli_order_reconciliations_table.php');
        $migration->up();

        $indexes = collect($conn->select('SHOW INDEX FROM inventory_meli_order_reconciliations'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        $this->assertContains('inv_meli_order_rec_order_uq', $indexes);
        $this->assertContains('inv_meli_order_rec_state_lease_idx', $indexes);
        $this->assertContains('inv_meli_order_rec_outbox_idx', $indexes);
        $this->assertContains('inv_meli_order_rec_pending_job_idx', $indexes);

        foreach (['fencing_token', 'pending_job_id', 'dispatch_confirmed_at', 'stock_sync_required', 'stock_sync_completed_revision'] as $col) {
            $this->assertTrue(Schema::connection('r3_mariadb')->hasColumn('inventory_meli_order_reconciliations', $col));
        }
    }

    public function test_mariadb_h1_atomic_same_db_enqueue_commits_and_rolls_back_with_outer_tx_without_orphans(): void
    {
        [$product] = $this->seedProduct('SKU-M-ATOMIC', 10);
        $this->seedLink($product, 1, 'MLM-M-ATOMIC', null, true);
        $order = $this->seedOrder(1, 880010, 'paid', [['item_id' => 'MLM-M-ATOMIC', 'key' => 'MLM-M-ATOMIC:', 'qty' => 2]]);

        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $observerPdo = $this->openRawPdo();

        // 1. Outer transaction rollback must roll back BOTH PENDING row AND `jobs` row atomically,
        //    AND an independent connection must NEVER see uncommitted rows mid-transaction!
        try {
            DB::transaction(function () use ($dispatcher, $order, $observerPdo): void {
                $dispatcher->dispatchAfterCommit($order);

                // Inside same TX: both rows exist.
                $this->assertSame(1, DB::table('jobs')->count());
                $this->assertSame(1, InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->count());

                // Independent InnoDB session sees 0 uncommitted jobs and 0 uncommitted reconciliation rows!
                $this->assertSame(0, (int) $observerPdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
                $this->assertSame(0, (int) $observerPdo->query('SELECT COUNT(*) FROM inventory_meli_order_reconciliations')->fetchColumn());

                throw new RuntimeException('Outer TX rollback');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(0, (int) $observerPdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $this->assertSame(0, (int) $observerPdo->query('SELECT COUNT(*) FROM inventory_meli_order_reconciliations')->fetchColumn());

        // 2. Clean commit -> independent session immediately sees BOTH PENDING (with pending_job_id & dispatch_confirmed_at) AND jobs row!
        DB::transaction(function () use ($dispatcher, $order): void {
            $dispatcher->dispatchAfterCommit($order);
        });

        $jobId = (int) $observerPdo->query('SELECT id FROM jobs LIMIT 1')->fetchColumn();
        $this->assertGreaterThan(0, $jobId);

        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $state->state);
        $this->assertSame($jobId, (int) $state->pending_job_id);
        $this->assertNotNull($state->dispatch_confirmed_at);
    }

    public function test_mariadb_concurrent_session_lease_exclusion_and_atomic_dirty_revision_followup(): void
    {
        [$product] = $this->seedProduct('SKU-M-LEASE', 10);
        $this->seedLink($product, 1, 'MLM-M-LEASE', null, true);
        $order = $this->seedOrder(1, 880001, 'paid', [['item_id' => 'MLM-M-LEASE', 'key' => 'MLM-M-LEASE:', 'qty' => 2]]);

        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Worker 1 claims active lease (token=1) on MariaDB InnoDB.
        $claim1 = $coordinator->claimForExecution($order->id, 'worker-alpha', leaseSeconds: 300);
        $this->assertTrue($claim1['claimed']);
        $this->assertSame(1, $claim1['fencing_token']);

        // Worker 2 cannot overlap while Worker 1's lease is active.
        $claim2 = $coordinator->claimForExecution($order->id, 'worker-beta', leaseSeconds: 300);
        $this->assertFalse($claim2['claimed']);
        $this->assertSame('active_lease_held_by_another_worker', $claim2['reason']);

        // Independent raw PDO session updates meli_orders.status -> 'cancelled' while Worker 1 runs!
        $pdo = $this->openRawPdo();
        $pdo->prepare('UPDATE meli_orders SET status = ? WHERE id = ?')->execute(['cancelled', $order->id]);

        // Dispatcher registers the material change -> bumps requested_revision WITHOUT enqueueing overlapping job.
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(0, DB::table('jobs')->count());

        // Worker 1 finishes its initial paid fingerprint -> completeExecution() ATOMICALLY inserts 1 follow-up job!
        $completion = $coordinator->completeExecution(
            $order->id,
            'worker-alpha',
            $claim1['fencing_token'],
            $claim1['starting_revision'],
            $claim1['fingerprint'],
        );
        $this->assertTrue($completion['needs_followup']);
        $this->assertTrue($completion['enqueued_atomically']);
        $this->assertFalse($completion['needs_after_commit_followup']);
        $this->assertSame(1, DB::table('jobs')->count());

        $row = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $row->state);
        $this->assertSame((int) DB::table('jobs')->value('id'), (int) $row->pending_job_id);
        $this->assertNotNull($row->dispatch_confirmed_at);
    }

    public function test_mariadb_h2_fencing_token_blocks_stale_owner_inside_innodb_transaction(): void
    {
        [$product] = $this->seedProduct('SKU-M-FENCE', 10);
        $this->seedLink($product, 1, 'MLM-M-FENCE', null, true);
        $order = $this->seedOrder(1, 880020, 'paid', [['item_id' => 'MLM-M-FENCE', 'key' => 'MLM-M-FENCE:', 'qty' => 4]]);

        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $service = app(InventoryMeliOrderReservationService::class);

        // Worker A claims token=1, then hangs past lease expiry (300s).
        $claimA = $coordinator->claimForExecution($order->id, 'worker-A', leaseSeconds: 300);
        $this->assertSame(1, $claimA['fencing_token']);

        Carbon::setTestNow(Carbon::now()->addSeconds(305));

        // Worker B reclaims lease with token=2.
        $claimB = $coordinator->claimForExecution($order->id, 'worker-B', leaseSeconds: 300);
        $this->assertTrue($claimB['claimed']);
        $this->assertSame(2, $claimB['fencing_token']);

        // Stale Worker A wakes up and attempts to mutate inventory with token=1 -> InnoDB TX aborts with StaleReconciliationLeaseException!
        try {
            $service->apply($order->fresh('items'), ['lease_owner' => 'worker-A', 'fencing_token' => 1]);
            $this->fail('Expected StaleReconciliationLeaseException for stale owner.');
        } catch (StaleReconciliationLeaseException) {
            // Expected
        }

        // Verify 0 mutations by Worker A!
        $this->assertSame(0, InventoryReservation::count());

        // Stale Worker A invoking failed() callback does NOT touch Worker B's active PROCESSING lease!
        (new ReconcileInventoryMeliOrderReservationsJob($order->id, 1))->failed(new RuntimeException('stale A timeout'));
        $state = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PROCESSING, $state->state);
        $this->assertSame('worker-B', $state->lease_owner);
        $this->assertSame(2, (int) $state->fencing_token);

        // Worker B legitimately reserves and completes!
        $service->apply($order->fresh('items'), ['lease_owner' => 'worker-B', 'fencing_token' => 2]);
        $coordinator->completeExecution($order->id, 'worker-B', 2, $claimB['starting_revision'], $claimB['fingerprint']);
        $this->assertSame(4, (int) InventoryReservation::active()->sum('quantity'));
    }

    public function test_mariadb_innodb_row_lock_prevents_concurrent_double_fulfillment_and_re_reservation(): void
    {
        [$product] = $this->seedProduct('SKU-M-FULFILL', 10);
        $this->seedLink($product, 1, 'MLM-M-FULFILL', null, true);
        $order = $this->seedOrder(1, 880002, 'paid', [['item_id' => 'MLM-M-FULFILL', 'key' => 'MLM-M-FULFILL:', 'qty' => 3]]);

        $service = app(InventoryMeliOrderReservationService::class);
        $service->apply($order);
        $this->assertSame(1, InventoryReservation::active()->count());

        // Hold an InnoDB FOR UPDATE lock on the meli_orders row from an independent second PDO session
        // to prove applyLine() genuinely acquires FOR UPDATE on meli_orders before deciding!
        DB::connection('r3_mariadb')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocker = $this->openRawPdo();
        $blocker->beginTransaction();
        $blocker->query("SELECT id FROM meli_orders WHERE id = {$order->id} FOR UPDATE")->fetchAll();

        try {
            $order->update(['shipping_status' => 'shipped']);
            $this->fail('Expected InnoDB lock wait timeout while second session holds FOR UPDATE on meli_orders.');
        } catch (QueryException $e) {
            $this->assertTrue(in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true));
        } finally {
            $blocker->rollBack();
        }

        // Update shipping_status to shipped from second session, then run apply() twice + status flap replay!
        $second = $this->openRawPdo();
        $second->prepare('UPDATE meli_orders SET shipping_status = ? WHERE id = ?')->execute(['shipped', $order->id]);

        $r1 = $service->apply($order->fresh('items'));
        $r2 = $service->apply($order->fresh('items'));
        $this->assertSame(InventoryMeliOrderReservationService::FULFILL, $r1[0]['action']);
        $this->assertSame('FULFILL_NOTHING_TO_DO', $r2[0]['action']);

        // Second session simulates transient shipment-lookup null flap!
        $second->prepare('UPDATE meli_orders SET shipping_status = NULL WHERE id = ?')->execute([$order->id]);
        $r3 = $service->apply($order->fresh('items'));
        $this->assertSame(InventoryMeliOrderReservationService::ALREADY_FULFILLED, $r3[0]['action']);

        // Strictly 1 physical outbound movement, exact stock = 7, 0 active reservations!
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::SALE)->count());
        $this->assertSame(7, app(InventoryStockService::class)->physicalStock($product));
        $this->assertSame(0, InventoryReservation::active()->count());
    }

    public function test_mariadb_h4_out_of_order_snapshot_and_pruned_job_self_healing_with_stopped_workers(): void
    {
        [$product] = $this->seedProduct('SKU-M-DEDUP', 15);
        $this->seedLink($product, 1, 'MLM-M-DEDUP', null, true);
        $order = $this->seedOrder(1, 880003, 'paid', [['item_id' => 'MLM-M-DEDUP', 'key' => 'MLM-M-DEDUP:', 'qty' => 2]]);
        $stalePaidSnapshot = clone $order;
        $stalePaidSnapshot->setRelation('items', $order->items);

        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);

        for ($i = 0; $i < 25; $i++) {
            $dispatcher->dispatchAfterCommit($order->fresh('items'));
        }
        $this->assertSame(1, DB::table('jobs')->count());

        // Simulate R2.1 pruning that job row -> next sync heals in O(1) via PK check!
        $prunedId = (int) DB::table('jobs')->value('id');
        DB::table('jobs')->where('id', $prunedId)->delete();
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotSame($prunedId, (int) DB::table('jobs')->value('id'));

        // Process the single job -> IDLE, reserved=2.
        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle(app(InventoryMeliOrderReservationService::class));
        $this->assertSame(2, (int) InventoryReservation::active()->sum('quantity'));

        // 25 more identical syncs after IDLE -> 0 new jobs!
        for ($i = 0; $i < 25; $i++) {
            $dispatcher->dispatchAfterCommit($order->fresh('items'));
        }
        $this->assertSame(0, DB::table('jobs')->count());

        // Second raw PDO session updates order to 'cancelled'; stale caller passes OLD in-memory 'paid' snapshot!
        $second = $this->openRawPdo();
        $second->prepare('UPDATE meli_orders SET status = ? WHERE id = ?')->execute(['cancelled', $order->id]);
        $cancelledFp = $coordinator->fingerprint($order->fresh('items'));

        $dec = $coordinator->registerOrderState($stalePaidSnapshot);
        $this->assertSame($cancelledFp, $dec['fingerprint']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_mariadb_h8_two_independent_connections_reject_out_of_order_http_responses_and_preflight_passes_innodb(): void
    {
        // 1. Verify production preflight fails when strict_atomic_queue=false, PASSES on MariaDB 10.5.29 InnoDB when true,
        //    and FAILS CLOSED if ANY transactional table (e.g. jobs) is non-InnoDB (MyISAM)!
        config()->set('queue.connections.database.after_commit', false);
        config()->set('queue.connections.database.retry_after', 7300);
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', false);
        $preflight = app(InventoryMeliOrderReconciliationPreflight::class);
        $this->assertFalse($preflight->check()['passed'], 'Preflight must FAIL when strict_atomic_queue=false.');

        config()->set('inventory.meli_order_reservations.strict_atomic_queue', true);
        $report = $preflight->check();
        $this->assertTrue($report['passed'], 'Preflight must PASS on MariaDB 10.5.29 InnoDB with valid strict config.');
        $this->assertSame(13, $report['runtime']['tables_verified']);

        // Non-transactional engine (MyISAM) on `jobs` MUST make preflight fail-closed!
        DB::connection('r3_mariadb')->statement('ALTER TABLE `jobs` ENGINE = MyISAM');
        try {
            $badEngine = $preflight->check();
            $this->assertFalse($badEngine['passed'], 'Preflight must FAIL when jobs uses MyISAM.');
            $engineCheck = collect($badEngine['checks'])->firstWhere('name', 'transactional_tables_use_innodb');
            $this->assertFalse($engineCheck['passed']);
            $this->assertArrayHasKey('jobs', $engineCheck['details']['non_innodb']);
        } finally {
            DB::connection('r3_mariadb')->statement('ALTER TABLE `jobs` ENGINE = InnoDB');
        }
        $this->assertTrue($preflight->check()['passed']);

        // 2. Two independent MariaDB connections with inverted HTTP response arrival order:
        //    Seed initial order, then Session 2 (independent PDO) commits newer cancelled + qty=4 (16:05:00Z).
        $order = $this->seedOrder(1, 880099, 'paid', [['item_id' => 'MLM-M-H8', 'key' => 'MLM-M-H8:', 'qty' => 1]]);
        $order->update(['raw' => ['date_created' => '2026-11-20T09:00:00Z', 'last_updated' => '2026-11-20T15:55:00Z']]);
        DB::table('jobs')->truncate();

        $session2 = $this->openRawPdo();
        $session2->beginTransaction();
        $newerRaw = json_encode([
            'id' => 880099,
            'status' => 'cancelled',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T16:05:00Z',
        ], JSON_THROW_ON_ERROR);
        $session2->prepare('UPDATE meli_orders SET status = ?, shipping_status = ?, raw = ? WHERE id = ?')
            ->execute(['cancelled', 'shipped', $newerRaw, $order->id]);
        $session2->prepare('UPDATE meli_order_items SET quantity = ? WHERE meli_order_id = ?')
            ->execute([4, $order->id]);
        $session2->commit();

        // Session 1 now persists an OLDER HTTP response (`paid`, qty=1, last_updated = 12:00:00-04:00 == 16:00:00Z < 16:05:00Z)!
        app(MeliOrderStoreService::class)->storeFromOrderApiResponse([
            'id' => 880099,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => '2026-11-20T12:00:00-04:00',
            'order_items' => [[
                'item' => ['id' => 'MLM-M-H8'],
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);

        // Under InnoDB FOR UPDATE, Session 1 observed Session 2's 16:05:00Z commit and REJECTED the stale overwrite!
        $fresh = $order->fresh('items');
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('shipped', $fresh->shipping_status);
        $this->assertSame(4, (int) $fresh->items->sole()->quantity);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_mariadb_h13_starvation_free_recovery_behind_110_healthy_pending_jobs(): void
    {
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', true);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);

        for ($i = 1; $i <= 110; $i++) {
            $ord = $this->seedOrder(1, 881000 + $i, 'paid', [['item_id' => 'MLM-M-H13', 'key' => 'MLM-M-H13:', 'qty' => 1]]);
            $dispatcher->dispatchAfterCommit($ord);
        }
        $this->assertSame(110, DB::table('jobs')->count());

        // Higher-ID stuck orders: 1 expired PROCESSING + 1 orphaned PENDING whose job vanished.
        $stuckProc = $this->seedOrder(1, 881501, 'paid', [['item_id' => 'MLM-M-H13', 'key' => 'MLM-M-H13:', 'qty' => 1]]);
        $coordinator->claimForExecution($stuckProc->id, 'dead-mariadb-worker', leaseSeconds: 300);

        $stuckPending = $this->seedOrder(1, 881502, 'paid', [['item_id' => 'MLM-M-H13', 'key' => 'MLM-M-H13:', 'qty' => 1]]);
        $dispatcher->dispatchAfterCommit($stuckPending);
        $vanishedId = (int) InventoryMeliOrderReconciliation::where('meli_order_id', $stuckPending->id)->value('pending_job_id');
        DB::table('jobs')->where('id', $vanishedId)->delete();

        Carbon::setTestNow(Carbon::now()->addSeconds(305));

        $res = $coordinator->recoverStuckReconciliations(limit: 10);
        $this->assertNull($res['blocked_reason']);
        $this->assertSame(2, $res['scanned']);
        $this->assertSame(1, $res['recovered_expired_processing']);
        $this->assertSame(1, $res['recovered_pending']);
        $this->assertSame(112, DB::table('jobs')->count());
    }

    public function test_mariadb_pr16_final_persist_remote_complete_payload_then_full_sync_materializes_qty_4_and_keeps_shipping_coherent(): void
    {
        config()->set('inventory.meli_order_reservations.strict_atomic_queue', true);
        [$product] = $this->seedProduct('SKU-M-PR16', 10);
        $this->seedLink($product, 1, 'MLM-M-PR16', null, true);

        $t1 = '2026-11-20T16:00:00Z';
        $t2 = '2026-11-20T16:05:00Z';

        $order = $this->seedOrder(1, 882001, 'paid', [['item_id' => 'MLM-M-PR16', 'key' => 'MLM-M-PR16:', 'qty' => 2]]);
        $order->update([
            'shipping_id' => '772001',
            'shipping_status' => 'ready_to_ship',
            'shipping_substatus' => 'ready_to_print',
            'shipping_raw' => ['id' => 772001, 'status' => 'ready_to_ship', 'last_updated' => $t1],
            'raw' => ['id' => 882001, 'status' => 'paid', 'date_created' => '2026-11-20T09:00:00Z', 'last_updated' => $t1],
        ]);

        $resService = app(InventoryMeliOrderReservationService::class);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($resService);
        $this->assertSame(2, (int) InventoryReservation::active()->sum('quantity'));
        DB::table('jobs')->truncate();

        $completeT2 = [
            'id' => 882001,
            'status' => 'paid',
            'date_created' => '2026-11-20T09:00:00Z',
            'last_updated' => $t2,
            'shipping' => ['id' => 772002],
            'order_items' => [[
                'item' => ['id' => 'MLM-M-PR16'],
                'quantity' => 4,
                'unit_price' => 100,
            ]],
        ];

        // 1. persistRemote() gets complete T2 (qty=4, new shipping_id=772002) WITHOUT matching shipment -> preserves T1 raw.last_updated & shipping_id=772001!
        app(MeliOrderCancellationService::class)->persistRemote($order, $completeT2, null);
        $order->refresh();
        $this->assertSame('772001', (string) $order->shipping_id);
        $this->assertSame(772001, (int) ($order->shipping_raw['id'] ?? 0));
        $this->assertSame($t1, $order->raw['last_updated']);

        // 2. Subsequent full sync with the EXACT same complete T2 (qty=4) materializes qty=4 and enqueues reconciliation!
        DB::table('jobs')->truncate();
        app(MeliOrderStoreService::class)->storeFromOrderApiResponse($completeT2);

        $order->refresh()->load('items');
        $this->assertSame(4, (int) $order->items->sole()->quantity);
        $this->assertSame($t2, $order->raw['last_updated']);
        $this->assertSame(1, DB::table('jobs')->count());

        DB::table('jobs')->truncate();
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($resService);
        $this->assertSame(4, (int) InventoryReservation::active()->sum('quantity'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function resetDatabase(): void
    {
        $pdo = new PDO(
            "mysql:host={$this->host};port={$this->port};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        $pdo->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function openRawPdo(): PDO
    {
        return new PDO(
            "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function runMigrationsOnMariaDb(): void
    {
        $schema = Schema::connection('r3_mariadb');
        $schema->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
        $schema->create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        $schema->create('llantas', fn (Blueprint $table) => $table->id());

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

        $schema->create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('topic')->nullable();
            $table->string('resource')->nullable();
            $table->string('status')->nullable();
            $table->string('display_id')->nullable();
            $table->string('shipping_id')->nullable();
            $table->string('shipping_status')->nullable();
            $table->string('shipping_substatus')->nullable();
            $table->json('shipping_raw')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        $schema->create('meli_order_items', function (Blueprint $table): void {
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
    private function seedProduct(string $sku, int $qty): array
    {
        $location = InventoryLocation::firstOrCreate(
            ['code' => 'MAIN-M'],
            ['name' => 'Main MariaDB', 'is_active' => true],
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

    private function seedLink(InventoryProduct $product, int $accountId, string $mlm, ?string $variation, bool $resEnabled): InventoryChannelLink
    {
        return InventoryChannelLink::create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $accountId,
            'external_listing_id' => $mlm,
            'external_variant_id' => $variation,
            'identity_key' => implode(':', [$accountId, $mlm, $variation ?? 'simple', $product->id, bin2hex(random_bytes(3))]),
            'is_active' => true,
            'stock_sync_enabled' => false,
            'order_reservation_enabled' => $resEnabled,
        ]);
    }

    /** @param list<array{item_id: string, variation?: ?string, key: ?string, qty: int}> $lines */
    private function seedOrder(int $accountId, int $remoteOrderId, string $status, array $lines): MeliOrder
    {
        $order = MeliOrder::create([
            'meli_account_id' => $accountId,
            'order_id' => $remoteOrderId,
            'status' => $status,
            'shipping_status' => 'ready_to_ship',
            'raw' => ['date_created' => '2026-11-20T10:00:00-06:00'],
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
