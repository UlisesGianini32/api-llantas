<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeliItemClassificationActionService
{
    public function acceptSuggestion(MeliPriceManagerItem $item, int $userId, string $source = 'manual_suggestion'): void
    {
        if ($item->classification_status !== 'suggested' || $item->suggested_brand_group_id === null) {
            throw ValidationException::withMessages(['item' => 'La publicación no tiene una sugerencia válida para aceptar.']);
        }

        $brandGroupId = (int) $item->suggested_brand_group_id;
        $this->applyDecision($item, $userId, 'accept_suggestion', [
            'brand_group_id' => $brandGroupId,
            'suggested_brand_group_id' => null,
            'classification_status' => 'categorized',
            'classification_source' => $source,
            'classification_confidence' => '1.0000',
        ], $brandGroupId);
    }

    public function assignBrand(
        MeliPriceManagerItem $item,
        MeliBrandGroup $brand,
        int $userId,
        string $source = 'manual_assignment',
    ): void {
        $this->applyDecision($item, $userId, 'assign_brand', [
            'brand_group_id' => $brand->id,
            'suggested_brand_group_id' => null,
            'classification_status' => 'categorized',
            'classification_source' => $source,
            'classification_confidence' => '1.0000',
        ], (int) $brand->id);
    }

    public function ignore(MeliPriceManagerItem $item, int $userId, string $source = 'manual_ignored'): void
    {
        $this->applyDecision($item, $userId, 'ignore', [
            'brand_group_id' => null,
            'suggested_brand_group_id' => null,
            'classification_status' => 'ignored',
            'classification_source' => $source,
            'classification_confidence' => null,
        ]);
    }

    public function restore(MeliPriceManagerItem $item, int $userId): void
    {
        $this->applyDecision($item, $userId, 'restore_pending', [
            'brand_group_id' => null,
            'suggested_brand_group_id' => null,
            'matched_brand_alias_id' => null,
            'classification_status' => 'uncategorized',
            'classification_source' => null,
            'classification_confidence' => null,
        ]);
    }

    /**
     * @param  array{alias: string, normalized_alias: string, match_type: string, priority: int, active: bool}  $data
     * @return array{alias: MeliBrandAlias, created: bool}
     */
    public function createAliasAndAssign(
        MeliPriceManagerItem $item,
        MeliBrandGroup $brand,
        array $data,
        int $userId,
    ): array {
        return DB::transaction(function () use ($item, $brand, $data, $userId): array {
            $alias = MeliBrandAlias::query()->firstOrCreate(
                [
                    'brand_group_id' => $brand->id,
                    'normalized_alias' => $data['normalized_alias'],
                ],
                [
                    'alias' => $data['alias'],
                    'match_type' => $data['match_type'],
                    'priority' => $data['priority'],
                    'active' => $data['active'],
                ],
            );

            $this->assignBrand($item, $brand, $userId);

            return ['alias' => $alias, 'created' => $alias->wasRecentlyCreated];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{brand: MeliBrandGroup, alias: ?MeliBrandAlias}
     */
    public function createBrandAndAssign(MeliPriceManagerItem $item, array $data, int $userId): array
    {
        return DB::transaction(function () use ($item, $data, $userId): array {
            $brand = MeliBrandGroup::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'active' => $data['active'],
                'sort_order' => $data['sort_order'],
            ]);

            $alias = null;
            if ($data['create_alias']) {
                $alias = $brand->aliases()->create([
                    'alias' => $data['alias'],
                    'normalized_alias' => $data['normalized_alias'],
                    'match_type' => $data['match_type'],
                    'priority' => $data['alias_priority'],
                    'active' => $data['alias_active'],
                ]);
            }

            $this->assignBrand($item, $brand, $userId);

            return ['brand' => $brand, 'alias' => $alias];
        });
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{processed: int, action: string}
     */
    public function bulk(
        int $accountId,
        array $itemIds,
        string $action,
        int $userId,
        ?MeliBrandGroup $brand = null,
    ): array {
        return DB::transaction(function () use ($accountId, $itemIds, $action, $userId, $brand): array {
            $items = MeliPriceManagerItem::query()
                ->where('meli_account_id', $accountId)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'La selección cambió o contiene publicaciones de otra cuenta.',
                ]);
            }

            foreach ($items as $item) {
                match ($action) {
                    'assign' => $this->assignBrand(
                        $item,
                        $brand ?? throw ValidationException::withMessages(['brand_group_id' => 'Selecciona una marca activa.']),
                        $userId,
                        'manual_bulk_assignment',
                    ),
                    'accept_suggestions' => $this->acceptSuggestion($item, $userId, 'manual_bulk_suggestion'),
                    'ignore' => $this->ignore($item, $userId, 'manual_bulk_ignored'),
                    'restore' => $this->restore($item, $userId),
                    default => throw ValidationException::withMessages(['action' => 'La acción masiva no es válida.']),
                };
            }

            return ['processed' => $items->count(), 'action' => $action];
        });
    }

    /** @param array<string, mixed> $changes */
    private function applyDecision(
        MeliPriceManagerItem $item,
        int $userId,
        string $action,
        array $changes,
        ?int $selectedBrandGroupId = null,
    ): void {
        $metadata = is_array($item->classification_metadata) ? $item->classification_metadata : [];
        $decisions = is_array($metadata['manual_decisions'] ?? null) ? $metadata['manual_decisions'] : [];
        $decisions[] = [
            'action' => $action,
            'user_id' => $userId,
            'decided_at' => now()->toISOString(),
            'previous_brand_group_id' => $item->brand_group_id,
            'previous_suggested_brand_group_id' => $item->suggested_brand_group_id,
            'previous_classification_status' => $item->classification_status,
            'previous_classification_source' => $item->classification_source,
            'previous_classification_confidence' => $item->classification_confidence,
            'selected_brand_group_id' => $selectedBrandGroupId,
        ];
        $metadata['manual_decisions'] = array_slice($decisions, -50);

        $item->forceFill([
            ...$changes,
            'classification_metadata' => $metadata,
        ])->save();
    }
}
