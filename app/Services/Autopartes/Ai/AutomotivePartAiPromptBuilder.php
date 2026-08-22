<?php

namespace App\Services\Autopartes\Ai;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;

class AutomotivePartAiPromptBuilder
{
    public const SCHEMA_NAME = 'automotive_part_enrichment_v1';

    public const SOURCE_FIELDS = [
        'item_number',
        'manufacturer_part_number',
        'vendor',
        'vendor_normalized',
        'category',
        'subcategory',
        'description_original',
        'description_normalized',
        'quantity',
        'retail_price_original',
        'min_model_year',
        'average_model_year',
        'max_model_year',
        'prevalent_model',
        'applicable_models_text',
        'length_inches',
        'width_inches',
        'height_inches',
        'cubic_inches',
        'weight_pounds',
        'length_cm',
        'width_cm',
        'height_cm',
        'weight_kg',
        'lifecycle',
        'missing_fields',
        'issue_codes',
    ];

    public function inputSnapshot(
        AutomotivePart $part,
        AutomotivePartEnrichmentReview $review,
    ): array {
        return [
            'item_number' => $part->item_number,
            'manufacturer_part_number' => $part->manufacturer_part_number,
            'vendor' => $part->vendor,
            'vendor_normalized' => $part->vendor_normalized,
            'category' => $part->category,
            'subcategory' => $part->subcategory,
            'description_original' => $part->description_original,
            'description_normalized' => $part->description_normalized,
            'quantity' => $part->quantity,
            'retail_price_original' => $part->retail_price_original,
            'min_model_year' => $part->min_model_year,
            'average_model_year' => $part->average_model_year,
            'max_model_year' => $part->max_model_year,
            'prevalent_model' => $part->prevalent_model,
            'applicable_models_text' => $part->applicable_models_text,
            'length_inches' => $part->length_inches,
            'width_inches' => $part->width_inches,
            'height_inches' => $part->height_inches,
            'cubic_inches' => $part->cubic_inches,
            'weight_pounds' => $part->weight_pounds,
            'length_cm' => $part->length_cm,
            'width_cm' => $part->width_cm,
            'height_cm' => $part->height_cm,
            'weight_kg' => $part->weight_kg,
            'lifecycle' => $part->lifecycle,
            'missing_fields' => $part->missing_fields,
            'issue_codes' => $review->issue_codes ?? [],
        ];
    }

    public function requestPayload(array $inputSnapshot, string $model): array
    {
        return [
            'model' => $model,
            'store' => false,
            'instructions' => $this->instructions(),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => json_encode($inputSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
        ];
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
Eres un asistente de enriquecimiento de catálogo de autopartes. Devuelve exclusivamente el JSON solicitado, sin Markdown ni texto externo. Escribe español mexicano neutral, profesional y apropiado para comercio electrónico. Conserva exactamente marcas, modelos, años y números de parte; no traduzcas marcas ni números de parte. Usa solo los datos suministrados. No inventes ni amplíes compatibilidad o rangos de años. No afirmes que una pieza es OEM, original, certificada o universal salvo que el origen lo indique. No inventes garantía, GTIN, material, posición, lado, país de origen ni condición. Usa las conversiones normalizadas proporcionadas y no recalcules unidades sin necesidad. Cuando falten datos, decláralos en missing_facts; cuando exista incertidumbre, declárala en warnings. Si no hay evidencia suficiente, usa null o un arreglo vacío. category_suggestion debe ser una categoría interna descriptiva en español, nunca un ID de Mercado Libre. No incluyas razonamiento interno ni chain-of-thought.
PROMPT;
    }

    public function schema(): array
    {
        $nullableString = ['type' => ['string', 'null'], 'maxLength' => 500];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'language', 'title_es', 'description_es', 'brand_normalized',
                'manufacturer_part_number', 'category_suggestion', 'compatibility',
                'attributes', 'missing_facts', 'warnings', 'source_basis', 'confidence',
            ],
            'properties' => [
                'language' => ['type' => 'string', 'enum' => ['es-MX']],
                'title_es' => ['type' => ['string', 'null'], 'maxLength' => max(1, (int) config('autopartes_ai.title_max_chars', 60))],
                'description_es' => ['type' => ['string', 'null'], 'maxLength' => 3000],
                'brand_normalized' => $nullableString,
                'manufacturer_part_number' => $nullableString,
                'category_suggestion' => $nullableString,
                'compatibility' => [
                    'type' => 'array',
                    'maxItems' => 25,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['make', 'model', 'year_from', 'year_to', 'notes'],
                        'properties' => [
                            'make' => $nullableString,
                            'model' => $nullableString,
                            'year_from' => ['type' => ['integer', 'null'], 'minimum' => 1900, 'maximum' => 2100],
                            'year_to' => ['type' => ['integer', 'null'], 'minimum' => 1900, 'maximum' => 2100],
                            'notes' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                        ],
                    ],
                ],
                'attributes' => [
                    'type' => 'array',
                    'maxItems' => 30,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'value', 'unit', 'source_field'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                            'value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                            'unit' => ['type' => ['string', 'null'], 'maxLength' => 50],
                            'source_field' => ['type' => 'string', 'enum' => self::SOURCE_FIELDS],
                        ],
                    ],
                ],
                'missing_facts' => $this->stringArraySchema(30, 500),
                'warnings' => $this->stringArraySchema(30, 1000),
                'source_basis' => $this->stringArraySchema(30, 500),
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    private function stringArraySchema(int $maxItems, int $maxLength): array
    {
        return [
            'type' => 'array',
            'maxItems' => $maxItems,
            'items' => ['type' => 'string', 'maxLength' => $maxLength],
        ];
    }
}
