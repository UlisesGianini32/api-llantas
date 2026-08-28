<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ApproveAutomotivePartMeliDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['review_notes' => ['nullable', 'string', 'max:2000']];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $draft = $this->route('draft');
            if ($draft instanceof AutomotivePartMeliDraft && $draft->status !== 'pending_review') {
                $validator->errors()->add('draft', 'Solo puede aprobarse un borrador pendiente de revisión.');
            }
            if ($draft instanceof AutomotivePartMeliDraft && $draft->hasBlockingErrors()) {
                $validator->errors()->add('draft', 'El borrador contiene errores bloqueantes.');
            }
        }];
    }
}
