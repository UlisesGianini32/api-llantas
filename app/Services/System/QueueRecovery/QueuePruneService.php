<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueuePruneService
{
    public const MAX_CHUNK_SIZE = 500;

    public const DEFAULT_CHUNK_SIZE = 500;

    public const DEFAULT_MAX_DELETE = 10000;

    public const DEFAULT_MIN_FREE_DISK_MB = 8192; // 8 GiB

    public const DEFAULT_MAX_DEADLOCK_RETRIES = 3;

    private bool $interrupted = false;

    /** @var (callable(int, list<int>): void)|null */
    private $afterCommitBeforeCheckpointHook = null;

    /** @var (callable(int): bool)|null */
    private $shouldInterruptChecker = null;

    public function __construct(
        private readonly QueuePayloadInspector $inspector,
        private readonly QueueRecoveryManifest $manifestManager,
        private readonly QueueRecoveryBackupService $backupService,
        private readonly QueueRecoveryDiskChecker $diskChecker,
        private readonly QueueWorkerDetector $workerDetector,
        private readonly QueueRecoveryLock $lock,
    ) {}

    /**
     * Test hook to simulate a failure strictly between DB commit and checkpoint persistence.
     *
     * @param  (callable(int, list<int>): void)|null  $hook
     */
    public function setAfterCommitBeforeCheckpointHook(?callable $hook): void
    {
        $this->afterCommitBeforeCheckpointHook = $hook;
    }

    /**
     * Test hook to simulate SIGINT/SIGTERM interruption cleanly between batches.
     *
     * @param  (callable(int): bool)|null  $checker
     */
    public function setShouldInterruptChecker(?callable $checker): void
    {
        $this->shouldInterruptChecker = $checker;
    }

    public function requestInterrupt(): void
    {
        $this->interrupted = true;
    }

    /**
     * Resolves and validates the target database queue connection, database name, and table.
     *
     * @return array{queue_connection: string, db_connection: string, database: string, table: string}
     */
    public function resolveQueueTarget(?string $queueConnectionName = null): array
    {
        $queueConn = $queueConnectionName ?: (string) config('queue.default', 'database');
        $connConfig = config("queue.connections.{$queueConn}");

        if (! is_array($connConfig) || ($connConfig['driver'] ?? null) !== 'database') {
            throw new QueueRecoveryException(
                "La conexión de cola '{$queueConn}' no utiliza driver 'database'.",
                'database_target_mismatch',
                ['queue_connection' => $queueConn, 'driver' => $connConfig['driver'] ?? null],
            );
        }

        $dbConnName = is_string($connConfig['connection'] ?? null) && trim($connConfig['connection']) !== ''
            ? trim($connConfig['connection'])
            : (string) config('database.default');
        $table = is_string($connConfig['table'] ?? null) && trim($connConfig['table']) !== ''
            ? trim($connConfig['table'])
            : 'jobs';

        $connection = DB::connection($dbConnName);
        $database = (string) $connection->getDatabaseName();

        $schema = Schema::connection($dbConnName);
        if (! $schema->hasTable($table)) {
            throw new QueueRecoveryException(
                "La tabla de colas '{$table}' no existe en la conexión '{$dbConnName}'.",
                'database_target_mismatch',
                ['db_connection' => $dbConnName, 'table' => $table],
            );
        }

        foreach (['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'] as $column) {
            if (! $schema->hasColumn($table, $column)) {
                throw new QueueRecoveryException(
                    "La tabla '{$table}' no contiene la columna requerida '{$column}'.",
                    'database_target_mismatch',
                    ['table' => $table, 'missing_column' => $column],
                );
            }
        }

        return [
            'queue_connection' => $queueConn,
            'db_connection' => $dbConnName,
            'database' => $database,
            'table' => $table,
        ];
    }

    /**
     * Read-only PK-cursor scan producing counts, anomalies, signed manifest, and optional backup.
     *
     * @param  array{
     *     jobs: list<string>,
     *     chunk?: int,
     *     max_delete?: int,
     *     start_id?: int,
     *     end_id?: ?int,
     *     incident_id?: string,
     *     manifest_path?: ?string,
     *     backup_out_path?: ?string,
     *     audit_log_path?: ?string,
     *     queue_connection?: ?string
     * }  $options
     * @return array<string, mixed>
     */
    public function dryRun(array $options): array
    {
        $allowedJobs = $this->inspector->validateWhitelist($options['jobs']);
        $chunk = $this->validateChunk((int) ($options['chunk'] ?? self::DEFAULT_CHUNK_SIZE));
        $maxDelete = $this->validateMaxDelete((int) ($options['max_delete'] ?? self::DEFAULT_MAX_DELETE));
        $startId = max(0, (int) ($options['start_id'] ?? 0));
        $incidentId = trim((string) ($options['incident_id'] ?? 'INC-QUEUE-R2-'.now()->utc()->format('Ymd-His')));
        if ($incidentId === '') {
            $incidentId = 'INC-QUEUE-R2-'.now()->utc()->format('Ymd-His');
        }

        $target = $this->resolveQueueTarget($options['queue_connection'] ?? null);
        $db = DB::connection($target['db_connection']);
        $table = $target['table'];

        $currentMaxId = (int) ($db->table($table)->max('id') ?? 0);
        $requestedEndId = isset($options['end_id']) && $options['end_id'] !== null && $options['end_id'] !== ''
            ? (int) $options['end_id']
            : $currentMaxId;

        if ($requestedEndId < 0) {
            throw new QueueRecoveryException('El parámetro --end-id no puede ser negativo.', 'invalid_bounds');
        }

        if ($requestedEndId > 0 && $startId > $requestedEndId) {
            throw new QueueRecoveryException(
                "Rango inválido: --start-id ({$startId}) es mayor que --end-id ({$requestedEndId}).",
                'invalid_bounds',
            );
        }

        $schema = Schema::connection($target['db_connection']);
        $hasMeliOrders = $schema->hasTable('meli_orders');
        $hasMeliAccounts = $schema->hasTable('meli_accounts');
        $hasBeautyDiscounts = $schema->hasTable('meli_beauty_scheduled_discounts');

        $totals = [
            'scanned' => 0,
            'candidates' => 0,
            'preserved_other_modules' => 0,
            'preserved_reserved' => 0,
            'preserved_invalid_json' => 0,
            'preserved_inconsistent' => 0,
            'missing_order_anomalies' => 0,
            'missing_beauty_rule_anomalies' => 0,
            'account_inconsistencies' => 0,
        ];

        $countsByClass = [];
        $countsByEntity = [];
        $anomalies = [
            'missing_orders' => [],
            'missing_beauty_rules' => [],
            'account_inconsistencies' => [],
            'invalid_payload_job_ids' => [],
            'inconsistent_payload_job_ids' => [],
            'reserved_job_ids' => [],
        ];

        $entries = [];
        $cursor = $startId;
        $highestScannedId = $startId;
        $limitReached = false;

        while ($cursor < $requestedEndId && ! $limitReached) {
            $rows = $db->table($table)
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->where('id', '>', $cursor)
                ->where('id', '<=', $requestedEndId)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $batchCandidates = [];
            foreach ($rows as $row) {
                $jobId = (int) $row->id;
                $cursor = $jobId;
                $highestScannedId = max($highestScannedId, $jobId);
                $totals['scanned']++;

                if ($row->reserved_at !== null) {
                    $totals['preserved_reserved']++;
                    if (count($anomalies['reserved_job_ids']) < 500) {
                        $anomalies['reserved_job_ids'][] = $jobId;
                    }

                    continue;
                }

                $inspection = $this->inspector->inspect((string) $row->payload, $allowedJobs);
                $classLabel = $inspection['job_class'] ?? 'UNKNOWN_OR_INVALID';
                $countsByClass[$classLabel] = ($countsByClass[$classLabel] ?? 0) + 1;

                if ($inspection['classification'] === QueuePayloadInspector::CLASSIFICATION_INVALID_JSON) {
                    $totals['preserved_invalid_json']++;
                    if (count($anomalies['invalid_payload_job_ids']) < 500) {
                        $anomalies['invalid_payload_job_ids'][] = $jobId;
                    }

                    continue;
                }

                if ($inspection['classification'] === QueuePayloadInspector::CLASSIFICATION_INCONSISTENT) {
                    $totals['preserved_inconsistent']++;
                    if (count($anomalies['inconsistent_payload_job_ids']) < 500) {
                        $anomalies['inconsistent_payload_job_ids'][] = [
                            'job_id' => $jobId,
                            'reason' => $inspection['reason'],
                        ];
                    }

                    continue;
                }

                if ($inspection['classification'] === QueuePayloadInspector::CLASSIFICATION_OTHER_MODULE) {
                    $totals['preserved_other_modules']++;

                    continue;
                }

                $batchCandidates[] = [
                    'id' => $jobId,
                    'job_class' => (string) $inspection['job_class'],
                    'queue' => (string) $row->queue,
                    'entity_type' => (string) $inspection['entity_type'],
                    'entity_id' => (int) $inspection['entity_id'],
                    'meli_item_id' => $inspection['meli_item_id'],
                    'created_at' => (int) $row->created_at,
                    'created_at_iso' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->created_at),
                    'reserved_at' => null,
                    'payload_hash' => (string) $inspection['payload_hash'],
                ];

                if ((count($entries) + count($batchCandidates)) >= $maxDelete) {
                    $limitReached = true;
                    break;
                }
            }

            if ($batchCandidates !== []) {
                $enriched = $this->enrichBatchCandidates(
                    $db,
                    $batchCandidates,
                    $hasMeliOrders,
                    $hasMeliAccounts,
                    $hasBeautyDiscounts,
                    $anomalies,
                    $totals,
                );

                foreach ($enriched as $entry) {
                    $entries[] = $entry;
                    $totals['candidates']++;
                    $entityKey = $entry['entity_type'].':'.$entry['entity_id'];
                    $countsByEntity[$entry['job_class']][$entityKey] = ($countsByEntity[$entry['job_class']][$entityKey] ?? 0) + 1;
                }
            }
        }

        ksort($countsByClass);
        foreach ($countsByEntity as $class => $map) {
            ksort($map);
            $countsByEntity[$class] = $map;
        }
        ksort($countsByEntity);

        $anomalies['missing_orders'] = array_values(array_unique($anomalies['missing_orders']));
        sort($anomalies['missing_orders']);
        $anomalies['missing_beauty_rules'] = array_values(array_unique($anomalies['missing_beauty_rules']));
        sort($anomalies['missing_beauty_rules']);

        $effectiveEndId = $entries !== []
            ? min($requestedEndId, max($highestScannedId, (int) end($entries)['id']))
            : $requestedEndId;

        $manifestBody = [
            'incident_id' => $incidentId,
            'generated_at' => now()->utc()->toIso8601String(),
            'mode' => 'dry_run',
            'queue_connection' => $target['queue_connection'],
            'connection' => $target['db_connection'],
            'database' => $target['database'],
            'table' => $table,
            'allowed_jobs' => $allowedJobs,
            'start_id' => $startId,
            'requested_end_id' => $requestedEndId,
            'end_id' => $effectiveEndId,
            'chunk_size' => $chunk,
            'max_delete' => $maxDelete,
            'limit_reached' => $limitReached,
            'totals' => $totals,
            'counts_by_class' => $countsByClass,
            'counts_by_entity' => $countsByEntity,
            'anomalies' => $anomalies,
            'entries' => $entries,
        ];

        $signedManifest = $this->manifestManager->sign($manifestBody);

        $manifestPath = isset($options['manifest_path']) && trim((string) $options['manifest_path']) !== ''
            ? trim((string) $options['manifest_path'])
            : null;

        if ($manifestPath !== null) {
            $this->manifestManager->writeAtomic($manifestPath, $signedManifest);
        }

        $backupResult = null;
        $backupOutPath = isset($options['backup_out_path']) && trim((string) $options['backup_out_path']) !== ''
            ? trim((string) $options['backup_out_path'])
            : null;

        if ($backupOutPath !== null) {
            $backupResult = $this->backupService->exportForManifest($db, $table, $signedManifest, $backupOutPath);
        }

        $auditLog = new QueueRecoveryAuditLog($options['audit_log_path'] ?? null);
        $auditLog->record('dry_run_completed', [
            'incident_id' => $incidentId,
            'manifest_sha256' => $signedManifest['manifest_sha256'],
            'manifest_path' => $manifestPath,
            'backup_path' => $backupOutPath,
            'start_id' => $startId,
            'end_id' => $effectiveEndId,
            'totals' => $totals,
        ]);

        return [
            'manifest' => $signedManifest,
            'manifest_path' => $manifestPath,
            'backup' => $backupResult,
        ];
    }

    /**
     * Executes controlled micro-transactional deletion strictly from a verified dry-run manifest and verified backup.
     *
     * @param  array{
     *     jobs: list<string>,
     *     manifest_path: string,
     *     backup_path: string,
     *     maintenance_window: string,
     *     chunk?: int,
     *     max_delete?: int,
     *     start_id?: ?int,
     *     end_id?: ?int,
     *     sleep_ms?: int,
     *     min_free_disk_mb?: int,
     *     audit_log_path: string,
     *     checkpoint_path?: ?string,
     *     resume?: bool,
     *     disk_check_paths?: list<string>,
     *     queue_connection?: ?string,
     *     max_deadlock_retries?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function apply(array $options): array
    {
        $this->interrupted = false;
        $this->registerSignalHandlers();

        $allowedJobs = $this->inspector->validateWhitelist($options['jobs']);
        $chunk = $this->validateChunk((int) ($options['chunk'] ?? self::DEFAULT_CHUNK_SIZE));
        $maxDelete = $this->validateMaxDelete((int) ($options['max_delete'] ?? self::DEFAULT_MAX_DELETE));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $minFreeDiskMb = (int) ($options['min_free_disk_mb'] ?? self::DEFAULT_MIN_FREE_DISK_MB);
        $resume = (bool) ($options['resume'] ?? false);
        $maxDeadlockRetries = max(1, (int) ($options['max_deadlock_retries'] ?? self::DEFAULT_MAX_DEADLOCK_RETRIES));

        $auditLogPath = trim((string) ($options['audit_log_path'] ?? ''));
        if ($auditLogPath === '') {
            throw new QueueRecoveryException(
                'El parámetro --audit-log es obligatorio para --apply.',
                'audit_log_required',
            );
        }
        $auditLog = new QueueRecoveryAuditLog($auditLogPath);

        $checkpointPath = isset($options['checkpoint_path']) && trim((string) $options['checkpoint_path']) !== ''
            ? trim((string) $options['checkpoint_path'])
            : $auditLogPath.'.checkpoint.json';
        $checkpoint = new QueueRecoveryCheckpoint($checkpointPath);

        $target = $this->resolveQueueTarget($options['queue_connection'] ?? null);
        $db = DB::connection($target['db_connection']);
        $table = $target['table'];

        $lockScope = implode('|', [$target['db_connection'], $target['database'], $table]);
        $this->lock->acquire($db, $lockScope);

        try {
            $auditLog->record('apply_started', [
                'maintenance_window' => trim((string) ($options['maintenance_window'] ?? '')),
                'resume' => $resume,
                'chunk' => $chunk,
                'max_delete' => $maxDelete,
            ]);

            // SAFETY CHECK 1: Both automations MUST be suspended.
            $this->assertAutomationsSuspended();

            // SAFETY CHECK 2: Authorized maintenance window + NO active target workers.
            $maintenanceWindow = trim((string) ($options['maintenance_window'] ?? ''));
            if ($maintenanceWindow === '') {
                throw new QueueRecoveryException(
                    'Se requiere una ventana operativa de mantenimiento autorizada (--maintenance-window).',
                    'maintenance_window_missing',
                );
            }

            // SAFETY CHECK 6: Load & cryptographically verify dry-run manifest.
            $manifestPath = trim((string) ($options['manifest_path'] ?? ''));
            if ($manifestPath === '') {
                throw new QueueRecoveryException(
                    'El parámetro --manifest generado previamente con --dry-run es obligatorio para --apply.',
                    'manifest_missing',
                );
            }
            $manifest = $this->manifestManager->loadAndVerify($manifestPath, $this->inspector);
            $manifestSha = (string) $manifest['manifest_sha256'];

            // Verify requested --job classes match manifest exactly.
            $manifestAllowedJobs = (array) $manifest['allowed_jobs'];
            sort($manifestAllowedJobs);
            if ($manifestAllowedJobs !== $allowedJobs) {
                throw new QueueRecoveryException(
                    'Las clases indicadas en --job no coinciden exactamente con allowed_jobs del manifiesto.',
                    'manifest_job_mismatch',
                    ['requested' => $allowedJobs, 'manifest' => $manifestAllowedJobs],
                );
            }

            // SAFETY CHECK 5: Verify connection, database, and table match manifest.
            if ((string) $manifest['connection'] !== $target['db_connection']
                || (string) $manifest['database'] !== $target['database']
                || (string) $manifest['table'] !== $table) {
                throw new QueueRecoveryException(
                    'La conexión, base de datos o tabla activa no coincide con el manifiesto.',
                    'database_target_mismatch',
                    [
                        'manifest' => [
                            'connection' => $manifest['connection'],
                            'database' => $manifest['database'],
                            'table' => $manifest['table'],
                        ],
                        'actual' => [
                            'connection' => $target['db_connection'],
                            'database' => $target['database'],
                            'table' => $table,
                        ],
                    ],
                );
            }

            // Determine queues present in manifest (plus default 'meli' if empty).
            $targetQueues = array_values(array_unique(array_filter(array_map(
                static fn (array $e): string => (string) ($e['queue'] ?? ''),
                (array) $manifest['entries'],
            ))));
            if ($targetQueues === []) {
                $targetQueues = ['meli'];
            }
            $this->workerDetector->assertNoActiveTargetWorkers($targetQueues);

            // SAFETY CHECK 4: Minimum free disk space (default 8 GiB) on all relevant paths.
            $backupPath = trim((string) ($options['backup_path'] ?? ''));
            if ($backupPath === '') {
                throw new QueueRecoveryException(
                    'Se requiere un archivo de respaldo verificable (--backup) antes de ejecutar --apply.',
                    'backup_missing',
                );
            }

            $diskPaths = array_values(array_filter([
                base_path(),
                storage_path(),
                sys_get_temp_dir(),
                dirname($manifestPath),
                dirname($backupPath),
                dirname($auditLogPath),
                ...(array) ($options['disk_check_paths'] ?? []),
            ]));
            $checkedDisk = $this->diskChecker->assertMinimumFreeSpace($diskPaths, $minFreeDiskMb);

            // SAFETY CHECK 7 & 8: Verify backup hashes against manifest AND run isolated restore test.
            $backupVerification = $this->backupService->verifyAndTestRestore($backupPath, $manifest);

            // Enforce upper PK bound (never exceed manifest end_id).
            $upperBoundId = (int) $manifest['end_id'];
            if (isset($options['end_id']) && $options['end_id'] !== null && $options['end_id'] !== '') {
                $requestedEnd = (int) $options['end_id'];
                if ($requestedEnd > $upperBoundId) {
                    throw new QueueRecoveryException(
                        "El --end-id ({$requestedEnd}) excede el límite superior autorizado del manifiesto ({$upperBoundId}).",
                        'upper_bound_violation',
                    );
                }
                $upperBoundId = min($upperBoundId, $requestedEnd);
            }

            $effectiveStartId = isset($options['start_id']) && $options['start_id'] !== null
                ? max((int) $options['start_id'], (int) $manifest['start_id'])
                : (int) $manifest['start_id'];

            // Load existing checkpoint and reconcile any crash between DB commit and checkpoint write.
            $existingCheckpoint = $checkpoint->load($manifestSha);
            $alreadyCommittedIds = [];
            $cumulativeDeleted = 0;
            $lastCommittedId = $effectiveStartId;
            $batchSeq = $auditLog->lastBatchSeq($manifestSha);

            if ($existingCheckpoint !== null) {
                $checkpointCompleted = (bool) ($existingCheckpoint['completed'] ?? false);
                $lastCommittedId = max($lastCommittedId, (int) ($existingCheckpoint['last_committed_id'] ?? 0));
                $cumulativeDeleted = (int) ($existingCheckpoint['total_deleted'] ?? 0);

                foreach ((array) $manifest['entries'] as $entry) {
                    $eid = (int) $entry['id'];
                    if ($eid <= $lastCommittedId) {
                        $alreadyCommittedIds[$eid] = true;
                    }
                }

                if ($checkpointCompleted) {
                    // Idempotency check: verify none of the manifest entries remain in jobs.
                    $remainingPresent = $this->countPresentManifestIds($db, $table, (array) $manifest['entries']);
                    if ($remainingPresent === 0) {
                        $auditLog->record('apply_idempotent_noop', [
                            'incident_id' => $manifest['incident_id'],
                            'manifest_sha256' => $manifestSha,
                            'total_deleted_previously' => $cumulativeDeleted,
                        ]);

                        return [
                            'status' => 'already_completed',
                            'incident_id' => $manifest['incident_id'],
                            'manifest_sha256' => $manifestSha,
                            'deleted_this_run' => 0,
                            'total_deleted' => $cumulativeDeleted,
                            'remaining_in_manifest' => 0,
                            'reconciled_after_crash' => 0,
                            'interrupted' => false,
                            'backup_verification' => $backupVerification,
                            'disk_checked_mb' => $checkedDisk,
                            'checkpoint_path' => $checkpointPath,
                        ];
                    }

                    throw new QueueRecoveryException(
                        "Inconsistencia: el checkpoint marca el manifiesto completado pero aún existen {$remainingPresent} registros en '{$table}'.",
                        'checkpoint_inconsistent',
                    );
                }

                if (! $resume) {
                    throw new QueueRecoveryException(
                        'Existe un checkpoint incompleto para este manifiesto. Utilice --resume para reanudar de forma segura.',
                        'checkpoint_requires_resume',
                        ['checkpoint_path' => $checkpointPath, 'last_committed_id' => $lastCommittedId],
                    );
                }
            }

            // Reconcile any unresolved batch_intent left by a crash strictly between DB commit and checkpoint save.
            $reconciledAfterCrash = 0;
            $unresolvedIntents = $auditLog->findUnresolvedIntents($manifestSha);

            if ($unresolvedIntents !== [] && ! $resume && $existingCheckpoint === null) {
                throw new QueueRecoveryException(
                    'Se detectó un lote pendiente de reconciliación tras interrupción en el audit log. Utilice --resume para reconciliar y continuar.',
                    'checkpoint_requires_resume',
                );
            }

            foreach ($unresolvedIntents as $intent) {
                $intentIds = $intent['ids'];
                if ($intentIds === []) {
                    continue;
                }

                $stillPresent = $db->table($table)
                    ->whereIn('id', $intentIds)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if ($stillPresent === []) {
                    // DB commit definitely succeeded before the crash; advance checkpoint without losing traceability!
                    foreach ($intentIds as $cid) {
                        $alreadyCommittedIds[$cid] = true;
                    }
                    $reconciledAfterCrash += count($intentIds);
                    $cumulativeDeleted += count($intentIds);
                    $lastCommittedId = max($lastCommittedId, max($intentIds));

                    $auditLog->record('batch_reconciled_after_crash', [
                        'incident_id' => $manifest['incident_id'],
                        'manifest_sha256' => $manifestSha,
                        'batch_seq' => $intent['batch_seq'],
                        'reconciled_count' => count($intentIds),
                        'min_id' => min($intentIds),
                        'max_id' => max($intentIds),
                        'cumulative_deleted' => $cumulativeDeleted,
                    ]);

                    $checkpoint->saveAtomic([
                        'incident_id' => $manifest['incident_id'],
                        'manifest_sha256' => $manifestSha,
                        'backup_file_sha256' => $backupVerification['file_sha256'],
                        'last_committed_id' => $lastCommittedId,
                        'last_batch_seq' => $intent['batch_seq'],
                        'total_deleted' => $cumulativeDeleted,
                        'manifest_total' => count((array) $manifest['entries']),
                        'completed' => count($alreadyCommittedIds) >= count((array) $manifest['entries']),
                    ]);
                } elseif (count($stillPresent) === count($intentIds)) {
                    // DB transaction rolled back or never executed before crash; record abort and proceed normally.
                    $auditLog->record('batch_aborted', [
                        'incident_id' => $manifest['incident_id'],
                        'manifest_sha256' => $manifestSha,
                        'batch_seq' => $intent['batch_seq'],
                        'reason' => 'intent_not_committed_before_crash',
                    ]);
                } else {
                    throw new QueueRecoveryException(
                        sprintf(
                            'Discrepancia crítica durante reconciliación de lote #%d: %d de %d IDs siguen presentes.',
                            $intent['batch_seq'],
                            count($stillPresent),
                            count($intentIds),
                        ),
                        'concurrent_modification',
                        ['batch_seq' => $intent['batch_seq']],
                    );
                }
            }

            // Filter remaining entries strictly within bounds and not yet committed.
            $remainingEntries = array_values(array_filter(
                (array) $manifest['entries'],
                static fn (array $entry): bool => ! isset($alreadyCommittedIds[(int) $entry['id']])
                    && (int) $entry['id'] <= $upperBoundId
                    && (int) $entry['id'] > $lastCommittedId,
            ));

            // If crash reconciliation already completed 100% of manifest entries, finalize cleanly.
            if ($remainingEntries === [] && count($alreadyCommittedIds) >= count((array) $manifest['entries'])) {
                $auditLog->record('apply_completed', [
                    'incident_id' => $manifest['incident_id'],
                    'manifest_sha256' => $manifestSha,
                    'deleted_this_run' => 0,
                    'reconciled_after_crash' => $reconciledAfterCrash,
                    'total_deleted' => $cumulativeDeleted,
                ]);

                return [
                    'status' => 'completed',
                    'incident_id' => $manifest['incident_id'],
                    'manifest_sha256' => $manifestSha,
                    'deleted_this_run' => 0,
                    'total_deleted' => $cumulativeDeleted,
                    'remaining_in_manifest' => 0,
                    'reconciled_after_crash' => $reconciledAfterCrash,
                    'interrupted' => false,
                    'backup_verification' => $backupVerification,
                    'disk_checked_mb' => $checkedDisk,
                    'checkpoint_path' => $checkpointPath,
                ];
            }

            // SAFETY CHECK 3: Verify ZERO reserved jobs among all remaining candidate IDs before deleting anything.
            $this->assertNoReservedCandidates($db, $table, $remainingEntries);

            $auditLog->record('preflight_passed', [
                'incident_id' => $manifest['incident_id'],
                'manifest_sha256' => $manifestSha,
                'backup_file_sha256' => $backupVerification['file_sha256'],
                'maintenance_window' => $maintenanceWindow,
                'remaining_candidates' => count($remainingEntries),
                'upper_bound_id' => $upperBoundId,
                'disk_checked_mb' => $checkedDisk,
            ]);

            $deletedThisRun = 0;
            $deadlockRetriesTotal = 0;
            $batchesExecuted = 0;
            $toProcess = array_slice($remainingEntries, 0, $maxDelete);

            foreach (array_chunk($toProcess, $chunk) as $batchEntries) {
                if ($this->shouldStop($batchesExecuted)) {
                    $this->interrupted = true;
                    break;
                }

                // Re-verify minimum free disk space before EVERY batch.
                $this->diskChecker->assertMinimumFreeSpace($diskPaths, $minFreeDiskMb);

                $batchSeq++;
                $batchIds = array_map(static fn (array $e): int => (int) $e['id'], $batchEntries);
                $minId = min($batchIds);
                $maxId = max($batchIds);

                // Write-Ahead Intent BEFORE DB commit for crash traceability.
                $auditLog->record('batch_intent', [
                    'incident_id' => $manifest['incident_id'],
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'count' => count($batchIds),
                    'min_id' => $minId,
                    'max_id' => $maxId,
                    'ids' => $batchIds,
                ]);

                $retriesUsed = $this->deleteBatchWithDeadlockRetry(
                    $db,
                    $table,
                    $batchEntries,
                    $allowedJobs,
                    $upperBoundId,
                    $maxDeadlockRetries,
                    $auditLog,
                    $manifestSha,
                    $batchSeq,
                );
                $deadlockRetriesTotal += $retriesUsed;

                // Optional hook for testing crash strictly between DB commit and checkpoint persistence.
                if ($this->afterCommitBeforeCheckpointHook !== null) {
                    ($this->afterCommitBeforeCheckpointHook)($batchSeq, $batchIds);
                }

                $deletedThisRun += count($batchIds);
                $cumulativeDeleted += count($batchIds);
                $lastCommittedId = $maxId;
                $batchesExecuted++;

                foreach ($batchIds as $cid) {
                    $alreadyCommittedIds[$cid] = true;
                }

                $remainingAfterBatch = count((array) $manifest['entries']) - count($alreadyCommittedIds);

                // Reconcile manifest, backup, deleted, and remaining counts after every batch.
                $auditLog->record('batch_committed', [
                    'incident_id' => $manifest['incident_id'],
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'deleted_count' => count($batchIds),
                    'min_id' => $minId,
                    'max_id' => $maxId,
                    'reconciliation' => [
                        'manifest_total' => count((array) $manifest['entries']),
                        'backup_verified_total' => $backupVerification['row_count'],
                        'cumulative_deleted' => $cumulativeDeleted,
                        'remaining_in_manifest' => max(0, $remainingAfterBatch),
                    ],
                ]);

                $checkpoint->saveAtomic([
                    'incident_id' => $manifest['incident_id'],
                    'manifest_sha256' => $manifestSha,
                    'backup_file_sha256' => $backupVerification['file_sha256'],
                    'last_committed_id' => $lastCommittedId,
                    'last_batch_seq' => $batchSeq,
                    'total_deleted' => $cumulativeDeleted,
                    'manifest_total' => count((array) $manifest['entries']),
                    'completed' => $remainingAfterBatch <= 0,
                ]);

                if ($sleepMs > 0 && $remainingAfterBatch > 0 && $deletedThisRun < count($toProcess) && ! $this->shouldStop($batchesExecuted)) {
                    usleep($sleepMs * 1000);
                }
            }

            $remainingFinal = max(0, count((array) $manifest['entries']) - count($alreadyCommittedIds));
            $status = $this->interrupted
                ? 'interrupted'
                : ($remainingFinal === 0 ? 'completed' : 'partial_limit_reached');

            $auditLog->record($this->interrupted ? 'apply_interrupted' : 'apply_completed', [
                'incident_id' => $manifest['incident_id'],
                'manifest_sha256' => $manifestSha,
                'status' => $status,
                'deleted_this_run' => $deletedThisRun,
                'reconciled_after_crash' => $reconciledAfterCrash,
                'total_deleted' => $cumulativeDeleted,
                'remaining_in_manifest' => $remainingFinal,
                'deadlock_retries' => $deadlockRetriesTotal,
            ]);

            return [
                'status' => $status,
                'incident_id' => $manifest['incident_id'],
                'manifest_sha256' => $manifestSha,
                'deleted_this_run' => $deletedThisRun,
                'total_deleted' => $cumulativeDeleted,
                'remaining_in_manifest' => $remainingFinal,
                'reconciled_after_crash' => $reconciledAfterCrash,
                'deadlock_retries' => $deadlockRetriesTotal,
                'interrupted' => $this->interrupted,
                'backup_verification' => $backupVerification,
                'disk_checked_mb' => $checkedDisk,
                'checkpoint_path' => $checkpointPath,
            ];
        } catch (QueueRecoveryException $e) {
            $auditLog->record('apply_aborted', [
                'reason_code' => $e->reasonCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ]);
            throw $e;
        } finally {
            $this->lock->release();
        }
    }

    private function assertAutomationsSuspended(): void
    {
        $reservationsAuto = (bool) config('inventory.meli_order_reservations.automatic', false);
        $beautyScheduler = (bool) config('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);

        if ($reservationsAuto || $beautyScheduler) {
            throw new QueueRecoveryException(
                'No se permite --apply mientras alguna automatización siga habilitada (INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC y MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED deben estar en false).',
                'automation_enabled',
                [
                    'inventory_meli_order_reservations_automatic' => $reservationsAuto,
                    'meli_beauty_scheduled_prices_scheduler_enabled' => $beautyScheduler,
                ],
            );
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function assertNoReservedCandidates(ConnectionInterface $db, string $table, array $entries): void
    {
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $entries);

        foreach (array_chunk($ids, self::MAX_CHUNK_SIZE) as $chunkIds) {
            $reservedIds = $db->table($table)
                ->whereIn('id', $chunkIds)
                ->whereNotNull('reserved_at')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($reservedIds !== []) {
                throw new QueueRecoveryException(
                    sprintf(
                        'Se detectaron %d trabajo(s) reservado(s) en los lotes objetivo (p. ej. ID #%d). Abortando.',
                        count($reservedIds),
                        $reservedIds[0],
                    ),
                    'reserved_jobs_detected',
                    ['sample_reserved_ids' => array_slice($reservedIds, 0, 10)],
                );
            }
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function countPresentManifestIds(ConnectionInterface $db, string $table, array $entries): int
    {
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $entries);
        $present = 0;

        foreach (array_chunk($ids, self::MAX_CHUNK_SIZE) as $chunkIds) {
            $present += (int) $db->table($table)->whereIn('id', $chunkIds)->count();
        }

        return $present;
    }

    /**
     * @param  list<array<string, mixed>>  $batchEntries
     * @param  list<string>  $allowedJobs
     */
    private function deleteBatchWithDeadlockRetry(
        ConnectionInterface $db,
        string $table,
        array $batchEntries,
        array $allowedJobs,
        int $upperBoundId,
        int $maxRetries,
        QueueRecoveryAuditLog $auditLog,
        string $manifestSha,
        int $batchSeq,
    ): int {
        $retries = 0;

        while (true) {
            try {
                $this->deleteSingleBatchTransaction($db, $table, $batchEntries, $allowedJobs, $upperBoundId);

                return $retries;
            } catch (QueryException $e) {
                if (! $this->isDeadlockOrLockWait($e) || $retries >= $maxRetries) {
                    $auditLog->record('batch_aborted', [
                        'manifest_sha256' => $manifestSha,
                        'batch_seq' => $batchSeq,
                        'reason' => $this->isDeadlockOrLockWait($e) ? 'deadlock_retries_exceeded' : 'query_error',
                        'retries' => $retries,
                    ]);

                    if ($this->isDeadlockOrLockWait($e)) {
                        throw new QueueRecoveryException(
                            "Deadlock o lock wait timeout persistente tras {$retries} reintento(s) en lote #{$batchSeq}.",
                            'deadlock_retries_exceeded',
                            ['batch_seq' => $batchSeq, 'retries' => $retries],
                            $e,
                        );
                    }

                    throw $e;
                }

                $retries++;
                $auditLog->record('batch_deadlock_retry', [
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'attempt' => $retries,
                    'max_retries' => $maxRetries,
                ]);
                usleep(min(500000, 50000 * (2 ** ($retries - 1))));
            } catch (QueueRecoveryException $e) {
                $auditLog->record('batch_aborted', [
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'reason' => $e->reasonCode,
                ]);
                throw $e;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $batchEntries
     * @param  list<string>  $allowedJobs
     */
    private function deleteSingleBatchTransaction(
        ConnectionInterface $db,
        string $table,
        array $batchEntries,
        array $allowedJobs,
        int $upperBoundId,
    ): void {
        $expectedById = [];
        foreach ($batchEntries as $entry) {
            $id = (int) $entry['id'];
            if ($id > $upperBoundId) {
                throw new QueueRecoveryException(
                    "Job #{$id} excede el límite superior autorizado end_id={$upperBoundId}.",
                    'upper_bound_violation',
                    ['job_id' => $id, 'end_id' => $upperBoundId],
                );
            }
            $expectedById[$id] = $entry;
        }

        $batchIds = array_keys($expectedById);
        sort($batchIds);
        $driver = $db->getDriverName();

        $db->transaction(function () use ($db, $table, $batchIds, $expectedById, $allowedJobs, $upperBoundId, $driver): void {
            $query = $db->table($table)
                ->select(['id', 'queue', 'payload', 'reserved_at'])
                ->whereIn('id', $batchIds)
                ->orderBy('id');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $query->lockForUpdate();
            }

            $rows = $query->get()->keyBy('id');

            if ($rows->count() !== count($batchIds)) {
                $missing = array_values(array_diff($batchIds, $rows->keys()->map(static fn ($k): int => (int) $k)->all()));
                throw new QueueRecoveryException(
                    sprintf(
                        'Modificación concurrente detectada: %d candidato(s) desaparecieron antes de la eliminación (p. ej. ID #%d).',
                        count($missing),
                        $missing[0] ?? 0,
                    ),
                    'concurrent_modification',
                    ['missing_ids' => array_slice($missing, 0, 10)],
                );
            }

            foreach ($batchIds as $id) {
                $row = $rows->get($id);
                $expected = $expectedById[$id];

                if ($row->reserved_at !== null) {
                    throw new QueueRecoveryException(
                        "Trabajo reservado detectado dentro de la transacción para job #{$id}. Abortando y revirtiendo lote.",
                        'reserved_jobs_detected',
                        ['job_id' => $id],
                    );
                }

                if ((string) $row->queue !== (string) $expected['queue']) {
                    throw new QueueRecoveryException(
                        "Modificación concurrente de cola en job #{$id}: esperada '{$expected['queue']}', actual '{$row->queue}'.",
                        'concurrent_modification',
                        ['job_id' => $id],
                    );
                }

                $actualPayload = (string) $row->payload;
                $actualHash = hash('sha256', $actualPayload);
                if (! hash_equals((string) $expected['payload_hash'], $actualHash)) {
                    throw new QueueRecoveryException(
                        "Modificación concurrente de payload en job #{$id}: el SHA-256 cambió.",
                        'concurrent_modification',
                        ['job_id' => $id],
                    );
                }

                $reinspected = $this->inspector->inspect($actualPayload, $allowedJobs);
                if ($reinspected['classification'] !== QueuePayloadInspector::CLASSIFICATION_CANDIDATE
                    || $reinspected['job_class'] !== (string) $expected['job_class']) {
                    throw new QueueRecoveryException(
                        "Revalidación fallida dentro de transacción para job #{$id}: clase o estructura inconsistente.",
                        'concurrent_modification',
                        ['job_id' => $id, 'reason' => $reinspected['reason']],
                    );
                }
            }

            $deleted = $db->table($table)
                ->whereIn('id', $batchIds)
                ->where('id', '<=', $upperBoundId)
                ->whereNull('reserved_at')
                ->delete();

            if ($deleted !== count($batchIds)) {
                throw new QueueRecoveryException(
                    sprintf(
                        'Discrepancia en DELETE: se esperaban eliminar %d filas, se eliminaron %d. Revirtiendo transacción.',
                        count($batchIds),
                        $deleted,
                    ),
                    'concurrent_modification',
                    ['expected' => count($batchIds), 'deleted' => $deleted],
                );
            }
        });
    }

    /**
     * Enriches dry-run candidates with meli_account_id / remote_order_id using batched PK lookups.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $anomalies
     * @param  array<string, int>  $totals
     * @return list<array<string, mixed>>
     */
    private function enrichBatchCandidates(
        ConnectionInterface $db,
        array $candidates,
        bool $hasMeliOrders,
        bool $hasMeliAccounts,
        bool $hasBeautyDiscounts,
        array &$anomalies,
        array &$totals,
    ): array {
        $orderIds = [];
        $discountIds = [];

        foreach ($candidates as $c) {
            if ($c['entity_type'] === 'meli_order') {
                $orderIds[(int) $c['entity_id']] = true;
            } elseif ($c['entity_type'] === 'beauty_discount') {
                $discountIds[(int) $c['entity_id']] = true;
            }
        }

        $ordersById = [];
        if ($hasMeliOrders && $orderIds !== []) {
            $rows = $db->table('meli_orders')
                ->select(['id', 'order_id', 'meli_account_id'])
                ->whereIn('id', array_keys($orderIds))
                ->get();
            foreach ($rows as $row) {
                $ordersById[(int) $row->id] = [
                    'order_id' => $row->order_id !== null ? (string) $row->order_id : null,
                    'meli_account_id' => $row->meli_account_id !== null ? (int) $row->meli_account_id : null,
                ];
            }
        }

        $discountsById = [];
        if ($hasBeautyDiscounts && $discountIds !== []) {
            $rows = $db->table('meli_beauty_scheduled_discounts')
                ->select(['id', 'meli_account_id'])
                ->whereIn('id', array_keys($discountIds))
                ->get();
            foreach ($rows as $row) {
                $discountsById[(int) $row->id] = [
                    'meli_account_id' => $row->meli_account_id !== null ? (int) $row->meli_account_id : null,
                ];
            }
        }

        $validAccountIds = [];
        if ($hasMeliAccounts) {
            $referencedAccountIds = [];
            foreach ($ordersById as $o) {
                if ($o['meli_account_id'] !== null) {
                    $referencedAccountIds[$o['meli_account_id']] = true;
                }
            }
            foreach ($discountsById as $d) {
                if ($d['meli_account_id'] !== null) {
                    $referencedAccountIds[$d['meli_account_id']] = true;
                }
            }
            if ($referencedAccountIds !== []) {
                foreach ($db->table('meli_accounts')->whereIn('id', array_keys($referencedAccountIds))->pluck('id') as $aid) {
                    $validAccountIds[(int) $aid] = true;
                }
            }
        }

        $enriched = [];
        foreach ($candidates as $c) {
            $accountId = null;
            $remoteOrderId = null;

            if ($c['entity_type'] === 'meli_order' && $hasMeliOrders) {
                $order = $ordersById[(int) $c['entity_id']] ?? null;
                if ($order === null) {
                    $totals['missing_order_anomalies']++;
                    $anomalies['missing_orders'][] = (int) $c['entity_id'];
                } else {
                    $accountId = $order['meli_account_id'];
                    $remoteOrderId = $order['order_id'];
                    if ($accountId === null || ($hasMeliAccounts && ! isset($validAccountIds[$accountId]))) {
                        $totals['account_inconsistencies']++;
                        if (count($anomalies['account_inconsistencies']) < 500) {
                            $anomalies['account_inconsistencies'][] = [
                                'job_id' => $c['id'],
                                'entity_type' => 'meli_order',
                                'entity_id' => $c['entity_id'],
                                'meli_account_id' => $accountId,
                            ];
                        }
                    }
                }
            } elseif ($c['entity_type'] === 'beauty_discount' && $hasBeautyDiscounts) {
                $discount = $discountsById[(int) $c['entity_id']] ?? null;
                if ($discount === null) {
                    $totals['missing_beauty_rule_anomalies']++;
                    $anomalies['missing_beauty_rules'][] = (int) $c['entity_id'];
                } else {
                    $accountId = $discount['meli_account_id'];
                    if ($accountId === null || ($hasMeliAccounts && ! isset($validAccountIds[$accountId]))) {
                        $totals['account_inconsistencies']++;
                        if (count($anomalies['account_inconsistencies']) < 500) {
                            $anomalies['account_inconsistencies'][] = [
                                'job_id' => $c['id'],
                                'entity_type' => 'beauty_discount',
                                'entity_id' => $c['entity_id'],
                                'meli_account_id' => $accountId,
                            ];
                        }
                    }
                }
            }

            $enriched[] = [
                'id' => $c['id'],
                'job_class' => $c['job_class'],
                'queue' => $c['queue'],
                'meli_account_id' => $accountId,
                'entity_type' => $c['entity_type'],
                'entity_id' => $c['entity_id'],
                'remote_order_id' => $remoteOrderId,
                'meli_item_id' => $c['meli_item_id'],
                'created_at' => $c['created_at'],
                'created_at_iso' => $c['created_at_iso'],
                'reserved_at' => null,
                'payload_hash' => $c['payload_hash'],
            ];
        }

        return $enriched;
    }

    private function validateChunk(int $chunk): int
    {
        if ($chunk < 1 || $chunk > self::MAX_CHUNK_SIZE) {
            throw new QueueRecoveryException(
                sprintf('El tamaño de lote --chunk (%d) debe estar entre 1 y %d.', $chunk, self::MAX_CHUNK_SIZE),
                'invalid_chunk_size',
                ['chunk' => $chunk, 'max_chunk' => self::MAX_CHUNK_SIZE],
            );
        }

        return $chunk;
    }

    private function validateMaxDelete(int $maxDelete): int
    {
        if ($maxDelete < 1) {
            throw new QueueRecoveryException(
                "El parámetro --max-delete ({$maxDelete}) debe ser mayor o igual a 1.",
                'invalid_max_delete',
                ['max_delete' => $maxDelete],
            );
        }

        return $maxDelete;
    }

    private function isDeadlockOrLockWait(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        return $sqlState === '40001'
            || in_array($driverCode, [1205, 1213], true)
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout')
            || str_contains($message, 'database is locked');
    }

    private function shouldStop(int $batchesExecuted): bool
    {
        if ($this->interrupted) {
            return true;
        }

        if ($this->shouldInterruptChecker !== null && ($this->shouldInterruptChecker)($batchesExecuted)) {
            return true;
        }

        return false;
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->interrupted = true;
        };

        if (defined('SIGINT')) {
            @pcntl_signal(SIGINT, $handler);
        }
        if (defined('SIGTERM')) {
            @pcntl_signal(SIGTERM, $handler);
        }
    }
}
