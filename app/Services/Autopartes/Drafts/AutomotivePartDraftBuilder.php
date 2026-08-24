<?php

namespace App\Services\Autopartes\Drafts;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliCategory;

class AutomotivePartDraftBuilder
{
    public function __construct(
        private AutomotivePartDraftConfiguration $configuration,
        private AutomotivePartDraftPriceCalculator $prices,
        private AutomotivePartDraftFingerprint $fingerprints,
        private AutomotivePartDraftValidator $validator,
    ) {}

    public function preview(AutomotivePart $part): array
    {
        $part->load([
            'enrichmentReview',
            'meliCategoryCandidates',
            'meliReadiness.approvedCategoryCandidate',
        ]);

        $review = $part->enrichmentReview;
        $readiness = $part->meliReadiness;
        $candidate = $readiness?->approvedCategoryCandidate;
        if ($candidate?->status !== 'approved') {
            $candidate = $part->meliCategoryCandidates
                ->where('status', 'approved')
                ->sortByDesc('id')
                ->first();
        }

        $category = $candidate === null ? null : AutomotivePartMeliCategory::query()
            ->with('attributeRequirements')
            ->where('site_id', strtoupper((string) config('autopartes_meli.site_id', 'MLM')))
            ->where('category_id', $candidate->category_id)
            ->first();
        $requirements = $category?->attributeRequirements?->sortBy('attribute_id')->values() ?? collect();
        $price = $this->prices->calculate($part);
        $contentRules = $this->configuration->contentRules();
        $images = collect($this->configuration->imagesFor($part))
            ->sort()
            ->values()
            ->map(fn (string $url) => ['url' => $url, 'source' => 'configured_source_key'])
            ->all();
        $attributes = collect($readiness?->proposed_attributes ?? [])
            ->filter(fn ($attribute) => is_array($attribute) && filled($attribute['attribute_id'] ?? null) && filled($attribute['value'] ?? null))
            ->sortBy('attribute_id')
            ->values()
            ->all();
        $compatibilities = $review?->status === 'approved' && is_array($review->proposed_compatibility)
            ? array_values($review->proposed_compatibility)
            : [];

        $payload = [
            'category_id' => $candidate?->category_id,
            'category_name' => $candidate?->category_name,
            'domain_id' => $candidate?->domain_id ?? $category?->domain_id,
            'title' => $review?->proposed_title,
            'description' => $review?->proposed_description,
            'price_mxn' => $price['price_mxn'],
            'stock' => is_int($part->quantity) ? $part->quantity : (is_numeric($part->quantity) ? (int) $part->quantity : null),
            'currency' => $price['rules']['currency'],
            'condition' => $contentRules['condition'],
            'prepared_attributes' => $attributes,
            'prepared_compatibilities' => $compatibilities,
            'prepared_images' => $images,
        ];
        $reviewSnapshot = [
            'id' => $review?->id,
            'status' => $review?->status,
            'proposed_title' => $review?->proposed_title,
            'proposed_description' => $review?->proposed_description,
            'proposed_brand' => $review?->proposed_brand,
            'proposed_compatibility' => $review?->proposed_compatibility,
            'proposed_attributes' => $review?->proposed_attributes,
            'enrichment_source' => $review?->enrichment_source,
            'reviewed_by' => $review?->reviewed_by,
            'reviewed_at' => $review?->reviewed_at?->toJSON(),
            'updated_at' => $review?->updated_at?->toJSON(),
        ];
        $candidateSnapshot = [
            'id' => $candidate?->id,
            'status' => $candidate?->status,
            'category_id' => $candidate?->category_id,
            'category_name' => $candidate?->category_name,
            'domain_id' => $candidate?->domain_id,
            'reviewed_by' => $candidate?->reviewed_by,
            'reviewed_at' => $candidate?->reviewed_at?->toJSON(),
            'updated_at' => $candidate?->updated_at?->toJSON(),
        ];
        $categorySnapshot = $category === null ? null : [
            'id' => $category->id,
            'site_id' => $category->site_id,
            'category_id' => $category->category_id,
            'name' => $category->name,
            'domain_id' => $category->domain_id,
            'path_from_root' => $category->path_from_root,
            'settings' => $category->settings,
            'synced_at' => $category->synced_at?->toJSON(),
            'attributes_synced_at' => $category->attributes_synced_at?->toJSON(),
        ];
        $readinessSnapshot = [
            'id' => $readiness?->id,
            'status' => $readiness?->status,
            'approved_category_candidate_id' => $readiness?->approved_category_candidate_id,
            'evaluation_fingerprint' => $readiness?->evaluation_fingerprint,
            'compatibility_requirements' => $readiness?->compatibility_requirements,
            'missing_required_attributes' => $readiness?->missing_required_attributes,
            'missing_conditional_attributes' => $readiness?->missing_conditional_attributes,
            'warnings' => $readiness?->warnings,
            'reviewed_by' => $readiness?->reviewed_by,
            'reviewed_at' => $readiness?->reviewed_at?->toJSON(),
            'last_evaluated_at' => $readiness?->last_evaluated_at?->toJSON(),
            'updated_at' => $readiness?->updated_at?->toJSON(),
        ];
        $requirementSnapshot = $requirements->map(fn ($requirement) => [
            'attribute_id' => $requirement->attribute_id,
            'name' => $requirement->name,
            'is_required' => $requirement->is_required,
            'is_catalog_required' => $requirement->is_catalog_required,
            'is_conditional_required' => $requirement->is_conditional_required,
            'tags' => $requirement->tags,
            'allowed_values' => $requirement->allowed_values,
            'updated_at' => $requirement->updated_at?->toJSON(),
        ])->all();
        $snapshot = [
            'automotive_part' => [
                'id' => $part->id,
                'source_key' => $part->source_key,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'vendor' => $part->vendor,
                'vendor_normalized' => $part->vendor_normalized,
                'description_original' => $part->description_original,
                'quantity' => $part->quantity,
                'original_currency' => $part->original_currency,
                'retail_price_original' => $part->retail_price_original,
                'prevalent_model' => $part->prevalent_model,
                'applicable_models_text' => $part->applicable_models_text,
                'min_model_year' => $part->min_model_year,
                'max_model_year' => $part->max_model_year,
                'updated_at' => $part->updated_at?->toJSON(),
            ],
            'enrichment_review' => $reviewSnapshot,
            'approved_category_candidate' => $candidateSnapshot,
            'category_snapshot' => $categorySnapshot,
            'attribute_requirements' => $requirementSnapshot,
            'readiness' => $readinessSnapshot,
            'price' => $price,
            'configuration' => array_merge($contentRules, [
                'pricing' => $price['rules'],
                'images_for_source_key' => $images,
            ]),
        ];
        $context = [
            'review' => $reviewSnapshot,
            'candidate' => $candidateSnapshot,
            'category' => $categorySnapshot,
            'readiness' => $readinessSnapshot,
            'requirements' => $requirementSnapshot,
            'price' => $price,
            'content_rules' => $contentRules,
            'payload' => $payload,
            'source_is_stale' => $readiness !== null && collect([
                $part->updated_at,
                $review?->updated_at,
                $candidate?->updated_at,
                $category?->updated_at,
                $category?->attributes_synced_at,
                ...$requirements->pluck('updated_at')->all(),
            ])->filter()->contains(fn ($timestamp) => $readiness->last_evaluated_at === null || $timestamp->gt($readiness->last_evaluated_at)),
        ];
        $validation = $this->validator->validate($context);
        $fingerprint = $this->fingerprints->make(['snapshot' => $snapshot, 'payload' => $payload]);

        return [
            'automotive_part_id' => $part->id,
            'automotive_part_enrichment_review_id' => $review?->id,
            'approved_category_candidate_id' => $candidate?->id,
            'payload' => $payload,
            'source_snapshot' => $snapshot,
            'fingerprint' => $fingerprint,
            'blocking_errors' => $validation['blocking_errors'],
            'warnings' => $validation['warnings'],
            'eligible' => $validation['blocking_errors'] === [],
            'suggested_status' => $validation['blocking_errors'] === [] ? 'pending_review' : 'incomplete',
        ];
    }
}
