<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use App\Services\InventoryMeliOrderReservationDispatcher;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Real MariaDB 10.5.29 / InnoDB (REPEATABLE-READ) concurrency, migration, locking,
 * lease exclusion, and double-deduction prevention tests for R3.
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
        Carbon::setTestNow();
        if ($this->bootstrapped) {
            DB::purge('r3_mariadb');
            $this->bootstrapped = false;
        }
        parent::tearDown();
    }

    public function test_mariadb_migration_is_additive_idempotent_and_uses_innodb_indexes(): void
    {
        $conn = DB::connection('r3_mariadb');
        $tableInfo = $conn->selectOne(
            "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'inventory_meli_order_reconciliations'",
            [$this->database],
        );
        $this->assertSame('InnoDB', $tableInfo->ENGINE);

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
    }

    public function test_mariadb_concurrent_session_lease_exclusion_and_dirty_revision_followup(): void
    {
        [$product] = $this->seedProduct('SKU-M-LEASE', 10);
        $this->seedLink($product, 1, 'MLM-M-LEASE', null, true);
        $order = $this->seedOrder(1, 880001, 'paid', [['item_id' => 'MLM-M-LEASE', 'key' => 'MLM-M-LEASE:', 'qty' => 2]]);

        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);

        // Worker 1 claims active lease on MariaDB InnoDB.
        $claim1 = $coordinator->claimForExecution($order->id, 'worker-alpha', 300);
        $this->assertTrue($claim1['claimed']);

        // Worker 2 cannot overlap while Worker 1's lease is active.
        $claim2 = $coordinator->claimForExecution($order->id, 'worker-beta', 300);
        $this->assertFalse($claim2['claimed']);
        $this->assertSame('active_lease_held_by_another_worker', $claim2['reason']);

        // Independent raw PDO session updates meli_orders.status -> 'cancelled' while Worker 1 runs!
        $pdo = $this->openRawPdo();
        $pdo->prepare('UPDATE meli_orders SET status = ? WHERE id = ?')->execute(['cancelled', $order->id]);

        // Dispatcher registers the material change -> bumps requested_revision WITHOUT enqueueing overlapping job.
        $dispatcher->dispatchAfterCommit($order->fresh('items'));
        $this->assertSame(0, DB::table('jobs')->count());

        // Worker 1 finishes its initial paid fingerprint -> coordinator detects revision drift and demands 1 follow-up!
        $completion = $coordinator->completeExecution(
            $order->id,
            'worker-alpha',
            $claim1['starting_revision'],
            'old-paid-fingerprint',
        );
        $this->assertTrue($completion['needs_followup']);

        $row = InventoryMeliOrderReconciliation::where('meli_order_id', $order->id)->firstOrFail();
        $this->assertSame(InventoryMeliOrderReconciliation::STATE_PENDING, $row->state);
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

    public function test_mariadb_repeated_syncs_with_stopped_workers_create_strictly_one_job_and_converge(): void
    {
        [$product] = $this->seedProduct('SKU-M-DEDUP', 15);
        $this->seedLink($product, 1, 'MLM-M-DEDUP', null, true);
        $order = $this->seedOrder(1, 880003, 'paid', [['item_id' => 'MLM-M-DEDUP', 'key' => 'MLM-M-DEDUP:', 'qty' => 2]]);

        $dispatcher = app(InventoryMeliOrderReservationDispatcher::class);
        for ($i = 0; $i < 25; $i++) {
            $dispatcher->dispatchAfterCommit($order->fresh('items'));
        }
        $this->assertSame(1, DB::table('jobs')->count());

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
            $table->string('status')->nullable();
            $table->string('shipping_status')->nullable();
            $table->json('shipping_raw')->nullable();
            $table->json('raw')->nullable();
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
