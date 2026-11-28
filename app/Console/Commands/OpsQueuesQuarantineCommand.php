<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\QueueRecovery\QueueQuarantineService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use Illuminate\Console\Command;

class OpsQueuesQuarantineCommand extends Command
{
    protected $signature = 'ops:queues-quarantine
        {--dry-run : Modo solo lectura (predeterminado); escanea y genera manifiesto NDJSON sin modificar la base de datos}
        {--apply : Traslada selectivamente jobs.queue a la cola de cuarentena autorizada (mutuamente excluyente con --dry-run y --restore)}
        {--restore : Restaura selectivamente jobs.queue desde cuarentena solo para IDs confirmados en el audit log (mutuamente excluyente)}
        {--job= : Clase FQCN exacta autorizada (sin comodines): ReconcileInventoryMeliOrderReservationsJob o ProcessMeliBeautyScheduledPriceJob}
        {--source-queue= : Cola de origen exacta (predeterminado según mapeo obligatorio: meli)}
        {--target-queue= : Cola de cuarentena destino exacta (predeterminado según mapeo obligatorio de la clase)}
        {--start-id=0 : Límite inferior exclusivo de PK (id > start-id)}
        {--end-id= : Límite superior inclusivo de PK (id <= end-id)}
        {--historical-upper-bound= : Cota superior histórica autorizada (nunca puede ser superada por --end-id)}
        {--chunk=500 : Tamaño de micro-lote (1..1000; predeterminado 500)}
        {--max-scan=0 : Límite máximo de filas escaneadas en dry-run (0 = hasta --end-id)}
        {--max-move=0 : Límite máximo de candidatos/traslados por corrida (0 = sin límite adicional)}
        {--max-runtime=0 : Tiempo máximo de ejecución en segundos (0 = sin límite)}
        {--sleep-ms= : Pausa en milisegundos entre micro-lotes (predeterminado 0 en dry-run, 200 en apply/restore)}
        {--incident= : Identificador del incidente para trazabilidad}
        {--manifest= : Ruta del manifiesto NDJSON (escritura exclusiva en dry-run, lectura verificada en apply/restore)}
        {--expected-manifest-sha256= : Checksum SHA-256 aprobado por el operador (OBLIGATORIO para --apply y --restore)}
        {--hmac-key-env=QUEUE_RECOVERY_MANIFEST_HMAC_KEY : Variable de entorno opcional con clave HMAC-SHA256 externa (nunca en argumentos CLI)}
        {--audit-log= : Ruta del archivo NDJSON de auditoría durable (obligatorio para --apply y --restore)}
        {--checkpoint= : Ruta opcional del archivo de checkpoint atómico}
        {--completion-marker= : Ruta opcional del archivo JSON marcador de finalización (solo dry-run)}
        {--maintenance-window= : Identificador de ventana de mantenimiento autorizada (obligatorio para --apply y --restore)}
        {--maintenance-lock-file= : Ruta del archivo sentinel JSON de ventana de mantenimiento fail-closed (obligatorio para --apply y --restore)}
        {--resume : Reanuda desde el checkpoint/audit log y reconcilia lotes pendientes tras interrupción}
        {--confirm= : Confirmación explícita obligatoria igual al incident_id del manifiesto para --apply y --restore}
        {--connection= : Nombre de conexión de cola Laravel (default config queue.default)}
        {--json : Emite salida estructurada en JSON (sin formato tabla)}';

    protected $description = 'Cuarentena selectiva, reversible y fail-closed de jobs históricos en MariaDB (R4.2): modifica únicamente jobs.queue sin borrar ni ejecutar jobs';

