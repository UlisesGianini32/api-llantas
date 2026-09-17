<?php

namespace App\Services\Llantas;

use App\Models\Llanta;
use App\Models\LlantaSkuAlias;
use App\Models\LlantaSkuCandidate;

class LlantaResolverService
{
    public function __construct(
        private LlantaDescriptionParser $parser,
        private LlantaDuplicateDetectorService $duplicateDetector
    ) {
    }

    public function resolve(string $sku, string $description): array
    {
        $sku = trim($sku);

        if ($llanta = Llanta::where('sku', $sku)->first()) {
            return [
                'llanta' => $llanta,
                'type' => 'sku',
                'score' => 100.0,
            ];
        }

        $alias = LlantaSkuAlias::with('llanta')
            ->where('sku_alias', $sku)
            ->first();

        if ($alias?->llanta) {
            return [
                'llanta' => $alias->llanta,
                'type' => 'alias',
                'score' => 100.0,
            ];
        }

        $candidate = $this->findBestCandidate($description);

        if (!$candidate) {
            return [
                'llanta' => null,
                'type' => 'new',
                'score' => 0.0,
            ];
        }

        /*
         * Solo permite alias automático cuando:
         *
         * 1. No existe ninguna diferencia técnica bloqueante.
         * 2. La puntuación alcanza el mínimo configurado.
         * 3. La similitud es MUY ALTA.
         */
        if (
            !$candidate['comparison']['vetoed']
            && $candidate['score'] >= (float) config(
                'llantas.auto_alias_min_score',
                99.5
            )
            && $candidate['comparison']['level'] === 'MUY ALTA'
        ) {
            LlantaSkuAlias::firstOrCreate(
                ['sku_alias' => $sku],
                [
                    'llanta_id' => $candidate['llanta']->id,
                    'source' => 'automatic',
                ]
            );

            return [
                'llanta' => $candidate['llanta'],
                'type' => 'auto_alias',
                'score' => $candidate['score'],
            ];
        }

        if (
            !$candidate['comparison']['vetoed']
            && $candidate['score'] >= (float) config(
                'llantas.candidate_min_score',
                86.0
            )
        ) {
            LlantaSkuCandidate::updateOrCreate(
                [
                    'llanta_id' => $candidate['llanta']->id,
                    'sku_new' => $sku,
                ],
                [
                    'description_new' => $description,
                    'score' => $candidate['score'],
                    'status' => 'pending',
                    'details' => [
                        'comparison' => $candidate['comparison'],
                        'incoming' => $candidate['incoming'],
                        'current' => $candidate['current'],
                    ],
                ]
            );

            return [
                'llanta' => null,
                'type' => 'candidate',
                'score' => $candidate['score'],
            ];
        }

        return [
            'llanta' => null,
            'type' => 'new',
            'score' => $candidate['score'],
        ];
    }

    private function findBestCandidate(
        string $description
    ): ?array {
        $incoming = $this->parser->parse($description);

        $query = Llanta::query();

        /*
         * La medida exacta es el filtro principal.
         */
        if ($incoming['medida'] !== 'N/A') {
            $query->where(function ($query) use ($incoming) {
                $query
                    ->where('medida', $incoming['medida'])
                    ->orWhere(
                        'descripcion',
                        'like',
                        '%' . $incoming['medida'] . '%'
                    );
            });
        }

        /*
         * La marca reduce todavía más los falsos positivos.
         */
        if ($incoming['marca'] !== 'GENERICA') {
            $query->where(function ($query) use ($incoming) {
                $query
                    ->where('marca', $incoming['marca'])
                    ->orWhere(
                        'descripcion',
                        'like',
                        '%' . $incoming['marca'] . '%'
                    );
            });
        }

        $best = null;

        foreach ($query->limit(200)->get() as $llanta) {
            $current = $this->parser->parse(
                (string) $llanta->descripcion
            );

            $comparison = $this->duplicateDetector
                ->compareDescriptions(
                    leftDescription: $description,
                    rightDescription: (string) $llanta->descripcion,
                    leftBrand: $incoming['marca'],
                    rightBrand: $llanta->marca,
                    leftSize: $incoming['medida'],
                    rightSize: $llanta->medida
                );

            if ($comparison['vetoed']) {
                continue;
            }

            $score = (float) $comparison['score'];

            if ($best === null || $score > $best['score']) {
                $best = [
                    'llanta' => $llanta,
                    'score' => $score,
                    'comparison' => $comparison,
                    'incoming' => $incoming,
                    'current' => $current,
                ];
            }
        }

        return $best;
    }
}