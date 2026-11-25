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
use App\Services\System\QueueRecovery\QueueRecoveryLock;
use App\Services\System\QueueRecovery\QueueRecoveryManifest;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Isolated 100,000-row synthetic volume and resource-consumption benchmark test for R2.1 (§6).
 * Uses a file-backed temporary SQLite database so DB storage does not inflate PHP RAM.
 */
class OpsQueuesPruneRedundantVolumeTest extends TestCase
{
    private string $tempDir;

    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r21_volume_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
        $this->sqlitePath = $this->tempDir.DIRECTORY_SEPARATOR.'jobs_100k.sqlite';

        config()->set('database.connections.r21_vol', [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath,
            'prefix' => '',
        ]);
        config()->set('database.default', 'r21_vol');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'r21_vol');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        DB::purge('r21_vol');
    }

    protected function tearDown(): void
    {
        DB::purge('r21_vol');
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_volume_100k_rows_pilot_1000_and_window_10000_stay_bounded_in_memory_and_preserve_other_modules(): void
    {
        $totalSeeded = 100000;
        $seedStart = microtime(true);
        $stats = $this->seedSynthetic100kJobs($totalSeeded);
        $seedDurationMs = round((microtime(true) - $seedStart) * 1000, 2);

        $this->assertSame($totalSeeded, (int) DB::connection('r21_vol')->table('jobs')->count());

        $service = $this->makeService(
            new QueueRecoveryDiskChecker(static fn (): int => 20 * 1024 * 1024 * 1024),
        );

        $jobs = [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES];
        $lockFile = $this->writeMaintenanceLock('MW-VOL-100K', 'INC-VOL-100K');
        $authorizedUpperBound = $stats['max_id'];

        // =====================================================================
        // Window 1: Production Pilot Profile (1,000 candidates, chunk=100)
        // =====================================================================
        $w1Manifest = $this->tmp('w1_pilot_manifest.json');
        $w1Backup = $this->tmp('w1_pilot_backup.ndjson');
        $w1Audit = $this->tmp('w1_pilot_audit.ndjson');

        $w1Dry = $service->dryRun([
            'jobs' => $jobs,
            'chunk' => 100,
            'max_delete' => 1000,
            'max_scan' => 50000,
            'start_id' => 0,
            'end_id' => $authorizedUpperBound,
            'incident_id' => 'INC-VOL-100K',
            'manifest_path' => $w1Manifest,
            'backup_out_path' => $w1Backup,
            'audit_log_path' => $w1Audit,
        ]);

        $this->assertSame(1000, $w1Dry['manifest']['totals']['candidates']);
        $this->assertTrue($w1Dry['manifest']['is_partial']);
        $this->assertSame('max_delete_reached', $w1Dry['manifest']['stop_reason']);

        $w1Apply = $service->apply([
            'jobs' => $jobs,
            'manifest_path' => $w1Manifest,
            'expected_manifest_sha256' => $w1Dry['manifest']['manifest_sha256'],
            'backup_path' => $w1Backup,
            'maintenance_window' => 'MW-VOL-100K',
            'maintenance_lock_file' => $lockFile,
            'chunk' => 100,
            'max_delete' => 1000,
            'sleep_ms' => 0,
            'audit_log_path' => $w1Audit,
        ]);

        $this->assertSame('completed', $w1Apply['status']);
        $this->assertSame(1000, $w1Apply['deleted_this_run']);
        $this->assertSame($w1Dry['manifest']['next_start_id'], $w1Apply['next_start_id']);
        $this->assertSame($totalSeeded - 1000, (int) DB::connection('r21_vol')->table('jobs')->count());

        // =====================================================================
        // Window 2: Standard Window Profile (10,000 candidates, chunk=500)
        // Continues strictly from Window 1's next_start_id!
        // =====================================================================
        $w2Manifest = $this->tmp('w2_10k_manifest.json');
        $w2Backup = $this->tmp('w2_10k_backup.ndjson');
        $w2Audit = $this->tmp('w2_10k_audit.ndjson');

        $w2Dry = $service->dryRun([
            'jobs' => $jobs,
            'chunk' => 500,
            'max_delete' => 10000,
            'max_scan' => 50000,
            'start_id' => $w1Apply['next_start_id'],
            'end_id' => $authorizedUpperBound,
            'incident_id' => 'INC-VOL-100K',
            'manifest_path' => $w2Manifest,
            'backup_out_path' => $w2Backup,
            'audit_log_path' => $w2Audit,
        ]);

        $this->assertSame(10000, $w2Dry['manifest']['totals']['candidates']);
        $this->assertTrue($w2Dry['manifest']['is_partial']);
        $this->assertGreaterThan($w1Apply['next_start_id'], $w2Dry['manifest']['entries'][0]['id']);

        $w2Apply = $service->apply([
            'jobs' => $jobs,
            'manifest_path' => $w2Manifest,
            'expected_manifest_sha256' => $w2Dry['manifest']['manifest_sha256'],
            'backup_path' => $w2Backup,
            'maintenance_window' => 'MW-VOL-100K',
            'maintenance_lock_file' => $lockFile,
            'chunk' => 500,
            'max_delete' => 10000,
            'sleep_ms' => 0,
            'audit_log_path' => $w2Audit,
        ]);

        $this->assertSame('completed', $w2Apply['status']);
        $this->assertSame(10000, $w2Apply['deleted_this_run']);
        $this->assertSame($totalSeeded - 11000, (int) DB::connection('r21_vol')->table('jobs')->count());

        // Verify ALL other-module jobs among the 100,000 rows remain 100% intact!
        $remainingOther = (int) DB::connection('r21_vol')
            ->table('jobs')
            ->where('payload', 'like', '%ProcessMeliMessageNotification%')
            ->count();
        $this->assertSame($stats['other_count'], $remainingOther);

        // Assert PHP peak memory stayed strictly bounded (< 160 MiB) — safe on 1.6 GiB host!
        $peakMemoryBytes = memory_get_peak_usage(true);
        $peakMemoryMib = round($peakMemoryBytes / (1024 * 1024), 2);
        $this->assertLessThan(160.0, $peakMemoryMib, "Peak PHP memory ({$peakMemoryMib} MiB) exceeded 160 MiB safety ceiling.");

        $metrics = [
            'seeded_rows' => $totalSeeded,
            'seed_duration_ms' => $seedDurationMs,
            'sqlite_db_bytes' => filesize($this->sqlitePath),
            'php_peak_memory_mib' => $peakMemoryMib,
            'pilot_1000_chunk_100' => [
                'dry_run_ms' => $w1Dry['duration_ms'],
                'manifest_bytes' => $w1Dry['manifest_size_bytes'],
                'backup_bytes' => $w1Dry['backup']['size_bytes'],
                'sqlite_restore_ms' => $w1Apply['backup_verification']['restore_duration_ms'],
                'apply_total_ms' => $w1Apply['total_duration_ms'],
                'batch_count' => count($w1Apply['batch_durations_ms']),
                'batch_max_ms' => max($w1Apply['batch_durations_ms']),
                'batch_avg_ms' => round(array_sum($w1Apply['batch_durations_ms']) / count($w1Apply['batch_durations_ms']), 2),
            ],
            'window_10000_chunk_500' => [
                'dry_run_ms' => $w2Dry['duration_ms'],
                'manifest_bytes' => $w2Dry['manifest_size_bytes'],
                'backup_bytes' => $w2Dry['backup']['size_bytes'],
                'sqlite_restore_ms' => $w2Apply['backup_verification']['restore_duration_ms'],
                'apply_total_ms' => $w2Apply['total_duration_ms'],
                'batch_count' => count($w2Apply['batch_durations_ms']),
                'batch_max_ms' => max($w2Apply['batch_durations_ms']),
                'batch_avg_ms' => round(array_sum($w2Apply['batch_durations_ms']) / count($w2Apply['batch_durations_ms']), 2),
            ],
        ];

        if (getenv('R21_PRINT_METRICS') === '1') {
            fwrite(STDERR, "\nR21_VOLUME_METRICS=".json_encode($metrics, JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    public function test_volume_mid_run_disk_space_drop_aborts_before_next_batch(): void
    {
        $this->seedSynthetic100kJobs(200);

        $manifestPath = $this->tmp('m_diskdrop.json');
        $backupPath = $this->tmp('b_diskdrop.ndjson');
        $auditPath = $this->tmp('a_diskdrop.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-DISKDROP', 'INC-DISKDROP');

        $diskDropped = false;
        // Preflight check + batch 1 pass (12 GiB); after batch 1 completes, disk drops to 6 GiB (< 8 GiB)!
        $dynamicDisk = new QueueRecoveryDiskChecker(static function () use (&$diskDropped): int {
            return $diskDropped
                ? 6 * 1024 * 1024 * 1024
                : 12 * 1024 * 1024 * 1024;
        });

        $service = $this->makeService($dynamicDisk);
        $service->setAfterAuditBeforeCheckpointHook(static function (int $batchSeq) use (&$diskDropped): void {
            if ($batchSeq === 1) {
                $diskDropped = true;
            }
        });
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            'chunk' => 50,
            'max_delete' => 150,
            'incident_id' => 'INC-DISKDROP',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
        ]);

        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $dry['manifest']['manifest_sha256'],
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-DISKDROP',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 50,
                'max_delete' => 150,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected insufficient_disk_space mid-run abort.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('insufficient_disk_space', $e->reasonCode);
        }

        // Batch 1 (50 rows) succeeded, remaining rows preserved cleanly!
        $this->assertSame(150, (int) DB::connection('r21_vol')->table('jobs')->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeService(QueueRecoveryDiskChecker $diskChecker): QueuePruneService
    {
        return new QueuePruneService(
            new QueuePayloadInspector,
            new QueueRecoveryManifest,
            new QueueRecoveryBackupService,
            $diskChecker,
            new QueueWorkerDetector(static fn (): array => [
                'processes' => [],
                'unreadable_count' => 0,
                'inspection_available' => true,
            ]),
            new QueueRecoveryLock,
        );
    }

    /** @return array{max_id: int, other_count: int} */
    private function seedSynthetic100kJobs(int $count): array
    {
        $pdo = new PDO('sqlite:'.$this->sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                reserved_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )
        ');

        $resPayloads = [];
        for ($k = 0; $k < 20; $k++) {
            $resPayloads[$k] = $this->buildPayload(new ReconcileInventoryMeliOrderReservationsJob(50000 + $k));
        }
        $beautyPayload = $this->buildPayload(new ProcessMeliBeautyScheduledPriceJob(12, 'MLM3850424391'));
        $otherPayload = $this->buildPayload(new ProcessMeliMessageNotification(['resource' => '/messages/123']));

        $stmt = $pdo->prepare('INSERT INTO jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) VALUES (?, ?, ?, 0, NULL, ?, ?)');
        $now = time();
        $otherCount = 0;
        $maxId = 0;

        $pdo->beginTransaction();
        for ($i = 1; $i <= $count; $i++) {
            // Introduce occasional PK gaps to mirror production PK discontinuities!
            $pk = $i + intdiv($i, 250);
            $maxId = $pk;

            if ($i % 40 === 0) {
                $payload = $otherPayload;
                $otherCount++;
            } elseif ($i % 40 === 20) {
                $payload = $beautyPayload;
            } else {
                $payload = $resPayloads[$i % 20];
            }

            $stmt->execute([$pk, 'meli', $payload, $now, $now]);
        }
        $pdo->commit();
        $pdo->exec('PRAGMA synchronous = FULL');

        return ['max_id' => $maxId, 'other_count' => $otherCount];
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

    private function writeMaintenanceLock(string $windowId, string $incidentId): string
    {
        $path = $this->tmp('lock_'.$windowId.'.json');
        file_put_contents($path, json_encode([
            'window_id' => $windowId,
            'incident_id' => $incidentId,
            'workers_halted_confirmed' => true,
            'approved_by' => 'ops-benchmark',
            'expires_at' => now()->utc()->addHours(2)->toIso8601String(),
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
