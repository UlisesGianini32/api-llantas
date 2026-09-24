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
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'type' => [
                'required',
                'string',
                Rule::in(array_values(array_diff(
                    InventoryMovement::types(),
                    [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT],
                ))),
            ],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'external_key' => ['nullable', 'string', 'max:191'],
        ];
    }
}
