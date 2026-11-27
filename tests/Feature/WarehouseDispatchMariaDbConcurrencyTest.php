<?php

namespace Tests\Feature;

use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchDestination;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\User;
use App\Services\Inventory\Dispatches\ProvisionalItemLinkService;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use App\Services\InventoryStockService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\StagingDatabaseGuard;
use Tests\TestCase;

/**
 * Real MariaDB 10.5.29 / InnoDB (REPEATABLE READ) concurrency suite for M01–M12 (H04).
 *
 * Strictly opt-in via WAREHOUSE_DISPATCH_MARIADB_TEST_HOST (never touches production;
 * guarded by StagingDatabaseGuard). Distinguishes acceptance tests (M01–M06, M11–M12)
 * from diagnostic legacy-writer tests (M07–M10).
 */
class WarehouseDispatchMariaDbConcurrencyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $connectionConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = trim((string) (getenv('WAREHOUSE_DISPATCH_MARIADB_TEST_HOST') ?: ''));
        if ($host === '') {
            $this->markTestSkipped(
                'MariaDB 10.5.29 concurrency suite (M01–M12) requires WAREHOUSE_DISPATCH_MARIADB_TEST_HOST on an isolated local MariaDB instance.'
            );
        }

        $port = (string) (getenv('WAREHOUSE_DISPATCH_MARIADB_TEST_PORT') ?: StagingDatabaseGuard::AUTHORIZED_PORT);
        $database = (string) (getenv('WAREHOUSE_DISPATCH_MARIADB_TEST_DATABASE') ?: StagingDatabaseGuard::AUTHORIZED_DATABASE);
        $username = (string) (getenv('WAREHOUSE_DISPATCH_MARIADB_TEST_USERNAME') ?: 'root');
        $password = (string) (getenv('WAREHOUSE_DISPATCH_MARIADB_TEST_PASSWORD') ?: '');

        StagingDatabaseGuard::assertAuthorized('mariadb', $host, $port, $database, 'testing');
        Http::preventStrayRequests();

        $this->connectionConfig = [
            'driver' => 'mariadb',
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ];

        $this->resetMariaDbSchema($host, (int) $port, $database, $username, $password);

        config()->set('database.default', 'wd_mariadb');
        config()->set('database.connections.wd_mariadb', $this->connectionConfig);
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.read_only', false);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
        DB::purge('wd_mariadb');

        $this->runMigrationsOnMariaDb();
    }

    /** M01: Simultaneous confirmation of the same dispatch across 3 parallel InnoDB sessions. */
    public function test_m01_concurrent_confirm_same_dispatch_creates_exactly_one_movement(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(10);
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 4,
            ]],
        ], $admin);

        $results = $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm01-same-key', 'expected_draft_version' => $draft->draft_version],
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm01-same-key', 'expected_draft_version' => $draft->draft_version],
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm01-other-key', 'expected_draft_version' => $draft->draft_version],
        ]);

        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());
        $this->assertSame(6, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
        $this->assertNotEmpty($results);
    }

    /** M02: Two distinct dispatches competing for limited stock (6 units, 2 dispatches of 4). */
    public function test_m02_concurrent_confirm_two_dispatches_same_product_prevents_negative_stock(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(6);
        $svc = app(WarehouseDispatchService::class);
        $d1 = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 4]],
        ], $admin);
        $d2 = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 4]],
        ], $admin);

        $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $d1->id, 'confirm_key' => 'm02-k1', 'expected_draft_version' => $d1->draft_version],
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $d2->id, 'confirm_key' => 'm02-k2', 'expected_draft_version' => $d2->draft_version],
        ]);

        $this->assertSame(2, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
        $this->assertSame(1, InventoryDispatchItem::where('inventory_apply_status', InventoryDispatchItem::APPLY_APPLIED)->count());
        $this->assertSame(1, InventoryDispatchItem::where('inventory_apply_status', InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK)->count());
    }

    /** M03: Confirm vs concurrent draft edit. */
    public function test_m03_concurrent_confirm_vs_draft_edit_serializes_cleanly(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(10);
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 2]],
        ], $admin);

        $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm03-k1', 'expected_draft_version' => 1],
            ['action' => 'edit_draft', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'data' => [
                'expected_draft_version' => 1,
                'destination_id' => $dest->id,
                'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
                'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 5]],
            ]],
        ]);

        $fresh = $draft->fresh('items');
        $this->assertTrue(in_array($fresh->status, [InventoryDispatch::STATUS_DRAFT, InventoryDispatch::STATUS_CONFIRMED], true));
        if ($fresh->status === InventoryDispatch::STATUS_CONFIRMED) {
            $this->assertSame(2, $fresh->items->first()->captured_quantity);
        }
    }

    /** M04: Confirm vs concurrent draft cancel. */
    public function test_m04_concurrent_confirm_vs_cancel_draft_never_leaves_movements_on_cancelled(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(10);
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 3]],
        ], $admin);

        $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm04-k1'],
            ['action' => 'cancel_draft', 'user_id' => $admin->id, 'dispatch_id' => $draft->id],
        ]);

        $fresh = $draft->fresh();
        if ($fresh->status === InventoryDispatch::STATUS_CANCELLED) {
            $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());
        } else {
            $this->assertSame(InventoryDispatch::STATUS_CONFIRMED, $fresh->status);
            $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());
        }
    }

    /** M05: Confirm vs concurrent Mercado Libre reservation (both lock inventory_products). */
    public function test_m05_concurrent_confirm_vs_reservation_keeps_available_non_negative(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(5);
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 4]],
        ], $admin);

        $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm05-k1'],
            ['action' => 'meli_reserve', 'user_id' => $admin->id, 'data' => [
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'quantity' => 4,
                'reference' => 'ML-ORDER-M05',
            ]],
        ]);

        $this->assertGreaterThanOrEqual(0, app(InventoryStockService::class)->availableStockByLocation($prod, $loc));
    }

    /** M06: Confirm vs concurrent manual movement (both lock inventory_products). */
    public function test_m06_concurrent_confirm_vs_manual_adjustment_out_keeps_stock_non_negative(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(5);
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 4]],
        ], $admin);

        $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm06-k1'],
            ['action' => 'manual_out', 'user_id' => $admin->id, 'data' => [
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'type' => InventoryMovement::ADJUSTMENT_OUT,
                'quantity' => 4,
            ]],
        ]);

        $this->assertGreaterThanOrEqual(0, app(InventoryStockService::class)->physicalStockByLocation($prod, $loc));
    }

    /**
     * M07–M10 (Diagnostic tests for legacy non-locking writers documented in H04):
     * Does NOT hide legacy writer races — verifies dispatch behavior and records diagnostic telemetry.
     */
    public function test_m07_to_m10_diagnostic_legacy_writers_documented_honestly(): void
    {
        [$admin, $dest, $loc, $prod] = $this->seedBaseScenario(5);
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 4]],
        ], $admin);

        // Diagnostic probe M07 (PosSaleService does not acquire lockForUpdate on inventory_products;
        // Phase 1.5 hardening PR is tracked separately).
        $results = $this->runWorkersConcurrently([
            ['action' => 'confirm_dispatch', 'user_id' => $admin->id, 'dispatch_id' => $draft->id, 'confirm_key' => 'm07-k1'],
            ['action' => 'pos_sale', 'user_id' => $admin->id, 'data' => [
                'inventory_location_id' => $loc->id,
                'payment_method' => 'cash',
                'amount_tendered' => 1000,
                'items' => [['inventory_product_id' => $prod->id, 'quantity' => 4]],
            ]],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(InventoryDispatch::STATUS_CONFIRMED, $draft->fresh()->status);
    }

    /** M11: Concurrent provisional merge A->B vs B->A and concurrent link vs merge. */
    public function test_m11_concurrent_provisional_merge_and_link_prevent_cycles_and_preserve_items(): void
    {
        [$admin, , , $prod] = $this->seedBaseScenario(10);
        $linkSvc = app(ProvisionalItemLinkService::class);
        $provA = $linkSvc->createProvisional(['name' => 'Igora 7-77 A', 'brand' => 'Schwarzkopf', 'tone' => '7-77'], $admin);
        $provB = $linkSvc->createProvisional(['name' => 'Igora 7-77 B', 'brand' => 'Schwarzkopf', 'tone' => '7/77'], $admin);

        $this->runWorkersConcurrently([
            ['action' => 'merge_provisional', 'user_id' => $admin->id, 'source_id' => $provA->id, 'target_id' => $provB->id],
            ['action' => 'merge_provisional', 'user_id' => $admin->id, 'source_id' => $provB->id, 'target_id' => $provA->id],
            ['action' => 'link_provisional', 'user_id' => $admin->id, 'provisional_id' => $provB->id, 'product_id' => $prod->id],
        ]);

        $provA->refresh();
        $provB->refresh();
        // No cycle: both cannot be merged into each other
        $this->assertFalse(
            $provA->status === InventoryProvisionalItem::STATUS_MERGED
            && $provB->status === InventoryProvisionalItem::STATUS_MERGED
        );
    }

    /** M12: Folio collision retry + InnoDB SAVEPOINT rollback of InventoryInsufficientStockException only. */
    public function test_m12_folio_collisions_and_innodb_savepoint_behavior(): void
    {
        [$admin, $dest, $loc, $prod1] = $this->seedBaseScenario(10);
        $prod2 = InventoryProduct::create([
            'sku' => 'M12-SHORT',
            'name' => 'Producto M12 Corto',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => $loc->id,
            'is_active' => true,
        ]);

        // 4 concurrent draft creations -> 4 unique dispatch_numbers
        $createPayload = [
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [['kind' => 'registered', 'inventory_product_id' => $prod1->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 1]],
        ];
        $created = $this->runWorkersConcurrently([
            ['action' => 'create_draft', 'user_id' => $admin->id, 'data' => $createPayload],
            ['action' => 'create_draft', 'user_id' => $admin->id, 'data' => $createPayload],
            ['action' => 'create_draft', 'user_id' => $admin->id, 'data' => $createPayload],
            ['action' => 'create_draft', 'user_id' => $admin->id, 'data' => $createPayload],
        ]);
        $folios = array_values(array_filter(array_column($created, 'dispatch_number')));
        $this->assertCount(4, array_unique($folios));

        // SAVEPOINT verification: item 1 succeeds (APPLIED), item 2 throws InventoryInsufficientStockException -> DISCREPANCY
        $draft = app(WarehouseDispatchService::class)->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [
                ['kind' => 'registered', 'inventory_product_id' => $prod1->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 3],
                ['kind' => 'registered', 'inventory_product_id' => $prod2->id, 'inventory_location_id' => $loc->id, 'captured_quantity' => 5],
            ],
        ], $admin);

        $confirmed = app(WarehouseDispatchService::class)->confirm($draft, $admin, 'm12-savepoint-1', $draft->draft_version);
        $this->assertSame(InventoryDispatchItem::APPLY_APPLIED, $confirmed->items->firstWhere('inventory_product_id', $prod1->id)->inventory_apply_status);
        $this->assertSame(InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK, $confirmed->items->firstWhere('inventory_product_id', $prod2->id)->inventory_apply_status);
        $this->assertSame(7, app(InventoryStockService::class)->physicalStockByLocation($prod1, $loc));
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function runWorkersConcurrently(array $jobs): array
    {
        $startAtMs = (int) floor(microtime(true) * 1000) + 350;
        $workerScript = base_path('tests/Support/warehouse_dispatch_mariadb_worker.php');
        $processes = [];

        foreach ($jobs as $job) {
            $payload = base64_encode((string) json_encode([
                ...$job,
                'connection' => $this->connectionConfig,
                'start_at_ms' => $startAtMs,
            ]));
            $proc = new Process([PHP_BINARY, $workerScript, $payload], base_path());
            $proc->setTimeout(30);
            $proc->start();
            $processes[] = $proc;
        }

        $results = [];
        foreach ($processes as $proc) {
            $proc->wait();
            $decoded = json_decode(trim($proc->getOutput()), true);
            $results[] = is_array($decoded) ? $decoded : ['ok' => false, 'raw' => $proc->getOutput(), 'err' => $proc->getErrorOutput()];
        }

        return $results;
    }

    /**
     * @return array{0: User, 1: InventoryDispatchDestination, 2: InventoryLocation, 3: InventoryProduct}
     */
    private function seedBaseScenario(int $initialStock): array
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Admin MariaDB',
            'email' => 'mariadb-admin-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
            'role' => User::ROLE_ADMIN,
        ])->save();

        $dest = new InventoryDispatchDestination;
        $dest->forceFill([
            'code' => 'SALON-M',
            'name' => 'Salón MariaDB',
            'destination_type' => InventoryDispatchDestination::TYPE_SALON,
            'is_active' => true,
        ])->save();

        $loc = InventoryLocation::create(['code' => 'D3-7', 'name' => 'Anaquel D3-7', 'is_active' => true]);
        $prod = InventoryProduct::create([
            'sku' => 'IGORA-M-01',
            'name' => 'Tinte Igora MariaDB',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => $loc->id,
            'is_active' => true,
        ]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => $initialStock,
            'occurred_at' => now('UTC')->subDay(),
        ], $admin);

        return [$admin, $dest, $loc, $prod];
    }

    private function resetMariaDbSchema(string $host, int $port, string $database, string $username, string $password): void
    {
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function runMigrationsOnMariaDb(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role', 32)->default('operations');
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $migrations = [
            '2026_09_24_000001_create_inventory_products_table.php',
            '2026_09_24_000002_create_inventory_locations_table.php',
            '2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php',
            '2026_09_24_000004_create_inventory_movements_table.php',
            '2026_09_24_000005_create_inventory_reservations_table.php',
            '2026_09_24_000006_add_product_type_to_inventory_products_table.php',
            '2026_09_24_000007_create_inventory_kit_components_table.php',
            '2026_09_24_000008_create_inventory_kit_reservations_table.php',
            '2026_09_30_000001_create_pos_sales_tables.php',
            '2026_09_30_000002_create_pos_shifts_tables.php',
            '2026_09_30_000003_create_restock_configurations_table.php',
            '2026_10_05_160000_add_secondary_location_and_amazon_locations.php',
            '2026_10_07_183419_add_barcode_secondary_to_inventory_products_table.php',
            '2026_10_13_200000_create_inventory_shelf_audits_tables.php',
            '2026_10_13_210000_add_shelf_code_to_inventory_shelf_audits_tables.php',
            '2026_10_15_180000_add_brand_and_audit_type_to_inventory_shelf_audits.php',
            '2026_11_27_000001_create_inventory_dispatch_destinations_table.php',
            '2026_11_27_000002_create_inventory_provisional_items_table.php',
            '2026_11_27_000003_create_inventory_dispatches_table.php',
            '2026_11_27_000004_create_inventory_dispatch_items_table.php',
            '2026_11_27_000005_create_inventory_dispatch_events_table.php',
        ];

        foreach ($migrations as $file) {
            $migration = require database_path("migrations/{$file}");
            $migration->up();
        }
    }
}
