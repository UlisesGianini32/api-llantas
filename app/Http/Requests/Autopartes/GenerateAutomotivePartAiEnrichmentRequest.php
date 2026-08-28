<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartEnrichmentReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateAutomotivePartAiEnrichmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $review = $this->route('review');
            if (! $review instanceof AutomotivePartEnrichmentReview) {
                return;
            }

            if ($review->status !== 'pending') {
                $validator->errors()->add('review', 'Solo una revisión pendiente puede enviarse a IA.');
            }

            if ($review->enrichment_source === 'manual') {
                $validator->errors()->add('review', 'Una propuesta manual no puede sobrescribirse con IA.');
            }
        }];
    }
}
