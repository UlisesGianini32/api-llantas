<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliScheduledPriceState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class MeliBeautyScheduledPriceService
{
    private const SELECTABLE_ITEM_STATUSES = ['active', 'paused'];

    public function __construct(
        private readonly MeliPriceUpdateService $priceUpdates,
        private readonly MeliBeautyPromotionWindow $window,
    ) {}

    public function isRuleActiveAt(MeliBeautyScheduledDiscount $rule, ?CarbonInterface $at = null): bool
    {
        return $this->window->shouldApply($rule, $at);
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

    /** @return Builder<MeliPriceManagerItem> */
    public function selectableItemsQuery(MeliBeautyScheduledDiscount $rule): Builder
    {
        return $this->eligibleItemsQuery($rule)
            ->whereIn('meli_price_manager_items.status', self::SELECTABLE_ITEM_STATUSES);
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
        $selections = $rule->scheduledItems()
            ->with('item.scheduledPriceState')
            ->orderBy('price_manager_item_id')
            ->get();
        $selectedIds = $selections->pluck('price_manager_item_id')->map(static fn ($id): int => (int) $id)->all();
        $eligibleIds = $selectedIds === []
            ? []
            : $this->eligibleItemsQuery($rule)->whereKey($selectedIds)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $conflictingItemIds = $this->runtimeConflictingItemIds($rule, $selectedIds);
        $entries = $selections->map(function ($selection) use ($eligibleIds): ?array {
            if ($selection->item === null) {
                return null;
            }

            return [
                'item' => $selection->item,
                'discount_percentage' => (float) $selection->discount_percentage,
                'selected' => true,
                'eligible' => in_array((int) $selection->item->id, $eligibleIds, true),
            ];
        })->filter()->values();

        if ($meliItemId !== null) {
            $entries = $entries->filter(fn (array $entry): bool => (string) $entry['item']->meli_item_id === $meliItemId)->values();
        }

        $stateItems = MeliScheduledPriceState::query()
            ->where('meli_beauty_scheduled_discount_id', $rule->id)
            ->whereIn('status', [MeliScheduledPriceState::STATUS_ACTIVE, MeliScheduledPriceState::STATUS_RESTORE_PENDING])
            ->with('item.scheduledPriceState')
            ->get()
            ->pluck('item')
            ->filter()
            ->reject(fn (MeliPriceManagerItem $item): bool => in_array((int) $item->id, $selectedIds, true))
            ->map(fn (MeliPriceManagerItem $item): array => [
                'item' => $item,
                'discount_percentage' => (float) $rule->discount_percentage,
                'selected' => false,
                'eligible' => false,
            ]);
        if ($meliItemId !== null) {
            $stateItems = $stateItems->filter(fn (array $entry): bool => (string) $entry['item']->meli_item_id === $meliItemId)->values();
        }
        $entries = $entries->concat($stateItems)->unique(fn (array $entry): int => (int) $entry['item']->id)->values();

        foreach ($entries as $entry) {
            /** @var MeliPriceManagerItem $item */
            $item = $entry['item'];
            $summary['processed']++;
            try {
                $state = $item->scheduledPriceState;
                $selected = (bool) $entry['selected'];
                $eligible = (bool) $entry['eligible'];
                $discountPercentage = (float) $entry['discount_percentage'];
                $shouldBeActive = $selected && $eligible && $this->window->shouldApply($rule);

                $stateBelongsToAnotherPromotion = $state !== null
                    && (int) $state->meli_beauty_scheduled_discount_id !== (int) $rule->id
                    && in_array($state->status, [MeliScheduledPriceState::STATUS_ACTIVE, MeliScheduledPriceState::STATUS_RESTORE_PENDING], true);
                if ($shouldBeActive && ($stateBelongsToAnotherPromotion || in_array((int) $item->id, $conflictingItemIds, true))) {
                    $summary['blocked']++;
                    $summary['details'][] = $this->detail($rule, $item, null, 'blocked', null, ['scheduled_promotion_conflict'], $discountPercentage);

                    continue;
                }

                if ($selected && ! $eligible && $this->window->shouldApply($rule)
                    && ($state === null || $state->status === MeliScheduledPriceState::STATUS_RESTORED)) {
                    $summary['blocked']++;
                    $summary['details'][] = $this->detail($rule, $item, null, 'blocked', null, ['item_no_longer_eligible'], $discountPercentage);

                    continue;
                }
                if (! $shouldBeActive && ($state === null || $state->status === MeliScheduledPriceState::STATUS_RESTORED)) {
                    $summary['no_change']++;
                    $summary['details'][] = $this->detail($rule, $item, null, 'no_change', null, [], $discountPercentage);

                    continue;
                }

                $snapshot = $this->priceUpdates->scheduledPromotionSnapshot($rule->meliAccount, $item, ! $shouldBeActive);
                $basePrice = (float) $snapshot['standard_base'];
                if ($shouldBeActive && $state !== null && $state->status !== MeliScheduledPriceState::STATUS_RESTORED) {
                    $basePrice = (float) $state->base_price;
                }
                $targetPrice = $shouldBeActive
                    ? $this->calculatePromotionalPrice($basePrice, $discountPercentage)
                    : $basePrice;

                if ($shouldBeActive) {
                    $existingPromotionMatches = $this->priceUpdates->isConfirmedPromotion($snapshot, $basePrice, $targetPrice);
                    if ($state !== null
                        && $state->status === MeliScheduledPriceState::STATUS_ACTIVE
                        && $existingPromotionMatches) {
                        $summary['no_change']++;
                        $summary['details'][] = $this->detail($rule, $item, $snapshot, 'no_change', $targetPrice, [], $discountPercentage);
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
                        $summary['details'][] = $this->detail($rule, $item, $snapshot, 'blocked', $targetPrice, $reasons, $discountPercentage);

                        continue;
                    }

                    $action = $state !== null && $state->status === MeliScheduledPriceState::STATUS_ACTIVE ? 'rebase' : 'apply';
                } else {
                    $action = 'restore';
                }

                $summary[$action]++;
                $summary['details'][] = $this->detail($rule, $item, $snapshot, $action, $targetPrice, [], $discountPercentage);
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
                        'scheduled_promotion_conflict', 'item_no_longer_eligible',
                        'scheduled_window_inactive',
                    ], true);
                $summary[$blocked ? 'blocked' : 'failed']++;
                $summary['errors'][] = [
                    'meli_item_id' => (string) $item->meli_item_id,
                    'message' => $exception->getMessage(),
                    'status' => $blocked ? 'blocked' : 'failed',
                ];
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
        $samePromotion = $state->exists
            && (int) $state->meli_beauty_scheduled_discount_id === (int) $rule->id;
        $state->fill([
            'price_manager_item_id' => $item->id,
            'meli_beauty_scheduled_discount_id' => $rule->id,
            'base_price' => $transition['base_price'],
            'promotional_price' => $transition['promotional_price'] ?? $state->promotional_price,
            'last_confirmed_remote_price' => $confirmedPrice,
            'last_observed_remote_price' => $confirmedPrice,
            'status' => $transition['action'] === 'restore' ? MeliScheduledPriceState::STATUS_RESTORED : MeliScheduledPriceState::STATUS_ACTIVE,
            'applied_at' => $transition['action'] === 'restore'
                ? $state->applied_at
                : ($samePromotion ? ($state->applied_at ?? now()) : now()),
            'restored_at' => $transition['action'] === 'restore' ? now() : null,
            'failure_message' => null,
        ])->save();
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
        ?float $discountPercentage = null,
    ): array {
        $discountPercentage ??= (float) $rule->discount_percentage;

        return [
            'meli_item_id' => (string) $item->meli_item_id,
            'brand' => (string) ($rule->brandGroup?->name ?? 'Marca'),
            'standard_base' => $snapshot['standard_base'] ?? null,
            'promotion_original' => $snapshot['promotion_original'] ?? null,
            'configured_discount' => $discountPercentage,
            'desired_target' => $target,
            'allowed_min' => $snapshot['allowed_min'] ?? null,
            'allowed_max' => $snapshot['allowed_max'] ?? null,
            'suggested' => $snapshot['suggested'] ?? null,
            'strategy' => 'price_discount',
            'reason' => implode(',', $reasons),
            'reasons' => $reasons,
            // Backwards-compatible aliases for existing console consumers.
            'base' => $snapshot['standard_base'] ?? null,
            'discount' => $discountPercentage,
            'target' => $target,
            'action' => $action,
        ];
    }

    /** @param list<int> $selectedIds
     * @return list<int>
     */
    private function runtimeConflictingItemIds(MeliBeautyScheduledDiscount $rule, array $selectedIds): array
    {
        if ($selectedIds === [] || ! $rule->active) {
            return [];
        }

        return MeliBeautyScheduledDiscount::query()
            ->where('active', true)
            ->whereKeyNot($rule->id)
            ->whereHas('scheduledItems', fn (Builder $query) => $query->whereIn('price_manager_item_id', $selectedIds))
            ->with(['scheduledItems' => fn ($query) => $query->whereIn('price_manager_item_id', $selectedIds)])
            ->get()
            ->filter(fn (MeliBeautyScheduledDiscount $other): bool => $this->window->overlaps($rule, $other))
            ->flatMap(fn (MeliBeautyScheduledDiscount $other) => $other->scheduledItems->pluck('price_manager_item_id'))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
