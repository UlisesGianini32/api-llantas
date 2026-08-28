<?php

namespace App\Http\Requests\Autopartes;

use Illuminate\Foundation\Http\FormRequest;

class IndexAutomotivePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_number' => ['nullable', 'string'],
            'manufacturer_part_number' => ['nullable', 'string'],
            'vendor' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'subcategory' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'stock' => ['nullable', 'string'],
            'sort' => ['nullable', 'in:item_number,manufacturer_part_number,vendor,category,subcategory,quantity,retail_price_original,last_imported_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
