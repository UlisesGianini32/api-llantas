<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * R4.2 Selective Historical Job Quarantine Service (`dryRun`, `apply`, `restore`).
 *
 * Operational & Transactional Guarantees:
 * - Modifies EXCLUSIVELY `jobs.queue` (preserves id, payload byte-for-byte, attempts, reserved_at,
 *   available_at, created_at; zero inserts, zero deletes, zero dispatch(), zero handle(), zero HTTP calls).
 * - Strict allowlist & mandatory queue mappings (`QueueQuarantinePolicy`).
 * - Streaming NDJSON manifest & bounded memory (< 128 MiB at 100k+ jobs; keyset pagination, zero OFFSET).
 * - Full pre-batch safety gates (confirmation, manifest SHA-256, optional HMAC, InnoDB check,
 *   automations disabled, stopped workers, operator maintenance-window sentinel, exclusive lock shared
 *   with `QueuePruneService`).
 * - Short micro-transactions (`SELECT ... FOR UPDATE`), deadlock retry, durable write-ahead `batch_intent`
 *   + `batch_committed` audit journal, atomic checkpoint, and deterministic 5-state crash reconciliation
 *   (`not_moved`, `moved_correctly`, `modified_by_other_process`, `missing`, `ambiguous`).
 */
class QueueQuarantineService
{
    public const DEFAULT_CHUNK_SIZE = 500;

    public const MAX_CHUNK_SIZE = 1000;

