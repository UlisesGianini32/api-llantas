<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Llanta;
use App\Services\BrandDetector;

class ArreglarMarcas extends Command
{
    protected $signature = 'marcas:arreglar {--dry-run : Solo mostrar cambios sin guardar}';
    protected $description = 'Arregla la columna marca detectándola desde la descripción (cuando está GENERICA o vacía)';

    public function handle()
    {
        $dry = (bool) $this->option('dry-run');

        $this->info($dry ? 'DRY RUN: no se guardará nada.' : 'Actualizando marcas en llantas...');

        $updated = 0;
        $noDetectadas = 0;

        Llanta::chunk(300, function ($llantas) use ($dry, &$updated, &$noDetectadas) {

            foreach ($llantas as $llanta) {

                $marcaDb = strtoupper(trim((string) $llanta->marca));

                // ✅ Solo arreglar si está GENERICA o vacía
                if ($marcaDb !== '' && $marcaDb !== 'GENERICA' && $marcaDb !== 'N/A' && $marcaDb !== 'NA') {
                    continue;
                }

                $detectada = BrandDetector::detect((string) $llanta->descripcion);
                if (!$detectada) {
                    $noDetectadas++;
                    continue;
                }

                if ($dry) {
                    $this->line("{$llanta->sku} | {$marcaDb} -> {$detectada} | {$llanta->descripcion}");
                } else {
                    $llanta->marca = $detectada;
                    $llanta->save();
                }

                $updated++;
            }
        });

        $this->info("Listo. Registros actualizados: {$updated}");
        $this->info("No detectadas: {$noDetectadas}");

        return self::SUCCESS;
    }
}
