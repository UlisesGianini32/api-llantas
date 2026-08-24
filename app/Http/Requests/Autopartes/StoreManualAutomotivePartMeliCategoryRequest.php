<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualAutomotivePartMeliCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string', 'regex:/^MLM\d+$/'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
            'refresh_metadata' => ['sometimes', 'boolean'],
        ];
    }
}
