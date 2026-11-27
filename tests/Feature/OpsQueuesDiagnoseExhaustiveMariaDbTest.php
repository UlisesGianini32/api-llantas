<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\QueueRecovery\QueueOrderExhaustiveClassifier;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryManifest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * Isolated real MariaDB 10.5.29 / InnoDB / REPEATABLE-READ verification suite for R2-DIAG.
 *
 * Requires R2_MARIADB_TEST_HOST (opt-in so default SQLite runs never require external DBs).
 * Proves:
 * - MariaDB 10.5.x + InnoDB + REPEATABLE-READ (`@@tx_isolation`) compatibility.
 * - Exhaustive 7-category classification with R3 durable table AND on pre-R3 legacy schema.
 * - Pilot-equivalent 1,000-job / 736-order manifest on real MariaDB with streaming NDJSON + SHA-256.
 * - Strict read-only proof (0 SQL writes, identical table checksums before & after).
 * - Fail-closed invariant enforcement on MariaDB.
 */
class OpsQueuesDiagnoseExhaustiveMariaDbTest extends TestCase
{
    private ?string $tempDir = null;

    private string $host;

    private int $port;

    private string $database;

    private string $username;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $host = trim((string) (getenv('R2_MARIADB_TEST_HOST') ?: ''));
        if ($host === '') {
            $this->markTestSkipped('R2-DIAG MariaDB/InnoDB tests require R2_MARIADB_TEST_HOST (isolated test instance).');
        }

        Http::preventStrayRequests();

        $this->host = $host;
        $this->port = (int) (getenv('R2_MARIADB_TEST_PORT') ?: 3309);
        $this->database = (string) (getenv('R2_MARIADB_TEST_DIAG_DATABASE') ?: 'r2_diag_exhaustive_test');
        $this->username = (string) (getenv('R2_MARIADB_TEST_USERNAME') ?: 'root');
        $this->password = (string) (getenv('R2_MARIADB_TEST_PASSWORD') ?: 'r2_test_root_pass');

        $this->tempDir = storage_path('app/queue-recovery-diag-mariadb-'.bin2hex(random_bytes(5)));
        @mkdir($this->tempDir, 0750, true);

        $this->bootstrapIsolatedDatabase();

