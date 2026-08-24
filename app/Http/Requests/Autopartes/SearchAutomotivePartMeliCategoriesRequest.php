<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class SearchAutomotivePartMeliCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'refresh_metadata' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
