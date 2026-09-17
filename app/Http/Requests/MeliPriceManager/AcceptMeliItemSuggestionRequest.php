<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Validation\Validator;

class AcceptMeliItemSuggestionRequest extends MeliItemAccountRequest
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
            function (Validator $validator): void {
                $item = $this->item();
                if ($item !== null && ($item->classification_status !== 'suggested' || $item->suggested_brand_group_id === null)) {
                    $validator->errors()->add('item', 'La publicación no tiene una sugerencia válida para aceptar.');
                }
            },
        ];
    }
}
