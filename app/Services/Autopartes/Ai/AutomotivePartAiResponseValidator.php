<?php

namespace App\Services\Autopartes\Ai;

use Illuminate\Support\Str;

class AutomotivePartAiResponseValidator
{
    private const ROOT_FIELDS = [
        'language', 'title_es', 'description_es', 'brand_normalized',
        'manufacturer_part_number', 'category_suggestion', 'compatibility',
        'attributes', 'missing_facts', 'warnings', 'source_basis', 'confidence',
    ];

    private const COMPATIBILITY_FIELDS = ['make', 'model', 'year_from', 'year_to', 'notes'];

    private const ATTRIBUTE_FIELDS = ['name', 'value', 'unit', 'source_field'];

    public function validate(array $payload, array $input): array
    {
        $errors = [];
        $this->validateObjectKeys($payload, self::ROOT_FIELDS, '$', $errors);

        if (($payload['language'] ?? null) !== 'es-MX') {
            $errors[] = 'language debe ser exactamente es-MX.';
        }

        $this->validateNullableString($payload, 'title_es', 1, $this->titleMaxChars(), $errors);
        $this->validateNullableString($payload, 'description_es', 1, 3000, $errors);
        $this->validateNullableString($payload, 'brand_normalized', 1, 500, $errors);
        $this->validateNullableString($payload, 'manufacturer_part_number', 1, 500, $errors);
        $this->validateNullableString($payload, 'category_suggestion', 1, 500, $errors);

        if (! isset($payload['confidence']) || ! is_int($payload['confidence']) && ! is_float($payload['confidence'])) {
            $errors[] = 'confidence debe ser numérico.';
        } elseif ($payload['confidence'] < 0 || $payload['confidence'] > 1) {
            $errors[] = 'confidence debe estar entre 0 y 1.';
        }

        $this->validateStringArray($payload, 'missing_facts', 30, 500, $errors);
        $this->validateStringArray($payload, 'warnings', 30, 1000, $errors);
        $this->validateStringArray($payload, 'source_basis', 30, 500, $errors);
        foreach (is_array($payload['source_basis'] ?? null) ? $payload['source_basis'] : [] as $index => $sourceField) {
            if (! is_string($sourceField) || ! in_array($sourceField, AutomotivePartAiPromptBuilder::SOURCE_FIELDS, true)) {
                $errors[] = "source_basis.{$index} no es un campo de origen permitido.";
            }
        }
        $this->validateCompatibility($payload, $input, $errors);
        $this->validateAttributes($payload, $input, $errors);
        $this->validateManufacturerPartNumber($payload, $input, $errors);
        $this->validateBrand($payload, $input, $errors);
        $this->validateContentSafety($payload, $input, $errors);

        return array_values(array_unique($errors));
    }

