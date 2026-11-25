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

    public const DEFAULT_MAX_SCAN = 50000;

    public const DEFAULT_MIN_FREE_DISK_MB = 8192; // 8 GiB

    public const DEFAULT_MAX_DEADLOCK_RETRIES = 3;

    private bool $interrupted = false;

    /** @var (callable(int, list<int>): void)|null */
    private $beforeIntentHook = null;

    /** @var (callable(int, list<int>): void)|null */
    private $afterIntentBeforeCommitHook = null;

    /** @var (callable(int, list<int>): void)|null */
    private $afterCommitBeforeAuditHook = null;

    /** @var (callable(int, list<int>): void)|null */
    private $afterAuditBeforeCheckpointHook = null;

    /** @var (callable(int): bool)|null */
    private $shouldInterruptChecker = null;

    public function __construct(
        private readonly QueuePayloadInspector $inspector,
        private readonly QueueRecoveryManifest $manifestManager,
        private readonly QueueRecoveryBackupService $backupService,
        private readonly QueueRecoveryDiskChecker $diskChecker,
        private readonly QueueWorkerDetector $workerDetector,
        private readonly QueueRecoveryLock $lock,
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {}

    /**
     * Test hook to simulate failure strictly before persisting batch_intent.
     *
     * @param  (callable(int, list<int>): void)|null  $hook
     */
    public function setBeforeIntentHook(?callable $hook): void
    {
        $this->beforeIntentHook = $hook;
    }

    /**
     * Test hook to simulate failure strictly after batch_intent and before DB DELETE/COMMIT.
     *
     * @param  (callable(int, list<int>): void)|null  $hook
     */
    public function setAfterIntentBeforeCommitHook(?callable $hook): void
    {
        $this->afterIntentBeforeCommitHook = $hook;
    }

    /**
     * Test hook to simulate failure strictly after DB COMMIT and before batch_committed audit record.
     *
     * @param  (callable(int, list<int>): void)|null  $hook
     */
    public function setAfterCommitBeforeAuditHook(?callable $hook): void
    {
        $this->afterCommitBeforeAuditHook = $hook;
    }

    /**
     * Backwards-compatible alias for setAfterCommitBeforeAuditHook().
     *
     * @param  (callable(int, list<int>): void)|null  $hook
     */
    public function setAfterCommitBeforeCheckpointHook(?callable $hook): void
    {
        $this->afterCommitBeforeAuditHook = $hook;
    }

    /**
     * Test hook to simulate failure strictly after batch_committed and before checkpoint save.
     *
     * @param  (callable(int, list<int>): void)|null  $hook
     */
    public function setAfterAuditBeforeCheckpointHook(?callable $hook): void
    {
        $this->afterAuditBeforeCheckpointHook = $hook;
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
     * Read-only PK-cursor scan producing counts, anomalies, manifest (checksum + optional HMAC), and optional backup.
     * Explicitly separates authorized_upper_bound, last_scanned_id, last_candidate_id, next_start_id, and is_partial.
     *
     * @param  array{
     *     jobs: list<string>,
     *     chunk?: int,
     *     max_delete?: int,
     *     max_scan?: int,
     *     start_id?: int,
     *     end_id?: ?int,
     *     incident_id?: string,
     *     manifest_path?: ?string,
     *     backup_out_path?: ?string,
     *     audit_log_path?: ?string,
     *     hmac_key?: ?string,
     *     queue_connection?: ?string
     * }  $options
     * @return array<string, mixed>
     */
    public function dryRun(array $options): array
    {
        $startedAt = microtime(true);
        $allowedJobs = $this->inspector->validateWhitelist($options['jobs']);
        $chunk = $this->validateChunk((int) ($options['chunk'] ?? self::DEFAULT_CHUNK_SIZE));
        $maxDelete = $this->validateMaxDelete((int) ($options['max_delete'] ?? self::DEFAULT_MAX_DELETE));
        $maxScan = $this->validateMaxScan((int) ($options['max_scan'] ?? self::DEFAULT_MAX_SCAN));
        $startId = max(0, (int) ($options['start_id'] ?? 0));
        $hmacKey = isset($options['hmac_key']) ? (string) $options['hmac_key'] : null;

        $incidentId = trim((string) ($options['incident_id'] ?? 'INC-QUEUE-R2-'.now()->utc()->format('Ymd-His')));
        if ($incidentId === '') {
            $incidentId = 'INC-QUEUE-R2-'.now()->utc()->format('Ymd-His');
        }

        $target = $this->resolveQueueTarget($options['queue_connection'] ?? null);
        $db = DB::connection($target['db_connection']);
        $table = $target['table'];

        $currentMaxId = (int) ($db->table($table)->max('id') ?? 0);
        $authorizedUpperBound = isset($options['end_id']) && $options['end_id'] !== null && $options['end_id'] !== ''
            ? (int) $options['end_id']
            : $currentMaxId;

        if ($authorizedUpperBound < 0) {
            throw new QueueRecoveryException('El parámetro --end-id no puede ser negativo.', 'invalid_bounds');
        }

        if ($authorizedUpperBound > 0 && $startId > $authorizedUpperBound) {
            throw new QueueRecoveryException(
                "Rango inválido: --start-id ({$startId}) es mayor que --end-id ({$authorizedUpperBound}).",
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
        $scanLimitReached = false;
        $scanExhausted = $cursor >= $authorizedUpperBound;

        while ($cursor < $authorizedUpperBound && ! $limitReached && ! $scanLimitReached) {
            $rows = $db->table($table)
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->where('id', '>', $cursor)
                ->where('id', '<=', $authorizedUpperBound)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                $scanExhausted = true;
                break;
            }

            $fetchedCount = $rows->count();
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
                } else {
                    $inspection = $this->inspector->inspect((string) $row->payload, $allowedJobs);
                    $classLabel = $inspection['job_class'] ?? 'UNKNOWN_OR_INVALID';
                    $countsByClass[$classLabel] = ($countsByClass[$classLabel] ?? 0) + 1;

                    if ($inspection['classification'] === QueuePayloadInspector::CLASSIFICATION_INVALID_JSON) {
                        $totals['preserved_invalid_json']++;
                        if (count($anomalies['invalid_payload_job_ids']) < 500) {
                            $anomalies['invalid_payload_job_ids'][] = $jobId;
                        }
                    } elseif ($inspection['classification'] === QueuePayloadInspector::CLASSIFICATION_INCONSISTENT) {
                        $totals['preserved_inconsistent']++;
                        if (count($anomalies['inconsistent_payload_job_ids']) < 500) {
                            $anomalies['inconsistent_payload_job_ids'][] = [
                                'job_id' => $jobId,
                                'reason' => $inspection['reason'],
                            ];
                        }
                    } elseif ($inspection['classification'] === QueuePayloadInspector::CLASSIFICATION_OTHER_MODULE) {
                        $totals['preserved_other_modules']++;
                    } else {
                        $normalizedRow = $this->backupService->normalizeRow($row);
                        $rowSha256 = $this->backupService->rowHash($normalizedRow);

                        $batchCandidates[] = [
                            'id' => $jobId,
                            'job_class' => (string) $inspection['job_class'],
                            'queue' => $normalizedRow['queue'],
                            'attempts' => $normalizedRow['attempts'],
                            'available_at' => $normalizedRow['available_at'],
                            'entity_type' => (string) $inspection['entity_type'],
                            'entity_id' => (int) $inspection['entity_id'],
                            'meli_item_id' => $inspection['meli_item_id'],
                            'created_at' => $normalizedRow['created_at'],
                            'created_at_iso' => gmdate('Y-m-d\TH:i:s\Z', $normalizedRow['created_at']),
                            'reserved_at' => null,
                            'payload_hash' => (string) $inspection['payload_hash'],
                            'row_sha256' => $rowSha256,
                        ];

                        if ((count($entries) + count($batchCandidates)) >= $maxDelete) {
                            $limitReached = true;
                            break;
                        }
                    }
                }

                if ($maxScan > 0 && $totals['scanned'] >= $maxScan) {
                    $scanLimitReached = true;
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

            if (! $limitReached && ! $scanLimitReached && ($fetchedCount < $chunk || $cursor >= $authorizedUpperBound)) {
                $scanExhausted = true;
                break;
            }
        }

        if ($cursor >= $authorizedUpperBound) {
            $scanExhausted = true;
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

        $isPartial = ! $scanExhausted && ($limitReached || $scanLimitReached);
        $lastScannedId = $isPartial ? $highestScannedId : $authorizedUpperBound;
        $lastCandidateId = $entries !== [] ? (int) end($entries)['id'] : null;
        $nextStartId = $lastScannedId;

        $stopReason = match (true) {
            ! $isPartial => 'completed_upper_bound',
            $limitReached => 'max_delete_reached',
            $scanLimitReached => 'max_scan_reached',
            default => 'completed_upper_bound',
        };

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
            'authorized_upper_bound' => $authorizedUpperBound,
            'last_scanned_id' => $lastScannedId,
            'last_candidate_id' => $lastCandidateId,
            'next_start_id' => $nextStartId,
            'is_partial' => $isPartial,
            'stop_reason' => $stopReason,
            // Backwards-compatible aliases kept explicitly tied to cursor fields:
            'requested_end_id' => $authorizedUpperBound,
            'end_id' => $lastScannedId,
            'chunk_size' => $chunk,
            'max_delete' => $maxDelete,
            'max_scan' => $maxScan,
            'limit_reached' => $limitReached,
            'scan_limit_reached' => $scanLimitReached,
            'totals' => $totals,
            'counts_by_class' => $countsByClass,
            'counts_by_entity' => $countsByEntity,
            'anomalies' => $anomalies,
            'entries' => $entries,
        ];

        $finalManifest = $this->manifestManager->finalize($manifestBody, $hmacKey);

        $manifestPath = isset($options['manifest_path']) && trim((string) $options['manifest_path']) !== ''
            ? trim((string) $options['manifest_path'])
            : null;

        $manifestSizeBytes = null;
        if ($manifestPath !== null) {
            $this->manifestManager->writeAtomic($manifestPath, $finalManifest, $hmacKey);
            $manifestSizeBytes = (int) (@filesize($manifestPath) ?: 0);
        }

        $backupResult = null;
        $backupOutPath = isset($options['backup_out_path']) && trim((string) $options['backup_out_path']) !== ''
            ? trim((string) $options['backup_out_path'])
            : null;

        if ($backupOutPath !== null) {
            $backupResult = $this->backupService->exportForManifest($db, $table, $finalManifest, $backupOutPath);
        }

        $auditLog = new QueueRecoveryAuditLog($options['audit_log_path'] ?? null, $this->pathGuard);
        $auditLog->record('dry_run_completed', [
            'incident_id' => $incidentId,
            'manifest_sha256' => $finalManifest['manifest_sha256'],
            'manifest_hmac_present' => isset($finalManifest['manifest_hmac_sha256']),
            'manifest_path' => $manifestPath,
            'backup_path' => $backupOutPath,
            'start_id' => $startId,
            'authorized_upper_bound' => $authorizedUpperBound,
            'last_scanned_id' => $lastScannedId,
            'last_candidate_id' => $lastCandidateId,
            'next_start_id' => $nextStartId,
            'is_partial' => $isPartial,
            'stop_reason' => $stopReason,
            'totals' => $totals,
        ]);

        return [
            'manifest' => $finalManifest,
            'manifest_path' => $manifestPath,
            'manifest_size_bytes' => $manifestSizeBytes,
            'backup' => $backupResult,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Executes controlled micro-transactional deletion strictly from an operator-approved dry-run manifest
     * and verified external backup, re-checking every safety gate before EVERY micro-batch.
     *
     * @param  array{
     *     jobs: list<string>,
     *     manifest_path: string,
     *     expected_manifest_sha256: string,
     *     backup_path: string,
     *     maintenance_window: string,
     *     maintenance_lock_file: string,
     *     hmac_key?: ?string,
     *     chunk?: int,
     *     max_delete?: int,
     *     start_id?: ?int,
     *     end_id?: ?int,
     *     sleep_ms?: int,
     *     min_free_disk_mb?: int,
     *     audit_log_path: string,
     *     checkpoint_path?: ?string,
     *     resume?: bool,
     *     confirm_reconcile_batch?: ?int,
     *     disk_check_paths?: list<string>,
     *     queue_connection?: ?string,
     *     max_deadlock_retries?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function apply(array $options): array
    {
        $startedAt = microtime(true);
        $this->interrupted = false;
        $this->registerSignalHandlers();

        $allowedJobs = $this->inspector->validateWhitelist($options['jobs']);
        $chunk = $this->validateChunk((int) ($options['chunk'] ?? self::DEFAULT_CHUNK_SIZE));
        $maxDelete = $this->validateMaxDelete((int) ($options['max_delete'] ?? self::DEFAULT_MAX_DELETE));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 100));
        $minFreeDiskMb = (int) ($options['min_free_disk_mb'] ?? self::DEFAULT_MIN_FREE_DISK_MB);
        $resume = (bool) ($options['resume'] ?? false);
        $confirmReconcileBatch = isset($options['confirm_reconcile_batch']) && $options['confirm_reconcile_batch'] !== null && $options['confirm_reconcile_batch'] !== ''
            ? (int) $options['confirm_reconcile_batch']
            : null;
        $maxDeadlockRetries = max(1, (int) ($options['max_deadlock_retries'] ?? self::DEFAULT_MAX_DEADLOCK_RETRIES));
        $expectedManifestSha = isset($options['expected_manifest_sha256']) ? trim((string) $options['expected_manifest_sha256']) : '';
        $hmacKey = isset($options['hmac_key']) ? (string) $options['hmac_key'] : null;

        $auditLogPath = trim((string) ($options['audit_log_path'] ?? ''));
        if ($auditLogPath === '') {
            throw new QueueRecoveryException(
                'El parámetro --audit-log es obligatorio para --apply.',
                'audit_log_required',
            );
        }
        $auditLog = new QueueRecoveryAuditLog($auditLogPath, $this->pathGuard);

        $checkpointPath = isset($options['checkpoint_path']) && trim((string) $options['checkpoint_path']) !== ''
            ? trim((string) $options['checkpoint_path'])
            : $auditLogPath.'.checkpoint.json';
        $checkpoint = new QueueRecoveryCheckpoint($checkpointPath, $this->pathGuard);

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

            // SAFETY CHECK 2a: Authorized maintenance window ID.
            $maintenanceWindow = trim((string) ($options['maintenance_window'] ?? ''));
            if ($maintenanceWindow === '') {
                throw new QueueRecoveryException(
                    'Se requiere una ventana operativa de mantenimiento autorizada (--maintenance-window).',
                    'maintenance_window_missing',
                );
            }

            // SAFETY CHECK 2b: Fail-closed maintenance sentinel file path.
            $maintenanceLockFile = trim((string) ($options['maintenance_lock_file'] ?? ''));
            if ($maintenanceLockFile === '') {
                throw new QueueRecoveryException(
                    'Se requiere el archivo sentinel de ventana de mantenimiento (--maintenance-lock-file) en modo fail-closed.',
                    'maintenance_gate_missing',
                );
            }

            // SAFETY CHECK 6: Load & verify dry-run manifest with independent operator approval (--expected-manifest-sha256) + optional HMAC.
            $manifestPath = trim((string) ($options['manifest_path'] ?? ''));
            if ($manifestPath === '') {
                throw new QueueRecoveryException(
                    'El parámetro --manifest generado previamente con --dry-run es obligatorio para --apply.',
                    'manifest_missing',
                );
            }

            $manifest = $this->manifestManager->loadAndVerify(
                $manifestPath,
                $this->inspector,
                $expectedManifestSha,
                $hmacKey,
                requireOperatorApproval: true,
            );
            $manifestSha = (string) $manifest['manifest_sha256'];
            $incidentId = (string) $manifest['incident_id'];
            $authorizedUpperBound = (int) $manifest['authorized_upper_bound'];
            $manifestLastScannedId = (int) $manifest['last_scanned_id'];
            $manifestNextStartId = (int) $manifest['next_start_id'];
            $manifestEntries = (array) $manifest['entries'];
            $manifestTotal = count($manifestEntries);

            // Verify maintenance sentinel file matches incident_id, window_id, workers_halted_confirmed=true, and is not expired.
            $this->workerDetector->assertMaintenanceGateActive($maintenanceLockFile, $maintenanceWindow, $incidentId);

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

            // Determine queues present in manifest (plus default 'meli' if empty) and run fail-closed worker check.
            $targetQueues = array_values(array_unique(array_filter(array_map(
                static fn (array $e): string => (string) ($e['queue'] ?? ''),
                $manifestEntries,
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

            // SAFETY CHECK 7 & 8: Verify backup hashes against ALL 7 columns in manifest AND run isolated restore test.
            $backupVerification = $this->backupService->verifyAndTestRestore($backupPath, $manifest);

            // Enforce upper PK bound (never exceed manifest last_scanned_id or authorized_upper_bound).
            $upperBoundId = min($authorizedUpperBound, $manifestLastScannedId);
            if (isset($options['end_id']) && $options['end_id'] !== null && $options['end_id'] !== '') {
                $requestedEnd = (int) $options['end_id'];
                if ($requestedEnd > $authorizedUpperBound) {
                    throw new QueueRecoveryException(
                        "El --end-id ({$requestedEnd}) excede el límite superior autorizado del manifiesto (authorized_upper_bound={$authorizedUpperBound}).",
                        'upper_bound_violation',
                    );
                }
                $upperBoundId = min($upperBoundId, $requestedEnd);
            }

            $effectiveStartId = isset($options['start_id']) && $options['start_id'] !== null
                ? max((int) $options['start_id'], (int) $manifest['start_id'])
                : (int) $manifest['start_id'];

            // Strictly parse the audit log journal FIRST (aborts immediately on ANY corrupt or truncated NDJSON line!).
            $journal = $auditLog->inspectManifestJournal($manifestSha);
            $batchSeq = $journal['last_batch_seq'];

            // Load existing checkpoint.
            $existingCheckpoint = $checkpoint->load($manifestSha);
            $alreadyCommittedIds = [];
            $cumulativeDeleted = 0;
            $lastDeletedId = null;
            $lastCommittedId = $effectiveStartId;

            if ($existingCheckpoint !== null) {
                $checkpointCompleted = (bool) ($existingCheckpoint['completed'] ?? false);
                $lastCommittedId = max($lastCommittedId, (int) ($existingCheckpoint['last_committed_id'] ?? 0));
                $lastDeletedId = isset($existingCheckpoint['last_deleted_id']) && $existingCheckpoint['last_deleted_id'] !== null
                    ? (int) $existingCheckpoint['last_deleted_id']
                    : ($lastCommittedId > 0 ? $lastCommittedId : null);
                $cumulativeDeleted = (int) ($existingCheckpoint['total_deleted'] ?? 0);

                foreach ($manifestEntries as $entry) {
                    $eid = (int) $entry['id'];
                    if ($eid <= $lastCommittedId) {
                        $alreadyCommittedIds[$eid] = true;
                    }
                }

                if ($checkpointCompleted) {
                    $remainingPresent = $this->countPresentManifestIds($db, $table, $manifestEntries);
                    if ($remainingPresent === 0) {
                        $auditLog->record('apply_idempotent_noop', [
                            'incident_id' => $incidentId,
                            'manifest_sha256' => $manifestSha,
                            'total_deleted_previously' => $cumulativeDeleted,
                        ]);

                        return [
                            'status' => 'already_completed',
                            'incident_id' => $incidentId,
                            'manifest_sha256' => $manifestSha,
                            'authorized_upper_bound' => $authorizedUpperBound,
                            'manifest_last_scanned_id' => $manifestLastScannedId,
                            'last_deleted_id' => $lastDeletedId,
                            'next_start_id' => $manifestNextStartId,
                            'manifest_is_partial' => (bool) ($manifest['is_partial'] ?? false),
                            'deleted_this_run' => 0,
                            'total_deleted' => $cumulativeDeleted,
                            'remaining_in_manifest' => 0,
                            'reconciled_after_crash' => 0,
                            'deadlock_retries' => 0,
                            'interrupted' => false,
                            'backup_verification' => $backupVerification,
                            'disk_checked_mb' => $checkedDisk,
                            'checkpoint_path' => $checkpointPath,
                            'batch_durations_ms' => [],
                            'total_duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                            'peak_memory_bytes' => memory_get_peak_usage(true),
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

            // Reconcile batches that were ALREADY committed (`batch_committed` recorded) but crashed before checkpoint save.
            $reconciledAfterCrash = 0;
            $uncheckpointedCommitted = array_values(array_filter(
                $journal['committed_batches'],
                static fn (array $b): bool => ! $b['checkpoint_saved'],
            ));

            if (($uncheckpointedCommitted !== [] || $journal['unresolved_intents'] !== []) && ! $resume) {
                throw new QueueRecoveryException(
                    'Se detectó un lote pendiente de reconciliación tras interrupción en el audit log. Utilice --resume para reconciliar y continuar.',
                    'checkpoint_requires_resume',
                );
            }

            foreach ($uncheckpointedCommitted as $committedBatch) {
                $batchIds = $committedBatch['ids'];
                if ($batchIds === []) {
                    continue;
                }

                $stillPresent = $db->table($table)->whereIn('id', $batchIds)->pluck('id')->all();
                if ($stillPresent !== []) {
                    throw new QueueRecoveryException(
                        sprintf(
                            'Inconsistencia crítica: lote #%d tiene batch_committed en audit log pero %d ID(s) siguen en %s.',
                            $committedBatch['batch_seq'],
                            count($stillPresent),
                            $table,
                        ),
                        'checkpoint_inconsistent',
                        ['batch_seq' => $committedBatch['batch_seq']],
                    );
                }

                $newlyAccounted = 0;
                foreach ($batchIds as $cid) {
                    if (! isset($alreadyCommittedIds[$cid])) {
                        $alreadyCommittedIds[$cid] = true;
                        $newlyAccounted++;
                    }
                }

                if ($newlyAccounted > 0) {
                    $reconciledAfterCrash += $newlyAccounted;
                    $cumulativeDeleted += $newlyAccounted;
                }
                $maxBatchId = max($batchIds);
                $lastCommittedId = max($lastCommittedId, $maxBatchId);
                $lastDeletedId = $lastDeletedId !== null ? max($lastDeletedId, $maxBatchId) : $maxBatchId;
                $isNowCompleted = count($alreadyCommittedIds) >= $manifestTotal;

                $checkpoint->saveAtomic([
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'backup_file_sha256' => $backupVerification['file_sha256'],
                    'authorized_upper_bound' => $authorizedUpperBound,
                    'manifest_last_scanned_id' => $manifestLastScannedId,
                    'last_deleted_id' => $lastDeletedId,
                    'last_committed_id' => $lastCommittedId,
                    'next_start_id' => $isNowCompleted ? $manifestNextStartId : null,
                    'last_batch_seq' => $committedBatch['batch_seq'],
                    'total_deleted' => $cumulativeDeleted,
                    'manifest_total' => $manifestTotal,
                    'completed' => $isNowCompleted,
                ]);

                $auditLog->record('batch_checkpoint_saved', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $committedBatch['batch_seq'],
                    'reconciled_from_committed_audit' => true,
                    'last_deleted_id' => $lastDeletedId,
                    'completed' => $isNowCompleted,
                ]);
            }

            // Reconcile unresolved `batch_intent` entries (NO `batch_committed` recorded).
            // NEVER assume missing rows were deleted by R2 without explicit operator confirmation!
            foreach ($journal['unresolved_intents'] as $intent) {
                $intentIds = $intent['ids'];
                if ($intentIds === []) {
                    continue;
                }

                $stillPresent = $db->table($table)
                    ->whereIn('id', $intentIds)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                if (count($stillPresent) === count($intentIds)) {
                    // 100% of rows still present => DB transaction did NOT commit before crash. Safe to mark aborted and re-execute.
                    $auditLog->record('batch_aborted', [
                        'incident_id' => $incidentId,
                        'manifest_sha256' => $manifestSha,
                        'batch_seq' => $intent['batch_seq'],
                        'reason' => 'intent_not_committed_before_crash',
                    ]);

                    continue;
                }

                if ($stillPresent !== []) {
                    // Partial disappearance under an uncommitted intent => ALWAYS abort and demand investigation!
                    throw new QueueRecoveryException(
                        sprintf(
                            'Discrepancia crítica en lote #%d sin batch_committed: %d de %d IDs siguen presentes y %d desaparecieron. Abortando para investigación manual.',
                            $intent['batch_seq'],
                            count($stillPresent),
                            count($intentIds),
                            count($intentIds) - count($stillPresent),
                        ),
                        'unresolved_partial_batch_intent',
                        [
                            'batch_seq' => $intent['batch_seq'],
                            'present_count' => count($stillPresent),
                            'expected_count' => count($intentIds),
                        ],
                    );
                }

                // 0 rows present, but NO `batch_committed` was recorded in audit log!
                // Do NOT assume automatically that R2 deleted them (e.g. an external worker could have consumed them).
                if ($confirmReconcileBatch !== $intent['batch_seq']) {
                    throw new QueueRecoveryException(
                        sprintf(
                            'Lote ambiguo #%d: existe batch_intent sin batch_committed y los %d IDs (PK %d..%d) ya no existen en %s. No se asume eliminación por R2; investigue logs/BD y solo si confirma que el COMMIT fue de R2 reanude con --resume --confirm-reconcile-batch=%d.',
                            $intent['batch_seq'],
                            count($intentIds),
                            min($intentIds),
                            max($intentIds),
                            $table,
                            $intent['batch_seq'],
                        ),
                        'unresolved_ambiguous_batch_intent',
                        [
                            'batch_seq' => $intent['batch_seq'],
                            'count' => count($intentIds),
                            'min_id' => min($intentIds),
                            'max_id' => max($intentIds),
                        ],
                    );
                }

                // Operator explicitly investigated and confirmed this exact batch_seq via --confirm-reconcile-batch.
                $newlyAccounted = 0;
                foreach ($intentIds as $cid) {
                    if (! isset($alreadyCommittedIds[$cid])) {
                        $alreadyCommittedIds[$cid] = true;
                        $newlyAccounted++;
                    }
                }
                $reconciledAfterCrash += $newlyAccounted;
                $cumulativeDeleted += $newlyAccounted;
                $maxIntentId = max($intentIds);
                $lastCommittedId = max($lastCommittedId, $maxIntentId);
                $lastDeletedId = $lastDeletedId !== null ? max($lastDeletedId, $maxIntentId) : $maxIntentId;
                $isNowCompleted = count($alreadyCommittedIds) >= $manifestTotal;

                $auditLog->record('batch_reconciled_operator_confirmed', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $intent['batch_seq'],
                    'operator_confirmed' => true,
                    'reconciled_count' => $newlyAccounted,
                    'ids' => $intentIds,
                    'min_id' => min($intentIds),
                    'max_id' => $maxIntentId,
                    'cumulative_deleted' => $cumulativeDeleted,
                ]);

                $checkpoint->saveAtomic([
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'backup_file_sha256' => $backupVerification['file_sha256'],
                    'authorized_upper_bound' => $authorizedUpperBound,
                    'manifest_last_scanned_id' => $manifestLastScannedId,
                    'last_deleted_id' => $lastDeletedId,
                    'last_committed_id' => $lastCommittedId,
                    'next_start_id' => $isNowCompleted ? $manifestNextStartId : null,
                    'last_batch_seq' => $intent['batch_seq'],
                    'total_deleted' => $cumulativeDeleted,
                    'manifest_total' => $manifestTotal,
                    'completed' => $isNowCompleted,
                ]);

                $auditLog->record('batch_checkpoint_saved', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $intent['batch_seq'],
                    'operator_confirmed_reconcile' => true,
                    'last_deleted_id' => $lastDeletedId,
                    'completed' => $isNowCompleted,
                ]);
            }

            // Filter remaining entries strictly within bounds and not yet committed.
            $remainingEntries = array_values(array_filter(
                $manifestEntries,
                static fn (array $entry): bool => ! isset($alreadyCommittedIds[(int) $entry['id']])
                    && (int) $entry['id'] <= $upperBoundId
                    && (int) $entry['id'] > $lastCommittedId,
            ));

            // If crash reconciliation already completed 100% of manifest entries, finalize cleanly.
            if ($remainingEntries === [] && count($alreadyCommittedIds) >= $manifestTotal) {
                $auditLog->record('apply_completed', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'deleted_this_run' => 0,
                    'reconciled_after_crash' => $reconciledAfterCrash,
                    'total_deleted' => $cumulativeDeleted,
                    'next_start_id' => $manifestNextStartId,
                ]);

                return [
                    'status' => 'completed',
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'authorized_upper_bound' => $authorizedUpperBound,
                    'manifest_last_scanned_id' => $manifestLastScannedId,
                    'last_deleted_id' => $lastDeletedId,
                    'next_start_id' => $manifestNextStartId,
                    'manifest_is_partial' => (bool) ($manifest['is_partial'] ?? false),
                    'deleted_this_run' => 0,
                    'total_deleted' => $cumulativeDeleted,
                    'remaining_in_manifest' => 0,
                    'reconciled_after_crash' => $reconciledAfterCrash,
                    'deadlock_retries' => 0,
                    'interrupted' => false,
                    'backup_verification' => $backupVerification,
                    'disk_checked_mb' => $checkedDisk,
                    'checkpoint_path' => $checkpointPath,
                    'batch_durations_ms' => [],
                    'total_duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                    'peak_memory_bytes' => memory_get_peak_usage(true),
                ];
            }

            // SAFETY CHECK 3: Verify ZERO reserved jobs among all remaining candidate IDs before deleting anything.
            $this->assertNoReservedCandidates($db, $table, $remainingEntries);

            $auditLog->record('preflight_passed', [
                'incident_id' => $incidentId,
                'manifest_sha256' => $manifestSha,
                'backup_file_sha256' => $backupVerification['file_sha256'],
                'maintenance_window' => $maintenanceWindow,
                'maintenance_lock_file' => $maintenanceLockFile,
                'remaining_candidates' => count($remainingEntries),
                'authorized_upper_bound' => $authorizedUpperBound,
                'manifest_last_scanned_id' => $manifestLastScannedId,
                'disk_checked_mb' => $checkedDisk,
            ]);

            $deletedThisRun = 0;
            $deadlockRetriesTotal = 0;
            $batchesExecuted = 0;
            $batchDurationsMs = [];
            $toProcess = array_slice($remainingEntries, 0, $maxDelete);

            foreach (array_chunk($toProcess, $chunk) as $batchEntries) {
                if ($this->shouldStop($batchesExecuted)) {
                    $this->interrupted = true;
                    break;
                }

                // Re-verify ALL operational safety gates before EVERY micro-batch!
                $this->assertAutomationsSuspended();
                $this->workerDetector->assertMaintenanceGateActive($maintenanceLockFile, $maintenanceWindow, $incidentId);
                $this->workerDetector->assertNoActiveTargetWorkers($targetQueues);
                $this->diskChecker->assertMinimumFreeSpace($diskPaths, $minFreeDiskMb);

                $batchSeq++;
                $batchIds = array_map(static fn (array $e): int => (int) $e['id'], $batchEntries);
                $minId = min($batchIds);
                $maxId = max($batchIds);

                if ($this->beforeIntentHook !== null) {
                    ($this->beforeIntentHook)($batchSeq, $batchIds);
                }

                // Step 1: Write-Ahead Intent BEFORE DB commit with fsync.
                $auditLog->record('batch_intent', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'count' => count($batchIds),
                    'min_id' => $minId,
                    'max_id' => $maxId,
                    'ids' => $batchIds,
                ]);

                if ($this->afterIntentBeforeCommitHook !== null) {
                    ($this->afterIntentBeforeCommitHook)($batchSeq, $batchIds);
                }

                // Step 2 & 3: Revalidate ALL 7 columns under lock, DELETE, COMMIT.
                $batchStart = microtime(true);
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
                $batchDurationMs = round((microtime(true) - $batchStart) * 1000, 2);
                $batchDurationsMs[] = $batchDurationMs;
                $deadlockRetriesTotal += $retriesUsed;

                if ($this->afterCommitBeforeAuditHook !== null) {
                    ($this->afterCommitBeforeAuditHook)($batchSeq, $batchIds);
                }

                $deletedThisRun += count($batchIds);
                $cumulativeDeleted += count($batchIds);
                $lastCommittedId = $maxId;
                $lastDeletedId = $lastDeletedId !== null ? max($lastDeletedId, $maxId) : $maxId;
                $batchesExecuted++;

                foreach ($batchIds as $cid) {
                    $alreadyCommittedIds[$cid] = true;
                }

                $remainingAfterBatch = max(0, $manifestTotal - count($alreadyCommittedIds));
                $isCompletedNow = $remainingAfterBatch === 0;

                // Step 4: Persist `batch_committed` with IDs and fsync.
                $auditLog->record('batch_committed', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'deleted_count' => count($batchIds),
                    'ids' => $batchIds,
                    'min_id' => $minId,
                    'max_id' => $maxId,
                    'duration_ms' => $batchDurationMs,
                    'reconciliation' => [
                        'manifest_total' => $manifestTotal,
                        'backup_verified_total' => $backupVerification['row_count'],
                        'cumulative_deleted' => $cumulativeDeleted,
                        'remaining_in_manifest' => $remainingAfterBatch,
                    ],
                ]);

                if ($this->afterAuditBeforeCheckpointHook !== null) {
                    ($this->afterAuditBeforeCheckpointHook)($batchSeq, $batchIds);
                }

                // Step 5: Persist atomic checkpoint with fsync and record `batch_checkpoint_saved`.
                $checkpoint->saveAtomic([
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'backup_file_sha256' => $backupVerification['file_sha256'],
                    'authorized_upper_bound' => $authorizedUpperBound,
                    'manifest_last_scanned_id' => $manifestLastScannedId,
                    'last_deleted_id' => $lastDeletedId,
                    'last_committed_id' => $lastCommittedId,
                    'next_start_id' => $isCompletedNow ? $manifestNextStartId : null,
                    'last_batch_seq' => $batchSeq,
                    'total_deleted' => $cumulativeDeleted,
                    'manifest_total' => $manifestTotal,
                    'completed' => $isCompletedNow,
                ]);

                $auditLog->record('batch_checkpoint_saved', [
                    'incident_id' => $incidentId,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $batchSeq,
                    'last_deleted_id' => $lastDeletedId,
                    'completed' => $isCompletedNow,
                ]);

                if ($sleepMs > 0 && $remainingAfterBatch > 0 && $deletedThisRun < count($toProcess) && ! $this->shouldStop($batchesExecuted)) {
                    usleep($sleepMs * 1000);
                }
            }

            $remainingFinal = max(0, $manifestTotal - count($alreadyCommittedIds));
            $status = $this->interrupted
                ? 'interrupted'
                : ($remainingFinal === 0 ? 'completed' : 'partial_limit_reached');

            $applyNextStartId = $remainingFinal === 0 ? $manifestNextStartId : null;

            $auditLog->record($this->interrupted ? 'apply_interrupted' : 'apply_completed', [
                'incident_id' => $incidentId,
                'manifest_sha256' => $manifestSha,
                'status' => $status,
                'deleted_this_run' => $deletedThisRun,
                'reconciled_after_crash' => $reconciledAfterCrash,
                'total_deleted' => $cumulativeDeleted,
                'remaining_in_manifest' => $remainingFinal,
                'last_deleted_id' => $lastDeletedId,
                'next_start_id' => $applyNextStartId,
                'deadlock_retries' => $deadlockRetriesTotal,
            ]);

            return [
                'status' => $status,
                'incident_id' => $incidentId,
                'manifest_sha256' => $manifestSha,
                'authorized_upper_bound' => $authorizedUpperBound,
                'manifest_last_scanned_id' => $manifestLastScannedId,
                'last_deleted_id' => $lastDeletedId,
                'next_start_id' => $applyNextStartId,
                'manifest_is_partial' => (bool) ($manifest['is_partial'] ?? false),
                'deleted_this_run' => $deletedThisRun,
                'total_deleted' => $cumulativeDeleted,
                'remaining_in_manifest' => $remainingFinal,
                'reconciled_after_crash' => $reconciledAfterCrash,
                'deadlock_retries' => $deadlockRetriesTotal,
                'interrupted' => $this->interrupted,
                'backup_verification' => $backupVerification,
                'disk_checked_mb' => $checkedDisk,
                'checkpoint_path' => $checkpointPath,
                'batch_durations_ms' => $batchDurationsMs,
                'total_duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ];
        } catch (QueueRecoveryException $e) {
            try {
                $auditLog->record('apply_aborted', [
                    'reason_code' => $e->reasonCode,
                    'message' => $e->getMessage(),
                    'context' => $e->context,
                ]);
            } catch (\Throwable) {
                // Avoid masking the original exception if the audit log itself failed due to I/O/fsync.
            }
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
     * Revalidates ALL 7 columns + row_sha256 + payload_hash + class inside a single locked micro-transaction
     * before executing DELETE.
     *
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
                    "Job #{$id} excede el límite superior autorizado ({$upperBoundId}).",
                    'upper_bound_violation',
                    ['job_id' => $id, 'upper_bound' => $upperBoundId],
                );
            }
            $expectedById[$id] = $entry;
        }

        $batchIds = array_keys($expectedById);
        sort($batchIds);
        $driver = $db->getDriverName();

        $db->transaction(function () use ($db, $table, $batchIds, $expectedById, $allowedJobs, $upperBoundId, $driver): void {
            $query = $db->table($table)
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
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
                $normalized = $this->backupService->normalizeRow($row);

                if ($normalized['reserved_at'] !== null) {
                    throw new QueueRecoveryException(
                        "Trabajo reservado detectado dentro de la transacción para job #{$id}. Abortando y revirtiendo lote.",
                        'reserved_jobs_detected',
                        ['job_id' => $id],
                    );
                }

                if ($normalized['queue'] !== (string) $expected['queue']
                    || $normalized['attempts'] !== (int) $expected['attempts']
                    || $normalized['available_at'] !== (int) $expected['available_at']
                    || $normalized['created_at'] !== (int) $expected['created_at']) {
                    throw new QueueRecoveryException(
                        "Modificación concurrente de metadatos (queue/attempts/available_at/created_at) en job #{$id}. Abortando y revirtiendo lote.",
                        'concurrent_modification',
                        ['job_id' => $id],
                    );
                }

                $actualPayloadHash = hash('sha256', $normalized['payload']);
                $actualRowSha256 = $this->backupService->rowHash($normalized);

                if (! hash_equals((string) $expected['payload_hash'], $actualPayloadHash)
                    || ! hash_equals((string) $expected['row_sha256'], $actualRowSha256)) {
                    throw new QueueRecoveryException(
                        "Modificación concurrente de payload o fila completa (row_sha256) en job #{$id}.",
                        'concurrent_modification',
                        ['job_id' => $id],
                    );
                }

                $reinspected = $this->inspector->inspect($normalized['payload'], $allowedJobs);
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
                'attempts' => $c['attempts'],
                'available_at' => $c['available_at'],
                'meli_account_id' => $accountId,
                'entity_type' => $c['entity_type'],
                'entity_id' => $c['entity_id'],
                'remote_order_id' => $remoteOrderId,
                'meli_item_id' => $c['meli_item_id'],
                'created_at' => $c['created_at'],
                'created_at_iso' => $c['created_at_iso'],
                'reserved_at' => null,
                'payload_hash' => $c['payload_hash'],
                'row_sha256' => $c['row_sha256'],
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

    private function validateMaxScan(int $maxScan): int
    {
        if ($maxScan < 0) {
            throw new QueueRecoveryException(
                "El parámetro --max-scan ({$maxScan}) debe ser mayor o igual a 0 (0 = sin límite de escaneo hasta --end-id).",
                'invalid_max_scan',
                ['max_scan' => $maxScan],
            );
        }

        return $maxScan;
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
