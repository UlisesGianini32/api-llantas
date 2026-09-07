<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliScheduledPriceState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MeliBeautyScheduledPriceService
{
    public function isRuleActiveAt(MeliBeautyScheduledDiscount $rule, ?CarbonInterface $at = null): bool
    {
        if (! $rule->active) {
            return false;
        }

        $timezone = $rule->timezone ?: (string) config('meli_price_manager.beauty.default_timezone');
        $localTime = CarbonImmutable::instance($at ?? now())->setTimezone($timezone)->format('H:i:s');
        $start = $this->timePart($rule->starts_at);
        $end = $this->timePart($rule->ends_at);

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $localTime >= $start && $localTime < $end
            : $localTime >= $start || $localTime < $end;
    }

    public function calculatePromotionalPrice(float $basePrice, float $discountPercentage): float
    {
        if (! is_finite($basePrice) || $basePrice <= 0) {
            throw new InvalidArgumentException('El precio base debe ser mayor que cero.');
        }

        if (! is_finite($discountPercentage) || $discountPercentage <= 0 || $discountPercentage >= 100) {
            throw new InvalidArgumentException('El descuento debe ser mayor que cero y menor que 100.');
        }

        return round($basePrice * (1 - ($discountPercentage / 100)), 2);
    }

    /** @return Builder<MeliPriceManagerItem> */
    public function eligibleItemsQuery(MeliBeautyScheduledDiscount $rule): Builder
    {
        $rootCategories = $this->configuredIds('allowed_root_category_ids');
        $categories = $this->configuredIds('allowed_category_ids');

        $query = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->where('meli_account_id', $rule->meli_account_id)
            ->where('brand_group_id', $rule->brand_group_id)
            ->where('classification_status', 'categorized')
            ->whereHas('brandGroup', fn (Builder $brand): Builder => $brand->where('active', true));

        if (! Schema::hasTable('meli_categories') || ($rootCategories === [] && $categories === [])) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $beauty) use ($rootCategories, $categories): void {
            if ($categories !== []) {
                $beauty->whereIn('category_id', $categories);
            }

            $beauty->when($rootCategories !== [], function (Builder $beauty) use ($rootCategories): void {
                $beauty->orWhereExists(function ($category) use ($rootCategories): void {
                    $category->selectRaw('1')
                        ->from('meli_categories as beauty_categories')
                        ->whereColumn('beauty_categories.category_id', 'meli_price_manager_items.category_id')
                        ->whereIn('beauty_categories.root_category_id', $rootCategories);
                });
            });
        });
    }

    public function ruleHasEligibleItems(MeliBeautyScheduledDiscount $rule): bool
    {
        return $this->eligibleItemsQuery($rule)->exists();
    }

    /**
     * Determines a transition without writing to the database or Mercado Libre.
     * A changed remote price is treated as the new base, never as a discount base.
     *
     * @return array{action: string, status: string, base_price: float|null, promotional_price: float|null, target_price: float|null}
     */
    public function determineTransition(
        MeliBeautyScheduledDiscount $rule,
        ?MeliScheduledPriceState $state,
        bool $ruleActive,
        float $remotePrice,
    ): array {
        if (! is_finite($remotePrice) || $remotePrice <= 0) {
            throw new InvalidArgumentException('El precio remoto debe ser mayor que cero.');
        }

        $remotePrice = round($remotePrice, 2);
        if (! $ruleActive) {
            if ($state === null || $state->status === MeliScheduledPriceState::STATUS_RESTORED) {
                return $this->transition('no_change', MeliScheduledPriceState::STATUS_RESTORED, null, null, null);
            }

            if (! $this->samePrice($remotePrice, (float) $state->promotional_price)) {
                return $this->transition('rebase', MeliScheduledPriceState::STATUS_RESTORED, $remotePrice, null, null);
            }

            return $this->transition('restore', MeliScheduledPriceState::STATUS_RESTORE_PENDING, (float) $state->base_price, null, (float) $state->base_price);
        }

        if ($state === null || in_array($state->status, [MeliScheduledPriceState::STATUS_RESTORED, MeliScheduledPriceState::STATUS_FAILED], true)) {
            $promotionalPrice = $this->calculatePromotionalPrice($remotePrice, (float) $rule->discount_percentage);

            return $this->transition('apply', MeliScheduledPriceState::STATUS_ACTIVE, $remotePrice, $promotionalPrice, $promotionalPrice);
        }

        if ($this->samePrice($remotePrice, (float) $state->promotional_price)) {
            return $this->transition('no_change', MeliScheduledPriceState::STATUS_ACTIVE, (float) $state->base_price, (float) $state->promotional_price, null);
        }

        $promotionalPrice = $this->calculatePromotionalPrice($remotePrice, (float) $rule->discount_percentage);

        return $this->transition('rebase', MeliScheduledPriceState::STATUS_ACTIVE, $remotePrice, $promotionalPrice, $promotionalPrice);
    }

    private function timePart(mixed $value): string
    {
        return substr(trim((string) $value), 0, 8);
    }

    /** @return list<string> */
    private function configuredIds(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            (array) config('meli_price_manager.beauty.'.$key, []),
        )));
    }

    /** @return array{action: string, status: string, base_price: float|null, promotional_price: float|null, target_price: float|null} */
    private function transition(string $action, string $status, ?float $basePrice, ?float $promotionalPrice, ?float $targetPrice): array
    {
        return [
            'action' => $action,
            'status' => $status,
            'base_price' => $basePrice !== null ? round($basePrice, 2) : null,
            'promotional_price' => $promotionalPrice !== null ? round($promotionalPrice, 2) : null,
            'target_price' => $targetPrice !== null ? round($targetPrice, 2) : null,
        ];
    }

    private function samePrice(float $first, float $second): bool
    {
        return (int) round($first * 100) === (int) round($second * 100);
    }
}
