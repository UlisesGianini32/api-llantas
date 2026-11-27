<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Services\InventoryMeliOrderReservationCutover;
use App\Services\MercadoLibre\PriceManager\MeliBeautyPromotionWindow;
use App\Services\System\QueueRecovery\QueueDiagnosticArtifactWriter;
use App\Services\System\QueueRecovery\QueueManifestStreamReader;
use App\Services\System\QueueRecovery\QueueOrderExhaustiveClassifier;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryManifest;
use App\Services\System\QueueRecovery\QueueRecoveryPathGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R2-DIAG.1 Hardening Suite covering D1, D2, D3, D4, and concurrent interval-read behavior.
 */
class OpsQueuesDiagnoseExhaustiveHardeningTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('queue.default', 'database');
        Config::set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'sqlite',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        Config::set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        DB::purge('sqlite');

        $this->createSchema();
        $this->tempDir = storage_path('app/queue-recovery-diag-harden-'.bin2hex(random_bytes(4)));
        @mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        putenv('QUEUE_RECOVERY_MANIFEST_HMAC_KEY');
        unset($_ENV['QUEUE_RECOVERY_MANIFEST_HMAC_KEY']);
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // =========================================================================
    // D1: Exact Duplicate Job ID Detection & Strict Ascending Beyond 250,000
    // =========================================================================

    public function test_d1_01_rejects_non_consecutive_duplicate_after_250000_entries_with_bounded_memory(): void
    {
        $path = $this->tmp('d1_dup_after_250k.ndjson');
        $fh = fopen($path, 'wb');
        $this->assertNotFalse($fh);
        fwrite($fh, '{"record_type":"manifest_header","schema_version":2,"incident_id":"INC-D1-250K"}'."\n");
        for ($i = 1; $i <= 250002; $i++) {
            fwrite($fh, '{"id":'.$i.',"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}'."\n");
        }
        // Non-consecutive duplicate of id=5 AFTER crossing 250,000!
        fwrite($fh, '{"id":5,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}'."\n");
        fclose($fh);

        $reader = new QueueManifestStreamReader;
        try {
            $reader->readAndVerify($path, new QueuePayloadInspector);
            $this->fail('Expected QueueRecoveryException for non-consecutive duplicate after 250,000 entries.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_unordered_or_duplicate_beyond_tracking_limit', $e->reasonCode);
            $this->assertSame(5, $e->context['job_id']);
            $this->assertSame(250002, $e->context['max_seen_job_id']);
            $this->assertSame(250000, $e->context['exact_tracking_limit']);
        }

        $peakMiB = round(memory_get_peak_usage(true) / 1048576, 2);
        $this->assertLessThan(160.0, $peakMiB);
    }

    public function test_d1_02_rejects_lower_unseen_id_after_exact_tracking_limit(): void
    {
        // Use small tracking limit (10) to test an unseen ID strictly lower than max_seen_job_id after limit.
        $path = $this->tmp('d1_lower_id.ndjson');
        $lines = ['{"record_type":"manifest_header","schema_version":2,"incident_id":"INC-D1-LOWER"}'];
        for ($i = 1; $i <= 12; $i++) {
            $jid = $i * 10; // 10, 20, ..., 120
            $lines[] = '{"id":'.$jid.',"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}';
        }
        // Unseen ID 55 is strictly lower than max_seen_job_id (120) -> MUST be rejected fail-closed!
        $lines[] = '{"id":55,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}';
        file_put_contents($path, implode("\n", $lines)."\n");

        $reader = new QueueManifestStreamReader(new QueueRecoveryPathGuard, maxExactSeenJobTracking: 10);
        try {
            $reader->readAndVerify($path, new QueuePayloadInspector);
            $this->fail('Expected QueueRecoveryException for out-of-order ID below max observed beyond limit.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_unordered_or_duplicate_beyond_tracking_limit', $e->reasonCode);
            $this->assertSame(55, $e->context['job_id']);
        }
    }

    public function test_d1_03_detects_non_consecutive_duplicates_within_exact_limit_and_valid_ascending_above_250k(): void
    {
        // (a) Non-consecutive duplicate within first 250,000 entries
        $this->seedOrder(100);
        $dupPath = $this->tmp('d1_dup_within.ndjson');
        file_put_contents($dupPath, implode("\n", [
            '{"record_type":"manifest_header","schema_version":2,"incident_id":"INC-D1-IN"}',
            '{"id":10,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}',
            '{"id":20,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}',
            '{"id":10,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}',
        ])."\n");

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $report = $service->diagnose(['manifest_path' => $dupPath]);
        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(1, $report['manifest_job_accounting']['duplicate_manifest_job_id_entries']);
        $this->assertSame(2, $report['invariants']['accounted_order_jobs']);
        $this->assertSame(3, $report['manifest_job_accounting']['total_manifest_entries']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);

        // (b) Valid strictly ascending manifest exceeding 250,000 entries -> passes with bounded memory!
        $ascPath = $this->tmp('d1_valid_250005.ndjson');
        $fh = fopen($ascPath, 'wb');
        $this->assertNotFalse($fh);
        fwrite($fh, '{"record_type":"manifest_header","schema_version":2,"incident_id":"INC-D1-ASC"}'."\n");
        for ($i = 1; $i <= 250005; $i++) {
            fwrite($fh, '{"id":'.$i.',"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":100,"reserved_at":null}'."\n");
        }
        fclose($fh);

        $reader = new QueueManifestStreamReader;
        $parsed = $reader->readAndVerify($ascPath, new QueuePayloadInspector);
        $this->assertSame(250005, $parsed['job_accounting']['total_manifest_entries']);
        $this->assertSame(250005, $parsed['job_accounting']['reservation_unreserved_job_entries']);
        $this->assertTrue($parsed['job_accounting']['strict_ascending_mode_activated']);
        $this->assertSame(0, $parsed['job_accounting']['duplicate_manifest_job_id_entries']);
        $this->assertLessThan(160.0, round(memory_get_peak_usage(true) / 1048576, 2));
    }

    public function test_d1_04_compatibility_with_real_r2_1_dry_run_manifest(): void
    {
        $this->seedOrder(501);
        $this->seedOrder(502);
        $this->insertQueueJob(10, 501);
        $this->insertQueueJob(11, 501);
        $this->insertQueueJob(12, 502);

        $manifestPath = $this->tmp('real_r2_1_dryrun.json');
        /** @var QueuePruneService $prune */
        $prune = $this->app->make(QueuePruneService::class);
        $dryRun = $prune->dryRun([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'incident_id' => 'INC-REAL-R21',
        ]);

        $sha = $dryRun['manifest']['manifest_sha256'];

        /** @var QueueReconstructionDiagnosticsService $diag */
        $diag = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $report = $diag->diagnose([
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $sha,
        ]);

        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame('json_r2_v2', $report['manifest_scope']['format']);
        $this->assertTrue($report['manifest_scope']['expected_hash_verified']);
        $this->assertSame($sha, $report['manifest_scope']['manifest_sha256']);
        $this->assertSame(2, $report['invariants']['accounted_orders']);
        $this->assertSame(3, $report['invariants']['accounted_order_jobs']);
    }

    // =========================================================================
    // D2: Reserved-Only & Mixed Orders, Truncated Samples, Metadata Coherence & Live PK Check
    // =========================================================================

    public function test_d2_01_reserved_only_and_mixed_orders_never_disappear_and_zero_double_counting(): void
    {
        $this->seedOrder(601); // Exclusively reserved jobs (2 reserved, 0 unreserved)
        $this->seedOrder(602); // Mixed (1 unreserved, 1 reserved)
        // Order 603: missing locally + exclusively reserved!

        $path = $this->tmp('d2_reserved_orders.ndjson');
        file_put_contents($path, implode("\n", [
            '{"record_type":"manifest_header","schema_version":2,"incident_id":"INC-D2"}',
            '{"id":1,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":601,"reserved_at":1732550001}',
            '{"id":2,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":601,"reserved_at":1732550002}',
            '{"id":3,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":602,"reserved_at":null}',
            '{"id":4,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":602,"reserved_at":1732550003}',
            '{"id":5,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":603,"reserved_at":1732550004}',
            // Conflicting job_class vs entity_type
            '{"id":6,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"beauty_discount","entity_id":99,"reserved_at":null}',
            // Other module class
            '{"id":7,"job_class":"App\\\\Jobs\\\\SyncMeliClaimJob","entity_type":"meli_claim","entity_id":88,"reserved_at":null}',
        ])."\n");

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $report = $service->diagnose(['manifest_path' => $path]);

        $this->assertTrue($report['invariants']['all_passed']);
        // ALL 3 identifiable orders (including reserved-only 601 & 603!) MUST be accounted!
        $this->assertSame(3, $report['invariants']['distinct_orders_in_manifest']);
        $this->assertSame(3, $report['invariants']['accounted_orders']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame(3, $report['invariants']['sum_primary_partition_counts']);
        $this->assertSame(5, $report['invariants']['accounted_order_jobs']); // 1 unreserved + 4 reserved
        $this->assertSame(1, $report['invariants']['accounted_unreserved_order_jobs']);
        $this->assertSame(4, $report['invariants']['accounted_reserved_order_jobs']);

        $ja = $report['manifest_job_accounting'];
        $this->assertSame(7, $ja['total_manifest_entries']);
        $this->assertSame(2, $ja['reserved_only_orders_in_manifest']);
        $this->assertSame(1, $ja['mixed_reserved_and_unreserved_orders_in_manifest']);
        $this->assertSame(1, $ja['conflicting_identity_job_entries']);
        $this->assertSame(1, $ja['other_class_job_entries']);

        $byId = $this->indexByOrderId($report);
        // Order 601: reserved-only -> pending_human_review
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[601]['primary_classification']);
        $this->assertContains('manifest_reserved_jobs_only', $byId[601]['reason_codes']);
        $this->assertSame(0, $byId[601]['associated_unreserved_jobs_count']);
        $this->assertSame(2, $byId[601]['associated_reserved_jobs_count']);
        $this->assertSame(2, $byId[601]['associated_jobs_count']);
        $this->assertSame([1, 2], $byId[601]['associated_reserved_job_ids']);
        $this->assertSame([], $byId[601]['associated_unreserved_job_ids']);
        $this->assertTrue($byId[601]['available_evidence']['manifest_queue_state']['reserved_at_reflects_manifest_snapshot_only']);
        $this->assertFalse($byId[601]['available_evidence']['manifest_queue_state']['live_queue_state_verified']);

        // Order 602: mixed -> pending_human_review
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[602]['primary_classification']);
        $this->assertContains('manifest_reserved_jobs_detected', $byId[602]['reason_codes']);
        $this->assertSame([3], $byId[602]['associated_unreserved_job_ids']);
        $this->assertSame([4], $byId[602]['associated_reserved_job_ids']);
        $this->assertSame([3, 4], $byId[602]['associated_job_ids']);

        // Order 603: missing + reserved-only -> pending_human_review
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[603]['primary_classification']);
        $this->assertContains('order_missing_from_db', $byId[603]['reason_codes']);
        $this->assertContains('manifest_reserved_jobs_only', $byId[603]['reason_codes']);
    }

    public function test_d2_02_truncated_samples_metadata_conflicts_and_optional_pk_live_jobs_check(): void
    {
        $this->seedOrder(701); // 30 jobs -> truncated sample flag = true!
        $this->seedOrder(702); // Manifest account hint disagrees with DB (account 99 vs 1)

        // Live jobs table: job #1 is NO LONGER reserved (reserved_at = null), while manifest says reserved!
        $this->insertQueueJob(1, 701, reservedAt: null);
        $this->insertQueueJob(2, 701, reservedAt: 1732550999);

        $lines = ['{"record_type":"manifest_header","schema_version":2,"incident_id":"INC-D2-META"}'];
        $lines[] = '{"id":1,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":701,"reserved_at":1732550000}';
        for ($j = 2; $j <= 30; $j++) {
            $lines[] = '{"id":'.$j.',"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":701,"reserved_at":null}';
        }
        $lines[] = '{"id":31,"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":702,"meli_account_id":99,"reserved_at":null}';

        $path = $this->tmp('d2_meta.ndjson');
        file_put_contents($path, implode("\n", $lines)."\n");

        $jobsQueriesDefault = 0;
        $jobsQueriesLive = [];
        DB::connection('sqlite')->listen(function ($q) use (&$jobsQueriesDefault, &$jobsQueriesLive): void {
            if (str_contains(strtolower((string) $q->sql), '"jobs"') || str_contains(strtolower((string) $q->sql), '`jobs`')) {
                $jobsQueriesDefault++;
                $jobsQueriesLive[] = (string) $q->sql;
            }
        });

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);

        // 1) Default (verify_live_jobs = false): ZERO queries against `jobs`!
        $reportDefault = $service->diagnose(['manifest_path' => $path]);
        $this->assertSame(0, $jobsQueriesDefault);

        $byIdDefault = $this->indexByOrderId($reportDefault);
        $this->assertTrue($byIdDefault[701]['associated_job_ids_truncated']);
        $this->assertCount(25, $byIdDefault[701]['associated_job_ids']);
        $this->assertSame(30, $byIdDefault[701]['associated_jobs_count']);
        $this->assertSame(25, $byIdDefault[701]['associated_job_ids_sample_limit']);
        $this->assertTrue($byIdDefault[701]['associated_unreserved_job_ids_truncated']);
        $this->assertSame(29, $byIdDefault[701]['associated_unreserved_jobs_count']);
        $this->assertSame(1, $byIdDefault[701]['associated_reserved_jobs_count']);

        // Order 702: manifest account hint (99) conflicts with DB account (1) -> pending_human_review!
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byIdDefault[702]['primary_classification']);
        $this->assertContains('manifest_metadata_conflict', $byIdDefault[702]['reason_codes']);

        // 2) Optional verify_live_jobs = true: PK-only query selecting NO payload column!
        $jobsQueriesLive = [];
        $reportLive = $service->diagnose(['manifest_path' => $path, 'verify_live_jobs' => true]);
        $this->assertNotEmpty($jobsQueriesLive);
        foreach ($jobsQueriesLive as $sql) {
            $this->assertStringNotContainsString('payload', strtolower($sql));
            $this->assertStringContainsString('where "id" in', strtolower($sql));
        }
        $liveEv = $this->indexByOrderId($reportLive)[701]['available_evidence']['manifest_queue_state'];
        $this->assertTrue($liveEv['live_queue_state_verified']);
        $this->assertSame(2, $liveEv['live_queue_verification']['present_in_live_jobs']);
        $this->assertSame(1, $liveEv['live_queue_verification']['currently_reserved_in_live_jobs']);
    }

    // =========================================================================
    // D3: Safe Report Writing, Collisions, Non-Overwrite, Concurrent Dest, Partial Write/fsync & HMAC Hygiene
    // =========================================================================

    public function test_d3_01_rejects_overwrite_collisions_and_concurrent_destination_creation(): void
    {
        $this->seedOrder(801);
        $manifestPath = $this->writeV2Manifest('d3_m.json', [['id' => 1, 'entity_id' => 801]]);
        $backupPath = $this->tmp('d3_backup.ndjson');
        file_put_contents($backupPath, "backup-immutable\n");

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);

        // 1) Collision between output and manifest
        try {
            $service->diagnose(['manifest_path' => $manifestPath, 'output_path' => $manifestPath]);
            $this->fail('Expected path collision error.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('artifact_path_collision', $e->reasonCode);
        }

        // 2) Collision between ndjson_output and backup
        try {
            $service->diagnose(['manifest_path' => $manifestPath, 'backup_path' => $backupPath, 'ndjson_output_path' => $backupPath]);
            $this->fail('Expected path collision with backup error.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('artifact_path_collision', $e->reasonCode);
        }

        // 3) Existing summary file MUST NOT be overwritten
        $existingOut = $this->tmp('existing_summary.json');
        file_put_contents($existingOut, '{"keep":true}');
        try {
            $service->diagnose(['manifest_path' => $manifestPath, 'output_path' => $existingOut]);
            $this->fail('Expected artifact_already_exists.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('artifact_already_exists', $e->reasonCode);
            $this->assertSame('{"keep":true}', file_get_contents($existingOut));
        }

        // 4) Concurrent creation of NDJSON destination right before publish -> rejected & temp cleaned!
        $raceTarget = $this->tmp('race_out.ndjson');
        $writer = new QueueDiagnosticArtifactWriter;
        $writer->setBeforePublishHook(static function (string $tmpPath, string $destPath): void {
            file_put_contents($destPath, "concurrent-winner\n");
        });

        $raceService = $this->makeDiagnosticsService(artifactWriter: $writer);
        try {
            $raceService->diagnose(['manifest_path' => $manifestPath, 'ndjson_output_path' => $raceTarget]);
            $this->fail('Expected artifact_already_exists on concurrent destination creation.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('artifact_already_exists', $e->reasonCode);
            $this->assertSame("concurrent-winner\n", file_get_contents($raceTarget));
            $this->assertSame([], glob($raceTarget.'.tmp.*') ?: []);
        }

        // Backup file untouched
        $this->assertSame("backup-immutable\n", file_get_contents($backupPath));
    }

    public function test_d3_02_partial_write_and_fsync_failure_fail_closed_without_completing_announcement(): void
    {
        $this->seedOrder(802);
        $manifestPath = $this->writeV2Manifest('d3_io.json', [['id' => 1, 'entity_id' => 802]]);

        // Simulate partial write (ENOSPC)
        $partialWriter = new QueueDiagnosticArtifactWriter(
            new QueueRecoveryPathGuard,
            writeCallback: static fn ($h, string $bytes): int => 1,
        );
        $this->app->instance(QueueReconstructionDiagnosticsService::class, $this->makeDiagnosticsService(artifactWriter: $partialWriter));

        $ndjsonPath = $this->tmp('partial_fail.ndjson');
        $summaryPath = $this->tmp('partial_fail.json');
        $code = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--manifest' => $manifestPath,
            '--ndjson-output' => $ndjsonPath,
            '--output' => $summaryPath,
        ]);

        $out1 = Artisan::output();
        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('COMPLETADO', $out1);
        $this->assertStringContainsString('io_write_failed', $out1);
        $this->assertFileDoesNotExist($ndjsonPath);
        $this->assertFileDoesNotExist($summaryPath);
        $this->assertSame([], glob($ndjsonPath.'.tmp.*') ?: []);

        // Simulate fsync() failure
        $fsyncFailWriter = new QueueDiagnosticArtifactWriter(
            new QueueRecoveryPathGuard,
            fsyncCallback: static fn ($h): bool => false,
        );
        $this->app->instance(QueueReconstructionDiagnosticsService::class, $this->makeDiagnosticsService(artifactWriter: $fsyncFailWriter));

        $code2 = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--manifest' => $manifestPath,
            '--ndjson-output' => $ndjsonPath,
            '--output' => $summaryPath,
        ]);

        $out2 = Artisan::output();
        $this->assertSame(1, $code2);
        $this->assertStringNotContainsString('COMPLETADO', $out2);
        $this->assertStringContainsString('io_sync_failed', $out2);
        $this->assertFileDoesNotExist($ndjsonPath);
        $this->assertFileDoesNotExist($summaryPath);
    }

    public function test_d3_03_joint_publication_completion_marker_and_hmac_env_secret_hygiene(): void
    {
        $this->seedOrder(803);
        $secret = 'SUPER_SECRET_HMAC_KEY_NEVER_IN_OUTPUT_9911';
        $manifestPath = $this->writeV2Manifest('d3_hmac.json', [['id' => 1, 'entity_id' => 803]], $secret);

        putenv('QUEUE_RECOVERY_MANIFEST_HMAC_KEY='.$secret);
        $_ENV['QUEUE_RECOVERY_MANIFEST_HMAC_KEY'] = $secret;

        $ndjsonPath = $this->tmp('joint_out.ndjson');
        $summaryPath = $this->tmp('joint_summary.json');
        $markerPath = $this->tmp('joint_complete.marker.json');

        $code = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--manifest' => $manifestPath,
            '--hmac-key-env' => 'QUEUE_RECOVERY_MANIFEST_HMAC_KEY',
            '--ndjson-output' => $ndjsonPath,
            '--output' => $summaryPath,
            '--completion-marker' => $markerPath,
        ]);

        $cliOutput = Artisan::output();
        $this->assertSame(0, $code, $cliOutput);
        $this->assertStringContainsString('COMPLETADO', $cliOutput);

        // Secret NEVER appears anywhere in CLI output or written artifacts!
        $summaryRaw = (string) file_get_contents($summaryPath);
        $ndjsonRaw = (string) file_get_contents($ndjsonPath);
        $markerRaw = (string) file_get_contents($markerPath);
        $this->assertStringNotContainsString($secret, $cliOutput);
        $this->assertStringNotContainsString($secret, $summaryRaw);
        $this->assertStringNotContainsString($secret, $ndjsonRaw);
        $this->assertStringNotContainsString($secret, $markerRaw);

        $summary = json_decode($summaryRaw, true, 64, JSON_THROW_ON_ERROR);
        $marker = json_decode($markerRaw, true, 64, JSON_THROW_ON_ERROR);

        $this->assertTrue($summary['manifest_scope']['hmac_authenticated']);
        $this->assertTrue($summary['manifest_scope']['checksum_is_not_authentication']);
        $this->assertTrue($summary['publication_protocol']['summary_published']);
        $this->assertSame('complete', $marker['status']);
        $this->assertSame(hash_file('sha256', $ndjsonPath), $marker['ndjson_output']['sha256']);
        $this->assertSame(hash_file('sha256', $summaryPath), $marker['summary_output']['sha256']);
    }

    // =========================================================================
    // D4: Single-FD Manifest Integrity, Between-Passes Tampering, Duplicate Keys & R2.1 Parity
    // =========================================================================

    public function test_d4_01_concurrent_modification_or_replacement_between_passes_aborts_and_publishes_nothing(): void
    {
        $this->seedOrder(901);
        $this->seedOrder(902);
        $manifestPath = $this->writeV2Manifest('d4_toctou.json', [['id' => 1, 'entity_id' => 901]]);

        // 1) In-place same-length byte tampering between Pass 1 and Pass 2
        $reader = new QueueManifestStreamReader;
        $reader->setBetweenPassesHook(static function (string $path): void {
            $raw = (string) file_get_contents($path);
            $tampered = str_replace('"entity_id": 901', '"entity_id": 902', $raw);
            file_put_contents($path, $tampered);
        });

        $service = $this->makeDiagnosticsService(streamReader: $reader);
        $ndjsonPath = $this->tmp('d4_aborted.ndjson');
        $summaryPath = $this->tmp('d4_aborted.json');

        try {
            $service->diagnose([
                'manifest_path' => $manifestPath,
                'ndjson_output_path' => $ndjsonPath,
                'output_path' => $summaryPath,
            ]);
            $this->fail('Expected manifest_concurrent_modification or manifest_tampered.');
        } catch (QueueRecoveryException $e) {
            $this->assertContains($e->reasonCode, ['manifest_concurrent_modification', 'manifest_tampered']);
        }

        // ZERO report files published!
        $this->assertFileDoesNotExist($ndjsonPath);
        $this->assertFileDoesNotExist($summaryPath);

        // 2) Truncation between Pass 1 and Pass 2
        $manifestPath2 = $this->writeV2Manifest('d4_trunc.json', [['id' => 1, 'entity_id' => 901]]);
        $reader2 = new QueueManifestStreamReader;
        $reader2->setBetweenPassesHook(static function (string $path): void {
            $fh = fopen($path, 'r+b');
            if ($fh !== false) {
                ftruncate($fh, 20);
                fclose($fh);
            }
        });
        $service2 = $this->makeDiagnosticsService(streamReader: $reader2);
        try {
            $service2->diagnose(['manifest_path' => $manifestPath2, 'output_path' => $summaryPath]);
            $this->fail('Expected manifest_concurrent_modification on truncation.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_concurrent_modification', $e->reasonCode);
        }
        $this->assertFileDoesNotExist($summaryPath);
    }

    public function test_d4_02_rejects_duplicate_json_keys_trailing_garbage_and_preserves_r2_1_parity(): void
    {
        $reader = new QueueManifestStreamReader;
        $inspector = new QueuePayloadInspector;

        // 1) Duplicate top-level key
        $dupTop = $this->tmp('d4_dup_top.json');
        file_put_contents($dupTop, '{"schema_version":2,"incident_id":"A","incident_id":"B","allowed_jobs":["App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob"],"entries":[],"manifest_sha256":"'.str_repeat('a', 64).'"}');
        try {
            $reader->readAndVerify($dupTop, $inspector);
            $this->fail('Expected manifest_duplicate_json_key.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_duplicate_json_key', $e->reasonCode);
        }

        // 2) Duplicate key inside entry object
        $dupEntry = $this->tmp('d4_dup_entry.json');
        file_put_contents($dupEntry, '{"schema_version":1,"incident_id":"A","entries":[{"id":1,"entity_id":10,"entity_id":99}]}');
        try {
            $reader->readAndVerify($dupEntry, $inspector);
            $this->fail('Expected manifest_duplicate_json_key inside entry.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_duplicate_json_key', $e->reasonCode);
        }

        // 3) Trailing garbage after root JSON object
        $trailing = $this->tmp('d4_trailing.json');
        file_put_contents($trailing, '{"schema_version":1,"incident_id":"A","entries":[]} GARBAGE');
        try {
            $reader->readAndVerify($trailing, $inspector);
            $this->fail('Expected manifest_invalid on trailing garbage.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('manifest_invalid', $e->reasonCode);
        }

        // 4) Strict R2.1 v2 parity: reserved_at !== null MUST be rejected just like QueueRecoveryManifest!
        $reservedV2 = $this->tmp('d4_reserved_v2.json');
        (new QueueRecoveryManifest)->writeAtomic($reservedV2, [
            'incident_id' => 'INC-V2-RES',
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'authorized_upper_bound' => 10,
            'last_scanned_id' => 10,
            'next_start_id' => 10,
            'entries' => [[
                'id' => 1,
                'job_class' => QueuePayloadInspector::JOB_RESERVATIONS,
                'queue' => 'meli',
                'attempts' => 0,
                'available_at' => 1732550000,
                'created_at' => 1732550000,
                'entity_type' => 'meli_order',
                'entity_id' => 901,
                'reserved_at' => 1732550055,
                'payload_hash' => hash('sha256', 'p1'),
                'row_sha256' => hash('sha256', 'r1'),
            ]],
        ]);
        try {
            $reader->readAndVerify($reservedV2, $inspector);
            $this->fail('Expected reserved_jobs_detected on strict R2.1 v2 manifest.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('reserved_jobs_detected', $e->reasonCode);
        }
    }

    // =========================================================================
    // Concurrent Source Data Changes During Chunked Interval Read (No Long TX)
    // =========================================================================

    public function test_concurrent_source_data_changes_between_chunks_documented_as_interval_read(): void
    {
        // Seed 60 orders (chunk=50 -> 2 chunks). Mutate order #55 between chunk 1 and chunk 2!
        for ($i = 1; $i <= 60; $i++) {
            $this->seedOrder($i);
        }

        $entries = [];
        for ($i = 1; $i <= 60; $i++) {
            $entries[] = ['id' => $i, 'entity_id' => $i];
        }
        $manifestPath = $this->writeV2Manifest('concurrent_chunks.json', $entries);

        $service = $this->makeDiagnosticsService();
        $service->setBetweenOrderChunksHook(static function (int $chunkNumber): void {
            if ($chunkNumber === 2) {
                // Simulate concurrent external order status change to cancelled + active reservation while diagnostics is running!
                DB::table('meli_orders')->where('id', 55)->update([
                    'status' => 'cancelled',
                    'shipping_status' => 'cancelled',
                    'raw' => json_encode(['date_created' => '2026-11-10T10:00:00-06:00', 'status' => 'cancelled']),
                ]);
                DB::table('inventory_reservations')->insert([
                    'source_type' => 'meli_order',
                    'source_id' => 55,
                    'inventory_product_id' => 10,
                    'quantity' => 1,
                    'status' => 'ACTIVE',
                ]);
            }
        });

        $report = $service->diagnose(['manifest_path' => $manifestPath, 'chunk' => 50]);

        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(60, $report['invariants']['accounted_orders']);
        $this->assertSame('chunked_interval_reads_not_global_snapshot', $report['diagnostic_snapshot_semantics']['consistency_model']);
        $this->assertFalse($report['diagnostic_snapshot_semantics']['holds_long_db_transaction']);

        $byId = $this->indexByOrderId($report);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES, $byId[55]['primary_classification']);
        $this->assertContains('cancelled_with_active_reservation', $byId[55]['reason_codes']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeDiagnosticsService(
        ?QueueManifestStreamReader $streamReader = null,
        ?QueueDiagnosticArtifactWriter $artifactWriter = null,
    ): QueueReconstructionDiagnosticsService {
        return new QueueReconstructionDiagnosticsService(
            new QueueRecoveryManifest,
            new QueuePayloadInspector,
            $this->app->make(InventoryMeliOrderReservationCutover::class),
            $this->app->make(MeliBeautyPromotionWindow::class),
            $streamReader ?? new QueueManifestStreamReader,
            new QueueOrderExhaustiveClassifier,
            new QueueRecoveryPathGuard,
            $artifactWriter ?? new QueueDiagnosticArtifactWriter,
        );
    }

    /** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
    private function indexByOrderId(array $report): array
    {
        $map = [];
        foreach ($report['exhaustive_classification']['entities'] as $e) {
            $map[(int) $e['local_order_id']] = $e;
        }

        return $map;
    }

    private function seedOrder(int $oid): void
    {
        if (! DB::table('meli_accounts')->where('id', 1)->exists()) {
            DB::table('meli_accounts')->insert(['id' => 1, 'nickname' => 'ACC-1']);
        }
        if (! DB::table('inventory_products')->where('id', 10)->exists()) {
            DB::table('inventory_products')->insert(['id' => 10, 'sku' => 'SKU-10', 'name' => 'Prod 10', 'product_type' => 'simple', 'is_active' => 1]);
            DB::table('inventory_channel_links')->insert([
                'id' => 1, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-10',
                'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'order_reservation_enabled' => 1,
            ]);
        }

        DB::table('meli_orders')->insert([
            'id' => $oid,
            'meli_account_id' => 1,
            'order_id' => 'O-'.$oid,
            'status' => 'paid',
            'shipping_status' => 'ready_to_ship',
            'raw' => json_encode(['date_created' => '2026-11-10T10:00:00-06:00', 'status' => 'paid'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-10 10:00:00',
            'updated_at' => '2026-11-10 10:00:00',
        ]);
        DB::table('meli_order_items')->insert([
            'meli_order_id' => $oid, 'item_id' => 'MLM-10', 'variation_id' => null,
            'remote_line_key' => 'MLM-10:', 'sku' => 'SKU-10', 'quantity' => 1,
        ]);
    }

    private function insertQueueJob(int $jobId, int $orderId, ?int $reservedAt = null): void
    {
        $job = new ReconcileInventoryMeliOrderReservationsJob($orderId);
        DB::table('jobs')->insert([
            'id' => $jobId,
            'queue' => 'meli',
            'payload' => json_encode([
                'uuid' => bin2hex(random_bytes(8)),
                'displayName' => ReconcileInventoryMeliOrderReservationsJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [
                    'commandName' => ReconcileInventoryMeliOrderReservationsJob::class,
                    'command' => serialize($job),
                ],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => 1732550000,
            'created_at' => 1732550000,
        ]);
    }

    /** @param list<array{id: int, entity_id: int}> $items */
    private function writeV2Manifest(string $name, array $items, ?string $hmacKey = null): string
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
                'payload_hash' => hash('sha256', 'payload-'.$jid),
                'row_sha256' => hash('sha256', 'row-'.$jid),
            ];
        }

        $path = $this->tmp($name);
        (new QueueRecoveryManifest)->writeAtomic($path, [
            'incident_id' => 'INC-R2-DIAG-H',
            'generated_at' => '2026-11-26T00:00:00Z',
            'mode' => 'dry_run',
            'queue_connection' => 'database',
            'connection' => 'sqlite',
            'database' => ':memory:',
            'table' => 'jobs',
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'start_id' => 0,
            'authorized_upper_bound' => $maxId,
            'last_scanned_id' => $maxId,
            'last_candidate_id' => $maxId > 0 ? $maxId : null,
            'next_start_id' => $maxId,
            'is_partial' => false,
            'stop_reason' => 'completed_upper_bound',
            'totals' => ['scanned' => count($entries), 'candidates' => count($entries)],
            'anomalies' => [],
            'entries' => $entries,
        ], $hmacKey);

        return $path;
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('nickname')->nullable();
        });
        $schema->create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_id')->nullable();
            $table->string('shipping_status')->nullable();
            $table->text('shipping_raw')->nullable();
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
            $table->boolean('stock_sync_enabled')->default(false);
            $table->boolean('order_reservation_enabled')->default(false);
            $table->string('remote_user_product_id')->nullable();
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
            $table->string('diagnostic_code')->nullable();
        });
        $schema->create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('inventory_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
            $table->text('metadata')->nullable();
        });
        $schema->create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('kit_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
            $table->text('metadata')->nullable();
        });
        $schema->create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id');
            $table->string('type');
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_key')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('inventory_meli_order_reconciliations', function (Blueprint $table): void {
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

    private function tmp(string $file): string
    {
        return $this->tempDir.DIRECTORY_SEPARATOR.$file;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
