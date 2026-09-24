<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:100', 'regex:/\S/', Rule::unique('inventory_products', 'sku')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('inventory_products', 'barcode')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price_mercado_libre' => ['nullable', 'numeric', 'min:0'],
            'price_amazon' => ['nullable', 'numeric', 'min:0'],
            'price_stylist' => ['nullable', 'numeric', 'min:0'],
            'price_public' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => trim((string) $this->input('sku', '')),
            'barcode' => ($barcode = trim((string) $this->input('barcode', ''))) === '' ? null : $barcode,
        ]);
    }
}
