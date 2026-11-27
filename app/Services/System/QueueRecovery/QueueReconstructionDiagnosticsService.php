<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use App\Models\InventoryChannelLink;
use App\Models\MeliBeautyScheduledDiscount;
use App\Services\InventoryMeliOrderReservationCutover;
use App\Services\MercadoLibre\PriceManager\MeliBeautyPromotionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Strictly READ-ONLY exhaustive reconstruction diagnostics for R2-DIAG.1 (format_version 2.1).
 *
 * Guarantees:
 * - 100% read-only: never mutates any DB table, never executes jobs, never calls Mercado Libre HTTP APIs.
 * - Exhaustive, mutually exclusive 7-category primary classification for EVERY identifiable distinct order
 *   in scope (including orders whose ONLY manifest jobs are reserved — D2), alongside overlapping secondary
 *   anomalies and non-authorizing operational recommendations.
 * - Fail-closed invariants: throws `QueueRecoveryException(..., 'diagnostic_invariant_violation')`
 *   if ANY accounting or partitioning invariant fails.
 * - Single-FD streaming manifest verification (D1, D4) + hardened exclusive `0600` artifact writer with
 *   collision checks, non-overwrite, `fflush`+`fsync`, non-clobbering publish, post-publish disk SHA-256
 *   verification, and unambiguous multi-file publication protocol (D3).
 * - Interval-based chunked reads (never holds long-running DB transactions on production).
 * - Full compatibility with both R3 durable state (`inventory_meli_order_reconciliations`)
 *   and pre-R3 legacy production schemas, plus backwards-compatible R2.1 report keys.
 */
class QueueReconstructionDiagnosticsService
{
    public const FORMAT_VERSION = '2.1';

    public const DEFAULT_MAX_INLINE_ENTITIES = 2000;

    /** @var (callable(array<string, mixed>): array<string, mixed>)|null */
    private $invariantCheckMutator = null;

    /** @var (callable(int): void)|null */
    private $betweenOrderChunksHook = null;

    public function __construct(
        private readonly QueueRecoveryManifest $manifestManager,
        private readonly QueuePayloadInspector $inspector,
        private readonly InventoryMeliOrderReservationCutover $cutover,
        private readonly MeliBeautyPromotionWindow $beautyWindow,
        private readonly QueueManifestStreamReader $manifestStreamReader = new QueueManifestStreamReader,
        private readonly QueueOrderExhaustiveClassifier $orderClassifier = new QueueOrderExhaustiveClassifier,
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
        private readonly QueueDiagnosticArtifactWriter $artifactWriter = new QueueDiagnosticArtifactWriter,
    ) {}

    /**
     * Test hook to inject an invariant violation and prove fail-closed behavior deterministically.
     *
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $mutator
     */
    public function setInvariantCheckMutator(?callable $mutator): void
    {
        $this->invariantCheckMutator = $mutator;
    }

    /**
     * Test hook invoked between order chunks to observe/test concurrent data changes during interval reads.
     *
     * @param  (callable(int): void)|null  $hook
     */
    public function setBetweenOrderChunksHook(?callable $hook): void
    {
        $this->betweenOrderChunksHook = $hook;
    }

