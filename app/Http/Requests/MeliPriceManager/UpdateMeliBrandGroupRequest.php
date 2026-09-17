<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBrandGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateMeliBrandGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'slug' => Str::slug((string) $this->input('slug')),
            'active' => $this->boolean('active'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var MeliBrandGroup $brand */
        $brand = $this->route('brand');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('meli_brand_groups', 'slug')->ignore($brand)],
            'description' => ['nullable', 'string', 'max:4000'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
        ];
    }
}
