<?php

namespace App\Http\Requests;

use App\Models\InventoryProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canOperate() ?? false;
    }

    public function rules(): array
    {
        $product = $this->route('inventoryProduct');
        $productId = $product instanceof InventoryProduct ? $product->getKey() : $product;

        return [
            'sku' => ['required', 'string', 'max:100', 'regex:/\S/', Rule::unique('inventory_products', 'sku')->ignore($productId)],
            'product_type' => ['sometimes', 'string', Rule::in(InventoryProduct::TYPES)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('inventory_products', 'barcode')->ignore($productId)],
            'barcode_secondary' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'price_mercado_libre' => ['nullable', 'numeric', 'min:0'],
            'price_amazon' => ['nullable', 'numeric', 'min:0'],
            'price_stylist' => ['nullable', 'numeric', 'min:0'],
            'price_public' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'primary_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'secondary_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'reserve_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => trim((string) $this->input('sku', '')),
            'barcode' => ($barcode = trim((string) $this->input('barcode', ''))) === '' ? null : $barcode,
            'barcode_secondary' => ($barcodeSec = trim((string) $this->input('barcode_secondary', ''))) === '' ? null : $barcodeSec,
        ]);
    }
}