        Config::set('database.connections.r2_diag_mariadb', [
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
        Config::set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        DB::purge('r2_diag_mariadb');

        $this->createMariaDbSchema(includeR3Table: true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->tempDir.DIRECTORY_SEPARATOR.$f);
                }
            }
            @rmdir($this->tempDir);
        }
        DB::purge('r2_diag_mariadb');
        parent::tearDown();
    }

    public function test_mariadb_01_engine_version_innodb_repeatable_read_and_all_seven_categories_read_only(): void
    {
        $conn = DB::connection('r2_diag_mariadb');
        $version = (string) $conn->selectOne('SELECT VERSION() AS v')->v;
        $isolation = (string) $conn->selectOne('SELECT @@tx_isolation AS iso')->iso;
        $engine = (string) $conn->selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, 'meli_orders'],
        )->ENGINE;

        $this->assertStringContainsString('MariaDB', $version);
        $this->assertSame('REPEATABLE-READ', strtoupper($isolation));
        $this->assertSame('InnoDB', $engine);

        $this->seedBaseCatalog($conn);

        // 1) pending_human_review (missing order 101 + R3 pending revision on order 108)
        // 2) ambiguous_entities (order 102: kit)
        $this->insertOrder($conn, 102, 1, 'MDB-102', 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 102, 'MLM-KIT', 'MLM-KIT:', 'SKU-KIT', 1);

        // 3) reconstructible_candidates (order 103: cancelled with active reservation)
        $this->insertOrder($conn, 103, 1, 'MDB-103', 'cancelled', 'cancelled', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 103, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r103 = (int) $conn->table('inventory_reservations')->insertGetId([
            'source_type' => 'meli_order', 'source_id' => 103, 'inventory_product_id' => 10, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);
        $this->insertAlloc($conn, 1, 'MDB-103', 'MLM-ACTIVE:', 10, 'SIMPLE', $r103, 1, 'ACTIVE');

        // 4) consistent_no_action_needed (order 104: paid exact active reservation + IDLE R3)
        $this->insertOrder($conn, 104, 1, 'MDB-104', 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 104, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 2);
        $r104 = (int) $conn->table('inventory_reservations')->insertGetId([
            'source_type' => 'meli_order', 'source_id' => 104, 'inventory_product_id' => 10, 'quantity' => 2, 'status' => 'ACTIVE',
        ]);
        $this->insertAlloc($conn, 1, 'MDB-104', 'MLM-ACTIVE:', 10, 'SIMPLE', $r104, 2, 'ACTIVE');
        $conn->table('inventory_meli_order_reconciliations')->insert([
            'meli_order_id' => 104, 'meli_account_id' => 1, 'remote_order_id' => 'MDB-104',
            'state' => 'IDLE', 'requested_revision' => 1, 'processed_revision' => 1,
            'fencing_token' => 1, 'stock_sync_required' => false, 'stock_sync_completed_revision' => 1,
        ]);

        // 5) out_of_scope_no_reservation_link (order 105)
        $this->insertOrder($conn, 105, 1, 'MDB-105', 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 105, 'MLM-UNLINKED', 'MLM-UNLINKED:', 'SKU-NONE', 1);

        // 6) out_of_scope_pre_cutover_or_ignored_status (order 106)
        $this->insertOrder($conn, 106, 1, 'MDB-106', 'paid', 'delivered', '2026-08-01T10:00:00-06:00');
        $this->insertItem($conn, 106, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // 7) insufficient_evidence (order 107: paid with RELEASED allocation)
        $this->insertOrder($conn, 107, 1, 'MDB-107', 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 107, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAlloc($conn, 1, 'MDB-107', 'MLM-ACTIVE:', 10, 'SIMPLE', null, 0, 'RELEASED');

        // 8) R3 pending revision blocking consistency (order 108)
        $this->insertOrder($conn, 108, 1, 'MDB-108', 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 108, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r108 = (int) $conn->table('inventory_reservations')->insertGetId([
            'source_type' => 'meli_order', 'source_id' => 108, 'inventory_product_id' => 10, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);
        $this->insertAlloc($conn, 1, 'MDB-108', 'MLM-ACTIVE:', 10, 'SIMPLE', $r108, 1, 'ACTIVE');
        $conn->table('inventory_meli_order_reconciliations')->insert([
            'meli_order_id' => 108, 'meli_account_id' => 1, 'remote_order_id' => 'MDB-108',
            'state' => 'PENDING', 'requested_revision' => 2, 'processed_revision' => 1,
            'fencing_token' => 1, 'pending_job_id' => 777, 'dispatch_confirmed_at' => now()->utc()->toDateTimeString(),
        ]);

        $manifestPath = $this->writeV2Manifest('mdb_01.json', [
            ['id' => 1, 'entity_id' => 101],
            ['id' => 2, 'entity_id' => 102],
            ['id' => 3, 'entity_id' => 103],
            ['id' => 4, 'entity_id' => 104],
            ['id' => 5, 'entity_id' => 105],
            ['id' => 6, 'entity_id' => 106],
            ['id' => 7, 'entity_id' => 107],
            ['id' => 8, 'entity_id' => 108],
        ]);

        $beforeHashes = $this->hashMariaDbTables($conn, includeR3: true);
        $writeQueries = 0;
        $conn->listen(function ($q) use (&$writeQueries): void {
            $sql = ltrim(strtoupper((string) $q->sql));
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE')) {
                $writeQueries++;
            }
        });

        $ndjsonPath = $this->tempDir.DIRECTORY_SEPARATOR.'mdb_01.ndjson';
        $jsonPath = $this->tempDir.DIRECTORY_SEPARATOR.'mdb_01_summary.json';

        $code = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--manifest' => $manifestPath,
            '--connection' => 'r2_diag_mariadb',
            '--cutover' => '2026-10-01T00:00:00-06:00',
            '--ndjson-output' => $ndjsonPath,
            '--output' => $jsonPath,
        ]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(0, $writeQueries, 'MariaDB diagnostics MUST execute 0 write SQL statements!');
        $this->assertSame($beforeHashes, $this->hashMariaDbTables($conn, includeR3: true));

        $report = json_decode((string) file_get_contents($jsonPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(8, $report['invariants']['accounted_orders']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);
        $this->assertSame('r3_durable_enabled', $report['r3_compatibility']['schema_mode']);
        $this->assertSame(hash_file('sha256', $ndjsonPath), $report['exhaustive_classification']['ndjson_output']['sha256']);

        $partition = $report['exhaustive_classification']['primary_partition_counts'];
        $this->assertSame(2, $partition[QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW]); // 101 + 108
        $this->assertSame(1, $partition[QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES]); // 102
        $this->assertSame(1, $partition[QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES]); // 103
        $this->assertSame(1, $partition[QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED]); // 104
        $this->assertSame(1, $partition[QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK]); // 105
        $this->assertSame(1, $partition[QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED]); // 106
        $this->assertSame(1, $partition[QueueOrderExhaustiveClassifier::PRIMARY_INSUFFICIENT_EVIDENCE]); // 107
    }

    public function test_mariadb_02_pre_r3_legacy_schema_without_reconciliations_table(): void
    {
        Schema::connection('r2_diag_mariadb')->dropIfExists('inventory_meli_order_reconciliations');
        $conn = DB::connection('r2_diag_mariadb');
        $this->seedBaseCatalog($conn);

        $this->insertOrder($conn, 201, 1, 'MDB-201', 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
        $this->insertItem($conn, 201, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r201 = (int) $conn->table('inventory_reservations')->insertGetId([
            'source_type' => 'meli_order', 'source_id' => 201, 'inventory_product_id' => 10, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);
        $this->insertAlloc($conn, 1, 'MDB-201', 'MLM-ACTIVE:', 10, 'SIMPLE', $r201, 1, 'ACTIVE');

        $manifestPath = $this->writeV2Manifest('mdb_02_pre_r3.json', [['id' => 1, 'entity_id' => 201]]);
        $beforeHashes = $this->hashMariaDbTables($conn, includeR3: false);

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $report = $service->diagnose([
            'manifest_path' => $manifestPath,
            'connection' => 'r2_diag_mariadb',
            'cutover' => '2026-10-01T00:00:00-06:00',
        ]);

        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame('pre_r3_legacy', $report['r3_compatibility']['schema_mode']);
        $this->assertFalse($report['r3_compatibility']['absence_treated_as_reconciled']);
        $this->assertContains('r3_durable_state_table_absent', $report['exhaustive_classification']['entities'][0]['missing_evidence']);
        $this->assertSame($beforeHashes, $this->hashMariaDbTables($conn, includeR3: false));
    }

    public function test_mariadb_03_pilot_equivalent_1000_jobs_736_orders_and_fail_closed_invariants(): void
    {
        $conn = DB::connection('r2_diag_mariadb');
        $this->seedBaseCatalog($conn);

        $repetitionPlan = [
            ...array_fill(0, 645, 1),
            ...array_fill(0, 33, 2),
            ...array_fill(0, 2, 3),
            ...array_fill(0, 53, 5),
            ...array_fill(0, 3, 6),
        ];

        $orderRows = [];
        $itemRows = [];
        $manifestEntries = [];
        $jobId = 5100001;

        for ($i = 0; $i < 736; $i++) {
            $oid = 400001 + $i;
            $remote = 'MDB-PILOT-'.$oid;
            if ($i >= 5) {
                $status = ($i % 3 === 0) ? 'cancelled' : 'paid';
                $date = ($i % 4 === 0) ? '2026-08-15T10:00:00-06:00' : '2026-11-10T10:00:00-06:00';
                $orderRows[] = [
                    'id' => $oid, 'meli_account_id' => 1, 'order_id' => $remote, 'status' => $status,
                    'shipping_status' => $status === 'cancelled' ? 'cancelled' : 'ready_to_ship',
                    'raw' => json_encode(['date_created' => $date, 'status' => $status], JSON_THROW_ON_ERROR),
                    'created_at' => '2026-11-10 10:00:00', 'updated_at' => '2026-11-10 10:00:00',
                ];
                $itemId = ($i % 5 === 0) ? 'MLM-KIT' : (($i % 2 === 0) ? 'MLM-UNLINKED' : 'MLM-ACTIVE');
                $itemRows[] = [
                    'meli_order_id' => $oid, 'item_id' => $itemId, 'variation_id' => null,
                    'remote_line_key' => $itemId.':', 'sku' => 'SKU-SYN', 'quantity' => 1,
                ];
            }

            for ($r = 0; $r < $repetitionPlan[$i]; $r++) {
                $manifestEntries[] = ['id' => $jobId++, 'entity_id' => $oid];
            }
        }

        foreach (array_chunk($orderRows, 200) as $ch) {
            $conn->table('meli_orders')->insert($ch);
        }
        foreach (array_chunk($itemRows, 200) as $ch) {
            $conn->table('meli_order_items')->insert($ch);
        }

        $manifestPath = $this->writeV2Manifest('mdb_pilot_1000.json', $manifestEntries);
        $ndjsonPath = $this->tempDir.DIRECTORY_SEPARATOR.'mdb_pilot_1000.ndjson';
        $beforeHashes = $this->hashMariaDbTables($conn, includeR3: true);

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $report = $service->diagnose([
            'manifest_path' => $manifestPath,
            'connection' => 'r2_diag_mariadb',
            'cutover' => '2026-10-01T00:00:00-06:00',
            'ndjson_output_path' => $ndjsonPath,
        ]);

        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(736, $report['invariants']['accounted_orders']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame(736, $report['invariants']['sum_primary_partition_counts']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);
        $this->assertSame(1000, $report['invariants']['accounted_order_jobs']);
        $this->assertSame(5, $report['invariants']['missing_orders_accounted']);
        $this->assertSame(736, $report['exhaustive_classification']['ndjson_output']['records']);
        $this->assertSame(hash_file('sha256', $ndjsonPath), $report['exhaustive_classification']['ndjson_output']['sha256']);
        $this->assertSame($beforeHashes, $this->hashMariaDbTables($conn, includeR3: true));

        // Prove fail-closed invariant error on MariaDB
        $service->setInvariantCheckMutator(static function (array $inv): array {
            $inv['accounted_orders'] = 735;

            return $inv;
        });

        $this->expectException(QueueRecoveryException::class);
        $service->diagnose([
            'manifest_path' => $manifestPath,
            'connection' => 'r2_diag_mariadb',
            'cutover' => '2026-10-01T00:00:00-06:00',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function bootstrapIsolatedDatabase(): void
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->host, $this->port),
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('DROP DATABASE IF EXISTS `'.$this->database.'`');
        $pdo->exec('CREATE DATABASE `'.$this->database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private function createMariaDbSchema(bool $includeR3Table): void
    {
        $schema = Schema::connection('r2_diag_mariadb');

        $schema->create('jobs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('meli_accounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('nickname')->nullable();
        });
        $schema->create('meli_orders', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('order_id', 64)->nullable()->index();
            $table->string('status', 64)->nullable();
            $table->string('shipping_id', 64)->nullable();
            $table->string('shipping_status', 64)->nullable();
            $table->longText('shipping_raw')->nullable();
            $table->longText('raw')->nullable();
            $table->timestamps();
        });
        $schema->create('meli_order_items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('meli_order_id')->index();
            $table->string('item_id', 64)->nullable();
            $table->string('variation_id', 64)->nullable();
            $table->string('remote_line_key', 128)->nullable();
            $table->string('sku', 128)->nullable();
            $table->integer('quantity')->default(1);
        });
        $schema->create('inventory_products', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('sku', 128);
            $table->string('name', 191);
            $table->string('product_type', 32)->default('simple');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('inventory_kit_components', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('kit_product_id');
            $table->unsignedBigInteger('component_product_id');
            $table->integer('quantity')->default(1);
        });
        $schema->create('inventory_channel_links', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('channel', 32);
            $table->string('account_key', 64);
            $table->string('external_listing_id', 64);
            $table->string('external_variant_id', 64)->nullable();
            $table->unsignedBigInteger('inventory_product_id');
            $table->boolean('is_active')->default(true);
            $table->boolean('stock_sync_enabled')->default(false);
            $table->boolean('order_reservation_enabled')->default(false);
            $table->string('remote_user_product_id', 64)->nullable();
        });
        $schema->create('inventory_channel_order_allocations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('channel', 32);
            $table->string('account_key', 64);
            $table->string('remote_order_id', 64)->index();
            $table->string('remote_line_key', 191);
            $table->string('identity_hash', 64)->unique();
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->string('reservation_kind', 16)->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('status', 32)->default('PENDING');
            $table->string('diagnostic_code', 64)->nullable();
        });
        $schema->create('inventory_reservations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status', 32)->default('ACTIVE');
            $table->longText('metadata')->nullable();
        });
        $schema->create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('kit_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status', 32)->default('ACTIVE');
            $table->longText('metadata')->nullable();
        });
        $schema->create('inventory_movements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('inventory_product_id')->index();
            $table->string('type', 32);
            $table->integer('quantity');
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference', 191)->nullable();
            $table->text('notes')->nullable();
            $table->string('external_key', 191)->nullable()->index();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        if ($includeR3Table) {
            $schema->create('inventory_meli_order_reconciliations', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('meli_order_id')->unique();
                $table->unsignedBigInteger('meli_account_id')->nullable();
                $table->string('remote_order_id', 64)->nullable();
                $table->string('state', 24)->default('IDLE');
                $table->unsignedBigInteger('requested_revision')->default(0);
                $table->unsignedBigInteger('processed_revision')->default(0);
                $table->unsignedBigInteger('last_enqueued_revision')->default(0);
                $table->unsignedBigInteger('fencing_token')->default(0);
                $table->unsignedBigInteger('pending_job_id')->nullable();
                $table->timestamp('dispatch_confirmed_at')->nullable();
                $table->boolean('stock_sync_required')->default(false);
                $table->unsignedBigInteger('stock_sync_completed_revision')->default(0);
                $table->timestamp('lease_expires_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('last_reconciled_at')->nullable();
            });
        }
    }

    private function seedBaseCatalog($conn): void
    {
        $conn->table('meli_accounts')->insert(['id' => 1, 'nickname' => 'MDB-ACC']);
        $conn->table('inventory_products')->insert([
            ['id' => 10, 'sku' => 'SKU-ACTIVE', 'name' => 'Simple', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 11, 'sku' => 'SKU-KIT', 'name' => 'Kit', 'product_type' => 'kit', 'is_active' => 1],
        ]);
        $conn->table('inventory_channel_links')->insert([
            ['id' => 1, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-ACTIVE', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'stock_sync_enabled' => 1, 'order_reservation_enabled' => 1],
            ['id' => 2, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-KIT', 'external_variant_id' => null, 'inventory_product_id' => 11, 'is_active' => 1, 'stock_sync_enabled' => 1, 'order_reservation_enabled' => 1],
        ]);
    }

    private function insertOrder($conn, int $id, int $accountId, string $remote, string $status, string $shippingStatus, string $dateCreated): void
    {
        $conn->table('meli_orders')->insert([
            'id' => $id, 'meli_account_id' => $accountId, 'order_id' => $remote,
            'status' => $status, 'shipping_status' => $shippingStatus,
            'raw' => json_encode(['date_created' => $dateCreated, 'status' => $status], JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-10 10:00:00', 'updated_at' => '2026-11-10 10:00:00',
        ]);
    }

    private function insertItem($conn, int $orderId, string $itemId, ?string $lineKey, string $sku, int $qty): void
    {
        $conn->table('meli_order_items')->insert([
            'meli_order_id' => $orderId, 'item_id' => $itemId, 'variation_id' => null,
            'remote_line_key' => $lineKey, 'sku' => $sku, 'quantity' => $qty,
        ]);
    }

    private function insertAlloc($conn, int $accountId, string $remote, string $lineKey, int $productId, string $kind, ?int $resId, int $qty, string $status): void
    {
        $conn->table('inventory_channel_order_allocations')->insert([
            'channel' => 'mercado_libre',
            'account_key' => (string) $accountId,
            'remote_order_id' => $remote,
            'remote_line_key' => $lineKey,
            'identity_hash' => hash('sha256', implode("\0", ['mercado_libre', (string) $accountId, $remote, $lineKey])),
            'inventory_product_id' => $productId,
            'reservation_kind' => $kind,
            'reservation_id' => $resId,
            'quantity' => $qty,
            'status' => $status,
        ]);
    }

    /** @param list<array{id: int, entity_id: int}> $items */
    private function writeV2Manifest(string $name, array $items): string
    {
        $entries = [];
        $maxId = 0;
        foreach ($items as $it) {
            $jid = (int) $it['id'];
            $maxId = max($maxId, $jid);
            $entries[] = [
                'id' => $jid,
                'job_class' => QueuePayloadInspector::JOB_RESERVATIONS,
                'queue' => 'meli',
                'attempts' => 0,
                'available_at' => 1732550000,
                'entity_type' => 'meli_order',
                'entity_id' => (int) $it['entity_id'],
                'meli_item_id' => null,
                'created_at' => 1732550000,
                'created_at_iso' => '2026-11-10T10:00:00Z',
                'reserved_at' => null,
                'payload_hash' => hash('sha256', 'p-'.$jid),
                'row_sha256' => hash('sha256', 'r-'.$jid),
            ];
        }

        $path = $this->tempDir.DIRECTORY_SEPARATOR.$name;
        (new QueueRecoveryManifest)->writeAtomic($path, [
            'incident_id' => 'INC-MDB-DIAG',
            'generated_at' => '2026-11-26T00:00:00Z',
            'mode' => 'dry_run',
            'queue_connection' => 'database',
            'connection' => 'r2_diag_mariadb',
            'database' => $this->database,
            'table' => 'jobs',
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'start_id' => 0,
            'authorized_upper_bound' => $maxId,
            'last_scanned_id' => $maxId,
            'last_candidate_id' => $maxId,
            'next_start_id' => $maxId,
            'is_partial' => false,
            'stop_reason' => 'completed_upper_bound',
            'totals' => ['scanned' => count($entries), 'candidates' => count($entries)],
            'anomalies' => [],
            'entries' => $entries,
        ]);

        return $path;
    }

    /** @return array<string, string> */
    private function hashMariaDbTables($conn, bool $includeR3): array
    {
        $tables = [
            'jobs',
            'meli_orders',
            'meli_order_items',
            'inventory_channel_order_allocations',
            'inventory_reservations',
            'inventory_kit_reservations',
            'inventory_movements',
        ];
        if ($includeR3) {
            $tables[] = 'inventory_meli_order_reconciliations';
        }
        $hashes = [];
        foreach ($tables as $t) {
            $rows = $conn->table($t)->orderBy('id')->get()->all();
            $hashes[$t] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }
}
