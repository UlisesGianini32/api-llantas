<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\QueueRecovery\QueueOrderExhaustiveClassifier;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use Illuminate\Console\Command;

class OpsQueuesDiagnoseReconstructionCommand extends Command
{
    protected $signature = 'ops:queues-diagnose-reconstruction
        {--manifest= : Ruta opcional de un manifiesto dry-run (JSON R2/R2.1 o NDJSON) para acotar el diagnóstico}
        {--backup= : Ruta opcional de respaldo existente para validar ausencia de colisiones (nunca se modifica)}
        {--expected-manifest-sha256= : Checksum SHA-256 esperado del manifiesto (opcional)}
        {--hmac-key-env=QUEUE_RECOVERY_MANIFEST_HMAC_KEY : Variable de entorno opcional con clave secreta HMAC-SHA256 externa (nunca pasar secretos en argumentos)}
        {--account= : Filtrar por ID interno de meli_accounts}
        {--cutover= : Fecha ISO-8601 de corte (default INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER)}
        {--chunk=500 : Tamaño de lote de lectura por cursor PK (50..500)}
        {--connection= : Conexión de base de datos (default config database.default)}
        {--output= : Ruta opcional para guardar el reporte JSON verificable (permisos 0600, sin sobrescritura)}
        {--ndjson-output= : Ruta opcional para emitir todas las entidades clasificadas en streaming NDJSON (permisos 0600, sin sobrescritura)}
        {--completion-marker= : Ruta opcional para emitir marcador JSON final de completitud tras verificar todos los archivos}
        {--max-inline-entities=2000 : Máximo de entidades inline en el resumen JSON (el NDJSON siempre incluye el 100%)}
        {--summary-only : Omitir arreglos inline de entidades en el resumen JSON (ideal para 100k–2.76M jobs con --ndjson-output)}
        {--verify-live-jobs : Opcional: verificar estado actual por PK en tabla jobs (sin cargar payloads, 100% solo lectura)}';

    protected $description = 'Diagnóstico exhaustivo 100% solo lectura (R2-DIAG.1 v2.1): partición disjunta de 7 categorías, integridad single-FD, escritura segura 0600 e invariantes fail-closed';

