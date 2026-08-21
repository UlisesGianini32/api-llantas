<?php

namespace App\Console\Commands;

use App\Services\Llantas\LlantaDuplicateDetectorService;
use Illuminate\Console\Command;

class LlantasBuscarCambiosSku extends Command
{
    protected $signature = 'llantas:buscar-cambios-sku
                            {--min=86 : Puntaje mínimo}
                            {--limit=200 : Máximo de resultados}
                            {--exactos : Mostrar únicamente coincidencias de 100 puntos}';

    protected $description = 'Busca posibles llantas duplicadas que tienen SKU diferente';

    public function handle(
        LlantaDuplicateDetectorService $detector
    ): int {
        $minimumScore = (float) $this->option('min');
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('exactos')) {
            $minimumScore = 99.5;
        }

        $this->info('Analizando la tabla llantas...');
        $this->newLine();

        $results = $detector->detect(
            minimumScore: $minimumScore,
            limit: $limit
        );

        if ($results->isEmpty()) {
            $this->info(
                'No se encontraron posibles duplicados con el puntaje indicado.'
            );

            return self::SUCCESS;
        }

        $this->table(
            [
                'Score',
                'Nivel',
                'ID A',
                'SKU A',
                'Stock A',
                'ID B',
                'SKU B',
                'Stock B',
                'Medida',
                'Motivo',
            ],
            $results->map(function (array $result): array {
                return [
                    number_format($result['score'], 2),
                    $result['level'],
                    $result['left']->id,
                    $result['left']->sku,
                    $result['left']->stock,
                    $result['right']->id,
                    $result['right']->sku,
                    $result['right']->stock,
                    $result['left_parsed']['medida'],
                    mb_strimwidth(
                        implode('; ', $result['reasons']),
                        0,
                        70,
                        '...'
                    ),
                ];
            })->all()
        );

        $this->newLine();

        $this->warn(
            'Este comando solo audita. No eliminó, fusionó ni modificó registros.'
        );

        $this->line(
            'Coincidencias encontradas: ' . $results->count()
        );

        $this->newLine();

        $this->comment(
            'Para ver únicamente las coincidencias exactas:'
        );

        $this->line(
            'php artisan llantas:buscar-cambios-sku --exactos'
        );

        return self::SUCCESS;
    }
}