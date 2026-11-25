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
        {--manifest= : Ruta del manifiesto firmado generado por dry-run (obligatorio)}
        {--backup= : Ruta del archivo de respaldo NDJSON (obligatorio)}
        {--export : Exporta el respaldo desde BD antes de verificar y probar restauración aislada}
        {--connection= : Nombre de conexión de cola (solo para --export)}';

    protected $description = 'Exporta (opcionalmente) y verifica integridad SHA-256 + prueba de restauración en entorno aislado de un respaldo R2';

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

        try {
            $manifest = $manifestManager->loadAndVerify($manifestPath, $inspector);

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
                $this->info("Respaldo exportado ({$exported['row_count']} filas): {$exported['path']}");
            }

            $verified = $backupService->verifyAndTestRestore($backupPath, $manifest);

            $this->info('RESPALDO VERIFICADO Y PRUEBA DE RESTAURACIÓN AISLADA EXITOSA.');
            $this->table(['Campo', 'Valor'], [
                ['Archivo', $verified['path']],
                ['Filas verificadas y restauradas', (string) $verified['row_count']],
                ['Entries SHA-256', $verified['entries_sha256']],
                ['File SHA-256', $verified['file_sha256']],
                ['Restauración aislada (SQLite :memory:)', $verified['isolated_restore_verified'] ? 'PASS' : 'FAIL'],
            ]);

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            $this->error("[{$e->reasonCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