    public function handle(QueueQuarantineService $service): int
    {
        $dryRunFlag = (bool) $this->option('dry-run');
        $applyFlag = (bool) $this->option('apply');
        $restoreFlag = (bool) $this->option('restore');
        $jsonOutput = (bool) $this->option('json');

        $selectedModes = ($dryRunFlag ? 1 : 0) + ($applyFlag ? 1 : 0) + ($restoreFlag ? 1 : 0);
        if ($selectedModes > 1) {
            return $this->emitError(
                'invalid_mode_combination',
                'Los modos --dry-run, --apply y --restore son estrictamente mutuamente excluyentes.',
                $jsonOutput,
                self::INVALID,
            );
        }

        $mode = match (true) {
            $applyFlag => 'apply',
            $restoreFlag => 'restore',
            default => 'dry_run',
        };

        $numericOptions = [
            'start-id' => $this->option('start-id'),
            'end-id' => $this->option('end-id'),
            'historical-upper-bound' => $this->option('historical-upper-bound'),
            'chunk' => $this->option('chunk'),
            'max-scan' => $this->option('max-scan'),
            'max-move' => $this->option('max-move'),
            'max-runtime' => $this->option('max-runtime'),
            'sleep-ms' => $this->option('sleep-ms'),
        ];

        foreach ($numericOptions as $name => $raw) {
            if ($raw !== null && $raw !== '' && ! preg_match('/\A\d+\z/', (string) $raw)) {
                return $this->emitError(
                    'invalid_numeric_option',
                    "La opción --{$name} debe ser un entero no negativo válido.",
                    $jsonOutput,
                    self::INVALID,
                );
            }
        }

        $hmacKeyEnv = trim((string) ($this->option('hmac-key-env') ?? ''));
        $hmacKey = $hmacKeyEnv !== '' ? (string) (getenv($hmacKeyEnv) ?: ($_ENV[$hmacKeyEnv] ?? '')) : '';
        $hmacKey = trim($hmacKey) !== '' ? trim($hmacKey) : null;

        $this->installSignalHandlers($service);

        try {
            if ($mode === 'dry_run') {
                $job = trim((string) ($this->option('job') ?? ''));
                if ($job === '') {
                    return $this->emitError(
                        'missing_job_class',
                        'Debe especificar una clase exacta autorizada con --job=<FQCN>.',
                        $jsonOutput,
                        self::INVALID,
                    );
                }

                $result = $service->dryRun([
                    'job' => $job,
                    'source_queue' => $this->nullableString($this->option('source-queue')),
                    'target_queue' => $this->nullableString($this->option('target-queue')),
                    'start_id' => (int) ($this->option('start-id') ?? 0),
                    'end_id' => $this->nullableInt($this->option('end-id')),
                    'historical_upper_bound' => $this->nullableInt($this->option('historical-upper-bound')),
                    'chunk' => (int) ($this->option('chunk') ?? QueueQuarantineService::DEFAULT_CHUNK_SIZE),
                    'max_scan' => (int) ($this->option('max-scan') ?? 0),
                    'max_move' => (int) ($this->option('max-move') ?? 0),
                    'max_runtime' => (int) ($this->option('max-runtime') ?? 0),
                    'sleep_ms' => $this->nullableInt($this->option('sleep-ms')) ?? 0,
                    'incident_id' => (string) ($this->option('incident') ?? ''),
                    'manifest_path' => $this->nullableString($this->option('manifest')),
                    'audit_log_path' => $this->nullableString($this->option('audit-log')),
                    'completion_marker_path' => $this->nullableString($this->option('completion-marker')),
                    'hmac_key' => $hmacKey,
                    'queue_connection' => $this->nullableString($this->option('connection')),
                ]);

                if ($jsonOutput) {
                    $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                    return self::SUCCESS;
                }

                $totals = $result['totals'];
                $this->info('CUARENTENA DRY-RUN COMPLETADO (sin escrituras en base de datos ni llamadas remotas).');
                $this->table(['Métrica', 'Valor'], [
                    ['Incidente', (string) $result['incident_id']],
                    ['Clase autorizada', (string) $result['mapping']['job_class']],
                    ['Transición autorizada', "{$result['mapping']['source_queue']} -> {$result['mapping']['target_queue']}"],
                    ['BD / Tabla / Motor', "{$result['target']['database']}.{$result['target']['table']} ({$result['target']['engine']})"],
                    ['start_id (exclusivo) .. authorized_end_id (inclusivo)', "{$result['start_id']} .. {$result['authorized_end_id']}"],
                    ['last_scanned_id / next_start_id', "{$result['last_scanned_id']} / {$result['next_start_id']}"],
                    ['Parcial / Motivo de parada', ($result['is_partial'] ? 'SÍ' : 'NO')." ({$result['stop_reason']})"],
                    ['Filas escaneadas', (string) $totals['scanned']],
                    ['Candidatos elegibles', (string) $totals['candidates']],
                    ['Candidatos con intentos previos (attempts > 0)', (string) $totals['previously_attempted_candidates']],
                    ['Excluidos (otros módulos)', (string) $totals['excluded_other_modules']],
                    ['Excluidos (reservados)', (string) $totals['excluded_reserved']],
                    ['Excluidos (otra cola)', (string) $totals['excluded_wrong_queue']],
                    ['Excluidos (JSON inválido / inconsistente)', "{$totals['excluded_invalid_json']} / {$totals['excluded_inconsistent_payload']}"],
                    ['Duración / Pico RAM', "{$result['duration_ms']} ms / {$result['peak_memory_mib']} MiB"],
                ]);

                if ($result['manifest'] !== null) {
                    $m = $result['manifest'];
                    $this->line("Manifiesto guardado (0600): {$m['path']}");
                    $this->line("manifest_sha256: {$m['manifest_sha256']}");
                    $this->line("file_sha256: {$m['file_sha256']}");
                    $this->line("Para aprobar en --apply pase exactamente: --expected-manifest-sha256={$m['manifest_sha256']} --confirm={$result['incident_id']}");
                }

                return self::SUCCESS;
            }

            $transitionOptions = [
                'job' => $this->nullableString($this->option('job')),
                'source_queue' => $this->nullableString($this->option('source-queue')),
                'target_queue' => $this->nullableString($this->option('target-queue')),
                'start_id' => $this->nullableInt($this->option('start-id')),
                'end_id' => $this->nullableInt($this->option('end-id')),
                'chunk' => (int) ($this->option('chunk') ?? QueueQuarantineService::DEFAULT_CHUNK_SIZE),
                'max_move' => (int) ($this->option('max-move') ?? 0),
                'max_runtime' => (int) ($this->option('max-runtime') ?? 0),
                'sleep_ms' => $this->nullableInt($this->option('sleep-ms')) ?? QueueQuarantineService::DEFAULT_SLEEP_MS,
                'manifest_path' => (string) ($this->option('manifest') ?? ''),
                'expected_manifest_sha256' => (string) ($this->option('expected-manifest-sha256') ?? ''),
                'hmac_key' => $hmacKey,
                'audit_log_path' => (string) ($this->option('audit-log') ?? ''),
                'checkpoint_path' => $this->nullableString($this->option('checkpoint')),
                'maintenance_window' => (string) ($this->option('maintenance-window') ?? ''),
                'maintenance_lock_file' => (string) ($this->option('maintenance-lock-file') ?? ''),
                'resume' => (bool) $this->option('resume'),
                'confirm' => (string) ($this->option('confirm') ?? ''),
                'queue_connection' => $this->nullableString($this->option('connection')),
            ];

            $result = $mode === 'apply'
                ? $service->apply($transitionOptions)
                : $service->restore($transitionOptions);

            if ($jsonOutput) {
                $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $totals = $result['totals'];
            $label = $mode === 'apply' ? 'CUARENTENA APPLY' : 'CUARENTENA RESTORE';
            $this->info("{$label} FINALIZADO: {$result['stop_reason']}");
            $this->table(['Métrica', 'Valor'], [
                ['Incidente', (string) $result['incident_id']],
                ['Operación', (string) $result['operation']],
                ['Clase', (string) $result['mapping']['job_class']],
                ['Transición', "{$result['from_queue']} -> {$result['to_queue']}"],
                ['Lotes ejecutados', (string) $totals['batches_executed']],
                ['Candidatos inspeccionados', (string) $totals['candidates_inspected']],
                ['Jobs trasladados (UPDATE jobs.queue)', (string) $totals['moved']],
                ['Con intentos previos (attempts > 0)', (string) $totals['previously_attempted_moved']],
                ['Omitidos (ya en destino / faltantes / reservados / modificados)', "{$totals['skipped_already_in_target']} / {$totals['skipped_missing']} / {$totals['skipped_reserved']} / {$totals['skipped_modified']}"],
                ['Reintentos por deadlock', (string) $totals['deadlock_retries']],
                ['Lotes reconciliados tras interrupción', (string) count($result['reconciled_intents'])],
                ['last_processed_id', (string) $result['last_processed_id']],
                ['Checkpoint', (string) $result['checkpoint_path']],
                ['Audit log', (string) $result['audit_log_path']],
                ['Duración / Pico RAM', "{$result['duration_ms']} ms / {$result['peak_memory_mib']} MiB"],
            ]);

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            return $this->emitError($e->reasonCode, $e->getMessage(), $jsonOutput, self::FAILURE);
        }
    }

    private function installSignalHandlers(QueueQuarantineService $service): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        if (function_exists('pcntl_async_signals')) {
            @pcntl_async_signals(true);
        }

        $handler = static function () use ($service): void {
            $service->requestInterrupt();
        };

        if (defined('SIGTERM')) {
            @pcntl_signal(SIGTERM, $handler);
        }
        if (defined('SIGINT')) {
            @pcntl_signal(SIGINT, $handler);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function emitError(string $reasonCode, string $message, bool $jsonOutput, int $exitCode): int
    {
        if ($jsonOutput) {
            $this->line(json_encode([
                'status' => 'error',
                'reason_code' => $reasonCode,
                'message' => $message,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->error("[{$reasonCode}] {$message}");
        }

        return $exitCode;
    }
}
