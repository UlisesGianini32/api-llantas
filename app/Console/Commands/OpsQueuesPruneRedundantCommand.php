<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use Illuminate\Console\Command;

class OpsQueuesPruneRedundantCommand extends Command
{
    protected $signature = 'ops:queues-prune-redundant
        {--dry-run : Modo solo lectura (predeterminado); analiza, reporta y genera manifiesto sin borrar}
        {--apply : Ejecuta eliminación por micro-lotes (mutuamente excluyente con --dry-run)}
        {--job=* : Clase(s) exacta(s) autorizada(s) de la lista blanca R2 (sin comodines)}
        {--chunk=500 : Tamaño de micro-lote (1..500)}
        {--max-delete=10000 : Tope máximo de candidatos por ejecución}
        {--max-scan=50000 : Tope máximo de filas escaneadas en dry-run (0 = sin límite hasta --end-id)}
        {--start-id=0 : Límite inferior exclusivo de PK (id > start-id)}
        {--end-id= : Límite superior inclusivo autorizado de PK (id <= authorized_upper_bound)}
        {--sleep-ms=100 : Pausa en milisegundos entre micro-lotes}
        {--min-free-disk-mb=8192 : Espacio mínimo libre requerido en MiB (default 8 GiB)}
        {--audit-log= : Ruta del archivo NDJSON de auditoría (obligatorio para --apply)}
        {--manifest= : Ruta del manifiesto (escritura en --dry-run, lectura verificada en --apply)}
        {--expected-manifest-sha256= : Checksum SHA-256 del manifiesto aprobado por el operador (OBLIGATORIO para --apply)}
        {--hmac-key-env=QUEUE_RECOVERY_MANIFEST_HMAC_KEY : Variable de entorno opcional con clave secreta HMAC-SHA256 externa}
        {--resume : Reanudar desde el checkpoint persistido y reconciliar lotes confirmados tras interrupción}
        {--confirm-reconcile-batch= : Número de lote batch_seq confirmado explícitamente por el operador tras investigar un intent ambiguo}
        {--backup= : Ruta del archivo de respaldo NDJSON verificable (obligatorio para --apply)}
        {--backup-out= : Ruta opcional fuera del repo para exportar respaldo completo en --dry-run}
        {--incident= : Identificador del incidente para trazabilidad en manifiesto y auditoría}
        {--maintenance-window= : Identificador de ventana de mantenimiento autorizada (obligatorio para --apply)}
        {--maintenance-lock-file= : Ruta del archivo JSON sentinel de ventana de mantenimiento fail-closed (obligatorio para --apply)}
        {--checkpoint= : Ruta explícita del checkpoint (por defecto <audit-log>.checkpoint.json)}
        {--disk-check-path=* : Rutas adicionales de filesystem a validar (p. ej. /var/lib/mysql)}
        {--connection= : Nombre de conexión de cola (default config queue.default)}';

    protected $description = 'Recuperación segura de colas Laravel (R2.1): diagnóstico dry-run con cursor explícito, manifiesto verificado y poda por micro-lotes fail-closed';

