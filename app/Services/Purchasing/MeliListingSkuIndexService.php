<?php

namespace App\Services\Purchasing;

use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Maintains the normalized auxiliary SKU/variation index (`meli_listing_sku_index`) so
 * purchase order photo resolution resolves item and variation SKUs via indexed B-tree
 * lookups instead of full-table `LIKE` scans over large JSON/TEXT columns.
 */
class MeliListingSkuIndexService
{
    public const TABLE = 'meli_listing_sku_index';

    public const SOURCE_PUBLICATIONS = 'meli_publications';

    public const SOURCE_PRICE_MANAGER = 'meli_price_manager_items';

    public function isTableAvailable(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    public function isReady(): bool
    {
        if (! $this->isTableAvailable()) {
            return false;
        }

        return DB::table(self::TABLE)->limit(1)->exists();
    }

    /**
     * Synchronize index entries for a single MeliPublication row.
     */
    public function syncFromPublication(MeliPublication $publication): int
    {
        if (! $this->isTableAvailable()) {
            return 0;
        }

        $entries = $this->extractEntriesFromPublication($publication);

        return $this->replaceSourceRows(self::SOURCE_PUBLICATIONS, (int) $publication->id, $entries);
    }

    /**
     * Synchronize index entries for a single MeliPriceManagerItem row.
     */
    public function syncFromPriceManagerItem(MeliPriceManagerItem $priceItem): int
    {
        if (! $this->isTableAvailable()) {
            return 0;
        }

        $entries = $this->extractEntriesFromPriceManagerItem($priceItem);

        return $this->replaceSourceRows(self::SOURCE_PRICE_MANAGER, (int) $priceItem->id, $entries);
    }

    public function deleteForSource(string $sourceTable, int $sourceRowId): void
    {
        if (! $this->isTableAvailable()) {
            return;
        }

        DB::table(self::TABLE)
            ->where('source_table', $sourceTable)
            ->where('source_row_id', $sourceRowId)
            ->delete();
    }

    /**
     * Rebuild the full auxiliary SKU/variation index in constant-memory primary-key chunks.
     *
     * @return array{publications_scanned: int, price_manager_scanned: int, rows_indexed: int}
     */
    public function rebuildIndex(int $chunkSize = 200): array
    {
        if (! $this->isTableAvailable()) {
            return [
                'publications_scanned' => 0,
                'price_manager_scanned' => 0,
                'rows_indexed' => 0,
            ];
        }

        $chunkSize = max(25, min(1000, $chunkSize));
        $publicationsScanned = 0;
        $priceManagerScanned = 0;
        $rowsIndexed = 0;

        DB::table(self::TABLE)->delete();

        if (Schema::hasTable(self::SOURCE_PUBLICATIONS)) {
            $pubColumns = Schema::getColumnListing(self::SOURCE_PUBLICATIONS);
            $pubSelect = array_values(array_filter([
                'id',
                'meli_account_id',
                'sku',
                'mlm',
                'status',
                'last_sync_at',
                'raw',
                in_array('is_current', $pubColumns, true) ? 'is_current' : null,
            ]));

            MeliPublication::query()
                ->select($pubSelect)
                ->orderBy('id')
                ->chunkById($chunkSize, function ($publications) use (&$publicationsScanned, &$rowsIndexed): void {
                    $batch = [];
                    foreach ($publications as $publication) {
                        $publicationsScanned++;
                        foreach ($this->extractEntriesFromPublication($publication) as $entry) {
                            $batch[] = $entry;
                        }
                    }

                    if ($batch !== []) {
                        $this->insertBatch($batch);
                        $rowsIndexed += count($batch);
                    }
                });
        }

        if (Schema::hasTable(self::SOURCE_PRICE_MANAGER)) {
            MeliPriceManagerItem::query()
                ->whereNotNull('raw_item')
                ->select(['id', 'meli_account_id', 'meli_item_id', 'sku', 'status', 'raw_item', 'raw_attributes', 'last_synced_at'])
                ->orderBy('id')
                ->chunkById($chunkSize, function ($items) use (&$priceManagerScanned, &$rowsIndexed): void {
                    $batch = [];
                    foreach ($items as $priceItem) {
                        $priceManagerScanned++;
                        foreach ($this->extractEntriesFromPriceManagerItem($priceItem) as $entry) {
                            $batch[] = $entry;
                        }
                    }

                    if ($batch !== []) {
                        $this->insertBatch($batch);
                        $rowsIndexed += count($batch);
                    }
                });
        }

        return [
            'publications_scanned' => $publicationsScanned,
            'price_manager_scanned' => $priceManagerScanned,
            'rows_indexed' => $rowsIndexed,
        ];
    }

    /**
     * Best-effort hook helper called from Eloquent model events; never breaks ML syncs
     * when running under read-only DB users or before the index migration is applied.
     */
    public static function syncSafelyFromModel(MeliPublication|MeliPriceManagerItem $model): void
    {
        try {
            $service = app(self::class);
            if ($model instanceof MeliPublication) {
                $service->syncFromPublication($model);
            } else {
                $service->syncFromPriceManagerItem($model);
            }
        } catch (Throwable) {
            // Never interrupt ML synchronization on auxiliary index sync errors
        }
    }

    public static function deleteSafelyForModel(MeliPublication|MeliPriceManagerItem $model): void
    {
        try {
            $table = $model instanceof MeliPublication ? self::SOURCE_PUBLICATIONS : self::SOURCE_PRICE_MANAGER;
            app(self::class)->deleteForSource($table, (int) $model->getKey());
        } catch (Throwable) {
            // Best-effort cleanup
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractEntriesFromPublication(MeliPublication $publication): array
    {
        $mlm = strtoupper(trim((string) ($publication->mlm ?? '')));
        if ($mlm === '') {
            return [];
        }

        $now = now()->toDateTimeString();
        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $isCurrent = array_key_exists('is_current', $publication->getAttributes())
            ? (bool) $publication->is_current
            : true;
        $syncedAt = $publication->last_sync_at ? $publication->last_sync_at->toDateTimeString() : null;
        $accountId = $publication->meli_account_id ? (int) $publication->meli_account_id : null;

        $dedup = [];

        $colSku = trim((string) ($publication->sku ?? ''));
        if ($colSku !== '') {
            $this->addEntry(
                $dedup,
                self::SOURCE_PUBLICATIONS,
                (int) $publication->id,
                $accountId,
                $mlm,
                null,
                $colSku,
                $publication->status,
                $isCurrent,
                $syncedAt,
                $now,
            );
        }

        if ($item !== []) {
            $itemSku = $this->sellerSku($item);
            if ($itemSku !== '') {
                $this->addEntry(
                    $dedup,
                    self::SOURCE_PUBLICATIONS,
                    (int) $publication->id,
                    $accountId,
                    $mlm,
                    null,
                    $itemSku,
                    $publication->status,
                    $isCurrent,
                    $syncedAt,
                    $now,
                );
            }

            foreach ((array) ($item['variations'] ?? []) as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                $varSku = $this->sellerSku($variation);
                if ($varSku === '') {
                    continue;
                }
                $varId = isset($variation['id']) ? trim((string) $variation['id']) : null;
                $varId = ($varId === '') ? null : $varId;

                $this->addEntry(
                    $dedup,
                    self::SOURCE_PUBLICATIONS,
                    (int) $publication->id,
                    $accountId,
                    $mlm,
                    $varId,
                    $varSku,
                    $publication->status,
                    $isCurrent,
                    $syncedAt,
                    $now,
                );
            }
        }

        return array_values($dedup);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractEntriesFromPriceManagerItem(MeliPriceManagerItem $priceItem): array
    {
        $mlm = strtoupper(trim((string) ($priceItem->meli_item_id ?? '')));
        $rawItem = is_array($priceItem->raw_item) ? $priceItem->raw_item : [];
        if ($mlm === '' || $rawItem === [] || ! empty($rawItem['truncated'])) {
            return [];
        }

        if (empty($rawItem['attributes']) && is_array($priceItem->raw_attributes) && $priceItem->raw_attributes !== []) {
            $rawItem['attributes'] = $priceItem->raw_attributes;
        }

        $now = now()->toDateTimeString();
        $syncedAt = $priceItem->last_synced_at ? $priceItem->last_synced_at->toDateTimeString() : null;
        $accountId = $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null;

        $dedup = [];

        $itemSku = $this->sellerSku($rawItem);
        if ($itemSku !== '') {
            $this->addEntry(
                $dedup,
                self::SOURCE_PRICE_MANAGER,
                (int) $priceItem->id,
                $accountId,
                $mlm,
                null,
                $itemSku,
                $priceItem->status,
                true,
                $syncedAt,
                $now,
            );
        }

        foreach ((array) ($rawItem['variations'] ?? []) as $variation) {
            if (! is_array($variation)) {
                continue;
            }
            $varSku = $this->sellerSku($variation);
            if ($varSku === '') {
                continue;
            }
            $varId = isset($variation['id']) ? trim((string) $variation['id']) : null;
            $varId = ($varId === '') ? null : $varId;

            $this->addEntry(
                $dedup,
                self::SOURCE_PRICE_MANAGER,
                (int) $priceItem->id,
                $accountId,
                $mlm,
                $varId,
                $varSku,
                $priceItem->status,
                true,
                $syncedAt,
                $now,
            );
        }

        return array_values($dedup);
    }

    /**
     * @param  array<string, array<string, mixed>>  $dedup
     */
    private function addEntry(
        array &$dedup,
        string $sourceTable,
        int $sourceRowId,
        ?int $meliAccountId,
        string $mlm,
        ?string $variationId,
        string $sku,
        ?string $listingStatus,
        bool $isCurrent,
        ?string $syncedAt,
        string $now,
    ): void {
        $skuTrimmed = mb_substr(trim($sku), 0, 191);
        $skuNormalized = strtoupper($skuTrimmed);
        if ($skuNormalized === '') {
            return;
        }

        $variationKey = $variationId !== null ? mb_substr($variationId, 0, 64) : '';
        $key = "{$sourceTable}|{$sourceRowId}|{$variationKey}|{$skuNormalized}";

        $dedup[$key] = [
            'source_table' => $sourceTable,
            'source_row_id' => $sourceRowId,
            'meli_account_id' => $meliAccountId,
            'mlm' => mb_substr($mlm, 0, 40),
            'variation_id' => $variationId !== null ? mb_substr($variationId, 0, 64) : null,
            'variation_key' => $variationKey,
            'sku' => $skuTrimmed,
            'sku_normalized' => $skuNormalized,
            'listing_status' => $listingStatus !== null ? mb_substr(trim($listingStatus), 0, 32) : null,
            'is_current' => $isCurrent,
            'synced_at' => $syncedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function replaceSourceRows(string $sourceTable, int $sourceRowId, array $entries): int
    {
        DB::table(self::TABLE)
            ->where('source_table', $sourceTable)
            ->where('source_row_id', $sourceRowId)
            ->delete();

        if ($entries === []) {
            return 0;
        }

        $this->insertBatch($entries);

        return count($entries);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertBatch(array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table(self::TABLE)->upsert(
                $chunk,
                ['source_table', 'source_row_id', 'variation_key', 'sku_normalized'],
                ['meli_account_id', 'mlm', 'variation_id', 'sku', 'listing_status', 'is_current', 'synced_at', 'updated_at'],
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function sellerSku(array $data): string
    {
        $direct = trim((string) ($data['seller_custom_field'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        foreach (['SELLER_SKU', 'SKU'] as $attributeId) {
            foreach ((array) ($data['attributes'] ?? []) as $attribute) {
                if (! is_array($attribute) || strcasecmp((string) ($attribute['id'] ?? ''), $attributeId) !== 0) {
                    continue;
                }

                $value = $attribute['value_name']
                    ?? $attribute['value_id']
                    ?? data_get($attribute, 'values.0.name')
                    ?? data_get($attribute, 'values.0.id');
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }
}
