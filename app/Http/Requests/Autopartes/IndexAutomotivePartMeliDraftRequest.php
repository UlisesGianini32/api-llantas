<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAutomotivePartMeliDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_merge(AutomotivePartMeliDraft::STATUSES, ['not_generated']))],
            'error' => ['nullable', Rule::in(AutomotivePartMeliDraft::VALIDATION_CODES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
