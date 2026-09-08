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
use Throwable;

class MeliBeautyScheduledPriceService
{
    public function __construct(private readonly MeliPriceUpdateService $priceUpdates) {}

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

        $expectedPromotionalPrice = $this->calculatePromotionalPrice((float) $state->base_price, (float) $rule->discount_percentage);
        if ($this->samePrice($remotePrice, $expectedPromotionalPrice)) {
            return $this->transition('no_change', MeliScheduledPriceState::STATUS_ACTIVE, (float) $state->base_price, (float) $state->promotional_price, null);
        }

        $usesStoredBase = $this->samePrice($remotePrice, (float) $state->promotional_price);
        $basePrice = $usesStoredBase ? (float) $state->base_price : $remotePrice;
        $promotionalPrice = $this->calculatePromotionalPrice($basePrice, (float) $rule->discount_percentage);

        return $this->transition('rebase', MeliScheduledPriceState::STATUS_ACTIVE, $basePrice, $promotionalPrice, $promotionalPrice);
    }

    /** @return array{processed: int, apply: int, restore: int, rebase: int, no_change: int, success: int, blocked: int, failed: int, errors: list<array<string, mixed>>, details: list<array<string, mixed>>} */
    public function processRule(MeliBeautyScheduledDiscount $rule, ?string $meliItemId = null, bool $dryRun = false): array
    {
        $summary = ['processed' => 0, 'apply' => 0, 'restore' => 0, 'rebase' => 0, 'no_change' => 0, 'success' => 0, 'blocked' => 0, 'failed' => 0, 'errors' => [], 'details' => []];
        $items = $this->eligibleItemsQuery($rule)
            ->with('scheduledPriceState')
            ->orderBy('id')
            ->get();

        if ($meliItemId !== null) {
            $items = $items->where('meli_item_id', $meliItemId)->values();
        }

        $stateItems = MeliScheduledPriceState::query()
            ->where('meli_beauty_scheduled_discount_id', $rule->id)
            ->whereIn('status', [MeliScheduledPriceState::STATUS_ACTIVE, MeliScheduledPriceState::STATUS_RESTORE_PENDING])
            ->with('item.scheduledPriceState')
            ->get()
            ->pluck('item')
            ->filter();
        if ($meliItemId !== null) {
            $stateItems = $stateItems->where('meli_item_id', $meliItemId)->values();
        }
        $items = $items->concat($stateItems)->unique('id')->values();

        foreach ($items as $item) {
            $summary['processed']++;
            try {
                $state = $item->scheduledPriceState;
                $inWindow = $this->isRuleActiveAt($rule);
                $shouldBeActive = $rule->active && $inWindow;
                if (! $shouldBeActive && ($state === null || $state->status === MeliScheduledPriceState::STATUS_RESTORED)) {
                    $summary['no_change']++;
                    $summary['details'][] = $this->detail($rule, $item, null, 'no_change', null, []);

                    continue;
                }

                $snapshot = $this->priceUpdates->scheduledPromotionSnapshot($rule->meliAccount, $item, ! $shouldBeActive);
                $basePrice = (float) $snapshot['standard_base'];
                if ($shouldBeActive && $state !== null && $state->status !== MeliScheduledPriceState::STATUS_RESTORED) {
                    $basePrice = (float) $state->base_price;
                }
                $targetPrice = $shouldBeActive
                    ? $this->calculatePromotionalPrice($basePrice, (float) $rule->discount_percentage)
                    : $basePrice;

                if ($shouldBeActive) {
                    $existingPromotionMatches = $this->priceUpdates->isConfirmedPromotion($snapshot, $basePrice, $targetPrice);
                    if ($state !== null
                        && $state->status === MeliScheduledPriceState::STATUS_ACTIVE
                        && $existingPromotionMatches) {
                        $summary['no_change']++;
                        $summary['details'][] = $this->detail($rule, $item, $snapshot, 'no_change', $targetPrice, []);
                        if (! $dryRun) {
                            $state->forceFill(['last_observed_remote_price' => $snapshot['sale_amount']])->save();
                        }

                        continue;
                    }

                    $reasons = $existingPromotionMatches
                        ? []
                        : $this->priceUpdates->promotionEligibilityReasons($snapshot, $basePrice, $targetPrice);
                    if (! $existingPromotionMatches && in_array($snapshot['promotion_status'], ['started', 'active'], true)) {
                        $reasons[] = 'price_discount_already_active';
                    }
                    $reasons = array_values(array_unique($reasons));
                    if ($reasons !== []) {
                        $summary['blocked']++;
                        $summary['details'][] = $this->detail($rule, $item, $snapshot, 'blocked', $targetPrice, $reasons);

                        continue;
                    }

                    $action = $state !== null && $state->status === MeliScheduledPriceState::STATUS_ACTIVE ? 'rebase' : 'apply';
                } else {
                    $action = 'restore';
                }

                $summary[$action]++;
                $summary['details'][] = $this->detail($rule, $item, $snapshot, $action, $targetPrice, []);
                if ($dryRun) {
                    continue;
                }

                $transition = $this->transition(
                    $action,
                    $action === 'restore' ? MeliScheduledPriceState::STATUS_RESTORE_PENDING : MeliScheduledPriceState::STATUS_ACTIVE,
                    $basePrice,
                    $shouldBeActive ? $targetPrice : null,
                    $targetPrice,
                );
                $result = $this->priceUpdates->updateScheduledPromotion(
                    $rule->meliAccount,
                    $item,
                    $rule,
                    $basePrice,
                    $targetPrice,
                    $action,
                );
                if (in_array($result['result'], ['success', 'blocked', 'failed'], true)) {
                    $summary[$result['result']]++;
                }
                if ($result['result'] === 'success') {
                    $this->persistConfirmedState($item, $rule, $transition, (float) $result['new_price']);
                }
            } catch (Throwable $exception) {
                $blocked = $exception instanceof MeliPriceUpdateException
                    && in_array($exception->errorCode(), [
                        'pricing_automation_active', 'pricing_automation_present', 'excluded_catalog_item',
                        'item_status_not_writable', 'promotional_prices_feature_disabled',
                        'promotion_base_mismatch', 'target_outside_promotion_range',
                        'price_discount_not_candidate', 'promotion_base_unavailable',
                        'promotion_range_unavailable', 'price_discount_already_active',
                        'concurrent_standard_price_change',
                    ], true);
                $summary[$blocked ? 'blocked' : 'failed']++;
                $summary['errors'][] = ['meli_item_id' => (string) $item->meli_item_id, 'message' => $exception->getMessage()];
                if (! $dryRun && ! $blocked) {
                    $state = $item->scheduledPriceState;
                    if ($state !== null) {
                        $state->forceFill([
                            'status' => $state->status === MeliScheduledPriceState::STATUS_ACTIVE
                                || (! $shouldBeActive && $state->status === MeliScheduledPriceState::STATUS_RESTORE_PENDING)
                                ? MeliScheduledPriceState::STATUS_RESTORE_PENDING
                                : MeliScheduledPriceState::STATUS_FAILED,
                            'failure_message' => $exception->getMessage(),
                        ])->save();
                    }
                }
            }
        }

        return $summary;
    }

    private function persistConfirmedState(MeliPriceManagerItem $item, MeliBeautyScheduledDiscount $rule, array $transition, float $confirmedPrice): void
    {
        $state = $item->scheduledPriceState()->firstOrNew([]);
        $state->fill([
            'price_manager_item_id' => $item->id,
            'meli_beauty_scheduled_discount_id' => $rule->id,
            'base_price' => $transition['base_price'],
            'promotional_price' => $transition['promotional_price'] ?? $state->promotional_price,
            'last_confirmed_remote_price' => $confirmedPrice,
            'last_observed_remote_price' => $confirmedPrice,
            'status' => $transition['action'] === 'restore' ? MeliScheduledPriceState::STATUS_RESTORED : MeliScheduledPriceState::STATUS_ACTIVE,
            'applied_at' => $transition['action'] === 'restore' ? $state->applied_at : ($state->applied_at ?? now()),
            'restored_at' => $transition['action'] === 'restore' ? now() : null,
            'failure_message' => null,
        ])->save();
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

    /** @param array<string, mixed>|null $snapshot
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function detail(
        MeliBeautyScheduledDiscount $rule,
        MeliPriceManagerItem $item,
        ?array $snapshot,
        string $action,
        ?float $target,
        array $reasons,
    ): array {
        return [
            'meli_item_id' => (string) $item->meli_item_id,
            'brand' => (string) ($rule->brandGroup?->name ?? 'Marca'),
            'standard_base' => $snapshot['standard_base'] ?? null,
            'promotion_original' => $snapshot['promotion_original'] ?? null,
            'configured_discount' => (float) $rule->discount_percentage,
            'desired_target' => $target,
            'allowed_min' => $snapshot['allowed_min'] ?? null,
            'allowed_max' => $snapshot['allowed_max'] ?? null,
            'suggested' => $snapshot['suggested'] ?? null,
            'strategy' => 'price_discount',
            'reason' => implode(',', $reasons),
            'reasons' => $reasons,
            // Backwards-compatible aliases for existing console consumers.
            'base' => $snapshot['standard_base'] ?? null,
            'discount' => (float) $rule->discount_percentage,
            'target' => $target,
            'action' => $action,
        ];
    }
}