    private function validateCompatibility(array $payload, array $input, array &$errors): void
    {
        if (! array_key_exists('compatibility', $payload) || ! is_array($payload['compatibility']) || ! array_is_list($payload['compatibility'])) {
            $errors[] = 'compatibility debe ser un arreglo.';

            return;
        }

        if (count($payload['compatibility']) > 25) {
            $errors[] = 'compatibility excede 25 elementos.';
        }

        $haystack = $this->normalize(implode(' ', array_filter([
            $input['applicable_models_text'] ?? null,
            $input['prevalent_model'] ?? null,
        ], fn ($value) => is_scalar($value) && trim((string) $value) !== '')));
        $sourceYears = array_values(array_filter([
            $input['min_model_year'] ?? null,
            $input['average_model_year'] ?? null,
            $input['max_model_year'] ?? null,
        ], fn ($year) => is_int($year)));
        $minimumYear = $sourceYears === [] ? null : min($sourceYears);
        $maximumYear = $sourceYears === [] ? null : max($sourceYears);

        foreach ($payload['compatibility'] as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $errors[] = "compatibility.{$index} debe ser un objeto.";

                continue;
            }

            $this->validateObjectKeys($item, self::COMPATIBILITY_FIELDS, "compatibility.{$index}", $errors);
            foreach (['make', 'model', 'notes'] as $field) {
                $this->validateNullableString($item, $field, 1, $field === 'notes' ? 1000 : 500, $errors, "compatibility.{$index}.");
            }

            foreach (['year_from', 'year_to'] as $field) {
                $year = $item[$field] ?? null;
                if ($year !== null && ! is_int($year)) {
                    $errors[] = "compatibility.{$index}.{$field} debe ser entero o null.";

                    continue;
                }

                if ($year !== null && ($year < 1900 || $year > 2100)) {
                    $errors[] = "compatibility.{$index}.{$field} está fuera del rango permitido.";
                }

                if ($year !== null && ($minimumYear === null || $year < $minimumYear || $year > $maximumYear)) {
                    $errors[] = "compatibility.{$index}.{$field} no está respaldado por los años de origen.";
                }
            }

            if (is_int($item['year_from'] ?? null) && is_int($item['year_to'] ?? null) && $item['year_from'] > $item['year_to']) {
                $errors[] = "compatibility.{$index} tiene un rango de años invertido.";
            }

            foreach (['make', 'model'] as $field) {
                $vehicle = $item[$field] ?? null;
                if (is_string($vehicle) && trim($vehicle) !== '') {
                    $normalizedVehicle = $this->normalize($vehicle);
                    if ($haystack === '' || ! str_contains($haystack, $normalizedVehicle)) {
                        $errors[] = "compatibility.{$index}.{$field} no aparece en la compatibilidad de origen.";
                    }
                }
            }
        }
    }

    private function validateAttributes(array $payload, array $input, array &$errors): void
    {
        if (! array_key_exists('attributes', $payload) || ! is_array($payload['attributes']) || ! array_is_list($payload['attributes'])) {
            $errors[] = 'attributes debe ser un arreglo.';

            return;
        }

        if (count($payload['attributes']) > 30) {
            $errors[] = 'attributes excede 30 elementos.';
        }

        foreach ($payload['attributes'] as $index => $attribute) {
            if (! is_array($attribute) || array_is_list($attribute)) {
                $errors[] = "attributes.{$index} debe ser un objeto.";

                continue;
            }

            $this->validateObjectKeys($attribute, self::ATTRIBUTE_FIELDS, "attributes.{$index}", $errors);
            foreach (['name', 'value', 'source_field'] as $field) {
                if (! isset($attribute[$field]) || ! is_string($attribute[$field]) || trim($attribute[$field]) === '') {
                    $errors[] = "attributes.{$index}.{$field} debe ser texto no vacío.";
                }
            }
            $this->validateNullableString($attribute, 'unit', 1, 50, $errors, "attributes.{$index}.");

            $sourceField = $attribute['source_field'] ?? null;
            if (! is_string($sourceField) || ! in_array($sourceField, AutomotivePartAiPromptBuilder::SOURCE_FIELDS, true)) {
                $errors[] = "attributes.{$index}.source_field no es un campo de origen permitido.";
            } elseif ($sourceField !== 'issue_codes' && $sourceField !== 'missing_fields' && blank($input[$sourceField] ?? null)) {
                $errors[] = "attributes.{$index}.source_field apunta a un dato de origen vacío.";
            }
        }
    }

    private function validateManufacturerPartNumber(array $payload, array $input, array &$errors): void
    {
        $original = $input['manufacturer_part_number'] ?? null;
        if (! is_string($original) || trim($original) === '') {
            return;
        }

        $proposed = $payload['manufacturer_part_number'] ?? null;
        if (! is_string($proposed) || trim($proposed) !== trim($original)) {
            $errors[] = 'manufacturer_part_number no coincide exactamente con el valor de origen.';
        }
    }

    private function validateBrand(array $payload, array $input, array &$errors): void
    {
        $brand = $payload['brand_normalized'] ?? null;
        if (! is_string($brand) || trim($brand) === '') {
            return;
        }

        $normalizedBrand = preg_replace('/[^a-z0-9]+/', '', $this->normalize($brand));
        $supported = collect([$input['vendor'] ?? null, $input['vendor_normalized'] ?? null])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => preg_replace('/[^a-z0-9]+/', '', $this->normalize($value)))
            ->contains($normalizedBrand);

        if (! $supported) {
            $errors[] = 'brand_normalized no está respaldada por la marca de origen.';
        }
    }

    private function validateContentSafety(array $payload, array $input, array &$errors): void
    {
        $outputText = $this->flattenStrings($payload);
        $sourceText = $this->normalize($this->flattenStrings($input));

        if (preg_match('/<\/?[a-z][^>]*>/iu', $outputText) === 1) {
            $errors[] = 'La respuesta contiene HTML.';
        }

        if (preg_match('/```|^\s{0,3}#{1,6}\s|!\[[^\]]*\]\(|\[[^\]]+\]\([^)]+\)|\*\*|__|^\s*[-*+]\s+/mu', $outputText) === 1) {
            $errors[] = 'La respuesta contiene Markdown.';
        }

        if (preg_match('/\b(ignore|ignora|instruction|instrucci[oó]n|system prompt|developer message|chain.of.thought)\b/iu', $outputText) === 1) {
            $errors[] = 'La respuesta contiene instrucciones o texto ajeno al resultado.';
        }

        if (is_string($payload['category_suggestion'] ?? null) && preg_match('/\bML[BM][A-Z]?\d+\b/i', $payload['category_suggestion']) === 1) {
            $errors[] = 'category_suggestion no puede contener un ID de Mercado Libre.';
        }

        $prohibitedClaims = [
            'oem' => '/\boem\b/iu',
            'original' => '/\boriginal(?:es)?\b/iu',
            'certificada' => '/\bcertificad[oa]s?\b/iu',
            'universal' => '/\buniversal(?:es)?\b/iu',
            'garantia' => '/\bgarant[ií]a\b/iu',
            'gtin' => '/\bgtin\b/iu',
            'material' => '/\bmaterial\b/iu',
            'posicion' => '/\bposici[oó]n\b/iu',
            'lado' => '/\blado\b/iu',
            'pais de origen' => '/\bpa[ií]s de origen\b/iu',
        ];

        foreach ($prohibitedClaims as $label => $pattern) {
            if (preg_match($pattern, $outputText) === 1 && preg_match($pattern, $sourceText) !== 1) {
                $errors[] = "La afirmación {$label} no está respaldada por el origen.";
            }
        }
    }

    private function validateObjectKeys(array $payload, array $expected, string $path, array &$errors): void
    {
        foreach ($expected as $field) {
            if (! array_key_exists($field, $payload)) {
                $errors[] = "{$path}.{$field} es requerido.";
            }
        }

        foreach (array_diff(array_keys($payload), $expected) as $field) {
            $errors[] = "{$path}.{$field} no está permitido.";
        }
    }

    private function validateNullableString(
        array $payload,
        string $field,
        int $minimum,
        int $maximum,
        array &$errors,
        string $prefix = '',
    ): void {
        if (! array_key_exists($field, $payload)) {
            return;
        }

        $value = $payload[$field];
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $errors[] = "{$prefix}{$field} debe ser texto o null.";

            return;
        }

        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            $errors[] = "{$prefix}{$field} debe medir entre {$minimum} y {$maximum} caracteres.";
        }
    }

    private function validateStringArray(array $payload, string $field, int $maxItems, int $maxLength, array &$errors): void
    {
        if (! array_key_exists($field, $payload) || ! is_array($payload[$field]) || ! array_is_list($payload[$field])) {
            $errors[] = "{$field} debe ser un arreglo.";

            return;
        }

        if (count($payload[$field]) > $maxItems) {
            $errors[] = "{$field} excede {$maxItems} elementos.";
        }

        foreach ($payload[$field] as $index => $value) {
            if (! is_string($value) || mb_strlen($value) > $maxLength) {
                $errors[] = "{$field}.{$index} debe ser texto de máximo {$maxLength} caracteres.";
            }
        }
    }

    private function flattenStrings(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        return implode(' ', array_map(fn ($item) => $this->flattenStrings($item), $value));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', Str::ascii($value)) ?? $value);
    }

    private function titleMaxChars(): int
    {
        return max(1, (int) config('autopartes_ai.title_max_chars', 60));
    }
}
