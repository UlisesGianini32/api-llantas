<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RejectAutomotivePartMeliDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['review_notes' => ['required', 'string', 'min:3', 'max:2000']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $draft = $this->route('draft');
            if ($draft instanceof AutomotivePartMeliDraft
                && ! in_array($draft->status, ['draft', 'incomplete', 'pending_review'], true)) {
                $validator->errors()->add('draft', 'El estado actual no permite rechazar el borrador.');
            }
        }];
    }
}
