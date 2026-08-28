<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class RunAutomotivePartEnrichmentAuditRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('refresh_approved')) {
            $this->merge(['refresh_approved' => $this->boolean('refresh_approved')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'part_id' => ['nullable', 'integer', 'exists:automotive_parts,id'],
            'refresh_approved' => ['nullable', 'boolean'],
        ];
    }
}
