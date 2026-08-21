<?php

namespace App\Services\Llantas;

use App\Models\Llanta;
use Illuminate\Support\Collection;

class LlantaDuplicateDetectorService
{
    public function __construct(
        private LlantaDescriptionParser $parser
    ) {
    }

    /**
     * Busca posibles duplicados en toda la tabla llantas.
     */
    public function detect(
        float $minimumScore = 86.0,
        int $limit = 200
    ): Collection {
        $llantas = Llanta::query()
            ->select([
                'id',
                'sku',
                'marca',
                'medida',
                'descripcion',
                'stock',
                'MLM',
                'last_import_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get();

        $parsed = [];

        foreach ($llantas as $llanta) {
            $parsed[$llanta->id] = $this->parseLlanta($llanta);
        }

        $results = collect();
        $total = $llantas->count();

        for ($i = 0; $i < $total; $i++) {
            $left = $llantas[$i];
            $leftParsed = $parsed[$left->id];

            for ($j = $i + 1; $j < $total; $j++) {
                $right = $llantas[$j];

                if ($left->sku === $right->sku) {
                    continue;
                }

                $rightParsed = $parsed[$right->id];

                if (!$this->passesFastFilter($leftParsed, $rightParsed)) {
                    continue;
                }

                $comparison = $this->compareParsed(
                    $leftParsed,
                    $rightParsed
                );

                if ($comparison['vetoed']) {
                    continue;
                }

                if ($comparison['score'] < $minimumScore) {
                    continue;
                }

                $results->push([
                    'left' => $left,
                    'right' => $right,
                    'score' => $comparison['score'],
                    'level' => $comparison['level'],
                    'reasons' => $comparison['reasons'],
                    'differences' => $comparison['differences'],
                    'left_parsed' => $leftParsed,
                    'right_parsed' => $rightParsed,
                ]);
            }
        }

        return $results
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Compara dos descripciones. Este método también se utiliza desde
     * LlantaResolverService durante las importaciones.
     */
    public function compareDescriptions(
        string $leftDescription,
        string $rightDescription,
        ?string $leftBrand = null,
        ?string $rightBrand = null,
        ?string $leftSize = null,
        ?string $rightSize = null
    ): array {
        $left = $this->parser->parse($leftDescription);
        $right = $this->parser->parse($rightDescription);

        $left = $this->applyDatabaseValues(
            parsed: $left,
            brand: $leftBrand,
            size: $leftSize
        );

        $right = $this->applyDatabaseValues(
            parsed: $right,
            brand: $rightBrand,
            size: $rightSize
        );

        return $this->compareParsed($left, $right);
    }

    public function compareParsed(array $left, array $right): array
    {
        $vetoes = $this->findVetoes($left, $right);

        if ($vetoes !== []) {
            return [
                'score' => 0.0,
                'level' => 'DESCARTADO',
                'vetoed' => true,
                'reasons' => [],
                'differences' => $vetoes,
                'left' => $left,
                'right' => $right,
            ];
        }

        /*
         * Una descripción completamente idéntica es la señal más fuerte.
         */
        if (
            $left['normalized'] !== ''
            && $left['normalized'] === $right['normalized']
        ) {
            return [
                'score' => 100.0,
                'level' => 'MUY ALTA',
                'vetoed' => false,
                'reasons' => ['Descripción exactamente igual'],
                'differences' => [],
                'left' => $left,
                'right' => $right,
            ];
        }

        $score = 0.0;
        $reasons = [];
        $differences = [];

        /*
         * Medida: 35 puntos.
         */
        if (
            $left['medida'] !== 'N/A'
            && $right['medida'] !== 'N/A'
            && $left['medida'] === $right['medida']
        ) {
            $score += 35;
            $reasons[] = 'Misma medida';
        } elseif (
            $left['medida'] === 'N/A'
            && $right['medida'] === 'N/A'
        ) {
            $score += 5;
            $reasons[] = 'Ambos sin medida de llanta';
        }

        /*
         * Marca: 20 puntos.
         */
        if (
            $left['marca'] !== 'GENERICA'
            && $right['marca'] !== 'GENERICA'
            && $left['marca'] === $right['marca']
        ) {
            $score += 20;
            $reasons[] = 'Misma marca';
        } elseif (
            $left['marca'] === 'GENERICA'
            && $right['marca'] === 'GENERICA'
        ) {
            $score += 5;
            $reasons[] = 'Ambos sin marca reconocida';
        }

        /*
         * Modelo y palabras importantes: hasta 25 puntos.
         */
        $tokenSimilarity = $this->tokenSimilarity(
            $left['tokens'],
            $right['tokens']
        );

        $tokenPoints = $tokenSimilarity * 25;
        $score += $tokenPoints;

        if ($tokenSimilarity >= 0.99) {
            $reasons[] = 'Modelo y palabras importantes iguales';
        } elseif ($tokenSimilarity >= 0.80) {
            $reasons[] = 'Modelo muy parecido';
        } elseif ($tokenSimilarity >= 0.60) {
            $reasons[] = 'Modelo parecido';
        } else {
            $differences[] = 'Poca similitud entre modelos';
        }

        /*
         * Índice de carga: 6 puntos.
         */
        $score += $this->scoreOptionalExactField(
            field: 'load_index',
            label: 'índice de carga',
            points: 6,
            left: $left,
            right: $right,
            reasons: $reasons
        );

        /*
         * Índice de velocidad: 4 puntos.
         */
        $score += $this->scoreOptionalExactField(
            field: 'speed_index',
            label: 'índice de velocidad',
            points: 4,
            left: $left,
            right: $right,
            reasons: $reasons
        );

        /*
         * Capas: 4 puntos.
         */
        $score += $this->scoreOptionalExactField(
            field: 'ply_rating',
            label: 'número de capas',
            points: 4,
            left: $left,
            right: $right,
            reasons: $reasons
        );

        /*
         * Construcción TL/TT: 2 puntos.
         */
        $score += $this->scoreOptionalExactField(
            field: 'construction',
            label: 'construcción TL/TT',
            points: 2,
            left: $left,
            right: $right,
            reasons: $reasons
        );

        /*
         * Categoría: 2 puntos.
         */
        $score += $this->scoreOptionalExactField(
            field: 'category',
            label: 'categoría',
            points: 2,
            left: $left,
            right: $right,
            reasons: $reasons
        );

        /*
         * Características especiales: 2 puntos.
         */
        $flagsMatched = 0;

        foreach (['is_xl', 'is_lt', 'is_rwl'] as $flag) {
            $leftValue = (bool) ($left[$flag] ?? false);
            $rightValue = (bool) ($right[$flag] ?? false);

            if ($leftValue === $rightValue) {
                $flagsMatched++;
            }
        }

        $score += ($flagsMatched / 3) * 2;

        /*
         * Productos no llanta con el mismo volumen.
         */
        if (
            $left['volume_ml'] !== null
            && $right['volume_ml'] !== null
            && $left['volume_ml'] === $right['volume_ml']
        ) {
            $score += 10;
            $reasons[] = 'Mismo volumen';
        }

        $score = round(min(100, $score), 2);

        return [
            'score' => $score,
            'level' => $this->level($score),
            'vetoed' => false,
            'reasons' => array_values(array_unique($reasons)),
            'differences' => array_values(array_unique($differences)),
            'left' => $left,
            'right' => $right,
        ];
    }

    private function parseLlanta(Llanta $llanta): array
    {
        $parsed = $this->parser->parse(
            (string) $llanta->descripcion
        );

        return $this->applyDatabaseValues(
            parsed: $parsed,
            brand: $llanta->marca,
            size: $llanta->medida
        );
    }

    private function applyDatabaseValues(
        array $parsed,
        ?string $brand,
        ?string $size
    ): array {
        $normalizedBrand = $brand !== null
            ? $this->parser->normalize($brand)
            : null;

        $normalizedSize = $size !== null
            ? $this->parser->normalize($size)
            : null;

        if (
            $parsed['marca'] === 'GENERICA'
            && filled($normalizedBrand)
            && !in_array($normalizedBrand, ['GENERICA', 'N/A'], true)
        ) {
            $parsed['marca'] = $normalizedBrand;
        }

        if (
            $parsed['medida'] === 'N/A'
            && filled($normalizedSize)
            && !in_array($normalizedSize, ['GENERICA', 'N/A'], true)
        ) {
            $parsed['medida'] = str_replace('ZR', 'R', $normalizedSize);
        }

        return $parsed;
    }

    private function passesFastFilter(array $left, array $right): bool
    {
        /*
         * Cuando ambos tienen medida, únicamente se comparan dentro de la
         * misma medida. Evita miles de comparaciones innecesarias.
         */
        if (
            $left['medida'] !== 'N/A'
            && $right['medida'] !== 'N/A'
        ) {
            return $left['medida'] === $right['medida'];
        }

        /*
         * Si solamente uno tiene medida, no son el mismo producto.
         */
        if (($left['medida'] === 'N/A') !== ($right['medida'] === 'N/A')) {
            return false;
        }

        /*
         * Para productos sin medida, requiere alguna palabra importante
         * compartida.
         */
        return array_intersect(
            $left['tokens'],
            $right['tokens']
        ) !== [];
    }

    private function findVetoes(array $left, array $right): array
    {
        $vetoes = [];

        $this->vetoDifferentRequiredField(
            field: 'medida',
            label: 'medida',
            left: $left,
            right: $right,
            missingValues: ['N/A'],
            vetoes: $vetoes
        );

        $this->vetoDifferentRequiredField(
            field: 'marca',
            label: 'marca',
            left: $left,
            right: $right,
            missingValues: ['GENERICA'],
            vetoes: $vetoes
        );

        $this->vetoDifferentOptionalField(
            field: 'load_index',
            label: 'índice de carga',
            left: $left,
            right: $right,
            vetoes: $vetoes
        );

        $this->vetoDifferentOptionalField(
            field: 'speed_index',
            label: 'índice de velocidad',
            left: $left,
            right: $right,
            vetoes: $vetoes
        );

        $this->vetoDifferentOptionalField(
            field: 'ply_rating',
            label: 'número de capas',
            left: $left,
            right: $right,
            vetoes: $vetoes
        );

        $this->vetoDifferentOptionalField(
            field: 'construction',
            label: 'construcción TL/TT',
            left: $left,
            right: $right,
            vetoes: $vetoes
        );

        $this->vetoDifferentOptionalField(
            field: 'volume_ml',
            label: 'volumen',
            left: $left,
            right: $right,
            vetoes: $vetoes
        );

        $this->vetoDifferentOptionalField(
            field: 'category',
            label: 'categoría',
            left: $left,
            right: $right,
            vetoes: $vetoes
        );

        return $vetoes;
    }

    private function vetoDifferentRequiredField(
        string $field,
        string $label,
        array $left,
        array $right,
        array $missingValues,
        array &$vetoes
    ): void {
        $leftValue = $left[$field] ?? null;
        $rightValue = $right[$field] ?? null;

        if (
            in_array($leftValue, $missingValues, true)
            || in_array($rightValue, $missingValues, true)
        ) {
            return;
        }

        if ($leftValue !== $rightValue) {
            $vetoes[] = sprintf(
                '%s diferente: %s contra %s',
                ucfirst($label),
                (string) $leftValue,
                (string) $rightValue
            );
        }
    }

    private function vetoDifferentOptionalField(
        string $field,
        string $label,
        array $left,
        array $right,
        array &$vetoes
    ): void {
        $leftValue = $left[$field] ?? null;
        $rightValue = $right[$field] ?? null;

        if ($leftValue === null || $rightValue === null) {
            return;
        }

        if ($leftValue !== $rightValue) {
            $vetoes[] = sprintf(
                '%s diferente: %s contra %s',
                ucfirst($label),
                (string) $leftValue,
                (string) $rightValue
            );
        }
    }

    private function scoreOptionalExactField(
        string $field,
        string $label,
        float $points,
        array $left,
        array $right,
        array &$reasons
    ): float {
        $leftValue = $left[$field] ?? null;
        $rightValue = $right[$field] ?? null;

        if ($leftValue === null || $rightValue === null) {
            return 0.0;
        }

        if ($leftValue !== $rightValue) {
            return 0.0;
        }

        $reasons[] = 'Mismo ' . $label;

        return $points;
    }

    private function tokenSimilarity(array $left, array $right): float
    {
        if ($left === [] && $right === []) {
            return 1.0;
        }

        $intersection = array_intersect($left, $right);
        $union = array_unique(array_merge($left, $right));

        if ($union === []) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    private function level(float $score): string
    {
        if ($score >= 99.5) {
            return 'MUY ALTA';
        }

        if ($score >= 94) {
            return 'ALTA';
        }

        if ($score >= 86) {
            return 'REVISAR';
        }

        return 'BAJA';
    }
}