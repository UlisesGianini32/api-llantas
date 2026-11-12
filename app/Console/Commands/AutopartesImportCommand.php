<?php

namespace App\Console\Commands;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveImportRun;
use App\Services\Autopartes\AutomotiveImportProcessor;
use App\Services\Autopartes\AutomotiveImportService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Console\Command;
use Throwable;

class AutopartesImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autopartes:import
                            {file : Ruta absoluta o relativa al libro Excel (.xls o .xlsx)}
                            {--branch=HMO : Código de sucursal de Autopartes (por defecto HMO)}
                            {--user=4 : ID del usuario operador que ejecuta la importación (por defecto 4)}
                            {--preview-only : Generar únicamente la vista previa y análisis documental sin confirmar}
                            {--confirm : Confirmar la importación y encolar el trabajo en autopartes_database}
                            {--process : Procesar secuencialmente todos los lotes de la importación en este proceso CLI}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa o previsualiza un catálogo Excel de Autopartes Hermosillo de forma segura vía CLI';

    /**
     * Execute the console command.
     */
    public function handle(AutomotiveImportService $service, AutomotiveImportProcessor $processor): int
    {
        $filePath = (string) $this->argument('file');
        if (! file_exists($filePath)) {
            $this->error("El archivo no existe: {$filePath}");
            return 1;
        }

        $realPath = (string) realpath($filePath);
        $fileName = basename($realPath);
        $branchCode = (string) $this->option('branch');
        $userId = (int) $this->option('user');

        $this->info("=== IMPORTACIÓN DE CATÁLOGO AUTOPARTES HERMOSILLO ===");
        $this->line("Archivo: {$realPath}");
        $this->line("Tamaño: " . number_format(filesize($realPath) / 1024 / 1024, 2) . " MB");
        $this->line("Sucursal: {$branchCode}");
        $this->line("Usuario ID: {$userId}");

        $user = AutopartesUserDirectory::find($userId);
        if (! $user) {
            $this->error("No se encontró el usuario ID {$userId} en el directorio de usuarios.");
            return 1;
        }

        $branch = AutomotiveBranch::where('code', $branchCode)->first();
        if (! $branch) {
            $this->error("No se encontró la sucursal con código '{$branchCode}'.");
            return 1;
        }

        $access = app(BranchCatalogAccess::class);
        if (! $access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::IMPORT)) {
            $this->error("El usuario ID {$userId} no cuenta con la capacidad de importación en la sucursal {$branchCode}.");
            return 1;
        }

        $this->info("\nIniciando análisis y generación de vista previa documental...");
        $startTime = microtime(true);

        try {
            $run = $service->preview($user, $branch, $realPath, $fileName);
        } catch (Throwable $e) {
            $this->error("Error al generar la vista previa: " . $e->getMessage());
            return 1;
        }

        $duration = round(microtime(true) - $startTime, 2);
        $preview = $run->preview ?? [];
        $summary = $preview['summary'] ?? [];
        $classifications = $summary['classifications'] ?? [];

        $this->info("Vista previa generada exitosamente en {$duration}s (Run ID: {$run->id})");
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Run ID', $run->id],
                ['Estado', $run->status],
                ['Archivo SHA-256', substr((string) ($run->file->sha256 ?? 'N/A'), 0, 16) . '...'],
                ['SHA-256 Semántico', substr((string) ($run->file->semantic_sha256 ?? 'N/A'), 0, 16) . '...'],
                ['Total hojas', $summary['sheets_count'] ?? 'N/A'],
                ['Total filas leídas', $summary['total_data_rows'] ?? 'N/A'],
                ['Total celdas', $summary['total_cells'] ?? 'N/A'],
                ['Filas nuevas (new)', $classifications['new'] ?? 0],
                ['Filas existentes (existing)', $classifications['existing'] ?? 0],
                ['Filas equivalentes (equivalent)', $classifications['equivalent'] ?? 0],
                ['Filas sin cambios (unchanged)', $classifications['unchanged'] ?? 0],
                ['Filas modificadas (modified)', $classifications['modified'] ?? 0],
                ['Filas ambiguas (ambiguous)', $classifications['ambiguous'] ?? 0],
                ['Revisión de identidad pendiente', $summary['pending_identity_review'] ?? 0],
                ['Incidencias registradas', $run->issues()->count()],
            ]
        );

        if ($this->option('preview-only') && ! $this->option('confirm') && ! $this->option('process')) {
            $this->info("\nModo --preview-only activo. La importación permanece en estado 'preview'.");
            $this->line("Para confirmar y encolar, ejecute con la opción --confirm.");
            return 0;
        }

        $isDirectProcess = (bool) $this->option('process');
        $dispatchQueue = ! $isDirectProcess;

        $shouldConfirm = $this->option('confirm') || $isDirectProcess;
        if (! $shouldConfirm) {
            if (! $this->confirm("¿Desea confirmar y encolar la importación Run ID {$run->id}?", true)) {
                $this->warn("Operación cancelada. El Run ID {$run->id} permanece en 'preview'.");
                return 0;
            }
        }

        $this->info("\nConfirmando importación Run ID {$run->id}...");
        try {
            $service->confirm($user, $branch, $run, retry: false, dispatchQueue: $dispatchQueue);
        } catch (Throwable $e) {
            $this->error("Error al confirmar la importación: " . $e->getMessage());
            return 1;
        }

        $run->refresh();
        $this->info("Importación confirmada. Estado: {$run->status}.");
        if ($dispatchQueue) {
            $this->line("Trabajo despachado a la conexión de cola: " . config('autopartes.queue_connection') . ", cola: " . config('autopartes.queue'));
        } else {
            $this->line("Modo sincrónico CLI activo: no se encolaron trabajos redundantes en " . config('autopartes.queue_connection') . ".");
        }

        if ($this->option('process')) {
            $this->info("\nProcesando lotes en este proceso CLI...");
            $batchSize = (int) config('autopartes.batch_size', 100);
            $totalPlan = count($preview['plan'] ?? []);
            $bar = $this->output->createProgressBar($totalPlan);
            $bar->start();

            $batchCount = 0;
            while ($processor->processBatch($run->id)) {
                $batchCount++;
                $run->refresh();
                $bar->setProgress($run->processed_rows);
            }

            $run->refresh();
            $bar->setProgress($run->processed_rows);
            $bar->finish();
            $this->newLine();

            $this->info("\n=== PROCESAMIENTO COMPLETADO ===");
            $this->table(
                ['Resultado', 'Valor'],
                [
                    ['Run ID', $run->id],
                    ['Estado final', $run->status],
                    ['Filas procesadas', $run->processed_rows . ' / ' . $totalPlan],
                    ['Autopartes creadas', $run->counters['created'] ?? 0],
                    ['Autopartes reutilizadas', $run->counters['reused'] ?? 0],
                    ['Autopartes rechazadas', $run->counters['rejected'] ?? 0],
                    ['Stock físico generado automáticamente', '0 (Garantizado: ningún movimiento de inventario creado)'],
                    ['Precios aprobados automáticamente', '0 (Garantizado: catálogo sin política de venta activa)'],
                ]
            );
        } else {
            $this->info("\nPara procesar los lotes asíncronamente con el trabajador dedicado:");
            $this->line("/opt/plesk/php/8.4/bin/php -d memory_limit=1024M artisan queue:work " . config('autopartes.queue_connection') . " --queue=" . config('autopartes.queue') . " --stop-when-empty --tries=1 --timeout=120");
        }

        return 0;
    }
}