    public function handle(QueuePruneService $service): int
    {
        $explicitDryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($explicitDryRun && $apply) {
            $this->error('Las opciones --dry-run y --apply son mutuamente excluyentes.');

            return self::INVALID;
        }

        $jobs = array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) $this->option('job'),
        )));

        if ($jobs === []) {
            $this->error('Debe indicar al menos una clase exacta permitida con --job=<FQCN>.');

            return self::INVALID;
        }

        $chunkRaw = $this->option('chunk');
        $maxDeleteRaw = $this->option('max-delete');
        $maxScanRaw = $this->option('max-scan');
        $startIdRaw = $this->option('start-id');
        $endIdRaw = $this->option('end-id');
        $sleepMsRaw = $this->option('sleep-ms');
        $minDiskRaw = $this->option('min-free-disk-mb');
        $confirmBatchRaw = $this->option('confirm-reconcile-batch');

        if (! is_numeric($chunkRaw) || ! is_numeric($maxDeleteRaw) || ! is_numeric($maxScanRaw)
            || ! is_numeric($startIdRaw) || ! is_numeric($sleepMsRaw) || ! is_numeric($minDiskRaw)
            || ($endIdRaw !== null && $endIdRaw !== '' && ! is_numeric($endIdRaw))
            || ($confirmBatchRaw !== null && $confirmBatchRaw !== '' && ! is_numeric($confirmBatchRaw))) {
            $this->error('Los parámetros numéricos (--chunk, --max-delete, --max-scan, --start-id, --end-id, --sleep-ms, --min-free-disk-mb, --confirm-reconcile-batch) deben ser enteros válidos.');

            return self::INVALID;
        }

        $hmacKeyEnv = trim((string) ($this->option('hmac-key-env') ?? ''));
        $hmacKey = $hmacKeyEnv !== '' ? (string) (getenv($hmacKeyEnv) ?: ($_ENV[$hmacKeyEnv] ?? '')) : '';
        $hmacKey = trim($hmacKey) !== '' ? trim($hmacKey) : null;

        try {
            if (! $apply) {
                $result = $service->dryRun([
                    'jobs' => $jobs,
                    'chunk' => (int) $chunkRaw,
                    'max_delete' => (int) $maxDeleteRaw,
                    'max_scan' => (int) $maxScanRaw,
                    'start_id' => (int) $startIdRaw,
                    'end_id' => ($endIdRaw !== null && $endIdRaw !== '') ? (int) $endIdRaw : null,
                    'incident_id' => (string) ($this->option('incident') ?: ''),
                    'manifest_path' => $this->option('manifest') ? (string) $this->option('manifest') : null,
                    'backup_out_path' => $this->option('backup-out') ? (string) $this->option('backup-out') : null,
                    'audit_log_path' => $this->option('audit-log') ? (string) $this->option('audit-log') : null,
                    'hmac_key' => $hmacKey,
                    'queue_connection' => $this->option('connection') ? (string) $this->option('connection') : null,
                ]);

                $manifest = $result['manifest'];
                $totals = $manifest['totals'];

                $this->info('DRY-RUN COMPLETADO (sin modificaciones en base de datos ni llamadas remotas).');
                $this->table(['Métrica', 'Valor'], [
                    ['Incidente', $manifest['incident_id']],
                    ['Conexión / BD / Tabla', "{$manifest['connection']} / {$manifest['database']} / {$manifest['table']}"],
                    ['start_id (exclusivo)', (string) $manifest['start_id']],
                    ['authorized_upper_bound (inclusivo)', (string) $manifest['authorized_upper_bound']],
                    ['last_scanned_id', (string) $manifest['last_scanned_id']],
                    ['last_candidate_id', $manifest['last_candidate_id'] !== null ? (string) $manifest['last_candidate_id'] : 'ninguno'],
                    ['next_start_id (para próxima ventana)', (string) $manifest['next_start_id']],
                    ['Ejecución parcial (is_partial)', ($manifest['is_partial'] ? 'SÍ' : 'NO')." ({$manifest['stop_reason']})"],
                    ['Filas escaneadas', (string) $totals['scanned']],
                    ['Candidatos elegibles', (string) $totals['candidates']],
                    ['Preservados (otros módulos)', (string) $totals['preserved_other_modules']],
                    ['Preservados (reservados)', (string) $totals['preserved_reserved']],
                    ['Preservados (JSON inválido)', (string) $totals['preserved_invalid_json']],
                    ['Preservados (inconsistentes)', (string) $totals['preserved_inconsistent']],
                    ['Anomalías pedidos inexistentes', (string) $totals['missing_order_anomalies']],
                    ['Anomalías reglas Beauty inexistentes', (string) $totals['missing_beauty_rule_anomalies']],
                    ['Inconsistencias de cuenta', (string) $totals['account_inconsistencies']],
                    ['Checksum manifest_sha256 (integridad)', $manifest['manifest_sha256']],
                    ['HMAC-SHA256 externo', isset($manifest['manifest_hmac_sha256']) ? $manifest['manifest_hmac_sha256'] : 'no configurado'],
                ]);

                if ($result['manifest_path'] !== null) {
                    $this->line('Manifiesto guardado (permisos 0600) en: '.$result['manifest_path']);
                    $this->line('Para aprobar este manifiesto en --apply pase exactamente: --expected-manifest-sha256='.$manifest['manifest_sha256']);
                }
                if ($result['backup'] !== null) {
                    $this->line(sprintf(
                        'Respaldo exportado (%d filas, %d bytes, SHA-256: %s): %s',
                        $result['backup']['row_count'],
                        $result['backup']['size_bytes'],
                        $result['backup']['file_sha256'],
                        $result['backup']['path'],
                    ));
                }

                return self::SUCCESS;
            }

            $result = $service->apply([
                'jobs' => $jobs,
                'manifest_path' => (string) ($this->option('manifest') ?? ''),
                'expected_manifest_sha256' => (string) ($this->option('expected-manifest-sha256') ?? ''),
                'hmac_key' => $hmacKey,
                'backup_path' => (string) ($this->option('backup') ?? ''),
                'maintenance_window' => (string) ($this->option('maintenance-window') ?? ''),
                'maintenance_lock_file' => (string) ($this->option('maintenance-lock-file') ?? ''),
                'chunk' => (int) $chunkRaw,
                'max_delete' => (int) $maxDeleteRaw,
                'start_id' => (int) $startIdRaw,
                'end_id' => ($endIdRaw !== null && $endIdRaw !== '') ? (int) $endIdRaw : null,
                'sleep_ms' => (int) $sleepMsRaw,
                'min_free_disk_mb' => (int) $minDiskRaw,
                'audit_log_path' => (string) ($this->option('audit-log') ?? ''),
                'checkpoint_path' => $this->option('checkpoint') ? (string) $this->option('checkpoint') : null,
                'resume' => (bool) $this->option('resume'),
                'confirm_reconcile_batch' => ($confirmBatchRaw !== null && $confirmBatchRaw !== '') ? (int) $confirmBatchRaw : null,
                'disk_check_paths' => array_values(array_filter(array_map('strval', (array) $this->option('disk-check-path')))),
                'queue_connection' => $this->option('connection') ? (string) $this->option('connection') : null,
            ]);

            $this->info('APPLY FINALIZADO: estado='.$result['status']);
            $this->table(['Métrica', 'Valor'], [
                ['Incidente', (string) $result['incident_id']],
                ['Estado', (string) $result['status']],
                ['authorized_upper_bound', (string) $result['authorized_upper_bound']],
                ['manifest_last_scanned_id', (string) $result['manifest_last_scanned_id']],
                ['last_deleted_id', $result['last_deleted_id'] !== null ? (string) $result['last_deleted_id'] : 'ninguno'],
                ['next_start_id (siguiente ventana)', $result['next_start_id'] !== null ? (string) $result['next_start_id'] : 'completar manifiesto actual con --resume'],
                ['Eliminados en esta corrida', (string) $result['deleted_this_run']],
                ['Reconciliados tras interrupción', (string) $result['reconciled_after_crash']],
                ['Total acumulado eliminado', (string) $result['total_deleted']],
                ['Restantes en manifiesto', (string) $result['remaining_in_manifest']],
                ['Respaldo verificado (restauración aislada)', $result['backup_verification']['isolated_restore_verified'] ? 'OK' : 'FAIL'],
                ['Checkpoint', (string) $result['checkpoint_path']],
            ]);

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            $this->error("[{$e->reasonCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
