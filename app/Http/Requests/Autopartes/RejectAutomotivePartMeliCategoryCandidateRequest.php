<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliCategoryCandidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RejectAutomotivePartMeliCategoryCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['review_notes' => ['required', 'string', 'max:2000']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $candidate = $this->route('candidate');
            if ($candidate instanceof AutomotivePartMeliCategoryCandidate && $candidate->status !== 'pending') {
                $validator->errors()->add('candidate', 'Solo un candidato pendiente puede rechazarse.');
            }
        }];
    }
}
