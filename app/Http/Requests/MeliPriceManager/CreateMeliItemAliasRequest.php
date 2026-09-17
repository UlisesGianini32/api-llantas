<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBrandAlias;
use App\Services\MercadoLibre\PriceManager\MeliBrandNormalizer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateMeliItemAliasRequest extends MeliItemAccountRequest
{
    protected function prepareForValidation(): void
    {
        $alias = trim((string) $this->input('alias'));

        $this->merge([
            'alias' => $alias,
            'normalized_alias' => app(MeliBrandNormalizer::class)->normalize($alias),
            'priority' => $this->input('priority', 0),
            'active' => $this->has('active') ? $this->boolean('active') : true,
            'confirm_conflict' => $this->boolean('confirm_conflict'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->accountRules(),
            'brand_group_id' => [
                'required',
                'integer',
                Rule::exists('meli_brand_groups', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'alias' => ['required', 'string', 'max:255'],
            'normalized_alias' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(MeliBrandAlias::MATCH_TYPES)],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'active' => ['required', 'boolean'],
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

                if ($validator->errors()->hasAny(['brand_group_id', 'normalized_alias'])) {
                    return;
                }

                $conflicts = MeliBrandAlias::query()
                    ->where('normalized_alias', $this->string('normalized_alias')->toString())
                    ->where('brand_group_id', '!=', $this->integer('brand_group_id'))
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
