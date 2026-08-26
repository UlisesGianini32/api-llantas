<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyMeliBrandReclassificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reclassify_all' => $this->has('reclassify_all') ? $this->boolean('reclassify_all') : true]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meli_account_id' => [
                'required',
                'integer',
                Rule::exists('meli_accounts', 'id')->where(fn ($query) => $query->where('user_id', $this->user()->id)),
            ],
            'reclassify_all' => ['required', 'boolean'],
            'confirm' => ['required', 'accepted'],
        ];
    }
}
