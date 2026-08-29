<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReassignMeliItemBrandRequest extends MeliItemAccountRequest
{
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
        ];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->validateStatus(
                $validator,
                ['categorized'],
                'Sólo se puede cambiar la marca de publicaciones categorizadas.',
            ),
        ];
    }
}
