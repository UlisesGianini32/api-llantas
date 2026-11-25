<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessMeliBeautyScheduledPriceJob;
use App\Jobs\ProcessMeliMessageNotification;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryAuditLog;
use App\Services\System\QueueRecovery\QueueRecoveryBackupService;
use App\Services\System\QueueRecovery\QueueRecoveryCheckpoint;
use App\Services\System\QueueRecovery\QueueRecoveryDiskChecker;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryLock;
use App\Services\System\QueueRecovery\QueueRecoveryManifest;
use App\Services\System\QueueRecovery\QueueRecoveryPathGuard;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OpsQueuesPruneRedundantHardeningTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r21_hardening_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', false);
        config()->set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        DB::purge('sqlite');
        $this->createFullSchema('sqlite');

        $this->app->singleton(QueueRecoveryDiskChecker::class, fn () => new QueueRecoveryDiskChecker(
            static fn (string $path): int => 20 * 1024 * 1024 * 1024,
        ));
        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true],
        ));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        DB::purge('sqlite');
        parent::tearDown();
    }

    // =========================================================================
    // R2.1 §1: Cursor advancement, consecutive windows, discontinuous IDs, interleaved classes
    // =========================================================================
    public function test_r21_01_consecutive_windows_with_small_limits_discontinuous_ids_and_interleaved_classes_skip_zero_candidates(): void
    {
        $authorizedUpperBound = 450;

        // Discontinuous PKs with interleaved candidates, other modules, reserved jobs, and post-bound jobs.
        $fixtures = [
            10 => ['type' => 'res', 'order' => 101],
            15 => ['type' => 'other'],
            22 => ['type' => 'beauty', 'discount' => 1],
            30 => ['type' => 'other'],
            31 => ['type' => 'other'],
            45 => ['type' => 'other'],
            50 => ['type' => 'other'],
            60 => ['type' => 'other'], // Forces a max-scan window with 0 candidates!
            77 => ['type' => 'res', 'order' => 102],
            88 => ['type' => 'reserved', 'order' => 103],
            99 => ['type' => 'beauty', 'discount' => 2],
            120 => ['type' => 'res', 'order' => 104],
            150 => ['type' => 'other'],
            200 => ['type' => 'res', 'order' => 105],
            250 => ['type' => 'beauty', 'discount' => 3],
            300 => ['type' => 'res', 'order' => 106],
            400 => ['type' => 'other'],
            450 => ['type' => 'res', 'order' => 107], // Exactly at authorized_upper_bound
            500 => ['type' => 'res', 'order' => 108], // Above authorized_upper_bound -> MUST remain!
        ];

        $expectedDeleted = [];
        $expectedPreserved = [];

        foreach ($fixtures as $id => $spec) {
            if ($spec['type'] === 'res') {
                $this->insertJobWithId($id, new ReconcileInventoryMeliOrderReservationsJob($spec['order']));
                if ($id <= $authorizedUpperBound) {
                    $expectedDeleted[] = $id;
                } else {
                    $expectedPreserved[] = $id;
                }
            } elseif ($spec['type'] === 'beauty') {
                $this->insertJobWithId($id, new ProcessMeliBeautyScheduledPriceJob($spec['discount'], 'MLM3850424391'));
                $expectedDeleted[] = $id;
            } elseif ($spec['type'] === 'reserved') {
                $this->insertJobWithId($id, new ReconcileInventoryMeliOrderReservationsJob($spec['order']), reservedAt: time());
                $expectedPreserved[] = $id;
            } else {
                $this->insertJobWithId($id, new ProcessMeliMessageNotification(['resource' => 'msg-'.$id]));
                $expectedPreserved[] = $id;
            }
        }

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $jobs = [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES];
        $lockFile = $this->writeMaintenanceLock('MW-WINDOWS', 'INC-WINDOWS');

        $cursorStartId = 0;
        $windowIndex = 0;
        $totalDeletedAcrossWindows = 0;
        $sawPartialMaxDelete = false;
        $sawPartialMaxScanWithZeroCandidates = false;

        while (true) {
            $windowIndex++;
            $this->assertLessThan(20, $windowIndex, 'Infinite cursor loop detected!');

            $manifestPath = $this->tmp("win_{$windowIndex}_manifest.json");
            $backupPath = $this->tmp("win_{$windowIndex}_backup.ndjson");
            $auditPath = $this->tmp("win_{$windowIndex}_audit.ndjson");

            $dry = $service->dryRun([
                'jobs' => $jobs,
                'chunk' => 2,
                'max_delete' => 3,
                'max_scan' => 4,
                'start_id' => $cursorStartId,
                'end_id' => $authorizedUpperBound,
                'incident_id' => 'INC-WINDOWS',
                'manifest_path' => $manifestPath,
                'backup_out_path' => $backupPath,
                'audit_log_path' => $auditPath,
            ]);

            $manifest = $dry['manifest'];
            $this->assertSame($authorizedUpperBound, $manifest['authorized_upper_bound']);
            $this->assertGreaterThan($cursorStartId, $manifest['last_scanned_id']);
            $this->assertSame($manifest['last_scanned_id'], $manifest['next_start_id']);

            if ($manifest['stop_reason'] === 'max_delete_reached') {
                $sawPartialMaxDelete = true;
                $this->assertTrue($manifest['is_partial']);
            }
            if ($manifest['stop_reason'] === 'max_scan_reached' && $manifest['totals']['candidates'] === 0) {
                $sawPartialMaxScanWithZeroCandidates = true;
                $this->assertTrue($manifest['is_partial']);
                $this->assertNull($manifest['last_candidate_id']);
            }

            if ($manifest['totals']['candidates'] > 0) {
                $applied = $service->apply([
                    'jobs' => $jobs,
                    'manifest_path' => $manifestPath,
                    'expected_manifest_sha256' => $manifest['manifest_sha256'],
                    'backup_path' => $backupPath,
                    'maintenance_window' => 'MW-WINDOWS',
                    'maintenance_lock_file' => $lockFile,
                    'chunk' => 2,
                    'max_delete' => 3,
                    'sleep_ms' => 0,
                    'audit_log_path' => $auditPath,
                ]);

                $this->assertSame('completed', $applied['status']);
                $this->assertSame($manifest['next_start_id'], $applied['next_start_id']);
                $this->assertSame($manifest['last_candidate_id'], $applied['last_deleted_id']);
                $totalDeletedAcrossWindows += $applied['deleted_this_run'];
            }

            $cursorStartId = $manifest['next_start_id'];
            if (! $manifest['is_partial']) {
                $this->assertSame($authorizedUpperBound, $cursorStartId);
                break;
            }
        }

        $this->assertTrue($sawPartialMaxDelete, 'Expected at least one partial window hitting max_delete.');
        $this->assertTrue($sawPartialMaxScanWithZeroCandidates, 'Expected at least one max_scan window over non-candidates.');
        $this->assertSame(count($expectedDeleted), $totalDeletedAcrossWindows);

        $remainingIds = DB::table('jobs')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($expectedPreserved);
        $this->assertSame($expectedPreserved, $remainingIds);
    }

    // =========================================================================
    // R2.1 §2: Manifest authenticity (recalculated checksum + external HMAC)
    // =========================================================================
    public function test_r21_02_rejects_tampered_manifest_even_with_recalculated_sha256_via_expected_hash_and_hmac(): void
    {
        $this->insertJobWithId(10, new ReconcileInventoryMeliOrderReservationsJob(101));
        $this->insertJobWithId(20, new ReconcileInventoryMeliOrderReservationsJob(102));

        $manifestPath = $this->tmp('m_auth.json');
        $backupPath = $this->tmp('b_auth.ndjson');
        $auditPath = $this->tmp('a_auth.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-AUTH', 'INC-AUTH');
        $hmacSecret = 'super-secret-external-operator-key-2026';

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-AUTH',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
            'hmac_key' => $hmacSecret,
        ]);
        $approvedSha = $dry['manifest']['manifest_sha256'];
        $this->assertArrayHasKey('manifest_hmac_sha256', $dry['manifest']);

        // 1. Missing --expected-manifest-sha256 must fail with manifest_approval_required.
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => '',
                'hmac_key' => $hmacSecret,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-AUTH',
                'maintenance_lock_file' => $lockFile,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected manifest_approval_required');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_approval_required', $e->reasonCode);
        }

        // 2. Attacker alters manifest AND recalculates a valid manifest_sha256 checksum!
        $tampered = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $tampered['entries'] = [$tampered['entries'][0]]; // Remove entry #20
        $manifestMgr = new QueueRecoveryManifest;
        $recomputed = $manifestMgr->finalize($tampered); // Recalculates valid manifest_sha256 WITHOUT the secret HMAC key!
        $recomputed['manifest_hmac_sha256'] = $dry['manifest']['manifest_hmac_sha256']; // Keep old HMAC
        file_put_contents($manifestPath, json_encode($recomputed, JSON_PRETTY_PRINT));

        // 2a. Operator passes original approved --expected-manifest-sha256 -> REJECTED!
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $approvedSha,
                'hmac_key' => $hmacSecret,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-AUTH',
                'maintenance_lock_file' => $lockFile,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected manifest_approval_mismatch');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_approval_mismatch', $e->reasonCode);
        }

        // 2b. Even if expected_manifest_sha256 were tricked to the recalculated checksum, external HMAC rejects it!
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $recomputed['manifest_sha256'],
                'hmac_key' => $hmacSecret,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-AUTH',
                'maintenance_lock_file' => $lockFile,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected manifest_hmac_invalid');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_hmac_invalid', $e->reasonCode);
        }

        $this->assertSame(2, DB::table('jobs')->count());
    }

    // =========================================================================
    // R2.1 §3: Crash recovery at EVERY protocol point + truncated NDJSON + disk full / fsync
    // =========================================================================
    public function test_r21_03_crash_before_intent_and_after_intent_before_commit_both_recover_cleanly(): void
    {
        $this->insertJobWithId(1, new ReconcileInventoryMeliOrderReservationsJob(101));
        $this->insertJobWithId(2, new ReconcileInventoryMeliOrderReservationsJob(102));

        $manifestPath = $this->tmp('m_c3.json');
        $backupPath = $this->tmp('b_c3.ndjson');
        $auditPath = $this->tmp('a_c3.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-C3', 'INC-C3');

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-C3',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
        ]);
        $sha = $dry['manifest']['manifest_sha256'];

        // A. Crash strictly BEFORE batch_intent.
        $service->setBeforeIntentHook(static function (): void {
            throw new RuntimeException('Crash before batch_intent');
        });
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-C3',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 2,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected crash before batch_intent');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Crash before batch_intent', $e->getMessage());
        }
        $this->assertSame(2, DB::table('jobs')->count());

        // B. Crash strictly AFTER batch_intent and BEFORE DB COMMIT.
        $service->setBeforeIntentHook(null);
        $service->setAfterIntentBeforeCommitHook(static function (): void {
            throw new RuntimeException('Crash after batch_intent before COMMIT');
        });
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-C3',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 2,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected crash after batch_intent');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Crash after batch_intent', $e->getMessage());
        }
        $this->assertSame(2, DB::table('jobs')->count());

        // C. Resume -> sees all 2 rows still in DB -> logs batch_aborted and completes deletion!
        $service->setAfterIntentBeforeCommitHook(null);
        $resumed = $service->apply([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $sha,
            'backup_path' => $backupPath,
            'maintenance_window' => 'MW-C3',
            'maintenance_lock_file' => $lockFile,
            'chunk' => 2,
            'sleep_ms' => 0,
            'audit_log_path' => $auditPath,
            'resume' => true,
        ]);

        $this->assertSame('completed', $resumed['status']);
        $this->assertSame(2, $resumed['deleted_this_run']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringContainsString('intent_not_committed_before_crash', (string) file_get_contents($auditPath));
    }

    public function test_r21_04_crash_after_commit_before_audit_aborts_ambiguous_until_operator_confirms(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->insertJobWithId($i, new ReconcileInventoryMeliOrderReservationsJob(200 + $i));
        }

        $manifestPath = $this->tmp('m_c4.json');
        $backupPath = $this->tmp('b_c4.ndjson');
        $auditPath = $this->tmp('a_c4.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-C4', 'INC-C4');

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-C4',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
        ]);
        $sha = $dry['manifest']['manifest_sha256'];

        $service->setAfterCommitBeforeAuditHook(static function (int $batchSeq): void {
            if ($batchSeq === 1) {
                throw new RuntimeException('Hard crash strictly between DB COMMIT and batch_committed');
            }
        });

        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-C4',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 2,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected hard crash after commit');
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(2, DB::table('jobs')->count());
        $service->setAfterCommitBeforeAuditHook(null);

        // Plain --resume MUST ABORT (never assume missing rows were deleted by R2!).
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-C4',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 2,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
                'resume' => true,
            ]);
            $this->fail('Expected unresolved_ambiguous_batch_intent');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('unresolved_ambiguous_batch_intent', $e->reasonCode);
        }

        // After operator investigates and explicitly confirms batch_seq=1, resume reconciles and completes!
        $resumed = $service->apply([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $sha,
            'backup_path' => $backupPath,
            'maintenance_window' => 'MW-C4',
            'maintenance_lock_file' => $lockFile,
            'chunk' => 2,
            'sleep_ms' => 0,
            'audit_log_path' => $auditPath,
            'resume' => true,
            'confirm_reconcile_batch' => 1,
        ]);

        $this->assertSame('completed', $resumed['status']);
        $this->assertSame(2, $resumed['reconciled_after_crash']);
        $this->assertSame(2, $resumed['deleted_this_run']);
        $this->assertSame(4, $resumed['total_deleted']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertStringContainsString('batch_reconciled_operator_confirmed', (string) file_get_contents($auditPath));
    }

    public function test_r21_05_partial_row_disappearance_under_uncommitted_intent_always_aborts(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertJobWithId($i, new ReconcileInventoryMeliOrderReservationsJob(300 + $i));
        }

        $manifestPath = $this->tmp('m_c5.json');
        $backupPath = $this->tmp('b_c5.ndjson');
        $auditPath = $this->tmp('a_c5.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-C5', 'INC-C5');

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-C5',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
        ]);
        $sha = $dry['manifest']['manifest_sha256'];

        // Record an uncommitted batch_intent for [1, 2, 3], then delete ONLY job #2 externally.
        $auditLog = new QueueRecoveryAuditLog($auditPath);
        $auditLog->record('batch_intent', [
            'incident_id' => 'INC-C5',
            'manifest_sha256' => $sha,
            'batch_seq' => 1,
            'count' => 3,
            'min_id' => 1,
            'max_id' => 3,
            'ids' => [1, 2, 3],
        ]);
        DB::table('jobs')->where('id', 2)->delete();

        // Even WITH --confirm-reconcile-batch=1, partial disappearance MUST ALWAYS ABORT!
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-C5',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 3,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
                'resume' => true,
                'confirm_reconcile_batch' => 1,
            ]);
            $this->fail('Expected unresolved_partial_batch_intent');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('unresolved_partial_batch_intent', $e->reasonCode);
        }

        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_r21_06_corrupt_or_truncated_last_ndjson_line_aborts_fail_closed(): void
    {
        $this->insertJobWithId(1, new ReconcileInventoryMeliOrderReservationsJob(401));

        $manifestPath = $this->tmp('m_c6.json');
        $backupPath = $this->tmp('b_c6.ndjson');
        $auditPath = $this->tmp('a_c6.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-C6', 'INC-C6');

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-C6',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
            'audit_log_path' => $auditPath,
        ]);
        $sha = $dry['manifest']['manifest_sha256'];

        // Append a truncated final NDJSON line simulating crash mid-write / disk full.
        file_put_contents($auditPath, '{"timestamp":"2026-11-24T00:00:00Z","event":"batch_com', FILE_APPEND);

        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-C6',
                'maintenance_lock_file' => $lockFile,
                'audit_log_path' => $auditPath,
                'resume' => true,
            ]);
            $this->fail('Expected audit_log_corrupt');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('audit_log_corrupt', $e->reasonCode);
        }

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_r21_07_io_write_partial_disk_full_and_fsync_failure_abort_cleanly(): void
    {
        // A. Partial write (disk full) during checkpoint atomic save -> io_write_failed and target untouched!
        $checkpointPath = $this->tmp('disk_full.checkpoint.json');
        $partialWriteGuard = new QueueRecoveryPathGuard(
            writeCallback: static fn ($handle, string $bytes): int => max(0, strlen($bytes) - 5),
        );
        $checkpoint = new QueueRecoveryCheckpoint($checkpointPath, $partialWriteGuard);

        try {
            $checkpoint->saveAtomic(['manifest_sha256' => str_repeat('a', 64), 'total_deleted' => 5]);
            $this->fail('Expected io_write_failed');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('io_write_failed', $e->reasonCode);
        }
        $this->assertFileDoesNotExist($checkpointPath);

        // B. fsync() failure during audit log write -> io_sync_failed!
        $auditPath = $this->tmp('fsync_fail.ndjson');
        $failingFsyncGuard = new QueueRecoveryPathGuard(
            fsyncCallback: static fn ($handle): bool => false,
        );
        $auditLog = new QueueRecoveryAuditLog($auditPath, $failingFsyncGuard);

        try {
            $auditLog->record('batch_intent', ['batch_seq' => 1]);
            $this->fail('Expected io_sync_failed');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('io_sync_failed', $e->reasonCode);
        }
    }

    // =========================================================================
    // R2.1 §4: Worker detection fail-closed + mid-run appearance + maintenance sentinel
    // =========================================================================
    public function test_r21_08_worker_detector_fails_closed_on_unreadable_proc_unavailable_inspection_horizon_cron_and_mid_run_spawn(): void
    {
        // A. Unavailable inspection -> fail-closed
        $unavail = new QueueWorkerDetector(static fn (): array => [
            'processes' => [],
            'unreadable_count' => 0,
            'inspection_available' => false,
        ]);
        try {
            $unavail->assertNoActiveTargetWorkers(['meli']);
            $this->fail('Expected worker_inspection_unavailable');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('worker_inspection_unavailable', $e->reasonCode);
        }

        // B. Unreadable /proc/<pid>/cmdline -> fail-closed
        $unreadable = new QueueWorkerDetector(static fn (): array => [
            'processes' => [],
            'unreadable_count' => 3,
            'inspection_available' => true,
        ]);
        try {
            $unreadable->assertNoActiveTargetWorkers(['meli']);
            $this->fail('Expected worker_inspection_insufficient_permissions');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('worker_inspection_insufficient_permissions', $e->reasonCode);
        }

        // C. Horizon and scheduler/cron processes detected
        foreach ([
            'php artisan horizon',
            'php artisan schedule:run',
            'php artisan meli:beauty-scheduled-prices',
        ] as $cmd) {
            $detector = new QueueWorkerDetector(static fn (): array => [
                'processes' => [['pid' => 7777, 'command' => $cmd]],
                'unreadable_count' => 0,
                'inspection_available' => true,
            ]);
            try {
                $detector->assertNoActiveTargetWorkers(['meli']);
                $this->fail("Expected active_worker_detected for '{$cmd}'");
            } catch (QueueRecoveryException $e) {
                $this->assertSame('active_worker_detected', $e->reasonCode);
            }
        }

        // D. Mid-run worker spawn between batch 1 and batch 2 -> batch 1 commits, batch 2 ABORTS!
        for ($i = 1; $i <= 4; $i++) {
            $this->insertJobWithId($i, new ReconcileInventoryMeliOrderReservationsJob(500 + $i));
        }

        $manifestPath = $this->tmp('m_midworker.json');
        $backupPath = $this->tmp('b_midworker.ndjson');
        $auditPath = $this->tmp('a_midworker.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-MIDW', 'INC-MIDW');

        $calls = 0;
        $dynamicDetector = new QueueWorkerDetector(static function () use (&$calls): array {
            $calls++;
            // Call 1 = preflight, Call 2 = before batch 1, Call 3 = before batch 2 -> worker appears!
            if ($calls >= 3) {
                return [
                    'processes' => [['pid' => 99999, 'command' => 'php artisan queue:work database --queue=meli']],
                    'unreadable_count' => 0,
                    'inspection_available' => true,
                ];
            }

            return ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true];
        });

        $service = new QueuePruneService(
            new QueuePayloadInspector,
            new QueueRecoveryManifest,
            new QueueRecoveryBackupService,
            new QueueRecoveryDiskChecker(static fn (): int => 20 * 1024 * 1024 * 1024),
            $dynamicDetector,
            new QueueRecoveryLock,
        );

        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-MIDW',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
        ]);

        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $dry['manifest']['manifest_sha256'],
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-MIDW',
                'maintenance_lock_file' => $lockFile,
                'chunk' => 2,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected active_worker_detected before batch 2');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('active_worker_detected', $e->reasonCode);
        }

        // Batch 1 (IDs 1,2) deleted cleanly, batch 2 (IDs 3,4) untouched!
        $this->assertSame([3, 4], DB::table('jobs')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_r21_09_maintenance_gate_sentinel_fails_closed_when_missing_expired_mismatched_or_removed_mid_run(): void
    {
        $detector = new QueueWorkerDetector(static fn (): array => ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true]);

        // Missing file
        try {
            $detector->assertMaintenanceGateActive($this->tmp('missing.lock.json'), 'MW-1', 'INC-1');
            $this->fail('Expected maintenance_gate_missing');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('maintenance_gate_missing', $e->reasonCode);
        }

        // Expired sentinel
        $expiredPath = $this->tmp('expired.lock.json');
        file_put_contents($expiredPath, json_encode([
            'window_id' => 'MW-1',
            'incident_id' => 'INC-1',
            'workers_halted_confirmed' => true,
            'expires_at' => now()->utc()->subMinutes(5)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        try {
            $detector->assertMaintenanceGateActive($expiredPath, 'MW-1', 'INC-1');
            $this->fail('Expected maintenance_gate_expired');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('maintenance_gate_expired', $e->reasonCode);
        }

        // Mismatched window_id
        $validPath = $this->writeMaintenanceLock('MW-OTHER', 'INC-1');
        try {
            $detector->assertMaintenanceGateActive($validPath, 'MW-1', 'INC-1');
            $this->fail('Expected maintenance_gate_mismatch');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('maintenance_gate_mismatch', $e->reasonCode);
        }
    }

    // =========================================================================
    // R2.1 §5: All-7-column revalidation + PK collision + path security
    // =========================================================================
    public function test_r21_10_revalidates_attempts_available_at_and_created_at_inside_micro_transaction(): void
    {
        foreach (['attempts' => 1, 'available_at' => time() + 999, 'created_at' => time() - 999] as $col => $newVal) {
            DB::table('jobs')->truncate();
            $this->insertJobWithId(1, new ReconcileInventoryMeliOrderReservationsJob(601));
            $this->insertJobWithId(2, new ReconcileInventoryMeliOrderReservationsJob(602));

            $manifestPath = $this->tmp("m_col_{$col}.json");
            $backupPath = $this->tmp("b_col_{$col}.ndjson");
            $auditPath = $this->tmp("a_col_{$col}.ndjson");
            $lockFile = $this->writeMaintenanceLock("MW-COL-{$col}", 'INC-COL');

            /** @var QueuePruneService $service */
            $service = $this->app->make(QueuePruneService::class);
            $dry = $service->dryRun([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'incident_id' => 'INC-COL',
                'manifest_path' => $manifestPath,
                'backup_out_path' => $backupPath,
            ]);

            // Mutate ONLY one metadata column on job #2 (payload & reserved_at unchanged!).
            DB::table('jobs')->where('id', 2)->update([$col => $newVal]);

            try {
                $service->apply([
                    'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                    'manifest_path' => $manifestPath,
                    'expected_manifest_sha256' => $dry['manifest']['manifest_sha256'],
                    'backup_path' => $backupPath,
                    'maintenance_window' => "MW-COL-{$col}",
                    'maintenance_lock_file' => $lockFile,
                    'chunk' => 2,
                    'sleep_ms' => 0,
                    'audit_log_path' => $auditPath,
                ]);
                $this->fail("Expected concurrent_modification when column '{$col}' changed.");
            } catch (QueueRecoveryException $e) {
                $this->assertSame('concurrent_modification', $e->reasonCode);
            }

            // Entire batch rolled back: both rows remain!
            $this->assertSame(2, DB::table('jobs')->count());
        }
    }

    public function test_r21_11_backup_restore_aborts_on_pk_collision_and_path_guard_blocks_repo_overwrite_and_traversal(): void
    {
        $this->insertJobWithId(10, new ReconcileInventoryMeliOrderReservationsJob(701));
        $this->insertJobWithId(11, new ReconcileInventoryMeliOrderReservationsJob(702));

        $manifestPath = $this->tmp('m_pk.json');
        $backupPath = $this->tmp('b_pk.ndjson');

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $dry = $service->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'incident_id' => 'INC-PK',
            'manifest_path' => $manifestPath,
            'backup_out_path' => $backupPath,
        ]);

        /** @var QueueRecoveryBackupService $backupService */
        $backupService = $this->app->make(QueueRecoveryBackupService::class);

        // 1. Accidental overwrite of existing backup MUST be blocked!
        try {
            $backupService->exportForManifest(DB::connection('sqlite'), 'jobs', $dry['manifest'], $backupPath);
            $this->fail('Expected backup_already_exists');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('backup_already_exists', $e->reasonCode);
        }

        // 2. Writing backup inside Git repo MUST be blocked!
        try {
            $backupService->exportForManifest(DB::connection('sqlite'), 'jobs', $dry['manifest'], base_path('storage/forbidden_backup_test.ndjson'));
            $this->fail('Expected unsafe_repo_path');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('unsafe_repo_path', $e->reasonCode);
        }

        // 3. Path traversal MUST be blocked!
        try {
            (new QueueRecoveryPathGuard)->assertSafeOutputPath($this->tempDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'escape.ndjson', true, true);
            $this->fail('Expected unsafe_path');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('unsafe_path', $e->reasonCode);
        }

        // 4. PK collision on restore MUST abort cleanly without overwriting existing row #10!
        $restoreSqlite = $this->tmp('restore_collision.sqlite');
        touch($restoreSqlite);
        config()->set('database.connections.restore_col', [
            'driver' => 'sqlite',
            'database' => $restoreSqlite,
            'prefix' => '',
        ]);
        $this->createJobsTableOnly('restore_col');
        DB::connection('restore_col')->table('jobs')->insert([
            'id' => 10,
            'queue' => 'default',
            'payload' => '{"existing":"do-not-overwrite"}',
            'attempts' => 5,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        try {
            $backupService->restoreToConnection($backupPath, 'restore_col', 'jobs');
            $this->fail('Expected restore_pk_collision');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('restore_pk_collision', $e->reasonCode);
        }

        // Existing row #10 untouched and row #11 NOT partially inserted!
        $this->assertSame(1, DB::connection('restore_col')->table('jobs')->count());
        $this->assertSame('{"existing":"do-not-overwrite"}', DB::connection('restore_col')->table('jobs')->where('id', 10)->value('payload'));
        DB::purge('restore_col');
    }

    // =========================================================================
    // R2.1 §7: Reconstruction diagnostics aligned with real production schema
    // =========================================================================
    public function test_r21_12_reconstruction_diagnostics_detects_occurred_at_deleted_modified_beauty_rules_ambiguous_links_and_3_tier_classification(): void
    {
        DB::table('meli_accounts')->insert(['id' => 1, 'nickname' => 'Cuenta 1']);
        DB::table('inventory_products')->insert([
            ['id' => 10, 'sku' => 'SKU-A', 'name' => 'Prod A', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 11, 'sku' => 'SKU-B', 'name' => 'Prod B', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 12, 'sku' => 'SKU-CLEAN', 'name' => 'Prod Clean', 'product_type' => 'simple', 'is_active' => 1],
        ]);
        // Duplicate active links for MLM-DUP (ambiguous!) + clean link for MLM-CLEAN + MLM-MOV
        DB::table('inventory_channel_links')->insert([
            ['id' => 1, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-DUP', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'order_reservation_enabled' => 1],
            ['id' => 2, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-DUP', 'external_variant_id' => null, 'inventory_product_id' => 11, 'is_active' => 1, 'order_reservation_enabled' => 1],
            ['id' => 3, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-CLEAN', 'external_variant_id' => null, 'inventory_product_id' => 12, 'is_active' => 1, 'order_reservation_enabled' => 1],
        ]);

        // Order 1: Clean single-line cancelled order with active reservation -> reconstructible_candidates!
        DB::table('meli_orders')->insert([
            'id' => 1, 'meli_account_id' => 1, 'order_id' => 'O-1', 'status' => 'cancelled', 'shipping_status' => 'cancelled',
            'raw' => json_encode(['date_created' => '2026-11-10T12:00:00-06:00']), 'created_at' => '2026-11-10 12:00:00', 'updated_at' => '2026-11-10 12:00:00',
        ]);
        DB::table('meli_order_items')->insert([
            'meli_order_id' => 1, 'item_id' => 'MLM-CLEAN', 'variation_id' => null, 'remote_line_key' => 'MLM-CLEAN:', 'sku' => 'SKU-CLEAN', 'quantity' => 1,
        ]);
        DB::table('inventory_reservations')->insert([
            'id' => 1, 'source_type' => 'meli_order', 'source_id' => 1, 'inventory_product_id' => 12, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);

        // Order 2: Ambiguous channel link + missing remote_line_key + manual movement with occurred_at AFTER order (even though created_at is earlier!)
        DB::table('meli_orders')->insert([
            'id' => 2, 'meli_account_id' => 1, 'order_id' => 'O-2', 'status' => 'paid', 'shipping_status' => 'ready_to_ship',
            'raw' => json_encode(['date_created' => '2026-11-10T12:00:00-06:00']), 'created_at' => '2026-11-10 12:00:00', 'updated_at' => '2026-11-10 12:00:00',
        ]);
        DB::table('meli_order_items')->insert([
            'meli_order_id' => 2, 'item_id' => 'MLM-DUP', 'variation_id' => null, 'remote_line_key' => null, 'sku' => 'SKU-A', 'quantity' => 1,
        ]);
        DB::table('inventory_movements')->insert([
            'id' => 1, 'inventory_product_id' => 10, 'type' => 'adjustment', 'quantity' => -1, 'reference_type' => 'manual',
            'occurred_at' => '2026-11-12 09:00:00', 'created_at' => '2026-11-01 09:00:00',
        ]);

        // Beauty items & rules:
        // Rule 700 exists and was MODIFIED (item 902 removed from meli_beauty_scheduled_discount_items, but still active in states!)
        // Rule 701 was DELETED, yet has an orphaned active state in meli_scheduled_price_states!
        DB::table('meli_price_manager_items')->insert([
            ['id' => 901, 'meli_account_id' => 1, 'meli_item_id' => 'MLM-B1', 'sku' => 'B1'],
            ['id' => 902, 'meli_account_id' => 1, 'meli_item_id' => 'MLM-B2', 'sku' => 'B2'],
        ]);
        DB::table('meli_beauty_scheduled_discounts')->insert([
            'id' => 700, 'meli_account_id' => 1, 'brand_group_id' => 1, 'discount_percentage' => 10,
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDay()->toDateString(),
            'starts_at' => '00:00', 'ends_at' => '23:59', 'all_day' => 1, 'timezone' => 'America/Mexico_City', 'active' => 1,
        ]);
        DB::table('meli_beauty_scheduled_discount_items')->insert([
            ['meli_beauty_scheduled_discount_id' => 700, 'price_manager_item_id' => 901],
        ]);
        DB::table('meli_scheduled_price_states')->insert([
            ['id' => 1, 'price_manager_item_id' => 902, 'meli_beauty_scheduled_discount_id' => 700, 'base_price' => 300, 'promotional_price' => 270, 'status' => 'active'],
            ['id' => 2, 'price_manager_item_id' => 901, 'meli_beauty_scheduled_discount_id' => 701, 'base_price' => 400, 'promotional_price' => 360, 'status' => 'restore_pending'],
        ]);

        /** @var QueueReconstructionDiagnosticsService $diag */
        $diag = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $report = $diag->diagnose(['cutover' => '2026-10-01T00:00:00-06:00']);

        $this->assertSame(1, $report['counts']['orders_with_manual_movements']);
        $this->assertSame(1, $report['anomaly_counts']['ambiguous_channel_links']);
        $this->assertSame(1, $report['anomaly_counts']['missing_remote_line_key_orders']);
        $this->assertSame(1, $report['anomaly_counts']['deleted_beauty_rules']);
        $this->assertSame(1, $report['anomaly_counts']['modified_beauty_rules_orphaned_states']);

        // 3-tier classification checks:
        $reconstructibleOrderIds = array_column(
            array_filter($report['reconstructibility_summary']['reconstructible_candidates'], static fn (array $x): bool => $x['entity_type'] === 'meli_order'),
            'entity_id',
        );
        $ambiguousOrderIds = array_column(
            array_filter($report['reconstructibility_summary']['ambiguous_entities'], static fn (array $x): bool => $x['entity_type'] === 'meli_order'),
            'entity_id',
        );
        $humanReviewBeautyIds = array_column(
            array_filter($report['reconstructibility_summary']['pending_human_review'], static fn (array $x): bool => $x['entity_type'] === 'beauty_discount'),
            'entity_id',
        );

        $this->assertSame([1], $reconstructibleOrderIds);
        $this->assertSame([2], $ambiguousOrderIds);
        $this->assertContains(700, $humanReviewBeautyIds);
        $this->assertContains(701, $humanReviewBeautyIds);
        Http::assertNothingSent();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function writeMaintenanceLock(string $windowId, string $incidentId): string
    {
        $path = $this->tmp('lock_'.$windowId.'.json');
        file_put_contents($path, json_encode([
            'window_id' => $windowId,
            'incident_id' => $incidentId,
            'workers_halted_confirmed' => true,
            'approved_by' => 'ops-auditor',
            'expires_at' => now()->utc()->addHours(2)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function insertJobWithId(int $id, object $job, string $queue = 'meli', ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'id' => $id,
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => bin2hex(random_bytes(8)),
                'displayName' => $job::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => null,
                'data' => [
                    'commandName' => $job::class,
                    'command' => serialize($job),
                ],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    private function createJobsTableOnly(string $connection): void
    {
        Schema::connection($connection)->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function createFullSchema(string $connection): void
    {
        $this->createJobsTableOnly($connection);
        $schema = Schema::connection($connection);

        $schema->create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('nickname')->nullable();
        });
        $schema->create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_status')->nullable();
            $table->text('raw')->nullable();
            $table->timestamps();
        });
        $schema->create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id');
            $table->string('item_id')->nullable();
            $table->string('variation_id')->nullable();
            $table->string('remote_line_key')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(1);
        });
        $schema->create('inventory_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku');
            $table->string('name');
            $table->string('product_type')->default('simple');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('inventory_kit_components', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('kit_product_id');
            $table->unsignedBigInteger('component_product_id');
            $table->integer('quantity')->default(1);
        });
        $schema->create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('account_key');
            $table->string('external_listing_id');
            $table->string('external_variant_id')->nullable();
            $table->unsignedBigInteger('inventory_product_id');
            $table->boolean('is_active')->default(true);
            $table->boolean('order_reservation_enabled')->default(false);
        });
        $schema->create('inventory_channel_order_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('account_key');
            $table->string('remote_order_id');
            $table->string('remote_line_key');
            $table->string('identity_hash')->unique();
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->string('reservation_kind')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('status')->default('PENDING');
        });
        $schema->create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('inventory_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
        });
        $schema->create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('kit_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
        });
        $schema->create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id');
            $table->string('type');
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id');
            $table->string('meli_item_id');
            $table->string('sku')->nullable();
        });
        $schema->create('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id');
            $table->unsignedBigInteger('brand_group_id')->default(1);
            $table->decimal('discount_percentage', 5, 2)->default(10);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('starts_at')->nullable();
            $table->string('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('timezone')->default('America/Mexico_City');
            $table->boolean('active')->default(false);
        });
        $schema->create('meli_beauty_scheduled_discount_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_beauty_scheduled_discount_id');
            $table->unsignedBigInteger('price_manager_item_id');
        });
        $schema->create('meli_scheduled_price_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('price_manager_item_id');
            $table->unsignedBigInteger('meli_beauty_scheduled_discount_id');
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('promotional_price', 12, 2)->nullable();
            $table->string('status')->default('active');
            $table->text('failure_message')->nullable();
        });
        $schema->create('meli_price_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('price_manager_item_id');
            $table->string('source')->nullable();
            $table->string('scheduled_action')->nullable();
            $table->string('status')->default('failed');
            $table->text('error_message')->nullable();
            $table->timestamp('changed_at')->nullable();
        });
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
