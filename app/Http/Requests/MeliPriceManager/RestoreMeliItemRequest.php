<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Validation\Validator;

class RestoreMeliItemRequest extends MeliItemAccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->accountRules();
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            fn (Validator $validator) => $this->validateStatus(
                $validator,
                ['ignored'],
                'Solo se pueden restaurar publicaciones ignoradas.',
            ),
        ];
    }
}
