<?php

namespace App\Console\Commands;

use App\Models\Llanta;
use App\Models\LlantaComparisonDecision;
use App\Services\Llantas\LlantaComparisonService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class LlantasComparar extends Command
{
    protected $signature = 'llantas:comparar
                            {--min=90 : Puntaje mínimo para guardar un candidato}';

    protected $description = 'Genera candidatos de comparación de llantas sin modificar el inventario.';

    public function handle(LlantaComparisonService $comparison): int
    {
        $minimumScore = max(0, min(100, (float) $this->option('min')));
        $now = Carbon::now();
        $llantas = Llanta::query()
            ->select([
                'id',
                'sku',
                'marca',
                'medida',
                'descripcion',
                'title_familyname',
                'costo',
                'precio_ML',
                'stock',
                'MLM',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get();

        $parsedById = [];
        $blocks = [];

        foreach ($llantas as $llanta) {
            $parsed = $comparison->parse($llanta);
            $parsedById[$llanta->id] = $parsed;

            $measure = (string) ($parsed['medida'] ?? 'N/A');
            $brand = (string) ($parsed['marca'] ?? 'GENERICA');
            $blockKey = $measure !== 'N/A'
                ? 'medida:'.$measure
                : 'sin_medida:'.$brand;
            $blocks[$blockKey][] = $llanta;
        }

        $existing = LlantaComparisonDecision::query()
            ->get()
            ->keyBy(fn (LlantaComparisonDecision $row): string => $this->pairKey(
                (int) $row->llanta_a_id,
                (int) $row->llanta_b_id
            ));

        $rows = [];
        $metrics = [
            'llantas' => $llantas->count(),
            'bloques' => count($blocks),
            'pares' => 0,
            'candidatos' => 0,
            'nuevos' => 0,
            'actualizados' => 0,
            'decisiones' => 0,
            'alta' => 0,
        ];

        foreach ($blocks as $block) {
            $count = count($block);
            for ($i = 0; $i < $count; $i++) {
                $left = $block[$i];
                $leftParsed = $parsedById[$left->id];

                for ($j = $i + 1; $j < $count; $j++) {
                    $right = $block[$j];

                    if ((string) $left->sku === (string) $right->sku) {
                        continue;
                    }

                    $leftBrand = (string) ($leftParsed['marca'] ?? 'GENERICA');
                    $rightBrand = (string) ($parsedById[$right->id]['marca'] ?? 'GENERICA');
                    if ($leftBrand !== 'GENERICA' && $rightBrand !== 'GENERICA' && $leftBrand !== $rightBrand) {
                        continue;
                    }

                    if ($this->hasIncompatibleTechnicalAttribute($leftParsed, $parsedById[$right->id])) {
                        continue;
                    }

                    $metrics['pares']++;
                    $result = $comparison->compareParsed($leftParsed, $parsedById[$right->id]);
                    if ($result['vetoed'] || (float) $result['score'] < $minimumScore) {
                        continue;
                    }

                    $metrics['candidatos']++;
                    if ((float) $result['score'] >= (float) config('llantas.duplicate_detector.exact_score', 94)) {
                        $metrics['alta']++;
                    }

                    $pair = $comparison->canonicalPair((int) $left->id, (int) $right->id);
                    $key = $this->pairKey($pair['llanta_a_id'], $pair['llanta_b_id']);
                    $previous = $existing->get($key);
                    if ($previous) {
                        $metrics['actualizados']++;
                        if ($previous->status !== 'pending') {
                            $metrics['decisiones']++;
                        }
                    } else {
                        $metrics['nuevos']++;
                    }

                    $rows[$key] = [
                        ...$pair,
                        'score' => (float) $result['score'],
                        'reasons' => json_encode(array_values($result['reasons']), JSON_THROW_ON_ERROR),
                        'differences' => json_encode(array_values($result['differences']), JSON_THROW_ON_ERROR),
                        'status' => $previous?->status ?? 'pending',
                        'decided_by' => $previous?->decided_by,
                        'decided_at' => $previous?->decided_at,
                        'last_detected_at' => $now,
                        'created_at' => $previous?->created_at ?? $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            LlantaComparisonDecision::query()->upsert(
                $chunk,
                ['llanta_a_id', 'llanta_b_id'],
                [
                    'score',
                    'reasons',
                    'differences',
                    'status',
                    'decided_by',
                    'decided_at',
                    'last_detected_at',
                    'updated_at',
                ]
            );
        }

        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Llantas analizadas', $metrics['llantas']],
                ['Bloques por medida', $metrics['bloques']],
                ['Pares evaluados', $metrics['pares']],
                ['Candidatos encontrados', $metrics['candidatos']],
                ['Pendientes nuevos', $metrics['nuevos']],
                ['Candidatos actualizados', $metrics['actualizados']],
                ['Decisiones previas respetadas', $metrics['decisiones']],
                ['Alta coincidencia', $metrics['alta']],
            ]
        );

        return self::SUCCESS;
    }

    private function pairKey(int $leftId, int $rightId): string
    {
        return min($leftId, $rightId).':'.max($leftId, $rightId);
    }

    private function hasIncompatibleTechnicalAttribute(array $left, array $right): bool
    {
        foreach (['load_index', 'speed_index', 'ply_rating', 'construction', 'volume_ml', 'category'] as $field) {
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;

            if ($leftValue !== null && $rightValue !== null && $leftValue !== $rightValue) {
                return true;
            }
        }

        return false;
    }
}