    /**
     * Runs exhaustive, strictly read-only diagnostics with fail-closed invariants and hardened
     * streaming NDJSON / JSON summary / completion-marker publication.
     *
     * @param  array{
     *     manifest_path?: ?string,
     *     backup_path?: ?string,
     *     expected_manifest_sha256?: ?string,
     *     hmac_key?: ?string,
     *     account_id?: ?int,
     *     cutover?: ?string,
     *     chunk?: int,
     *     connection?: ?string,
     *     ndjson_output_path?: ?string,
     *     output_path?: ?string,
     *     completion_marker_path?: ?string,
     *     max_inline_entities?: ?int,
     *     summary_only?: bool,
     *     verify_live_jobs?: bool,
     *     queue_table?: ?string
     * }  $options
     * @return array<string, mixed>
     */
    public function diagnose(array $options = []): array
    {
        $startedAt = microtime(true);
        $intervalStartedIso = now()->utc()->toIso8601String();
        $connName = is_string($options['connection'] ?? null) && trim($options['connection']) !== ''
            ? trim($options['connection'])
            : (string) config('database.default');
        $db = DB::connection($connName);
        $schema = Schema::connection($connName);
        $chunk = max(50, min(500, (int) ($options['chunk'] ?? 500)));
        $accountFilter = isset($options['account_id']) && $options['account_id'] !== null && $options['account_id'] !== ''
            ? (int) $options['account_id']
            : null;
        $summaryOnly = (bool) ($options['summary_only'] ?? false);
        $maxInlineEntities = $summaryOnly
            ? 0
            : max(0, (int) ($options['max_inline_entities'] ?? self::DEFAULT_MAX_INLINE_ENTITIES));
        $verifyLiveJobs = (bool) ($options['verify_live_jobs'] ?? false);
        $queueTable = is_string($options['queue_table'] ?? null) && trim($options['queue_table']) !== ''
            ? trim($options['queue_table'])
            : 'jobs';

        $manifestPath = isset($options['manifest_path']) && trim((string) $options['manifest_path']) !== ''
            ? trim((string) $options['manifest_path'])
            : null;
        $backupPath = isset($options['backup_path']) && trim((string) $options['backup_path']) !== ''
            ? trim((string) $options['backup_path'])
            : null;
        $ndjsonOutputPath = isset($options['ndjson_output_path']) && trim((string) $options['ndjson_output_path']) !== ''
            ? trim((string) $options['ndjson_output_path'])
            : null;
        $summaryOutputPath = isset($options['output_path']) && trim((string) $options['output_path']) !== ''
            ? trim((string) $options['output_path'])
            : null;
        $completionMarkerPath = isset($options['completion_marker_path']) && trim((string) $options['completion_marker_path']) !== ''
            ? trim((string) $options['completion_marker_path'])
            : null;

        // D3: Pre-validate path collisions & non-overwrite BEFORE any scan or file creation!
        $this->artifactWriter->assertNoPathCollisions([
            'manifest' => $manifestPath,
            'backup' => $backupPath,
            'ndjson_output' => $ndjsonOutputPath,
            'summary_output' => $summaryOutputPath,
            'completion_marker' => $completionMarkerPath,
        ]);

        if ($summaryOutputPath !== null) {
            $safeSummary = $this->pathGuard->assertSafeOutputPath($summaryOutputPath, false, false);
            clearstatcache(true, $safeSummary);
            if (file_exists($safeSummary) || is_link($safeSummary)) {
                throw new QueueRecoveryException(
                    "El archivo de resumen JSON ya existe y no se permite sobrescritura: '{$safeSummary}'.",
                    'artifact_already_exists',
                    ['path' => $safeSummary],
                );
            }
            $summaryOutputPath = $safeSummary;
        }

        if ($completionMarkerPath !== null) {
            $safeMarker = $this->pathGuard->assertSafeOutputPath($completionMarkerPath, false, false);
            clearstatcache(true, $safeMarker);
            if (file_exists($safeMarker) || is_link($safeMarker)) {
                throw new QueueRecoveryException(
                    "El marcador de completitud ya existe y no se permite sobrescritura: '{$safeMarker}'.",
                    'artifact_already_exists',
                    ['path' => $safeMarker],
                );
            }
            $completionMarkerPath = $safeMarker;
        }

        $manifestData = null;
        $scopedOrderIds = null;
        $scopedDiscountIds = null;
        $manifestMeta = null;

        if ($manifestPath !== null) {
            $manifestData = $this->manifestStreamReader->readAndVerify(
                $manifestPath,
                $this->inspector,
                isset($options['expected_manifest_sha256']) ? (string) $options['expected_manifest_sha256'] : null,
                isset($options['hmac_key']) ? (string) $options['hmac_key'] : null,
            );

            $scopedOrderIds = array_keys($manifestData['order_job_counts']);
            $scopedDiscountIds = $manifestData['discount_ids'];

            $manifestMeta = [
                'format' => $manifestData['format'],
                'schema_version' => $manifestData['schema_version'],
                'incident_id' => $manifestData['incident_id'],
                'manifest_sha256' => $manifestData['manifest_sha256'],
                'integrity_checksum_sha256' => $manifestData['manifest_sha256'],
                'file_sha256' => $manifestData['file_sha256'],
                'expected_hash_verified' => (bool) ($manifestData['expected_hash_verified'] ?? false),
                'hmac_verified' => (bool) $manifestData['hmac_verified'],
                'hmac_authenticated' => (bool) $manifestData['hmac_verified'],
                'checksum_is_not_authentication' => true,
                'distinct_orders_in_manifest' => count($scopedOrderIds),
                'distinct_discounts_in_manifest' => count($scopedDiscountIds),
                'total_manifest_entries' => (int) $manifestData['job_accounting']['total_manifest_entries'],
            ];
        }

        $cutoverAt = $this->resolveCutover($options['cutover'] ?? null);

        $tables = [
            'meli_accounts' => $schema->hasTable('meli_accounts'),
            'meli_orders' => $schema->hasTable('meli_orders'),
            'meli_order_items' => $schema->hasTable('meli_order_items'),
            'inventory_channel_links' => $schema->hasTable('inventory_channel_links'),
            'inventory_channel_order_allocations' => $schema->hasTable('inventory_channel_order_allocations'),
            'inventory_reservations' => $schema->hasTable('inventory_reservations'),
            'inventory_kit_reservations' => $schema->hasTable('inventory_kit_reservations'),
            'inventory_kit_components' => $schema->hasTable('inventory_kit_components'),
            'inventory_products' => $schema->hasTable('inventory_products'),
            'inventory_movements' => $schema->hasTable('inventory_movements'),
            'inventory_meli_order_reconciliations' => $schema->hasTable('inventory_meli_order_reconciliations'),
            'meli_beauty_scheduled_discounts' => $schema->hasTable('meli_beauty_scheduled_discounts'),
            'meli_beauty_scheduled_discount_items' => $schema->hasTable('meli_beauty_scheduled_discount_items'),
            'meli_scheduled_price_states' => $schema->hasTable('meli_scheduled_price_states'),
            'meli_price_changes' => $schema->hasTable('meli_price_changes'),
            'meli_price_manager_items' => $schema->hasTable('meli_price_manager_items'),
        ];

        $hasLiveJobsTable = $verifyLiveJobs && $schema->hasTable($queueTable);

        $validAccountIds = [];
        if ($tables['meli_accounts']) {
            foreach ($db->table('meli_accounts')->pluck('id') as $aid) {
                $validAccountIds[(int) $aid] = true;
            }
        }

        $categories = [
            'cancelled_orders_with_active_reservations' => [],
            'shipped_orders_with_active_reservations' => [],
            'paid_orders_pending_reservation' => [],
            'shipped_during_outage_never_reserved' => [],
            'orders_with_manual_movements' => [],
            'multiline_orders' => [],
            'kits_and_components' => [],
            'active_beauty_promotions' => [],
            'beauty_promotions_pending_restore' => [],
            'expired_beauty_promotions' => [],
            'remote_states_requiring_verification' => [],
        ];
        $categoryCounts = array_fill_keys(array_keys($categories), 0);

        $schemaAnomalies = [
            'missing_orders_in_manifest' => [],
            'ambiguous_channel_links' => [],
            'missing_remote_line_key_orders' => [],
            'account_mismatches' => [],
            'deleted_beauty_rules' => [],
            'modified_beauty_rules_orphaned_states' => [],
        ];
        $schemaAnomalyCounts = array_fill_keys(array_keys($schemaAnomalies), 0);

        $classification = [
            'reconstructible_candidates' => [],
            'ambiguous_entities' => [],
            'pending_human_review' => [],
        ];
        $classificationCounts = [
            'reconstructible_candidates' => 0,
            'ambiguous_entities' => 0,
            'pending_human_review' => 0,
        ];

        $primaryPartitionCounts = array_fill_keys(QueueOrderExhaustiveClassifier::PRIMARY_CATEGORIES, 0);
        $secondaryAnomalyCounts = [];
        $reasonCodeCounts = [];
        $r3StateCounts = [
            'IDLE' => 0,
            'PENDING' => 0,
            'PROCESSING' => 0,
            'FAILED' => 0,
            'ABSENT_ROW' => 0,
            'EXPIRED_LEASES' => 0,
            'STOCK_SYNC_REQUIRED' => 0,
            'PENDING_REVISION_UNPROCESSED' => 0,
        ];
        $jobsByPrimaryCategory = array_fill_keys(QueueOrderExhaustiveClassifier::PRIMARY_CATEGORIES, 0);
        $jobSecurityCounters = [
            'jobs_for_missing_orders' => 0,
            'jobs_for_pending_obligations' => 0,
        ];

        $inlineEntities = [];
        $inlineTruncated = false;

        // D3: Open exclusive 0600 NDJSON temp stream via QueueDiagnosticArtifactWriter (never overwrites existing files!).
        $ndjsonStream = null;
        if ($ndjsonOutputPath !== null) {
            $ndjsonStream = $this->artifactWriter->openExclusiveStream($ndjsonOutputPath, disallowOverwrite: true, disallowInsideRepo: false);
            $ndjsonOutputPath = (string) $ndjsonStream['target_path'];
        }

        $orderStats = [
            'expected_distinct_orders' => 0,
            'accounted_orders' => 0,
            'accounted_order_jobs' => 0,
            'accounted_unreserved_order_jobs' => 0,
            'accounted_reserved_order_jobs' => 0,
            'duplicate_primary_classifications' => 0,
            'missing_orders_count' => 0,
            'filtered_out_by_account_count' => 0,
        ];

        $ndjsonPublishedMeta = null;

        try {
            if ($tables['meli_orders'] || $scopedOrderIds !== null) {
                $this->diagnoseOrders(
                    $db,
                    $tables,
                    $validAccountIds,
                    $scopedOrderIds,
                    $manifestData,
                    $accountFilter,
                    $cutoverAt,
                    $chunk,
                    $maxInlineEntities,
                    $hasLiveJobsTable,
                    $queueTable,
                    $categories,
                    $categoryCounts,
                    $schemaAnomalies,
                    $schemaAnomalyCounts,
                    $classification,
                    $classificationCounts,
                    $primaryPartitionCounts,
                    $secondaryAnomalyCounts,
                    $reasonCodeCounts,
                    $r3StateCounts,
                    $jobsByPrimaryCategory,
                    $jobSecurityCounters,
                    $inlineEntities,
                    $inlineTruncated,
                    $ndjsonStream,
                    $orderStats,
                );
            }

            if ($tables['meli_beauty_scheduled_discounts']) {
                $this->diagnoseBeautyPromotions(
                    $db,
                    $tables,
                    $validAccountIds,
                    $scopedDiscountIds,
                    $accountFilter,
                    $maxInlineEntities,
                    $categories,
                    $categoryCounts,
                    $schemaAnomalies,
                    $schemaAnomalyCounts,
                    $classification,
                    $classificationCounts,
                );
            }

            $invariants = $this->evaluateAndVerifyInvariants(
                $manifestData,
                $accountFilter,
                $orderStats,
                $primaryPartitionCounts,
                $ndjsonStream !== null,
                $ndjsonStream !== null ? (int) $ndjsonStream['records_written'] : 0,
            );

            // Phase 1 of joint publication: flush, fsync, close, non-clobbering publish & disk SHA-256 verify NDJSON!
            if ($ndjsonStream !== null) {
                $ndjsonPublishedMeta = $this->artifactWriter->publishStream($ndjsonStream);
            }
        } catch (Throwable $e) {
            $this->artifactWriter->abortStream($ndjsonStream);

            throw $e;
        }

        ksort($secondaryAnomalyCounts);
        ksort($reasonCodeCounts);

        $jobAccounting = $manifestData['job_accounting'] ?? [
            'total_manifest_entries' => 0,
            'reservation_job_entries' => 0,
            'reservation_unreserved_job_entries' => 0,
            'reservation_reserved_job_entries' => 0,
            'total_order_job_entries' => 0,
            'non_order_reserved_job_entries' => 0,
            'beauty_job_entries' => 0,
            'other_class_job_entries' => 0,
            'other_class_job_samples' => [],
            'reserved_job_entries' => 0,
            'reserved_job_entries_sample' => [],
            'invalid_or_unverifiable_job_entries' => 0,
            'conflicting_identity_job_entries' => 0,
            'invalid_or_unverifiable_job_samples' => [],
            'duplicate_manifest_job_id_entries' => 0,
            'duplicate_manifest_job_ids_sample' => [],
            'upper_bound_exceeded_entries' => 0,
            'distinct_orders_in_manifest' => 0,
            'reserved_only_orders_in_manifest' => 0,
            'mixed_reserved_and_unreserved_orders_in_manifest' => 0,
            'distinct_discounts_in_manifest' => 0,
            'orders_with_single_job' => 0,
            'orders_with_multiple_jobs' => 0,
            'extra_duplicate_jobs_for_same_order' => 0,
            'jobs_per_order_distribution' => [],
            'exact_seen_job_tracking_limit' => QueueManifestStreamReader::MAX_EXACT_SEEN_JOB_TRACKING,
            'strict_ascending_mode_activated' => false,
            'manifest_declared_preserved_reserved' => 0,
            'manifest_declared_preserved_invalid_json' => 0,
            'manifest_declared_preserved_inconsistent' => 0,
            'manifest_declared_preserved_other_modules' => 0,
            'manifest_declared_reserved_job_ids_sample' => [],
            'manifest_declared_invalid_payload_job_ids_sample' => [],
            'manifest_declared_inconsistent_payload_job_ids_sample' => [],
        ];

        $historicalRecoverySecurity = [
            'total_jobs_in_manifest' => (int) $jobAccounting['total_manifest_entries'],
            'reservation_jobs_in_manifest' => (int) $jobAccounting['reservation_job_entries'],
            'reservation_unreserved_jobs_in_manifest' => (int) ($jobAccounting['reservation_unreserved_job_entries'] ?? $jobAccounting['reservation_job_entries']),
            'reservation_reserved_jobs_in_manifest' => (int) ($jobAccounting['reservation_reserved_job_entries'] ?? 0),
            'total_order_jobs_in_manifest' => (int) ($jobAccounting['total_order_job_entries'] ?? $jobAccounting['reservation_job_entries']),
            'distinct_orders_in_manifest' => (int) $jobAccounting['distinct_orders_in_manifest'],
            'reserved_only_orders_in_manifest' => (int) ($jobAccounting['reserved_only_orders_in_manifest'] ?? 0),
            'mixed_reserved_and_unreserved_orders_in_manifest' => (int) ($jobAccounting['mixed_reserved_and_unreserved_orders_in_manifest'] ?? 0),
            'orders_with_single_job' => (int) $jobAccounting['orders_with_single_job'],
            'orders_with_multiple_jobs' => (int) $jobAccounting['orders_with_multiple_jobs'],
            'extra_duplicate_jobs_for_same_order' => (int) $jobAccounting['extra_duplicate_jobs_for_same_order'],
            'jobs_per_order_distribution' => $jobAccounting['jobs_per_order_distribution'],
            'reserved_jobs_count' => (int) $jobAccounting['reserved_job_entries'] + (int) $jobAccounting['manifest_declared_preserved_reserved'],
            'invalid_payload_jobs_count' => (int) $jobAccounting['invalid_or_unverifiable_job_entries']
                + (int) $jobAccounting['manifest_declared_preserved_invalid_json']
                + (int) $jobAccounting['manifest_declared_preserved_inconsistent'],
            'conflicting_identity_jobs_count' => (int) ($jobAccounting['conflicting_identity_job_entries'] ?? 0),
            'other_class_jobs_in_entries' => (int) ($jobAccounting['other_class_job_entries'] ?? 0),
            'other_module_jobs_preserved' => (int) $jobAccounting['manifest_declared_preserved_other_modules'],
            'jobs_for_missing_orders' => $jobSecurityCounters['jobs_for_missing_orders'],
            'jobs_for_ambiguous_orders' => $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES],
            'jobs_for_pending_human_review_orders' => $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW],
            'jobs_for_pending_obligations' => $jobSecurityCounters['jobs_for_pending_obligations'],
            'jobs_for_reconstructible_candidates' => $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES],
            'jobs_for_consistent_orders' => $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED],
            'jobs_for_out_of_scope_orders' => $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK]
                + $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED],
            'jobs_for_insufficient_evidence_orders' => $jobsByPrimaryCategory[QueueOrderExhaustiveClassifier::PRIMARY_INSUFFICIENT_EVIDENCE],
            'jobs_by_primary_classification' => $jobsByPrimaryCategory,
            'safety_guards' => [
                'duplicate_entity_id_does_not_imply_deletable' => true,
                'single_job_orders_must_not_lose_reconciliation_obligation' => true,
                'manifest_reserved_at_is_historical_snapshot_not_live_worker_proof' => true,
                'live_queue_pk_verification_enabled' => $hasLiveJobsTable,
                'automatic_job_deletion_authorized' => false,
                'automatic_inventory_mutation_authorized' => false,
            ],
        ];

        $intervalCompletedIso = now()->utc()->toIso8601String();
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);

        $report = [
            'format_version' => self::FORMAT_VERSION,
            'generated_at' => $intervalCompletedIso,
            'mode' => 'read_only_diagnosis',
            'connection' => $connName,
            'database' => $db->getDatabaseName(),
            'cutover_at' => $cutoverAt?->toIso8601String(),
            'account_filter' => $accountFilter,
            'diagnostic_snapshot_semantics' => [
                'consistency_model' => 'chunked_interval_reads_not_global_snapshot',
                'holds_long_db_transaction' => false,
                'interval_started_at' => $intervalStartedIso,
                'interval_completed_at' => $intervalCompletedIso,
                'live_jobs_pk_verification' => $hasLiveJobsTable,
            ],
            'publication_protocol' => [
                'scheme' => 'two_phase_ndjson_then_summary_with_optional_completion_marker',
                'complete_output_rule' => 'An NDJSON file is complete ONLY when the paired summary JSON (or completion marker) exists, passes disk SHA-256 verification, and references its exact ndjson_output.sha256 and record count.',
                'summary_published' => false,
                'completion_marker_path' => $completionMarkerPath,
            ],
            'manifest_scope' => $manifestMeta,
            'tables_present' => $tables,
            'r3_compatibility' => [
                'r3_durable_state_table_present' => $tables['inventory_meli_order_reconciliations'],
                'schema_mode' => $tables['inventory_meli_order_reconciliations'] ? 'r3_durable_enabled' : 'pre_r3_legacy',
                'absence_treated_as_reconciled' => false,
                'state_counts' => $tables['inventory_meli_order_reconciliations'] ? $r3StateCounts : null,
            ],
            'invariants' => $invariants,
            'exhaustive_classification' => [
                'priority_order' => QueueOrderExhaustiveClassifier::PRIMARY_CATEGORIES,
                'primary_partition_counts' => $primaryPartitionCounts,
                'secondary_anomaly_counts' => $secondaryAnomalyCounts,
                'reason_code_counts' => $reasonCodeCounts,
                'inline_entities_count' => count($inlineEntities),
                'inline_limit' => $maxInlineEntities,
                'inline_truncated' => $inlineTruncated,
                'ndjson_output' => $ndjsonPublishedMeta,
                'entities' => $inlineEntities,
            ],
            'manifest_job_accounting' => $jobAccounting,
            'historical_recovery_security' => $historicalRecoverySecurity,
            'performance_metrics' => [
                'duration_ms' => $durationMs,
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
                'chunk_size' => $chunk,
            ],
            // Backwards-compatible R2.1 keys preserved verbatim:
            'reconstructibility_policy' => [
                'automatic_reconstructibility_assumed' => false,
                'automatic_job_deletion_authorized' => false,
                'automatic_inventory_mutation_authorized' => false,
                'categories_requiring_manual_or_remote_verification' => [
                    'shipped_during_outage_never_reserved',
                    'orders_with_manual_movements',
                    'multiline_orders',
                    'kits_and_components',
                    'remote_states_requiring_verification',
                    'deleted_beauty_rules',
                    'modified_beauty_rules_orphaned_states',
                    'ambiguous_channel_links',
                    'missing_remote_line_key_orders',
                    'account_mismatches',
                ],
            ],
            'counts' => $categoryCounts,
            'anomaly_counts' => $schemaAnomalyCounts,
            'reconstructibility_summary' => [
                'counts' => $classificationCounts,
                'reconstructible_candidates' => $classification['reconstructible_candidates'],
                'ambiguous_entities' => $classification['ambiguous_entities'],
                'pending_human_review' => $classification['pending_human_review'],
            ],
            'schema_anomalies' => $schemaAnomalies,
            'categories' => $categories,
        ];

        // Phase 2 of joint publication: publish verified Summary JSON (if requested).
        $summaryPublishedMeta = null;
        if ($summaryOutputPath !== null) {
            $report['publication_protocol']['summary_published'] = true;
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $summaryPublishedMeta = $this->artifactWriter->writeAndPublishString(
                $summaryOutputPath,
                $json,
                disallowOverwrite: true,
                disallowInsideRepo: false,
            );
            $report['publication_protocol']['summary_output'] = $summaryPublishedMeta;
        }

        // Phase 3 (optional): publish final Completion Marker referencing exact verified hashes of ALL artifacts!
        if ($completionMarkerPath !== null) {
            $markerPayload = [
                'status' => 'complete',
                'format_version' => self::FORMAT_VERSION,
                'completed_at' => now()->utc()->toIso8601String(),
                'invariants_passed' => true,
                'distinct_orders_in_scope' => $invariants['distinct_orders_in_scope'],
                'accounted_orders' => $invariants['accounted_orders'],
                'manifest_sha256' => $manifestMeta['manifest_sha256'] ?? null,
                'ndjson_output' => $ndjsonPublishedMeta,
                'summary_output' => $summaryPublishedMeta,
            ];
            $markerJson = json_encode($markerPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $markerMeta = $this->artifactWriter->writeAndPublishString(
                $completionMarkerPath,
                $markerJson,
                disallowOverwrite: true,
                disallowInsideRepo: false,
            );
            $report['publication_protocol']['completion_marker_output'] = $markerMeta;
        }

        return $report;
    }

    /**
     * @param  array<string, bool>  $tables
     * @param  array<int, bool>  $validAccountIds
     * @param  list<int>|null  $scopedOrderIds
     * @param  array<string, mixed>|null  $manifestData
     * @param  array<string, list<array<string, mixed>>>  $categories
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, list<array<string, mixed>>>  $schemaAnomalies
     * @param  array<string, int>  $schemaAnomalyCounts
     * @param  array<string, list<array<string, mixed>>>  $classification
     * @param  array<string, int>  $classificationCounts
     * @param  array<string, int>  $primaryPartitionCounts
     * @param  array<string, int>  $secondaryAnomalyCounts
     * @param  array<string, int>  $reasonCodeCounts
     * @param  array<string, int>  $r3StateCounts
     * @param  array<string, int>  $jobsByPrimaryCategory
     * @param  array<string, int>  $jobSecurityCounters
     * @param  list<array<string, mixed>>  $inlineEntities
     * @param  array<string, mixed>|null  $ndjsonStream
     * @param  array<string, int>  $orderStats
     */
    private function diagnoseOrders(
        ConnectionInterface $db,
        array $tables,
        array $validAccountIds,
        ?array $scopedOrderIds,
        ?array $manifestData,
        ?int $accountFilter,
        ?CarbonImmutable $cutoverAt,
        int $chunk,
        int $maxInlineEntities,
        bool $hasLiveJobsTable,
        string $queueTable,
        array &$categories,
        array &$categoryCounts,
        array &$schemaAnomalies,
        array &$schemaAnomalyCounts,
        array &$classification,
        array &$classificationCounts,
        array &$primaryPartitionCounts,
        array &$secondaryAnomalyCounts,
        array &$reasonCodeCounts,
        array &$r3StateCounts,
        array &$jobsByPrimaryCategory,
        array &$jobSecurityCounters,
        array &$inlineEntities,
        bool &$inlineTruncated,
        ?array &$ndjsonStream,
        array &$orderStats,
    ): void {
        $schema = Schema::connection($db->getName());
        $columns = [
            'meli_orders_shipping_id' => $tables['meli_orders'] && $schema->hasColumn('meli_orders', 'shipping_id'),
            'meli_orders_shipping_raw' => $tables['meli_orders'] && $schema->hasColumn('meli_orders', 'shipping_raw'),
            'item_variation_id' => $tables['meli_order_items'] && $schema->hasColumn('meli_order_items', 'variation_id'),
            'item_remote_line_key' => $tables['meli_order_items'] && $schema->hasColumn('meli_order_items', 'remote_line_key'),
            'link_order_reservation_enabled' => $tables['inventory_channel_links'] && $schema->hasColumn('inventory_channel_links', 'order_reservation_enabled'),
            'link_stock_sync_enabled' => $tables['inventory_channel_links'] && $schema->hasColumn('inventory_channel_links', 'stock_sync_enabled'),
            'link_remote_user_product_id' => $tables['inventory_channel_links'] && $schema->hasColumn('inventory_channel_links', 'remote_user_product_id'),
            'alloc_diagnostic_code' => $tables['inventory_channel_order_allocations'] && $schema->hasColumn('inventory_channel_order_allocations', 'diagnostic_code'),
            'res_metadata' => $tables['inventory_reservations'] && $schema->hasColumn('inventory_reservations', 'metadata'),
            'kit_res_metadata' => $tables['inventory_kit_reservations'] && $schema->hasColumn('inventory_kit_reservations', 'metadata'),
            'mov_occurred_at' => $tables['inventory_movements'] && $schema->hasColumn('inventory_movements', 'occurred_at'),
            'mov_external_key' => $tables['inventory_movements'] && $schema->hasColumn('inventory_movements', 'external_key'),
            'mov_reference_id' => $tables['inventory_movements'] && $schema->hasColumn('inventory_movements', 'reference_id'),
            'mov_reference' => $tables['inventory_movements'] && $schema->hasColumn('inventory_movements', 'reference'),
            'mov_notes' => $tables['inventory_movements'] && $schema->hasColumn('inventory_movements', 'notes'),
        ];

        $productsById = [];
        $kitProductIds = [];
        $componentProductIds = [];
        $kitComponentsByKitId = [];

        if ($tables['inventory_products']) {
            $prodCols = ['id', 'sku', 'is_active'];
            $hasProductType = $schema->hasColumn('inventory_products', 'product_type');
            if ($hasProductType) {
                $prodCols[] = 'product_type';
            }
            foreach ($db->table('inventory_products')->get($prodCols) as $p) {
                $pid = (int) $p->id;
                $ptype = $hasProductType ? strtolower(trim((string) ($p->product_type ?? 'simple'))) : 'simple';
                $productsById[$pid] = [
                    'sku' => $p->sku !== null ? (string) $p->sku : null,
                    'is_active' => (bool) ($p->is_active ?? true),
                    'product_type' => $ptype,
                ];
                if ($ptype === 'kit') {
                    $kitProductIds[$pid] = true;
                }
            }
        }

        if ($tables['inventory_kit_components']) {
            foreach ($db->table('inventory_kit_components')->select(['kit_product_id', 'component_product_id'])->get() as $kc) {
                $kpid = (int) $kc->kit_product_id;
                $cpid = (int) $kc->component_product_id;
                $kitProductIds[$kpid] = true;
                $componentProductIds[$cpid] = true;
                $kitComponentsByKitId[$kpid][] = $cpid;
            }
        }

        $allLinksByKey = [];
        $activeLinkAccountsByListing = [];
        $sharedStockGroupProducts = [];

        if ($tables['inventory_channel_links']) {
            $linkCols = ['id', 'account_key', 'external_listing_id', 'external_variant_id', 'inventory_product_id', 'is_active'];
            if ($columns['link_order_reservation_enabled']) {
                $linkCols[] = 'order_reservation_enabled';
            }
            if ($columns['link_stock_sync_enabled']) {
                $linkCols[] = 'stock_sync_enabled';
            }
            if ($columns['link_remote_user_product_id']) {
                $linkCols[] = 'remote_user_product_id';
            }

            foreach ($db->table('inventory_channel_links')->where('channel', InventoryChannelLink::MERCADO_LIBRE)->get($linkCols) as $link) {
                $accKey = (string) $link->account_key;
                $listingId = (string) $link->external_listing_id;
                $varId = $link->external_variant_id !== null && trim((string) $link->external_variant_id) !== ''
                    ? trim((string) $link->external_variant_id)
                    : '';
                $isActive = (bool) ($link->is_active ?? true);
                $resEnabled = $columns['link_order_reservation_enabled'] ? (bool) ($link->order_reservation_enabled ?? false) : true;
                $syncEnabled = $columns['link_stock_sync_enabled'] ? (bool) ($link->stock_sync_enabled ?? false) : false;
                $remoteUpId = $columns['link_remote_user_product_id'] && $link->remote_user_product_id !== null && trim((string) $link->remote_user_product_id) !== ''
                    ? trim((string) $link->remote_user_product_id)
                    : null;

                $key = $accKey.'|'.$listingId.'|'.$varId;
                $allLinksByKey[$key][] = [
                    'link_id' => (int) $link->id,
                    'account_key' => $accKey,
                    'inventory_product_id' => (int) $link->inventory_product_id,
                    'is_active' => $isActive,
                    'order_reservation_enabled' => $resEnabled,
                    'stock_sync_enabled' => $syncEnabled,
                    'remote_user_product_id' => $remoteUpId,
                ];

                if ($isActive) {
                    $activeLinkAccountsByListing[$listingId][$accKey] = true;
                }

                if ($isActive && $syncEnabled && $varId === '' && $remoteUpId !== null) {
                    $groupKey = $accKey.'|'.$remoteUpId;
                    $sharedStockGroupProducts[$groupKey][(int) $link->inventory_product_id] = true;
                }
            }
        }

        $sharedStockConflictGroups = [];
        foreach ($sharedStockGroupProducts as $gKey => $pSet) {
            if (count($pSet) > 1) {
                $sharedStockConflictGroups[$gKey] = true;
            }
        }
        unset($sharedStockGroupProducts);

        $orderJobCounts = $manifestData['order_job_counts'] ?? [];
        $orderUnreservedCounts = $manifestData['order_unreserved_counts'] ?? [];
        $orderReservedCounts = $manifestData['order_reserved_counts'] ?? [];
        $orderJobSampleCsv = $manifestData['order_job_sample_csv'] ?? [];
        $orderUnreservedSampleCsv = $manifestData['order_unreserved_job_sample_csv'] ?? [];
        $orderReservedSampleCsv = $manifestData['order_reserved_job_sample_csv'] ?? [];
        $orderAccountHints = $manifestData['order_manifest_account_hints'] ?? [];
        $orderRemoteHints = $manifestData['order_manifest_remote_order_hints'] ?? [];
        $orderMetaConflicts = $manifestData['order_manifest_metadata_conflicts'] ?? [];

        $orderSelectCols = ['id', 'meli_account_id', 'order_id', 'status', 'shipping_status', 'raw', 'created_at'];
        if ($columns['meli_orders_shipping_id']) {
            $orderSelectCols[] = 'shipping_id';
        }
        if ($columns['meli_orders_shipping_raw']) {
            $orderSelectCols[] = 'shipping_raw';
        }

        $nowUtc = now()->utc()->toImmutable();
        $classifiedOrderIds = [];
        $foundScopedOrderIds = [];
        $cursor = 0;
        $scopedTotal = $scopedOrderIds !== null ? count($scopedOrderIds) : 0;
        $scopedOffset = 0;
        $chunkNumber = 0;

        if ($scopedOrderIds !== null) {
            $orderStats['expected_distinct_orders'] = $scopedTotal;
        }

        while (true) {
            if ($scopedOrderIds !== null && $scopedOffset >= $scopedTotal) {
                break;
            }
            if ($scopedOrderIds === null && ! $tables['meli_orders']) {
                break;
            }

            $chunkNumber++;
            if ($this->betweenOrderChunksHook !== null && $chunkNumber > 1) {
                ($this->betweenOrderChunksHook)($chunkNumber);
            }

            if ($scopedOrderIds !== null) {
                $chunkIds = array_slice($scopedOrderIds, $scopedOffset, $chunk);
                $scopedOffset += $chunk;

                $orders = $tables['meli_orders']
                    ? $db->table('meli_orders')
                        ->select($orderSelectCols)
                        ->whereIn('id', $chunkIds)
                        ->orderBy('id')
                        ->get()
                    : collect();
            } else {
                $orders = $db->table('meli_orders')
                    ->select($orderSelectCols)
                    ->where('id', '>', $cursor)
                    ->when($accountFilter !== null, fn ($q) => $q->where('meli_account_id', $accountFilter))
                    ->orderBy('id')
                    ->limit($chunk)
                    ->get();

                if ($orders->isEmpty()) {
                    break;
                }
                $cursor = (int) $orders->last()->id;
                $orderStats['expected_distinct_orders'] += $orders->count();
            }

            if ($orders->isEmpty()) {
                continue;
            }

            if ($scopedOrderIds !== null && $accountFilter !== null) {
                $filtered = [];
                foreach ($orders as $o) {
                    $oid = (int) $o->id;
                    $foundScopedOrderIds[$oid] = true;
                    if ($o->meli_account_id !== null && (int) $o->meli_account_id === $accountFilter) {
                        $filtered[] = $o;
                    } else {
                        $orderStats['filtered_out_by_account_count']++;
                    }
                }
                if ($filtered === []) {
                    continue;
                }
                $orders = collect($filtered);
            } else {
                foreach ($orders as $o) {
                    $foundScopedOrderIds[(int) $o->id] = true;
                }
            }

            $batchOrderIds = $orders->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $batchRemoteIds = $orders->pluck('order_id')
                ->filter(static fn ($id): bool => $id !== null && trim((string) $id) !== '')
                ->map(static fn ($id): string => trim((string) $id))
                ->unique()
                ->values()
                ->all();

            // Optional read-only PK lookup on `jobs` (ONLY when --verify-live-jobs is set; NEVER loads payloads!)
            $liveJobsById = $hasLiveJobsTable
                ? $this->loadLiveJobsBySamplePk($db, $queueTable, $batchOrderIds, $orderJobSampleCsv)
                : [];

            // Batch Query 1: meli_order_items
            $itemsByOrderId = [];
            if ($tables['meli_order_items']) {
                $itemCols = ['id', 'meli_order_id', 'item_id', 'sku', 'quantity'];
                if ($columns['item_variation_id']) {
                    $itemCols[] = 'variation_id';
                }
                if ($columns['item_remote_line_key']) {
                    $itemCols[] = 'remote_line_key';
                }
                foreach ($db->table('meli_order_items')->whereIn('meli_order_id', $batchOrderIds)->get($itemCols) as $item) {
                    $itemsByOrderId[(int) $item->meli_order_id][] = $item;
                }
            }

            // Batch Query 2: inventory_channel_order_allocations
            $allocsByRemoteKey = [];
            $allocsByRemoteOrderId = [];
            $allocSimpleResIds = [];
            $allocKitResIds = [];
            if ($tables['inventory_channel_order_allocations'] && $batchRemoteIds !== []) {
                $allocRows = $db->table('inventory_channel_order_allocations')
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->whereIn('remote_order_id', $batchRemoteIds)
                    ->get();

                foreach ($allocRows as $alloc) {
                    $rOrderId = (string) $alloc->remote_order_id;
                    $key = ((string) $alloc->account_key).'|'.$rOrderId;
                    $allocsByRemoteKey[$key][] = $alloc;
                    $allocsByRemoteOrderId[$rOrderId][] = $alloc;
                    if ($alloc->reservation_id !== null) {
                        if ((string) ($alloc->reservation_kind ?? '') === 'KIT') {
                            $allocKitResIds[(int) $alloc->reservation_id] = true;
                        } else {
                            $allocSimpleResIds[(int) $alloc->reservation_id] = true;
                        }
                    }
                }
            }

            // Batch Query 3: inventory_reservations
            $reservationsById = [];
            $directResByOrderId = [];
            if ($tables['inventory_reservations']) {
                $resCols = ['id', 'source_type', 'source_id', 'inventory_product_id', 'quantity', 'status'];
                if ($columns['res_metadata']) {
                    $resCols[] = 'metadata';
                }

                $resRows = $db->table('inventory_reservations')
                    ->where(function ($q) use ($batchOrderIds, $allocSimpleResIds): void {
                        $q->where(function ($sub) use ($batchOrderIds): void {
                            $sub->where('source_type', 'meli_order')
                                ->whereIn('source_id', $batchOrderIds);
                        });
                        if ($allocSimpleResIds !== []) {
                            $q->orWhereIn('id', array_keys($allocSimpleResIds));
                        }
                    })
                    ->get($resCols);

                foreach ($resRows as $r) {
                    $reservationsById[(int) $r->id] = $r;
                    if ((string) ($r->source_type ?? '') === 'meli_order' && $r->source_id !== null) {
                        $directResByOrderId[(int) $r->source_id][] = $r;
                    }
                }
            }

            // Batch Query 4: inventory_kit_reservations
            $kitReservationsById = [];
            $directKitResByOrderId = [];
            if ($tables['inventory_kit_reservations']) {
                $kitResCols = ['id', 'source_type', 'source_id', 'kit_product_id', 'quantity', 'status'];
                if ($columns['kit_res_metadata']) {
                    $kitResCols[] = 'metadata';
                }

                $kitResRows = $db->table('inventory_kit_reservations')
                    ->where(function ($q) use ($batchOrderIds, $allocKitResIds): void {
                        $q->where(function ($sub) use ($batchOrderIds): void {
                            $sub->where('source_type', 'meli_order')
                                ->whereIn('source_id', $batchOrderIds);
                        });
                        if ($allocKitResIds !== []) {
                            $q->orWhereIn('id', array_keys($allocKitResIds));
                        }
                    })
                    ->get($kitResCols);

                foreach ($kitResRows as $r) {
                    $kitReservationsById[(int) $r->id] = $r;
                    if ((string) ($r->source_type ?? '') === 'meli_order' && $r->source_id !== null) {
                        $directKitResByOrderId[(int) $r->source_id][] = $r;
                    }
                }
            }

            $allBatchProductIds = [];
            $batchFulfillmentKeys = [];
            foreach ($orders as $order) {
                $oid = (int) $order->id;
                $accStr = (string) ($order->meli_account_id ?? '');
                $remStr = trim((string) ($order->order_id ?? ''));
                $orderItems = $itemsByOrderId[$oid] ?? [];
                $allocs = $allocsByRemoteKey[$accStr.'|'.$remStr] ?? [];

                foreach ($allocs as $a) {
                    if ($a->inventory_product_id !== null) {
                        $pid = (int) $a->inventory_product_id;
                        $allBatchProductIds[$pid] = true;
                        foreach ($kitComponentsByKitId[$pid] ?? [] as $cpid) {
                            $allBatchProductIds[$cpid] = true;
                        }
                    }
                }
                foreach ($directResByOrderId[$oid] ?? [] as $dr) {
                    if ($dr->inventory_product_id !== null) {
                        $allBatchProductIds[(int) $dr->inventory_product_id] = true;
                    }
                }
                foreach ($directKitResByOrderId[$oid] ?? [] as $dkr) {
                    if ($dkr->kit_product_id !== null) {
                        $kpid = (int) $dkr->kit_product_id;
                        $allBatchProductIds[$kpid] = true;
                        foreach ($kitComponentsByKitId[$kpid] ?? [] as $cpid) {
                            $allBatchProductIds[$cpid] = true;
                        }
                    }
                }
                foreach ($orderItems as $oi) {
                    $var = $columns['item_variation_id'] && $oi->variation_id !== null ? trim((string) $oi->variation_id) : '';
                    $lk = $accStr.'|'.trim((string) ($oi->item_id ?? '')).'|'.$var;
                    foreach ($allLinksByKey[$lk] ?? [] as $ml) {
                        $pid = (int) $ml['inventory_product_id'];
                        $allBatchProductIds[$pid] = true;
                        foreach ($kitComponentsByKitId[$pid] ?? [] as $cpid) {
                            $allBatchProductIds[$cpid] = true;
                        }
                    }

                    if ($columns['mov_external_key'] && $remStr !== '') {
                        $lineKey = $columns['item_remote_line_key'] && $oi->remote_line_key !== null ? trim((string) $oi->remote_line_key) : '';
                        $identityLine = $lineKey !== '' ? $lineKey : 'legacy-row:'.(int) $oi->id;
                        $identityHash = hash('sha256', implode("\0", ['mercado_libre', $accStr, $remStr, $identityLine]));
                        $batchFulfillmentKeys[] = 'meli-order:'.$identityHash.':fulfill';
                        $batchFulfillmentKeys[] = 'meli-order:'.$remStr.':line:'.($lineKey !== '' ? $lineKey : (string) $oi->id).':fulfill';
                    }
                }
            }

            // Batch Query 5: fulfillment movements by unique `external_key`
            $existingFulfillmentKeys = [];
            if ($tables['inventory_movements'] && $columns['mov_external_key'] && $batchFulfillmentKeys !== []) {
                foreach (array_chunk(array_values(array_unique($batchFulfillmentKeys)), 500) as $keyChunk) {
                    foreach ($db->table('inventory_movements')->whereIn('external_key', $keyChunk)->pluck('external_key') as $fk) {
                        if (is_string($fk) && $fk !== '') {
                            $existingFulfillmentKeys[$fk] = true;
                        }
                    }
                }
            }

            // Batch Query 6: manual non-reservation movements
            $manualMovementsByProduct = [];
            if ($tables['inventory_movements'] && $allBatchProductIds !== []) {
                $movCols = ['id', 'inventory_product_id', 'type', 'quantity', 'reference_type', 'created_at'];
                if ($columns['mov_occurred_at']) {
                    $movCols[] = 'occurred_at';
                }
                if ($columns['mov_reference_id']) {
                    $movCols[] = 'reference_id';
                }
                if ($columns['mov_reference']) {
                    $movCols[] = 'reference';
                }
                if ($columns['mov_notes']) {
                    $movCols[] = 'notes';
                }

                $movs = $db->table('inventory_movements')
                    ->whereIn('inventory_product_id', array_keys($allBatchProductIds))
                    ->where(function ($q): void {
                        $q->whereNull('reference_type')
                            ->orWhereNotIn('reference_type', ['inventory_reservation', 'inventory_kit_reservation']);
                    })
                    ->orderByDesc('id')
                    ->get($movCols);

                foreach ($movs as $m) {
                    $manualMovementsByProduct[(int) $m->inventory_product_id][] = $m;
                }
            }

            // Batch Query 7: R3 durable reconciliation state
            $r3ByOrderId = [];
            if ($tables['inventory_meli_order_reconciliations']) {
                foreach ($db->table('inventory_meli_order_reconciliations')->whereIn('meli_order_id', $batchOrderIds)->get() as $r3) {
                    $r3ByOrderId[(int) $r3->meli_order_id] = $r3;
                }
            }

            foreach ($orders as $order) {
                $oid = (int) $order->id;
                $accStr = (string) ($order->meli_account_id ?? '');
                $remStr = trim((string) ($order->order_id ?? ''));

                $allocs = $allocsByRemoteKey[$accStr.'|'.$remStr] ?? [];
                $crossAccountAllocs = [];
                if ($remStr !== '' && isset($allocsByRemoteOrderId[$remStr])) {
                    foreach ($allocsByRemoteOrderId[$remStr] as $ca) {
                        if ((string) $ca->account_key !== $accStr) {
                            $crossAccountAllocs[] = $ca;
                        }
                    }
                }

                $jobsCount = $orderJobCounts[$oid] ?? 0;
                $unreservedCount = $orderUnreservedCounts[$oid] ?? 0;
                $reservedCount = $orderReservedCounts[$oid] ?? 0;

                $jobIds = $this->parseCsvIds($orderJobSampleCsv[$oid] ?? '');
                $unreservedJobIds = $this->parseCsvIds($orderUnreservedSampleCsv[$oid] ?? '');
                $reservedJobIds = $this->parseCsvIds($orderReservedSampleCsv[$oid] ?? '');

                $liveCheck = $hasLiveJobsTable
                    ? $this->summarizeLiveQueueForOrder($jobIds, $reservedCount, $liveJobsById)
                    : null;

                $result = $this->orderClassifier->classifyExistingOrder($order, [
                    'associated_job_ids' => $jobIds,
                    'associated_jobs_count' => $jobsCount,
                    'associated_job_ids_truncated' => $jobsCount > count($jobIds),
                    'associated_unreserved_job_ids' => $unreservedJobIds,
                    'associated_unreserved_jobs_count' => $unreservedCount,
                    'associated_unreserved_job_ids_truncated' => $unreservedCount > count($unreservedJobIds),
                    'associated_reserved_job_ids' => $reservedJobIds,
                    'associated_reserved_job_ids_truncated' => $reservedCount > count($reservedJobIds),
                    'reserved_jobs_count' => $reservedCount,
                    'manifest_account_hint' => $orderAccountHints[$oid] ?? null,
                    'manifest_remote_order_hint' => $orderRemoteHints[$oid] ?? null,
                    'manifest_metadata_conflict' => isset($orderMetaConflicts[$oid]),
                    'live_queue_verification' => $liveCheck,
                    'order_items' => $itemsByOrderId[$oid] ?? [],
                    'allocations' => $allocs,
                    'cross_account_allocations' => $crossAccountAllocs,
                    'reservations_by_id' => $reservationsById,
                    'kit_reservations_by_id' => $kitReservationsById,
                    'direct_reservations' => $directResByOrderId[$oid] ?? [],
                    'direct_kit_reservations' => $directKitResByOrderId[$oid] ?? [],
                    'existing_fulfillment_keys' => $existingFulfillmentKeys,
                    'manual_movements_by_product' => $manualMovementsByProduct,
                    'all_links_by_key' => $allLinksByKey,
                    'active_link_accounts_by_listing' => $activeLinkAccountsByListing,
                    'shared_stock_conflict_groups' => $sharedStockConflictGroups,
                    'products_by_id' => $productsById,
                    'kit_product_ids' => $kitProductIds,
                    'component_product_ids' => $componentProductIds,
                    'kit_components_by_kit_id' => $kitComponentsByKitId,
                    'valid_account_ids' => $validAccountIds,
                    'tables' => $tables,
                    'columns' => $columns,
                    'cutover_at' => $cutoverAt,
                    'now_utc' => $nowUtc,
                    'r3_reconciliation' => $r3ByOrderId[$oid] ?? null,
                ]);

                $this->recordClassifiedOrder(
                    $result,
                    $tables['inventory_meli_order_reconciliations'],
                    $maxInlineEntities,
                    $classifiedOrderIds,
                    $categories,
                    $categoryCounts,
                    $schemaAnomalies,
                    $schemaAnomalyCounts,
                    $classification,
                    $classificationCounts,
                    $primaryPartitionCounts,
                    $secondaryAnomalyCounts,
                    $reasonCodeCounts,
                    $r3StateCounts,
                    $jobsByPrimaryCategory,
                    $jobSecurityCounters,
                    $inlineEntities,
                    $inlineTruncated,
                    $ndjsonStream,
                    $orderStats,
                );
            }
        }

        // Account for EVERY manifest-scoped order that does not exist in `meli_orders` (including reserved-only!)
        if ($scopedOrderIds !== null && $accountFilter === null) {
            $missingIds = [];
            foreach ($scopedOrderIds as $scopedId) {
                if (! isset($foundScopedOrderIds[$scopedId])) {
                    $missingIds[] = $scopedId;
                }
            }

            $missingLiveJobsById = ($hasLiveJobsTable && $missingIds !== [])
                ? $this->loadLiveJobsBySamplePk($db, $queueTable, $missingIds, $orderJobSampleCsv)
                : [];

            foreach ($missingIds as $scopedId) {
                $jobsCount = $orderJobCounts[$scopedId] ?? 0;
                $unreservedCount = $orderUnreservedCounts[$scopedId] ?? 0;
                $reservedCount = $orderReservedCounts[$scopedId] ?? 0;
                $jobIds = $this->parseCsvIds($orderJobSampleCsv[$scopedId] ?? '');
                $unreservedJobIds = $this->parseCsvIds($orderUnreservedSampleCsv[$scopedId] ?? '');
                $reservedJobIds = $this->parseCsvIds($orderReservedSampleCsv[$scopedId] ?? '');
                $liveCheck = $hasLiveJobsTable
                    ? $this->summarizeLiveQueueForOrder($jobIds, $reservedCount, $missingLiveJobsById)
                    : null;

                $result = $this->orderClassifier->classifyMissingOrder(
                    $scopedId,
                    $jobIds,
                    $jobsCount,
                    $jobsCount > count($jobIds),
                    $reservedCount,
                    $tables['inventory_meli_order_reconciliations'],
                    $unreservedJobIds,
                    $unreservedCount,
                    $unreservedCount > count($unreservedJobIds),
                    $reservedJobIds,
                    $reservedCount > count($reservedJobIds),
                    isset($orderMetaConflicts[$scopedId]),
                    $liveCheck,
                );

                $orderStats['missing_orders_count']++;
                $jobSecurityCounters['jobs_for_missing_orders'] += $jobsCount;

                $this->recordClassifiedOrder(
                    $result,
                    $tables['inventory_meli_order_reconciliations'],
                    $maxInlineEntities,
                    $classifiedOrderIds,
                    $categories,
                    $categoryCounts,
                    $schemaAnomalies,
                    $schemaAnomalyCounts,
                    $classification,
                    $classificationCounts,
                    $primaryPartitionCounts,
                    $secondaryAnomalyCounts,
                    $reasonCodeCounts,
                    $r3StateCounts,
                    $jobsByPrimaryCategory,
                    $jobSecurityCounters,
                    $inlineEntities,
                    $inlineTruncated,
                    $ndjsonStream,
                    $orderStats,
                );
            }
        }
    }

    /**
     * Optional read-only PK lookup on `jobs` (selects ONLY id, queue, reserved_at, attempts — NEVER payload!).
     *
     * @param  list<int>  $orderIds
     * @param  array<int, string>  $orderJobSampleCsv
     * @return array<int, object>
     */
    private function loadLiveJobsBySamplePk(
        ConnectionInterface $db,
        string $queueTable,
        array $orderIds,
        array $orderJobSampleCsv,
    ): array {
        $sampleIds = [];
        foreach ($orderIds as $oid) {
            foreach ($this->parseCsvIds($orderJobSampleCsv[$oid] ?? '') as $jid) {
                $sampleIds[$jid] = true;
            }
        }

        if ($sampleIds === []) {
            return [];
        }

        $rowsById = [];
        foreach (array_chunk(array_keys($sampleIds), 500) as $idChunk) {
            foreach ($db->table($queueTable)->select(['id', 'queue', 'reserved_at', 'attempts'])->whereIn('id', $idChunk)->get() as $row) {
                $rowsById[(int) $row->id] = $row;
            }
        }

        return $rowsById;
    }

    /**
     * @param  list<int>  $sampleJobIds
     * @param  array<int, object>  $liveJobsById
     * @return array<string, mixed>
     */
    private function summarizeLiveQueueForOrder(array $sampleJobIds, int $manifestReservedCount, array $liveJobsById): array
    {
        $presentInLive = 0;
        $absentInLive = 0;
        $currentlyReservedInLive = 0;

        foreach ($sampleJobIds as $jid) {
            if (! isset($liveJobsById[$jid])) {
                $absentInLive++;

                continue;
            }
            $presentInLive++;
            if ($liveJobsById[$jid]->reserved_at !== null) {
                $currentlyReservedInLive++;
            }
        }

        return [
            'sampled_job_ids_checked' => count($sampleJobIds),
            'present_in_live_jobs' => $presentInLive,
            'absent_in_live_jobs' => $absentInLive,
            'currently_reserved_in_live_jobs' => $currentlyReservedInLive,
            'manifest_vs_live_reserved_differs' => $currentlyReservedInLive !== $manifestReservedCount,
        ];
    }

    /** @return list<int> */
    private function parseCsvIds(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        return array_map('intval', explode(',', $csv));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, bool>  $classifiedOrderIds
     * @param  array<string, list<array<string, mixed>>>  $categories
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, list<array<string, mixed>>>  $schemaAnomalies
     * @param  array<string, int>  $schemaAnomalyCounts
     * @param  array<string, list<array<string, mixed>>>  $classification
     * @param  array<string, int>  $classificationCounts
     * @param  array<string, int>  $primaryPartitionCounts
     * @param  array<string, int>  $secondaryAnomalyCounts
     * @param  array<string, int>  $reasonCodeCounts
     * @param  array<string, int>  $r3StateCounts
     * @param  array<string, int>  $jobsByPrimaryCategory
     * @param  array<string, int>  $jobSecurityCounters
     * @param  list<array<string, mixed>>  $inlineEntities
     * @param  array<string, mixed>|null  $ndjsonStream
     * @param  array<string, int>  $orderStats
     */
    private function recordClassifiedOrder(
        array $result,
        bool $r3TablePresent,
        int $maxInlineEntities,
        array &$classifiedOrderIds,
        array &$categories,
        array &$categoryCounts,
        array &$schemaAnomalies,
        array &$schemaAnomalyCounts,
        array &$classification,
        array &$classificationCounts,
        array &$primaryPartitionCounts,
        array &$secondaryAnomalyCounts,
        array &$reasonCodeCounts,
        array &$r3StateCounts,
        array &$jobsByPrimaryCategory,
        array &$jobSecurityCounters,
        array &$inlineEntities,
        bool &$inlineTruncated,
        ?array &$ndjsonStream,
        array &$orderStats,
    ): void {
        $entity = $result['entity'];
        $flags = $result['legacy_flags'];
        $meta = $result['summary_meta'];

        $oid = (int) $entity['local_order_id'];
        $primary = (string) $entity['primary_classification'];
        $jobsCount = (int) $entity['associated_jobs_count'];
        $unreservedCount = (int) ($entity['associated_unreserved_jobs_count'] ?? $jobsCount);
        $reservedCount = (int) ($entity['associated_reserved_jobs_count'] ?? 0);

        if (isset($classifiedOrderIds[$oid])) {
            $orderStats['duplicate_primary_classifications']++;

            return;
        }
        $classifiedOrderIds[$oid] = true;

        $orderStats['accounted_orders']++;
        $orderStats['accounted_order_jobs'] += $jobsCount;
        $orderStats['accounted_unreserved_order_jobs'] += $unreservedCount;
        $orderStats['accounted_reserved_order_jobs'] += $reservedCount;

        if (! array_key_exists($primary, $primaryPartitionCounts)) {
            throw new QueueRecoveryException(
                "Clasificación principal desconocida '{$primary}' para pedido #{$oid}.",
                'diagnostic_invariant_violation',
            );
        }

        $primaryPartitionCounts[$primary]++;
        $jobsByPrimaryCategory[$primary] += $jobsCount;
        if (! empty($meta['has_pending_obligation'])) {
            $jobSecurityCounters['jobs_for_pending_obligations'] += $jobsCount;
        }

        foreach ($entity['secondary_anomalies'] as $anomalyCode) {
            $secondaryAnomalyCounts[$anomalyCode] = ($secondaryAnomalyCounts[$anomalyCode] ?? 0) + 1;
        }
        foreach ($entity['reason_codes'] as $rc) {
            $reasonCodeCounts[$rc] = ($reasonCodeCounts[$rc] ?? 0) + 1;
        }

        if ($r3TablePresent) {
            $r3Ev = $entity['available_evidence']['r3_reconciliation'] ?? null;
            if (! is_array($r3Ev)) {
                $r3StateCounts['ABSENT_ROW']++;
            } else {
                $st = (string) ($r3Ev['state'] ?? 'IDLE');
                if (isset($r3StateCounts[$st])) {
                    $r3StateCounts[$st]++;
                }
                if (! empty($r3Ev['lease_expired'])) {
                    $r3StateCounts['EXPIRED_LEASES']++;
                }
                if (! empty($r3Ev['stock_sync_required'])) {
                    $r3StateCounts['STOCK_SYNC_REQUIRED']++;
                }
                if ((int) ($r3Ev['requested_revision'] ?? 0) > (int) ($r3Ev['processed_revision'] ?? 0)) {
                    $r3StateCounts['PENDING_REVISION_UNPROCESSED']++;
                }
            }
        }

        // D3: Incremental write with partial-write check + SHA-256 update!
        if ($ndjsonStream !== null) {
            $line = json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $this->artifactWriter->writeStreamChunk($ndjsonStream, $line, countRecord: true);
        }

        if (count($inlineEntities) < $maxInlineEntities) {
            $inlineEntities[] = $entity;
        } elseif ($maxInlineEntities >= 0) {
            $inlineTruncated = true;
        }

        if ($primary === QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW) {
            $classificationCounts['pending_human_review']++;
            if (count($classification['pending_human_review']) < $maxInlineEntities) {
                $classification['pending_human_review'][] = [
                    'entity_type' => 'meli_order',
                    'entity_id' => $oid,
                    'meli_account_id' => $entity['meli_account_id'],
                    'reasons' => $entity['reason_codes'],
                ];
            }
        } elseif ($primary === QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES) {
            $classificationCounts['ambiguous_entities']++;
            if (count($classification['ambiguous_entities']) < $maxInlineEntities) {
                $classification['ambiguous_entities'][] = [
                    'entity_type' => 'meli_order',
                    'entity_id' => $oid,
                    'meli_account_id' => $entity['meli_account_id'],
                    'reasons' => $entity['reason_codes'],
                ];
            }
        } elseif ($primary === QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES) {
            $classificationCounts['reconstructible_candidates']++;
            if (count($classification['reconstructible_candidates']) < $maxInlineEntities) {
                $classification['reconstructible_candidates'][] = [
                    'entity_type' => 'meli_order',
                    'entity_id' => $oid,
                    'meli_account_id' => $entity['meli_account_id'],
                    'candidate_action' => $entity['operational_recommendation']['candidate_action'],
                    'automatic_execution_allowed' => false,
                ];
            }
        }

        if (! empty($flags['missing_order'])) {
            $schemaAnomalyCounts['missing_orders_in_manifest']++;
            if (count($schemaAnomalies['missing_orders_in_manifest']) < $maxInlineEntities) {
                $schemaAnomalies['missing_orders_in_manifest'][] = [
                    'meli_order_id' => $oid,
                    'reason' => 'order_not_found_in_meli_orders',
                ];
            }

            return;
        }

        $summary = $flags['summary'];

        if (! empty($flags['account_mismatch'])) {
            $schemaAnomalyCounts['account_mismatches']++;
            if (count($schemaAnomalies['account_mismatches']) < $maxInlineEntities) {
                $schemaAnomalies['account_mismatches'][] = [
                    'entity_type' => 'meli_order',
                    'entity_id' => $oid,
                    'meli_account_id' => $entity['meli_account_id'],
                ];
            }
        }
        if (! empty($flags['ambiguous_link_keys'])) {
            $schemaAnomalyCounts['ambiguous_channel_links']++;
            if (count($schemaAnomalies['ambiguous_channel_links']) < $maxInlineEntities) {
                $schemaAnomalies['ambiguous_channel_links'][] = [
                    ...$summary,
                    'ambiguous_links' => $flags['ambiguous_link_keys'],
                ];
            }
        }
        if ((int) ($flags['missing_line_key_count'] ?? 0) > 0) {
            $schemaAnomalyCounts['missing_remote_line_key_orders']++;
            if (count($schemaAnomalies['missing_remote_line_key_orders']) < $maxInlineEntities) {
                $schemaAnomalies['missing_remote_line_key_orders'][] = [
                    ...$summary,
                    'items_missing_remote_line_key' => (int) $flags['missing_line_key_count'],
                ];
            }
        }
        if (! empty($flags['is_cancelled_with_active'])) {
            $categoryCounts['cancelled_orders_with_active_reservations']++;
            if (count($categories['cancelled_orders_with_active_reservations']) < $maxInlineEntities) {
                $categories['cancelled_orders_with_active_reservations'][] = $summary;
            }
        }
        if (! empty($flags['is_shipped_with_active'])) {
            $categoryCounts['shipped_orders_with_active_reservations']++;
            if (count($categories['shipped_orders_with_active_reservations']) < $maxInlineEntities) {
                $categories['shipped_orders_with_active_reservations'][] = $summary;
            }
        }
        if (! empty($flags['is_paid_pending'])) {
            $categoryCounts['paid_orders_pending_reservation']++;
            if (count($categories['paid_orders_pending_reservation']) < $maxInlineEntities) {
                $categories['paid_orders_pending_reservation'][] = $summary;
            }
        }
        if (! empty($flags['is_shipped_never_reserved'])) {
            $categoryCounts['shipped_during_outage_never_reserved']++;
            if (count($categories['shipped_during_outage_never_reserved']) < $maxInlineEntities) {
                $categories['shipped_during_outage_never_reserved'][] = [
                    ...$summary,
                    'requires_manual_verification' => true,
                ];
            }
        }
        if (! empty($flags['matched_manual_movements'])) {
            $categoryCounts['orders_with_manual_movements']++;
            if (count($categories['orders_with_manual_movements']) < $maxInlineEntities) {
                $categories['orders_with_manual_movements'][] = [
                    ...$summary,
                    'manual_movements_count' => count($flags['matched_manual_movements']),
                    'manual_movements_sample' => array_slice($flags['matched_manual_movements'], 0, 5),
                    'requires_manual_verification' => true,
                ];
            }
        }
        if (! empty($flags['is_multiline'])) {
            $categoryCounts['multiline_orders']++;
            if (count($categories['multiline_orders']) < $maxInlineEntities) {
                $categories['multiline_orders'][] = [
                    ...$summary,
                    'requires_manual_verification' => true,
                ];
            }
        }
        if (! empty($flags['involves_kit_or_component'])) {
            $categoryCounts['kits_and_components']++;
            if (count($categories['kits_and_components']) < $maxInlineEntities) {
                $categories['kits_and_components'][] = [
                    ...$summary,
                    'kit_product_ids' => array_values(array_unique($flags['kit_pids'] ?? [])),
                    'component_product_ids' => array_values(array_unique($flags['component_pids'] ?? [])),
                    'has_active_kit_reservation' => (bool) ($flags['has_active_kit_reservation'] ?? false),
                    'requires_manual_verification' => true,
                ];
            }
        }
    }

    /**
     * Evaluates and verifies ALL mandatory R2-DIAG.1 invariants fail-closed (with zero double counting).
     *
     * @param  array<string, mixed>|null  $manifestData
     * @param  array<string, int>  $orderStats
     * @param  array<string, int>  $primaryPartitionCounts
     * @return array<string, mixed>
     */
    private function evaluateAndVerifyInvariants(
        ?array $manifestData,
        ?int $accountFilter,
        array $orderStats,
        array $primaryPartitionCounts,
        bool $ndjsonEnabled,
        int $ndjsonRecordsWritten,
    ): array {
        $expectedDistinctOrders = ($manifestData !== null && $accountFilter !== null)
            ? max(0, $orderStats['expected_distinct_orders'] - $orderStats['filtered_out_by_account_count'])
            : $orderStats['expected_distinct_orders'];

        $accountedOrders = $orderStats['accounted_orders'];
        $primaryPartitionSum = array_sum($primaryPartitionCounts);
        $unclassifiedOrders = $expectedDistinctOrders - $accountedOrders;
        $duplicatePrimary = $orderStats['duplicate_primary_classifications'];

        $invariants = [
            'scope_mode' => $manifestData !== null ? 'manifest' : 'database_scan',
            'distinct_orders_in_scope' => $expectedDistinctOrders,
            'distinct_orders_in_manifest' => $manifestData !== null ? (int) $manifestData['job_accounting']['distinct_orders_in_manifest'] : null,
            'accounted_orders' => $accountedOrders,
            'unclassified_orders' => $unclassifiedOrders,
            'sum_primary_partition_counts' => $primaryPartitionSum,
            'duplicate_primary_classifications' => $duplicatePrimary,
            'missing_orders_accounted' => $orderStats['missing_orders_count'],
            'accounted_order_jobs' => $orderStats['accounted_order_jobs'],
            'accounted_unreserved_order_jobs' => $orderStats['accounted_unreserved_order_jobs'],
            'accounted_reserved_order_jobs' => $orderStats['accounted_reserved_order_jobs'],
            'total_manifest_entries' => $manifestData !== null ? (int) $manifestData['job_accounting']['total_manifest_entries'] : null,
            'ndjson_records_written' => $ndjsonEnabled ? $ndjsonRecordsWritten : null,
            'all_passed' => true,
            'violations' => [],
        ];

        if ($this->invariantCheckMutator !== null) {
            $invariants = ($this->invariantCheckMutator)($invariants);
        }

        $violations = [];

        if ($invariants['accounted_orders'] !== $invariants['distinct_orders_in_scope']) {
            $violations[] = sprintf(
                'accounted_orders (%d) != distinct_orders_in_scope (%d)',
                $invariants['accounted_orders'],
                $invariants['distinct_orders_in_scope'],
            );
        }

        if ($invariants['unclassified_orders'] !== 0) {
            $violations[] = sprintf('unclassified_orders (%d) != 0', $invariants['unclassified_orders']);
        }

        if ($invariants['sum_primary_partition_counts'] !== $invariants['distinct_orders_in_scope']) {
            $violations[] = sprintf(
                'sum(primary_partition_counts) (%d) != distinct_orders_in_scope (%d)',
                $invariants['sum_primary_partition_counts'],
                $invariants['distinct_orders_in_scope'],
            );
        }

        if ($invariants['duplicate_primary_classifications'] !== 0) {
            $violations[] = sprintf(
                'duplicate_primary_classifications (%d) != 0',
                $invariants['duplicate_primary_classifications'],
            );
        }

        if ($manifestData !== null && $accountFilter === null) {
            $ja = $manifestData['job_accounting'];
            $expectedTotalOrderJobs = (int) ($ja['total_order_job_entries'] ?? $ja['reservation_job_entries']);
            $expectedUnreservedOrderJobs = (int) ($ja['reservation_unreserved_job_entries'] ?? $ja['reservation_job_entries']);
            $expectedReservedOrderJobs = (int) ($ja['reservation_reserved_job_entries'] ?? 0);
            $nonOrderReservedJobs = (int) ($ja['non_order_reserved_job_entries'] ?? 0);
            $otherClassJobs = (int) ($ja['other_class_job_entries'] ?? 0);

            // Disjoint sum across EVERY manifest entry (zero double counting!):
            $reconciledManifestEntries = $invariants['accounted_order_jobs']
                + (int) $ja['beauty_job_entries']
                + $nonOrderReservedJobs
                + $otherClassJobs
                + (int) $ja['invalid_or_unverifiable_job_entries']
                + (int) $ja['duplicate_manifest_job_id_entries'];

            if ($reconciledManifestEntries !== (int) $ja['total_manifest_entries']) {
                $violations[] = sprintf(
                    'manifest_job_total_mismatch: reconciled (%d) != total_manifest_entries (%d)',
                    $reconciledManifestEntries,
                    (int) $ja['total_manifest_entries'],
                );
            }

            if ($invariants['accounted_order_jobs'] !== $expectedTotalOrderJobs) {
                $violations[] = sprintf(
                    'accounted_order_jobs (%d) != total_order_job_entries (%d)',
                    $invariants['accounted_order_jobs'],
                    $expectedTotalOrderJobs,
                );
            }

            if ($invariants['accounted_unreserved_order_jobs'] !== $expectedUnreservedOrderJobs
                || $invariants['accounted_reserved_order_jobs'] !== $expectedReservedOrderJobs) {
                $violations[] = sprintf(
                    'order_job_reserved_split_mismatch: accounted=(%d unres, %d res) != manifest=(%d unres, %d res)',
                    $invariants['accounted_unreserved_order_jobs'],
                    $invariants['accounted_reserved_order_jobs'],
                    $expectedUnreservedOrderJobs,
                    $expectedReservedOrderJobs,
                );
            }
        }

        if ($ndjsonEnabled && $invariants['ndjson_records_written'] !== $invariants['distinct_orders_in_scope']) {
            $violations[] = sprintf(
                'ndjson_records_written (%d) != distinct_orders_in_scope (%d)',
                (int) $invariants['ndjson_records_written'],
                $invariants['distinct_orders_in_scope'],
            );
        }

        if ($violations !== []) {
            $invariants['all_passed'] = false;
            $invariants['violations'] = $violations;

            throw new QueueRecoveryException(
                'Fallo de invariantes obligatorias de diagnóstico exhaustivo: '.implode('; ', $violations),
                'diagnostic_invariant_violation',
                ['invariants' => $invariants],
            );
        }

        return $invariants;
    }

    /**
     * @param  array<string, bool>  $tables
     * @param  array<int, bool>  $validAccountIds
     * @param  list<int>|null  $scopedDiscountIds
     * @param  array<string, list<array<string, mixed>>>  $categories
     * @param  array<string, int>  $categoryCounts
     * @param  array<string, list<array<string, mixed>>>  $schemaAnomalies
     * @param  array<string, int>  $schemaAnomalyCounts
     * @param  array<string, list<array<string, mixed>>>  $classification
     * @param  array<string, int>  $classificationCounts
     */
    private function diagnoseBeautyPromotions(
        ConnectionInterface $db,
        array $tables,
        array $validAccountIds,
        ?array $scopedDiscountIds,
        ?int $accountFilter,
        int $maxInlineEntities,
        array &$categories,
        array &$categoryCounts,
        array &$schemaAnomalies,
        array &$schemaAnomalyCounts,
        array &$classification,
        array &$classificationCounts,
    ): void {
        $rulesQuery = $db->table('meli_beauty_scheduled_discounts')->orderBy('id');
        if ($scopedDiscountIds !== null) {
            if ($scopedDiscountIds === []) {
                return;
            }
            $rulesQuery->whereIn('id', $scopedDiscountIds);
        }
        if ($accountFilter !== null) {
            $rulesQuery->where('meli_account_id', $accountFilter);
        }

        $rules = $rulesQuery->get();
        $foundRuleIds = [];
        foreach ($rules as $r) {
            $foundRuleIds[(int) $r->id] = true;
        }

        $deletedRuleIds = [];
        if ($scopedDiscountIds !== null && $accountFilter === null) {
            foreach ($scopedDiscountIds as $did) {
                if (! isset($foundRuleIds[$did])) {
                    $deletedRuleIds[$did] = 'referenced_in_manifest_but_deleted';
                }
            }
        }

        if ($tables['meli_scheduled_price_states']) {
            $orphanStatesQuery = $db->table('meli_scheduled_price_states')
                ->whereIn('status', ['active', 'restore_pending', 'failed']);
            if ($scopedDiscountIds !== null) {
                $orphanStatesQuery->whereIn('meli_beauty_scheduled_discount_id', $scopedDiscountIds);
            }
            foreach ($orphanStatesQuery->get() as $st) {
                $did = (int) $st->meli_beauty_scheduled_discount_id;
                if (! isset($foundRuleIds[$did]) && ! $db->table('meli_beauty_scheduled_discounts')->where('id', $did)->exists()) {
                    $deletedRuleIds[$did] = 'orphaned_scheduled_price_state_without_rule';
                    $categoryCounts['remote_states_requiring_verification']++;
                    if (count($categories['remote_states_requiring_verification']) < $maxInlineEntities) {
                        $categories['remote_states_requiring_verification'][] = [
                            'discount_id' => $did,
                            'meli_account_id' => null,
                            'price_manager_item_id' => (int) $st->price_manager_item_id,
                            'meli_item_id' => null,
                            'sku' => null,
                            'local_state_status' => (string) $st->status,
                            'window_status' => 'rule_deleted',
                            'base_price' => $st->base_price !== null ? (float) $st->base_price : null,
                            'promotional_price' => $st->promotional_price !== null ? (float) $st->promotional_price : null,
                            'failure_message' => $st->failure_message !== null ? mb_substr((string) $st->failure_message, 0, 200) : null,
                            'recent_failed_changes' => 0,
                            'requires_remote_verification' => true,
                        ];
                    }
                }
            }
        }

        foreach ($deletedRuleIds as $did => $reason) {
            $schemaAnomalyCounts['deleted_beauty_rules']++;
            if (count($schemaAnomalies['deleted_beauty_rules']) < $maxInlineEntities) {
                $schemaAnomalies['deleted_beauty_rules'][] = [
                    'discount_id' => $did,
                    'reason' => $reason,
                ];
            }
            $classificationCounts['pending_human_review']++;
            if (count($classification['pending_human_review']) < $maxInlineEntities) {
                $classification['pending_human_review'][] = [
                    'entity_type' => 'beauty_discount',
                    'entity_id' => $did,
                    'meli_account_id' => null,
                    'reasons' => [$reason],
                ];
            }
        }

        if ($rules->isEmpty()) {
            return;
        }

        $ruleIds = array_keys($foundRuleIds);

        $explicitItemsByRule = [];
        if ($tables['meli_beauty_scheduled_discount_items']) {
            $pivotRows = $db->table('meli_beauty_scheduled_discount_items')
                ->whereIn('meli_beauty_scheduled_discount_id', $ruleIds)
                ->get(['meli_beauty_scheduled_discount_id', 'price_manager_item_id']);
            foreach ($pivotRows as $pr) {
                $explicitItemsByRule[(int) $pr->meli_beauty_scheduled_discount_id][(int) $pr->price_manager_item_id] = true;
            }
        }

        $statesByRule = [];
        if ($tables['meli_scheduled_price_states']) {
            $states = $db->table('meli_scheduled_price_states')
                ->whereIn('meli_beauty_scheduled_discount_id', $ruleIds)
                ->get();
            foreach ($states as $s) {
                $statesByRule[(int) $s->meli_beauty_scheduled_discount_id][] = $s;
            }
        }

        $itemsById = [];
        if ($tables['meli_price_manager_items'] && $tables['meli_scheduled_price_states']) {
            $itemIds = [];
            foreach ($statesByRule as $ruleStates) {
                foreach ($ruleStates as $s) {
                    $itemIds[(int) $s->price_manager_item_id] = true;
                }
            }
            if ($itemIds !== []) {
                foreach ($db->table('meli_price_manager_items')->whereIn('id', array_keys($itemIds))->get(['id', 'meli_item_id', 'sku']) as $it) {
                    $itemsById[(int) $it->id] = [
                        'meli_item_id' => (string) $it->meli_item_id,
                        'sku' => $it->sku !== null ? (string) $it->sku : null,
                    ];
                }
            }
        }

        $recentFailedChangesByItem = [];
        if ($tables['meli_price_changes'] && $tables['meli_price_manager_items']) {
            $failedChanges = $db->table('meli_price_changes')
                ->where('source', 'scheduled_beauty')
                ->where('status', 'failed')
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'price_manager_item_id', 'scheduled_action', 'error_message', 'changed_at']);
            foreach ($failedChanges as $fc) {
                $recentFailedChangesByItem[(int) $fc->price_manager_item_id][] = $fc;
            }
        }

        foreach ($rules as $row) {
            $accountId = $row->meli_account_id !== null ? (int) $row->meli_account_id : null;
            $model = new MeliBeautyScheduledDiscount([
                'meli_account_id' => $row->meli_account_id,
                'brand_group_id' => $row->brand_group_id ?? null,
                'discount_percentage' => $row->discount_percentage ?? 0,
                'starts_on' => $row->starts_on ?? null,
                'ends_on' => $row->ends_on ?? null,
                'starts_at' => $row->starts_at ?? null,
                'ends_at' => $row->ends_at ?? null,
                'all_day' => (bool) ($row->all_day ?? false),
                'timezone' => $row->timezone ?? MeliBeautyPromotionWindow::TIMEZONE,
                'active' => (bool) ($row->active ?? false),
            ]);
            $model->id = (int) $row->id;

            $windowStatus = $this->beautyWindow->status($model);
            $ruleStates = $statesByRule[$model->id] ?? [];
            $explicitItems = $explicitItemsByRule[$model->id] ?? [];

            $activeStates = array_values(array_filter($ruleStates, static fn ($s): bool => (string) $s->status === 'active'));
            $restorePendingStates = array_values(array_filter($ruleStates, static fn ($s): bool => (string) $s->status === 'restore_pending'));
            $failedStates = array_values(array_filter($ruleStates, static fn ($s): bool => (string) $s->status === 'failed'));

            $accountMismatch = $accountId === null || ($tables['meli_accounts'] && ! isset($validAccountIds[$accountId]));
            if ($accountMismatch) {
                $schemaAnomalyCounts['account_mismatches']++;
                if (count($schemaAnomalies['account_mismatches']) < $maxInlineEntities) {
                    $schemaAnomalies['account_mismatches'][] = [
                        'entity_type' => 'beauty_discount',
                        'entity_id' => $model->id,
                        'meli_account_id' => $accountId,
                    ];
                }
            }

            $orphanedItemIds = [];
            if ($explicitItems !== []) {
                foreach (array_merge($activeStates, $restorePendingStates, $failedStates) as $st) {
                    $pmItemId = (int) $st->price_manager_item_id;
                    if (! isset($explicitItems[$pmItemId])) {
                        $orphanedItemIds[] = $pmItemId;
                    }
                }
            }
            if ($orphanedItemIds !== []) {
                $schemaAnomalyCounts['modified_beauty_rules_orphaned_states']++;
                if (count($schemaAnomalies['modified_beauty_rules_orphaned_states']) < $maxInlineEntities) {
                    $schemaAnomalies['modified_beauty_rules_orphaned_states'][] = [
                        'discount_id' => $model->id,
                        'meli_account_id' => $accountId,
                        'orphaned_price_manager_item_ids' => array_values(array_unique($orphanedItemIds)),
                        'reason' => 'state_item_removed_from_meli_beauty_scheduled_discount_items',
                    ];
                }
            }

            $ruleSummary = [
                'discount_id' => $model->id,
                'meli_account_id' => $accountId,
                'active_flag' => (bool) ($row->active ?? false),
                'all_day' => (bool) ($row->all_day ?? false),
                'starts_on' => $row->starts_on ?? null,
                'ends_on' => $row->ends_on ?? null,
                'window_status' => $windowStatus,
                'explicit_items_count' => count($explicitItems),
                'states_active' => count($activeStates),
                'states_restore_pending' => count($restorePendingStates),
                'states_failed' => count($failedStates),
            ];

            if (((bool) ($row->active ?? false) && in_array($windowStatus, ['in_window', 'outside_hours', 'scheduled'], true))
                || $activeStates !== []) {
                $categoryCounts['active_beauty_promotions']++;
                if (count($categories['active_beauty_promotions']) < $maxInlineEntities) {
                    $categories['active_beauty_promotions'][] = $ruleSummary;
                }
            }

            $needsRestore = $restorePendingStates !== []
                || ($activeStates !== [] && in_array($windowStatus, ['disabled', 'finished', 'outside_hours'], true));
            if ($needsRestore) {
                $categoryCounts['beauty_promotions_pending_restore']++;
                if (count($categories['beauty_promotions_pending_restore']) < $maxInlineEntities) {
                    $categories['beauty_promotions_pending_restore'][] = [
                        ...$ruleSummary,
                        'items_needing_restore' => count($restorePendingStates) + ($windowStatus !== 'in_window' ? count($activeStates) : 0),
                    ];
                }
            }

            if ($windowStatus === 'finished') {
                $categoryCounts['expired_beauty_promotions']++;
                if (count($categories['expired_beauty_promotions']) < $maxInlineEntities) {
                    $categories['expired_beauty_promotions'][] = $ruleSummary;
                }
            }

            $remoteStatesCount = count($activeStates) + count($restorePendingStates) + count($failedStates);
            foreach (array_merge($activeStates, $restorePendingStates, $failedStates) as $st) {
                $itemInfo = $itemsById[(int) $st->price_manager_item_id] ?? ['meli_item_id' => null, 'sku' => null];
                $categoryCounts['remote_states_requiring_verification']++;
                if (count($categories['remote_states_requiring_verification']) < $maxInlineEntities) {
                    $categories['remote_states_requiring_verification'][] = [
                        'discount_id' => $model->id,
                        'meli_account_id' => $accountId,
                        'price_manager_item_id' => (int) $st->price_manager_item_id,
                        'meli_item_id' => $itemInfo['meli_item_id'],
                        'sku' => $itemInfo['sku'],
                        'local_state_status' => (string) $st->status,
                        'window_status' => $windowStatus,
                        'orphaned_from_rule_items' => in_array((int) $st->price_manager_item_id, $orphanedItemIds, true),
                        'base_price' => $st->base_price !== null ? (float) $st->base_price : null,
                        'promotional_price' => $st->promotional_price !== null ? (float) $st->promotional_price : null,
                        'failure_message' => $st->failure_message !== null ? mb_substr((string) $st->failure_message, 0, 200) : null,
                        'recent_failed_changes' => count($recentFailedChangesByItem[(int) $st->price_manager_item_id] ?? []),
                        'requires_remote_verification' => true,
                    ];
                }
            }

            if ($accountMismatch || $needsRestore || $failedStates !== [] || $orphanedItemIds !== [] || $remoteStatesCount > 0) {
                $reasons = [];
                if ($accountMismatch) {
                    $reasons[] = 'account_mismatch';
                }
                if ($needsRestore) {
                    $reasons[] = 'beauty_promotion_pending_restore';
                }
                if ($failedStates !== []) {
                    $reasons[] = 'beauty_promotion_failed_states';
                }
                if ($orphanedItemIds !== []) {
                    $reasons[] = 'modified_beauty_rule_orphaned_states';
                }
                if ($remoteStatesCount > 0) {
                    $reasons[] = 'remote_meli_state_verification_required';
                }

                $classificationCounts['pending_human_review']++;
                if (count($classification['pending_human_review']) < $maxInlineEntities) {
                    $classification['pending_human_review'][] = [
                        'entity_type' => 'beauty_discount',
                        'entity_id' => $model->id,
                        'meli_account_id' => $accountId,
                        'reasons' => array_values(array_unique($reasons)),
                    ];
                }
            } else {
                $classificationCounts['reconstructible_candidates']++;
                if (count($classification['reconstructible_candidates']) < $maxInlineEntities) {
                    $classification['reconstructible_candidates'][] = [
                        'entity_type' => 'beauty_discount',
                        'entity_id' => $model->id,
                        'meli_account_id' => $accountId,
                        'candidate_action' => $windowStatus === 'finished' ? 'no_action_expired_without_active_states' : 'evaluate_rule_window_when_scheduler_resumes',
                        'automatic_execution_allowed' => false,
                    ];
                }
            }
        }
    }

    private function resolveCutover(?string $override): ?CarbonImmutable
    {
        if ($override !== null && trim($override) !== '') {
            try {
                return CarbonImmutable::parse(trim($override));
            } catch (Throwable) {
                return null;
            }
        }

        return $this->cutover->configuredAt();
    }
}
