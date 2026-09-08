<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use App\Services\MercadoLibre\PriceManager\MeliBeautyPromotionWindow;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPromotionGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreMeliBeautyScheduledDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'starts_on' => $this->normalizeDate($this->input('starts_on')),
            'ends_on' => $this->normalizeDate($this->input('ends_on')),
            'timezone' => MeliBeautyPromotionWindow::TIMEZONE,
            'active' => $this->has('active') ? $this->boolean('active') : $this->defaultActive(),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'meli_account_id' => ['required', 'integer', 'exists:meli_accounts,id'],
            'brand_group_id' => ['required', 'integer', 'exists:meli_brand_groups,id'],
            'discount_percentage' => ['nullable', 'numeric', 'gt:0', 'lt:100', 'decimal:0,2'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'timezone' => ['required', 'in:'.MeliBeautyPromotionWindow::TIMEZONE],
            'active' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.price_manager_item_id' => ['required', 'integer', 'distinct', 'exists:meli_price_manager_items,id'],
            'items.*.discount_percentage' => ['required', 'numeric', 'gt:0', 'lt:100', 'decimal:0,2'],
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

            $promotion = new MeliBeautyScheduledDiscount($validator->validated());
            $window = app(MeliBeautyPromotionWindow::class);
            if ($window->bounds($promotion) === null) {
                $validator->errors()->add('ends_on', 'La fecha y hora finales deben ser posteriores al inicio; una ventana nocturna no puede comenzar y terminar la misma fecha.');

                return;
            }

            $itemIds = $this->itemIds();
            $guard = app(MeliBeautyScheduledPromotionGuard::class);
            if ($guard->ineligibleItemIds($promotion, $itemIds) !== []) {
                $validator->errors()->add('items', 'Una o más publicaciones no pertenecen a la cuenta/marca o no son Beauty administrables.');

                return;
            }

            if (($conflict = $guard->conflictingPromotion($promotion, $itemIds, $this->ignoredPromotionId())) !== null) {
                $validator->errors()->add('items', "Las publicaciones seleccionadas se solapan con la promoción #{$conflict->id}.");
            }
        });
    }

    /** @return list<int> */
    protected function itemIds(): array
    {
        return array_values(array_map(
            static fn (array $item): int => (int) $item['price_manager_item_id'],
            (array) $this->input('items', []),
        ));
    }

    protected function ignoredPromotionId(): ?int
    {
        return null;
    }

    protected function defaultActive(): bool
    {
        return false;
    }

    private function normalizeDate(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        foreach (['!d/m/Y', '!Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, trim($value), MeliBeautyPromotionWindow::TIMEZONE);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Throwable) {
                // The validation rule reports malformed input.
            }
        }

        return $value;
    }
}
