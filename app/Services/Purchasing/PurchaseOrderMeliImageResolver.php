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
 * 2. Unified SKU verification across synced meli_publications and meli_price_manager_items
 *    (including secondary variation SKUs inside raw JSON snapshots):
 *    - Multiple snapshots of the SAME listing identity (mlm + variation_id) are reconciled
 *      by currency/freshness (is_current, synced timestamp, listing status).
 *    - Multiple DIFFERENT listing identities sharing a SKU with conflicting photos (including
 *      cross-table conflicts between meli_publications and meli_price_manager_items, or a simple
 *      listing colliding with a secondary variation of another listing) are NEVER auto-selected:
 *      they return url=null with ambiguous=true so the UI marks them for review.
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

        $pubColumns = $hasPublications ? Schema::getColumnListing('meli_publications') : [];
        $pubHasIsCurrent = in_array('is_current', $pubColumns, true);
        $pubHasPictures = in_array('pictures', $pubColumns, true);
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
        $skuFallbackItems = [];
        $linkDiagnosticsByItemId = [];

        // Pass 1: Priority 1 — Explicit inventory_channel_links.
        foreach ($items as $item) {
            $sku = $this->itemSku($item);
            if ($sku === '') {
                $results[(int) $item->id] = $this->emptyResolution($item, '', 'missing_sku');
                continue;
            }

            $linkAttempt = $this->resolveFromExplicitLinks(
                $item,
                $sku,
                $linksByProductId->get($item->inventory_product_id, collect()),
                $accounts,
                $pubByMlm,
                $priceByMlm,
            );

            if ($linkAttempt['resolved'] !== null) {
                $results[(int) $item->id] = $linkAttempt['resolved'];
                continue;
            }

            if ($linkAttempt['failure'] !== null) {
                $linkDiagnosticsByItemId[(int) $item->id] = $linkAttempt['failure'];
            }

            $skuFallbackItems[] = ['item' => $item, 'sku' => $sku];
        }

        // Pass 2: Unified SKU resolution across meli_publications and meli_price_manager_items
        // (including secondary variation SKUs stored inside raw JSON) for items without an explicit link.
        if ($skuFallbackItems !== []) {
            $fallbackSkus = collect($skuFallbackItems)
                ->pluck('sku')
                ->unique(fn (string $sku): string => strtoupper($sku))
                ->values()
                ->all();

            $variationPubs = $hasPublications
                ? $this->queryVariationPublicationsByRaw($pubSelect, $pubHasIsCurrent, $fallbackSkus, $publications->pluck('id')->all())
                : collect();

            $variationPriceItems = $hasPriceManager
                ? $this->queryVariationPriceManagerItemsByRaw($fallbackSkus, $priceItems->pluck('id')->all())
                : collect();

            foreach ($skuFallbackItems as $entry) {
                /** @var PurchaseOrderItem $item */
                $item = $entry['item'];
                $sku = $entry['sku'];
                $skuUpper = strtoupper($sku);

                $results[(int) $item->id] = $this->resolveUnifiedBySku(
                    $item,
                    $sku,
                    $pubBySku->get($skuUpper, collect()),
                    $variationPubs,
                    $priceBySku->get($skuUpper, collect()),
                    $variationPriceItems,
                    $linkDiagnosticsByItemId[(int) $item->id] ?? null,
                );
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
     * Priority 1: Resolve using explicit inventory_channel_links (account + MLM + variant ID).
     *
     * @param  Collection<int, InventoryChannelLink>  $productLinks
     * @param  Collection<int, MeliAccount>  $accounts
     * @param  Collection<string, Collection<int, MeliPublication>>  $pubByMlm
     * @param  Collection<string, Collection<int, MeliPriceManagerItem>>  $priceByMlm
     * @return array{resolved: ?array<string, mixed>, failure: ?array<string, mixed>}
     */
    private function resolveFromExplicitLinks(
        PurchaseOrderItem $item,
        string $sku,
        Collection $productLinks,
        Collection $accounts,
        Collection $pubByMlm,
        Collection $priceByMlm,
    ): array {
        $lastFailure = null;

        foreach ($productLinks as $link) {
            $mlm = $this->linkMlm($link);
            if ($mlm === '') {
                continue;
            }

            $variationId = $this->nullableTrim($link->external_variant_id);

            // 1a. Check synced meli_publications for this linked MLM.
            foreach ($pubByMlm->get($mlm, collect()) as $publication) {
                if (! $this->accountKeyMatchesAccount($link->account_key, $publication->meli_account_id, $accounts)) {
                    $lastFailure ??= [
                        'reason' => 'account_mismatch',
                        'mlm' => $mlm,
                        'variation_id' => $variationId,
                        'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                        'listing_status' => $publication->status,
                        'ambiguous' => false,
                        'ambiguous_matches' => [],
                    ];
                    continue;
                }

                $detail = $this->extractImageFromPublication($publication, $sku, $variationId, true);
                if ($detail['url'] !== null) {
                    return [
                        'resolved' => $this->resolvedResult(
                            $item,
                            $sku,
                            $detail['url'],
                            self::SOURCE_CHANNEL_LINK_PUBLICATION,
                            $mlm,
                            $detail['variation_id'] ?? $variationId,
                            $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                            $publication->status,
                        ),
                        'failure' => null,
                    ];
                }

                $lastFailure = [
                    'reason' => $detail['reason'],
                    'mlm' => $mlm,
                    'variation_id' => $variationId,
                    'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                    'listing_status' => $publication->status,
                    'ambiguous' => $detail['ambiguous'],
                    'ambiguous_matches' => $detail['ambiguous'] ? [[
                        'source' => self::SOURCE_CHANNEL_LINK_PUBLICATION,
                        'mlm' => $mlm,
                        'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                        'reason' => $detail['reason'],
                    ]] : [],
                ];
            }

            // 1b. Check synced meli_price_manager_items for this linked MLM.
            foreach ($priceByMlm->get($mlm, collect()) as $priceItem) {
                if (! $this->accountKeyMatchesAccount($link->account_key, $priceItem->meli_account_id, $accounts)) {
                    $lastFailure ??= [
                        'reason' => 'account_mismatch',
                        'mlm' => $mlm,
                        'variation_id' => $variationId,
                        'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                        'listing_status' => $priceItem->status,
                        'ambiguous' => false,
                        'ambiguous_matches' => [],
                    ];
                    continue;
                }

                $detail = $this->extractImageFromPriceManagerItem($priceItem, $sku, $variationId, true);
                if ($detail['url'] !== null) {
                    return [
                        'resolved' => $this->resolvedResult(
                            $item,
                            $sku,
                            $detail['url'],
                            self::SOURCE_CHANNEL_LINK_PRICE_MANAGER,
                            $mlm,
                            $detail['variation_id'] ?? $variationId,
                            $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                            $priceItem->status,
                        ),
                        'failure' => null,
                    ];
                }

                $lastFailure = [
                    'reason' => $detail['reason'],
                    'mlm' => $mlm,
                    'variation_id' => $variationId,
                    'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                    'listing_status' => $priceItem->status,
                    'ambiguous' => $detail['ambiguous'],
                    'ambiguous_matches' => $detail['ambiguous'] ? [[
                        'source' => self::SOURCE_CHANNEL_LINK_PRICE_MANAGER,
                        'mlm' => $mlm,
                        'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                        'reason' => $detail['reason'],
                    ]] : [],
                ];
            }
        }

        return ['resolved' => null, 'failure' => $lastFailure];
    }

    /**
     * Unified SKU resolution across meli_publications and meli_price_manager_items (including secondary
     * variations embedded in raw JSON) when no explicit inventory_channel_links match exists.
     *
     * @param  Collection<int, MeliPublication>  $directPubs
     * @param  Collection<int, MeliPublication>  $variationPubs
     * @param  Collection<int, MeliPriceManagerItem>  $directPriceItems
     * @param  Collection<int, MeliPriceManagerItem>  $variationPriceItems
     * @param  ?array<string, mixed>  $linkFailure
     * @return array<string, mixed>
     */
    private function resolveUnifiedBySku(
        PurchaseOrderItem $item,
        string $sku,
        Collection $directPubs,
        Collection $variationPubs,
        Collection $directPriceItems,
        Collection $variationPriceItems,
        ?array $linkFailure,
    ): array {
        $rawCandidates = [];
        $intraListingAmbiguities = [];
        $fallbackFailure = $linkFailure;

        $seenPubIds = [];
        foreach ($directPubs as $publication) {
            $seenPubIds[(int) $publication->id] = true;
            $this->collectPublicationCandidate(
                $publication,
                $sku,
                self::SOURCE_MELI_PUBLICATION_SKU,
                $rawCandidates,
                $intraListingAmbiguities,
                $fallbackFailure,
            );
        }

        foreach ($variationPubs as $publication) {
            if (isset($seenPubIds[(int) $publication->id])) {
                continue;
            }
            $this->collectPublicationCandidate(
                $publication,
                $sku,
                self::SOURCE_MELI_PUBLICATION_VARIATION_SKU,
                $rawCandidates,
                $intraListingAmbiguities,
                $fallbackFailure,
            );
        }

        $seenPriceIds = [];
        foreach ($directPriceItems as $priceItem) {
            $seenPriceIds[(int) $priceItem->id] = true;
            $this->collectPriceManagerCandidate(
                $priceItem,
                $sku,
                self::SOURCE_MELI_PRICE_MANAGER_SKU,
                $rawCandidates,
                $intraListingAmbiguities,
                $fallbackFailure,
            );
        }

        foreach ($variationPriceItems as $priceItem) {
            if (isset($seenPriceIds[(int) $priceItem->id])) {
                continue;
            }
            $this->collectPriceManagerCandidate(
                $priceItem,
                $sku,
                self::SOURCE_MELI_PRICE_MANAGER_VARIATION_SKU,
                $rawCandidates,
                $intraListingAmbiguities,
                $fallbackFailure,
            );
        }

        if ($intraListingAmbiguities !== []) {
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
                'reason' => 'variation_ambiguous',
                'ambiguous' => true,
                'ambiguous_matches' => $intraListingAmbiguities,
            ];
        }

        if ($rawCandidates === []) {
            return [
                'item_id' => (int) $item->id,
                'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
                'sku' => $sku,
                'url' => null,
                'source' => null,
                'mlm' => $fallbackFailure['mlm'] ?? null,
                'variation_id' => $fallbackFailure['variation_id'] ?? null,
                'meli_account_id' => $fallbackFailure['meli_account_id'] ?? null,
                'listing_status' => $fallbackFailure['listing_status'] ?? null,
                'reason' => $fallbackFailure['reason'] ?? 'no_listing_found',
                'ambiguous' => (bool) ($fallbackFailure['ambiguous'] ?? false),
                'ambiguous_matches' => $fallbackFailure['ambiguous_matches'] ?? [],
            ];
        }

        // Reconcile multiple snapshots belonging to the EXACT SAME listing identity (mlm + variation_id)
        // using currency and freshness (is_current, synced_at timestamp, listing status).
        $groupedByIdentity = [];
        foreach ($rawCandidates as $candidate) {
            $groupedByIdentity[$candidate['identity_key']][] = $candidate;
        }

        $canonicalMatches = [];
        $snapshotConflicts = [];

        foreach ($groupedByIdentity as $snapshots) {
            $reconciled = $this->reconcileSnapshotsForSameListing($snapshots);
            if ($reconciled['conflict']) {
                $snapshotConflicts = array_merge($snapshotConflicts, $reconciled['conflicts']);
                continue;
            }

            if ($reconciled['winner'] !== null) {
                $canonicalMatches[] = $reconciled['winner'];
            }
        }

        if ($snapshotConflicts !== []) {
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
                'reason' => 'conflicting_listing_snapshots',
                'ambiguous' => true,
                'ambiguous_matches' => array_map(
                    fn (array $m): array => $this->formatAmbiguousCandidate($m),
                    $snapshotConflicts,
                ),
            ];
        }

        $distinctUrls = array_values(array_unique(array_column($canonicalMatches, 'url')));
        if (count($distinctUrls) > 1) {
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
                'reason' => 'multiple_publications_conflicting_photos',
                'ambiguous' => true,
                'ambiguous_matches' => array_map(
                    fn (array $m): array => $this->formatAmbiguousCandidate($m),
                    $canonicalMatches,
                ),
            ];
        }

        usort($canonicalMatches, fn (array $a, array $b): int => $this->compareCanonicalPriority($a, $b));
        $best = $canonicalMatches[0];

        return $this->resolvedResult(
            $item,
            $sku,
            $best['url'],
            $best['source'],
            $best['mlm'],
            $best['variation_id'],
            $best['meli_account_id'],
            $best['listing_status'],
            false,
            [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rawCandidates
     * @param  list<array<string, mixed>>  $intraListingAmbiguities
     * @param  ?array<string, mixed>  $fallbackFailure
     */
    private function collectPublicationCandidate(
        MeliPublication $publication,
        string $sku,
        string $source,
        array &$rawCandidates,
        array &$intraListingAmbiguities,
        ?array &$fallbackFailure,
    ): void {
        $detail = $this->extractImageFromPublication($publication, $sku, null, false);
        $mlm = strtoupper(trim((string) $publication->mlm)) ?: null;

        if ($detail['url'] !== null) {
            $variationId = $detail['variation_id'];
            $isCurrent = array_key_exists('is_current', $publication->getAttributes())
                ? (bool) $publication->is_current
                : true;
            $syncedAtTs = $publication->last_sync_at ? $publication->last_sync_at->getTimestamp() : null;

            $rawCandidates[] = [
                'identity_key' => $this->listingIdentityKey($mlm, $variationId, 'pub#'.$publication->id),
                'url' => $detail['url'],
                'source' => $source,
                'mlm' => $mlm,
                'variation_id' => $variationId,
                'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                'listing_status' => $publication->status,
                'status_rank' => $this->statusRank($publication->status),
                'is_current' => $isCurrent,
                'synced_at_ts' => $syncedAtTs,
                'source_rank' => 0, // meli_publications preferred over price manager when tied
                'row_id' => (int) $publication->id,
            ];

            return;
        }

        if ($detail['ambiguous']) {
            $intraListingAmbiguities[] = [
                'source' => $source,
                'mlm' => $mlm,
                'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                'reason' => $detail['reason'],
            ];

            return;
        }

        if ($detail['reason'] !== 'variation_not_found' && $detail['reason'] !== 'sku_mismatch') {
            $fallbackFailure ??= [
                'reason' => $detail['reason'],
                'mlm' => $mlm,
                'variation_id' => $detail['variation_id'],
                'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                'listing_status' => $publication->status,
                'ambiguous' => false,
                'ambiguous_matches' => [],
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rawCandidates
     * @param  list<array<string, mixed>>  $intraListingAmbiguities
     * @param  ?array<string, mixed>  $fallbackFailure
     */
    private function collectPriceManagerCandidate(
        MeliPriceManagerItem $priceItem,
        string $sku,
        string $source,
        array &$rawCandidates,
        array &$intraListingAmbiguities,
        ?array &$fallbackFailure,
    ): void {
        $detail = $this->extractImageFromPriceManagerItem($priceItem, $sku, null, false);
        $mlm = strtoupper(trim((string) $priceItem->meli_item_id)) ?: null;

        if ($detail['url'] !== null) {
            $variationId = $detail['variation_id'];
            $syncedAtTs = $priceItem->last_synced_at ? $priceItem->last_synced_at->getTimestamp() : null;

            $rawCandidates[] = [
                'identity_key' => $this->listingIdentityKey($mlm, $variationId, 'pm#'.$priceItem->id),
                'url' => $detail['url'],
                'source' => $source,
                'mlm' => $mlm,
                'variation_id' => $variationId,
                'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                'listing_status' => $priceItem->status,
                'status_rank' => $this->statusRank($priceItem->status),
                'is_current' => true,
                'synced_at_ts' => $syncedAtTs,
                'source_rank' => 1,
                'row_id' => (int) $priceItem->id,
            ];

            return;
        }

        if ($detail['ambiguous']) {
            $intraListingAmbiguities[] = [
                'source' => $source,
                'mlm' => $mlm,
                'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                'reason' => $detail['reason'],
            ];

            return;
        }

        if ($detail['reason'] !== 'variation_not_found' && $detail['reason'] !== 'sku_mismatch') {
            $fallbackFailure ??= [
                'reason' => $detail['reason'],
                'mlm' => $mlm,
                'variation_id' => $detail['variation_id'],
                'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                'listing_status' => $priceItem->status,
                'ambiguous' => false,
                'ambiguous_matches' => [],
            ];
        }
    }

    /**
     * Reconcile multiple snapshots belonging to the same ML listing (mlm + variation_id)
     * by evaluating currency and freshness (is_current, synced timestamp, status) before
     * declaring a snapshot conflict.
     *
     * @param  list<array<string, mixed>>  $snapshots
     * @return array{winner: ?array<string, mixed>, conflict: bool, conflicts: list<array<string, mixed>>}
     */
    private function reconcileSnapshotsForSameListing(array $snapshots): array
    {
        if (count($snapshots) === 1) {
            return ['winner' => $snapshots[0], 'conflict' => false, 'conflicts' => []];
        }

        usort($snapshots, fn (array $a, array $b): int => $this->compareSnapshotVigencia($a, $b));

        $best = $snapshots[0];
        $tiedAtTop = array_values(array_filter(
            $snapshots,
            fn (array $candidate): bool => $this->compareSnapshotVigencia($candidate, $best) === 0,
        ));

        $topDistinctUrls = array_values(array_unique(array_column($tiedAtTop, 'url')));
        if (count($topDistinctUrls) > 1) {
            return ['winner' => null, 'conflict' => true, 'conflicts' => $tiedAtTop];
        }

        usort($tiedAtTop, fn (array $a, array $b): int => ($a['source_rank'] <=> $b['source_rank']) ?: ($b['row_id'] <=> $a['row_id']));

        return ['winner' => $tiedAtTop[0], 'conflict' => false, 'conflicts' => []];
    }

    /**
     * Compare two snapshots of the SAME listing identity by currency/freshness only.
     * Returns < 0 if $a is more current than $b, > 0 if $b is more current, 0 if tied.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareSnapshotVigencia(array $a, array $b): int
    {
        // 1. Current listing row beats non-current historical duplicate.
        $currentCmp = ((int) $b['is_current']) <=> ((int) $a['is_current']);
        if ($currentCmp !== 0) {
            return $currentCmp;
        }

        // 2. Fresher sync timestamp beats older (or undated) snapshot.
        $aTs = $a['synced_at_ts'] ?? PHP_INT_MIN;
        $bTs = $b['synced_at_ts'] ?? PHP_INT_MIN;
        if ($aTs !== $bTs) {
            return $bTs <=> $aTs;
        }

        // 3. Active beats paused, paused beats closed/historical.
        return $a['status_rank'] <=> $b['status_rank'];
    }

    /**
     * Order canonical listing matches that already agree on the same photo URL.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareCanonicalPriority(array $a, array $b): int
    {
        return ($a['status_rank'] <=> $b['status_rank'])
            ?: (((int) $b['is_current']) <=> ((int) $a['is_current']))
            ?: ($a['source_rank'] <=> $b['source_rank'])
            ?: (($b['synced_at_ts'] ?? PHP_INT_MIN) <=> ($a['synced_at_ts'] ?? PHP_INT_MIN))
            ?: ($b['row_id'] <=> $a['row_id']);
    }

    private function listingIdentityKey(?string $mlm, ?string $variationId, string $fallbackId): string
    {
        $normalizedMlm = strtoupper(trim((string) ($mlm ?? '')));
        if ($normalizedMlm === '') {
            return $fallbackId.'|'.trim((string) ($variationId ?? ''));
        }

        return $normalizedMlm.'|'.trim((string) ($variationId ?? ''));
    }

    private function statusRank(?string $status): int
    {
        return match (strtolower(trim((string) $status))) {
            'active' => 0,
            'paused' => 1,
            default => 2,
        };
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function formatAmbiguousCandidate(array $candidate): array
    {
        return [
            'source' => $candidate['source'],
            'mlm' => $candidate['mlm'],
            'variation_id' => $candidate['variation_id'],
            'meli_account_id' => $candidate['meli_account_id'],
            'listing_status' => $candidate['listing_status'],
            'url' => $candidate['url'],
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
     * Single bulk lookup for secondary variation SKUs embedded in meli_publications.raw.
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
     * Single bulk lookup for secondary variation SKUs embedded in meli_price_manager_items.raw_item.
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
