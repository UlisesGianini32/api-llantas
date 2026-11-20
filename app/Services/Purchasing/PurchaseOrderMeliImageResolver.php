<?php

namespace App\Services\Purchasing;

use App\Models\InventoryChannelLink;
use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves purchase order product pictures from already-synced Mercado Libre data.
 *
 * Priority order:
 * 1. Explicit inventory_channel_links (respecting account, listing MLM, and variation ID).
 * 2. Synced meli_publications (exact SKU match, including multi-variation raw JSON snapshots).
 * 3. Synced meli_price_manager_items (only when exact product/variation identity is verified).
 *
 * No outbound ML API calls, no inventory mutations, and no fuzzy name-based matching.
 */
class PurchaseOrderMeliImageResolver
{
    public const SOURCE_CHANNEL_LINK_PUBLICATION = 'channel_link_publication';

    public const SOURCE_CHANNEL_LINK_PRICE_MANAGER = 'channel_link_price_manager';

    public const SOURCE_MELI_PUBLICATION_SKU = 'meli_publication_sku';

    public const SOURCE_MELI_PUBLICATION_VARIATION_SKU = 'meli_publication_variation_sku';

    public const SOURCE_MELI_PRICE_MANAGER_SKU = 'meli_price_manager_sku';

    public const SOURCE_MELI_PRICE_MANAGER_VARIATION_SKU = 'meli_price_manager_variation_sku';

    /** @return array<int, string> Purchase-order item ID => trusted picture URL */
    public function forOrder(PurchaseOrder $order): array
    {
        $urls = [];

        foreach ($this->resolveForOrder($order) as $itemId => $resolution) {
            if (is_string($resolution['url']) && $resolution['url'] !== '') {
                $urls[(int) $itemId] = $resolution['url'];
            }
        }

        return $urls;
    }

