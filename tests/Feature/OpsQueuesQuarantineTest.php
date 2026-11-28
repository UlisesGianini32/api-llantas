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
use App\Services\System\QueueRecovery\QueueQuarantinePolicy;
use App\Services\System\QueueRecovery\QueueQuarantineService;
use App\Services\System\QueueRecovery\QueueRecoveryLock;
use App\Services\System\QueueRecovery\QueueWorkerDetector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OpsQueuesQuarantineTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r4_2_quarantine_'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'sqlite');
        config()->set('queue.connections.database.table', 'jobs');
        config()->set('inventory.meli_order_reservations.automatic', false);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        DB::purge('sqlite');
        $this->createTestSchema();

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
    // 1. Dry-run sin modificaciones + modos mutuamente excluyentes
    // =========================================================================
    public function test_01_dry_run_is_default_mutually_exclusive_and_never_modifies_database(): void
    {
        $this->insertReservationJob(1001);
        $this->insertReservationJob(1002);
        $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'msg-1']);

        $beforeHash = $this->snapshotAllTablesHash();

        foreach ([
            ['--dry-run' => true, '--apply' => true],
            ['--dry-run' => true, '--restore' => true],
            ['--apply' => true, '--restore' => true],
            ['--dry-run' => true, '--apply' => true, '--restore' => true],
        ] as $invalidCombo) {
            $code = Artisan::call('ops:queues-quarantine', array_merge($invalidCombo, [
                '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
                '--json' => true,
            ]));
            $this->assertSame(2, $code);
            $out = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame('invalid_mode_combination', $out['reason_code']);
        }

        $manifestPath = $this->tmp('dry_manifest.ndjson');
        $markerPath = $this->tmp('dry_complete.json');
        $auditPath = $this->tmp('dry_audit.ndjson');

        $queries = [];
        DB::listen(static function (QueryExecuted $q) use (&$queries): void {
            $queries[] = strtoupper(ltrim($q->sql));
        });

        $code = Artisan::call('ops:queues-quarantine', [
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--incident' => 'INC-R42-01',
            '--manifest' => $manifestPath,
            '--completion-marker' => $markerPath,
            '--audit-log' => $auditPath,
            '--json' => true,
        ]);

        $rawOut = Artisan::output();
        $this->assertSame(0, $code, $rawOut);
        $payload = json_decode($rawOut, true, 64, JSON_THROW_ON_ERROR);

        $this->assertSame('dry_run', $payload['mode']);
        $this->assertSame(2, $payload['totals']['candidates']);
        $this->assertSame(1, $payload['totals']['excluded_other_modules']);
        $this->assertFileExists($manifestPath);
        $this->assertFileExists($markerPath);
        $this->assertFileExists($auditPath);
        $this->assertSame($beforeHash, $this->snapshotAllTablesHash());

        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('UPDATE ', $sql);
            $this->assertStringNotContainsString('INSERT ', $sql);
            $this->assertStringNotContainsString('DELETE ', $sql);
        }

        Http::assertNothingSent();
    }

    // =========================================================================
    // 2. Solo clases permitidas y mapeo obligatorio de colas
    // =========================================================================
    public function test_02_enforces_strict_allowlist_and_mandatory_queue_mapping(): void
    {
        $this->insertReservationJob(1001);

        foreach ([
            '*',
            'App\\Jobs\\*',
            '%Reconcile%',
            ProcessMeliMessageNotification::class,
            SyncMeliClaimJob::class,
            PushMeliSharedStockGroupJob::class,
            SyncInventoryMeliStockLinkJob::class,
            ImportExcelFromTelegramJob::class,
            SyncMeliAccountPublicationsJob::class,
            SyncMeliPriceManagerItemsJob::class,
        ] as $forbidden) {
            $code = Artisan::call('ops:queues-quarantine', [
                '--dry-run' => true,
                '--job' => $forbidden,
                '--json' => true,
            ]);
            $out = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $code, "Class {$forbidden} must be rejected.");
            $this->assertSame('invalid_quarantine_job_class', $out['reason_code']);
        }

        // Wrong source queue rejected
        $codeWrongSrc = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--source-queue' => 'default',
            '--json' => true,
        ]);
        $outSrc = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeWrongSrc);
        $this->assertSame('invalid_quarantine_queue', $outSrc['reason_code']);

        // Wrong target queue (cross-mapping or arbitrary queue) rejected
        $codeWrongDst = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--target-queue' => QueueQuarantinePolicy::TARGET_QUEUE_BEAUTY,
            '--json' => true,
        ]);
        $outDst = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeWrongDst);
        $this->assertSame('invalid_quarantine_queue', $outDst['reason_code']);
    }

    // =========================================================================
    // 3, 4, 5, 11, 12, 29, 30, 31. Preservación exacta byte-a-byte,
    // apply/restore solo modifican jobs.queue, 0 INSERT/DELETE, 0 HTTP, 0 cambios a otras tablas
    // =========================================================================
    public function test_03_04_05_11_12_29_30_31_apply_and_restore_modify_only_queue_and_preserve_all_columns_and_other_tables(): void
    {
        $this->seedAuxiliaryBusinessTables();
        $auxBeforeHash = $this->snapshotAuxiliaryTablesHash();

        $resId1 = $this->insertReservationJob(101, attempts: 0, availableAt: 1700000100, createdAt: 1700000001);
        $resId2 = $this->insertReservationJob(102, attempts: 2, availableAt: 1700000200, createdAt: 1700000002);
        $beautyId = $this->insertBeautyJob(55, 'MLM3850424391', attempts: 1, availableAt: 1700000300, createdAt: 1700000003);
        $otherIds = [
            $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'm1']),
            $this->insertOtherModuleJob(SyncMeliClaimJob::class, ['claimId' => 'c1']),
            $this->insertOtherModuleJob(PushMeliSharedStockGroupJob::class, ['groupId' => 10]),
            $this->insertOtherModuleJob(SyncInventoryMeliStockLinkJob::class, ['linkId' => 20]),
            $this->insertOtherModuleJob(ImportExcelFromTelegramJob::class, ['file' => 'x.xlsx'], 'default'),
            $this->insertOtherModuleJob(SyncMeliAccountPublicationsJob::class, ['accountId' => 1]),
            $this->insertOtherModuleJob(SyncMeliPriceManagerItemsJob::class, ['accountId' => 1]),
        ];

        $initialRows = DB::table('jobs')->orderBy('id')->get()->keyBy('id');
        $initialIds = $initialRows->keys()->all();

        $manifestRes = $this->tmp('m_res.ndjson');
        $manifestBeauty = $this->tmp('m_beauty.ndjson');
        $auditPath = $this->tmp('audit.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-R42-01', 'INC-R42-FULL');
        $lockFileBeauty = $this->writeMaintenanceLock('MW-R42-02', 'INC-R42-BEAUTY');

        $resSha = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $manifestRes, 'INC-R42-FULL');
        $beautySha = $this->runDryRun(QueuePayloadInspector::JOB_BEAUTY_PRICES, $manifestBeauty, 'INC-R42-BEAUTY');

        // Monitor every SQL statement executed during apply & restore!
        $executedSql = [];
        DB::listen(static function (QueryExecuted $q) use (&$executedSql): void {
            $executedSql[] = strtoupper(ltrim($q->sql));
        });

        // Apply reservations quarantine
        $applyRes = $this->runApply($manifestRes, $resSha, $auditPath, $lockFile, 'MW-R42-01', 'INC-R42-FULL');
        $this->assertSame(2, $applyRes['totals']['moved']);
        $this->assertSame(1, $applyRes['totals']['previously_attempted_moved']);

        // Apply beauty quarantine
        $applyBeauty = $this->runApply($manifestBeauty, $beautySha, $auditPath, $lockFileBeauty, 'MW-R42-02', 'INC-R42-BEAUTY');
        $this->assertSame(1, $applyBeauty['totals']['moved']);
        $this->assertSame(1, $applyBeauty['totals']['previously_attempted_moved']);

        // Verify exact row count, exact ID set, exact byte-for-byte preservation of all non-queue columns!
        $afterApply = DB::table('jobs')->orderBy('id')->get()->keyBy('id');
        $this->assertSame($initialIds, $afterApply->keys()->all());
        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, $afterApply[$resId1]->queue);
        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, $afterApply[$resId2]->queue);
        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_BEAUTY, $afterApply[$beautyId]->queue);

        foreach ($otherIds as $oid) {
            $this->assertSame($initialRows[$oid]->queue, $afterApply[$oid]->queue);
        }

        foreach ($initialIds as $id) {
            $this->assertSame((int) $initialRows[$id]->id, (int) $afterApply[$id]->id);
            $this->assertSame((string) $initialRows[$id]->payload, (string) $afterApply[$id]->payload);
            $this->assertSame(hash('sha256', (string) $initialRows[$id]->payload), hash('sha256', (string) $afterApply[$id]->payload));
            $this->assertSame((int) $initialRows[$id]->attempts, (int) $afterApply[$id]->attempts);
            $this->assertSame($initialRows[$id]->reserved_at, $afterApply[$id]->reserved_at);
            $this->assertSame((int) $initialRows[$id]->available_at, (int) $afterApply[$id]->available_at);
            $this->assertSame((int) $initialRows[$id]->created_at, (int) $afterApply[$id]->created_at);
        }

        // Restore reservations & beauty back to `meli`
        $restoreRes = $this->runRestore($manifestRes, $resSha, $auditPath, $lockFile, 'MW-R42-01', 'INC-R42-FULL');
        $this->assertSame(2, $restoreRes['totals']['moved']);

        $restoreBeauty = $this->runRestore($manifestBeauty, $beautySha, $auditPath, $lockFileBeauty, 'MW-R42-02', 'INC-R42-BEAUTY');
        $this->assertSame(1, $restoreBeauty['totals']['moved']);

        // Every single row & column in `jobs` is now 100% byte-identical to before apply!
        $afterRestore = DB::table('jobs')->orderBy('id')->get()->keyBy('id');
        $this->assertSame($initialIds, $afterRestore->keys()->all());
        foreach ($initialIds as $id) {
            $this->assertEquals((array) $initialRows[$id], (array) $afterRestore[$id]);
        }

        // Verify ZERO INSERT or DELETE queries on `jobs`, and every UPDATE touched ONLY `queue`!
        foreach ($executedSql as $sql) {
            $this->assertStringNotContainsString('INSERT ', $sql);
            $this->assertStringNotContainsString('DELETE ', $sql);
            if (str_starts_with($sql, 'UPDATE ')) {
                $this->assertStringContainsString('SET "QUEUE" = ?', $sql);
                $this->assertStringNotContainsString('PAYLOAD', $sql);
                $this->assertStringNotContainsString('ATTEMPTS', $sql);
            }
        }

        // Auxiliary inventory/orders/reservations/failed_jobs tables completely untouched
        $this->assertSame($auxBeforeHash, $this->snapshotAuxiliaryTablesHash());
        Http::assertNothingSent();
    }

    // =========================================================================
    // 6, 7, 8, 9, 10. Exclusiones estrictas: reservados, JSON inválido,
    // payloads inconsistentes, fuera de cota superior, otros módulos
    // =========================================================================
    public function test_06_07_08_09_10_excludes_reserved_invalid_json_inconsistent_out_of_bounds_and_other_modules(): void
    {
        $validId = $this->insertReservationJob(201);
        $reservedId = $this->insertReservationJob(202, reservedAt: time());
        $invalidJsonId = $this->insertRawJob('meli', '{corrupt-json');
        $inconsistentClassId = $this->insertRawJob('meli', json_encode([
            'uuid' => 'u-inconsistent',
            'displayName' => QueuePayloadInspector::JOB_RESERVATIONS,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => QueuePayloadInspector::JOB_RESERVATIONS,
                'command' => serialize(new ProcessMeliMessageNotification(['resource' => 'spoof'])),
            ],
        ], JSON_THROW_ON_ERROR));
        $missingEntityId = $this->insertRawJob('meli', json_encode([
            'uuid' => 'u-missing-entity',
            'displayName' => QueuePayloadInspector::JOB_RESERVATIONS,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => QueuePayloadInspector::JOB_RESERVATIONS,
                'command' => 'O:52:"App\\Jobs\\ReconcileInventoryMeliOrderReservationsJob":0:{}',
            ],
        ], JSON_THROW_ON_ERROR));
        $otherModId = $this->insertOtherModuleJob(ProcessMeliMessageNotification::class, ['resource' => 'ok']);
        $wrongQueueId = $this->insertReservationJob(203, queue: 'default');
        $aboveBoundId = $this->insertReservationJob(204);

        $manifestPath = $this->tmp('m_exclusions.ndjson');
        $code = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--end-id' => (string) $wrongQueueId,
            '--historical-upper-bound' => (string) $wrongQueueId,
            '--incident' => 'INC-EXCL',
            '--manifest' => $manifestPath,
            '--json' => true,
        ]);
        $out = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code);

        $this->assertSame(1, $out['totals']['candidates']);
        $this->assertSame(1, $out['totals']['excluded_reserved']);
        $this->assertSame(1, $out['totals']['excluded_invalid_json']);
        $this->assertSame(2, $out['totals']['excluded_inconsistent_payload']);
        $this->assertSame(1, $out['totals']['excluded_other_modules']);
        $this->assertSame(1, $out['totals']['excluded_wrong_queue']);

        // Reject --end-id exceeding --historical-upper-bound
        $codeBound = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--end-id' => (string) $aboveBoundId,
            '--historical-upper-bound' => (string) $wrongQueueId,
            '--json' => true,
        ]);
        $outBound = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeBound);
        $this->assertSame('upper_bound_violation', $outBound['reason_code']);

        // Apply moves ONLY $validId and leaves all excluded & above-bound rows untouched
        $auditPath = $this->tmp('a_excl.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-EXCL', 'INC-EXCL');
        $applyOut = $this->runApply($manifestPath, $out['manifest']['manifest_sha256'], $auditPath, $lockFile, 'MW-EXCL', 'INC-EXCL');
        $this->assertSame(1, $applyOut['totals']['moved']);

        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, DB::table('jobs')->where('id', $validId)->value('queue'));
        foreach ([$reservedId, $invalidJsonId, $inconsistentClassId, $missingEntityId, $otherModId, $aboveBoundId] as $untouched) {
            $this->assertSame('meli', DB::table('jobs')->where('id', $untouched)->value('queue'));
        }
        $this->assertSame('default', DB::table('jobs')->where('id', $wrongQueueId)->value('queue'));
    }

    // =========================================================================
    // 13, 14, 15. Rechazo de manifiesto alterado, SHA-256 incorrecto, HMAC inválido y manifiesto incompleto
    // =========================================================================
    public function test_13_14_15_rejects_tampered_wrong_sha256_invalid_hmac_and_incomplete_manifests(): void
    {
        $this->insertReservationJob(301);
        $manifestPath = $this->tmp('m_valid.ndjson');
        $auditPath = $this->tmp('a_sec.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-SEC', 'INC-SEC');

        // Generate with HMAC key via env (never CLI argv)
        putenv('TEST_R42_HMAC=super-secret-r42-key');
        $_ENV['TEST_R42_HMAC'] = 'super-secret-r42-key';
        try {
            $code = Artisan::call('ops:queues-quarantine', [
                '--dry-run' => true,
                '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
                '--incident' => 'INC-SEC',
                '--manifest' => $manifestPath,
                '--hmac-key-env' => 'TEST_R42_HMAC',
                '--json' => true,
            ]);
            $dry = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(0, $code);
            $this->assertTrue($dry['manifest']['hmac_present']);
            $validSha = $dry['manifest']['manifest_sha256'];

            // 14a. Wrong expected SHA-256 rejected
            $wrongSha = str_repeat('a', 64);
            $codeWrongSha = Artisan::call('ops:queues-quarantine', [
                '--apply' => true,
                '--manifest' => $manifestPath,
                '--expected-manifest-sha256' => $wrongSha,
                '--hmac-key-env' => 'TEST_R42_HMAC',
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-SEC',
                '--maintenance-lock-file' => $lockFile,
                '--confirm' => 'INC-SEC',
                '--json' => true,
            ]);
            $outWrongSha = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $codeWrongSha);
            $this->assertSame('manifest_approval_mismatch', $outWrongSha['reason_code']);

            // 14b. Wrong HMAC key rejected
            putenv('TEST_R42_HMAC_WRONG=wrong-key');
            $_ENV['TEST_R42_HMAC_WRONG'] = 'wrong-key';
            $codeBadHmac = Artisan::call('ops:queues-quarantine', [
                '--apply' => true,
                '--manifest' => $manifestPath,
                '--expected-manifest-sha256' => $validSha,
                '--hmac-key-env' => 'TEST_R42_HMAC_WRONG',
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-SEC',
                '--maintenance-lock-file' => $lockFile,
                '--confirm' => 'INC-SEC',
                '--json' => true,
            ]);
            $outBadHmac = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $codeBadHmac);
            $this->assertSame('manifest_hmac_invalid', $outBadHmac['reason_code']);

            // 13. Tampered candidate line rejected
            $tamperedPath = $this->tmp('m_tampered.ndjson');
            $content = (string) file_get_contents($manifestPath);
            file_put_contents($tamperedPath, str_replace('"entity_id":301', '"entity_id":999', $content));

            $codeTampered = Artisan::call('ops:queues-quarantine', [
                '--apply' => true,
                '--manifest' => $tamperedPath,
                '--expected-manifest-sha256' => $validSha,
                '--hmac-key-env' => 'TEST_R42_HMAC',
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-SEC',
                '--maintenance-lock-file' => $lockFile,
                '--confirm' => 'INC-SEC',
                '--json' => true,
            ]);
            $outTampered = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $codeTampered);
            $this->assertSame('manifest_tampered', $outTampered['reason_code']);

            // 15. Incomplete/truncated manifest (missing footer) rejected
            $incompletePath = $this->tmp('m_incomplete.ndjson');
            $lines = explode("\n", trim($content));
            array_pop($lines); // remove footer
            file_put_contents($incompletePath, implode("\n", $lines)."\n");

            $codeIncomplete = Artisan::call('ops:queues-quarantine', [
                '--apply' => true,
                '--manifest' => $incompletePath,
                '--expected-manifest-sha256' => $validSha,
                '--hmac-key-env' => 'TEST_R42_HMAC',
                '--audit-log' => $auditPath,
                '--maintenance-window' => 'MW-SEC',
                '--maintenance-lock-file' => $lockFile,
                '--confirm' => 'INC-SEC',
                '--json' => true,
            ]);
            $outIncomplete = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $codeIncomplete);
            $this->assertSame('manifest_incomplete', $outIncomplete['reason_code']);
        } finally {
            putenv('TEST_R42_HMAC');
            putenv('TEST_R42_HMAC_WRONG');
            unset($_ENV['TEST_R42_HMAC'], $_ENV['TEST_R42_HMAC_WRONG']);
        }
    }

    // =========================================================================
    // 16, 17, 18. Lock exclusivo concurrente, workers activos y automatismos habilitados
    // =========================================================================
    public function test_16_17_18_blocks_concurrent_lock_active_workers_and_enabled_automations(): void
    {
        $this->insertReservationJob(401);
        $manifestPath = $this->tmp('m_gates.ndjson');
        $auditPath = $this->tmp('a_gates.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-GATES', 'INC-GATES');
        $sha = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $manifestPath, 'INC-GATES');

        // 18a. Reservations automatic enabled -> blocked
        config()->set('inventory.meli_order_reservations.automatic', true);
        $codeAuto1 = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $sha, $auditPath, $lockFile, 'MW-GATES', 'INC-GATES'));
        $outAuto1 = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeAuto1);
        $this->assertSame('automation_still_enabled', $outAuto1['reason_code']);
        config()->set('inventory.meli_order_reservations.automatic', false);

        // 18b. Beauty scheduler enabled -> blocked
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', true);
        $codeAuto2 = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $sha, $auditPath, $lockFile, 'MW-GATES', 'INC-GATES'));
        $outAuto2 = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeAuto2);
        $this->assertSame('automation_still_enabled', $outAuto2['reason_code']);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        // 17a. Active worker on target/source queue -> blocked
        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => [
                'processes' => [
                    ['pid' => 4242, 'command' => 'php artisan queue:work --queue=meli'],
                ],
                'unreadable_count' => 0,
                'inspection_available' => true,
            ],
        ));
        $codeWorker = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $sha, $auditPath, $lockFile, 'MW-GATES', 'INC-GATES'));
        $outWorker = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeWorker);
        $this->assertSame('active_worker_detected', $outWorker['reason_code']);

        // Restore zero-worker detector
        $this->app->singleton(QueueWorkerDetector::class, fn () => new QueueWorkerDetector(
            static fn (): array => ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => true],
        ));

        // 17b. Missing maintenance lock file -> blocked
        $codeNoMaint = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $sha, $auditPath, $this->tmp('missing_lock.json'), 'MW-GATES', 'INC-GATES'));
        $outNoMaint = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeNoMaint);
        $this->assertSame('maintenance_gate_missing', $outNoMaint['reason_code']);

        // 16. Shared QueueRecoveryLock already held (same scope key as QueuePruneService!) -> blocked
        $holderLock = new QueueRecoveryLock;
        $scopeKey = implode('|', ['sqlite', ':memory:', 'jobs']);
        $holderLock->acquire(DB::connection('sqlite'), $scopeKey);
        try {
            $codeLock = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $sha, $auditPath, $lockFile, 'MW-GATES', 'INC-GATES'));
            $outLock = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $codeLock);
            $this->assertSame('already_running', $outLock['reason_code']);
        } finally {
            $holderLock->release();
        }
    }

    // =========================================================================
    // 19. Revalidación concurrente de filas entre dry-run y apply
    // =========================================================================
    public function test_19_revalidates_rows_during_apply_and_skips_concurrently_modified_reserved_or_deleted_rows(): void
    {
        $okId = $this->insertReservationJob(501);
        $reservedLaterId = $this->insertReservationJob(502);
        $modifiedLaterId = $this->insertReservationJob(503);
        $deletedLaterId = $this->insertReservationJob(504);

        $manifestPath = $this->tmp('m_reval.ndjson');
        $auditPath = $this->tmp('a_reval.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-REVAL', 'INC-REVAL');
        $sha = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $manifestPath, 'INC-REVAL');

        // Simulate concurrent external changes AFTER dry-run!
        DB::table('jobs')->where('id', $reservedLaterId)->update(['reserved_at' => time()]);
        DB::table('jobs')->where('id', $modifiedLaterId)->update(['attempts' => 9]);
        DB::table('jobs')->where('id', $deletedLaterId)->delete();

        $out = $this->runApply($manifestPath, $sha, $auditPath, $lockFile, 'MW-REVAL', 'INC-REVAL');

        $this->assertSame(1, $out['totals']['moved']);
        $this->assertSame(1, $out['totals']['skipped_reserved']);
        $this->assertSame(1, $out['totals']['skipped_modified']);
        $this->assertSame(1, $out['totals']['skipped_missing']);

        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, DB::table('jobs')->where('id', $okId)->value('queue'));
        $this->assertSame('meli', DB::table('jobs')->where('id', $reservedLaterId)->value('queue'));
        $this->assertSame('meli', DB::table('jobs')->where('id', $modifiedLaterId)->value('queue'));
    }

    // =========================================================================
    // 20, 21, 22, 23, 24. Interrupción antes de commit, después de commit antes de auditoría,
    // estado ambiguo fail-closed, resume sin omisiones, idempotencia de apply y restore
    // =========================================================================
    public function test_20_interruption_before_commit_reconciles_not_moved_and_resumes_cleanly(): void
    {
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->insertReservationJob(600 + $i);
        }

        $manifestPath = $this->tmp('m_crash_before.ndjson');
        $auditPath = $this->tmp('a_crash_before.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-CB', 'INC-CB');
        $sha = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $manifestPath, 'INC-CB');

        /** @var QueueQuarantineService $service */
        $service = $this->app->make(QueueQuarantineService::class);
        $service->setAfterIntentBeforeCommitHook(static function (int $batchSeq): void {
            if ($batchSeq === 2) {
                throw new RuntimeException('Simulated crash after batch_intent before SQL COMMIT');
            }
        });

        try {
            $service->apply($this->serviceApplyOptions($manifestPath, $sha, $auditPath, $lockFile, 'MW-CB', 'INC-CB', ['chunk' => 2]));
            $this->fail('Expected simulated crash exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('before SQL COMMIT', $e->getMessage());
        } finally {
            $service->setAfterIntentBeforeCommitHook(null);
        }

        // Batch 1 committed (2 rows moved), Batch 2 rolled back (4 rows still in `meli`)
        $this->assertSame(2, DB::table('jobs')->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());
        $this->assertSame(4, DB::table('jobs')->where('queue', 'meli')->count());

        // Without --resume, must fail-closed requiring --resume!
        $codeNoResume = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $sha, $auditPath, $lockFile, 'MW-CB', 'INC-CB', ['--chunk' => '2']));
        $outNoResume = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeNoResume);
        $this->assertSame('unresolved_batch_intent_requires_resume', $outNoResume['reason_code']);

        // With --resume: reconciles batch 2 as not_moved -> batch_aborted and moves all 4 remaining rows!
        $resumed = $this->runApply($manifestPath, $sha, $auditPath, $lockFile, 'MW-CB', 'INC-CB', ['--chunk' => '2', '--resume' => true]);
        $this->assertCount(1, $resumed['reconciled_intents']);
        $this->assertSame('aborted_before_commit_will_retry', $resumed['reconciled_intents'][0]['resolution']);
        $this->assertSame(4, $resumed['totals']['moved']);
        $this->assertSame(6, DB::table('jobs')->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());
    }

    public function test_21_interruption_after_commit_before_audit_reconciles_moved_correctly_and_stops_on_ambiguity(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->insertReservationJob(700 + $i);
        }

        $manifestPath = $this->tmp('m_crash_after.ndjson');
        $auditPath = $this->tmp('a_crash_after.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-CA', 'INC-CA');
        $sha = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $manifestPath, 'INC-CA');

        /** @var QueueQuarantineService $service */
        $service = $this->app->make(QueueQuarantineService::class);
        $service->setAfterCommitBeforeAuditHook(static function (int $batchSeq): void {
            if ($batchSeq === 2) {
                throw new RuntimeException('Simulated crash after SQL COMMIT before batch_committed');
            }
        });

        try {
            $service->apply($this->serviceApplyOptions($manifestPath, $sha, $auditPath, $lockFile, 'MW-CA', 'INC-CA', ['chunk' => 2]));
            $this->fail('Expected simulated crash exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('after SQL COMMIT', $e->getMessage());
        } finally {
            $service->setAfterCommitBeforeAuditHook(null);
        }

        // Batches 1 & 2 both committed in DB (4 rows in quarantine), batch 2 missing audit confirmation!
        $this->assertSame(4, DB::table('jobs')->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());

        // Resume reconciles batch 2 as committed_reconciled_from_live_state and moves remaining 2 rows!
        $resumed = $this->runApply($manifestPath, $sha, $auditPath, $lockFile, 'MW-CA', 'INC-CA', ['--chunk' => '2', '--resume' => true]);
        $this->assertCount(1, $resumed['reconciled_intents']);
        $this->assertSame('committed_reconciled_from_live_state', $resumed['reconciled_intents'][0]['resolution']);
        $this->assertSame(2, $resumed['totals']['moved']);
        $this->assertSame(6, DB::table('jobs')->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());

        // Ambiguous unresolved intent (row externally modified while unresolved) must stop fail-closed!
        $ambId1 = $this->insertReservationJob(791);
        $ambId2 = $this->insertReservationJob(792);
        $mAmb = $this->tmp('m_amb.ndjson');
        $aAmb = $this->tmp('a_amb.ndjson');
        $lockAmb = $this->writeMaintenanceLock('MW-AMB', 'INC-AMB');
        $shaAmb = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $mAmb, 'INC-AMB');

        $service->setAfterIntentBeforeCommitHook(static function (): void {
            throw new RuntimeException('Crash before commit for ambiguity check');
        });
        try {
            $service->apply($this->serviceApplyOptions($mAmb, $shaAmb, $aAmb, $lockAmb, 'MW-AMB', 'INC-AMB', ['chunk' => 2]));
        } catch (RuntimeException) {
        } finally {
            $service->setAfterIntentBeforeCommitHook(null);
        }

        // Externally modify one of the two unresolved rows (simulating rogue external process!)
        DB::table('jobs')->where('id', $ambId2)->update(['attempts' => 7]);

        $codeAmb = Artisan::call('ops:queues-quarantine', $this->applyArgs($mAmb, $shaAmb, $aAmb, $lockAmb, 'MW-AMB', 'INC-AMB', ['--resume' => true]));
        $outAmb = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codeAmb);
        $this->assertSame('unresolved_ambiguous_batch_intent', $outAmb['reason_code']);
        $this->assertSame('meli', DB::table('jobs')->where('id', $ambId1)->value('queue'));
    }

    public function test_22_23_24_resume_without_omissions_and_idempotent_apply_and_restore(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $ids[] = $this->insertReservationJob(800 + $i);
        }
        // Also insert an unquarantined job already sitting in target queue manually (MUST NOT be touched by restore!)
        $manualQuarantineId = $this->insertReservationJob(899, queue: QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS);

        $manifestPath = $this->tmp('m_idem.ndjson');
        $auditPath = $this->tmp('a_idem.ndjson');
        $lockFile = $this->writeMaintenanceLock('MW-IDEM', 'INC-IDEM');
        $sha = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $manifestPath, 'INC-IDEM');

        // Run 1: partial apply capped at --max-move=3 with --chunk=5 (tests mid-chunk slicing!)
        $run1 = $this->runApply($manifestPath, $sha, $auditPath, $lockFile, 'MW-IDEM', 'INC-IDEM', ['--chunk' => '5', '--max-move' => '3']);
        $this->assertSame(3, $run1['totals']['moved']);
        $this->assertTrue($run1['stopped_early']);
        $this->assertSame('max_move_reached', $run1['stop_reason']);

        // Run 2: resume completes remaining 4 rows without omission
        $run2 = $this->runApply($manifestPath, $sha, $auditPath, $lockFile, 'MW-IDEM', 'INC-IDEM', ['--chunk' => '5', '--resume' => true]);
        $this->assertSame(4, $run2['totals']['moved']);
        $this->assertFalse($run2['stopped_early']);
        $this->assertSame(7, DB::table('jobs')->whereIn('id', $ids)->where('queue', QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS)->count());

        // 23. Idempotent apply re-run moves 0 additional rows cleanly
        $run3 = $this->runApply($manifestPath, $sha, $auditPath, $lockFile, 'MW-IDEM', 'INC-IDEM', ['--resume' => true]);
        $this->assertSame(0, $run3['totals']['moved']);
        $this->assertSame('completed', $run3['stop_reason']);

        // Selective restore: first restore last 3 IDs, then restore first 4 IDs
        $restPart1 = $this->runRestore($manifestPath, $sha, $auditPath, $lockFile, 'MW-IDEM', 'INC-IDEM', [
            '--start-id' => (string) $ids[3],
            '--end-id' => (string) $ids[6],
        ]);
        $this->assertSame(3, $restPart1['totals']['moved']);

        $restPart2 = $this->runRestore($manifestPath, $sha, $auditPath, $lockFile, 'MW-IDEM', 'INC-IDEM');
        $this->assertSame(4, $restPart2['totals']['moved']);

        // All 7 manifest IDs restored; manual un-audited row stayed untouched in quarantine!
        $this->assertSame(7, DB::table('jobs')->whereIn('id', $ids)->where('queue', 'meli')->count());
        $this->assertSame(QueueQuarantinePolicy::TARGET_QUEUE_RESERVATIONS, DB::table('jobs')->where('id', $manualQuarantineId)->value('queue'));

        // 24. Idempotent restore re-run moves 0 rows cleanly
        $restIdem = $this->runRestore($manifestPath, $sha, $auditPath, $lockFile, 'MW-IDEM', 'INC-IDEM', ['--resume' => true]);
        $this->assertSame(0, $restIdem['totals']['moved']);
        $this->assertSame('completed', $restIdem['stop_reason']);
    }

    // =========================================================================
    // 25, 26, 27, 28. Previously attempted jobs, --max-scan/--max-move/--max-runtime,
    // ventanas exactas y cursor keyset con huecos grandes en IDs
    // =========================================================================
    public function test_25_26_27_28_previously_attempted_limits_exact_windows_and_large_id_gaps(): void
    {
        // Insert with huge non-consecutive ID gaps (simulating production 2.45M..5.22M)
        $sparseIds = [10, 5000, 999999, 2452096, 5222259];
        foreach ($sparseIds as $idx => $id) {
            $attempts = $idx % 2 === 1 ? 3 : 0;
            $this->insertReservationJob(900 + $idx, attempts: $attempts, explicitId: $id);
        }

        // Window 1: --max-scan=2
        $m1 = $this->tmp('m_win1.ndjson');
        $code1 = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--start-id' => '0',
            '--end-id' => '5222259',
            '--chunk' => '2',
            '--max-scan' => '2',
            '--incident' => 'INC-WIN-1',
            '--manifest' => $m1,
            '--json' => true,
        ]);
        $out1 = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code1);
        $this->assertTrue($out1['is_partial']);
        $this->assertSame('max_scan_reached', $out1['stop_reason']);
        $this->assertSame(2, $out1['totals']['scanned']);
        $this->assertSame(2, $out1['totals']['candidates']);
        $this->assertSame(1, $out1['totals']['previously_attempted_candidates']);
        $this->assertSame(5000, $out1['next_start_id']);

        // Window 2: resume from next_start_id=5000 with --max-move=2
        $m2 = $this->tmp('m_win2.ndjson');
        $code2 = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--start-id' => (string) $out1['next_start_id'],
            '--end-id' => '5222259',
            '--chunk' => '2',
            '--max-move' => '2',
            '--incident' => 'INC-WIN-2',
            '--manifest' => $m2,
            '--json' => true,
        ]);
        $out2 = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code2);
        $this->assertTrue($out2['is_partial']);
        $this->assertSame('max_move_reached', $out2['stop_reason']);
        $this->assertSame(2, $out2['totals']['candidates']);
        $this->assertSame(2452096, $out2['next_start_id']);

        // Window 3: remaining final ID 5222259
        $m3 = $this->tmp('m_win3.ndjson');
        $code3 = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => QueuePayloadInspector::JOB_RESERVATIONS,
            '--start-id' => (string) $out2['next_start_id'],
            '--end-id' => '5222259',
            '--chunk' => '2',
            '--incident' => 'INC-WIN-3',
            '--manifest' => $m3,
            '--json' => true,
        ]);
        $out3 = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $code3);
        $this->assertFalse($out3['is_partial']);
        $this->assertSame('completed_upper_bound', $out3['stop_reason']);
        $this->assertSame(1, $out3['totals']['candidates']);
        $this->assertSame(5222259, $out3['last_candidate_id']);

        // Verify union of 3 contiguous windows == exact 5 sparse IDs without overlap or gap!
        $allFound = array_merge($this->readManifestIds($m1), $this->readManifestIds($m2), $this->readManifestIds($m3));
        $this->assertSame($sparseIds, $allFound);

        // Verify --max-runtime stops cleanly via checker/interrupt
        /** @var QueueQuarantineService $service */
        $service = $this->app->make(QueueQuarantineService::class);
        $mSig = $this->tmp('m_sig.ndjson');
        $aSig = $this->tmp('a_sig.ndjson');
        $lockSig = $this->writeMaintenanceLock('MW-SIG', 'INC-SIG');
        $shaSig = $this->runDryRun(QueuePayloadInspector::JOB_RESERVATIONS, $mSig, 'INC-SIG');

        $service->setShouldInterruptChecker(static fn (int $nextBatchSeq): bool => $nextBatchSeq === 2);
        try {
            $sigRes = $service->apply($this->serviceApplyOptions($mSig, $shaSig, $aSig, $lockSig, 'MW-SIG', 'INC-SIG', ['chunk' => 2]));
            $this->assertTrue($sigRes['stopped_early']);
            $this->assertSame('interrupted_by_signal', $sigRes['stop_reason']);
            $this->assertSame(2, $sigRes['totals']['moved']);
        } finally {
            $service->setShouldInterruptChecker(null);
        }

        // Resume completes remaining 3 sparse IDs preserving exact attempts=3!
        $sigDone = $this->runApply($mSig, $shaSig, $aSig, $lockSig, 'MW-SIG', 'INC-SIG', ['--chunk' => '2', '--resume' => true]);
        $this->assertSame(3, $sigDone['totals']['moved']);
        $this->assertSame(3, (int) DB::table('jobs')->where('id', 5000)->value('attempts'));
        $this->assertSame(3, (int) DB::table('jobs')->where('id', 2452096)->value('attempts'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================
    private function createTestSchema(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sku');
            $table->integer('quantity');
        });

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('remote_order_id');
            $table->string('status');
        });
    }

    private function seedAuxiliaryBusinessTables(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-1',
            'connection' => 'database',
            'queue' => 'meli',
            'payload' => '{"test":1}',
            'exception' => 'RuntimeException',
            'failed_at' => '2026-11-27 00:00:00',
        ]);
        DB::table('inventory_stocks')->insert([
            'sku' => 'SKU-001',
            'quantity' => 15,
        ]);
        DB::table('meli_orders')->insert([
            'remote_order_id' => '20000101',
            'status' => 'paid',
        ]);
    }

    private function insertReservationJob(
        int $orderId,
        string $queue = 'meli',
        int $attempts = 0,
        ?int $reservedAt = null,
        ?int $availableAt = null,
        ?int $createdAt = null,
        ?int $explicitId = null,
    ): int {
        return $this->insertJobObject(
            new ReconcileInventoryMeliOrderReservationsJob($orderId),
            $queue,
            $attempts,
            $reservedAt,
            $availableAt,
            $createdAt,
            $explicitId,
        );
    }

    private function insertBeautyJob(
        int $ruleId,
        string $itemId,
        string $queue = 'meli',
        int $attempts = 0,
        ?int $reservedAt = null,
        ?int $availableAt = null,
        ?int $createdAt = null,
    ): int {
        return $this->insertJobObject(
            new ProcessMeliBeautyScheduledPriceJob($ruleId, $itemId),
            $queue,
            $attempts,
            $reservedAt,
            $availableAt,
            $createdAt,
        );
    }

    /** @param array<string, mixed> $args */
    private function insertOtherModuleJob(string $class, array $args, string $queue = 'meli'): int
    {
        $command = match ($class) {
            ProcessMeliMessageNotification::class => new ProcessMeliMessageNotification($args),
            SyncMeliClaimJob::class => new SyncMeliClaimJob(1, (string) ($args['claimId'] ?? 'c1')),
            PushMeliSharedStockGroupJob::class => new PushMeliSharedStockGroupJob((int) ($args['groupId'] ?? 1)),
            SyncInventoryMeliStockLinkJob::class => new SyncInventoryMeliStockLinkJob((int) ($args['linkId'] ?? 1)),
            ImportExcelFromTelegramJob::class => new ImportExcelFromTelegramJob('file-id', 'chat-1', (string) ($args['file'] ?? 'f.xlsx')),
            SyncMeliAccountPublicationsJob::class => new SyncMeliAccountPublicationsJob(1, (int) ($args['accountId'] ?? 1)),
            SyncMeliPriceManagerItemsJob::class => new SyncMeliPriceManagerItemsJob((int) ($args['accountId'] ?? 1)),
            default => throw new RuntimeException("Unsupported test job class {$class}"),
        };

        return $this->insertJobObject($command, $queue);
    }

    private function insertJobObject(
        object $job,
        string $queue = 'meli',
        int $attempts = 0,
        ?int $reservedAt = null,
        ?int $availableAt = null,
        ?int $createdAt = null,
        ?int $explicitId = null,
    ): int {
        $payload = json_encode([
            'uuid' => 'uuid-'.bin2hex(random_bytes(8)),
            'displayName' => $job::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => $job::class,
                'command' => serialize($job),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->insertRawJob($queue, $payload, $attempts, $reservedAt, $availableAt, $createdAt, $explicitId);
    }

    private function insertRawJob(
        string $queue,
        string $payload,
        int $attempts = 0,
        ?int $reservedAt = null,
        ?int $availableAt = null,
        ?int $createdAt = null,
        ?int $explicitId = null,
    ): int {
        $now = time();
        $row = [
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => $attempts,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt ?? $now,
            'created_at' => $createdAt ?? $now,
        ];
        if ($explicitId !== null) {
            $row['id'] = $explicitId;
            DB::table('jobs')->insert($row);

            return $explicitId;
        }

        return (int) DB::table('jobs')->insertGetId($row);
    }

    private function runDryRun(string $jobClass, string $manifestPath, string $incidentId): string
    {
        $code = Artisan::call('ops:queues-quarantine', [
            '--dry-run' => true,
            '--job' => $jobClass,
            '--incident' => $incidentId,
            '--manifest' => $manifestPath,
            '--json' => true,
        ]);
        $raw = Artisan::output();
        $this->assertSame(0, $code, $raw);
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

        return (string) $decoded['manifest']['manifest_sha256'];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function applyArgs(
        string $manifestPath,
        string $expectedSha,
        string $auditPath,
        string $lockFile,
        string $window,
        string $incidentId,
        array $extra = [],
    ): array {
        return array_merge([
            '--apply' => true,
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $expectedSha,
            '--audit-log' => $auditPath,
            '--maintenance-window' => $window,
            '--maintenance-lock-file' => $lockFile,
            '--confirm' => $incidentId,
            '--sleep-ms' => '0',
            '--json' => true,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function runApply(
        string $manifestPath,
        string $expectedSha,
        string $auditPath,
        string $lockFile,
        string $window,
        string $incidentId,
        array $extra = [],
    ): array {
        $code = Artisan::call('ops:queues-quarantine', $this->applyArgs($manifestPath, $expectedSha, $auditPath, $lockFile, $window, $incidentId, $extra));
        $raw = Artisan::output();
        $this->assertSame(0, $code, $raw);

        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function runRestore(
        string $manifestPath,
        string $expectedSha,
        string $auditPath,
        string $lockFile,
        string $window,
        string $incidentId,
        array $extra = [],
    ): array {
        $code = Artisan::call('ops:queues-quarantine', array_merge([
            '--restore' => true,
            '--manifest' => $manifestPath,
            '--expected-manifest-sha256' => $expectedSha,
            '--audit-log' => $auditPath,
            '--maintenance-window' => $window,
            '--maintenance-lock-file' => $lockFile,
            '--confirm' => $incidentId,
            '--sleep-ms' => '0',
            '--json' => true,
        ], $extra));
        $raw = Artisan::output();
        $this->assertSame(0, $code, $raw);

        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function serviceApplyOptions(
        string $manifestPath,
        string $expectedSha,
        string $auditPath,
        string $lockFile,
        string $window,
        string $incidentId,
        array $extra = [],
    ): array {
        return array_merge([
            'manifest_path' => $manifestPath,
            'expected_manifest_sha256' => $expectedSha,
            'audit_log_path' => $auditPath,
            'maintenance_window' => $window,
            'maintenance_lock_file' => $lockFile,
            'confirm' => $incidentId,
            'sleep_ms' => 0,
        ], $extra);
    }

    private function writeMaintenanceLock(string $window, string $incidentId): string
    {
        $path = $this->tmp('maint_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $window).'.json');
        file_put_contents($path, json_encode([
            'window_id' => $window,
            'incident_id' => $incidentId,
            'workers_halted_confirmed' => true,
            'created_at' => now()->utc()->toIso8601String(),
            'expires_at' => now()->utc()->addHour()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return list<int> */
    private function readManifestIds(string $manifestPath): array
    {
        $ids = [];
        foreach (file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $rec = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            if (($rec['record_type'] ?? null) === 'candidate') {
                $ids[] = (int) $rec['id'];
            }
        }

        return $ids;
    }

    private function snapshotAllTablesHash(): string
    {
        return hash('sha256', json_encode([
            'jobs' => DB::table('jobs')->orderBy('id')->get()->all(),
            'aux' => $this->snapshotAuxiliaryTablesHash(),
        ], JSON_THROW_ON_ERROR));
    }

    private function snapshotAuxiliaryTablesHash(): string
    {
        return hash('sha256', json_encode([
            'failed_jobs' => DB::table('failed_jobs')->orderBy('id')->get()->all(),
            'inventory_stocks' => DB::table('inventory_stocks')->orderBy('id')->get()->all(),
            'meli_orders' => DB::table('meli_orders')->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR));
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
