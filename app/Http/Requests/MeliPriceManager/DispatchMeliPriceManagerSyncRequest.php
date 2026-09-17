<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DispatchMeliPriceManagerSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meli_account_id' => [
                'required',
                'integer',
                Rule::exists('meli_accounts', 'id')
                    ->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
        ];
    }
}
