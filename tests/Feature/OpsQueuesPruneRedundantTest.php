<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportExcelFromTelegramJob;
use App\Jobs\ProcessMeliBeautyScheduledPriceJob;
use App\Jobs\ProcessMeliMessageNotification;
use App\Jobs\PushMeliSharedStockGroupJob;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Jobs\SyncInventoryMeliStockLinkJob;
use App\Jobs\SyncMeliAccountPublicationsJob;
use App\Jobs\SyncMeliClaimJob;
use App\Jobs\SyncMeliPriceManagerItemsJob;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueRecoveryBackupService;
use App\Services\System\QueueRecovery\QueueRecoveryDiskChecker;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryLock;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OpsQueuesPruneRedundantTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r2_queue_recovery_'.bin2hex(random_bytes(6));
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
        $this->createTestSchema('sqlite');

        // Default deterministic disk (> 20 GiB free) and zero active workers for isolated tests.
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
    // 1. Dry-run sin modificaciones
    // =========================================================================
    public function test_01_dry_run_is_default_mutually_exclusive_with_apply_and_never_mutates(): void
    {
        $this->seedAccountAndOrder(1, 1001, '200001001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(1001);
        $this->insertReservationJob(1001);
        $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'msg-1']);

        $manifestPath = $this->tmp('dry_manifest.json');
        $auditPath = $this->tmp('dry_audit.ndjson');

        $codeInvalid = Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
        ]);
        $this->assertSame(2, $codeInvalid);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--audit-log' => $auditPath,
            '--incident' => 'INC-TEST-01',
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(3, DB::table('jobs')->count());
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('INC-TEST-01', $manifest['incident_id']);
        $this->assertSame(2, $manifest['totals']['candidates']);
        $this->assertSame(1, $manifest['totals']['preserved_other_modules']);
        $this->assertSame(1, $manifest['entries'][0]['meli_account_id']);
        $this->assertSame(1001, $manifest['entries'][0]['entity_id']);
        $this->assertSame('200001001', $manifest['entries'][0]['remote_order_id']);
        $this->assertArrayHasKey('authorized_upper_bound', $manifest);
        $this->assertArrayHasKey('last_scanned_id', $manifest);
        $this->assertArrayHasKey('next_start_id', $manifest);
        $this->assertFalse($manifest['is_partial']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $manifest['manifest_sha256']);
        Http::assertNothingSent();
    }

    // =========================================================================
    // 2. Lista blanca de clases
    // =========================================================================
    public function test_02_whitelist_rejects_wildcards_and_unauthorized_classes(): void
    {
        $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'msg-1']);

        foreach ([
            '*',
            'App\\Jobs\\*',
            ProcessMeliMessageNotification::class,
            SyncMeliClaimJob::class,
            PushMeliSharedStockGroupJob::class,
            SyncInventoryMeliStockLinkJob::class,
            ImportExcelFromTelegramJob::class,
            SyncMeliAccountPublicationsJob::class,
            SyncMeliPriceManagerItemsJob::class,
        ] as $forbidden) {
            $code = Artisan::call('ops:queues-prune-redundant', [
                '--dry-run' => true,
                '--job' => [$forbidden],
            ]);
            $this->assertSame(1, $code, "Class '{$forbidden}' should have been rejected.");
            $this->assertStringContainsString('invalid_job_whitelist', Artisan::output());
        }

        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // 3. Protección de trabajos de otros módulos
    // =========================================================================
    public function test_03_protects_all_other_production_module_jobs_intact(): void
    {
        $this->seedAccountAndOrder(1, 2001, '200002001', 'paid', 'ready_to_ship');
        $this->seedBeautyRule(1, 50);

        $preservedIds = [
            $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'm1']),
            $this->insertOtherModuleJob(SyncMeliClaimJob::class, ['claimId' => 'c1']),
            $this->insertOtherModuleJob(PushMeliSharedStockGroupJob::class, ['groupId' => 11]),
            $this->insertOtherModuleJob(SyncInventoryMeliStockLinkJob::class, ['linkId' => 22]),
            $this->insertOtherModuleJob(ImportExcelFromTelegramJob::class, ['fileName' => 'lista.xlsx'], 'default'),
            $this->insertOtherModuleJob(SyncMeliAccountPublicationsJob::class, ['accountId' => 1]),
            $this->insertOtherModuleJob(SyncMeliPriceManagerItemsJob::class, ['meliAccountId' => 1]),
        ];

        $this->insertReservationJob(2001);
        $this->insertBeautyJob(50);

        $beforeSnapshots = DB::table('jobs')->whereIn('id', $preservedIds)->orderBy('id')->get()->keyBy('id');

        $manifestPath = $this->tmp('m03.json');
        $backupPath = $this->tmp('b03.ndjson');
        $auditPath = $this->tmp('a03.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-03');

        $sha = $this->runDryRunAndBackup(
            [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            $manifestPath,
            $backupPath,
        );

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-03',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(0, $code, Artisan::output());

        $this->assertSame(count($preservedIds), DB::table('jobs')->count());
        $afterSnapshots = DB::table('jobs')->whereIn('id', $preservedIds)->orderBy('id')->get()->keyBy('id');
        foreach ($preservedIds as $pid) {
            $this->assertEquals($beforeSnapshots->get($pid), $afterSnapshots->get($pid));
        }
    }

    // =========================================================================
    // 4. Protección de trabajos reservados
    // =========================================================================
    public function test_04_protects_reserved_jobs_in_dry_run_and_aborts_if_reserved_before_apply(): void
    {
        $this->seedAccountAndOrder(1, 3001, '200003001', 'paid', 'ready_to_ship');
        $unreservedId = $this->insertReservationJob(3001);
        $reservedId = $this->insertReservationJob(3001, reservedAt: time());

        $manifestPath = $this->tmp('m04.json');
        $backupPath = $this->tmp('b04.ndjson');
        $auditPath = $this->tmp('a04.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-04');

        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $manifest['totals']['candidates']);
        $this->assertSame(1, $manifest['totals']['preserved_reserved']);
        $this->assertSame([$reservedId], $manifest['anomalies']['reserved_job_ids']);

        DB::table('jobs')->where('id', $unreservedId)->update(['reserved_at' => time()]);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-04',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('reserved_jobs_detected', Artisan::output());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    // =========================================================================
    // 5. Payload JSON inválido
    // =========================================================================
    public function test_05_preserves_invalid_json_payloads_without_unserialize(): void
    {
        $this->seedAccountAndOrder(1, 4001, '200004001', 'paid', 'ready_to_ship');
        $invalidId1 = $this->insertRawJob('{not valid json at all');
        $invalidId2 = $this->insertRawJob('"just a scalar string"');
        $validId = $this->insertReservationJob(4001);

        $manifestPath = $this->tmp('m05.json');
        $backupPath = $this->tmp('b05.ndjson');
        $auditPath = $this->tmp('a05.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-05');

        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $manifest['totals']['candidates']);
        $this->assertSame(2, $manifest['totals']['preserved_invalid_json']);
        $this->assertSame([$invalidId1, $invalidId2], $manifest['anomalies']['invalid_payload_job_ids']);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-05',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertDatabaseMissing('jobs', ['id' => $validId]);
        $this->assertDatabaseHas('jobs', ['id' => $invalidId1]);
        $this->assertDatabaseHas('jobs', ['id' => $invalidId2]);
    }

    // =========================================================================
    // 6. Payload con clase o identificadores inconsistentes
    // =========================================================================
    public function test_06_preserves_payloads_with_inconsistent_class_or_identifiers(): void
    {
        $this->seedAccountAndOrder(1, 5001, '200005001', 'paid', 'ready_to_ship');

        $mismatchClassId = $this->insertRawJob(json_encode([
            'displayName' => QueuePayloadInspector::JOB_RESERVATIONS,
            'data' => [
                'commandName' => QueuePayloadInspector::JOB_RESERVATIONS,
                'command' => serialize(new ProcessMeliMessageNotification(['resource' => 'evil'])),
            ],
        ], JSON_THROW_ON_ERROR));

        $mismatchCmdNameId = $this->insertRawJob(json_encode([
            'displayName' => QueuePayloadInspector::JOB_RESERVATIONS,
            'data' => [
                'commandName' => ProcessMeliMessageNotification::class,
                'command' => serialize(new ReconcileInventoryMeliOrderReservationsJob(5001)),
            ],
        ], JSON_THROW_ON_ERROR));

        $zeroOrderId = $this->insertRawJob(json_encode([
            'displayName' => QueuePayloadInspector::JOB_RESERVATIONS,
            'data' => [
                'commandName' => QueuePayloadInspector::JOB_RESERVATIONS,
                'command' => 'O:52:"App\\Jobs\\ReconcileInventoryMeliOrderReservationsJob":1:{s:11:"meliOrderId";i:0;}',
            ],
        ], JSON_THROW_ON_ERROR));

        $corruptBeautyId = $this->insertRawJob(json_encode([
            'displayName' => QueuePayloadInspector::JOB_BEAUTY_PRICES,
            'data' => [
                'commandName' => QueuePayloadInspector::JOB_BEAUTY_PRICES,
                'command' => 'O:43:"App\\Jobs\\ProcessMeliBeautyScheduledPriceJob":2:{s:10:"discountId";i:9;s:10:"meliItemId";s:99:"MLM123";}',
            ],
        ], JSON_THROW_ON_ERROR));

        $validId = $this->insertReservationJob(5001);

        $manifestPath = $this->tmp('m06.json');
        $this->runDryRunAndBackup(
            [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            $manifestPath,
            $this->tmp('b06.ndjson'),
        );

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $manifest['totals']['candidates']);
        $this->assertSame(4, $manifest['totals']['preserved_inconsistent']);
        $this->assertSame([$validId], array_column($manifest['entries'], 'id'));

        foreach ([$mismatchClassId, $mismatchCmdNameId, $zeroOrderId, $corruptBeautyId] as $preserved) {
            $this->assertDatabaseHas('jobs', ['id' => $preserved]);
        }
    }

    // =========================================================================
    // 7. Coincidencia exacta de clase
    // =========================================================================
    public function test_07_requires_exact_fqcn_class_match(): void
    {
        $this->seedAccountAndOrder(1, 6001, '200006001', 'paid', 'ready_to_ship');
        $exactId = $this->insertReservationJob(6001);

        $similarClass = 'App\\Jobs\\ReconcileInventoryMeliOrderReservationsJobV2';
        $similarId = $this->insertRawJob(json_encode([
            'displayName' => $similarClass,
            'data' => [
                'commandName' => $similarClass,
                'command' => sprintf('O:%d:"%s":1:{s:11:"meliOrderId";i:6001;}', strlen($similarClass), $similarClass),
            ],
        ], JSON_THROW_ON_ERROR));

        $manifestPath = $this->tmp('m07.json');
        $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $this->tmp('b07.ndjson'));

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame([$exactId], array_column($manifest['entries'], 'id'));
        $this->assertSame(1, $manifest['totals']['preserved_other_modules']);
        $this->assertDatabaseHas('jobs', ['id' => $similarId]);
    }

    // =========================================================================
    // 8. Respeto de límites por lote
    // =========================================================================
    public function test_08_enforces_chunk_cap_and_max_delete_limits(): void
    {
        $this->seedAccountAndOrder(1, 7001, '200007001', 'paid', 'ready_to_ship');
        for ($i = 0; $i < 15; $i++) {
            $this->insertReservationJob(7001);
        }

        $codeOversize = Artisan::call('ops:queues-prune-redundant', [
            '--dry-run' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--chunk' => 501,
        ]);
        $this->assertSame(1, $codeOversize);
        $this->assertStringContainsString('invalid_chunk_size', Artisan::output());

        $manifestPath = $this->tmp('m08.json');
        $backupPath = $this->tmp('b08.ndjson');
        $auditPath = $this->tmp('a08.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-08');

        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath, maxDelete: 10, chunk: 3);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(10, $manifest['totals']['candidates']);
        $this->assertTrue($manifest['is_partial']);
        $this->assertSame('max_delete_reached', $manifest['stop_reason']);

        $codeApply = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-08',
            '--maintenance-lock-file' => $lockPath,
            '--chunk' => 2,
            '--max-delete' => 6,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(0, $codeApply, Artisan::output());
        $this->assertSame(9, DB::table('jobs')->count());
    }

    // =========================================================================
    // 9. Respeto de límite superior de PK
    // =========================================================================
    public function test_09_respects_upper_pk_bound_end_id(): void
    {
        $this->seedAccountAndOrder(1, 8001, '200008001', 'paid', 'ready_to_ship');
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->insertReservationJob(8001);
        }
        $cutoffId = $ids[4];

        $manifestPath = $this->tmp('m09.json');
        $backupPath = $this->tmp('b09.ndjson');
        $auditPath = $this->tmp('a09.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-09');

        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath, endId: $cutoffId);

        $codeExceed = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-09',
            '--maintenance-lock-file' => $lockPath,
            '--end-id' => $cutoffId + 1,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(1, $codeExceed);
        $this->assertStringContainsString('upper_bound_violation', Artisan::output());

        $codeOk = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-09',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(0, $codeOk, Artisan::output());

        $remainingIds = DB::table('jobs')->orderBy('id')->pluck('id')->all();
        $this->assertSame(array_slice($ids, 5), $remainingIds);
    }

    // =========================================================================
    // 10. Reanudación tras interrupción
    // =========================================================================
    public function test_10_resumes_cleanly_after_interruption_using_persistent_checkpoint(): void
    {
        $this->seedAccountAndOrder(1, 9001, '200009001', 'paid', 'ready_to_ship');
        for ($i = 0; $i < 6; $i++) {
            $this->insertReservationJob(9001);
        }

        $manifestPath = $this->tmp('m10.json');
        $backupPath = $this->tmp('b10.ndjson');
        $auditPath = $this->tmp('a10.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-10');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $service->setShouldInterruptChecker(static fn (int $batchesDone): bool => $batchesDone >= 1);

        $firstRun = $service->apply([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $sha,
            'backup_path' => $backupPath,
            'maintenance_window' => 'MW-TEST-10',
            'maintenance_lock_file' => $lockPath,
            'chunk' => 2,
            'max_delete' => 10,
            'sleep_ms' => 0,
            'audit_log_path' => $auditPath,
        ]);

        $this->assertSame('interrupted', $firstRun['status']);
        $this->assertTrue($firstRun['interrupted']);
        $this->assertSame(2, $firstRun['deleted_this_run']);
        $this->assertNull($firstRun['next_start_id']);
        $this->assertSame(4, DB::table('jobs')->count());

        $service->setShouldInterruptChecker(null);
        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-TEST-10',
                'maintenance_lock_file' => $lockPath,
                'chunk' => 2,
                'max_delete' => 10,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
                'resume' => false,
            ]);
            $this->fail('Expected checkpoint_requires_resume exception.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('checkpoint_requires_resume', $e->reasonCode);
        }

        $secondRun = $service->apply([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $sha,
            'backup_path' => $backupPath,
            'maintenance_window' => 'MW-TEST-10',
            'maintenance_lock_file' => $lockPath,
            'chunk' => 2,
            'max_delete' => 10,
            'sleep_ms' => 0,
            'audit_log_path' => $auditPath,
            'resume' => true,
        ]);

        $this->assertSame('completed', $secondRun['status']);
        $this->assertSame(4, $secondRun['deleted_this_run']);
        $this->assertSame(6, $secondRun['total_deleted']);
        $this->assertNotNull($secondRun['next_start_id']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    // =========================================================================
    // 11. Idempotencia del comando
    // =========================================================================
    public function test_11_command_is_idempotent_on_repeated_apply_after_completion(): void
    {
        $this->seedAccountAndOrder(1, 10001, '200010001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(10001);
        $this->insertReservationJob(10001);
        $keptId = $this->insertOtherModuleJob(SyncMeliClaimJob::class, ['claimId' => '999']);

        $manifestPath = $this->tmp('m11.json');
        $backupPath = $this->tmp('b11.ndjson');
        $auditPath = $this->tmp('a11.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-11');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $params = [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-11',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ];

        $this->assertSame(0, Artisan::call('ops:queues-prune-redundant', $params));
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertSame(0, Artisan::call('ops:queues-prune-redundant', $params));
        $this->assertStringContainsString('already_completed', Artisan::output());
        $this->assertDatabaseHas('jobs', ['id' => $keptId]);
    }

    // =========================================================================
    // 12. Aborto por espacio insuficiente
    // =========================================================================
    public function test_12_aborts_when_free_disk_space_is_below_8_gib_threshold(): void
    {
        $this->seedAccountAndOrder(1, 11001, '200011001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(11001);

        $manifestPath = $this->tmp('m12.json');
        $backupPath = $this->tmp('b12.ndjson');
        $auditPath = $this->tmp('a12.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-12');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $this->app->singleton(QueueRecoveryDiskChecker::class, fn () => new QueueRecoveryDiskChecker(
            static fn (string $path): int => 5 * 1024 * 1024 * 1024,
        ));

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-12',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('insufficient_disk_space', Artisan::output());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // 13. Aborto por automatización habilitada (y worker activo / falta ventana)
    // =========================================================================
    public function test_13_aborts_when_automations_enabled_or_active_worker_or_missing_maintenance_window(): void
    {
        $this->seedAccountAndOrder(1, 12001, '200012001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(12001);

        $manifestPath = $this->tmp('m13.json');
        $backupPath = $this->tmp('b13.ndjson');
        $auditPath = $this->tmp('a13.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-13');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $baseParams = [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-13',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ];

        config()->set('inventory.meli_order_reservations.automatic', true);
        $this->assertSame(1, Artisan::call('ops:queues-prune-redundant', $baseParams));
        $this->assertStringContainsString('automation_enabled', Artisan::output());
        config()->set('inventory.meli_order_reservations.automatic', false);

        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', true);
        $this->assertSame(1, Artisan::call('ops:queues-prune-redundant', $baseParams));
        $this->assertStringContainsString('automation_enabled', Artisan::output());
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        $noWindow = $baseParams;
        unset($noWindow['--maintenance-window']);
        $this->assertSame(1, Artisan::call('ops:queues-prune-redundant', $noWindow));
        $this->assertStringContainsString('maintenance_window_missing', Artisan::output());

        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => [
                'processes' => [
                    ['pid' => 424242, 'command' => 'php artisan queue:work database --queue=meli,default'],
                ],
                'unreadable_count' => 0,
                'inspection_available' => true,
            ],
        ));
        $this->assertSame(1, Artisan::call('ops:queues-prune-redundant', $baseParams));
        $this->assertStringContainsString('active_worker_detected', Artisan::output());

        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // 14. Aborto por ausencia de respaldo verificable
    // =========================================================================
    public function test_14_aborts_when_backup_is_missing_or_corrupted(): void
    {
        $this->seedAccountAndOrder(1, 13001, '200013001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(13001);

        $manifestPath = $this->tmp('m14.json');
        $backupPath = $this->tmp('b14.ndjson');
        $auditPath = $this->tmp('a14.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-14');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $codeMissing = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $this->tmp('does_not_exist.ndjson'),
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-14',
            '--maintenance-lock-file' => $lockPath,
        ]);
        $this->assertSame(1, $codeMissing);
        $this->assertStringContainsString('backup_missing', Artisan::output());

        $corrupt = str_replace('"attempts":0', '"attempts":99', (string) file_get_contents($backupPath));
        file_put_contents($backupPath, $corrupt);

        $codeCorrupt = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-14',
            '--maintenance-lock-file' => $lockPath,
        ]);
        $this->assertSame(1, $codeCorrupt);
        $this->assertStringContainsString('backup_integrity_failed', Artisan::output());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // 15. Aborto por manifiesto alterado
    // =========================================================================
    public function test_15_aborts_when_manifest_is_tampered(): void
    {
        $this->seedAccountAndOrder(1, 14001, '200014001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(14001);

        $manifestPath = $this->tmp('m15.json');
        $backupPath = $this->tmp('b15.ndjson');
        $auditPath = $this->tmp('a15.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-15');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $manifest['incident_id'] = 'TAMPERED-INCIDENT';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-15',
            '--maintenance-lock-file' => $lockPath,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('manifest_tampered', Artisan::output());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // =========================================================================
    // 16. Modificación concurrente de un candidato
    // =========================================================================
    public function test_16_aborts_and_rolls_back_batch_on_concurrent_candidate_modification(): void
    {
        $this->seedAccountAndOrder(1, 15001, '200015001', 'paid', 'ready_to_ship');
        $id1 = $this->insertReservationJob(15001);
        $id2 = $this->insertReservationJob(15001);

        $manifestPath = $this->tmp('m16.json');
        $backupPath = $this->tmp('b16.ndjson');
        $auditPath = $this->tmp('a16.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-16');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        DB::table('jobs')->where('id', $id2)->update([
            'payload' => $this->buildJobPayload(new ReconcileInventoryMeliOrderReservationsJob(99999)),
        ]);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-16',
            '--maintenance-lock-file' => $lockPath,
            '--chunk' => 2,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('concurrent_modification', Artisan::output());
        $this->assertDatabaseHas('jobs', ['id' => $id1]);
        $this->assertDatabaseHas('jobs', ['id' => $id2]);
    }

    // =========================================================================
    // 17. Inserción concurrente de trabajos nuevos
    // =========================================================================
    public function test_17_preserves_newly_inserted_jobs_above_authorized_upper_bound(): void
    {
        $this->seedAccountAndOrder(1, 16001, '200016001', 'paid', 'ready_to_ship');
        $oldId1 = $this->insertReservationJob(16001);
        $oldId2 = $this->insertReservationJob(16001);

        $manifestPath = $this->tmp('m17.json');
        $backupPath = $this->tmp('b17.ndjson');
        $auditPath = $this->tmp('a17.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-17');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $newId1 = $this->insertReservationJob(16001);
        $newId2 = $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'new-msg']);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-17',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertDatabaseMissing('jobs', ['id' => $oldId1]);
        $this->assertDatabaseMissing('jobs', ['id' => $oldId2]);
        $this->assertDatabaseHas('jobs', ['id' => $newId1]);
        $this->assertDatabaseHas('jobs', ['id' => $newId2]);
    }

    // =========================================================================
    // 18. Fallo entre batch_committed y actualización de checkpoint
    // =========================================================================
    public function test_18_recovers_traceability_when_crash_occurs_between_batch_committed_and_checkpoint_update(): void
    {
        $this->seedAccountAndOrder(1, 17001, '200017001', 'paid', 'ready_to_ship');
        for ($i = 0; $i < 4; $i++) {
            $this->insertReservationJob(17001);
        }

        $manifestPath = $this->tmp('m18.json');
        $backupPath = $this->tmp('b18.ndjson');
        $auditPath = $this->tmp('a18.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-18');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        /** @var QueuePruneService $service */
        $service = $this->app->make(QueuePruneService::class);
        $service->setAfterAuditBeforeCheckpointHook(static function (int $batchSeq): void {
            if ($batchSeq === 1) {
                throw new RuntimeException('Simulated crash after batch_committed and before checkpoint save');
            }
        });

        try {
            $service->apply([
                'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'manifest_path' => $manifestPath,
                'expected_manifest_sha256' => $sha,
                'backup_path' => $backupPath,
                'maintenance_window' => 'MW-TEST-18',
                'maintenance_lock_file' => $lockPath,
                'chunk' => 2,
                'max_delete' => 10,
                'sleep_ms' => 0,
                'audit_log_path' => $auditPath,
            ]);
            $this->fail('Expected simulated crash after batch_committed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertFileDoesNotExist($auditPath.'.checkpoint.json');

        $service->setAfterAuditBeforeCheckpointHook(null);
        $resumed = $service->apply([
            'jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $sha,
            'backup_path' => $backupPath,
            'maintenance_window' => 'MW-TEST-18',
            'maintenance_lock_file' => $lockPath,
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
        $this->assertSame(0, DB::table('jobs')->count());

        $auditContents = (string) file_get_contents($auditPath);
        $this->assertStringContainsString('"reconciled_from_committed_audit":true', $auditContents);
    }

    // =========================================================================
    // 19. Restauración de un lote en base de datos aislada
    // =========================================================================
    public function test_19_restores_backed_up_batch_into_isolated_database_with_bit_for_bit_fidelity(): void
    {
        $this->seedAccountAndOrder(1, 18001, '200018001', 'paid', 'ready_to_ship');
        $this->seedBeautyRule(1, 88);

        $id1 = $this->insertReservationJob(18001);
        $id2 = $this->insertBeautyJob(88, 'MLM3850424391');
        $originalRows = DB::table('jobs')->whereIn('id', [$id1, $id2])->orderBy('id')->get()->keyBy('id');

        $manifestPath = $this->tmp('m19.json');
        $backupPath = $this->tmp('b19.ndjson');
        $auditPath = $this->tmp('a19.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-19');

        $sha = $this->runDryRunAndBackup(
            [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            $manifestPath,
            $backupPath,
        );

        $verifyCode = Artisan::call('ops:queues-verify-backup', [
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
        ]);
        $this->assertSame(0, $verifyCode, Artisan::output());

        $applyCode = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS, QueuePayloadInspector::JOB_BEAUTY_PRICES],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-19',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);
        $this->assertSame(0, $applyCode, Artisan::output());
        $this->assertSame(0, DB::table('jobs')->count());

        $isolatedSqliteFile = $this->tmp('isolated_restore.sqlite');
        touch($isolatedSqliteFile);
        config()->set('database.connections.isolated_restore', [
            'driver' => 'sqlite',
            'database' => $isolatedSqliteFile,
            'prefix' => '',
        ]);
        $this->createJobsTableOnly('isolated_restore');

        /** @var QueueRecoveryBackupService $backupService */
        $backupService = $this->app->make(QueueRecoveryBackupService::class);
        $restored = $backupService->restoreToConnection($backupPath, 'isolated_restore', 'jobs');

        $this->assertSame(2, $restored['restored']);
        $this->assertSame(2, $restored['verified']);

        $restoredRows = DB::connection('isolated_restore')->table('jobs')->orderBy('id')->get()->keyBy('id');
        foreach ([$id1, $id2] as $id) {
            $this->assertEquals(
                $backupService->normalizeRow($originalRows->get($id)),
                $backupService->normalizeRow($restoredRows->get($id)),
            );
        }

        DB::purge('isolated_restore');
    }

    // =========================================================================
    // 20. Preservación íntegra de trabajos no autorizados y tablas de negocio
    // =========================================================================
    public function test_20_preserves_unauthorized_jobs_and_all_business_tables_untouched(): void
    {
        $this->seedFullBusinessTablesScenario();

        $keptId = $this->insertOtherModuleJob(ImportExcelFromTelegramJob::class, ['fileName' => 'catalogo.xlsx'], 'default');
        $targetId = $this->insertReservationJob(90001);

        $beforeBusinessHashes = $this->hashBusinessTables();

        $manifestPath = $this->tmp('m20.json');
        $backupPath = $this->tmp('b20.ndjson');
        $auditPath = $this->tmp('a20.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-20');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $code = Artisan::call('ops:queues-prune-redundant', [
            '--apply' => true,
            '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $sha,
            '--backup' => $backupPath,
            '--audit-log' => $auditPath,
            '--maintenance-window' => 'MW-TEST-20',
            '--maintenance-lock-file' => $lockPath,
            '--sleep-ms' => 0,
        ]);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertDatabaseMissing('jobs', ['id' => $targetId]);
        $this->assertDatabaseHas('jobs', ['id' => $keptId]);
        $this->assertSame($beforeBusinessHashes, $this->hashBusinessTables());
        Http::assertNothingSent();
    }

    // =========================================================================
    // 21. Diagnóstico de reconstrucción 100% read-only (11 categorías + esquema real)
    // =========================================================================
    public function test_21_reconstruction_diagnostics_classifies_all_11_categories_read_only_and_detects_anomalies(): void
    {
        $this->seedFullBusinessTablesScenario();

        $this->insertReservationJob(999999);
        DB::table('meli_orders')->insert([
            'id' => 90099,
            'meli_account_id' => 999,
            'order_id' => '200090099',
            'status' => 'paid',
            'shipping_status' => 'ready_to_ship',
            'raw' => json_encode(['date_created' => '2026-11-10T10:00:00-06:00']),
            'created_at' => '2026-11-10 10:00:00',
            'updated_at' => '2026-11-10 10:00:00',
        ]);
        $this->insertReservationJob(90099);

        $manifestPath = $this->tmp('m21.json');
        $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $this->tmp('b21.ndjson'));
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertContains(999999, $manifest['anomalies']['missing_orders']);
        $this->assertGreaterThanOrEqual(1, $manifest['totals']['account_inconsistencies']);

        $beforeHashes = $this->hashBusinessTables();
        $reportPath = $this->tmp('diag_report.json');

        $code = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--cutover' => '2026-10-01T00:00:00-06:00',
            '--output' => $reportPath,
        ]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertFileExists($reportPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('read_only_diagnosis', $report['mode']);
        $this->assertFalse($report['reconstructibility_policy']['automatic_reconstructibility_assumed']);

        $this->assertGreaterThanOrEqual(1, $report['counts']['cancelled_orders_with_active_reservations']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['shipped_orders_with_active_reservations']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['paid_orders_pending_reservation']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['shipped_during_outage_never_reserved']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['orders_with_manual_movements']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['multiline_orders']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['kits_and_components']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['active_beauty_promotions']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['beauty_promotions_pending_restore']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['expired_beauty_promotions']);
        $this->assertGreaterThanOrEqual(1, $report['counts']['remote_states_requiring_verification']);

        $this->assertArrayHasKey('reconstructibility_summary', $report);
        $this->assertGreaterThanOrEqual(1, $report['reconstructibility_summary']['counts']['ambiguous_entities']);
        $this->assertGreaterThanOrEqual(1, $report['reconstructibility_summary']['counts']['pending_human_review']);

        $this->assertSame($beforeHashes, $this->hashBusinessTables());
        Http::assertNothingSent();
    }

    // =========================================================================
    // 22. Prevención de ejecuciones simultáneas (lock exclusivo)
    // =========================================================================
    public function test_22_prevents_simultaneous_executions_via_exclusive_lock(): void
    {
        $this->seedAccountAndOrder(1, 22001, '200022001', 'paid', 'ready_to_ship');
        $this->insertReservationJob(22001);

        $manifestPath = $this->tmp('m22.json');
        $backupPath = $this->tmp('b22.ndjson');
        $auditPath = $this->tmp('a22.ndjson');
        $lockPath = $this->writeMaintenanceLock('MW-TEST-22');
        $sha = $this->runDryRunAndBackup([QueuePayloadInspector::JOB_RESERVATIONS], $manifestPath, $backupPath);

        $holder = new QueueRecoveryLock;
        $scope = implode('|', ['sqlite', ':memory:', 'jobs']);
        $holder->acquire(DB::connection('sqlite'), $scope);

        try {
            $code = Artisan::call('ops:queues-prune-redundant', [
                '--apply' => true,
                '--job' => [QueuePayloadInspector::JOB_RESERVATIONS],
                '--manifest' => $manifestPath,
                '--expected-manifest-sha256' => $sha,
                '--backup' => $backupPath,
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-TEST-22',
                '--maintenance-lock-file' => $lockPath,
                '--sleep-ms' => 0,
            ]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('already_running', Artisan::output());
            $this->assertSame(1, DB::table('jobs')->count());
        } finally {
            $holder->release();
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param list<string> $jobs */
    private function runDryRunAndBackup(
        array $jobs,
        string $manifestPath,
        string $backupPath,
        int $maxDelete = 10000,
        int $chunk = 500,
        ?int $endId = null,
        string $incidentId = 'INC-R2-TEST',
    ): string {
        $params = [
            '--dry-run' => true,
            '--job' => $jobs,
            '--manifest' => $manifestPath,
            '--backup-out' => $backupPath,
            '--max-delete' => $maxDelete,
            '--chunk' => $chunk,
            '--incident' => $incidentId,
        ];
        if ($endId !== null) {
            $params['--end-id'] = $endId;
        }

        $code = Artisan::call('ops:queues-prune-redundant', $params);
        $this->assertSame(0, $code, Artisan::output());

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);

        return (string) $manifest['manifest_sha256'];
    }

    private function writeMaintenanceLock(string $windowId, string $incidentId = 'INC-R2-TEST'): string
    {
        $path = $this->tmp('maintenance_'.$windowId.'.lock.json');
        file_put_contents($path, json_encode([
            'window_id' => $windowId,
            'incident_id' => $incidentId,
            'workers_halted_confirmed' => true,
            'approved_by' => 'ops-test',
            'expires_at' => now()->utc()->addHours(2)->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function insertReservationJob(int $meliOrderId, string $queue = 'meli', ?int $reservedAt = null): int
    {
        return $this->insertRawJob(
            $this->buildJobPayload(new ReconcileInventoryMeliOrderReservationsJob($meliOrderId)),
            $queue,
            $reservedAt,
        );
    }

    private function insertBeautyJob(int $discountId, ?string $itemId = null, string $queue = 'meli', ?int $reservedAt = null): int
    {
        return $this->insertRawJob(
            $this->buildJobPayload(new ProcessMeliBeautyScheduledPriceJob($discountId, $itemId)),
            $queue,
            $reservedAt,
        );
    }

    /** @param array<string, mixed> $args */
    private function insertOtherModuleJob(string $class, array $args, string $queue = 'meli', ?int $reservedAt = null): int
    {
        $instance = match ($class) {
            ProcessMeliMessageNotification::class => new ProcessMeliMessageNotification($args),
            SyncMeliClaimJob::class => new SyncMeliClaimJob(1, (string) ($args['claimId'] ?? '1')),
            PushMeliSharedStockGroupJob::class => new PushMeliSharedStockGroupJob((int) ($args['groupId'] ?? 1)),
            SyncInventoryMeliStockLinkJob::class => new SyncInventoryMeliStockLinkJob((int) ($args['linkId'] ?? 1)),
            ImportExcelFromTelegramJob::class => new ImportExcelFromTelegramJob('chat1', 'file1', (string) ($args['fileName'] ?? 'test.xlsx')),
            SyncMeliAccountPublicationsJob::class => new SyncMeliAccountPublicationsJob(1, (int) ($args['accountId'] ?? 1)),
            SyncMeliPriceManagerItemsJob::class => new SyncMeliPriceManagerItemsJob((int) ($args['meliAccountId'] ?? 1)),
            default => throw new RuntimeException("Unsupported test job class {$class}"),
        };

        return $this->insertRawJob($this->buildJobPayload($instance), $queue, $reservedAt);
    }

    private function buildJobPayload(object $job): string
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

    private function insertRawJob(string $payload, string $queue = 'meli', ?int $reservedAt = null): int
    {
        return (int) DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    private function seedAccountAndOrder(int $accountId, int $orderId, string $remoteOrderId, string $status, string $shippingStatus): void
    {
        DB::table('meli_accounts')->insertOrIgnore([
            'id' => $accountId,
            'nickname' => 'Cuenta '.$accountId,
        ]);

        DB::table('meli_orders')->insertOrIgnore([
            'id' => $orderId,
            'meli_account_id' => $accountId,
            'order_id' => $remoteOrderId,
            'status' => $status,
            'shipping_status' => $shippingStatus,
            'raw' => json_encode(['date_created' => '2026-11-10T12:00:00-06:00']),
            'created_at' => '2026-11-10 12:00:00',
            'updated_at' => '2026-11-10 12:00:00',
        ]);
    }

    private function seedBeautyRule(int $accountId, int $discountId): void
    {
        DB::table('meli_accounts')->insertOrIgnore([
            'id' => $accountId,
            'nickname' => 'Cuenta '.$accountId,
        ]);

        DB::table('meli_beauty_scheduled_discounts')->insertOrIgnore([
            'id' => $discountId,
            'meli_account_id' => $accountId,
            'brand_group_id' => 1,
            'discount_percentage' => 10.0,
            'starts_on' => now()->subDays(2)->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
            'starts_at' => '00:00',
            'ends_at' => '23:59',
            'all_day' => true,
            'timezone' => 'America/Mexico_City',
            'active' => true,
        ]);
    }

    private function seedFullBusinessTablesScenario(): void
    {
        DB::table('meli_accounts')->insertOrIgnore(['id' => 1, 'nickname' => 'Cuenta 1']);

        DB::table('inventory_products')->insert([
            ['id' => 101, 'sku' => 'SKU-SIMPLE', 'name' => 'Simple', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 102, 'sku' => 'SKU-KIT', 'name' => 'Kit', 'product_type' => 'kit', 'is_active' => 1],
            ['id' => 103, 'sku' => 'SKU-COMP', 'name' => 'Component', 'product_type' => 'simple', 'is_active' => 1],
        ]);
        DB::table('inventory_kit_components')->insert([
            ['kit_product_id' => 102, 'component_product_id' => 103, 'quantity' => 2],
        ]);
        DB::table('inventory_channel_links')->insert([
            ['id' => 1, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM101', 'external_variant_id' => null, 'inventory_product_id' => 101, 'is_active' => 1, 'order_reservation_enabled' => 1],
            ['id' => 2, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM102', 'external_variant_id' => null, 'inventory_product_id' => 102, 'is_active' => 1, 'order_reservation_enabled' => 1],
        ]);

        // Order 90001: cancelled with active reservation
        $this->seedAccountAndOrder(1, 90001, '200090001', 'cancelled', 'cancelled');
        DB::table('meli_order_items')->insert(['meli_order_id' => 90001, 'item_id' => 'MLM101', 'variation_id' => null, 'remote_line_key' => 'MLM101:', 'sku' => 'SKU-SIMPLE', 'quantity' => 1]);
        DB::table('inventory_channel_order_allocations')->insert([
            'channel' => 'mercado_libre', 'account_key' => '1', 'remote_order_id' => '200090001', 'remote_line_key' => 'MLM101:',
            'identity_hash' => hash('sha256', 'alloc-1'), 'inventory_product_id' => 101, 'reservation_kind' => 'SIMPLE', 'reservation_id' => 1, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);
        DB::table('inventory_reservations')->insert(['id' => 1, 'source_type' => 'meli_order', 'source_id' => 90001, 'inventory_product_id' => 101, 'quantity' => 1, 'status' => 'ACTIVE']);

        // Order 90002: shipped with active kit reservation + multiline
        $this->seedAccountAndOrder(1, 90002, '200090002', 'paid', 'shipped');
        DB::table('meli_order_items')->insert([
            ['meli_order_id' => 90002, 'item_id' => 'MLM102', 'variation_id' => null, 'remote_line_key' => 'MLM102:', 'sku' => 'SKU-KIT', 'quantity' => 1],
            ['meli_order_id' => 90002, 'item_id' => 'MLM101', 'variation_id' => null, 'remote_line_key' => 'MLM101:', 'sku' => 'SKU-SIMPLE', 'quantity' => 1],
        ]);
        DB::table('inventory_channel_order_allocations')->insert([
            'channel' => 'mercado_libre', 'account_key' => '1', 'remote_order_id' => '200090002', 'remote_line_key' => 'MLM102:',
            'identity_hash' => hash('sha256', 'alloc-2'), 'inventory_product_id' => 102, 'reservation_kind' => 'KIT', 'reservation_id' => 2, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);
        DB::table('inventory_kit_reservations')->insert(['id' => 2, 'source_type' => 'meli_order', 'source_id' => 90002, 'kit_product_id' => 102, 'quantity' => 1, 'status' => 'ACTIVE']);

        // Order 90003: paid pending reservation + manual movement on product 101 (using real `occurred_at`!)
        $this->seedAccountAndOrder(1, 90003, '200090003', 'paid', 'ready_to_ship');
        DB::table('meli_order_items')->insert(['meli_order_id' => 90003, 'item_id' => 'MLM101', 'variation_id' => null, 'remote_line_key' => 'MLM101:', 'sku' => 'SKU-SIMPLE', 'quantity' => 2]);
        DB::table('inventory_movements')->insert([
            'id' => 1, 'inventory_product_id' => 101, 'type' => 'adjustment', 'quantity' => -2, 'reference_type' => 'manual_audit',
            'occurred_at' => '2026-11-11 10:00:00', 'created_at' => '2026-11-11 10:00:00',
        ]);

        // Order 90004: shipped during outage that NEVER had reservation
        $this->seedAccountAndOrder(1, 90004, '200090004', 'paid', 'delivered');
        DB::table('meli_order_items')->insert(['meli_order_id' => 90004, 'item_id' => 'MLM101', 'variation_id' => null, 'remote_line_key' => 'MLM101:', 'sku' => 'SKU-SIMPLE', 'quantity' => 1]);

        // Beauty rules: active (#501), expired with pending restore (#502)
        DB::table('meli_price_manager_items')->insert([
            ['id' => 301, 'meli_account_id' => 1, 'meli_item_id' => 'MLM3850424391', 'sku' => 'BSLC001D'],
            ['id' => 302, 'meli_account_id' => 1, 'meli_item_id' => 'MLM3850424392', 'sku' => 'BSLC002D'],
        ]);
        DB::table('meli_beauty_scheduled_discounts')->insert([
            [
                'id' => 501, 'meli_account_id' => 1, 'brand_group_id' => 1, 'discount_percentage' => 10,
                'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString(),
                'starts_at' => '00:00', 'ends_at' => '23:59', 'all_day' => 1, 'timezone' => 'America/Mexico_City', 'active' => 1,
            ],
            [
                'id' => 502, 'meli_account_id' => 1, 'brand_group_id' => 1, 'discount_percentage' => 15,
                'starts_on' => now()->subDays(10)->toDateString(), 'ends_on' => now()->subDays(2)->toDateString(),
                'starts_at' => '00:00', 'ends_at' => '23:59', 'all_day' => 1, 'timezone' => 'America/Mexico_City', 'active' => 1,
            ],
        ]);
        DB::table('meli_beauty_scheduled_discount_items')->insert([
            ['meli_beauty_scheduled_discount_id' => 501, 'price_manager_item_id' => 301],
            ['meli_beauty_scheduled_discount_id' => 502, 'price_manager_item_id' => 302],
        ]);
        DB::table('meli_scheduled_price_states')->insert([
            [
                'id' => 1, 'price_manager_item_id' => 301, 'meli_beauty_scheduled_discount_id' => 501,
                'base_price' => 500, 'promotional_price' => 450, 'status' => 'failed',
                'failure_message' => 'Mercado Libre HTTP 400: OFFER_RANGE_INVALID',
            ],
            [
                'id' => 2, 'price_manager_item_id' => 302, 'meli_beauty_scheduled_discount_id' => 502,
                'base_price' => 600, 'promotional_price' => 510, 'status' => 'restore_pending',
                'failure_message' => null,
            ],
        ]);
        DB::table('meli_price_changes')->insert([
            [
                'id' => 1, 'price_manager_item_id' => 301, 'source' => 'scheduled_beauty',
                'scheduled_action' => 'apply', 'status' => 'failed',
                'error_message' => 'OFFER_RANGE_INVALID', 'changed_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    /** @return array<string, string> */
    private function hashBusinessTables(): array
    {
        $tables = [
            'meli_orders',
            'inventory_channel_order_allocations',
            'inventory_reservations',
            'inventory_kit_reservations',
            'inventory_movements',
            'meli_scheduled_price_states',
            'meli_beauty_scheduled_discounts',
        ];
        $hashes = [];
        foreach ($tables as $t) {
            $rows = DB::table($t)->get()->all();
            $hashes[$t] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }

    private function createTestSchema(string $connection): void
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
