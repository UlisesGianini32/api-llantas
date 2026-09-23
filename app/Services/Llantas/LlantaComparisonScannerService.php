<?php

namespace App\Services\Llantas;

use App\Models\Llanta;
use App\Models\LlantaComparisonDecision;
use Illuminate\Support\Carbon;

class LlantaComparisonScannerService
{
    public const DEFAULT_MIN_SCORE = 90.0;

    public function __construct(
        private LlantaComparisonService $comparison,
    ) {}

    /**
     * Escanea el inventario y actualiza solamente la tabla de decisiones.
     * Nunca modifica llantas, aliases, stock, precios ni publicaciones.
     *
     * @return array{llantas:int,bloques:int,pares:int,candidates:int,new_candidates:int,refreshed_candidates:int,decisions_respected:int,pending_total:int,high_confidence:int}
     */
    public function scan(?float $minimumScore = null): array
    {
        $minimumScore ??= self::DEFAULT_MIN_SCORE;
        $minimumScore = max(0, min(100, $minimumScore));
        $now = Carbon::now();
        $llantas = Llanta::query()
            ->select([
                'id', 'sku', 'marca', 'medida', 'descripcion', 'title_familyname',
                'costo', 'precio_ML', 'stock', 'MLM', 'created_at', 'updated_at',
            ])
            ->orderBy('id')
            ->get();

        $parsedById = [];
        $blocks = [];

        foreach ($llantas as $llanta) {
            $parsed = $this->comparison->parse($llanta);
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
            'candidates' => 0,
            'new_candidates' => 0,
            'refreshed_candidates' => 0,
            'decisions_respected' => 0,
            'pending_total' => 0,
            'high_confidence' => 0,
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

                    $rightParsed = $parsedById[$right->id];
                    $leftBrand = (string) ($leftParsed['marca'] ?? 'GENERICA');
                    $rightBrand = (string) ($rightParsed['marca'] ?? 'GENERICA');
                    if ($leftBrand !== 'GENERICA' && $rightBrand !== 'GENERICA' && $leftBrand !== $rightBrand) {
                        continue;
                    }
                    if ($this->hasIncompatibleTechnicalAttribute($leftParsed, $rightParsed)) {
                        continue;
                    }

                    $metrics['pares']++;
                    $result = $this->comparison->compareParsed($leftParsed, $rightParsed);
                    if ($result['vetoed'] || (float) $result['score'] < $minimumScore) {
                        continue;
                    }

                    $metrics['candidates']++;
                    if ((float) $result['score'] >= (float) config('llantas.duplicate_detector.exact_score', 94)) {
                        $metrics['high_confidence']++;
                    }

                    $pair = $this->comparison->canonicalPair((int) $left->id, (int) $right->id);
                    $key = $this->pairKey($pair['llanta_a_id'], $pair['llanta_b_id']);
                    $previous = $existing->get($key);
                    if ($previous) {
                        $metrics['refreshed_candidates']++;
                        if ($previous->status !== 'pending') {
                            $metrics['decisions_respected']++;
                        }
                    } else {
                        $metrics['new_candidates']++;
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
                    'last_detected_at',
                    'updated_at',
                ]
            );
        }

        $metrics['pending_total'] = (int) LlantaComparisonDecision::query()
            ->where('status', 'pending')
            ->count();

        return $metrics;
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