    /**
     * Resolve detailed photo metadata and diagnostics for every item in a purchase order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveForOrder(PurchaseOrder $order, bool $logDiagnostics = true): array
    {
        $order->loadMissing(['items.product:id,sku,name']);

        return $this->resolveItems($order, $order->items, $logDiagnostics);
    }

    /**
     * Resolve detailed photo metadata for a single purchase order item.
     *
     * @return array<string, mixed>
     */
    public function resolveForItem(PurchaseOrder $order, PurchaseOrderItem $item): array
    {
        $item->loadMissing(['product:id,sku,name']);
        $resolved = $this->resolveItems($order, collect([$item]), false);

        return $resolved[(int) $item->id] ?? $this->emptyResolution($item, '', 'no_listing_found');
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveItems(PurchaseOrder $order, Collection $items, bool $logDiagnostics): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $hasPublications = Schema::hasTable('meli_publications');
        $hasPriceManager = Schema::hasTable('meli_price_manager_items');
        $hasLinks = Schema::hasTable('inventory_channel_links');
        $hasAccounts = Schema::hasTable('meli_accounts');

        if (! $hasPublications && ! $hasPriceManager) {
            $results = [];
            foreach ($items as $item) {
                $sku = $this->itemSku($item);
                $results[(int) $item->id] = $this->emptyResolution(
                    $item,
                    $sku,
                    $sku === '' ? 'missing_sku' : 'no_ml_tables',
                );
            }

            return $results;
        }

        $accounts = $hasAccounts
            ? MeliAccount::query()->get(['id', 'meli_user_id', 'nickname', 'is_default'])
            : collect();

        $skus = $items
            ->map(fn (PurchaseOrderItem $item): string => $this->itemSku($item))
            ->filter()
            ->unique(fn (string $sku): string => strtoupper($sku))
            ->values()
            ->all();

        $skuQueryVariants = $this->caseVariants($skus);

        $productIds = $items
            ->pluck('inventory_product_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Priority 1 links: prefer active links first, then historical inactive links as fallback.
        $links = ($hasLinks && $productIds !== [])
            ? InventoryChannelLink::query()
                ->whereIn('inventory_product_id', $productIds)
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->get([
                    'id',
                    'inventory_product_id',
                    'account_key',
                    'external_listing_id',
                    'external_product_id',
                    'external_variant_id',
                    'is_active',
                    'remote_status',
                ])
            : collect();

        $mlms = $links
            ->map(fn (InventoryChannelLink $link): string => $this->linkMlm($link))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $mlmQueryVariants = $this->caseVariants($mlms);

        $pubHasIsCurrent = $hasPublications && Schema::hasColumn('meli_publications', 'is_current');
        $pubHasPictures = $hasPublications && Schema::hasColumn('meli_publications', 'pictures');
        $pubSelect = array_values(array_filter([
            'id',
            'meli_account_id',
            'sku',
            'mlm',
            'status',
            'last_sync_at',
            'raw',
            $pubHasIsCurrent ? 'is_current' : null,
            $pubHasPictures ? 'pictures' : null,
        ]));

        $publications = ($hasPublications && ($skuQueryVariants !== [] || $mlmQueryVariants !== []))
            ? $this->queryPublications($pubSelect, $pubHasIsCurrent, $skuQueryVariants, $mlmQueryVariants)
            : collect();

        $priceItems = ($hasPriceManager && ($skuQueryVariants !== [] || $mlmQueryVariants !== []))
            ? $this->queryPriceManagerItems($skuQueryVariants, $mlmQueryVariants)
            : collect();

        $pubByMlm = $publications->groupBy(fn (MeliPublication $pub): string => strtoupper(trim((string) $pub->mlm)));
        $pubBySku = $publications->groupBy(fn (MeliPublication $pub): string => strtoupper(trim((string) $pub->sku)));
        $priceByMlm = $priceItems->groupBy(fn (MeliPriceManagerItem $pm): string => strtoupper(trim((string) $pm->meli_item_id)));
        $priceBySku = $priceItems->groupBy(fn (MeliPriceManagerItem $pm): string => strtoupper(trim((string) $pm->sku)));
        $linksByProductId = $links->groupBy('inventory_product_id');

        $results = [];
        $unmatchedItems = [];

        foreach ($items as $item) {
            $sku = $this->itemSku($item);
            if ($sku === '') {
                $results[(int) $item->id] = $this->emptyResolution($item, '', 'missing_sku');
                continue;
            }

            $resolution = $this->resolveSingleItemFromIndexedData(
                $item,
                $sku,
                $linksByProductId->get($item->inventory_product_id, collect()),
                $accounts,
                $pubByMlm,
                $pubBySku,
                $priceByMlm,
                $priceBySku,
            );

            $results[(int) $item->id] = $resolution;
            if ($resolution['url'] === null && ! $resolution['ambiguous']) {
                $unmatchedItems[] = ['item' => $item, 'sku' => $sku];
            }
        }

        // Secondary bulk pass for unmatched SKUs: multi-variation listings store only the first
        // variation's SKU in the top-level `sku` column, while other variations' SKUs live in JSON.
        if ($unmatchedItems !== []) {
            $remainingSkus = collect($unmatchedItems)
                ->pluck('sku')
                ->unique(fn (string $sku): string => strtoupper($sku))
                ->values()
                ->all();

            $variationPubs = $hasPublications
                ? $this->queryVariationPublicationsByRaw($pubSelect, $pubHasIsCurrent, $remainingSkus, $publications->pluck('id')->all())
                : collect();

            $variationPriceItems = $hasPriceManager
                ? $this->queryVariationPriceManagerItemsByRaw($remainingSkus, $priceItems->pluck('id')->all())
                : collect();

            foreach ($unmatchedItems as $entry) {
                /** @var PurchaseOrderItem $item */
                $item = $entry['item'];
                $sku = $entry['sku'];
                $current = $results[(int) $item->id];

                $pubMatch = $this->resolveFromPublicationCandidates(
                    $item,
                    $sku,
                    $variationPubs,
                    self::SOURCE_MELI_PUBLICATION_VARIATION_SKU,
                );
                if ($pubMatch['url'] !== null) {
                    $results[(int) $item->id] = $pubMatch;
                    continue;
                }

                if ($pubMatch['ambiguous'] && ! $current['ambiguous']) {
                    $results[(int) $item->id] = $pubMatch;
                    continue;
                }

                $pmMatch = $this->resolveFromPriceManagerCandidates(
                    $item,
                    $sku,
                    $variationPriceItems,
                    self::SOURCE_MELI_PRICE_MANAGER_VARIATION_SKU,
                );
                if ($pmMatch['url'] !== null) {
                    $results[(int) $item->id] = $pmMatch;
                    continue;
                }

                if ($pmMatch['ambiguous'] && ! $results[(int) $item->id]['ambiguous']) {
                    $results[(int) $item->id] = $pmMatch;
                }
            }
        }

        if ($logDiagnostics) {
            $this->logDiagnostics($order, $results);
        }

        return $results;
    }

    /**
     * Backwards-compatible public entry point for resolving a single publication's image.
     */
    public function imageForPublication(
        MeliPublication $publication,
        string $sku,
        ?string $variationId = null,
        bool $trustExplicitLink = false,
    ): ?string {
        $detail = $this->extractImageFromPublication($publication, $sku, $variationId, $trustExplicitLink);

        return $detail['url'];
    }

    /**
     * Validate and normalize a Mercado Libre CDN image URL.
     */
    public function trustedUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || preg_match('/[\x00-\x1F\x7F\s]/', $trimmed) === 1) {
            return null;
        }

        $url = preg_replace('/^http:\/\//i', 'https://', $trimmed);
        if (! is_string($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return null;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || ($host !== 'mlstatic.com' && ! str_ends_with($host, '.mlstatic.com'))) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path === '/') {
            return null;
        }

