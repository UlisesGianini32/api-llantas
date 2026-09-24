<?php

namespace App\Http\Requests;

use App\Models\InventoryLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $location = $this->route('inventoryLocation');
        $locationId = $location instanceof InventoryLocation ? $location->getKey() : $location;

        return [
            'code' => ['required', 'string', 'max:100', 'regex:/\S/', Rule::unique('inventory_locations', 'code')->ignore($locationId)],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code', '')))]);
    }
}
