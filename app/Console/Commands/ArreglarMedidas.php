<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Llanta;

class ArreglarMedidas extends Command
{
    protected $signature = 'medidas:arreglar {--dry-run : Solo mostrar cambios sin guardar}';
    protected $description = 'Arregla llantas.medida detectándola desde la descripción (cuando está N/A o vacía)';

    public function handle()
    {
        $dry = (bool) $this->option('dry-run');

        $this->info($dry ? 'DRY RUN: no se guardará nada.' : 'Actualizando medidas en llantas...');

        $updated = 0;

        Llanta::chunk(300, function ($llantas) use ($dry, &$updated) {
            foreach ($llantas as $llanta) {

                $medidaDb = strtoupper(trim((string) $llanta->medida));

                // Solo arreglar si está N/A o vacía
                if ($medidaDb !== '' && $medidaDb !== 'N/A' && $medidaDb !== 'NA') {
                    continue;
                }

                $detectada = self::detectMedida((string) $llanta->descripcion);
                if (!$detectada) continue;

                if ($dry) {
                    $this->line("{$llanta->sku} | {$medidaDb} -> {$detectada}");
                } else {
                    $llanta->medida = $detectada;
                    $llanta->save();
                }

                $updated++;
            }
        });

        $this->info("Listo. Registros afectados: {$updated}");
        return self::SUCCESS;
    }

    /**
     * Detecta medida desde descripción.
     * Cubre:
     * - 225/65R17, 225/65ZR17, 285/55R20, 235/75R15, 33X12.50R20, 37X13.50R17
     * - 10R22.5, 11R22.5, 9R22.5
     * - 11L-16, 10-16.5, 12.00-24, 7.50-16
     */
    private static function detectMedida(string $text): ?string
    {
        $t = strtoupper(trim($text));
        $t = preg_replace('/\s+/', ' ', $t);

        $patterns = [
            // 225/65R17, 225/65ZR17, 225/65R17C etc (tomamos hasta la Rin)
            '/\b\d{3}\/\d{2,3}\s*(?:ZR|R)\s*\d{2}\b/',

            // 33X12.50R20, 37X13.50R17 (pulgadas)
            '/\b\d{2}(?:\.\d+)?\s*X\s*\d{1,2}(?:\.\d+)?\s*R\s*\d{2}\b/',

            // 10R22.5 / 11R22.5 / 9R22.5
            '/\b\d{1,2}\s*R\s*\d{2}(?:\.\d)?\b/',

            // 11L-16
            '/\b\d{1,2}\s*L\s*-\s*\d{2}\b/',

            // 10-16.5, 12.00-24, 7.50-16, 11.00-20
            '/\b\d{1,2}(?:\.\d{1,2})?\s*-\s*\d{2}(?:\.\d)?\b/',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $t, $m)) {
                // Normalizar espacios
                $medida = preg_replace('/\s+/', '', $m[0]);

                // Normalizar X y R con formato típico
                $medida = str_replace(['X','R','-','L'], ['X','R','-','L'], $medida);

                // Casos: "33X12.50R20" ya está bien
                return $medida;
            }
        }

        return null;
    }
}
