<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMeliMessageNotification;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueueQuarantinePolicy;
use App\Services\System\QueueRecovery\QueueQuarantineService;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * R4.2 Volume Benchmark & Bounded Memory Verification (>= 100,000 synthetic jobs).
 *
 * Verifies:
 * - Streaming NDJSON manifest & keyset pagination keep peak PHP memory strictly < 128 MiB.
 * - Memory usage does NOT grow linearly with total rows.
 * - Exact counts, zero inserts/deletes, 1,000 preserved other-module jobs untouched,
 *   clean mid-run interruption + `--resume`, and full `--restore` reversibility.
 */
class OpsQueuesQuarantineVolumeTest extends TestCase
{
    private string $tempDir;

    private string $sqlitePath;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r4_2_volume_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
        $this->sqlitePath = $this->tempDir.DIRECTORY_SEPARATOR.'volume_100k.sqlite';

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $this->sqlitePath);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        DB::purge('sqlite');
        touch($this->sqlitePath);

        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true],
        ));
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_33_34_volume_100k_jobs_streaming_memory_under_128mib_resume_and_restore(): void
    {
        $targetCount = 100000;
        $otherCount = 1000;
        $totalCount = $targetCount + $otherCount;

        // Fast bulk seed via raw PDO inside a single transaction (keeps test setup ~1s and low RAM)
        /** @var PDO $pdo */
        $pdo = DB::connection('sqlite')->getPdo();
        $pdo->exec('PRAGMA synchronous = OFF; PRAGMA journal_mode = MEMORY;');

        $sampleReservation = json_encode([
            'uuid' => 'uuid-r42-vol',
            'displayName' => ReconcileInventoryMeliOrderReservationsJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => ReconcileInventoryMeliOrderReservationsJob::class,
                'command' => serialize(new ReconcileInventoryMeliOrderReservationsJob(500001)),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $sampleOther = json_encode([
            'uuid' => 'uuid-r42-other',
            'displayName' => ProcessMeliMessageNotification::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => ProcessMeliMessageNotification::class,
                'command' => serialize(new ProcessMeliMessageNotification(['resource' => 'msg-vol'])),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $now = time();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES (?, ?, ?, NULL, ?, ?)');
        for ($i = 1; $i <= $totalCount; $i++) {
            $isOther = ($i % 101 === 0) && ($otherCount > 0);
            if ($isOther) {
                $stmt->execute(['meli', $sampleOther, 0, $now, $now]);
                $otherCount--;
            } else {
                $attempts = ($i === 1000 || $i === 50000) ? 2 : 0;
                $stmt->execute(['meli', $sampleReservation, $attempts, $now, $now]);
            }
        }
        $pdo->commit();
        $pdo->exec('PRAGMA synchronous = NORMAL;');

        $this->assertSame($totalCount, DB::table('jobs')->count());

        $manifestPath = $this->tmp('vol_100k_manifest.ndjson');
        $auditPath = $this->tmp('vol_100k_audit.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-VOL-100K', 'INC-VOL-100K');

        // 1. DRY-RUN over 101,000 rows
        $tDryStart = microtime(true);
        $codeDry = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--chunk' => '500',
            '--incident' => 'INC-VOL-100K',
            '--manifest' => $manifestPath,
            '--json' => true,
        ]);
        $dryRaw = Artisan::output();
        $dryDurationSec = round(microtime(true) - $tDryStart, 3);
        $this->assertSame(0, $codeDry, $dryRaw);
        $dry = json_decode($dryRaw, true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame($totalCount, $dry['totals']['scanned']);
        $this->assertSame($targetCount, $dry['totals']['candidates']);
        $this->assertSame(1000, $dry['totals']['excluded_other_modules']);
        $this->assertSame(2, $dry['totals']['previously_attempted_candidates']);

        $manifestSha = (string) $dry['manifest']['manifest_sha256'];
        $manifestSizeBytes = (int) filesize($manifestPath);

        // 2. APPLY with mid-run interruption at batch 100 (50,000 rows) + --resume for remaining 50,000 rows!
        /** @var QueueQuarantineService $service */
        $service = $this->app->make(QueueQuarantineService::class);
        $memMidApplyBytes = 0;

        $tApplyStart = microtime(true);
        $service->setShouldInterruptChecker(static function (int $nextBatchSeq) use (&$memMidApplyBytes): bool {
            if ($nextBatchSeq === 101) {
                $memMidApplyBytes = memory_get_usage(true);

                return true;
            }

            return false;
        });

        try {
            $applyPart1 = $service->apply([
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $manifestSha,
                'audit_log_path' => $auditPath,
                'maintenance_window' => 'MW-VOL-100K',
                'maintenance_lock_file' => $lockFile,
                'confirm' => 'INC-VOL-100K',
                'chunk' => 500,
                'sleep_ms' => 0,
            ]);
        } finally {
            $service->setShouldInterruptChecker(null);
        }

        $this->assertTrue($applyPart1['stopped_early']);
        $this->assertSame('interrupted_by_signal', $applyPart1['stop_reason']);
        $this->assertSame(50000, $applyPart1['totals']['moved']);

        $applyPart2 = $service->apply([
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $manifestSha,
            'audit_log_path' => $auditPath,
            'maintenance_window' => 'MW-VOL-100K',
            'maintenance_lock_file' => $lockFile,
            'confirm' => 'INC-VOL-100K',
            'chunk' => 500,
            'sleep_ms' => 0,
            'resume' => true,
        ]);
        $applyDurationSec = round(microtime(true) - $tApplyStart, 3);
        $memEndApplyBytes = memory_get_usage(true);

        $this->assertFalse($applyPart2['stopped_early']);
        $this->assertSame(50000, $applyPart2['totals']['moved']);
        $this->assertSame($totalCount, DB::table('jobs')->count());
        $this->assertSame($targetCount, DB::table('jobs')->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());
        $this->assertSame(1000, DB::table('jobs')->where('queue', 'meli')->count());

        // 3. RESTORE all 100,000 rows back to `meli`
        $tRestoreStart = microtime(true);
        $restoreRes = $service->restore([
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $manifestSha,
            'audit_log_path' => $auditPath,
            'maintenance_window' => 'MW-VOL-100K',
            'maintenance_lock_file' => $lockFile,
            'confirm' => 'INC-VOL-100K',
            'chunk' => 500,
            'sleep_ms' => 0,
        ]);
        $restoreDurationSec = round(microtime(true) - $tRestoreStart, 3);

        $this->assertSame($targetCount, $restoreRes['totals']['moved']);
        $this->assertSame($totalCount, DB::table('jobs')->count());
        $this->assertSame($totalCount, DB::table('jobs')->where('queue', 'meli')->count());
        $this->assertSame(0, DB::table('jobs')->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());

        $auditSizeBytes = (int) filesize($auditPath);
        $peakMemoryBytes = memory_get_peak_usage(true);
        $peakMemoryMib = round($peakMemoryBytes / 1048576, 2);

        // Mandatory R4.2 memory invariant: strictly < 128 MiB and bounded growth!
        $this->assertLessThan(128.0, $peakMemoryMib, "Peak memory {$peakMemoryMib} MiB exceeded 128 MiB limit.");
        $this->assertLessThanOrEqual(
            24 * 1024 * 1024,
            abs($memEndApplyBytes - $memMidApplyBytes),
            'Apply memory grew unexpectedly between 50k and 100k rows.',
        );

        // Optional metrics export when R4_VOLUME_METRICS_OUT is specified for deliverable reporting
        $metricsOut = trim((string) (getenv('R4_VOLUME_METRICS_OUT') ?: ''));
        if ($metricsOut !== '') {
            file_put_contents($metricsOut, json_encode([
                'total_rows_examined' => $totalCount,
                'target_rows_moved' => $targetCount,
                'preserved_other_rows' => 1000,
                'chunk_size' => 500,
                'sleep_ms' => 0,
                'dry_run_seconds' => $dryDurationSec,
                'apply_with_midrun_resume_seconds' => $applyDurationSec,
                'restore_seconds' => $restoreDurationSec,
                'peak_memory_mib' => $peakMemoryMib,
                'mem_mid_apply_mib' => round($memMidApplyBytes / 1048576, 2),
                'mem_end_apply_mib' => round($memEndApplyBytes / 1048576, 2),
                'manifest_size_bytes' => $manifestSizeBytes,
                'manifest_size_mib' => round($manifestSizeBytes / 1048576, 2),
                'audit_log_size_bytes' => $auditSizeBytes,
                'audit_log_size_mib' => round($auditSizeBytes / 1048576, 2),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        Http::assertNothingSent();
    }

    private function writeMaintenanceLock(string $window, string $incidentId): string
    {
        $path = $this->tmp('maint.json');
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
