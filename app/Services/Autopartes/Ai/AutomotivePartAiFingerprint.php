<?php

namespace App\Services\Autopartes\Ai;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;

class AutomotivePartAiFingerprint
{
    public function __construct(private AutomotivePartAiPromptBuilder $promptBuilder) {}

    public function make(
        AutomotivePart $part,
        AutomotivePartEnrichmentReview $review,
        string $model,
        string $promptVersion,
    ): string {
        $payload = [
            'model' => $model,
            'prompt_version' => $promptVersion,
            'product' => $this->promptBuilder->inputSnapshot($part, $review),
            'review_state' => [
                'id' => $review->id,
                'status' => $review->status,
                'enrichment_source' => $review->enrichment_source,
                'proposed_title' => $review->proposed_title,
                'proposed_description' => $review->proposed_description,
                'proposed_brand' => $review->proposed_brand,
                'proposed_category' => $review->proposed_category,
                'proposed_compatibility' => $review->proposed_compatibility,
                'proposed_attributes' => $review->proposed_attributes,
                'updated_at' => $review->updated_at?->toJSON(),
            ],
        ];

        return hash('sha256', json_encode(
            $this->sortRecursively($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
