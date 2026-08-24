<?php

namespace App\Services\Autopartes\Drafts;

use Illuminate\Support\Str;

class AutomotivePartDraftValidator
{
    public function validate(array $context): array
    {
        $errors = [];
        $warnings = [];
        $review = $context['review'];
        $candidate = $context['candidate'];
        $category = $context['category'];
        $readiness = $context['readiness'];
        $payload = $context['payload'];
        $rules = $context['content_rules'];

        if (($review['status'] ?? null) !== 'approved') {
            $errors[] = $this->issue('missing_approved_enrichment', 'enrichment_review', 'Se requiere una revisión de enriquecimiento aprobada.');
        }
        if (($candidate['status'] ?? null) !== 'approved' || blank($candidate['reviewed_by'] ?? null) || blank($candidate['reviewed_at'] ?? null)) {
            $errors[] = $this->issue('missing_approved_category', 'category_id', 'Se requiere una categoría MLM aprobada por una persona.');
        }

        $categoryStale = $category === null
            || ($candidate['category_id'] ?? null) !== ($category['category_id'] ?? null)
            || ($readiness['approved_category_candidate_id'] ?? null) !== ($candidate['id'] ?? null)
            || blank($category['synced_at'] ?? null)
            || blank($category['attributes_synced_at'] ?? null)
            || data_get($category, 'settings.listing_allowed') === false;
        if ($categoryStale && ($candidate['status'] ?? null) === 'approved') {
            $errors[] = $this->issue('stale_category_mapping', 'category_id', 'El mapeo de categoría ya no coincide con los metadatos y readiness vigentes.');
        }

        $titleLength = mb_strlen(trim((string) ($payload['title'] ?? '')));
        if ($titleLength < $rules['title_min_length'] || $titleLength > $rules['title_max_length']) {
            $errors[] = $this->issue('invalid_title', 'title', 'El título no cumple los límites configurados.', [
                'length' => $titleLength,
                'min' => $rules['title_min_length'],
                'max' => $rules['title_max_length'],
            ]);
        }
        $descriptionLength = mb_strlen(trim((string) ($payload['description'] ?? '')));
        if ($descriptionLength < $rules['description_min_length'] || $descriptionLength > $rules['description_max_length']) {
            $errors[] = $this->issue('invalid_description', 'description', 'La descripción no cumple los límites configurados.', [
                'length' => $descriptionLength,
                'min' => $rules['description_min_length'],
                'max' => $rules['description_max_length'],
            ]);
        }
        if (! is_int($payload['stock'] ?? null) || $payload['stock'] < 0) {
            $errors[] = $this->issue('invalid_stock', 'stock', 'El stock debe ser un entero mayor o igual a cero.');
        }
        if (($payload['currency'] ?? null) !== 'MXN') {
            $errors[] = $this->issue('unsupported_currency', 'currency', 'El borrador solo admite moneda MXN.');
        }

        foreach ($context['price']['errors'] as $code) {
            $errors[] = match ($code) {
                'missing_exchange_rate' => $this->issue($code, 'price_mxn', 'No existe una tasa USD/MXN válida configurada.'),
                'missing_price_mxn' => $this->issue($code, 'price_mxn', 'No fue posible obtener un precio válido en MXN.'),
                'unsupported_currency' => $this->issue($code, 'currency', 'La moneda de origen o destino no está soportada.'),
                default => $this->issue($code, 'price_mxn', 'La configuración explícita de precio no es válida.'),
            };
        }
        if (($payload['prepared_images'] ?? []) === []) {
            $errors[] = $this->issue('missing_images', 'prepared_images', 'No existen imágenes respaldadas para la autoparte.');
        }

        $preparedAttributes = collect($payload['prepared_attributes'] ?? [])->keyBy(fn ($attribute) => $attribute['attribute_id'] ?? null);
        foreach ($context['requirements'] as $requirement) {
            if (! ($requirement['is_required'] || $requirement['is_catalog_required'])) {
                if ($requirement['is_conditional_required'] && ! $preparedAttributes->has($requirement['attribute_id'])) {
                    $warnings[] = $this->issue('missing_conditional_attribute', 'prepared_attributes', 'Falta revisar un atributo condicional.', [
                        'attribute_id' => $requirement['attribute_id'],
                    ]);
                }

                continue;
            }

            $prepared = $preparedAttributes->get($requirement['attribute_id']);
            if (! is_array($prepared) || blank($prepared['value'] ?? null)) {
                $errors[] = $this->issue('missing_required_attribute', 'prepared_attributes', 'Falta un atributo obligatorio respaldado.', [
                    'attribute_id' => $requirement['attribute_id'],
                    'attribute_name' => $requirement['name'],
                ]);
            }
        }

        if (data_get($readiness, 'compatibility_requirements.required') === true
            && ($payload['prepared_compatibilities'] ?? []) === []) {
            $errors[] = $this->issue('missing_compatibility', 'prepared_compatibilities', 'La categoría exige compatibilidad y no existe una propuesta aprobada suficiente.');
        }
        if (($readiness['status'] ?? null) !== 'ready') {
            $errors[] = $this->issue('readiness_not_ready', 'readiness', 'La Fase 4 todavía no tiene confirmación humana final.');
        }
        if ($context['source_is_stale'] ?? false) {
            $errors[] = $this->staleSourceIssue();
        }
        if (! in_array($payload['condition'] ?? null, ['new', 'used'], true)) {
            $errors[] = $this->issue('unsupported_condition', 'condition', 'La condición debe configurarse explícitamente como new o used.');
        }

        foreach ($readiness['warnings'] ?? [] as $warning) {
            if (is_string($warning) && filled($warning)) {
                $warnings[] = $this->issue('readiness_warning', 'readiness', Str::limit($warning, 500, ''));
            }
        }

        return [
            'blocking_errors' => $this->uniqueIssues($errors),
            'warnings' => $this->uniqueIssues($warnings),
        ];
    }

    public function staleSourceIssue(): array
    {
        return $this->issue('stale_source_data', 'fingerprint', 'Los datos fuente cambiaron desde la generación del borrador.');
    }

    private function issue(string $code, string $field, string $message, array $metadata = []): array
    {
        return compact('code', 'field', 'message', 'metadata');
    }

    private function uniqueIssues(array $issues): array
    {
        return collect($issues)
            ->unique(fn ($issue) => $issue['code'].'|'.$issue['field'].'|'.json_encode($issue['metadata']))
            ->values()
            ->all();
    }
}
