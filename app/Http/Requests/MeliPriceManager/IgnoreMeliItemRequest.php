<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Validation\Validator;

class IgnoreMeliItemRequest extends MeliItemAccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->accountRules(),
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->validateStatus(
                $validator,
                ['uncategorized', 'suggested'],
                'Solo se pueden ignorar publicaciones pendientes.',
            ),
        ];
    }
}