        return $url;
    }

    /**
     * @param  Collection<int, InventoryChannelLink>  $productLinks
     * @param  Collection<int, MeliAccount>  $accounts
     * @param  Collection<string, Collection<int, MeliPublication>>  $pubByMlm
     * @param  Collection<string, Collection<int, MeliPublication>>  $pubBySku
     * @param  Collection<string, Collection<int, MeliPriceManagerItem>>  $priceByMlm
     * @param  Collection<string, Collection<int, MeliPriceManagerItem>>  $priceBySku
     * @return array<string, mixed>
     */
    private function resolveSingleItemFromIndexedData(
        PurchaseOrderItem $item,
        string $sku,
        Collection $productLinks,
        Collection $accounts,
        Collection $pubByMlm,
        Collection $pubBySku,
        Collection $priceByMlm,
        Collection $priceBySku,
    ): array {
        $skuUpper = strtoupper($sku);
        $lastFailureReason = 'no_listing_found';
        $lastContext = ['mlm' => null, 'variation_id' => null, 'meli_account_id' => null, 'listing_status' => null];
        $ambiguousFlag = false;
        $ambiguousMatches = [];

        // Priority 1: Explicit inventory_channel_links (respecting account, MLM, and variant ID).
        foreach ($productLinks as $link) {
            $mlm = $this->linkMlm($link);
            if ($mlm === '') {
                continue;
            }

            $variationId = $this->nullableTrim($link->external_variant_id);

            // 1a. Check synced meli_publications for this linked MLM.
            foreach ($pubByMlm->get($mlm, collect()) as $publication) {
                if (! $this->accountKeyMatchesAccount($link->account_key, $publication->meli_account_id, $accounts)) {
                    if ($lastFailureReason === 'no_listing_found') {
                        $lastFailureReason = 'account_mismatch';
                        $lastContext = [
                            'mlm' => $mlm,
                            'variation_id' => $variationId,
                            'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                            'listing_status' => $publication->status,
                        ];
                    }
                    continue;
                }

                $detail = $this->extractImageFromPublication($publication, $sku, $variationId, true);
                if ($detail['url'] !== null) {
                    return $this->resolvedResult(
                        $item,
                        $sku,
                        $detail['url'],
                        self::SOURCE_CHANNEL_LINK_PUBLICATION,
                        $mlm,
                        $detail['variation_id'] ?? $variationId,
                        $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                        $publication->status,
                    );
                }

                $lastFailureReason = $detail['reason'];
                $lastContext = [
                    'mlm' => $mlm,
                    'variation_id' => $variationId,
                    'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                    'listing_status' => $publication->status,
                ];
                if ($detail['ambiguous']) {
                    $ambiguousFlag = true;
                    $ambiguousMatches[] = [
                        'source' => self::SOURCE_CHANNEL_LINK_PUBLICATION,
                        'mlm' => $mlm,
                        'meli_account_id' => $publication->meli_account_id,
                        'reason' => $detail['reason'],
                    ];
                }
            }

            // 1b. Check synced meli_price_manager_items for this linked MLM.
            foreach ($priceByMlm->get($mlm, collect()) as $priceItem) {
                if (! $this->accountKeyMatchesAccount($link->account_key, $priceItem->meli_account_id, $accounts)) {
                    if ($lastFailureReason === 'no_listing_found') {
                        $lastFailureReason = 'account_mismatch';
                        $lastContext = [
                            'mlm' => $mlm,
                            'variation_id' => $variationId,
                            'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                            'listing_status' => $priceItem->status,
                        ];
                    }
                    continue;
                }

                $detail = $this->extractImageFromPriceManagerItem($priceItem, $sku, $variationId, true);
                if ($detail['url'] !== null) {
                    return $this->resolvedResult(
                        $item,
                        $sku,
                        $detail['url'],
                        self::SOURCE_CHANNEL_LINK_PRICE_MANAGER,
                        $mlm,
                        $detail['variation_id'] ?? $variationId,
                        $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                        $priceItem->status,
                    );
                }

                $lastFailureReason = $detail['reason'];
                $lastContext = [
                    'mlm' => $mlm,
                    'variation_id' => $variationId,
                    'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                    'listing_status' => $priceItem->status,
                ];
                if ($detail['ambiguous']) {
                    $ambiguousFlag = true;
                    $ambiguousMatches[] = [
                        'source' => self::SOURCE_CHANNEL_LINK_PRICE_MANAGER,
                        'mlm' => $mlm,
                        'meli_account_id' => $priceItem->meli_account_id,
                        'reason' => $detail['reason'],
                    ];
                }
            }
        }

        // Priority 2: Synced meli_publications with exact SKU match.
        $pubCandidates = $pubBySku->get($skuUpper, collect());
        if ($pubCandidates->isNotEmpty()) {
            $pubResolution = $this->resolveFromPublicationCandidates(
                $item,
                $sku,
                $pubCandidates,
                self::SOURCE_MELI_PUBLICATION_SKU,
            );
            if ($pubResolution['url'] !== null || $pubResolution['ambiguous']) {
                return $pubResolution;
            }
            if ($pubResolution['reason'] !== 'no_listing_found') {
                $lastFailureReason = $pubResolution['reason'];
                $lastContext = [
                    'mlm' => $pubResolution['mlm'],
                    'variation_id' => $pubResolution['variation_id'],
                    'meli_account_id' => $pubResolution['meli_account_id'],
                    'listing_status' => $pubResolution['listing_status'],
                ];
            }
        }

        // Priority 3: Synced meli_price_manager_items when product identity is strictly verified.
        $pmCandidates = $priceBySku->get($skuUpper, collect());
        if ($pmCandidates->isNotEmpty()) {
            $pmResolution = $this->resolveFromPriceManagerCandidates(
                $item,
                $sku,
                $pmCandidates,
                self::SOURCE_MELI_PRICE_MANAGER_SKU,
            );
            if ($pmResolution['url'] !== null || $pmResolution['ambiguous']) {
                return $pmResolution;
            }
            if ($pmResolution['reason'] !== 'no_listing_found' && $lastFailureReason === 'no_listing_found') {
                $lastFailureReason = $pmResolution['reason'];
                $lastContext = [
                    'mlm' => $pmResolution['mlm'],
                    'variation_id' => $pmResolution['variation_id'],
                    'meli_account_id' => $pmResolution['meli_account_id'],
                    'listing_status' => $pmResolution['listing_status'],
                ];
            }
        }

        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => null,
            'source' => null,
            'mlm' => $lastContext['mlm'],
            'variation_id' => $lastContext['variation_id'],
            'meli_account_id' => $lastContext['meli_account_id'],
            'listing_status' => $lastContext['listing_status'],
            'reason' => $lastFailureReason,
            'ambiguous' => $ambiguousFlag,
            'ambiguous_matches' => $ambiguousMatches,
        ];
    }

    /**
     * @param  Collection<int, MeliPublication>  $candidates
     * @return array<string, mixed>
     */
    private function resolveFromPublicationCandidates(
        PurchaseOrderItem $item,
        string $sku,
        Collection $candidates,
        string $source,
    ): array {
        $resolvedMatches = [];
        $lastFailureReason = 'no_listing_found';
        $lastContext = ['mlm' => null, 'variation_id' => null, 'meli_account_id' => null, 'listing_status' => null];
        $ambiguousMatches = [];
        $hasIntraListingAmbiguity = false;

        foreach ($candidates as $publication) {
            $detail = $this->extractImageFromPublication($publication, $sku, null, false);
            $mlm = strtoupper(trim((string) $publication->mlm)) ?: null;

            if ($detail['url'] !== null) {
                $resolvedMatches[] = [
                    'url' => $detail['url'],
                    'mlm' => $mlm,
                    'variation_id' => $detail['variation_id'],
                    'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                    'listing_status' => $publication->status,
                ];
                continue;
            }

            if ($detail['reason'] !== 'variation_not_found' && $detail['reason'] !== 'sku_mismatch') {
                $lastFailureReason = $detail['reason'];
                $lastContext = [
                    'mlm' => $mlm,
                    'variation_id' => $detail['variation_id'],
                    'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                    'listing_status' => $publication->status,
                ];
            }

            if ($detail['ambiguous']) {
                $hasIntraListingAmbiguity = true;
                $ambiguousMatches[] = [
                    'source' => $source,
                    'mlm' => $mlm,
                    'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                    'reason' => $detail['reason'],
                ];
            }
        }

        if ($resolvedMatches !== []) {
            $distinctUrls = array_values(array_unique(array_column($resolvedMatches, 'url')));
            $crossListingDiff = count($distinctUrls) > 1;

            if ($crossListingDiff || $hasIntraListingAmbiguity) {
                $conflictMatches = array_merge(
                    $ambiguousMatches,
                    array_map(fn (array $m): array => [
                        'source' => $source,
                        'mlm' => $m['mlm'],
                        'variation_id' => $m['variation_id'],
                        'meli_account_id' => $m['meli_account_id'],
                        'listing_status' => $m['listing_status'],
                        'url' => $m['url'],
                    ], $resolvedMatches),
                );

                return [
                    'item_id' => (int) $item->id,
                    'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
                    'sku' => $sku,
                    'url' => null,
                    'source' => null,
                    'mlm' => null,
                    'variation_id' => null,
                    'meli_account_id' => null,
                    'listing_status' => null,
                    'reason' => $crossListingDiff ? 'multiple_publications_conflicting_photos' : 'variation_ambiguous',
                    'ambiguous' => true,
                    'ambiguous_matches' => $conflictMatches,
                ];
            }

            $best = $resolvedMatches[0];

            return $this->resolvedResult(
                $item,
                $sku,
                $best['url'],
                $source,
                $best['mlm'],
                $best['variation_id'],
                $best['meli_account_id'],
                $best['listing_status'],
                false,
                [],
            );
        }

        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => null,
            'source' => null,
            'mlm' => $lastContext['mlm'],
            'variation_id' => $lastContext['variation_id'],
            'meli_account_id' => $lastContext['meli_account_id'],
            'listing_status' => $lastContext['listing_status'],
            'reason' => $hasIntraListingAmbiguity ? 'variation_ambiguous' : $lastFailureReason,
            'ambiguous' => $hasIntraListingAmbiguity,
            'ambiguous_matches' => $ambiguousMatches,
        ];
    }

    /**
     * @param  Collection<int, MeliPriceManagerItem>  $candidates
     * @return array<string, mixed>
     */
    private function resolveFromPriceManagerCandidates(
        PurchaseOrderItem $item,
        string $sku,
        Collection $candidates,
        string $source,
    ): array {
        $resolvedMatches = [];
        $lastFailureReason = 'no_listing_found';
        $lastContext = ['mlm' => null, 'variation_id' => null, 'meli_account_id' => null, 'listing_status' => null];
        $ambiguousMatches = [];
        $hasIntraListingAmbiguity = false;

        foreach ($candidates as $priceItem) {
            $detail = $this->extractImageFromPriceManagerItem($priceItem, $sku, null, false);
            $mlm = strtoupper(trim((string) $priceItem->meli_item_id)) ?: null;

            if ($detail['url'] !== null) {
                $resolvedMatches[] = [
                    'url' => $detail['url'],
                    'mlm' => $mlm,
                    'variation_id' => $detail['variation_id'],
                    'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                    'listing_status' => $priceItem->status,
                ];
                continue;
            }

            if ($detail['reason'] !== 'variation_not_found' && $detail['reason'] !== 'sku_mismatch') {
                $lastFailureReason = $detail['reason'];
                $lastContext = [
                    'mlm' => $mlm,
                    'variation_id' => $detail['variation_id'],
                    'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                    'listing_status' => $priceItem->status,
                ];
            }

            if ($detail['ambiguous']) {
                $hasIntraListingAmbiguity = true;
                $ambiguousMatches[] = [
                    'source' => $source,
                    'mlm' => $mlm,
                    'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                    'reason' => $detail['reason'],
                ];
            }
        }

        if ($resolvedMatches !== []) {
            $distinctUrls = array_values(array_unique(array_column($resolvedMatches, 'url')));
            $crossListingDiff = count($distinctUrls) > 1;

            if ($crossListingDiff || $hasIntraListingAmbiguity) {
                $conflictMatches = array_merge(
                    $ambiguousMatches,
                    array_map(fn (array $m): array => [
                        'source' => $source,
                        'mlm' => $m['mlm'],
                        'variation_id' => $m['variation_id'],
                        'meli_account_id' => $m['meli_account_id'],
                        'listing_status' => $m['listing_status'],
                        'url' => $m['url'],
                    ], $resolvedMatches),
                );

                return [
                    'item_id' => (int) $item->id,
                    'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
                    'sku' => $sku,
                    'url' => null,
                    'source' => null,
                    'mlm' => null,
                    'variation_id' => null,
                    'meli_account_id' => null,
                    'listing_status' => null,
                    'reason' => $crossListingDiff ? 'multiple_publications_conflicting_photos' : 'variation_ambiguous',
                    'ambiguous' => true,
                    'ambiguous_matches' => $conflictMatches,
                ];
            }

            $best = $resolvedMatches[0];

            return $this->resolvedResult(
                $item,
                $sku,
                $best['url'],
                $source,
                $best['mlm'],
                $best['variation_id'],
                $best['meli_account_id'],
                $best['listing_status'],
                false,
                [],
            );
        }

        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => null,
            'source' => null,
            'mlm' => $lastContext['mlm'],
            'variation_id' => $lastContext['variation_id'],
            'meli_account_id' => $lastContext['meli_account_id'],
            'listing_status' => $lastContext['listing_status'],
            'reason' => $hasIntraListingAmbiguity ? 'variation_ambiguous' : $lastFailureReason,
            'ambiguous' => $hasIntraListingAmbiguity,
            'ambiguous_matches' => $ambiguousMatches,
        ];
    }

    /**
     * @return array{url: ?string, variation_id: ?string, reason: string, ambiguous: bool}
     */
    private function extractImageFromPriceManagerItem(
        MeliPriceManagerItem $priceItem,
        string $sku,
        ?string $variationId,
        bool $trustExplicitLink,
    ): array {
        $rawItem = is_array($priceItem->raw_item) ? $priceItem->raw_item : [];
        if ($rawItem === [] || ! empty($rawItem['truncated'])) {
            return [
                'url' => null,
                'variation_id' => $variationId,
                'reason' => 'price_manager_snapshot_incomplete',
                'ambiguous' => false,
            ];
        }

        // Merge raw_attributes if missing from raw_item so SELLER_SKU verification works cleanly.
        if (empty($rawItem['attributes']) && is_array($priceItem->raw_attributes) && $priceItem->raw_attributes !== []) {
            $rawItem['attributes'] = $priceItem->raw_attributes;
        }

        if (empty($rawItem['thumbnail']) && is_string($priceItem->thumbnail) && trim($priceItem->thumbnail) !== '') {
            $rawItem['thumbnail'] = $priceItem->thumbnail;
        }

        $snapshot = new MeliPublication;
        $snapshot->mlm = $priceItem->meli_item_id;
        $snapshot->meli_account_id = $priceItem->meli_account_id;
        $snapshot->status = $priceItem->status;
        $snapshot->raw = $rawItem;
        // For price manager items without an explicit channel link, require SKU verification in the
        // raw ML payload itself rather than trusting the denormalized `sku` column alone.
        $snapshot->sku = $trustExplicitLink ? (string) ($priceItem->sku ?? '') : '';

        return $this->extractImageFromPublication($snapshot, $sku, $variationId, $trustExplicitLink);
    }

    /**
     * @return array{url: ?string, variation_id: ?string, reason: string, ambiguous: bool}
     */
    private function extractImageFromPublication(
        MeliPublication $publication,
        string $sku,
        ?string $variationId = null,
        bool $trustExplicitLink = false,
    ): array {
        $sku = trim($sku);
        if ($sku === '') {
            return ['url' => null, 'variation_id' => $variationId, 'reason' => 'missing_sku', 'ambiguous' => false];
        }

        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $modelPictures = is_array($publication->pictures ?? null) ? $publication->pictures : [];

        if ($item === [] && $modelPictures === []) {
            return ['url' => null, 'variation_id' => $variationId, 'reason' => 'empty_raw_payload', 'ambiguous' => false];
        }

        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        $pictures = is_array($item['pictures'] ?? null) && $item['pictures'] !== []
            ? $item['pictures']
            : $modelPictures;

        if ($variationId !== null) {
            foreach ($variations as $variation) {
                if (! is_array($variation) || (string) ($variation['id'] ?? '') !== $variationId) {
                    continue;
                }

                $variationSku = $this->sellerSku($variation);
                if ($variationSku !== '' && strcasecmp($variationSku, $sku) !== 0) {
                    return [
                        'url' => null,
                        'variation_id' => $variationId,
                        'reason' => 'variation_sku_mismatch',
                        'ambiguous' => false,
                    ];
                }

                $pic = $this->variationPicture($variation, $pictures);

                return [
                    'url' => $pic['url'],
                    'variation_id' => $variationId,
                    'reason' => $pic['url'] !== null ? 'resolved' : $pic['reason'],
                    'ambiguous' => false,
                ];
            }

            return [
                'url' => null,
                'variation_id' => $variationId,
                'reason' => 'variation_not_found',
                'ambiguous' => false,
            ];
        }

        if ($variations !== []) {
            // A generic listing cover can be the wrong color/model: require a single exact SKU variant.
            $matches = array_values(array_filter(
                $variations,
                fn (mixed $variation): bool => is_array($variation) && strcasecmp($this->sellerSku($variation), $sku) === 0,
            ));

            if (count($matches) === 0) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'reason' => 'variation_not_found',
                    'ambiguous' => false,
                ];
            }

            if (count($matches) > 1) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'reason' => 'variation_ambiguous',
                    'ambiguous' => true,
                ];
            }

            $matchedVariation = $matches[0];
            $matchedVariationId = isset($matchedVariation['id']) ? (string) $matchedVariation['id'] : null;
            $pic = $this->variationPicture($matchedVariation, $pictures);

            return [
                'url' => $pic['url'],
                'variation_id' => $matchedVariationId,
                'reason' => $pic['url'] !== null ? 'resolved' : $pic['reason'],
                'ambiguous' => false,
            ];
        }

        $sellerSku = $this->sellerSku($item);
        $columnSku = trim((string) ($publication->sku ?? ''));

        if ($sellerSku !== '' && strcasecmp($sellerSku, $sku) !== 0) {
            return ['url' => null, 'variation_id' => null, 'reason' => 'sku_mismatch', 'ambiguous' => false];
        }

        if ($sellerSku === '' && ! $trustExplicitLink) {
            if ($columnSku === '' || strcasecmp($columnSku, $sku) !== 0) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'reason' => 'publication_sku_unverified',
                    'ambiguous' => false,
                ];
            }
        }

        if ($sellerSku === '' && $trustExplicitLink && $columnSku !== '' && strcasecmp($columnSku, $sku) !== 0) {
            return ['url' => null, 'variation_id' => null, 'reason' => 'sku_mismatch', 'ambiguous' => false];
        }

        $pic = $this->firstValidPicture($pictures, $item['thumbnail'] ?? null);

        return [
            'url' => $pic['url'],
            'variation_id' => null,
            'reason' => $pic['url'] !== null ? 'resolved' : $pic['reason'],
            'ambiguous' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $variation
     * @param  array<int, mixed>  $pictures
     * @return array{url: ?string, reason: string}
     */
    private function variationPicture(array $variation, array $pictures): array
    {
        $sawCandidate = false;
        $ids = array_values(array_filter(array_map('strval', (array) ($variation['picture_ids'] ?? []))));

        foreach ($ids as $id) {
            foreach ($pictures as $picture) {
                if (! is_array($picture) || (string) ($picture['id'] ?? '') !== $id) {
                    continue;
                }

                $sawCandidate = true;
                $url = $this->pictureUrl($picture);
                if ($url !== null) {
                    return ['url' => $url, 'reason' => 'resolved'];
                }
            }
        }

        $inlinePictures = is_array($variation['pictures'] ?? null) ? $variation['pictures'] : [];
        foreach ($inlinePictures as $inlinePicture) {
            $sawCandidate = true;
            $url = $this->pictureUrl($inlinePicture);
            if ($url !== null) {
                return ['url' => $url, 'reason' => 'resolved'];
            }
        }

        return [
            'url' => null,
            'reason' => $sawCandidate ? 'untrusted_or_invalid_url' : 'no_valid_picture',
        ];
    }

    /**
     * @param  array<int, mixed>  $pictures
     * @return array{url: ?string, reason: string}
     */
    private function firstValidPicture(array $pictures, mixed $thumbnail): array
    {
        $sawCandidate = false;

        foreach ($pictures as $picture) {
            if ($picture === null || $picture === '' || $picture === []) {
                continue;
            }

            $sawCandidate = true;
            $url = $this->pictureUrl($picture);
            if ($url !== null) {
                return ['url' => $url, 'reason' => 'resolved'];
            }
        }

        if (is_string($thumbnail) && trim($thumbnail) !== '') {
            $sawCandidate = true;
            $url = $this->trustedUrl($thumbnail);
            if ($url !== null) {
                return ['url' => $url, 'reason' => 'resolved'];
            }
        }

        return [
            'url' => null,
            'reason' => $sawCandidate ? 'untrusted_or_invalid_url' : 'no_valid_picture',
        ];
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

    private function pictureUrl(mixed $picture): ?string
    {
        if (is_array($picture)) {
            return $this->trustedUrl($picture['secure_url'] ?? $picture['url'] ?? null);
        }

        return $this->trustedUrl($picture);
    }

    /**
     * @param  list<string>  $select
     * @param  list<string>  $skuVariants
     * @param  list<string>  $mlmVariants
     * @return Collection<int, MeliPublication>
     */
    private function queryPublications(array $select, bool $hasIsCurrent, array $skuVariants, array $mlmVariants): Collection
    {
        $query = MeliPublication::query()
            ->where(function ($nested) use ($skuVariants, $mlmVariants): void {
                if ($mlmVariants !== []) {
                    $nested->whereIn('mlm', $mlmVariants);
                }
                if ($skuVariants !== []) {
                    if ($mlmVariants !== []) {
                        $nested->orWhereIn('sku', $skuVariants);
                    } else {
                        $nested->whereIn('sku', $skuVariants);
                    }
                }
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END");

        if ($hasIsCurrent) {
            $query->orderByDesc('is_current');
        }

        return $query
            ->orderByDesc('last_sync_at')
            ->orderByDesc('id')
            ->get($select);
    }

    /**
     * @param  list<string>  $skuVariants
     * @param  list<string>  $mlmVariants
     * @return Collection<int, MeliPriceManagerItem>
     */
    private function queryPriceManagerItems(array $skuVariants, array $mlmVariants): Collection
    {
        return MeliPriceManagerItem::query()
            ->whereNotNull('raw_item')
            ->where(function ($nested) use ($skuVariants, $mlmVariants): void {
                if ($mlmVariants !== []) {
                    $nested->whereIn('meli_item_id', $mlmVariants);
                }
                if ($skuVariants !== []) {
                    if ($mlmVariants !== []) {
                        $nested->orWhereIn('sku', $skuVariants);
                    } else {
                        $nested->whereIn('sku', $skuVariants);
                    }
                }
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END")
            ->orderByDesc('last_synced_at')
            ->orderByDesc('id')
            ->get(['id', 'meli_account_id', 'meli_item_id', 'sku', 'raw_item', 'raw_attributes', 'thumbnail', 'status', 'last_synced_at']);
    }

    /**
     * Single bulk lookup for multi-variation SKUs embedded in meli_publications.raw.
     *
     * @param  list<string>  $select
     * @param  list<string>  $skus
     * @param  list<int>  $excludeIds
     * @return Collection<int, MeliPublication>
     */
    private function queryVariationPublicationsByRaw(array $select, bool $hasIsCurrent, array $skus, array $excludeIds): Collection
    {
        if ($skus === []) {
            return collect();
        }

        $query = MeliPublication::query()
            ->whereNotNull('raw')
            ->where(function ($nested) use ($skus): void {
                foreach (array_slice($skus, 0, 100) as $index => $sku) {
                    $jsonToken = json_encode($sku, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $needle = is_string($jsonToken) ? $jsonToken : '"'.$sku.'"';
                    $pattern = '%'.$this->escapeLike($needle).'%';
                    if ($index === 0) {
                        $nested->where('raw', 'like', $pattern);
                    } else {
                        $nested->orWhere('raw', 'like', $pattern);
                    }
                }
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END");

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        if ($hasIsCurrent) {
            $query->orderByDesc('is_current');
        }

        return $query
            ->orderByDesc('last_sync_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get($select);
    }

    /**
     * Single bulk lookup for multi-variation SKUs embedded in meli_price_manager_items.raw_item.
     *
     * @param  list<string>  $skus
     * @param  list<int>  $excludeIds
     * @return Collection<int, MeliPriceManagerItem>
     */
    private function queryVariationPriceManagerItemsByRaw(array $skus, array $excludeIds): Collection
    {
        if ($skus === []) {
            return collect();
        }

        $query = MeliPriceManagerItem::query()
            ->whereNotNull('raw_item')
            ->where(function ($nested) use ($skus): void {
                foreach (array_slice($skus, 0, 100) as $index => $sku) {
                    $jsonToken = json_encode($sku, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $needle = is_string($jsonToken) ? $jsonToken : '"'.$sku.'"';
                    $pattern = '%'.$this->escapeLike($needle).'%';
                    if ($index === 0) {
                        $nested->where('raw_item', 'like', $pattern);
                    } else {
                        $nested->orWhere('raw_item', 'like', $pattern);
                    }
                }
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'paused' THEN 1 ELSE 2 END");

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query
            ->orderByDesc('last_synced_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get(['id', 'meli_account_id', 'meli_item_id', 'sku', 'raw_item', 'raw_attributes', 'thumbnail', 'status', 'last_synced_at']);
    }

    /**
     * Match an InventoryChannelLink.account_key against a listing's meli_account_id.
     * Supports numeric account IDs, numeric meli_user_id values, nicknames, and 'default'.
     * Unknown, ambiguous, or mismatched account keys are strictly rejected.
     *
     * @param  Collection<int, MeliAccount>  $accounts
     */
    public function accountKeyMatchesAccount(mixed $accountKey, mixed $publicationAccountId, Collection $accounts): bool
    {
        $key = trim((string) ($accountKey ?? ''));
        if ($key === '') {
            return true;
        }

        $pubAccountId = is_numeric($publicationAccountId) ? (int) $publicationAccountId : null;
        if ($pubAccountId === null) {
            return false;
        }

        if (strtolower($key) === 'default') {
            $defaultAccounts = $accounts
                ->filter(static fn (MeliAccount $account): bool => (bool) $account->is_default)
                ->values();

            return $defaultAccounts->count() === 1
                && (int) $defaultAccounts->first()->id === $pubAccountId;
        }

        if ($accounts->isEmpty()) {
            return ctype_digit($key) && (int) $key === $pubAccountId;
        }

        $matchedAccountIds = [];

        foreach ($accounts as $account) {
            if (ctype_digit($key) && (int) $key === (int) $account->id) {
                $matchedAccountIds[] = (int) $account->id;
            }
            if (trim((string) ($account->meli_user_id ?? '')) !== '' && (string) $account->meli_user_id === $key) {
                $matchedAccountIds[] = (int) $account->id;
            }
            if (trim((string) ($account->nickname ?? '')) !== '' && strcasecmp((string) $account->nickname, $key) === 0) {
                $matchedAccountIds[] = (int) $account->id;
            }
        }

        $uniqueMatchedIds = array_values(array_unique($matchedAccountIds));
        if (count($uniqueMatchedIds) !== 1) {
            return false;
        }

        return $pubAccountId === $uniqueMatchedIds[0];
    }

    private function itemSku(PurchaseOrderItem $item): string
    {
        return trim((string) ($item->product?->sku ?: $item->sku));
    }

    private function linkMlm(InventoryChannelLink $link): string
    {
        $listingId = strtoupper(trim((string) ($link->external_listing_id ?? '')));
        if ($listingId !== '') {
            return $listingId;
        }

        $productId = strtoupper(trim((string) ($link->external_product_id ?? '')));

        return str_starts_with($productId, 'MLM') ? $productId : '';
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function caseVariants(array $values): array
    {
        $variants = [];
        foreach ($values as $value) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $variants[$trimmed] = $trimmed;
            $upper = strtoupper($trimmed);
            $variants[$upper] = $upper;
            $lower = strtolower($trimmed);
            $variants[$lower] = $lower;
        }

        return array_values($variants);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @return array<string, mixed> */
    private function emptyResolution(PurchaseOrderItem $item, string $sku, string $reason): array
    {
        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => null,
            'source' => null,
            'mlm' => null,
            'variation_id' => null,
            'meli_account_id' => null,
            'listing_status' => null,
            'reason' => $reason,
            'ambiguous' => false,
            'ambiguous_matches' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ambiguousMatches
     * @return array<string, mixed>
     */
    private function resolvedResult(
        PurchaseOrderItem $item,
        string $sku,
        string $url,
        string $source,
        ?string $mlm,
        ?string $variationId,
        ?int $meliAccountId,
        ?string $listingStatus,
        bool $ambiguous = false,
        array $ambiguousMatches = [],
    ): array {
        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => $url,
            'source' => $source,
            'mlm' => $mlm,
            'variation_id' => $variationId,
            'meli_account_id' => $meliAccountId,
            'listing_status' => $listingStatus,
            'reason' => 'resolved',
            'ambiguous' => $ambiguous,
            'ambiguous_matches' => $ambiguousMatches,
        ];
    }

    /** @param array<int, array<string, mixed>> $results */
    private function logDiagnostics(PurchaseOrder $order, array $results): void
    {
        $missing = [];
        $ambiguous = [];

        foreach ($results as $entry) {
            if ($entry['url'] === null) {
                $missing[] = [
                    'item_id' => $entry['item_id'],
                    'sku' => $entry['sku'],
                    'mlm' => $entry['mlm'],
                    'variation_id' => $entry['variation_id'],
                    'reason' => $entry['reason'],
                ];
            }
            if ($entry['ambiguous']) {
                $ambiguous[] = [
                    'item_id' => $entry['item_id'],
                    'sku' => $entry['sku'],
                    'reason' => $entry['reason'],
                    'candidates' => count($entry['ambiguous_matches']),
                ];
            }
        }

        if ($missing === [] && $ambiguous === []) {
            return;
        }

        Log::info('[Purchasing][MeliPhotos] Resolution diagnostics', [
            'purchase_order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'total_items' => count($results),
            'resolved_count' => count($results) - count($missing),
            'missing_count' => count($missing),
            'ambiguous_count' => count($ambiguous),
            'missing_items' => array_slice($missing, 0, 50),
            'ambiguous_items' => array_slice($ambiguous, 0, 20),
        ]);
    }
}
