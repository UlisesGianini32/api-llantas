<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutomotivePartEnrichmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proposed_title' => ['nullable', 'string', 'max:255'],
            'proposed_description' => ['nullable', 'string'],
            'proposed_brand' => ['nullable', 'string', 'max:255'],
            'proposed_category' => ['nullable', 'string', 'max:255'],
            'proposed_compatibility' => ['nullable', 'json'],
            'proposed_attributes' => ['nullable', 'json'],
            'confidence_score' => ['nullable', 'numeric', 'between:0,1'],
            'reviewer_notes' => ['nullable', 'string'],
        ];
    }
}
