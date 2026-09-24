<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryReservationRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1'],
            'source_type' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'external_key' => ['nullable', 'string', 'max:191'],
            'expires_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
