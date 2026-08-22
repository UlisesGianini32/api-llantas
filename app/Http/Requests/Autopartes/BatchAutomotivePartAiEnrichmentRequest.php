<?php

namespace App\Http\Requests\Autopartes;

use App\Services\Autopartes\AutomotivePartEnrichmentAuditService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchAutomotivePartAiEnrichmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) config('autopartes_ai.max_batch', 10))],
            'issue' => ['nullable', 'string', Rule::in(AutomotivePartEnrichmentAuditService::ISSUE_CODES)],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
