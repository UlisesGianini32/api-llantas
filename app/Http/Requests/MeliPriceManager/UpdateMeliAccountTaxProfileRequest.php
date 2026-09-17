<?php

namespace App\Http\Requests\MeliPriceManager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeliAccountTaxProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rateRequired = Rule::requiredIf(fn (): bool => $this->boolean('enabled'));

        return [
            'meli_account_id' => ['required', 'integer'],
            'enabled' => ['required', 'boolean'],
            'vat_included_rate' => [$rateRequired, 'nullable', 'numeric', 'min:0', 'max:100'],
            'vat_withholding_rate' => [$rateRequired, 'nullable', 'numeric', 'min:0', 'max:100'],
            'income_tax_withholding_rate' => [$rateRequired, 'nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vat_included_rate.required' => 'Indica el porcentaje de IVA incluido al activar la estimación fiscal.',
            'vat_withholding_rate.required' => 'Indica el porcentaje de retención de IVA al activar la estimación fiscal.',
            'income_tax_withholding_rate.required' => 'Indica el porcentaje de retención de ISR al activar la estimación fiscal.',
            '*.numeric' => 'Los porcentajes fiscales deben ser numéricos.',
            '*.min' => 'Los porcentajes fiscales no pueden ser negativos.',
            '*.max' => 'Los porcentajes fiscales no pueden ser mayores a 100.',
        ];
    }
}
