<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliCategoryCandidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ApproveAutomotivePartMeliCategoryCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'refresh_metadata' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $candidate = $this->route('candidate');
            if ($candidate instanceof AutomotivePartMeliCategoryCandidate && $candidate->status !== 'pending') {
                $validator->errors()->add('candidate', 'Solo un candidato pendiente puede aprobarse.');
            }
        }];
    }
}
