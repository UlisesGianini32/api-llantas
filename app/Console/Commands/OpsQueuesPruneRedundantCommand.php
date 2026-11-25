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
        {--start-id=0 : Límite inferior exclusivo de PK (id > start-id)}
        {--end-id= : Límite superior inclusivo de PK (id <= end-id)}
        {--sleep-ms=100 : Pausa en milisegundos entre micro-lotes}
        {--min-free-disk-mb=8192 : Espacio mínimo libre requerido en MiB (default 8 GiB)}
        {--audit-log= : Ruta del archivo NDJSON de auditoría (obligatorio para --apply)}
        {--manifest= : Ruta del manifiesto firmado (escritura en --dry-run, lectura verificada en --apply)}
        {--resume : Reanudar desde el checkpoint persistido y reconciliar intents interrumpidos}
        {--backup= : Ruta del archivo de respaldo NDJSON verificable (obligatorio para --apply)}
        {--backup-out= : Ruta opcional para exportar el respaldo completo al generar el manifiesto en --dry-run}
        {--incident= : Identificador del incidente para trazabilidad en manifiesto y auditoría}
        {--maintenance-window= : Identificador de ventana de mantenimiento autorizada (obligatorio para --apply)}
        {--checkpoint= : Ruta explícita del checkpoint (por defecto <audit-log>.checkpoint.json)}
        {--disk-check-path=* : Rutas adicionales de filesystem a validar (p. ej. /var/lib/mysql)}
        {--connection= : Nombre de conexión de cola (default config queue.default)}';

    protected $description = 'Recuperación segura de colas Laravel (R2): diagnóstico dry-run, manifiesto firmado y poda controlada por micro-lotes';

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
        $startIdRaw = $this->option('start-id');
        $endIdRaw = $this->option('end-id');
        $sleepMsRaw = $this->option('sleep-ms');
        $minDiskRaw = $this->option('min-free-disk-mb');

        if (! is_numeric($chunkRaw) || ! is_numeric($maxDeleteRaw) || ! is_numeric($startIdRaw)
            || ! is_numeric($sleepMsRaw) || ! is_numeric($minDiskRaw)
            || ($endIdRaw !== null && $endIdRaw !== '' && ! is_numeric($endIdRaw))) {
            $this->error('Los parámetros numéricos (--chunk, --max-delete, --start-id, --end-id, --sleep-ms, --min-free-disk-mb) deben ser enteros válidos.');

            return self::INVALID;
        }

        try {
            if (! $apply) {
                $result = $service->dryRun([
                    'jobs' => $jobs,
                    'chunk' => (int) $chunkRaw,
                    'max_delete' => (int) $maxDeleteRaw,
                    'start_id' => (int) $startIdRaw,
                    'end_id' => ($endIdRaw !== null && $endIdRaw !== '') ? (int) $endIdRaw : null,
                    'incident_id' => (string) ($this->option('incident') ?: ''),
                    'manifest_path' => $this->option('manifest') ? (string) $this->option('manifest') : null,
                    'backup_out_path' => $this->option('backup-out') ? (string) $this->option('backup-out') : null,
                    'audit_log_path' => $this->option('audit-log') ? (string) $this->option('audit-log') : null,
                    'queue_connection' => $this->option('connection') ? (string) $this->option('connection') : null,
                ]);

                $manifest = $result['manifest'];
                $totals = $manifest['totals'];

                $this->info('DRY-RUN COMPLETADO (sin modificaciones en base de datos ni llamadas remotas).');
                $this->table(['Métrica', 'Valor'], [
                    ['Incidente', $manifest['incident_id']],
                    ['Conexión / BD / Tabla', "{$manifest['connection']} / {$manifest['database']} / {$manifest['table']}"],
                    ['Rango PK (start..end)', "{$manifest['start_id']} .. {$manifest['end_id']}"],
                    ['Filas escaneadas', (string) $totals['scanned']],
                    ['Candidatos elegibles', (string) $totals['candidates']],
                    ['Preservados (otros módulos)', (string) $totals['preserved_other_modules']],
                    ['Preservados (reservados)', (string) $totals['preserved_reserved']],
                    ['Preservados (JSON inválido)', (string) $totals['preserved_invalid_json']],
                    ['Preservados (inconsistentes)', (string) $totals['preserved_inconsistent']],
                    ['Anomalías pedidos inexistentes', (string) $totals['missing_order_anomalies']],
                    ['Anomalías reglas Beauty inexistentes', (string) $totals['missing_beauty_rule_anomalies']],
                    ['Inconsistencias de cuenta', (string) $totals['account_inconsistencies']],
                    ['Manifest SHA-256', $manifest['manifest_sha256']],
                ]);

                if ($result['manifest_path'] !== null) {
                    $this->line('Manifiesto firmado guardado en: '.$result['manifest_path']);
                }
                if ($result['backup'] !== null) {
                    $this->line(sprintf(
                        'Respaldo exportado (%d filas, SHA-256: %s): %s',
                        $result['backup']['row_count'],
                        $result['backup']['file_sha256'],
                        $result['backup']['path'],
                    ));
                }

                return self::SUCCESS;
            }

            $result = $service->apply([
                'jobs' => $jobs,
                'manifest_path' => (string) ($this->option('manifest') ?? ''),
                'backup_path' => (string) ($this->option('backup') ?? ''),
                'maintenance_window' => (string) ($this->option('maintenance-window') ?? ''),
                'chunk' => (int) $chunkRaw,
                'max_delete' => (int) $maxDeleteRaw,
                'start_id' => (int) $startIdRaw,
                'end_id' => ($endIdRaw !== null && $endIdRaw !== '') ? (int) $endIdRaw : null,
                'sleep_ms' => (int) $sleepMsRaw,
                'min_free_disk_mb' => (int) $minDiskRaw,
                'audit_log_path' => (string) ($this->option('audit-log') ?? ''),
                'checkpoint_path' => $this->option('checkpoint') ? (string) $this->option('checkpoint') : null,
                'resume' => (bool) $this->option('resume'),
                'disk_check_paths' => array_values(array_filter(array_map('strval', (array) $this->option('disk-check-path')))),
                'queue_connection' => $this->option('connection') ? (string) $this->option('connection') : null,
            ]);

            $this->info('APPLY FINALIZADO: estado='.$result['status']);
            $this->table(['Métrica', 'Valor'], [
                ['Incidente', (string) $result['incident_id']],
                ['Estado', (string) $result['status']],
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
