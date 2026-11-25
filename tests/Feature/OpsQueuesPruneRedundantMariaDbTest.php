<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMeliBeautyScheduledPriceJob;
use App\Jobs\ProcessMeliMessageNotification;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueRecoveryBackupService;
use App\Services\System\QueueRecovery\QueueRecoveryDiskChecker;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Isolated MariaDB/InnoDB locking, concurrency, deadlock/lock-wait retry,
 * GET_LOCK mutual exclusion, and cross-database restore verification tests for R2.
 *
 * Requires R2_MARIADB_TEST_HOST (opt-in so default SQLite test runs never touch external DBs).
 */
class OpsQueuesPruneRedundantMariaDbTest extends TestCase
{
    private ?string $tempDir = null;

    private string $host;

    private int $port;

    private string $database;

    private string $restoreDatabase;

    private string $username;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $host = trim((string) (getenv('R2_MARIADB_TEST_HOST') ?: ''));
        if ($host === '') {
            $this->markTestSkipped('R2 MariaDB/InnoDB tests require R2_MARIADB_TEST_HOST (isolated test instance).');
        }

        Http::preventStrayRequests();

        $this->host = $host;
        $this->port = (int) (getenv('R2_MARIADB_TEST_PORT') ?: 3309);
        $this->database = (string) (getenv('R2_MARIADB_TEST_DATABASE') ?: 'r2_queue_recovery_test');
        $this->restoreDatabase = (string) (getenv('R2_MARIADB_TEST_RESTORE_DATABASE') ?: 'r2_queue_recovery_restore');
        $this->username = (string) (getenv('R2_MARIADB_TEST_USERNAME') ?: 'root');
        $this->password = (string) (getenv('R2_MARIADB_TEST_PASSWORD') ?: 'r2_test_root_pass');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r2_mariadb_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);

        $this->bootstrapIsolatedDatabases();

        config()->set('database.connections.r2_mariadb', [
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
        config()->set('database.connections.r2_mariadb_restore', [
            'driver' => 'mariadb',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->restoreDatabase,
            'username' => $this->username,
            'password' => $this->password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);
        config()->set('database.default', 'r2_mariadb');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'r2_mariadb');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        DB::purge('r2_mariadb');
        DB::purge('r2_mariadb_restore');

        $this->app->singleton(QueueRecoveryDiskChecker::class, fn () => new QueueRecoveryDiskChecker(
            static fn (string $path): int => 20 * 1024 * 1024 * 1024,
        ));
        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => [],
        ));
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            DB::purge('r2_mariadb');
            DB::purge('r2_mariadb_restore');
            $this->deleteDirectory($this->tempDir);
            $this->tempDir = null;
        }
        parent::tearDown();
    }

    public function test_mariadb_innodb_full_prune_and_cross_database_restore(): void
    {
        $conn = DB::connection('r2_mariadb');
        $engine = $conn->selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'jobs'", [$this->database]);
        $this->assertSame('InnoDB', $engine->ENGINE);

        $preservedId = $this->insertJob(new ProcessMeliMessageNotification(['resource' => 'msg-mariadb-1']));
        $targetIds = [
            $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(501)),
            $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(502)),
            $this->insertJob(new ProcessMeliBeautyScheduledPriceJob(12, 'MLM3850424391')),
        ];

        $originalTargets = $conn->table('jobs')->whereIn('id', $targetIds)->orderBy('id')->get()->keyBy('id');

        $manifestPath = $this->tmp('m_maria.json');
        $backupPath = $this->tmp('b_maria.ndjson');
        $auditPath = $this->tmp('a_maria.ndjson');

        $dryCode = Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            '--manifest' => $manifestPath,
            '--backup-out' => $backupPath,
            '--incident' => 'INC-R2-MARIADB',
        ]);
        $this->assertSame(0, $dryCode, Artisan::output());

        // Concurrently insert a new job ABOVE manifest end_id using a separate PDO connection.
        $secondPdo = $this->openRawPdo($this->database);
        $newJobPayload = $this->buildPayload(new ReconcileInventoryMeliOrderReservationsJob(999));
        $stmt = $secondPdo->prepare('INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES (?, ?, 0, NULL, ?, ?)');
        $stmt->execute(['meli', $newJobPayload, time(), time()]);
        $newConcurrentId = (int) $secondPdo->lastInsertId();

        $applyCode = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            '--manifest' => $manifestPath,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-MARIADB-01',
            '--chunk' => 2,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(0, $applyCode, Artisan::output());

        // Target jobs deleted; preserved module job AND concurrent post-bound job intact!
        $remaining = $conn->table('jobs')->orderBy('id')->pluck('id')->all();
        $this->assertSame([$preservedId, $newConcurrentId], $remaining);

        // Restore backed-up batch into isolated MariaDB database `r2_queue_recovery_restore`.
        /** @var QueueRecoveryBackupService $backupService */
        $backupService = $this->app->make(QueueRecoveryBackupService::class);
        $restored = $backupService->restoreToConnection($backupPath, 'r2_mariadb_restore', 'jobs');
        $this->assertSame(3, $restored['restored']);
        $this->assertSame(3, $restored['verified']);

        $restoredRows = DB::connection('r2_mariadb_restore')->table('jobs')->orderBy('id')->get()->keyBy('id');
        foreach ($targetIds as $tid) {
            $this->assertEquals(
                $backupService->normalizeRow($originalTargets->get($tid)),
                $backupService->normalizeRow($restoredRows->get($tid)),
            );
        }
    }

    public function test_mariadb_innodb_detects_concurrent_lock_wait_timeout_and_retries_bounded(): void
    {
        $id1 = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(601));
        $id2 = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(602));

        $manifestPath = $this->tmp('m_lockwait.json');
        $backupPath = $this->tmp('b_lockwait.ndjson');
        $auditPath = $this->tmp('a_lockwait.ndjson');

        $this->assertSame(0, Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--backup-out' => $backupPath,
            '--incident' => 'INC-R2-LOCKWAIT',
        ]));

        // Set a short InnoDB lock_wait_timeout on the prune connection so test runs fast (~1s).
        DB::connection('r2_mariadb')->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Hold an exclusive InnoDB row lock on $id1 from an independent second PDO connection!
        $blocker = $this->openRawPdo($this->database);
        $blocker->beginTransaction();
        $blocker->query("SELECT id FROM jobs WHERE id = {$id1} FOR UPDATE")->fetchAll();

        try {
            /** @var QueuePruneService $service */
            $service = $this->app->make(QueuePruneService::class);
            try {
                $service->apply([
                    'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                    'manifest_path' => $manifestPath,
                    'backup_path' => $backupPath,
                    'maintenance_window' => 'MW-MARIADB-LOCKWAIT',
                    'chunk' => 2,
                    'max_delete' => 10,
                    'sleep_ms' => 0,
                    'audit_log_path' => $auditPath,
                    'max_deadlock_retries' => 2,
                ]);
                $this->fail('Expected deadlock_retries_exceeded due to held InnoDB FOR UPDATE lock.');
            } catch (QueueRecoveryException $e) {
                $this->assertSame('deadlock_retries_exceeded', $e->reasonCode);
            }
        } finally {
            $blocker->rollBack();
        }

        // Verify retries were logged and BOTH rows were preserved intact (rolled back cleanly).
        $audit = (string) file_get_contents($auditPath);
        $this->assertStringContainsString('"event":"batch_deadlock_retry"', $audit);
        $this->assertSame(2, DB::connection('r2_mariadb')->table('jobs')->count());
        $this->assertSame([$id1, $id2], DB::connection('r2_mariadb')->table('jobs')->orderBy('id')->pluck('id')->all());
    }

    public function test_mariadb_innodb_aborts_and_rolls_back_on_concurrent_update_from_second_session(): void
    {
        $id1 = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(701));
        $id2 = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(702));

        $manifestPath = $this->tmp('m_conc.json');
        $backupPath = $this->tmp('b_conc.ndjson');
        $auditPath = $this->tmp('a_conc.ndjson');

        $this->assertSame(0, Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--backup-out' => $backupPath,
            '--incident' => 'INC-R2-CONC',
        ]));

        // Second session concurrently reserves job #2 right before apply!
        $secondPdo = $this->openRawPdo($this->database);
        $secondPdo->prepare('UPDATE jobs SET reserved_at = ? WHERE id = ?')->execute([time(), $id2]);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-MARIADB-CONC',
            '--chunk' => 2,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('reserved_jobs_detected', Artisan::output());
        $this->assertSame(2, DB::connection('r2_mariadb')->table('jobs')->count());
    }

    public function test_mariadb_get_lock_prevents_concurrent_execution_across_two_sessions(): void
    {
        $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(801));

        $manifestPath = $this->tmp('m_getlock.json');
        $backupPath = $this->tmp('b_getlock.ndjson');
        $auditPath = $this->tmp('a_getlock.ndjson');

        $this->assertSame(0, Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--backup-out' => $backupPath,
            '--incident' => 'INC-R2-GETLOCK',
        ]));

        // Acquire MariaDB GET_LOCK directly on a second independent PDO session (simulating another host/container).
        $scope = implode('|', ['r2_mariadb', $this->database, 'jobs']);
        $dbLockName = 'ops_qrec_'.substr(hash('sha256', $scope), 0, 48);

        $secondPdo = $this->openRawPdo($this->database);
        $stmt = $secondPdo->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        $stmt->execute([$dbLockName]);
        $this->assertSame(1, (int) $stmt->fetchColumn());

        try {
            $code = Artisan::call('ops:queues-prune-redundant', [
                '--apply' => true,
                '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
                '--manifest' => $manifestPath,
                '--backup' => $backupPath,
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-MARIADB-GETLOCK',
                '--sleep-ms' => 0,
            ]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('already_running', Artisan::output());
            $this->assertSame(1, DB::connection('r2_mariadb')->table('jobs')->count());
        } finally {
            $rel = $secondPdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$dbLockName]);
        }
    }

    public function test_mariadb_crash_between_commit_and_checkpoint_reconciles_on_resume(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(900 + $i));
        }

        $manifestPath = $this->tmp('m_crash.json');
        $backupPath = $this->tmp('b_crash.ndjson');
        $auditPath = $this->tmp('a_crash.ndjson');

        $this->assertSame(0, Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--backup-out' => $backupPath,
            '--incident' => 'INC-R2-CRASH',
        ]));

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $service->setAfterCommitBeforeCheckpointHook(static function (int $batchSeq): void {
            if ($batchSeq === 1) {
                throw new RuntimeException('MariaDB simulated crash after COMMIT');
            }
        });

        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-MARIADB-CRASH',
                'chunk' => 2,
                'max_delete' => 10,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected simulated post-commit crash.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('MariaDB simulated crash', $e->getMessage());
        }

        $this->assertSame(2, DB::connection('r2_mariadb')->table('jobs')->count());

        $service->setAfterCommitBeforeCheckpointHook(null);
        $resumed = $service->apply([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'backup_path' => $backupPath,
            'maintenance_window' => 'MW-MARIADB-CRASH',
            'chunk' => 2,
            'max_delete' => 10,
            'sleep_ms' => 0,
            'audit_log_path' => $auditPath,
            'resume' => true,
        ]);

        $this->assertSame('completed', $resumed['status']);
        $this->assertSame(2, $resumed['reconciled_after_crash']);
        $this->assertSame(2, $resumed['deleted_this_run']);
        $this->assertSame(4, $resumed['total_deleted']);
        $this->assertSame(0, DB::connection('r2_mariadb')->table('jobs')->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function bootstrapIsolatedDatabases(): void
    {
        $pdo = new PDO(
            "mysql:host={$this->host};port={$this->port};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        foreach ([$this->database, $this->restoreDatabase] as $dbName) {
            $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("
                CREATE TABLE `{$dbName}`.`jobs` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `queue` VARCHAR(255) NOT NULL,
                    `payload` LONGTEXT NOT NULL,
                    `attempts` TINYINT UNSIGNED NOT NULL,
                    `reserved_at` INT UNSIGNED NULL,
                    `available_at` INT UNSIGNED NOT NULL,
                    `created_at` INT UNSIGNED NOT NULL,
                    INDEX `jobs_queue_index` (`queue`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    private function openRawPdo(string $dbName): PDO
    {
        return new PDO(
            "mysql:host={$this->host};port={$this->port};dbname={$dbName};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function insertJob(object $job, string $queue = 'meli'): int
    {
        return (int) DB::connection('r2_mariadb')->table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => $this->buildPayload($job),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    private function buildPayload(object $job): string
    {
        return json_encode([
            'uuid' => bin2hex(random_bytes(8)),
            'displayName' => $job::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'data' => [
                'commandName' => $job::class,
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function tmp(string $name): string
    {
        return $this->tempDir.DIRECTORY_SEPARATOR.$name;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$item;
                is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