    public const DEFAULT_SLEEP_MS = 200;

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
        private readonly QueuePayloadInspector $inspector = new QueuePayloadInspector,
        private readonly QueueQuarantinePolicy $policy = new QueueQuarantinePolicy,
        private readonly QueueQuarantineManifest $manifestManager = new QueueQuarantineManifest,
        private readonly QueueRecoveryBackupService $backupService = new QueueRecoveryBackupService,
        private readonly QueueWorkerDetector $workerDetector = new QueueWorkerDetector,
        private readonly QueueRecoveryLock $lock = new QueueRecoveryLock,
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
        private readonly QueueDiagnosticArtifactWriter $artifactWriter = new QueueDiagnosticArtifactWriter,
    ) {}

    public function requestInterrupt(): void
    {
        $this->interrupted = true;
    }

    /** @param (callable(int, list<int>): void)|null $hook */
    public function setBeforeIntentHook(?callable $hook): void
    {
        $this->beforeIntentHook = $hook;
    }

    /** @param (callable(int, list<int>): void)|null $hook */
    public function setAfterIntentBeforeCommitHook(?callable $hook): void
    {
        $this->afterIntentBeforeCommitHook = $hook;
    }

    /** @param (callable(int, list<int>): void)|null $hook */
    public function setAfterCommitBeforeAuditHook(?callable $hook): void
    {
        $this->afterCommitBeforeAuditHook = $hook;
    }

    /** @param (callable(int, list<int>): void)|null $hook */
    public function setAfterAuditBeforeCheckpointHook(?callable $hook): void
    {
        $this->afterAuditBeforeCheckpointHook = $hook;
    }

    /** @param (callable(int): bool)|null $checker */
    public function setShouldInterruptChecker(?callable $checker): void
    {
        $this->shouldInterruptChecker = $checker;
    }

    /**
     * 100% READ-ONLY keyset planning pass generating a verified streaming NDJSON quarantine manifest.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dryRun(array $options): array
    {
        $startedAt = microtime(true);
        $intervalStartedIso = now()->utc()->toIso8601String();
        $this->interrupted = false;

        $mapping = $this->policy->resolveAuthorizedMapping(
            (string) ($options['job'] ?? ''),
            isset($options['source_queue']) ? (string) $options['source_queue'] : null,
            isset($options['target_queue']) ? (string) $options['target_queue'] : null,
        );

        $chunk = $this->validateChunk((int) ($options['chunk'] ?? self::DEFAULT_CHUNK_SIZE));
        $startId = max(0, (int) ($options['start_id'] ?? 0));
        $maxScan = max(0, (int) ($options['max_scan'] ?? 0));
        $maxMove = max(0, (int) ($options['max_move'] ?? 0));
        $maxRuntime = max(0, (int) ($options['max_runtime'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? 0));
        $hmacKey = isset($options['hmac_key']) && trim((string) $options['hmac_key']) !== ''
            ? trim((string) $options['hmac_key'])
            : null;

        $incidentId = trim((string) ($options['incident_id'] ?? ''));
        if ($incidentId === '') {
            $incidentId = 'INC-QUARANTINE-R4-2-'.now()->utc()->format('Ymd-His');
        }

        $manifestPath = isset($options['manifest_path']) && trim((string) $options['manifest_path']) !== ''
            ? trim((string) $options['manifest_path'])
            : null;
        $auditLogPath = isset($options['audit_log_path']) && trim((string) $options['audit_log_path']) !== ''
            ? trim((string) $options['audit_log_path'])
            : null;
        $completionMarkerPath = isset($options['completion_marker_path']) && trim((string) $options['completion_marker_path']) !== ''
            ? trim((string) $options['completion_marker_path'])
            : null;

        $this->artifactWriter->assertNoPathCollisions([
            'manifest' => $manifestPath,
            'audit_log' => $auditLogPath,
            'completion_marker' => $completionMarkerPath,
        ]);

        $target = $this->policy->resolveAndValidateQueueTarget(
            isset($options['queue_connection']) ? (string) $options['queue_connection'] : null,
            requireInnoDb: false,
        );
        $db = DB::connection($target['db_connection']);
        $table = $target['table'];

        $currentMaxId = (int) ($db->table($table)->max('id') ?? 0);
        $authorizedEndId = isset($options['end_id']) && $options['end_id'] !== null && $options['end_id'] !== ''
            ? (int) $options['end_id']
            : $currentMaxId;
        $historicalUpperBound = isset($options['historical_upper_bound']) && $options['historical_upper_bound'] !== null && $options['historical_upper_bound'] !== ''
            ? (int) $options['historical_upper_bound']
            : $authorizedEndId;

        if ($authorizedEndId < 0 || $historicalUpperBound < 0) {
            throw new QueueRecoveryException('Los límites de ID no pueden ser negativos.', 'invalid_bounds');
        }
        if ($authorizedEndId > 0 && $startId > $authorizedEndId) {
            throw new QueueRecoveryException(
                "Rango inválido: --start-id ({$startId}) es mayor que --end-id ({$authorizedEndId}).",
                'invalid_bounds',
            );
        }
        if ($historicalUpperBound > 0 && $authorizedEndId > $historicalUpperBound) {
            throw new QueueRecoveryException(
                "El límite --end-id ({$authorizedEndId}) excede el límite histórico autorizado ({$historicalUpperBound}).",
                'upper_bound_violation',
            );
        }

        $headerFields = [
            'incident_id' => $incidentId,
            'generated_at' => $intervalStartedIso,
            'mode' => 'quarantine_dry_run',
            'queue_connection' => $target['queue_connection'],
            'connection' => $target['db_connection'],
            'database' => $target['database'],
            'table' => $table,
            'table_engine' => $target['engine'],
            'job_class' => $mapping['job_class'],
            'source_queue' => $mapping['source_queue'],
            'target_queue' => $mapping['target_queue'],
            'historical_upper_bound' => $historicalUpperBound,
            'start_id' => $startId,
            'authorized_end_id' => $authorizedEndId,
            'chunk_size' => $chunk,
            'max_scan' => $maxScan,
            'max_move' => $maxMove,
            'max_runtime_seconds' => $maxRuntime,
            'snapshot_semantics' => 'chunked_interval_reads_revalidated_on_apply',
        ];

        $writerState = $manifestPath !== null
            ? $this->manifestManager->openWriter($manifestPath, $headerFields)
            : null;

        $totals = [
            'scanned' => 0,
            'candidates' => 0,
            'previously_attempted_candidates' => 0,
            'excluded_reserved' => 0,
            'excluded_invalid_json' => 0,
            'excluded_inconsistent_payload' => 0,
            'excluded_other_modules' => 0,
            'excluded_wrong_queue' => 0,
            'excluded_out_of_bounds' => 0,
        ];

        $previouslyAttemptedSampleIds = [];
        $cursor = $startId;
        $highestScannedId = $startId;
        $lastCandidateId = null;
        $moveLimitReached = false;
        $scanLimitReached = false;
        $runtimeLimitReached = false;
        $interruptedBySignal = false;
        $scanExhausted = $cursor >= $authorizedEndId;

        try {
            while ($cursor < $authorizedEndId && ! $moveLimitReached && ! $scanLimitReached && ! $runtimeLimitReached) {
                if ($this->shouldStop(0)) {
                    $interruptedBySignal = true;
                    break;
                }
                if ($maxRuntime > 0 && (microtime(true) - $startedAt) >= $maxRuntime) {
                    $runtimeLimitReached = true;
                    break;
                }

                $rows = $db->table($table)
                    ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                    ->where('id', '>', $cursor)
                    ->where('id', '<=', $authorizedEndId)
                    ->orderBy('id')
                    ->limit($chunk)
                    ->get();

                if ($rows->isEmpty()) {
                    $scanExhausted = true;
                    break;
                }

                $fetchedCount = $rows->count();

                foreach ($rows as $row) {
                    $jobId = (int) $row->id;
                    $cursor = $jobId;
                    $highestScannedId = max($highestScannedId, $jobId);
                    $totals['scanned']++;

                    if ($jobId > $authorizedEndId) {
                        $totals['excluded_out_of_bounds']++;
                    } elseif ((string) $row->queue !== $mapping['source_queue']) {
                        $totals['excluded_wrong_queue']++;
                    } elseif ($row->reserved_at !== null) {
                        $totals['excluded_reserved']++;
                    } else {
                        $inspection = $this->inspector->inspect((string) $row->payload, [$mapping['job_class']]);
                        $cls = $inspection['classification'];

                        if ($cls === QueuePayloadInspector::CLASSIFICATION_INVALID_JSON) {
                            $totals['excluded_invalid_json']++;
                        } elseif ($cls === QueuePayloadInspector::CLASSIFICATION_INCONSISTENT) {
                            $totals['excluded_inconsistent_payload']++;
                        } elseif ($cls === QueuePayloadInspector::CLASSIFICATION_OTHER_MODULE) {
                            $totals['excluded_other_modules']++;
                        } else {
                            $normalized = $this->backupService->normalizeRow($row);
                            $rowSha = $this->backupService->rowHash($normalized);
                            $previouslyAttempted = $normalized['attempts'] > 0;

                            if ($previouslyAttempted) {
                                $totals['previously_attempted_candidates']++;
                                if (count($previouslyAttemptedSampleIds) < 50) {
                                    $previouslyAttemptedSampleIds[] = $jobId;
                                }
                            }

                            $candidate = [
                                'id' => $jobId,
                                'queue' => $normalized['queue'],
                                'job_class' => $mapping['job_class'],
                                'entity_type' => (string) $inspection['entity_type'],
                                'entity_id' => (int) $inspection['entity_id'],
                                'meli_item_id' => $inspection['meli_item_id'],
                                'attempts' => $normalized['attempts'],
                                'previously_attempted' => $previouslyAttempted,
                                'reserved_at' => null,
                                'available_at' => $normalized['available_at'],
                                'created_at' => $normalized['created_at'],
                                'payload_hash' => (string) $inspection['payload_hash'],
                                'row_sha256' => $rowSha,
                            ];

                            if ($writerState !== null) {
                                $this->manifestManager->appendCandidate($writerState, $candidate);
                            }

                            $totals['candidates']++;
                            $lastCandidateId = $jobId;

                            if ($maxMove > 0 && $totals['candidates'] >= $maxMove) {
                                $moveLimitReached = true;
                                break;
                            }
                        }
                    }

                    if ($maxScan > 0 && $totals['scanned'] >= $maxScan) {
                        $scanLimitReached = true;
                        break;
                    }
                }

                if (! $moveLimitReached && ! $scanLimitReached && ($fetchedCount < $chunk || $cursor >= $authorizedEndId)) {
                    $scanExhausted = true;
                    break;
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            if ($cursor >= $authorizedEndId) {
                $scanExhausted = true;
            }

            $isPartial = ! $scanExhausted && ($moveLimitReached || $scanLimitReached || $runtimeLimitReached || $interruptedBySignal);
            $lastScannedId = $isPartial ? $highestScannedId : $authorizedEndId;
            $nextStartId = $lastScannedId;

            $stopReason = match (true) {
                $interruptedBySignal => 'interrupted_by_signal',
                ! $isPartial => 'completed_upper_bound',
                $moveLimitReached => 'max_move_reached',
                $scanLimitReached => 'max_scan_reached',
                $runtimeLimitReached => 'max_runtime_reached',
                default => 'completed_upper_bound',
            };

            $footerFields = [
                'completed_at' => now()->utc()->toIso8601String(),
                'is_partial' => $isPartial,
                'stop_reason' => $stopReason,
                'last_scanned_id' => $lastScannedId,
                'last_candidate_id' => $lastCandidateId,
                'next_start_id' => $nextStartId,
                'previously_attempted_sample_ids' => $previouslyAttemptedSampleIds,
                'totals' => $totals,
            ];

            $publishedManifest = null;
            if ($writerState !== null) {
                $publishedManifest = $this->manifestManager->finalizeAndPublish($writerState, $footerFields, $hmacKey);
            }
        } catch (Throwable $e) {
            $this->manifestManager->abortWriter($writerState);
            throw $e;
        }

        if ($auditLogPath !== null) {
            $audit = new QueueRecoveryAuditLog($auditLogPath, $this->pathGuard);
            $audit->record('quarantine_dry_run_completed', [
                'incident_id' => $incidentId,
                'job_class' => $mapping['job_class'],
                'source_queue' => $mapping['source_queue'],
                'target_queue' => $mapping['target_queue'],
                'manifest_path' => $publishedManifest['path'] ?? null,
                'manifest_sha256' => $publishedManifest['manifest_sha256'] ?? null,
                'start_id' => $startId,
                'authorized_end_id' => $authorizedEndId,
                'last_scanned_id' => $lastScannedId,
                'last_candidate_id' => $lastCandidateId,
                'next_start_id' => $nextStartId,
                'is_partial' => $isPartial,
                'stop_reason' => $stopReason,
                'totals' => $totals,
            ]);
        }

        $markerMeta = null;
        if ($completionMarkerPath !== null && $publishedManifest !== null) {
            $markerJson = json_encode([
                'status' => 'complete',
                'operation' => 'quarantine_dry_run',
                'incident_id' => $incidentId,
                'completed_at' => now()->utc()->toIso8601String(),
                'manifest_path' => $publishedManifest['path'],
                'manifest_sha256' => $publishedManifest['manifest_sha256'],
                'file_sha256' => $publishedManifest['file_sha256'],
                'candidates_count' => $publishedManifest['candidates_count'],
                'next_start_id' => $nextStartId,
                'is_partial' => $isPartial,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $markerMeta = $this->artifactWriter->writeAndPublishString($completionMarkerPath, $markerJson, disallowOverwrite: true);
        }

        return [
            'mode' => 'dry_run',
            'incident_id' => $incidentId,
            'format_version' => QueueQuarantineManifest::FORMAT_VERSION,
            'target' => $target,
            'mapping' => $mapping,
            'start_id' => $startId,
            'authorized_end_id' => $authorizedEndId,
            'historical_upper_bound' => $historicalUpperBound,
            'last_scanned_id' => $lastScannedId,
            'last_candidate_id' => $lastCandidateId,
            'next_start_id' => $nextStartId,
            'is_partial' => $isPartial,
            'stop_reason' => $stopReason,
            'previously_attempted_sample_ids' => $previouslyAttemptedSampleIds,
            'totals' => $totals,
            'manifest' => $publishedManifest,
            'completion_marker' => $markerMeta,
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
    }

    /**
     * Explicitly authorized quarantine execution: modifies EXCLUSIVELY `jobs.queue` from
     * `$sourceQueue` to `$targetQueue` in micro-transactional `SELECT ... FOR UPDATE` batches
     * strictly for verified candidates in the operator-approved manifest.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function apply(array $options): array
    {
        return $this->executeQueueTransition('quarantine_apply', $options);
    }

    /**
     * Explicitly authorized selective restoration: moves ONLY jobs previously confirmed as moved
     * in the durable audit log (`quarantine_apply` `batch_committed`) back from `$targetQueue` to
     * `$sourceQueue`, preserving ID, payload, attempts, and timestamps verbatim.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function restore(array $options): array
    {
        return $this->executeQueueTransition('quarantine_restore', $options);
    }

    /**
     * Shared hardened micro-transactional engine for both `apply` and `restore`.
     *
     * @param  'quarantine_apply'|'quarantine_restore'  $operation
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function executeQueueTransition(string $operation, array $options): array
    {
        $startedAt = microtime(true);
        $this->interrupted = false;
        $isRestore = $operation === 'quarantine_restore';

        $manifestPath = trim((string) ($options['manifest_path'] ?? ''));
        if ($manifestPath === '') {
            throw new QueueRecoveryException('Debe especificar --manifest=<ruta> verificado.', 'manifest_missing');
        }

        $expectedSha = trim((string) ($options['expected_manifest_sha256'] ?? ''));
        $hmacKey = isset($options['hmac_key']) && trim((string) $options['hmac_key']) !== ''
            ? trim((string) $options['hmac_key'])
            : null;

        $verified = $this->manifestManager->verify(
            $manifestPath,
            $expectedSha,
            $hmacKey,
            requireExpectedSha256: true,
        );

        $header = $verified['header'];
        $manifestSha = (string) $verified['manifest_sha256'];
        $incidentId = (string) $header['incident_id'];
        $mapping = $this->policy->resolveAuthorizedMapping(
            (string) $header['job_class'],
            (string) $header['source_queue'],
            (string) $header['target_queue'],
        );

        // If operator explicitly passed --job / --source-queue / --target-queue, assert exact match!
        if (! empty($options['job'])) {
            $reqMap = $this->policy->resolveAuthorizedMapping(
                (string) $options['job'],
                isset($options['source_queue']) ? (string) $options['source_queue'] : null,
                isset($options['target_queue']) ? (string) $options['target_queue'] : null,
            );
            if ($reqMap !== $mapping) {
                throw new QueueRecoveryException(
                    'Los parámetros --job/--source-queue/--target-queue no coinciden con el manifiesto aprobado.',
                    'manifest_parameter_mismatch',
                );
            }
        }

        // Mandatory incident-bound confirmation
        $confirm = trim((string) ($options['confirm'] ?? ''));
        if ($confirm === '' || $confirm !== $incidentId) {
            throw new QueueRecoveryException(
                "Confirmación operativa obligatoria inválida: proporcione --confirm={$incidentId}.",
                'confirmation_mismatch',
                ['expected_incident_id' => $incidentId],
            );
        }

        // Mandatory audit log path
        $auditLogPath = trim((string) ($options['audit_log_path'] ?? ''));
        if ($auditLogPath === '') {
            throw new QueueRecoveryException(
                'Debe especificar --audit-log=<ruta> durable para registrar intenciones y confirmaciones por lote.',
                'audit_log_required',
            );
        }

        // Mandatory operator containment window sentinel (GET_LOCK alone does not prove no external consumers!)
        $maintenanceWindow = trim((string) ($options['maintenance_window'] ?? ''));
        $maintenanceLockFile = trim((string) ($options['maintenance_lock_file'] ?? ''));
        if ($maintenanceWindow === '' || $maintenanceLockFile === '') {
            throw new QueueRecoveryException(
                'Se requiere ventana de contención operativa confirmada (--maintenance-window y --maintenance-lock-file) antes de modificar jobs.queue.',
                'maintenance_gate_missing',
            );
        }

        $chunk = $this->validateChunk((int) ($options['chunk'] ?? self::DEFAULT_CHUNK_SIZE));
        $maxMove = max(0, (int) ($options['max_move'] ?? 0));
        $maxRuntime = max(0, (int) ($options['max_runtime'] ?? 0));
        $sleepMs = max(0, (int) ($options['sleep_ms'] ?? self::DEFAULT_SLEEP_MS));
        $resumeEnabled = (bool) ($options['resume'] ?? false);
        $checkpointPath = isset($options['checkpoint_path']) && trim((string) $options['checkpoint_path']) !== ''
            ? trim((string) $options['checkpoint_path'])
            : $manifestPath.'.'.($isRestore ? 'restore' : 'apply').'.checkpoint.json';
        $minRestoreId = isset($options['start_id']) && $options['start_id'] !== null && $options['start_id'] !== ''
            ? max(0, (int) $options['start_id'])
            : null;
        $maxRestoreId = isset($options['end_id']) && $options['end_id'] !== null && $options['end_id'] !== ''
            ? max(0, (int) $options['end_id'])
            : null;

        $this->artifactWriter->assertNoPathCollisions([
            'manifest' => $manifestPath,
            'audit_log' => $auditLogPath,
            'checkpoint' => $checkpointPath,
            'maintenance_lock' => $maintenanceLockFile,
        ]);

        // Validate queue target, InnoDB engine, automations OFF, active workers OFF, and maintenance sentinel!
        $target = $this->policy->resolveAndValidateQueueTarget(
            isset($options['queue_connection']) ? (string) $options['queue_connection'] : null,
            requireInnoDb: true,
        );
        if ((string) $header['database'] !== $target['database'] || (string) $header['table'] !== $target['table']) {
            throw new QueueRecoveryException(
                sprintf(
                    "El manifiesto pertenece a '%s.%s', pero la conexión apunta a '%s.%s'.",
                    (string) $header['database'],
                    (string) $header['table'],
                    $target['database'],
                    $target['table'],
                ),
                'database_target_mismatch',
            );
        }

        $this->assertAllRuntimeSafetyGates(
            $maintenanceLockFile,
            $maintenanceWindow,
            $incidentId,
            [$mapping['source_queue'], $mapping['target_queue']],
        );

        $db = DB::connection($target['db_connection']);
        $table = $target['table'];

        // Share the EXACT same lock scope key as QueuePruneService so prune/apply/restore NEVER overlap!
        $lockScope = implode('|', [$target['db_connection'], $target['database'], $table]);
        $this->lock->acquire($db, $lockScope);

        $auditLog = new QueueRecoveryAuditLog($auditLogPath, $this->pathGuard);
        $checkpoint = new QueueRecoveryCheckpoint($checkpointPath, $this->pathGuard);

        $fromQueue = $isRestore ? $mapping['target_queue'] : $mapping['source_queue'];
        $toQueue = $isRestore ? $mapping['source_queue'] : $mapping['target_queue'];

        try {
            // For restore: load ONLY IDs confirmed as moved by `quarantine_apply` in the audit log!
            $applyMovedIds = null;
            if ($isRestore) {
                $applyJournal = $this->inspectOperationJournal($auditLogPath, $manifestSha, 'quarantine_apply', collectCommittedIds: true);
                if ($applyJournal['unresolved_intents'] !== []) {
                    throw new QueueRecoveryException(
                        'El audit log contiene lotes de cuarentena (apply) sin confirmar; resuelva/reanude apply antes de ejecutar --restore.',
                        'unresolved_apply_intent_blocks_restore',
                    );
                }
                $applyMovedIds = [];
                foreach ($applyJournal['committed_batches'] as $cb) {
                    foreach ($cb['ids'] as $mid) {
                        $applyMovedIds[(int) $mid] = true;
                    }
                }
                if ($applyMovedIds === []) {
                    throw new QueueRecoveryException(
                        'El audit log no registra ningún job trasladado confirmado para este manifiesto; nada que restaurar.',
                        'restore_no_moved_records',
                    );
                }
            }

            // Inspect journal & checkpoint for THIS operation (`quarantine_apply` or `quarantine_restore`).
            $journal = $this->inspectOperationJournal($auditLogPath, $manifestSha, $operation, collectCommittedIds: $isRestore);
            $savedCheckpoint = $checkpoint->load($manifestSha);

            $reconciledIntents = [];
            if ($journal['unresolved_intents'] !== []) {
                if (! $resumeEnabled) {
                    throw new QueueRecoveryException(
                        sprintf(
                            'Se detectaron %d lote(s) con intención registrada sin confirmación final en el audit log. Ejecute con --resume para inspeccionar y reconciliar de forma segura.',
                            count($journal['unresolved_intents']),
                        ),
                        'unresolved_batch_intent_requires_resume',
                        ['unresolved_count' => count($journal['unresolved_intents'])],
                    );
                }

                $reconciledIntents = $this->reconcileUnresolvedIntents(
                    $db,
                    $table,
                    $verified,
                    $journal['unresolved_intents'],
                    $mapping,
                    $fromQueue,
                    $toQueue,
                    $operation,
                    $auditLog,
                );

                // Refresh journal after deterministic reconciliation.
                $journal = $this->inspectOperationJournal($auditLogPath, $manifestSha, $operation, collectCommittedIds: $isRestore);
            }

            $movedIdsFilter = $applyMovedIds;
            if ($isRestore && is_array($movedIdsFilter)) {
                foreach ($journal['committed_batches'] as $cb) {
                    foreach ($cb['ids'] as $restoredId) {
                        unset($movedIdsFilter[(int) $restoredId]);
                    }
                }
            }

            $maxCommittedId = (int) $journal['max_committed_id'];
            $checkpointLastId = is_array($savedCheckpoint) ? (int) ($savedCheckpoint['last_processed_id'] ?? 0) : 0;
            $startIdExclusive = $minRestoreId ?? 0;
            $resumeAfterId = $isRestore
                ? $startIdExclusive
                : max($startIdExclusive, $checkpointLastId, $maxCommittedId);
            $batchSeq = (int) $journal['last_batch_seq'];

            $totals = [
                'batches_executed' => 0,
                'candidates_inspected' => 0,
                'moved' => 0,
                'previously_attempted_moved' => 0,
                'skipped_missing' => 0,
                'skipped_already_in_target' => 0,
                'skipped_reserved' => 0,
                'skipped_modified' => 0,
                'skipped_inconsistent' => 0,
                'deadlock_retries' => 0,
            ];

            $auditLog->record($operation.'_started', [
                'incident_id' => $incidentId,
                'operation' => $operation,
                'manifest_sha256' => $manifestSha,
                'job_class' => $mapping['job_class'],
                'from_queue' => $fromQueue,
                'to_queue' => $toQueue,
                'resume_enabled' => $resumeEnabled,
                'resume_after_id' => $resumeAfterId,
                'maintenance_window' => $maintenanceWindow,
            ]);

            $stoppedEarly = false;
            $stopReason = 'completed';
            $lastProcessedId = $isRestore ? max($checkpointLastId, $maxCommittedId) : $resumeAfterId;

            if (! $isRestore || ($movedIdsFilter !== null && $movedIdsFilter !== [])) {
                foreach ($this->manifestManager->streamCandidateBatches(
                    $verified,
                    $chunk,
                    afterId: $resumeAfterId,
                    minId: null,
                    maxId: $maxRestoreId,
                    onlyIds: $movedIdsFilter,
                ) as $batchCandidates) {
                    $pendingFromBatch = $batchCandidates;

                    while ($pendingFromBatch !== []) {
                        if ($this->shouldStop($batchSeq + 1)) {
                            $stoppedEarly = true;
                            $stopReason = 'interrupted_by_signal';
                            break 2;
                        }
                        if ($maxRuntime > 0 && (microtime(true) - $startedAt) >= $maxRuntime) {
                            $stoppedEarly = true;
                            $stopReason = 'max_runtime_reached';
                            break 2;
                        }
                        if ($maxMove > 0 && $totals['moved'] >= $maxMove) {
                            $stoppedEarly = true;
                            $stopReason = 'max_move_reached';
                            break 2;
                        }

                        $take = $maxMove > 0
                            ? min(count($pendingFromBatch), $maxMove - $totals['moved'])
                            : count($pendingFromBatch);
                        $currentSlice = array_slice($pendingFromBatch, 0, $take);
                        $pendingFromBatch = array_slice($pendingFromBatch, $take);

                        // Re-check ALL runtime safety gates BEFORE EVERY micro-batch!
                        $this->assertAllRuntimeSafetyGates(
                            $maintenanceLockFile,
                            $maintenanceWindow,
                            $incidentId,
                            [$mapping['source_queue'], $mapping['target_queue']],
                        );

                        $batchSeq++;
                        $batchOutcome = $this->executeMicroBatch(
                            $db,
                            $table,
                            $currentSlice,
                            $mapping,
                            $fromQueue,
                            $toQueue,
                            $isRestore,
                            $operation,
                            $manifestSha,
                            $incidentId,
                            $batchSeq,
                            $auditLog,
                        );

                        $totals['batches_executed']++;
                        $totals['candidates_inspected'] += count($currentSlice);
                        $totals['moved'] += $batchOutcome['moved_count'];
                        $totals['previously_attempted_moved'] += $batchOutcome['previously_attempted_moved'];
                        $totals['skipped_missing'] += $batchOutcome['skipped_missing'];
                        $totals['skipped_already_in_target'] += $batchOutcome['skipped_already_in_target'];
                        $totals['skipped_reserved'] += $batchOutcome['skipped_reserved'];
                        $totals['skipped_modified'] += $batchOutcome['skipped_modified'];
                        $totals['skipped_inconsistent'] += $batchOutcome['skipped_inconsistent'];
                        $totals['deadlock_retries'] += $batchOutcome['deadlock_retries'];

                        $lastProcessedId = max($lastProcessedId, (int) end($currentSlice)['id']);

                        if ($batchOutcome['eligible_ids'] !== []) {
                            if ($this->afterCommitBeforeAuditHook !== null) {
                                ($this->afterCommitBeforeAuditHook)($batchSeq, $batchOutcome['eligible_ids']);
                            }

                            $auditLog->record('batch_committed', [
                                'incident_id' => $incidentId,
                                'operation' => $operation,
                                'manifest_sha256' => $manifestSha,
                                'batch_seq' => $batchSeq,
                                'ids' => $batchOutcome['eligible_ids'],
                                'moved_count' => $batchOutcome['moved_count'],
                                'min_id' => min($batchOutcome['eligible_ids']),
                                'max_id' => max($batchOutcome['eligible_ids']),
                                'from_queue' => $fromQueue,
                                'to_queue' => $toQueue,
                            ]);

                            if ($this->afterAuditBeforeCheckpointHook !== null) {
                                ($this->afterAuditBeforeCheckpointHook)($batchSeq, $batchOutcome['eligible_ids']);
                            }
                        }

                        $checkpoint->saveAtomic([
                            'operation' => $operation,
                            'incident_id' => $incidentId,
                            'manifest_sha256' => $manifestSha,
                            'last_processed_id' => $lastProcessedId,
                            'last_batch_seq' => $batchSeq,
                            'from_queue' => $fromQueue,
                            'to_queue' => $toQueue,
                            'totals' => $totals,
                        ]);

                        if ($batchOutcome['eligible_ids'] !== []) {
                            $auditLog->record('batch_checkpoint_saved', [
                                'incident_id' => $incidentId,
                                'operation' => $operation,
                                'manifest_sha256' => $manifestSha,
                                'batch_seq' => $batchSeq,
                                'last_processed_id' => $lastProcessedId,
                            ]);
                        }

                        if ($sleepMs > 0) {
                            usleep($sleepMs * 1000);
                        }
                    }
                }
            }

            $auditLog->record($operation.'_finished', [
                'incident_id' => $incidentId,
                'operation' => $operation,
                'manifest_sha256' => $manifestSha,
                'stopped_early' => $stoppedEarly,
                'stop_reason' => $stopReason,
                'last_processed_id' => $lastProcessedId,
                'totals' => $totals,
            ]);

            return [
                'mode' => $isRestore ? 'restore' : 'apply',
                'operation' => $operation,
                'incident_id' => $incidentId,
                'manifest_sha256' => $manifestSha,
                'mapping' => $mapping,
                'from_queue' => $fromQueue,
                'to_queue' => $toQueue,
                'resume_after_id' => $resumeAfterId,
                'last_processed_id' => $lastProcessedId,
                'stopped_early' => $stoppedEarly,
                'stop_reason' => $stopReason,
                'reconciled_intents' => $reconciledIntents,
                'totals' => $totals,
                'checkpoint_path' => $checkpointPath,
                'audit_log_path' => $auditLogPath,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
            ];
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Executes one short micro-transaction (`SELECT ... FOR UPDATE`, full 7-column revalidation,
     * durable `batch_intent`, `UPDATE jobs SET queue = ?` ONLY, affected-row verification, commit)
     * with bounded deadlock retry.
     *
     * @param  list<array<string, mixed>>  $batchCandidates
     * @param  array{job_class: string, source_queue: string, target_queue: string}  $mapping
     * @return array{
     *     eligible_ids: list<int>,
     *     moved_count: int,
     *     previously_attempted_moved: int,
     *     skipped_missing: int,
     *     skipped_already_in_target: int,
     *     skipped_reserved: int,
     *     skipped_modified: int,
     *     skipped_inconsistent: int,
     *     deadlock_retries: int
     * }
     */
    private function executeMicroBatch(
        ConnectionInterface $db,
        string $table,
        array $batchCandidates,
        array $mapping,
        string $fromQueue,
        string $toQueue,
        bool $isRestore,
        string $operation,
        string $manifestSha,
        string $incidentId,
        int $batchSeq,
        QueueRecoveryAuditLog $auditLog,
    ): array {
        $candidateById = [];
        foreach ($batchCandidates as $c) {
            $candidateById[(int) $c['id']] = $c;
        }
        $candidateIds = array_keys($candidateById);

        $attempt = 0;
        $deadlockRetries = 0;

        while (true) {
            $attempt++;

            try {
                $outcome = $db->transaction(function () use (
                    $db,
                    $table,
                    $candidateIds,
                    $candidateById,
                    $mapping,
                    $fromQueue,
                    $toQueue,
                    $isRestore,
                    $operation,
                    $manifestSha,
                    $incidentId,
                    $batchSeq,
                    $auditLog,
                ): array {
                    $lockedRows = $db->table($table)
                        ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                        ->whereIn('id', $candidateIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    $eligibleIds = [];
                    $previouslyAttemptedMoved = 0;
                    $skippedMissing = 0;
                    $skippedAlreadyInTarget = 0;
                    $skippedReserved = 0;
                    $skippedModified = 0;
                    $skippedInconsistent = 0;

                    foreach ($candidateIds as $jid) {
                        $row = $lockedRows->get($jid);
                        if ($row === null) {
                            $skippedMissing++;

                            continue;
                        }

                        $expected = $candidateById[$jid];
                        $normalized = $this->backupService->normalizeRow($row);

                        if ($normalized['reserved_at'] !== null) {
                            $skippedReserved++;

                            continue;
                        }

                        // Compute row hash as if queue were original `source_queue` so we verify ALL 6 other columns!
                        $normalizedAsOriginal = [...$normalized, 'queue' => $mapping['source_queue']];
                        $payloadHash = hash('sha256', $normalized['payload']);
                        $originalRowSha = $this->backupService->rowHash($normalizedAsOriginal);

                        if ($normalized['queue'] === $toQueue) {
                            if (hash_equals((string) $expected['payload_hash'], $payloadHash)
                                && hash_equals((string) $expected['row_sha256'], $originalRowSha)) {
                                $skippedAlreadyInTarget++;
                            } else {
                                $skippedModified++;
                            }

                            continue;
                        }

                        if ($normalized['queue'] !== $fromQueue) {
                            $skippedModified++;

                            continue;
                        }

                        if (! hash_equals((string) $expected['payload_hash'], $payloadHash)
                            || ! hash_equals((string) $expected['row_sha256'], $originalRowSha)
                            || $normalized['attempts'] !== (int) $expected['attempts']
                            || $normalized['available_at'] !== (int) $expected['available_at']
                            || $normalized['created_at'] !== (int) $expected['created_at']) {
                            $skippedModified++;

                            continue;
                        }

                        $inspection = $this->inspector->inspect($normalized['payload'], [$mapping['job_class']]);
                        if ($inspection['classification'] !== QueuePayloadInspector::CLASSIFICATION_CANDIDATE
                            || $inspection['job_class'] !== $mapping['job_class']) {
                            $skippedInconsistent++;

                            continue;
                        }

                        $eligibleIds[] = $jid;
                        if ($normalized['attempts'] > 0) {
                            $previouslyAttemptedMoved++;
                        }
                    }

                    $movedCount = 0;
                    if ($eligibleIds !== []) {
                        if ($this->beforeIntentHook !== null) {
                            ($this->beforeIntentHook)($batchSeq, $eligibleIds);
                        }

                        $auditLog->record('batch_intent', [
                            'incident_id' => $incidentId,
                            'operation' => $operation,
                            'manifest_sha256' => $manifestSha,
                            'batch_seq' => $batchSeq,
                            'ids' => $eligibleIds,
                            'min_id' => min($eligibleIds),
                            'max_id' => max($eligibleIds),
                            'from_queue' => $fromQueue,
                            'to_queue' => $toQueue,
                        ]);

                        if ($this->afterIntentBeforeCommitHook !== null) {
                            ($this->afterIntentBeforeCommitHook)($batchSeq, $eligibleIds);
                        }

                        // Strictly updates ONLY `jobs.queue` — preserves every other column verbatim!
                        $affected = $db->table($table)
                            ->whereIn('id', $eligibleIds)
                            ->where('queue', $fromQueue)
                            ->whereNull('reserved_at')
                            ->update(['queue' => $toQueue]);

                        if ($affected !== count($eligibleIds)) {
                            throw new QueueRecoveryException(
                                sprintf(
                                    'Discrepancia al actualizar lote #%d (%s): esperadas %d filas, actualizadas %d. Revirtiendo transacción.',
                                    $batchSeq,
                                    $isRestore ? 'restore' : 'apply',
                                    count($eligibleIds),
                                    $affected,
                                ),
                                'batch_update_count_mismatch',
                                ['batch_seq' => $batchSeq, 'expected' => count($eligibleIds), 'actual' => $affected],
                            );
                        }

                        $movedCount = $affected;
                    }

                    return [
                        'eligible_ids' => $eligibleIds,
                        'moved_count' => $movedCount,
                        'previously_attempted_moved' => $previouslyAttemptedMoved,
                        'skipped_missing' => $skippedMissing,
                        'skipped_already_in_target' => $skippedAlreadyInTarget,
                        'skipped_reserved' => $skippedReserved,
                        'skipped_modified' => $skippedModified,
                        'skipped_inconsistent' => $skippedInconsistent,
                    ];
                });

                return [
                    ...$outcome,
                    'deadlock_retries' => $deadlockRetries,
                ];
            } catch (QueryException $e) {
                if ($this->isDeadlockOrLockWait($e) && $attempt < self::DEFAULT_MAX_DEADLOCK_RETRIES) {
                    $deadlockRetries++;
                    usleep(50000 * $attempt);

                    continue;
                }

                throw new QueueRecoveryException(
                    "Error de base de datos durante lote #{$batchSeq}: ".$e->getMessage(),
                    'database_batch_error',
                    ['batch_seq' => $batchSeq, 'sql_state' => $e->getCode()],
                    $e,
                );
            }
        }
    }

    /**
     * Inspects unresolved `batch_intent` entries against live DB rows and manifest fingerprints,
     * classifying each row into: `not_moved`, `moved_correctly`, `modified_by_other_process`,
     * `missing`, or `ambiguous`. Resolves clean all-moved or all-not-moved intents idempotently;
     * stops fail-closed on ANY ambiguity, missing row, or external modification!
     *
     * @param  array<string, mixed>  $verifiedManifest
     * @param  list<array{batch_seq: int, ids: list<int>, min_id: int, max_id: int}>  $unresolvedIntents
     * @param  array{job_class: string, source_queue: string, target_queue: string}  $mapping
     * @return list<array<string, mixed>>
     */
    private function reconcileUnresolvedIntents(
        ConnectionInterface $db,
        string $table,
        array $verifiedManifest,
        array $unresolvedIntents,
        array $mapping,
        string $fromQueue,
        string $toQueue,
        string $operation,
        QueueRecoveryAuditLog $auditLog,
    ): array {
        $manifestSha = (string) $verifiedManifest['manifest_sha256'];
        $incidentId = (string) $verifiedManifest['header']['incident_id'];
        $reconciled = [];

        foreach ($unresolvedIntents as $intent) {
            $ids = $intent['ids'];
            $seq = $intent['batch_seq'];
            $manifestCandidates = $this->manifestManager->findCandidatesByIds($verifiedManifest, $ids);
            $liveRows = $db->table($table)
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            $states = [
                'not_moved' => [],
                'moved_correctly' => [],
                'modified_by_other_process' => [],
                'missing' => [],
                'ambiguous' => [],
            ];

            foreach ($ids as $jid) {
                $row = $liveRows->get($jid);
                $expected = $manifestCandidates[$jid] ?? null;

                if ($row === null) {
                    $states['missing'][] = $jid;

                    continue;
                }
                if ($expected === null) {
                    $states['ambiguous'][] = $jid;

                    continue;
                }

                $normalized = $this->backupService->normalizeRow($row);
                $normalizedAsOriginal = [...$normalized, 'queue' => $mapping['source_queue']];
                $payloadMatches = hash_equals((string) $expected['payload_hash'], hash('sha256', $normalized['payload']));
                $rowMatches = hash_equals((string) $expected['row_sha256'], $this->backupService->rowHash($normalizedAsOriginal));

                if (! $payloadMatches || ! $rowMatches || $normalized['reserved_at'] !== null) {
                    $states['modified_by_other_process'][] = $jid;

                    continue;
                }

                if ($normalized['queue'] === $toQueue) {
                    $states['moved_correctly'][] = $jid;
                } elseif ($normalized['queue'] === $fromQueue) {
                    $states['not_moved'][] = $jid;
                } else {
                    $states['modified_by_other_process'][] = $jid;
                }
            }

            $total = count($ids);
            if (count($states['moved_correctly']) === $total) {
                $auditLog->record('batch_committed', [
                    'incident_id' => $incidentId,
                    'operation' => $operation,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $seq,
                    'ids' => $ids,
                    'moved_count' => $total,
                    'min_id' => min($ids),
                    'max_id' => max($ids),
                    'from_queue' => $fromQueue,
                    'to_queue' => $toQueue,
                    'reconciled_from_live_state' => true,
                ]);
                $reconciled[] = ['batch_seq' => $seq, 'resolution' => 'committed_reconciled_from_live_state', 'count' => $total];
            } elseif (count($states['not_moved']) === $total) {
                $auditLog->record('batch_aborted', [
                    'incident_id' => $incidentId,
                    'operation' => $operation,
                    'manifest_sha256' => $manifestSha,
                    'batch_seq' => $seq,
                    'ids' => $ids,
                    'reconciled_from_live_state' => true,
                ]);
                $reconciled[] = ['batch_seq' => $seq, 'resolution' => 'aborted_before_commit_will_retry', 'count' => $total];
            } else {
                throw new QueueRecoveryException(
                    sprintf(
                        'Estado ambiguo o alterado al reconciliar lote pendiente #%d (%s): moved=%d, not_moved=%d, modified=%d, missing=%d, ambiguous=%d. Requiere revisión manual explícita.',
                        $seq,
                        $operation,
                        count($states['moved_correctly']),
                        count($states['not_moved']),
                        count($states['modified_by_other_process']),
                        count($states['missing']),
                        count($states['ambiguous']),
                    ),
                    'unresolved_ambiguous_batch_intent',
                    ['batch_seq' => $seq, 'states' => $states],
                );
            }
        }

        return $reconciled;
    }

    /**
     * Parses the append-only audit log strictly for a specific manifest and operation (`quarantine_apply`
     * or `quarantine_restore`), aborting fail-closed on ANY corrupt or truncated NDJSON line.
     *
     * @return array{
     *     last_batch_seq: int,
     *     max_committed_id: int,
     *     committed_batches: array<int, array{batch_seq: int, ids: list<int>, min_id: int, max_id: int}>,
     *     unresolved_intents: list<array{batch_seq: int, ids: list<int>, min_id: int, max_id: int}>
     * }
     */
    private function inspectOperationJournal(
        string $auditLogPath,
        string $manifestSha256,
        string $operation,
        bool $collectCommittedIds = false,
    ): array {
        if (! is_file($auditLogPath)) {
            return [
                'last_batch_seq' => 0,
                'max_committed_id' => 0,
                'committed_batches' => [],
                'unresolved_intents' => [],
            ];
        }

        $safePath = $this->pathGuard->assertSafeInputPath($auditLogPath);
        $handle = @fopen($safePath, 'rb');
        if ($handle === false) {
            throw new QueueRecoveryException("No se pudo leer el audit log: '{$safePath}'.", 'audit_log_unreadable');
        }

        $intents = [];
        $committed = [];
        $committedSeqs = [];
        $aborted = [];
        $lastSeq = 0;
        $maxCommittedId = 0;
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }

                try {
                    $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new QueueRecoveryException(
                        "Audit log corrupto o truncado en línea #{$lineNumber}: '{$safePath}'.",
                        'audit_log_corrupt',
                        ['line' => $lineNumber],
                        $e,
                    );
                }

                if (! is_array($record) || ! is_string($record['event'] ?? null)) {
                    throw new QueueRecoveryException("Registro inválido en audit log línea #{$lineNumber}.", 'audit_log_corrupt');
                }

                if ((string) ($record['manifest_sha256'] ?? '') !== $manifestSha256
                    || (string) ($record['operation'] ?? '') !== $operation) {
                    continue;
                }

                $event = (string) $record['event'];
                $seq = (int) ($record['batch_seq'] ?? 0);
                if ($seq > $lastSeq) {
                    $lastSeq = $seq;
                }
                if ($seq <= 0) {
                    continue;
                }

                if ($event === 'batch_intent' && is_array($record['ids'] ?? null)) {
                    $intents[$seq] = [
                        'batch_seq' => $seq,
                        'ids' => array_values(array_map('intval', $record['ids'])),
                        'min_id' => (int) ($record['min_id'] ?? 0),
                        'max_id' => (int) ($record['max_id'] ?? 0),
                    ];
                } elseif ($event === 'batch_committed') {
                    $committedSeqs[$seq] = true;
                    $maxId = (int) ($record['max_id'] ?? ($intents[$seq]['max_id'] ?? 0));
                    if ($maxId > $maxCommittedId) {
                        $maxCommittedId = $maxId;
                    }
                    if ($collectCommittedIds) {
                        $ids = is_array($record['ids'] ?? null)
                            ? array_values(array_map('intval', $record['ids']))
                            : ($intents[$seq]['ids'] ?? []);
                        if ($ids !== [] && $maxId <= 0) {
                            $maxId = max($ids);
                            if ($maxId > $maxCommittedId) {
                                $maxCommittedId = $maxId;
                            }
                        }
                        $committed[$seq] = [
                            'batch_seq' => $seq,
                            'ids' => $ids,
                            'min_id' => (int) ($record['min_id'] ?? ($ids !== [] ? min($ids) : 0)),
                            'max_id' => $maxId,
                        ];
                    }
                    // Free intent memory once resolved so apply stays O(1) RAM even on millions of rows!
                    unset($intents[$seq]);
                } elseif ($event === 'batch_aborted') {
                    $aborted[$seq] = true;
                    unset($intents[$seq]);
                }
            }
        } finally {
            fclose($handle);
        }

        $unresolved = [];
        foreach ($intents as $seq => $intent) {
            if (! isset($committedSeqs[$seq]) && ! isset($aborted[$seq])) {
                $unresolved[] = $intent;
            }
        }
        usort($unresolved, static fn (array $a, array $b): int => $a['batch_seq'] <=> $b['batch_seq']);
        ksort($committed);

        return [
            'last_batch_seq' => $lastSeq,
            'max_committed_id' => $maxCommittedId,
            'committed_batches' => $committed,
            'unresolved_intents' => $unresolved,
        ];
    }

    /** @param list<string> $queues */
    private function assertAllRuntimeSafetyGates(
        string $maintenanceLockFile,
        string $maintenanceWindow,
        string $incidentId,
        array $queues,
    ): void {
        $this->policy->assertProblematicAutomationsDisabled();
        $this->workerDetector->assertMaintenanceGateActive($maintenanceLockFile, $maintenanceWindow, $incidentId);
        $this->workerDetector->assertNoActiveTargetWorkers($queues, strictVisibility: true);
    }

    private function validateChunk(int $chunk): int
    {
        if ($chunk < 1 || $chunk > self::MAX_CHUNK_SIZE) {
            throw new QueueRecoveryException(
                "Tamaño de lote inválido: --chunk={$chunk} (rango permitido: 1..".self::MAX_CHUNK_SIZE.').',
                'invalid_chunk_size',
            );
        }

        return $chunk;
    }

    private function shouldStop(int $nextBatchSeq): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            @pcntl_signal_dispatch();
        }

        if ($this->interrupted) {
            return true;
        }

        return $this->shouldInterruptChecker !== null && ($this->shouldInterruptChecker)($nextBatchSeq);
    }

    private function isDeadlockOrLockWait(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $msg = strtolower($e->getMessage());

        return $sqlState === '40001'
            || in_array($driverCode, [1213, 1205], true)
            || str_contains($msg, 'deadlock')
            || str_contains($msg, 'lock wait timeout')
            || str_contains($msg, 'database is locked');
    }
}
