<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class GenerateAutomotivePartMeliDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['force' => ['sometimes', 'boolean']];
    }
}
