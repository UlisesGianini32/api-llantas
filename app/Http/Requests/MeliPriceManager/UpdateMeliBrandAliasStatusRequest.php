<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeliBrandAliasStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }
}
