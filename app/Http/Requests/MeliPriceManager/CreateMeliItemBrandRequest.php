<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBrandAlias;
use App\Services\MercadoLibre\PriceManager\MeliBrandNormalizer;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateMeliItemBrandRequest extends MeliItemAccountRequest
{
    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name'));
        $alias = trim((string) $this->input('alias'));

        $this->merge([
            'name' => $name,
            'slug' => Str::slug($this->filled('slug') ? (string) $this->input('slug') : $name),
            'active' => $this->has('active') ? $this->boolean('active') : true,
            'sort_order' => $this->input('sort_order', 0),
            'create_alias' => $this->boolean('create_alias'),
            'alias' => $alias,
            'normalized_alias' => app(MeliBrandNormalizer::class)->normalize($alias),
            'alias_priority' => $this->input('alias_priority', 0),
            'alias_active' => $this->has('alias_active') ? $this->boolean('alias_active') : true,
            'confirm_conflict' => $this->boolean('confirm_conflict'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->accountRules(),
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:meli_brand_groups,slug'],
            'description' => ['nullable', 'string', 'max:4000'],
            'active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer'],
            'create_alias' => ['required', 'boolean'],
            'alias' => ['nullable', 'required_if:create_alias,true', 'string', 'max:255'],
            'normalized_alias' => ['nullable', 'required_if:create_alias,true', 'string', 'max:255'],
            'match_type' => ['nullable', 'required_if:create_alias,true', Rule::in(MeliBrandAlias::MATCH_TYPES)],
            'alias_priority' => ['nullable', 'required_if:create_alias,true', 'integer', 'min:0', 'max:1000'],
            'alias_active' => ['required', 'boolean'],
            'confirm_conflict' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $this->validateStatus(
                    $validator,
                    ['uncategorized', 'suggested', 'ignored'],
                    'Solo se pueden asignar publicaciones pendientes o ignoradas desde esta bandeja.',
                );

                if (! $this->boolean('create_alias') || $validator->errors()->has('normalized_alias')) {
                    return;
                }

                $conflicts = MeliBrandAlias::query()
                    ->where('normalized_alias', $this->string('normalized_alias')->toString())
                    ->with('brandGroup:id,name')
                    ->get()
                    ->pluck('brandGroup.name')
                    ->filter()
                    ->unique()
                    ->values();

                if ($conflicts->isNotEmpty() && ! $this->boolean('confirm_conflict')) {
                    $validator->errors()->add(
                        'confirm_conflict',
                        'Este alias ya existe en '.$conflicts->join(', ').' y podría generar resultados ambiguos. Confirma para continuar.',
                    );
                }
            },
        ];
    }
}
