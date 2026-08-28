<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class BatchAutomotivePartMeliCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) config('autopartes_meli.max_batch', 10))],
            'internal_category' => ['nullable', 'string', 'max:255'],
            'refresh_metadata' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
