<?php

namespace App\Services\Autopartes\Ai;

use App\Models\AutomotivePartEnrichmentReview;

class AutomotivePartAiEligibility
{
    public function reason(AutomotivePartEnrichmentReview $review): ?string
    {
        if (in_array($review->status, ['approved', 'rejected'], true)) {
            return 'La revisión ya tiene una decisión humana final.';
        }

        if ($review->status !== 'pending') {
            return 'Solo las revisiones pendientes pueden procesarse con IA.';
        }

        if ($review->enrichment_source === 'manual') {
            return 'La propuesta fue creada o editada manualmente.';
        }

        $part = $review->automotivePart;
        if ($part === null) {
            return 'La revisión no tiene una autoparte asociada.';
        }

        $hasUsefulData = collect([
            $part->item_number,
            $part->manufacturer_part_number,
            $part->vendor,
            $part->description_original,
            $part->applicable_models_text,
        ])->contains(fn ($value) => is_string($value) && trim($value) !== '');

        return $hasUsefulData ? null : 'El producto no contiene datos suficientes para generar una propuesta.';
    }
}
