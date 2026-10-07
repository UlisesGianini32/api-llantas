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
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'destination_location_id' => ['required_if:type,TRANSFER', 'nullable', 'integer', 'exists:inventory_locations,id'],
            'type' => [
                'required',
                'string',
                Rule::in([
                    ...array_values(array_diff(
                        InventoryMovement::types(),
                        [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT],
                    )),
                    'TRANSFER',
                ]),
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
            'items.*.inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.external_key' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $globalLoc = $this->input('inventory_location_id');
            $items = $this->input('items');

            if (! empty($items) && is_array($items)) {
                foreach ($items as $idx => $item) {
                    $itemLoc = $item['inventory_location_id'] ?? null;
                    if (empty($itemLoc) && empty($globalLoc)) {
                        $prod = \App\Models\InventoryProduct::find($item['inventory_product_id'] ?? null);
                        if (! $prod || ! $prod->primary_location_id) {
                            $name = $prod?->name ?: 'Item #'.($idx + 1);
                            $validator->errors()->add(
                                "items.{$idx}.inventory_location_id",
                                "El producto '{$name}' no tiene ubicación asignada. Selecciona una ubicación para este producto o una ubicación general."
                            );
                        }
                    }
                }
            } elseif ($this->filled('inventory_product_id') && empty($globalLoc)) {
                $prod = \App\Models\InventoryProduct::find($this->input('inventory_product_id'));
                if (! $prod || ! $prod->primary_location_id) {
                    $validator->errors()->add(
                        'inventory_location_id',
                        'Debes seleccionar una ubicación de almacén o el producto debe tener una asignada.'
                    );
                }
            }

            if ($this->input('type') === 'TRANSFER') {
                $destLoc = (int) $this->input('destination_location_id');
                if (! $destLoc) {
                    $validator->errors()->add('destination_location_id', 'Debes seleccionar la ubicación destino para mover los productos.');
                }
                if ($globalLoc && $destLoc && (int) $globalLoc === $destLoc) {
                    $validator->errors()->add('destination_location_id', 'La ubicación destino debe ser distinta a la ubicación de origen.');
                }
            }
        });
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
