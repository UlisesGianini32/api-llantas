<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMeliBeautyScheduledPriceJob;
use App\Jobs\ProcessMeliMessageNotification;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueueQuarantinePolicy;
use App\Services\System\QueueRecovery\QueueQuarantineService;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Isolated real MariaDB 10.5.29 / InnoDB concurrency, SELECT ... FOR UPDATE, GET_LOCK mutual exclusion,
 * crash recovery, and full quarantine apply + restore tests for R4.2.
 *
 * Opt-in via R4_MARIADB_TEST_HOST (or R2_MARIADB_TEST_HOST) so default SQLite test runs never touch external DBs.
 */
class OpsQueuesQuarantineMariaDbTest extends TestCase
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

        $host = trim((string) (getenv('R4_MARIADB_TEST_HOST') ?: getenv('R2_MARIADB_TEST_HOST') ?: ''));
        if ($host === '') {
            $this->markTestSkipped('R4.2 MariaDB/InnoDB tests require R4_MARIADB_TEST_HOST or R2_MARIADB_TEST_HOST.');
        }

        Http::preventStrayRequests();

        $this->host = $host;
        $this->port = (int) (getenv('R4_MARIADB_TEST_PORT') ?: getenv('R2_MARIADB_TEST_PORT') ?: 3309);
        $this->database = (string) (getenv('R4_MARIADB_TEST_DATABASE') ?: 'r4_2_quarantine_test');
        $this->username = (string) (getenv('R4_MARIADB_TEST_USERNAME') ?: getenv('R2_MARIADB_TEST_USERNAME') ?: 'root');
        $this->password = (string) (getenv('R4_MARIADB_TEST_PASSWORD') ?: getenv('R2_MARIADB_TEST_PASSWORD') ?: 'r2_test_root_pass');

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r4_2_mariadb_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);

        $this->bootstrapIsolatedDatabase();

        config()->set('database.connections.r4_mariadb', [
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
        config()->set('database.default', 'r4_mariadb');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'r4_mariadb');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        DB::purge('r4_mariadb');

        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true],
        ));
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            DB::purge('r4_mariadb');
            $this->deleteDirectory($this->tempDir);
            $this->tempDir = null;
        }
        parent::tearDown();
    }

    public function test_32_mariadb_innodb_quarantine_concurrency_get_lock_crash_recovery_and_restore(): void
    {
        $conn = DB::connection('r4_mariadb');
        $engine = $conn->selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'jobs'", [$this->database]);
        $this->assertSame('InnoDB', $engine->ENGINE);

        $txIso = $conn->selectOne('SELECT @@tx_isolation AS iso');
        $this->assertSame('REPEATABLE-READ', $txIso->iso);

        $preservedId = $this->insertJob(new ProcessMeliMessageNotification(['resource' => 'msg-maria-1']));
        $resId1 = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(5001), attempts: 0);
        $resId2 = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(5002), attempts: 2);
        $resConcurrentReservedId = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(5003), attempts: 0);
        $resConcurrentModifiedId = $this->insertJob(new ReconcileInventoryMeliOrderReservationsJob(5004), attempts: 0);
        $beautyId = $this->insertJob(new ProcessMeliBeautyScheduledPriceJob(77, 'MLM3850424391'), attempts: 1);

        $initialSnapshot = $conn->table('jobs')->orderBy('id')->get()->keyBy('id');

        $manifestPath = $this->tmp('m_maria.ndjson');
        $auditPath = $this->tmp('a_maria.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-MARIA-R42', 'INC-MARIA-R42');

        // 1. Dry-run
        $codeDry = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--incident' => 'INC-MARIA-R42',
            '--manifest' => $manifestPath,
            '--json' => true,
        ]);
        $dryRaw = Artisan::output();
        $this->assertSame(0, $codeDry, $dryRaw);
        $dry = json_decode($dryRaw, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $dry['totals']['candidates']);
        $this->assertSame(1, $dry['totals']['previously_attempted_candidates']);
        $sha = (string) $dry['manifest']['manifest_sha256'];

        // 2. Verify real MariaDB GET_LOCK mutual exclusion from a SECOND independent PDO session!
        $secondPdo = $this->openRawPdo($this->database);
        $scopeKey = implode('|', ['r4_mariadb', $this->database, 'jobs']);
        $mysqlLockName = 'ops_qrec_'.substr(hash('sha256', $scopeKey), 0, 48);
        $gotLock = (int) $secondPdo->query("SELECT GET_LOCK('{$mysqlLockName}', 0)")->fetchColumn();
        $this->assertSame(1, $gotLock);

        try {
            $codeBlocked = Artisan::call('ops:queues-quarantine', [
                '--apply' => true,
                '--manifest' => $manifestPath,
                '--expected-manifest-sha256' => $sha,
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-MARIA-R42',
                '--maintenance-lock-file' => $lockFile,
                '--confirm' => 'INC-MARIA-R42',
                '--sleep-ms' => '0',
                '--json' => true,
            ]);
            $outBlocked = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $codeBlocked);
            $this->assertSame('already_running', $outBlocked['reason_code']);
        } finally {
            $secondPdo->query("SELECT RELEASE_LOCK('{$mysqlLockName}')")->fetchColumn();
        }

        // 3. Real concurrent changes on 2nd PDO session AFTER dry-run (before apply commits):
        // - Reserve $resConcurrentReservedId
        // - Modify attempts on $resConcurrentModifiedId
        // - Insert a new job ABOVE manifest authorized_end_id
        $secondPdo->prepare('UPDATE jobs SET reserved_at = ? WHERE id = ?')->execute([time(), $resConcurrentReservedId]);
        $secondPdo->prepare('UPDATE jobs SET attempts = 5 WHERE id = ?')->execute([$resConcurrentModifiedId]);
        $newAboveBoundPayload = $this->buildPayload(new ReconcileInventoryMeliOrderReservationsJob(9999));
        $secondPdo->prepare('INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES (?, ?, 0, NULL, ?, ?)')
            ->execute(['meli', $newAboveBoundPayload, time(), time()]);
        $newAboveBoundId = (int) $secondPdo->lastInsertId();

        // 4. Simulate crash AFTER SQL COMMIT before batch_committed audit record on batch 1, then --resume!
        /** @var QueueQuarantineService $service */
        $service = $this->app->make(QueueQuarantineService::class);
        $service->setAfterCommitBeforeAuditHook(static function (int $batchSeq): void {
            if ($batchSeq === 1) {
                throw new RuntimeException('Simulated MariaDB crash after COMMIT before audit');
            }
        });
        try {
            $service->apply([
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'audit_log_path' => $auditPath,
                'maintenance_window' => 'MW-MARIA-R42',
                'maintenance_lock_file' => $lockFile,
                'confirm' => 'INC-MARIA-R42',
                'chunk' => 1,
                'sleep_ms' => 0,
            ]);
            $this->fail('Expected simulated crash.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('after COMMIT', $e->getMessage());
        } finally {
            $service->setAfterCommitBeforeAuditHook(null);
        }

        // Resume reconciles batch 1 and finishes remaining batches!
        $codeResume = Artisan::call('ops:queues-quarantine', [
            '--apply' => true,
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-MARIA-R42',
            '--maintenance-lock-file' => $lockFile,
            '--confirm' => 'INC-MARIA-R42',
            '--chunk' => '1',
            '--sleep-ms' => '0',
            '--resume' => true,
            '--json' => true,
        ]);
        $resumeRaw = Artisan::output();
        $this->assertSame(0, $codeResume, $resumeRaw);
        $resumed = json_decode($resumeRaw, true, 64, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $resumed['reconciled_intents']);
        $this->assertSame('committed_reconciled_from_live_state', $resumed['reconciled_intents'][0]['resolution']);
        $this->assertSame(1, $resumed['totals']['moved']); // resId2 moved in resume (resId1 reconciled from batch 1)
        $this->assertSame(1, $resumed['totals']['skipped_reserved']);
        $this->assertSame(1, $resumed['totals']['skipped_modified']);

        // Verify live MariaDB state: ONLY resId1 & resId2 moved; all others untouched!
        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, $conn->table('jobs')->where('id', $resId1)->value('queue'));
        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, $conn->table('jobs')->where('id', $resId2)->value('queue'));
        $this->assertSame('meli', $conn->table('jobs')->where('id', $resConcurrentReservedId)->value('queue'));
        $this->assertSame('meli', $conn->table('jobs')->where('id', $resConcurrentModifiedId)->value('queue'));
        $this->assertSame('meli', $conn->table('jobs')->where('id', $preservedId)->value('queue'));
        $this->assertSame('meli', $conn->table('jobs')->where('id', $beautyId)->value('queue'));
        $this->assertSame('meli', $conn->table('jobs')->where('id', $newAboveBoundId)->value('queue'));

        // Exact byte-for-byte preservation on resId1 & resId2 (except queue)
        foreach ([$resId1, $resId2] as $movedId) {
            $live = $conn->table('jobs')->where('id', $movedId)->first();
            $this->assertSame((string) $initialSnapshot[$movedId]->payload, (string) $live->payload);
            $this->assertSame((int) $initialSnapshot[$movedId]->attempts, (int) $live->attempts);
            $this->assertSame((int) $initialSnapshot[$movedId]->available_at, (int) $live->available_at);
            $this->assertSame((int) $initialSnapshot[$movedId]->created_at, (int) $live->created_at);
        }

        // 5. Full restore back to `meli` on MariaDB
        $codeRestore = Artisan::call('ops:queues-quarantine', [
            '--restore' => true,
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-MARIA-R42',
            '--maintenance-lock-file' => $lockFile,
            '--confirm' => 'INC-MARIA-R42',
            '--chunk' => '2',
            '--sleep-ms' => '0',
            '--json' => true,
        ]);
        $restoreRaw = Artisan::output();
        $this->assertSame(0, $codeRestore, $restoreRaw);
        $restored = json_decode($restoreRaw, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $restored['totals']['moved']);

        foreach ([$resId1, $resId2] as $movedId) {
            $live = $conn->table('jobs')->where('id', $movedId)->first();
            $this->assertEquals((array) $initialSnapshot[$movedId], (array) $live);
        }
        $this->assertSame(7, $conn->table('jobs')->count());
        Http::assertNothingSent();
    }

    private function bootstrapIsolatedDatabase(): void
    {
        $pdo = new PDO(
            "mysql:host={$this->host};port={$this->port};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        $pdo->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$this->database}`");
        $pdo->exec('
            CREATE TABLE `jobs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `queue` varchar(255) NOT NULL,
                `payload` longtext NOT NULL,
                `attempts` tinyint unsigned NOT NULL DEFAULT 0,
                `reserved_at` int unsigned DEFAULT NULL,
                `available_at` int unsigned NOT NULL,
                `created_at` int unsigned NOT NULL,
                PRIMARY KEY (`id`),
                KEY `jobs_queue_index` (`queue`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    private function openRawPdo(string $database): PDO
    {
        return new PDO(
            "mysql:host={$this->host};port={$this->port};dbname={$database};charset=utf8mb4",
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function insertJob(object $job, string $queue = 'meli', int $attempts = 0): int
    {
        $now = time();

        return (int) DB::connection('r4_mariadb')->table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => $this->buildPayload($job),
            'attempts' => $attempts,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function buildPayload(object $job): string
    {
        return json_encode([
            'uuid' => 'uuid-'.bin2hex(random_bytes(8)),
            'displayName' => $job::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => $job::class,
                'command' => serialize($job),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function writeMaintenanceLock(string $window, string $incidentId): string
    {
        $path = $this->tmp('maint_maria.json');
        file_put_contents($path, json_encode([
            'window_id' => $window,
            'incident_id' => $incidentId,
            'workers_halted_confirmed' => true,
            'created_at' => now()->utc()->toIso8601String(),
            'expires_at' => now()->utc()->addHour()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $path;
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
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
