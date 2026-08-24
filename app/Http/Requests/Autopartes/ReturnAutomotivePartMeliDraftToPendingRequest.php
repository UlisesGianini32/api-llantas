<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartMeliDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReturnAutomotivePartMeliDraftToPendingRequest extends FormRequest
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
            if ($draft instanceof AutomotivePartMeliDraft
                && ! in_array($draft->status, ['approved', 'rejected'], true)) {
                $validator->errors()->add('draft', 'Solo un borrador aprobado o rechazado puede volver a revisión.');
            }
        }];
    }
}
