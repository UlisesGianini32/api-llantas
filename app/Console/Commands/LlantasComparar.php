<?php

namespace App\Console\Commands;

use App\Services\Llantas\LlantaComparisonScannerService;
use Illuminate\Console\Command;

class LlantasComparar extends Command
{
    protected $signature = 'llantas:comparar
                            {--min= : Puntaje mínimo para guardar un candidato (por defecto 90)}';

    protected $description = 'Genera candidatos de comparación de llantas sin modificar el inventario.';

    public function handle(LlantaComparisonScannerService $scanner): int
    {
        $option = $this->option('min');
        $minimumScore = $option === null
            ? LlantaComparisonScannerService::DEFAULT_MIN_SCORE
            : (float) $option;
        $metrics = $scanner->scan($minimumScore);

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Llantas analizadas', $metrics['llantas']],
                ['Bloques por medida', $metrics['bloques']],
                ['Pares evaluados', $metrics['pares']],
                ['Candidatos encontrados', $metrics['candidates']],
                ['Candidatos nuevos', $metrics['new_candidates']],
                ['Candidatos actualizados', $metrics['refreshed_candidates']],
                ['Decisiones previas respetadas', $metrics['decisions_respected']],
                ['Pendientes totales', $metrics['pending_total']],
                ['Alta coincidencia', $metrics['high_confidence']],
            ]
        );

        return self::SUCCESS;
    }
}
