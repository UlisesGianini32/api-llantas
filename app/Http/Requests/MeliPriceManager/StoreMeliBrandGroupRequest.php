<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreMeliBrandGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name'));

        $this->merge([
            'name' => $name,
            'slug' => Str::slug($this->filled('slug') ? (string) $this->input('slug') : $name),
            'sort_order' => $this->input('sort_order', 0),
            'active' => $this->has('active') ? $this->boolean('active') : true,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:meli_brand_groups,slug'],
            'description' => ['nullable', 'string', 'max:4000'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
        ];
    }
}
