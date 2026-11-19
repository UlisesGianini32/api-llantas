<?php

namespace App\Services\Inventory\Igora;

use App\Models\MeliAccount;
use App\Models\MeliFullStock;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IgoraCatalogDiscoveryService
{
    private const PUBLICATION_CHUNK_SIZE = 100;

    /**
     * Descubre publicaciones relacionadas con Schwarzkopf Igora desde meli_publications.raw
     * y las enriquece con meli_price_manager_items y meli_full_stocks en modo solo lectura.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function discover(array $filters = []): Collection
    {
        if (! Schema::hasTable('meli_publications')) {
            return collect();
        }

        $accountKey = trim((string) ($filters['account_key'] ?? ''));
        $hasPriceManager = Schema::hasTable('meli_price_manager_items');
        $hasFullStock = Schema::hasTable('meli_full_stocks');
        $hasIsCurrent = Schema::hasColumn('meli_publications', 'is_current');
        $hasMeliAccountId = Schema::hasColumn('meli_publications', 'meli_account_id');
        $hasSubStatus = Schema::hasColumn('meli_publications', 'sub_status');
        $hasPermalink = Schema::hasColumn('meli_publications', 'permalink');
        $hasLastSyncAt = Schema::hasColumn('meli_publications', 'last_sync_at');
        $staleAfterHours = max(1, (int) config('meli_price_manager.stale_after_hours', 24));

        $accountsById = Schema::hasTable('meli_accounts')
            ? MeliAccount::query()->get(['id', 'user_id', 'meli_user_id', 'nickname', 'is_default'])->keyBy('id')
            : collect();
        $defaultAccountsByUser = $accountsById->groupBy('user_id')->map(
            fn (Collection $group) => $group->firstWhere('is_default', true) ?? $group->first()
        );

        // Pre-filtrar en SQL los MLMs candidatos desde Price Manager (por título o marca)
        // para no hidratar ni decodificar miles de publicaciones ajenas (llantas/autopartes/Syscom).
        $pmCandidateMlms = [];
        if ($hasPriceManager) {
            $hasNormalizedBrand = Schema::hasColumn('meli_price_manager_items', 'normalized_brand');
            $pmCandidateMlms = MeliPriceManagerItem::query()
                ->when($accountKey !== '', fn ($q) => $q->where('meli_account_id', (int) $accountKey))
                ->where(function ($q) use ($hasNormalizedBrand): void {
                    $q->where('title', 'like', '%igora%')
                        ->orWhere('title', 'like', '%schwarzkopf%')
                        ->orWhere('meli_brand', 'like', '%schwarzkopf%')
                        ->orWhere('meli_brand', 'like', '%igora%');
                    if ($hasNormalizedBrand) {
                        $q->orWhere('normalized_brand', 'like', '%schwarzkopf%')
                            ->orWhere('normalized_brand', 'like', '%igora%');
                    }
                })
                ->pluck('meli_item_id')
                ->filter()
                ->map(fn (mixed $mlm): string => strtoupper(trim((string) $mlm)))
                ->unique()
                ->values()
                ->all();
        }

        $selectColumns = ['id', 'user_id', 'mlm', 'sku', 'status', 'raw'];
        if ($hasMeliAccountId) {
            $selectColumns[] = 'meli_account_id';
        }
        if ($hasSubStatus) {
            $selectColumns[] = 'sub_status';
        }
        if ($hasPermalink) {
            $selectColumns[] = 'permalink';
        }
        if ($hasIsCurrent) {
            $selectColumns[] = 'is_current';
        }
        if ($hasLastSyncAt) {
            $selectColumns[] = 'last_sync_at';
        }

        $selectedPublications = [];

        $query = MeliPublication::query()
            ->select($selectColumns)
            ->whereNotNull('mlm')
            ->where('mlm', '!=', '')
            ->where(function ($q) use ($pmCandidateMlms): void {
                $q->where('raw', 'like', '%igora%')
                    ->orWhere('raw', 'like', '%schwarzkopf%');
                if ($pmCandidateMlms !== []) {
                    $q->orWhereIn('mlm', $pmCandidateMlms);
                }
            });

        if ($accountKey !== '' && $hasMeliAccountId) {
            $query->where('meli_account_id', (int) $accountKey);
        }

        $query->orderBy('id');

        $query->chunkById(self::PUBLICATION_CHUNK_SIZE, function (Collection $publications) use (
            &$selectedPublications,
            $accountKey,
            $hasIsCurrent,
            $accountsById,
            $defaultAccountsByUser,
        ): void {
            foreach ($publications as $publication) {
                $mlm = strtoupper(trim((string) $publication->mlm));
                if ($mlm === '') {
                    continue;
                }

                $resolvedAccount = $this->resolveAccount($publication, $accountsById, $defaultAccountsByUser);
                $resolvedAccountId = $resolvedAccount?->id
                    ? (int) $resolvedAccount->id
                    : ($publication->meli_account_id ? (int) $publication->meli_account_id : 0);

                if ($accountKey !== '' && (string) $resolvedAccountId !== $accountKey) {
                    continue;
                }

                $dedupeKey = $resolvedAccountId.'|'.$mlm;
                $currentFlag = $hasIsCurrent ? (bool) ($publication->is_current ?? false) : true;

                if (isset($selectedPublications[$dedupeKey])) {
                    $existing = $selectedPublications[$dedupeKey];
                    $existingCurrentFlag = $existing['is_current'];
                    // Conservar preferentemente is_current = true y, a igualdad, el ID más reciente.
                    if ($existingCurrentFlag && ! $currentFlag) {
                        continue;
                    }
                    if ($existingCurrentFlag === $currentFlag && (int) $existing['publication']->id >= (int) $publication->id) {
                        continue;
                    }
                }

                $selectedPublications[$dedupeKey] = [
                    'publication' => $publication,
                    'mlm' => $mlm,
                    'account' => $resolvedAccount,
                    'account_id' => $resolvedAccountId > 0 ? $resolvedAccountId : null,
                    'is_current' => $currentFlag,
                ];
            }
        }, 'id');

        if ($selectedPublications === []) {
            return collect();
        }

        $deduped = collect(array_values($selectedPublications));
        $mlms = $deduped->pluck('mlm')->unique()->values()->all();

        $priceManagerByKey = collect();
        if ($hasPriceManager) {
            $pmQuery = MeliPriceManagerItem::query()->whereIn('meli_item_id', $mlms);
            if (Schema::hasTable('meli_brand_groups')) {
                $pmQuery->with('brandGroup:id,name,slug');
            }
            $priceManagerByKey = $pmQuery->get()->keyBy(
                fn (MeliPriceManagerItem $item): string => ((int) $item->meli_account_id).'|'.strtoupper(trim((string) $item->meli_item_id))
            );
        }

        $fullStocksByKey = collect();
        if ($hasFullStock) {
            $fullStocksByKey = MeliFullStock::query()
                ->whereIn('mlm', $mlms)
                ->get()
                ->groupBy(fn (MeliFullStock $stock): string => ((int) $stock->meli_account_id).'|'.strtoupper(trim((string) $stock->mlm)));
        }

        $rows = collect();

        foreach ($deduped as $entry) {
            /** @var MeliPublication $publication */
            $publication = $entry['publication'];
            $mlm = $entry['mlm'];
            /** @var MeliAccount|null $account */
            $account = $entry['account'];
            $resolvedAccountId = $entry['account_id'];

            $pmKey = ($resolvedAccountId ?? 0).'|'.$mlm;
            /** @var MeliPriceManagerItem|null $pmItem */
            $pmItem = $priceManagerByKey->get($pmKey);
            if (! $pmItem && $resolvedAccountId === null) {
                $pmItem = $priceManagerByKey->first(
                    fn (MeliPriceManagerItem $candidate): bool => strtoupper(trim((string) $candidate->meli_item_id)) === $mlm
                );
            }

            $rawItem = MeliPublication::itemArrayFromRaw($publication->raw);
            if ($rawItem === [] && $pmItem && is_array($pmItem->raw_item)) {
                $rawItem = $pmItem->raw_item;
            }

            if (! $this->isIgoraOrSchwarzkopfPeroxideCandidate($publication, $rawItem, $pmItem)) {
                continue;
            }

            $fullRows = $fullStocksByKey->get($pmKey, collect());

            foreach ($this->expandPublication($publication, $rawItem, $pmItem, $account, $resolvedAccountId, $fullRows, $hasIsCurrent, $staleAfterHours) as $row) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, MeliAccount>  $accountsById
     * @param  Collection<int, MeliAccount>  $defaultAccountsByUser
     */
    private function resolveAccount(
        MeliPublication $publication,
        Collection $accountsById,
        Collection $defaultAccountsByUser,
    ): ?MeliAccount {
        if ($publication->meli_account_id && $accountsById->has((int) $publication->meli_account_id)) {
            return $accountsById->get((int) $publication->meli_account_id);
        }

        if ($publication->user_id && $defaultAccountsByUser->has((int) $publication->user_id)) {
            return $defaultAccountsByUser->get((int) $publication->user_id);
        }

        return null;
    }

    /**
     * Determina si una publicación pertenece al universo Schwarzkopf Igora / peróxidos asociados.
     *
     * @param  array<string, mixed>  $rawItem
     */
    private function isIgoraOrSchwarzkopfPeroxideCandidate(
        MeliPublication $publication,
        array $rawItem,
        ?MeliPriceManagerItem $pmItem,
    ): bool {
        $textParts = [
            (string) ($rawItem['title'] ?? ''),
            (string) ($pmItem?->title ?? ''),
            (string) ($pmItem?->meli_brand ?? ''),
            (string) ($pmItem?->normalized_brand ?? ''),
            (string) ($pmItem?->brandGroup?->name ?? ''),
            (string) ($pmItem?->brandGroup?->slug ?? ''),
            $this->extractAttributeValue((array) ($rawItem['attributes'] ?? []), 'BRAND'),
            $this->extractAttributeValue((array) ($rawItem['attributes'] ?? []), 'LINE'),
            $this->extractAttributeValue((array) ($rawItem['attributes'] ?? []), 'MODEL'),
            $this->extractAttributeValue((array) ($rawItem['attributes'] ?? []), 'PRODUCT_LINE'),
            $this->extractAttributeValue((array) ($pmItem?->raw_attributes ?? []), 'LINE'),
            $this->extractAttributeValue((array) ($pmItem?->raw_attributes ?? []), 'BRAND'),
        ];

        foreach ((array) ($rawItem['variations'] ?? []) as $variation) {
            if (! is_array($variation)) {
                continue;
            }
            $textParts[] = $this->formatVariationLabel($variation);
        }

        $combined = Str::ascii(mb_strtolower(implode(' ', array_filter($textParts)), 'UTF-8'));

        if ($combined === '') {
            return false;
        }

        if (preg_match('/\bigor(?:a)?\b/', $combined) === 1) {
            return true;
        }

        if (str_contains($combined, 'schwarzkopf')
            && preg_match('/\b(?:royal|vibrance|color\s*10|zero\s*amm|peroxido|oxidante|activador|revelador|developer)\b/', $combined) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Desglosa una publicación en 1 fila (simple) o N filas (una por cada variación).
     *
     * @param  array<string, mixed>  $rawItem
     * @param  Collection<int, MeliFullStock>  $fullRows
     * @return list<array<string, mixed>>
     */
    private function expandPublication(
        MeliPublication $publication,
        array $rawItem,
        ?MeliPriceManagerItem $pmItem,
        ?MeliAccount $account,
        ?int $resolvedAccountId,
        Collection $fullRows,
        bool $hasIsCurrent,
        int $staleAfterHours,
    ): array {
        $mlm = strtoupper(trim((string) $publication->mlm));
        $title = trim((string) ($rawItem['title'] ?? $pmItem?->title ?? ''));
        $status = strtolower(trim((string) ($publication->status ?: ($rawItem['status'] ?? $pmItem?->status ?? ''))));
        $subStatus = $this->normalizeStringList($publication->sub_status ?? ($rawItem['sub_status'] ?? []));
        $rawArray = is_array($publication->raw) ? $publication->raw : [];
        $hasRawSnapshot = $rawItem !== [];

        $shipping = is_array($rawItem['shipping'] ?? null) ? $rawItem['shipping'] : [];
        $logisticType = strtolower(trim((string) ($shipping['logistic_type'] ?? '')));
        $shippingTags = $this->normalizeStringList($shipping['tags'] ?? []);
        $baseIsFull = $logisticType === 'fulfillment'
            || collect($shippingTags)->contains(fn (string $tag): bool => str_contains($tag, 'fulfillment') || str_contains($tag, 'fbm'));

        $moderationSignals = $this->detectModerationSignals($status, $subStatus, $rawArray);
        $snapshotAt = $publication->last_sync_at ?? $pmItem?->last_synced_at;
        $isStaleSnapshot = $snapshotAt === null || $snapshotAt->lt(now()->subHours($staleAfterHours));

        $itemAttributes = is_array($rawItem['attributes'] ?? null) ? $rawItem['attributes'] : (array) ($pmItem?->raw_attributes ?? []);
        $description = $this->extractDescription($rawItem, $rawArray);

        $itemLevelUserProductId = $this->nullableTrim($rawItem['user_product_id'] ?? $pmItem?->user_product_id ?? null);
        $itemLevelInventoryId = $this->nullableTrim($rawItem['inventory_id'] ?? $pmItem?->inventory_id ?? null);
        $pmStock = ($pmItem && is_numeric($pmItem->available_quantity)) ? (int) $pmItem->available_quantity : null;
        $isCurrent = $hasIsCurrent ? (bool) ($publication->is_current ?? true) : true;

        $variations = collect((array) ($rawItem['variations'] ?? []))
            ->filter(fn (mixed $variation): bool => is_array($variation))
            ->values();

        if ($variations->isEmpty()) {
            $sku = $this->extractSimpleSku($publication, $rawItem, $pmItem);
            $ean = $this->extractEan($itemAttributes);
            $pubStock = is_numeric($rawItem['available_quantity'] ?? null) ? (int) $rawItem['available_quantity'] : null;
            $fullRow = $fullRows->first(fn (MeliFullStock $f): bool => blank($f->variation_id));
            $isFull = $baseIsFull || $fullRow !== null;

            return [[
                'meli_publication_id' => (int) $publication->id,
                'meli_price_manager_item_id' => $pmItem?->id ? (int) $pmItem->id : null,
                'account_id' => $resolvedAccountId,
                'account_key' => $resolvedAccountId !== null ? (string) $resolvedAccountId : null,
                'account_name' => $account?->nickname ?: ($resolvedAccountId !== null ? 'Cuenta #'.$resolvedAccountId : 'Sin cuenta'),
                'mlm' => $mlm,
                'variation_id' => null,
                'has_variations' => false,
                'variation_label' => null,
                'variation_attributes' => [],
                'user_product_id' => $itemLevelUserProductId,
                'inventory_id' => $itemLevelInventoryId,
                'catalog_listing' => (bool) ($rawItem['catalog_listing'] ?? $pmItem?->catalog_listing ?? false),
                'catalog_product_id' => $this->nullableTrim($rawItem['catalog_product_id'] ?? $pmItem?->catalog_product_id ?? null),
                'sku' => $sku,
                'ean' => $ean,
                'title' => $title !== '' ? $title : "Publicación {$mlm}",
                'description' => $description,
                'permalink' => $publication->permalink ?: ($rawItem['permalink'] ?? $pmItem?->permalink),
                'category_id' => $this->nullableTrim($rawItem['category_id'] ?? $pmItem?->category_id ?? null),
                'meli_brand' => $this->nullableTrim($pmItem?->meli_brand ?? $this->extractAttributeValue($itemAttributes, 'BRAND')),
                'meli_brand_group' => $pmItem?->brandGroup?->name,
                'item_attributes' => $itemAttributes,
                'status' => $status !== '' ? $status : 'unknown',
                'sub_status' => $subStatus,
                'is_current' => $isCurrent,
                'is_paused' => $status === 'paused',
                'is_moderated_or_denounced' => $moderationSignals !== [],
                'moderation_signals' => $moderationSignals,
                'is_full' => $isFull,
                'logistic_type' => $logisticType !== '' ? $logisticType : ($isFull ? 'fulfillment' : null),
                'full_stock_available' => $fullRow ? (int) $fullRow->full_available_quantity : null,
                'remote_publication_stock' => $pubStock,
                'remote_price_manager_stock' => $pmStock,
                'remote_price' => is_numeric($rawItem['price'] ?? null)
                    ? round((float) $rawItem['price'], 2)
                    : ($pmItem && is_numeric($pmItem->current_price) ? round((float) $pmItem->current_price, 2) : null),
                'snapshot_at' => $snapshotAt?->toIso8601String(),
                'snapshot_at_human' => $snapshotAt?->format('Y-m-d H:i'),
                'snapshot_stale' => $isStaleSnapshot,
                'has_raw_snapshot' => $hasRawSnapshot,
                'data_incomplete' => ! $hasRawSnapshot || $title === '' || $sku === '' || $pubStock === null,
            ]];
        }

        return $variations->map(function (array $variation) use (
            $publication,
            $rawItem,
            $pmItem,
            $account,
            $resolvedAccountId,
            $mlm,
            $title,
            $description,
            $status,
            $subStatus,
            $isCurrent,
            $moderationSignals,
            $baseIsFull,
            $logisticType,
            $fullRows,
            $itemAttributes,
            $itemLevelUserProductId,
            $itemLevelInventoryId,
            $pmStock,
            $snapshotAt,
            $isStaleSnapshot,
            $hasRawSnapshot,
        ): array {
            $variationId = $this->nullableTrim($variation['id'] ?? null);
            $variationSku = $this->extractVariationSku($variation);
            $variationAttrs = array_values(array_filter([
                ...(is_array($variation['attribute_combinations'] ?? null) ? $variation['attribute_combinations'] : []),
                ...(is_array($variation['attributes'] ?? null) ? $variation['attributes'] : []),
            ], 'is_array'));
            $ean = $this->extractEan($variationAttrs) ?: $this->extractEan($itemAttributes);
            $variationLabel = $this->formatVariationLabel($variation);

            $userProductId = $this->nullableTrim($variation['user_product_id'] ?? $itemLevelUserProductId);
            $inventoryId = $this->nullableTrim($variation['inventory_id'] ?? $itemLevelInventoryId);
            $pubStock = is_numeric($variation['available_quantity'] ?? null) ? (int) $variation['available_quantity'] : null;

            $fullRow = $variationId !== null
                ? $fullRows->first(fn (MeliFullStock $f): bool => (string) $f->variation_id === $variationId)
                : null;
            $isFull = $baseIsFull || $fullRow !== null;

            return [
                'meli_publication_id' => (int) $publication->id,
                'meli_price_manager_item_id' => $pmItem?->id ? (int) $pmItem->id : null,
                'account_id' => $resolvedAccountId,
                'account_key' => $resolvedAccountId !== null ? (string) $resolvedAccountId : null,
                'account_name' => $account?->nickname ?: ($resolvedAccountId !== null ? 'Cuenta #'.$resolvedAccountId : 'Sin cuenta'),
                'mlm' => $mlm,
                'variation_id' => $variationId,
                'has_variations' => true,
                'variation_label' => $variationLabel !== '' ? $variationLabel : null,
                'variation_attributes' => $variationAttrs,
                'user_product_id' => $userProductId,
                'inventory_id' => $inventoryId,
                'catalog_listing' => (bool) ($rawItem['catalog_listing'] ?? $pmItem?->catalog_listing ?? false),
                'catalog_product_id' => $this->nullableTrim($variation['catalog_product_id'] ?? $rawItem['catalog_product_id'] ?? $pmItem?->catalog_product_id ?? null),
                'sku' => $variationSku,
                'ean' => $ean,
                'title' => $title !== '' ? $title : "Publicación {$mlm}",
                'description' => $description,
                'permalink' => $publication->permalink ?: ($rawItem['permalink'] ?? $pmItem?->permalink),
                'category_id' => $this->nullableTrim($rawItem['category_id'] ?? $pmItem?->category_id ?? null),
                'meli_brand' => $this->nullableTrim($pmItem?->meli_brand ?? $this->extractAttributeValue($itemAttributes, 'BRAND')),
                'meli_brand_group' => $pmItem?->brandGroup?->name,
                'item_attributes' => $itemAttributes,
                'status' => $status !== '' ? $status : 'unknown',
                'sub_status' => $subStatus,
                'is_current' => $isCurrent,
                'is_paused' => $status === 'paused',
                'is_moderated_or_denounced' => $moderationSignals !== [],
                'moderation_signals' => $moderationSignals,
                'is_full' => $isFull,
                'logistic_type' => $logisticType !== '' ? $logisticType : ($isFull ? 'fulfillment' : null),
                'full_stock_available' => $fullRow ? (int) $fullRow->full_available_quantity : null,
                'remote_publication_stock' => $pubStock,
                'remote_price_manager_stock' => $pmStock,
                'remote_price' => is_numeric($variation['price'] ?? null)
                    ? round((float) $variation['price'], 2)
                    : (is_numeric($rawItem['price'] ?? null) ? round((float) $rawItem['price'], 2) : null),
                'snapshot_at' => $snapshotAt?->toIso8601String(),
                'snapshot_at_human' => $snapshotAt?->format('Y-m-d H:i'),
                'snapshot_stale' => $isStaleSnapshot,
                'has_raw_snapshot' => $hasRawSnapshot,
                'data_incomplete' => ! $hasRawSnapshot || $title === '' || $variationId === null || $variationSku === '' || $pubStock === null,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $rawItem
     */
    private function extractSimpleSku(MeliPublication $publication, array $rawItem, ?MeliPriceManagerItem $pmItem): string
    {
        $attributes = (array) ($rawItem['attributes'] ?? []);
        $candidates = [
            $rawItem['seller_custom_field'] ?? null,
            $this->extractAttributeValue($attributes, 'SELLER_SKU'),
            $this->extractAttributeValue($attributes, 'SKU'),
            $publication->sku,
            $pmItem?->sku,
            $this->extractAttributeValue((array) ($pmItem?->raw_attributes ?? []), 'SELLER_SKU'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * En publicaciones con variaciones nunca se hereda el SKU general de meli_publications
     * para evitar asignar el mismo SKU a distintos tonos.
     *
     * @param  array<string, mixed>  $variation
     */
    private function extractVariationSku(array $variation): string
    {
        $attributes = (array) ($variation['attributes'] ?? []);
        $candidates = [
            $variation['seller_custom_field'] ?? null,
            $this->extractAttributeValue($attributes, 'SELLER_SKU'),
            $this->extractAttributeValue($attributes, 'SKU'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $attributes
     */
    private function extractEan(array $attributes): ?string
    {
        foreach (['GTIN', 'EAN', 'UPC', 'BARCODE'] as $key) {
            $val = $this->extractAttributeValue($attributes, $key);
            if ($val !== '') {
                return $val;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $attributes
     */
    private function extractAttributeValue(array $attributes, string $attributeId): string
    {
        foreach ($attributes as $attribute) {
            if (! is_array($attribute) || strcasecmp((string) ($attribute['id'] ?? ''), $attributeId) !== 0) {
                continue;
            }

            $value = $attribute['value_name']
                ?? $attribute['value_id']
                ?? data_get($attribute, 'values.0.name')
                ?? data_get($attribute, 'values.0.id');
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $variation
     */
    private function formatVariationLabel(array $variation): string
    {
        $parts = [];
        foreach ((array) ($variation['attribute_combinations'] ?? $variation['attributes'] ?? []) as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $name = trim((string) ($attr['name'] ?? $attr['id'] ?? ''));
            $val = trim((string) ($attr['value_name'] ?? $attr['value_id'] ?? ''));
            if ($val === '') {
                continue;
            }
            $parts[] = $name !== '' ? "{$name}: {$val}" : $val;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $rawItem
     * @param  array<string, mixed>  $rawArray
     */
    private function extractDescription(array $rawItem, array $rawArray): string
    {
        $candidates = [
            data_get($rawItem, 'description.plain_text'),
            data_get($rawItem, 'description.text'),
            is_string($rawItem['description'] ?? null) ? $rawItem['description'] : null,
            data_get($rawArray, 'description.plain_text'),
            is_string($rawArray['description'] ?? null) ? $rawArray['description'] : null,
        ];

        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $subStatus
     * @param  array<string, mixed>  $rawArray
     * @return list<string>
     */
    private function detectModerationSignals(string $status, array $subStatus, array $rawArray): array
    {
        $signals = [];

        if (in_array($status, ['under_review', 'blocked', 'suspended', 'inactive', 'closed'], true)) {
            $signals[] = 'status:'.$status;
        }

        foreach ($subStatus as $sub) {
            $normalized = strtolower($sub);
            if (in_array($normalized, ['forbidden', 'warning', 'waiting_for_patch', 'held', 'suspended', 'moderation', 'denounced'], true)
                || str_contains($normalized, 'moderat')
                || str_contains($normalized, 'denounc')
                || str_contains($normalized, 'infring')) {
                $signals[] = 'sub_status:'.$normalized;
            }
        }

        if (! empty($rawArray['moderations']) && is_array($rawArray['moderations'])) {
            $signals[] = 'raw_moderations';
        }

        return array_values(array_unique($signals));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [strtolower(trim($value))];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            $value,
        ))));
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
