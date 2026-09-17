<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Services\MercadoLibre\PriceManager\MeliBrandNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeliBrandAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $alias = trim((string) $this->input('alias'));

        $this->merge([
            'alias' => $alias,
            'normalized_alias' => app(MeliBrandNormalizer::class)->normalize($alias),
            'priority' => $this->input('priority', 0),
            'active' => $this->has('active') ? $this->boolean('active') : true,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var MeliBrandGroup $brand */
        $brand = $this->route('brand');

        return [
            'alias' => ['required', 'string', 'max:255'],
            'normalized_alias' => [
                'required',
                'string',
                'max:255',
                Rule::unique('meli_brand_aliases', 'normalized_alias')
                    ->where(fn ($query) => $query->where('brand_group_id', $brand->id)),
            ],
            'match_type' => ['required', Rule::in(MeliBrandAlias::MATCH_TYPES)],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'normalized_alias.unique' => 'Ya existe un alias equivalente en esta marca.',
            'normalized_alias.required' => 'El alias debe contener letras o números.',
        ];
    }
}
