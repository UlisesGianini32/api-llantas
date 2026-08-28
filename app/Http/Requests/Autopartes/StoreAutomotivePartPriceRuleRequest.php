<?php

namespace App\Http\Requests\Autopartes;

use App\Models\AutomotivePartPriceRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomotivePartPriceRuleRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', Rule::in(AutomotivePartPriceRule::SCOPES)],
            'scope_value' => ['nullable', 'string', 'max:255', Rule::requiredIf(fn () => $this->input('scope_type') !== 'global')],
            'source_currency' => ['required', Rule::in(['USD'])], 'target_currency' => ['required', Rule::in(['MXN'])],
            'usd_mxn_rate' => ['required', 'numeric', 'gt:0'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:'.config('autopartes_media_pricing.max_markup_percent', 1000)],
            'meli_fee_percent' => ['required', 'numeric', 'min:0', 'lt:'.config('autopartes_media_pricing.max_meli_fee_percent', 95)],
            'fixed_cost_mxn' => ['required', 'numeric', 'min:0'],
            'rounding_mode' => ['required', Rule::in(AutomotivePartPriceRule::ROUNDING_MODES)],
            'rounding_increment' => ['required', 'numeric', 'gt:0'],
            'minimum_price_mxn' => ['nullable', 'numeric', 'min:0'],
            'maximum_price_mxn' => ['nullable', 'numeric', 'gt:0', 'gte:minimum_price_mxn'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:4000'], 'metadata' => ['nullable', 'array'],
        ];
    }
}
