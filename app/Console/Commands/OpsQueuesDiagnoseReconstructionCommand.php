<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\QueueRecovery\QueueOrderExhaustiveClassifier;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryPathGuard;
use Illuminate\Console\Command;

class OpsQueuesDiagnoseReconstructionCommand extends Command
{
    protected $signature = 'ops:queues-diagnose-reconstruction
        {--manifest= : Ruta opcional de un manifiesto dry-run (JSON R2/R2.1 o NDJSON) para acotar el diagnóstico}
        {--expected-manifest-sha256= : Checksum SHA-256 esperado del manifiesto (opcional)}
        {--hmac-key= : Clave HMAC-SHA256 opcional para verificar autenticidad del manifiesto}
        {--account= : Filtrar por ID interno de meli_accounts}
        {--cutover= : Fecha ISO-8601 de corte (default INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER)}
        {--chunk=500 : Tamaño de lote de lectura por cursor PK (50..500)}
        {--connection= : Conexión de base de datos (default config database.default)}
        {--output= : Ruta opcional para guardar el reporte JSON verificable (permisos 0600)}
        {--ndjson-output= : Ruta opcional para emitir todas las entidades clasificadas en streaming NDJSON (permisos 0600)}
        {--max-inline-entities=2000 : Máximo de entidades inline en el resumen JSON (el NDJSON siempre incluye el 100%)}
        {--summary-only : Omitir arreglos inline de entidades en el resumen JSON (ideal para 100k–2.76M jobs con --ndjson-output)}';

    protected $description = 'Diagnóstico exhaustivo 100% solo lectura (R2-DIAG v2.0): partición disjunta de 7 categorías, invariantes fail-closed, compatibilidad R3/pre-R3 y salida NDJSON';

    public function handle(
        QueueReconstructionDiagnosticsService $service,
        QueueRecoveryPathGuard $pathGuard,
    ): int {
        try {
            $hmacKey = $this->option('hmac-key') ? (string) $this->option('hmac-key') : null;
            if ($hmacKey === null || trim($hmacKey) === '') {
                $envKey = getenv('QUEUE_RECOVERY_MANIFEST_HMAC_KEY');
                $hmacKey = is_string($envKey) && trim($envKey) !== '' ? trim($envKey) : null;
            }

            $report = $service->diagnose([
                'manifest_path' => $this->option('manifest') ? (string) $this->option('manifest') : null,
                'expected_manifest_sha256' => $this->option('expected-manifest-sha256') ? (string) $this->option('expected-manifest-sha256') : null,
                'hmac_key' => $hmacKey,
                'account_id' => $this->option('account') !== null && $this->option('account') !== '' ? (int) $this->option('account') : null,
                'cutover' => $this->option('cutover') ? (string) $this->option('cutover') : null,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'connection' => $this->option('connection') ? (string) $this->option('connection') : null,
                'ndjson_output_path' => $this->option('ndjson-output') ? (string) $this->option('ndjson-output') : null,
                'max_inline_entities' => (int) ($this->option('max-inline-entities') ?? QueueReconstructionDiagnosticsService::DEFAULT_MAX_INLINE_ENTITIES),
                'summary_only' => (bool) $this->option('summary-only'),
            ]);

            $this->info('DIAGNÓSTICO EXHAUSTIVO COMPLETADO (R2-DIAG v'.$report['format_version'].' — 100% SOLO LECTURA, sin mutaciones ni llamadas a Mercado Libre).');

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
                    'NDJSON streaming guardado (0600): %s (%d registros, %d bytes, sha256=%s)',
                    $ndjsonMeta['path'],
                    $ndjsonMeta['records'],
                    $ndjsonMeta['size_bytes'],
                    $ndjsonMeta['sha256'],
                ));
            }

            $outputPath = $this->option('output') ? trim((string) $this->option('output')) : '';
            if ($outputPath !== '') {
                $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $pathGuard->writeAtomicFile($outputPath, $json."\n", 0600, false, false);
                $this->line('Reporte JSON guardado (permisos 0600) en: '.$outputPath);
            }

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            $this->error("[{$e->reasonCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
