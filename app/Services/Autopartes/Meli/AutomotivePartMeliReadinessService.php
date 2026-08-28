<?php

namespace App\Services\Autopartes\Meli;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliCategory;
use App\Models\AutomotivePartMeliReadiness;
use App\Models\User;

class AutomotivePartMeliReadinessService
{
    public function __construct(
        private AutomotivePartMeliAttributeMapper $mapper,
        private MercadoLibreCatalogMetadataClient $client,
    ) {}

    public function evaluate(AutomotivePart $part, bool $refreshMetadata = false): AutomotivePartMeliReadiness
    {
        $part->loadMissing('meliCategoryCandidates');
        $approved = $part->meliCategoryCandidates->firstWhere('status', 'approved');

        if ($approved === null) {
            return AutomotivePartMeliReadiness::query()->updateOrCreate(
                ['automotive_part_id' => $part->id],
                [
                    'approved_category_candidate_id' => null,
                    'status' => $part->meliCategoryCandidates->where('status', 'pending')->isNotEmpty() ? 'category_pending' : 'unmapped',
                    'proposed_attributes' => [],
                    'missing_required_attributes' => [],
                    'missing_conditional_attributes' => [],
                    'compatibility_requirements' => null,
                    'warnings' => [],
                    'evaluation_fingerprint' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'review_notes' => null,
                    'last_evaluated_at' => now(),
                ],
            );
        }

        $category = AutomotivePartMeliCategory::query()
            ->where('site_id', strtoupper((string) config('autopartes_meli.site_id', 'MLM')))
            ->where('category_id', $approved->category_id)
            ->with('attributeRequirements')
            ->firstOrFail();
        $mapped = $this->mapper->map($part, $category->attributeRequirements);
        [$compatibility, $compatibilityWarnings, $compatibilityBlocking] = $this->compatibility($part, $category, $refreshMetadata);
        $warnings = array_values(array_unique(array_merge($mapped['warnings'], $compatibilityWarnings)));
        $missingRequired = $mapped['missingRequired'];

        if ($compatibilityBlocking) {
            $missingRequired[] = ['attribute_id' => 'COMPATIBILITY', 'name' => 'Compatibilidad vehicular suficiente'];
        }

        $fingerprint = hash('sha256', json_encode([
            'candidate_id' => $approved->id,
            'attributes' => $mapped['proposed'],
            'missing_required' => $missingRequired,
            'missing_conditional' => $mapped['missingConditional'],
            'compatibility' => $compatibility,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $existing = AutomotivePartMeliReadiness::query()->where('automotive_part_id', $part->id)->first();
        $reviewStillValid = $existing?->evaluation_fingerprint === $fingerprint && $existing?->reviewed_at !== null;
        $status = $missingRequired !== [] ? 'incomplete' : ($reviewStillValid ? 'ready' : 'ready_for_review');

        return AutomotivePartMeliReadiness::query()->updateOrCreate(
            ['automotive_part_id' => $part->id],
            [
                'approved_category_candidate_id' => $approved->id,
                'status' => $status,
                'proposed_attributes' => $mapped['proposed'],
                'missing_required_attributes' => $missingRequired,
                'missing_conditional_attributes' => $mapped['missingConditional'],
                'compatibility_requirements' => $compatibility,
                'warnings' => $warnings,
                'evaluation_fingerprint' => $fingerprint,
                'reviewed_by' => $reviewStillValid ? $existing->reviewed_by : null,
                'reviewed_at' => $reviewStillValid ? $existing->reviewed_at : null,
                'review_notes' => $reviewStillValid ? $existing->review_notes : null,
                'last_evaluated_at' => now(),
            ],
        );
    }

    public function confirmReady(AutomotivePart $part, User $user, ?string $notes = null): AutomotivePartMeliReadiness
    {
        $readiness = $this->evaluate($part);
        if ($readiness->status !== 'ready_for_review') {
            throw new AutomotivePartMeliException(
                'La autoparte debe tener categoría y atributos obligatorios completos antes de la revisión final.',
                'not_ready_for_review',
            );
        }

        $readiness->forceFill([
            'status' => 'ready',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        return $readiness->fresh();
    }

    private function compatibility(
        AutomotivePart $part,
        AutomotivePartMeliCategory $category,
        bool $refreshMetadata,
    ): array {
        $domainId = strtoupper((string) $category->domain_id);
        $settingsText = json_encode($category->settings ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $isAutoparts = str_contains($domainId, 'VEHICLE')
            || str_contains($domainId, 'CAR_')
            || str_contains(mb_strtoupper((string) $settingsText), 'VEHICLE_PARTS_ACCESSORIES');

        if (! $isAutoparts) {
            return [['is_autoparts_domain' => false, 'domain_id' => $domainId ?: null], [], false];
        }

        $domainMetadata = null;
        $warnings = [];
        try {
            if ($domainId !== '') {
                $domainMetadata = $this->client->domain($domainId, $refreshMetadata);
            }
        } catch (AutomotivePartMeliException $exception) {
            $warnings[] = 'No fue posible confirmar los metadatos de compatibilidad del dominio.';
        }

        $raw = array_merge($category->raw_payload ?? [], $domainMetadata['payload'] ?? []);
        $explicitRequired = data_get($raw, 'settings.compatibilities_required')
            ?? data_get($raw, 'compatibilities.required')
            ?? data_get($raw, 'compatibility_required');
        $hasSource = filled($part->applicable_models_text) || filled($part->prevalent_model);

        if (! $hasSource) {
            $warnings[] = 'La categoría pertenece a autopartes, pero no existe compatibilidad suficiente en el origen.';
        }
        if ($explicitRequired === null) {
            $warnings[] = 'Los metadatos no declaran de forma inequívoca si la compatibilidad es obligatoria; requiere revisión humana.';
        }

        return [[
            'is_autoparts_domain' => true,
            'domain_id' => $domainId ?: null,
            'required' => is_bool($explicitRequired) ? $explicitRequired : null,
            'source_present' => $hasSource,
            'source' => [
                'prevalent_model' => $part->prevalent_model,
                'applicable_models_text' => $part->applicable_models_text,
                'min_model_year' => $part->min_model_year,
                'max_model_year' => $part->max_model_year,
            ],
            'domain_raw_payload' => $domainMetadata['payload'] ?? null,
        ], $warnings, $explicitRequired === true && ! $hasSource];
    }
}
