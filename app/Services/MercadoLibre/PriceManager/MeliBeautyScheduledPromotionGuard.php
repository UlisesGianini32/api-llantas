<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliScheduledPriceState;

class MeliBeautyScheduledPromotionGuard
{
    public function __construct(
        private readonly MeliBeautyScheduledPriceService $prices,
        private readonly MeliBeautyPromotionWindow $window,
    ) {}

    /** @param list<int> $itemIds
     * @return list<int>
     */
    public function ineligibleItemIds(MeliBeautyScheduledDiscount $promotion, array $itemIds): array
    {
        $eligible = $this->prices->eligibleItemsQuery($promotion)
            ->whereKey($itemIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_diff($itemIds, $eligible));
    }

    /** @param list<int> $itemIds */
    public function conflictingPromotion(
        MeliBeautyScheduledDiscount $promotion,
        array $itemIds,
        ?int $ignorePromotionId = null,
    ): ?MeliBeautyScheduledDiscount {
        if (! $promotion->active || $this->window->bounds($promotion) === null || $itemIds === []) {
            return null;
        }

        return MeliBeautyScheduledDiscount::query()
            ->where('active', true)
            ->when($ignorePromotionId !== null, fn ($query) => $query->whereKeyNot($ignorePromotionId))
            ->whereHas('scheduledItems', fn ($query) => $query->whereIn('price_manager_item_id', $itemIds))
            ->orderBy('id')
            ->get()
            ->first(fn (MeliBeautyScheduledDiscount $other): bool => $this->window->overlaps($promotion, $other));
    }

    public function hasPendingRemoteState(MeliBeautyScheduledDiscount $promotion): bool
    {
        return $promotion->priceStates()
            ->whereIn('status', [
                MeliScheduledPriceState::STATUS_ACTIVE,
                MeliScheduledPriceState::STATUS_RESTORE_PENDING,
            ])
            ->exists();
    }
}
