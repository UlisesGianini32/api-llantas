<?php

namespace App\Services\Llantas;

use App\Models\Llanta;

class LlantaComparisonService
{
    public function __construct(
        private LlantaDescriptionParser $parser,
        private LlantaDuplicateDetectorService $detector,
    ) {}

    /**
     * Devuelve los datos normalizados que se usan únicamente para comparar.
     * Los valores originales de la llanta nunca se modifican.
     */
    public function parse(Llanta $llanta): array
    {
        $parsed = $this->detector->parseLlanta($llanta);
        if (filled($llanta->marca)) {
            $parsed['marca'] = $this->parser->normalize((string) $llanta->marca);
        }
        if (filled($llanta->medida)) {
            $parsed['medida'] = $this->normalizeMeasure((string) $llanta->medida);
        }
        $parsed['model_signature'] = $this->modelSignature($llanta, $parsed);

        return $parsed;
    }

    public function compare(Llanta $left, Llanta $right): array
    {
        return $this->compareParsed(
            $this->parse($left),
            $this->parse($right)
        );
    }

    public function compareParsed(array $left, array $right): array
    {
        return $this->detector->compareParsedForComparison($left, $right);
    }

    /**
     * Los pares se guardan siempre en el mismo sentido para evitar A/B y B/A.
     *
     * @return array{llanta_a_id: int, llanta_b_id: int}
     */
    public function canonicalPair(int $leftId, int $rightId): array
    {
        return $leftId < $rightId
            ? ['llanta_a_id' => $leftId, 'llanta_b_id' => $rightId]
            : ['llanta_a_id' => $rightId, 'llanta_b_id' => $leftId];
    }

    /**
     * Atributos técnicos ya extraídos por LlantaDescriptionParser para la UI.
     */
    public function technicalAttributes(array $parsed): array
    {
        return [
            'load_index' => $parsed['load_index'] ?? null,
            'speed_index' => $parsed['speed_index'] ?? null,
            'ply_rating' => $parsed['ply_rating'] ?? null,
            'construction' => $parsed['construction'] ?? null,
            'category' => $parsed['category'] ?? null,
            'is_xl' => (bool) ($parsed['is_xl'] ?? false),
            'is_lt' => (bool) ($parsed['is_lt'] ?? false),
            'is_rwl' => (bool) ($parsed['is_rwl'] ?? false),
        ];
    }

    private function modelSignature(Llanta $llanta, array $parsed): string
    {
        $text = $this->parser->normalize(implode(' ', array_filter([
            (string) ($llanta->descripcion ?? ''),
            (string) ($llanta->title_familyname ?? ''),
        ])));

        foreach ([$parsed['marca'] ?? null, $parsed['medida'] ?? null] as $value) {
            if (filled($value) && ! in_array($value, ['GENERICA', 'N/A'], true)) {
                $text = str_replace((string) $value, ' ', $text);
            }
        }

        $text = preg_replace('/\b\d{3}\s*(?:\/|\s)\s*\d{2}\s*R?\s*\d{2}(?:\.5)?\b/', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d{2,3}\/\d{2,3}[A-Z]?\b/', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d{2,3}\s*[A-Z]\b/', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:XL|LT|TL|TT|RWL|RADIAL|LLANTA)\b/', ' ', $text) ?? $text;

        return (string) (preg_replace('/[^A-Z0-9]/', '', $text) ?? '');
    }

    private function normalizeMeasure(string $measure): string
    {
        $value = $this->parser->normalize($measure);
        $value = str_replace('ZR', 'R', $value);

        return (string) (preg_replace('/\s+/', '', $value) ?? $value);
    }
}
