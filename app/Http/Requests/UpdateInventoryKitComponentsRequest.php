<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryKitComponentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'components' => ['nullable', 'array'],
            'components.*.component_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'components.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
