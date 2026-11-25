<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueuePruneService;
use App\Services\System\QueueRecovery\QueueRecoveryBackupService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OpsQueuesVerifyBackupCommand extends Command
{
    protected $signature = 'ops:queues-verify-backup
        {--manifest= : Ruta del manifiesto generado por dry-run (obligatorio)}
        {--backup= : Ruta del archivo de respaldo NDJSON fuera del repositorio (obligatorio)}
        {--expected-manifest-sha256= : Checksum SHA-256 esperado del manifiesto aprobado (recomendado)}
        {--hmac-key-env=QUEUE_RECOVERY_MANIFEST_HMAC_KEY : Variable de entorno opcional con clave HMAC-SHA256}
        {--export : Exporta el respaldo desde BD antes de verificar y probar restauración aislada}
        {--connection= : Nombre de conexión de cola (solo para --export)}';

    protected $description = 'Exporta (opcionalmente) y verifica integridad SHA-256 de 7 columnas + prueba de restauración en entorno aislado de un respaldo R2.1';

    public function handle(
        QueuePayloadInspector $inspector,
        QueueRecoveryManifest $manifestManager,
        QueueRecoveryBackupService $backupService,
        QueuePruneService $pruneService,
    ): int {
        $manifestPath = trim((string) ($this->option('manifest') ?? ''));
        $backupPath = trim((string) ($this->option('backup') ?? ''));

        if ($manifestPath === '' || $backupPath === '') {
            $this->error('Debe especificar --manifest=<ruta> y --backup=<ruta>.');

            return self::INVALID;
        }

        $expectedSha = trim((string) ($this->option('expected-manifest-sha256') ?? ''));
        $hmacKeyEnv = trim((string) ($this->option('hmac-key-env') ?? ''));
        $hmacKey = $hmacKeyEnv !== '' ? (string) (getenv($hmacKeyEnv) ?: ($_ENV[$hmacKeyEnv] ?? '')) : '';
        $hmacKey = trim($hmacKey) !== '' ? trim($hmacKey) : null;

        try {
            $manifest = $manifestManager->loadAndVerify(
                $manifestPath,
                $inspector,
                $expectedSha !== '' ? $expectedSha : null,
                $hmacKey,
                requireOperatorApproval: false,
            );

            if ((bool) $this->option('export')) {
                $target = $pruneService->resolveQueueTarget($this->option('connection') ? (string) $this->option('connection') : null);
                if ((string) $manifest['connection'] !== $target['db_connection']
                    || (string) $manifest['database'] !== $target['database']
                    || (string) $manifest['table'] !== $target['table']) {
                    throw new QueueRecoveryException('La conexión/BD/tabla actual no coincide con el manifiesto.', 'database_target_mismatch');
                }
                $exported = $backupService->exportForManifest(
                    DB::connection($target['db_connection']),
                    $target['table'],
                    $manifest,
                    $backupPath,
                );
                $this->info("Respaldo exportado ({$exported['row_count']} filas, {$exported['size_bytes']} bytes): {$exported['path']}");
            }

            $verified = $backupService->verifyAndTestRestore($backupPath, $manifest);

            $this->info('RESPALDO VERIFICADO Y PRUEBA DE RESTAURACIÓN AISLADA EXITOSA.');
            $this->table(['Campo', 'Valor'], [
                ['Archivo', $verified['path']],
                ['Tamaño (bytes)', (string) $verified['size_bytes']],
                ['Filas verificadas y restauradas', (string) $verified['row_count']],
                ['Entries SHA-256', $verified['entries_sha256']],
                ['File SHA-256', $verified['file_sha256']],
                ['Duración prueba restauración (ms)', (string) $verified['restore_duration_ms']],
                ['Pico memoria restauración (MiB)', (string) round($verified['restore_peak_memory_bytes'] / (1024 * 1024), 2)],
                ['Restauración aislada (SQLite :memory:)', $verified['isolated_restore_verified'] ? 'PASS' : 'FAIL'],
            ]);

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            $this->error("[{$e->reasonCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
