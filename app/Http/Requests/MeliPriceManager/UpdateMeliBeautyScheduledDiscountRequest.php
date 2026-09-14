<?php

namespace App\Http\Requests\MeliPriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPromotionGuard;

class UpdateMeliBeautyScheduledDiscountRequest extends StoreMeliBeautyScheduledDiscountRequest
{
    public function withValidator($validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var MeliBeautyScheduledDiscount $current */
            $current = $this->route('discount');
            if (app(MeliBeautyScheduledPromotionGuard::class)->hasPendingRemoteState($current)
                && $this->changesExecutionDefinition($current)) {
                $validator->errors()->add('items', 'Restaura primero los precios activos antes de cambiar la programación o sus publicaciones.');
            }
        });
    }

    protected function ignoredPromotionId(): ?int
    {
        return (int) $this->route('discount')?->getKey();
    }

    protected function defaultActive(): bool
    {
        return (bool) $this->route('discount')?->active;
    }

    protected function defaultAllDay(): bool
    {
        return (bool) $this->route('discount')?->all_day;
    }

    private function changesExecutionDefinition(MeliBeautyScheduledDiscount $current): bool
    {
        $incomingItems = collect($this->input('items', []))
            ->mapWithKeys(static fn (array $item): array => [(int) $item['price_manager_item_id'] => number_format((float) $item['discount_percentage'], 2, '.', '')])
            ->sortKeys()
            ->all();
        $storedItems = $current->scheduledItems()
            ->pluck('discount_percentage', 'price_manager_item_id')
            ->map(static fn (mixed $value): string => number_format((float) $value, 2, '.', ''))
            ->sortKeys()
            ->all();

        if ((bool) $current->all_day !== $this->boolean('all_day')) {
            return true;
        }

        $stored = [
            'meli_account_id' => (string) $current->meli_account_id,
            'brand_group_id' => (string) $current->brand_group_id,
            'starts_on' => optional($current->starts_on)->format('Y-m-d'),
            'ends_on' => optional($current->ends_on)->format('Y-m-d'),
        ];

        if (! $current->all_day && ! $this->boolean('all_day')) {
            $stored['starts_at'] = substr((string) $current->starts_at, 0, 5);
            $stored['ends_at'] = substr((string) $current->ends_at, 0, 5);
        }

        foreach ($stored as $field => $value) {
            if ((string) $value !== (string) $this->input($field)) {
                return true;
            }
        }

        return $incomingItems !== $storedItems;
    }
}
