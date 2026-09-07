<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeliBeautyScheduledDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'timezone' => $this->input('timezone') ?: config('meli_price_manager.beauty.default_timezone'),
            'active' => $this->has('active') ? $this->boolean('active') : true,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meli_account_id' => ['required', 'integer', 'exists:meli_accounts,id'],
            'brand_group_id' => [
                'required', 'integer', 'exists:meli_brand_groups,id',
                Rule::unique('meli_beauty_scheduled_discounts', 'brand_group_id')
                    ->where(fn ($query) => $query->where('meli_account_id', $this->integer('meli_account_id'))),
            ],
            'discount_percentage' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'timezone' => ['required', 'timezone'],
            'active' => ['required', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->user()?->meliAccounts()->whereKey($this->integer('meli_account_id'))->exists()) {
                $validator->errors()->add('meli_account_id', 'La cuenta no pertenece al usuario autenticado.');

                return;
            }

            $rule = new MeliBeautyScheduledDiscount($this->validated());
            if (! app(MeliBeautyScheduledPriceService::class)->ruleHasEligibleItems($rule)) {
                $validator->errors()->add('brand_group_id', 'La marca no tiene publicaciones Beauty categorizadas y válidas en esta cuenta.');
            }
        });
    }
}
