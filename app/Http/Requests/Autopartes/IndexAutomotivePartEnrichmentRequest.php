<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartEnrichmentReview;
use App\Services\Autopartes\AutomotivePartEnrichmentAuditService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAutomotivePartEnrichmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(AutomotivePartEnrichmentReview::STATUSES)],
            'issue_code' => ['nullable', Rule::in(AutomotivePartEnrichmentAuditService::ISSUE_CODES)],
            'category' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
