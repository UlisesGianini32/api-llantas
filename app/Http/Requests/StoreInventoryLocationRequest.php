<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100', 'regex:/\S/', Rule::unique('inventory_locations', 'code')],
            'name' => ['nullable', 'string', 'max:255'],
            'amazon_aisle' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code', ''))),
            'amazon_aisle' => ($aisle = mb_strtoupper(trim((string) $this->input('amazon_aisle', '')))) === '' ? null : $aisle,
        ]);
    }
}
