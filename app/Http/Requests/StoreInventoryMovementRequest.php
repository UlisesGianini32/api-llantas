<?php

namespace App\Http\Requests;

use App\Models\InventoryMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'type' => [
                'required',
                'string',
                Rule::in(array_values(array_diff(
                    InventoryMovement::types(),
                    [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT],
                ))),
            ],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'external_key' => ['nullable', 'string', 'max:191'],

            // Modo individual
            'inventory_product_id' => ['required_without:items', 'nullable', 'integer', 'exists:inventory_products,id'],
            'quantity' => ['required_without:items', 'nullable', 'integer', 'min:1'],

            // Modo lote (múltiples productos a la vez)
            'items' => ['required_without:inventory_product_id', 'nullable', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required_with:items', 'integer', 'exists:inventory_products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.external_key' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_product_id.required_without' => 'Debes seleccionar al menos un producto o agregar productos a la lista.',
            'items.required_without' => 'Debes agregar al menos un producto a la lista.',
            'items.min' => 'Debes incluir al menos un producto en la lista.',
            'items.*.quantity.min' => 'La cantidad de cada producto debe ser mayor a cero.',
        ];
    }
}
