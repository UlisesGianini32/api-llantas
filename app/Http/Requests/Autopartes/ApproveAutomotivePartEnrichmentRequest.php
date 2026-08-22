<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAutomotivePartEnrichmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reviewer_notes' => ['nullable', 'string'],
        ];
    }
}