    public function handle(
        QueueReconstructionDiagnosticsService $service,
    ): int {
        try {
            // D3: Read HMAC secret exclusively from configured environment variable — never accept secret in CLI argv!
            $hmacKeyEnv = trim((string) ($this->option('hmac-key-env') ?? ''));
            $hmacKeyRaw = $hmacKeyEnv !== '' ? (string) (getenv($hmacKeyEnv) ?: ($_ENV[$hmacKeyEnv] ?? '')) : '';
            $hmacKey = trim($hmacKeyRaw) !== '' ? trim($hmacKeyRaw) : null;

            // ALL requested output files (NDJSON, summary JSON, completion marker) are written, synced,
            // published without overwriting existing files, and disk-SHA256-verified INSIDE diagnose()
            // BEFORE any "COMPLETADO" announcement is printed!
            $report = $service->diagnose([
                'manifest_path' => $this->option('manifest') ? (string) $this->option('manifest') : null,
                'backup_path' => $this->option('backup') ? (string) $this->option('backup') : null,
                'expected_manifest_sha256' => $this->option('expected-manifest-sha256') ? (string) $this->option('expected-manifest-sha256') : null,
                'hmac_key' => $hmacKey,
                'account_id' => $this->option('account') !== null && $this->option('account') !== '' ? (int) $this->option('account') : null,
                'cutover' => $this->option('cutover') ? (string) $this->option('cutover') : null,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'connection' => $this->option('connection') ? (string) $this->option('connection') : null,
                'ndjson_output_path' => $this->option('ndjson-output') ? (string) $this->option('ndjson-output') : null,
                'output_path' => $this->option('output') ? (string) $this->option('output') : null,
                'completion_marker_path' => $this->option('completion-marker') ? (string) $this->option('completion-marker') : null,
                'max_inline_entities' => (int) ($this->option('max-inline-entities') ?? QueueReconstructionDiagnosticsService::DEFAULT_MAX_INLINE_ENTITIES),
                'summary_only' => (bool) $this->option('summary-only'),
                'verify_live_jobs' => (bool) $this->option('verify-live-jobs'),
            ]);

            $this->info('DIAGNÓSTICO EXHAUSTIVO COMPLETADO (R2-DIAG.1 v'.$report['format_version'].' — 100% SOLO LECTURA, todos los archivos verificados en disco).');

            $partition = $report['exhaustive_classification']['primary_partition_counts'];
            $jobsByPrimary = $report['historical_recovery_security']['jobs_by_primary_classification'];
            $labels = [
                QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW => '1. pending_human_review (revisión humana/remota)',
                QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES => '2. ambiguous_entities (conflictos/ambigüedad)',
                QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES => '3. reconstructible_candidates (NO auto-autoriza)',
                QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED => '4. consistent_no_action_needed (verificado positivamente)',
                QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK => '5. out_of_scope_no_reservation_link (sin vínculo válido)',
                QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED => '6. out_of_scope_pre_cutover_or_ignored_status',
                QueueOrderExhaustiveClassifier::PRIMARY_INSUFFICIENT_EVIDENCE => '7. insufficient_evidence (evidencia insuficiente)',
            ];

            $partitionRows = [];
            foreach (QueueOrderExhaustiveClassifier::PRIMARY_CATEGORIES as $cat) {
                $partitionRows[] = [
                    $labels[$cat] ?? $cat,
                    (string) ($partition[$cat] ?? 0),
                    (string) ($jobsByPrimary[$cat] ?? 0),
                ];
            }
            $this->table(['Clasificación Principal Disjunta (Prioridad 1→7)', 'Pedidos', 'Jobs asociados'], $partitionRows);

            $inv = $report['invariants'];
            $r3 = $report['r3_compatibility'];
            $this->table(['Invariante / Compatibilidad', 'Valor'], [
                ['distinct_orders_in_scope', (string) $inv['distinct_orders_in_scope']],
                ['accounted_orders', (string) $inv['accounted_orders']],
                ['unclassified_orders', (string) $inv['unclassified_orders']],
                ['sum(primary_partition_counts)', (string) $inv['sum_primary_partition_counts']],
                ['duplicate_primary_classifications', (string) $inv['duplicate_primary_classifications']],
                ['missing_orders_accounted', (string) $inv['missing_orders_accounted']],
                ['invariants.all_passed', $inv['all_passed'] ? 'TRUE' : 'FALSE'],
                ['r3_compatibility.schema_mode', (string) $r3['schema_mode']],
                ['performance.peak_memory_mib', (string) $report['performance_metrics']['peak_memory_mib'].' MiB'],
            ]);

            $this->table(['Categoría (compatibilidad R2.1)', 'Cantidad', 'Requiere verificación manual/remota'], [
                ['Pedidos cancelados con reservas activas', (string) $report['counts']['cancelled_orders_with_active_reservations'], 'Revisar'],
                ['Pedidos enviados con reservas activas', (string) $report['counts']['shipped_orders_with_active_reservations'], 'Revisar'],
                ['Pedidos pagados pendientes de reserva', (string) $report['counts']['paid_orders_pending_reservation'], 'Revisar'],
                ['Pedidos enviados en caída sin reserva previa', (string) $report['counts']['shipped_during_outage_never_reserved'], 'SÍ (no re-simular)'],
                ['Pedidos con movimientos manuales (occurred_at)', (string) $report['counts']['orders_with_manual_movements'], 'SÍ'],
                ['Pedidos multilínea', (string) $report['counts']['multiline_orders'], 'SÍ'],
                ['Kits y componentes', (string) $report['counts']['kits_and_components'], 'SÍ'],
                ['Promociones Beauty activas', (string) $report['counts']['active_beauty_promotions'], 'Revisar'],
                ['Promociones Beauty pendientes de restauración', (string) $report['counts']['beauty_promotions_pending_restore'], 'Prioridad alta'],
                ['Promociones Beauty vencidas', (string) $report['counts']['expired_beauty_promotions'], 'Revisar'],
                ['Estados remotos que requieren verificación', (string) $report['counts']['remote_states_requiring_verification'], 'SÍ (GET remoto)'],
            ]);

            $ndjsonMeta = $report['exhaustive_classification']['ndjson_output'];
            if (is_array($ndjsonMeta)) {
                $this->line(sprintf(
                    'NDJSON streaming verificado (0600): %s (%d registros, %d bytes, sha256=%s)',
                    $ndjsonMeta['path'],
                    $ndjsonMeta['records'],
                    $ndjsonMeta['size_bytes'],
                    $ndjsonMeta['sha256'],
                ));
            }

            $summaryMeta = $report['publication_protocol']['summary_output'] ?? null;
            if (is_array($summaryMeta)) {
                $this->line(sprintf(
                    'Reporte JSON verificado (0600): %s (%d bytes, sha256=%s)',
                    $summaryMeta['path'],
                    $summaryMeta['size_bytes'],
                    $summaryMeta['sha256'],
                ));
            }

            $markerMeta = $report['publication_protocol']['completion_marker_output'] ?? null;
            if (is_array($markerMeta)) {
                $this->line(sprintf(
                    'Marcador de completitud verificado (0600): %s (sha256=%s)',
                    $markerMeta['path'],
                    $markerMeta['sha256'],
                ));
            }

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            $this->error("[{$e->reasonCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
