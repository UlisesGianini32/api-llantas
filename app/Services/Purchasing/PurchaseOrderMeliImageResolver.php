<?php

namespace App\Services\Purchasing;

use App\Models\InventoryChannelLink;
use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves purchase order product pictures from manual overrides, admin-approved
 * Mercado Libre listings/variations, explicit channel links, and already-synced
 * Mercado Libre data.
 *
 * Priority order:
 * 1. Manual item override (`photo_source_mode = 'manual'` uploaded file / trusted URL, or `'none'`).
 * 2. Admin-approved ML publication/variation (`photo_source_mode = 'approved_ml'`).
 * 3. Explicit inventory_channel_links (respecting account, listing MLM, and variation ID).
 * 4. Unified SKU verification across synced meli_publications and meli_price_manager_items
 *    (including secondary variation SKUs inside raw JSON snapshots):
 *    - Each bulk-fetched variation snapshot is strictly indexed by its own verified SKUs so
 *      an unrelated MLM never contaminates another item's diagnostics.
 *    - Multiple snapshots of the SAME listing identity (mlm + variation_id) are reconciled
 *      by currency/freshness (is_current, synced timestamp, listing status).
 *    - Multiple DIFFERENT listing identities sharing a SKU with conflicting photos are NEVER
 *      auto-selected: they return url=null with ambiguous=true so the UI marks them for review.
 *
 * Title-based discovery is exposed separately ONLY to propose candidates for manual selection,
 * never for automatic assignment.
 */
class PurchaseOrderMeliImageResolver
{
    public const SOURCE_MANUAL_UPLOAD = 'manual_upload';

    public const SOURCE_MANUAL_URL = 'manual_url';

    public const SOURCE_MANUAL_NONE = 'manual_none';

    public const SOURCE_APPROVED_PUBLICATION = 'approved_publication';

    public const SOURCE_APPROVED_VARIATION = 'approved_variation';

    public const SOURCE_CHANNEL_LINK_PUBLICATION = 'channel_link_publication';

    public const SOURCE_CHANNEL_LINK_PRICE_MANAGER = 'channel_link_price_manager';

    public const SOURCE_MELI_PUBLICATION_SKU = 'meli_publication_sku';

    public const SOURCE_MELI_PUBLICATION_VARIATION_SKU = 'meli_publication_variation_sku';

    public const SOURCE_MELI_PRICE_MANAGER_SKU = 'meli_price_manager_sku';

    public const SOURCE_MELI_PRICE_MANAGER_VARIATION_SKU = 'meli_price_manager_variation_sku';

    public const AUDIT_RESOLVED_SKU_LINK = 'resolved_by_sku_or_link';

    public const AUDIT_RESOLVED_VARIATION = 'resolved_by_variation';

    public const AUDIT_PENDING_MANUAL_APPROVAL = 'pending_manual_approval';

    public const AUDIT_PENDING_NO_CANDIDATE = 'pending_without_candidate';

    public const AUDIT_MANUAL_OVERRIDE = 'manual_override';

    /**
     * Operational bounds for keyset-paginated raw variation scanning (used ONLY as fallback when
     * the normalized `meli_listing_sku_index` table is not yet available or populated).
     * Each SKU batch scans primary-key pages (id > lastId) of VARIATION_SCAN_PAGE_SIZE rows,
     * streaming verified SKU matches into memory and discarding non-matching rows per page.
     * Reaching page, time, or memory ceilings sets `variation_scan_truncated = true` and emits a
     * warning so truncation is never silent.
     */
    public const VARIATION_SCAN_SKU_BATCH_SIZE = 100;

    public const VARIATION_SCAN_PAGE_SIZE = 100;

    public const VARIATION_SCAN_MAX_PAGES = 20;

    public const VARIATION_SCAN_MAX_SECONDS = 5.0;

    public const SLOW_RESOLUTION_WARN_SECONDS = 2.5;

    /** @var list<string> */
    private const TITLE_STOPWORDS = [
        'de', 'del', 'la', 'las', 'el', 'los', 'en', 'con', 'sin', 'para', 'por',
        'un', 'una', 'unos', 'unas', 'al', 'y', 'o', 'a', 'tipo', 'pza', 'pzas',
        'pieza', 'piezas', 'cm', 'mm', 'ml', 'kg', 'gr', 'oz',
    ];

    public function __construct(
        private readonly int $variationScanPageSize = self::VARIATION_SCAN_PAGE_SIZE,
        private readonly int $variationScanMaxPages = self::VARIATION_SCAN_MAX_PAGES,
        private readonly int $variationScanSkuBatchSize = self::VARIATION_SCAN_SKU_BATCH_SIZE,
        private readonly float $variationScanMaxSeconds = self::VARIATION_SCAN_MAX_SECONDS,
    ) {}

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

        $results = [];
        $remainingItems = collect();

        // Pass 0: Manual overrides (uploaded photo / manual URL) and explicit rejections ('none').
        foreach ($items as $item) {
            $manualResult = $this->resolveFromManualOverride($item);
            if ($manualResult !== null) {
                $results[(int) $item->id] = $manualResult;
                continue;
            }
            $remainingItems->push($item);
        }

        if ($remainingItems->isEmpty()) {
            if ($logDiagnostics) {
                $this->logDiagnostics($order, $results);
            }

            return $results;
        }

        $hasPublications = Schema::hasTable('meli_publications');
        $hasPriceManager = Schema::hasTable('meli_price_manager_items');
        $hasLinks = Schema::hasTable('inventory_channel_links');

        if (! $hasPublications && ! $hasPriceManager) {
            foreach ($remainingItems as $item) {
                $sku = $this->itemSku($item);
                $approvedMlm = $this->normalizedMlm($item->getAttribute('approved_mlm'));
                $approvedUrl = $this->trustedUrl($item->getAttribute('approved_photo_url'));
                if ($item->getAttribute('photo_source_mode') === PurchaseOrderItem::PHOTO_MODE_APPROVED_ML && $approvedUrl !== null) {
                    $approvedVarId = $this->nullableTrim($item->getAttribute('approved_variation_id'));
                    $results[(int) $item->id] = $this->resolvedResult(
                        $item,
                        $sku,
                        $approvedUrl,
                        $approvedVarId !== null ? self::SOURCE_APPROVED_VARIATION : self::SOURCE_APPROVED_PUBLICATION,
                        $approvedMlm,
                        $approvedVarId,
                        is_numeric($item->getAttribute('approved_meli_account_id')) ? (int) $item->getAttribute('approved_meli_account_id') : null,
                        null,
                        false,
                        [],
                        null,
                        null,
                        'resolved_approved_snapshot',
                    );
                    continue;
                }

                $results[(int) $item->id] = $this->emptyResolution(
                    $item,
                    $sku,
                    $sku === '' ? 'missing_sku' : 'no_ml_tables',
                );
            }

            if ($logDiagnostics) {
                $this->logDiagnostics($order, $results);
            }

            return $results;
        }

        $skus = $remainingItems
            ->map(fn (PurchaseOrderItem $item): string => $this->itemSku($item))
            ->filter()
            ->unique(fn (string $sku): string => strtoupper($sku))
            ->values()
            ->all();

        $skuQueryVariants = $this->caseVariants($skus);

        $productIds = $remainingItems
            ->pluck('inventory_product_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Explicit channel links: prefer active links first, then historical inactive links as fallback.
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

        $accounts = ($links->isNotEmpty() && Schema::hasTable('meli_accounts'))
            ? MeliAccount::query()->get(['id', 'meli_user_id', 'nickname', 'is_default'])
            : collect();

        $approvedMlms = $remainingItems
            ->filter(fn (PurchaseOrderItem $item): bool => $item->getAttribute('photo_source_mode') === PurchaseOrderItem::PHOTO_MODE_APPROVED_ML)
            ->map(fn (PurchaseOrderItem $item): ?string => $this->normalizedMlm($item->getAttribute('approved_mlm')))
            ->filter()
            ->values()
            ->all();

        $mlms = collect($links)
            ->map(fn (InventoryChannelLink $link): string => $this->linkMlm($link))
            ->merge($approvedMlms)
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
            in_array('permalink', $pubColumns, true) ? 'permalink' : null,
            $pubHasIsCurrent ? 'is_current' : null,
            $pubHasPictures ? 'pictures' : null,
        ]));

        $priceSelect = $hasPriceManager ? $this->priceManagerSelectColumns() : [];

        $publications = ($hasPublications && ($skuQueryVariants !== [] || $mlmQueryVariants !== []))
            ? $this->queryPublications($pubSelect, $pubHasIsCurrent, $skuQueryVariants, $mlmQueryVariants)
            : collect();

        $priceItems = ($hasPriceManager && ($skuQueryVariants !== [] || $mlmQueryVariants !== []))
            ? $this->queryPriceManagerItems($skuQueryVariants, $mlmQueryVariants, $priceSelect)
            : collect();

        $pubByMlm = $publications->groupBy(fn (MeliPublication $pub): string => strtoupper(trim((string) $pub->mlm)));
        $pubBySku = $publications->groupBy(fn (MeliPublication $pub): string => strtoupper(trim((string) $pub->sku)));
        $priceByMlm = $priceItems->groupBy(fn (MeliPriceManagerItem $pm): string => strtoupper(trim((string) $pm->meli_item_id)));
        $priceBySku = $priceItems->groupBy(fn (MeliPriceManagerItem $pm): string => strtoupper(trim((string) $pm->sku)));
        $linksByProductId = $links->groupBy('inventory_product_id');

        $skuFallbackItems = [];
        $linkDiagnosticsByItemId = [];

        // Pass 1: Admin-approved ML selections and explicit inventory_channel_links.
        foreach ($remainingItems as $item) {
            $sku = $this->itemSku($item);
            $rejectedMlms = $this->normalizedRejectedMlms($item);

            if ($item->getAttribute('photo_source_mode') === PurchaseOrderItem::PHOTO_MODE_APPROVED_ML) {
                $approvedResult = $this->resolveFromApprovedSelection($item, $sku, $pubByMlm, $priceByMlm);
                if ($approvedResult !== null) {
                    $results[(int) $item->id] = $approvedResult;
                    continue;
                }
            }

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
                $rejectedMlms,
            );

            if ($linkAttempt['resolved'] !== null) {
                $results[(int) $item->id] = $linkAttempt['resolved'];
                continue;
            }

            if ($linkAttempt['failure'] !== null) {
                $linkDiagnosticsByItemId[(int) $item->id] = $linkAttempt['failure'];
            }

            $skuFallbackItems[] = [
                'item' => $item,
                'sku' => $sku,
                'rejected_mlms' => $rejectedMlms,
            ];
        }

        // Pass 2: Unified SKU resolution across meli_publications and meli_price_manager_items
        // (including secondary variation SKUs stored inside raw JSON) for items without an explicit match.
        // Priority: normalized B-tree index `meli_listing_sku_index` (zero raw LIKE scans).
        // Fallback: resource-guarded keyset-paginated scan (page, time, and memory bounds).
        if ($skuFallbackItems !== []) {
            $pass2StartedAt = microtime(true);
            $fallbackSkus = collect($skuFallbackItems)
                ->pluck('sku')
                ->unique(fn (string $sku): string => strtoupper($sku))
                ->values()
                ->all();

            $fallbackSkusUpper = array_values(array_map(static fn (string $s): string => strtoupper($s), $fallbackSkus));
            $fallbackSkuSet = array_fill_keys($fallbackSkusUpper, true);

            $variationPubsBySku = $this->indexPublicationsByVerifiedSku($publications, $fallbackSkuSet);
            $variationPriceBySku = $this->indexPriceManagerItemsByVerifiedSku($priceItems, $fallbackSkuSet);

            $variationIndexUsed = false;
            $variationIndexGeneration = null;
            $scanTruncated = false;
            $scanDiagnostics = null;
            $scannedRowsTotal = 0;

            $activeVariationGeneration = $this->resolveActiveVariationSkuIndexGeneration();

            if ($activeVariationGeneration !== null) {
                $variationIndexUsed = true;
                $variationIndexGeneration = $activeVariationGeneration;
                $indexedLookup = $this->lookupVariationRowsFromSkuIndex(
                    $activeVariationGeneration,
                    $fallbackSkusUpper,
                    $fallbackSkuSet,
                    $hasPublications,
                    $pubSelect,
                    $publications->pluck('id')->all(),
                    $hasPriceManager,
                    $priceSelect,
                    $priceItems->pluck('id')->all(),
                );
                $variationPubsBySku = $this->mergeIndexedCollections($variationPubsBySku, $indexedLookup['pub_by_sku']);
                $variationPriceBySku = $this->mergeIndexedCollections($variationPriceBySku, $indexedLookup['price_by_sku']);
            } else {
                $deadline = $this->variationScanMaxSeconds > 0
                    ? microtime(true) + $this->variationScanMaxSeconds
                    : microtime(true) - 1.0;

                $pubScan = $hasPublications
                    ? $this->scanVariationPublicationsByRaw($pubSelect, $fallbackSkus, $publications->pluck('id')->all(), $fallbackSkuSet, $deadline)
                    : ['by_sku' => [], 'truncated' => false, 'truncation_reason' => null, 'scanned_rows' => 0, 'pages_scanned' => 0, 'max_rows' => 0];

                $priceScan = $hasPriceManager
                    ? $this->scanVariationPriceManagerItemsByRaw($fallbackSkus, $priceItems->pluck('id')->all(), $fallbackSkuSet, $priceSelect, $deadline)
                    : ['by_sku' => [], 'truncated' => false, 'truncation_reason' => null, 'scanned_rows' => 0, 'pages_scanned' => 0, 'max_rows' => 0];

                $variationPubsBySku = $this->mergeIndexedCollections($variationPubsBySku, $pubScan['by_sku']);
                $variationPriceBySku = $this->mergeIndexedCollections($variationPriceBySku, $priceScan['by_sku']);

                $scannedRowsTotal = $pubScan['scanned_rows'] + $priceScan['scanned_rows'];
                $scanTruncated = $pubScan['truncated'] || $priceScan['truncated'];
                $scanDiagnostics = $scanTruncated ? [
                    'meli_publications_truncated' => $pubScan['truncated'],
                    'meli_publications_reason' => $pubScan['truncation_reason'],
                    'meli_publications_scanned' => $pubScan['scanned_rows'],
                    'meli_price_manager_truncated' => $priceScan['truncated'],
                    'meli_price_manager_reason' => $priceScan['truncation_reason'],
                    'meli_price_manager_scanned' => $priceScan['scanned_rows'],
                    'page_size' => max(1, $this->variationScanPageSize),
                    'max_pages_per_batch' => max(1, $this->variationScanMaxPages),
                    'max_seconds' => $this->variationScanMaxSeconds,
                ] : null;

                if ($scanTruncated) {
                    Log::warning('[Purchasing][MeliPhotos] Variation raw scan reached operational ceiling', [
                        'purchase_order_id' => (int) $order->id,
                        'order_number' => (string) $order->order_number,
                        'fallback_skus_count' => count($fallbackSkus),
                        'scan_diagnostics' => $scanDiagnostics,
                    ]);
                }
            }

            $pass2Elapsed = microtime(true) - $pass2StartedAt;
            if ($pass2Elapsed >= self::SLOW_RESOLUTION_WARN_SECONDS) {
                Log::warning('[Purchasing][MeliPhotos] Slow photo resolution detected', [
                    'purchase_order_id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'duration_ms' => round($pass2Elapsed * 1000, 1),
                    'fallback_skus_count' => count($fallbackSkus),
                    'variation_index_used' => $variationIndexUsed,
                    'scanned_rows' => $scannedRowsTotal,
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                ]);
            }

            foreach ($skuFallbackItems as $entry) {
                /** @var PurchaseOrderItem $item */
                $item = $entry['item'];
                $sku = $entry['sku'];
                $skuUpper = strtoupper($sku);

                $resolvedEntry = $this->resolveUnifiedBySku(
                    $item,
                    $sku,
                    $pubBySku->get($skuUpper, collect()),
                    $variationPubsBySku[$skuUpper] ?? collect(),
                    $priceBySku->get($skuUpper, collect()),
                    $variationPriceBySku[$skuUpper] ?? collect(),
                    $linkDiagnosticsByItemId[(int) $item->id] ?? null,
                    $entry['rejected_mlms'],
                );

                $resolvedEntry['variation_index_used'] = $variationIndexUsed;
                $resolvedEntry['variation_index_generation'] = $variationIndexGeneration;
                $resolvedEntry['variation_scan_truncated'] = $scanTruncated;
                if ($scanDiagnostics !== null) {
                    $resolvedEntry['scan_diagnostics'] = $scanDiagnostics;
                }

                $results[(int) $item->id] = $resolvedEntry;
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
     * Pass 0: Resolve manual uploaded photo / manual URL override or explicit 'none' rejection.
     *
     * @return ?array<string, mixed>
     */
    private function resolveFromManualOverride(PurchaseOrderItem $item): ?array
    {
        $mode = $item->getAttribute('photo_source_mode');
        if ($mode === null || $mode === '') {
            return null;
        }

        $sku = $this->itemSku($item);
        $notes = $this->nullableTrim($item->getAttribute('photo_notes'));

        if ($mode === PurchaseOrderItem::PHOTO_MODE_NONE) {
            return $this->emptyResolution(
                $item,
                $sku,
                'manual_rejected_no_photo',
                self::AUDIT_PENDING_NO_CANDIDATE,
                self::SOURCE_MANUAL_NONE,
                PurchaseOrderItem::PHOTO_MODE_NONE,
                $notes,
            );
        }

        if ($mode !== PurchaseOrderItem::PHOTO_MODE_MANUAL) {
            return null;
        }

        $manualPath = $this->nullableTrim($item->getAttribute('manual_photo_path'));
        if ($manualPath !== null) {
            if (! $this->isValidStoredManualPhoto($manualPath)) {
                return $this->emptyResolution(
                    $item,
                    $sku,
                    'manual_photo_missing_file',
                    self::AUDIT_PENDING_NO_CANDIDATE,
                    self::SOURCE_MANUAL_UPLOAD,
                    PurchaseOrderItem::PHOTO_MODE_MANUAL,
                    $notes,
                );
            }

            $res = $this->resolvedResult(
                $item,
                $sku,
                'local://'.$manualPath,
                self::SOURCE_MANUAL_UPLOAD,
                null,
                null,
                null,
                null,
                false,
                [],
                null,
                null,
                'manual_override',
                self::AUDIT_MANUAL_OVERRIDE,
            );
            $res['manual_path'] = $manualPath;
            $res['photo_source_mode'] = PurchaseOrderItem::PHOTO_MODE_MANUAL;
            $res['photo_notes'] = $notes;

            return $res;
        }

        $manualUrl = $this->trustedUrl($item->getAttribute('approved_photo_url'));
        if ($manualUrl !== null) {
            $res = $this->resolvedResult(
                $item,
                $sku,
                $manualUrl,
                self::SOURCE_MANUAL_URL,
                $this->normalizedMlm($item->getAttribute('approved_mlm')),
                $this->nullableTrim($item->getAttribute('approved_variation_id')),
                is_numeric($item->getAttribute('approved_meli_account_id')) ? (int) $item->getAttribute('approved_meli_account_id') : null,
                null,
                false,
                [],
                null,
                null,
                'manual_override',
                self::AUDIT_MANUAL_OVERRIDE,
            );
            $res['photo_source_mode'] = PurchaseOrderItem::PHOTO_MODE_MANUAL;
            $res['photo_notes'] = $notes;

            return $res;
        }

        return $this->emptyResolution(
            $item,
            $sku,
            'manual_photo_missing_file',
            self::AUDIT_PENDING_NO_CANDIDATE,
            self::SOURCE_MANUAL_UPLOAD,
            PurchaseOrderItem::PHOTO_MODE_MANUAL,
            $notes,
        );
    }

    /**
     * Pass 1a: Resolve an admin-approved Mercado Libre publication or variation.
     *
     * @param  Collection<string, Collection<int, MeliPublication>>  $pubByMlm
     * @param  Collection<string, Collection<int, MeliPriceManagerItem>>  $priceByMlm
     * @return ?array<string, mixed>
     */
    private function resolveFromApprovedSelection(
        PurchaseOrderItem $item,
        string $sku,
        Collection $pubByMlm,
        Collection $priceByMlm,
    ): ?array {
        $approvedMlm = $this->normalizedMlm($item->getAttribute('approved_mlm'));
        if ($approvedMlm === null) {
            return null;
        }

        $approvedVarId = $this->nullableTrim($item->getAttribute('approved_variation_id'));
        $approvedAccId = is_numeric($item->getAttribute('approved_meli_account_id'))
            ? (int) $item->getAttribute('approved_meli_account_id')
            : null;
        $fallbackSnapshotUrl = $this->trustedUrl($item->getAttribute('approved_photo_url'));
        $notes = $this->nullableTrim($item->getAttribute('photo_notes'));

        $snapshots = [];

        foreach ($pubByMlm->get($approvedMlm, collect()) as $publication) {
            if ($approvedAccId !== null && (int) ($publication->meli_account_id ?? 0) !== $approvedAccId) {
                continue;
            }

            $detail = $this->extractImageFromPublication($publication, $sku, $approvedVarId, true, true);
            if ($detail['url'] !== null) {
                $snapshots[] = $this->buildPublicationSnapshotCandidate(
                    $publication,
                    $approvedMlm,
                    $detail,
                    ($detail['variation_id'] ?? $approvedVarId) !== null
                        ? self::SOURCE_APPROVED_VARIATION
                        : self::SOURCE_APPROVED_PUBLICATION,
                );
            }
        }

        foreach ($priceByMlm->get($approvedMlm, collect()) as $priceItem) {
            if ($approvedAccId !== null && (int) ($priceItem->meli_account_id ?? 0) !== $approvedAccId) {
                continue;
            }

            $detail = $this->extractImageFromPriceManagerItem($priceItem, $sku, $approvedVarId, true, true);
            if ($detail['url'] !== null) {
                $snapshots[] = $this->buildPriceManagerSnapshotCandidate(
                    $priceItem,
                    $approvedMlm,
                    $detail,
                    ($detail['variation_id'] ?? $approvedVarId) !== null
                        ? self::SOURCE_APPROVED_VARIATION
                        : self::SOURCE_APPROVED_PUBLICATION,
                );
            }
        }

        $liveWinner = null;
        if ($snapshots !== []) {
            $reconciled = $this->reconcileSnapshotsForSameListing($snapshots);
            $liveWinner = $reconciled['winner'] ?? $snapshots[0];
        }

        // Preserve the exact visually approved photo URL even if a later ML snapshot changes,
        // mutates cover pictures, truncates, or disappears. Enrich metadata from live snapshot when available.
        if ($fallbackSnapshotUrl !== null) {
            $resolvedVarId = $approvedVarId ?? ($liveWinner['variation_id'] ?? null);
            $auditCategory = $resolvedVarId !== null ? self::AUDIT_RESOLVED_VARIATION : self::AUDIT_RESOLVED_SKU_LINK;
            $reason = ($liveWinner !== null && ($liveWinner['url'] ?? null) === $fallbackSnapshotUrl)
                ? 'resolved_approved'
                : 'resolved_approved_preserved';

            $res = $this->resolvedResult(
                $item,
                $sku,
                $fallbackSnapshotUrl,
                $resolvedVarId !== null ? self::SOURCE_APPROVED_VARIATION : self::SOURCE_APPROVED_PUBLICATION,
                $approvedMlm,
                $resolvedVarId,
                $liveWinner['meli_account_id'] ?? $approvedAccId,
                $liveWinner['listing_status'] ?? null,
                false,
                [],
                $liveWinner['listing_title'] ?? null,
                $liveWinner['variation_label'] ?? null,
                $reason,
                $auditCategory,
            );
            $res['photo_source_mode'] = PurchaseOrderItem::PHOTO_MODE_APPROVED_ML;
            $res['photo_notes'] = $notes;

            return $res;
        }

        if ($liveWinner !== null) {
            $resolvedVarId = $liveWinner['variation_id'] ?? $approvedVarId;
            $auditCategory = $resolvedVarId !== null ? self::AUDIT_RESOLVED_VARIATION : self::AUDIT_RESOLVED_SKU_LINK;

            $res = $this->resolvedResult(
                $item,
                $sku,
                $liveWinner['url'],
                $liveWinner['source'],
                $approvedMlm,
                $resolvedVarId,
                $liveWinner['meli_account_id'],
                $liveWinner['listing_status'],
                false,
                [],
                $liveWinner['listing_title'] ?? null,
                $liveWinner['variation_label'] ?? null,
                'resolved_approved',
                $auditCategory,
            );
            $res['photo_source_mode'] = PurchaseOrderItem::PHOTO_MODE_APPROVED_ML;
            $res['photo_notes'] = $notes;

            return $res;
        }

        return $this->emptyResolution(
            $item,
            $sku,
            'approved_listing_not_found',
            self::AUDIT_PENDING_MANUAL_APPROVAL,
            null,
            PurchaseOrderItem::PHOTO_MODE_APPROVED_ML,
            $notes,
        );
    }

    /**
     * Priority 3: Resolve using explicit inventory_channel_links (account + MLM + variant ID).
     *
     * @param  Collection<int, InventoryChannelLink>  $productLinks
     * @param  Collection<int, MeliAccount>  $accounts
     * @param  Collection<string, Collection<int, MeliPublication>>  $pubByMlm
     * @param  Collection<string, Collection<int, MeliPriceManagerItem>>  $priceByMlm
     * @param  array<string, true>  $rejectedMlms
     * @return array{resolved: ?array<string, mixed>, failure: ?array<string, mixed>}
     */
    private function resolveFromExplicitLinks(
        PurchaseOrderItem $item,
        string $sku,
        Collection $productLinks,
        Collection $accounts,
        Collection $pubByMlm,
        Collection $priceByMlm,
        array $rejectedMlms = [],
    ): array {
        $lastFailure = null;

        foreach ($productLinks as $link) {
            $mlm = $this->linkMlm($link);
            if ($mlm === '' || isset($rejectedMlms[$mlm])) {
                continue;
            }

            $variationId = $this->nullableTrim($link->external_variant_id);

            // Check synced meli_publications for this linked MLM.
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
                    $resolvedVarId = $detail['variation_id'] ?? $variationId;

                    return [
                        'resolved' => $this->resolvedResult(
                            $item,
                            $sku,
                            $detail['url'],
                            self::SOURCE_CHANNEL_LINK_PUBLICATION,
                            $mlm,
                            $resolvedVarId,
                            $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                            $publication->status,
                            false,
                            [],
                            $detail['listing_title'] ?? null,
                            $detail['variation_label'] ?? null,
                            'resolved',
                            $resolvedVarId !== null ? self::AUDIT_RESOLVED_VARIATION : self::AUDIT_RESOLVED_SKU_LINK,
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
                        'listing_title' => $detail['listing_title'] ?? null,
                        'reason' => $detail['reason'],
                    ]] : [],
                ];
            }

            // Check synced meli_price_manager_items for this linked MLM.
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
                    $resolvedVarId = $detail['variation_id'] ?? $variationId;

                    return [
                        'resolved' => $this->resolvedResult(
                            $item,
                            $sku,
                            $detail['url'],
                            self::SOURCE_CHANNEL_LINK_PRICE_MANAGER,
                            $mlm,
                            $resolvedVarId,
                            $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                            $priceItem->status,
                            false,
                            [],
                            $detail['listing_title'] ?? null,
                            $detail['variation_label'] ?? null,
                            'resolved',
                            $resolvedVarId !== null ? self::AUDIT_RESOLVED_VARIATION : self::AUDIT_RESOLVED_SKU_LINK,
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
                        'listing_title' => $detail['listing_title'] ?? null,
                        'reason' => $detail['reason'],
                    ]] : [],
                ];
            }
        }

        return ['resolved' => null, 'failure' => $lastFailure];
    }

    /**
     * Unified SKU resolution across meli_publications and meli_price_manager_items (including secondary
     * variations embedded in raw JSON) when no explicit match exists.
     *
     * @param  Collection<int, MeliPublication>  $directPubs
     * @param  Collection<int, MeliPublication>  $variationPubs
     * @param  Collection<int, MeliPriceManagerItem>  $directPriceItems
     * @param  Collection<int, MeliPriceManagerItem>  $variationPriceItems
     * @param  ?array<string, mixed>  $linkFailure
     * @param  array<string, true>  $rejectedMlms
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
        array $rejectedMlms = [],
    ): array {
        $rawCandidates = [];
        $intraListingAmbiguities = [];
        $fallbackFailure = $linkFailure;

        $seenPubIds = [];
        foreach ($directPubs as $publication) {
            $seenPubIds[(int) $publication->id] = true;
            $mlm = $this->normalizedMlm($publication->mlm);
            if ($mlm !== null && isset($rejectedMlms[$mlm])) {
                continue;
            }
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
            $mlm = $this->normalizedMlm($publication->mlm);
            if ($mlm !== null && isset($rejectedMlms[$mlm])) {
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
            $mlm = $this->normalizedMlm($priceItem->meli_item_id);
            if ($mlm !== null && isset($rejectedMlms[$mlm])) {
                continue;
            }
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
            $mlm = $this->normalizedMlm($priceItem->meli_item_id);
            if ($mlm !== null && isset($rejectedMlms[$mlm])) {
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
            return $this->emptyResolution(
                $item,
                $sku,
                'variation_ambiguous',
                self::AUDIT_PENDING_MANUAL_APPROVAL,
                null,
                null,
                $this->nullableTrim($item->getAttribute('photo_notes')),
                true,
                $intraListingAmbiguities,
            );
        }

        if ($rawCandidates === []) {
            $isAmbiguous = (bool) ($fallbackFailure['ambiguous'] ?? false);

            return [
                'item_id' => (int) $item->id,
                'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
                'sku' => $sku,
                'url' => null,
                'manual_path' => null,
                'source' => null,
                'mlm' => $fallbackFailure['mlm'] ?? null,
                'variation_id' => $fallbackFailure['variation_id'] ?? null,
                'variation_label' => null,
                'listing_title' => null,
                'meli_account_id' => $fallbackFailure['meli_account_id'] ?? null,
                'listing_status' => $fallbackFailure['listing_status'] ?? null,
                'photo_source_mode' => $item->getAttribute('photo_source_mode'),
                'photo_notes' => $this->nullableTrim($item->getAttribute('photo_notes')),
                'reason' => $fallbackFailure['reason'] ?? 'no_listing_found',
                'audit_category' => $isAmbiguous ? self::AUDIT_PENDING_MANUAL_APPROVAL : self::AUDIT_PENDING_NO_CANDIDATE,
                'ambiguous' => $isAmbiguous,
                'ambiguous_matches' => $fallbackFailure['ambiguous_matches'] ?? [],
                'variation_index_used' => false,
                'variation_scan_truncated' => false,
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
            return $this->emptyResolution(
                $item,
                $sku,
                'conflicting_listing_snapshots',
                self::AUDIT_PENDING_MANUAL_APPROVAL,
                null,
                null,
                $this->nullableTrim($item->getAttribute('photo_notes')),
                true,
                array_map(fn (array $m): array => $this->formatAmbiguousCandidate($m), $snapshotConflicts),
            );
        }

        $distinctUrls = array_values(array_unique(array_column($canonicalMatches, 'url')));
        if (count($distinctUrls) > 1) {
            return $this->emptyResolution(
                $item,
                $sku,
                'multiple_publications_conflicting_photos',
                self::AUDIT_PENDING_MANUAL_APPROVAL,
                null,
                null,
                $this->nullableTrim($item->getAttribute('photo_notes')),
                true,
                array_map(fn (array $m): array => $this->formatAmbiguousCandidate($m), $canonicalMatches),
            );
        }

        usort($canonicalMatches, fn (array $a, array $b): int => $this->compareCanonicalPriority($a, $b));
        $best = $canonicalMatches[0];
        $auditCategory = $best['variation_id'] !== null
            ? self::AUDIT_RESOLVED_VARIATION
            : self::AUDIT_RESOLVED_SKU_LINK;

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
            $best['listing_title'] ?? null,
            $best['variation_label'] ?? null,
            'resolved',
            $auditCategory,
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
        $mlm = $this->normalizedMlm($publication->mlm);

        if ($detail['url'] !== null) {
            $rawCandidates[] = $this->buildPublicationSnapshotCandidate($publication, $mlm, $detail, $source);

            return;
        }

        if ($detail['ambiguous']) {
            $intraListingAmbiguities[] = [
                'source' => $source,
                'mlm' => $mlm,
                'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                'listing_title' => $detail['listing_title'] ?? null,
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
        $mlm = $this->normalizedMlm($priceItem->meli_item_id);

        if ($detail['url'] !== null) {
            $rawCandidates[] = $this->buildPriceManagerSnapshotCandidate($priceItem, $mlm, $detail, $source);

            return;
        }

        if ($detail['ambiguous']) {
            $intraListingAmbiguities[] = [
                'source' => $source,
                'mlm' => $mlm,
                'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
                'listing_title' => $detail['listing_title'] ?? null,
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
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function buildPublicationSnapshotCandidate(
        MeliPublication $publication,
        ?string $mlm,
        array $detail,
        string $source,
    ): array {
        $variationId = $detail['variation_id'];
        $isCurrent = array_key_exists('is_current', $publication->getAttributes())
            ? (bool) $publication->is_current
            : true;
        $syncedAtTs = $publication->last_sync_at ? $publication->last_sync_at->getTimestamp() : null;

        return [
            'identity_key' => $this->listingIdentityKey($mlm, $variationId, 'pub#'.$publication->id),
            'url' => $detail['url'],
            'source' => $source,
            'mlm' => $mlm,
            'variation_id' => $variationId,
            'variation_label' => $detail['variation_label'] ?? null,
            'listing_title' => $detail['listing_title'] ?? null,
            'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
            'listing_status' => $publication->status,
            'status_rank' => $this->statusRank($publication->status),
            'is_current' => $isCurrent,
            'synced_at_ts' => $syncedAtTs,
            'source_rank' => 0, // meli_publications preferred over price manager when tied
            'row_id' => (int) $publication->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function buildPriceManagerSnapshotCandidate(
        MeliPriceManagerItem $priceItem,
        ?string $mlm,
        array $detail,
        string $source,
    ): array {
        $variationId = $detail['variation_id'];
        $syncedAtTs = $priceItem->last_synced_at ? $priceItem->last_synced_at->getTimestamp() : null;

        return [
            'identity_key' => $this->listingIdentityKey($mlm, $variationId, 'pm#'.$priceItem->id),
            'url' => $detail['url'],
            'source' => $source,
            'mlm' => $mlm,
            'variation_id' => $variationId,
            'variation_label' => $detail['variation_label'] ?? null,
            'listing_title' => $detail['listing_title'] ?? null,
            'meli_account_id' => $priceItem->meli_account_id ? (int) $priceItem->meli_account_id : null,
            'listing_status' => $priceItem->status,
            'status_rank' => $this->statusRank($priceItem->status),
            'is_current' => true,
            'synced_at_ts' => $syncedAtTs,
            'source_rank' => 1,
            'row_id' => (int) $priceItem->id,
        ];
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
            'variation_label' => $candidate['variation_label'] ?? null,
            'listing_title' => $candidate['listing_title'] ?? null,
            'meli_account_id' => $candidate['meli_account_id'],
            'listing_status' => $candidate['listing_status'],
            'url' => $candidate['url'],
        ];
    }

    /**
     * @return array{url: ?string, variation_id: ?string, variation_label: ?string, listing_title: ?string, reason: string, ambiguous: bool}
     */
    private function extractImageFromPriceManagerItem(
        MeliPriceManagerItem $priceItem,
        string $sku,
        ?string $variationId,
        bool $trustExplicitLink,
        bool $allowSkuMismatch = false,
    ): array {
        $rawItem = is_array($priceItem->raw_item) ? $priceItem->raw_item : [];
        $title = $this->nullableTrim($rawItem['title'] ?? $priceItem->title ?? null);

        if ($rawItem === [] || ! empty($rawItem['truncated'])) {
            return [
                'url' => null,
                'variation_id' => $variationId,
                'variation_label' => null,
                'listing_title' => $title,
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

        if (empty($rawItem['title']) && $title !== null) {
            $rawItem['title'] = $title;
        }

        $snapshot = new MeliPublication;
        $snapshot->mlm = $priceItem->meli_item_id;
        $snapshot->meli_account_id = $priceItem->meli_account_id;
        $snapshot->status = $priceItem->status;
        $snapshot->permalink = $priceItem->permalink;
        $snapshot->raw = $rawItem;
        // For price manager items without an explicit channel link, require SKU verification in the
        // raw ML payload itself rather than trusting the denormalized `sku` column alone.
        $snapshot->sku = $trustExplicitLink ? (string) ($priceItem->sku ?? '') : '';

        return $this->extractImageFromPublication($snapshot, $sku, $variationId, $trustExplicitLink, $allowSkuMismatch);
    }

    /**
     * @return array{url: ?string, variation_id: ?string, variation_label: ?string, listing_title: ?string, reason: string, ambiguous: bool}
     */
    private function extractImageFromPublication(
        MeliPublication $publication,
        string $sku,
        ?string $variationId = null,
        bool $trustExplicitLink = false,
        bool $allowSkuMismatch = false,
    ): array {
        $sku = trim($sku);
        if ($sku === '' && ! $allowSkuMismatch) {
            return [
                'url' => null,
                'variation_id' => $variationId,
                'variation_label' => null,
                'listing_title' => null,
                'reason' => 'missing_sku',
                'ambiguous' => false,
            ];
        }

        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $modelPictures = is_array($publication->pictures ?? null) ? $publication->pictures : [];
        $listingTitle = $this->extractListingTitle($item, $publication);

        if ($item === [] && $modelPictures === []) {
            return [
                'url' => null,
                'variation_id' => $variationId,
                'variation_label' => null,
                'listing_title' => $listingTitle,
                'reason' => 'empty_raw_payload',
                'ambiguous' => false,
            ];
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

                $variationLabel = $this->extractVariationLabel($variation);

                if (! $allowSkuMismatch) {
                    $variationSku = $this->sellerSku($variation);
                    if ($variationSku !== '' && strcasecmp($variationSku, $sku) !== 0) {
                        return [
                            'url' => null,
                            'variation_id' => $variationId,
                            'variation_label' => $variationLabel,
                            'listing_title' => $listingTitle,
                            'reason' => 'variation_sku_mismatch',
                            'ambiguous' => false,
                        ];
                    }
                }

                $pic = $this->variationPicture($variation, $pictures);

                return [
                    'url' => $pic['url'],
                    'variation_id' => $variationId,
                    'variation_label' => $variationLabel,
                    'listing_title' => $listingTitle,
                    'reason' => $pic['url'] !== null ? 'resolved' : $pic['reason'],
                    'ambiguous' => false,
                ];
            }

            return [
                'url' => null,
                'variation_id' => $variationId,
                'variation_label' => null,
                'listing_title' => $listingTitle,
                'reason' => 'variation_not_found',
                'ambiguous' => false,
            ];
        }

        if ($variations !== [] && ! $allowSkuMismatch) {
            // A generic listing cover can be the wrong color/model: require a single exact SKU variant.
            $matches = array_values(array_filter(
                $variations,
                fn (mixed $variation): bool => is_array($variation) && $sku !== '' && strcasecmp($this->sellerSku($variation), $sku) === 0,
            ));

            if (count($matches) === 0) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'variation_label' => null,
                    'listing_title' => $listingTitle,
                    'reason' => 'variation_not_found',
                    'ambiguous' => false,
                ];
            }

            if (count($matches) > 1) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'variation_label' => null,
                    'listing_title' => $listingTitle,
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
                'variation_label' => $this->extractVariationLabel($matchedVariation),
                'listing_title' => $listingTitle,
                'reason' => $pic['url'] !== null ? 'resolved' : $pic['reason'],
                'ambiguous' => false,
            ];
        }

        if ($variations !== [] && $allowSkuMismatch && $sku !== '') {
            // Even when a whole multi-variation listing was approved without variation_id, prefer exact SKU variation if unique.
            $matches = array_values(array_filter(
                $variations,
                fn (mixed $variation): bool => is_array($variation) && strcasecmp($this->sellerSku($variation), $sku) === 0,
            ));

            if (count($matches) === 1) {
                $matchedVariation = $matches[0];
                $matchedVariationId = isset($matchedVariation['id']) ? (string) $matchedVariation['id'] : null;
                $pic = $this->variationPicture($matchedVariation, $pictures);
                if ($pic['url'] !== null) {
                    return [
                        'url' => $pic['url'],
                        'variation_id' => $matchedVariationId,
                        'variation_label' => $this->extractVariationLabel($matchedVariation),
                        'listing_title' => $listingTitle,
                        'reason' => 'resolved',
                        'ambiguous' => false,
                    ];
                }
            }
        }

        if (! $allowSkuMismatch) {
            $sellerSku = $this->sellerSku($item);
            $columnSku = trim((string) ($publication->sku ?? ''));

            if ($sellerSku !== '' && strcasecmp($sellerSku, $sku) !== 0) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'variation_label' => null,
                    'listing_title' => $listingTitle,
                    'reason' => 'sku_mismatch',
                    'ambiguous' => false,
                ];
            }

            if ($sellerSku === '' && ! $trustExplicitLink) {
                if ($columnSku === '' || strcasecmp($columnSku, $sku) !== 0) {
                    return [
                        'url' => null,
                        'variation_id' => null,
                        'variation_label' => null,
                        'listing_title' => $listingTitle,
                        'reason' => 'publication_sku_unverified',
                        'ambiguous' => false,
                    ];
                }
            }

            if ($sellerSku === '' && $trustExplicitLink && $columnSku !== '' && strcasecmp($columnSku, $sku) !== 0) {
                return [
                    'url' => null,
                    'variation_id' => null,
                    'variation_label' => null,
                    'listing_title' => $listingTitle,
                    'reason' => 'sku_mismatch',
                    'ambiguous' => false,
                ];
            }
        }

        $pic = $this->firstValidPicture($pictures, $item['thumbnail'] ?? null);

        return [
            'url' => $pic['url'],
            'variation_id' => null,
            'variation_label' => null,
            'listing_title' => $listingTitle,
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
     * Resolve an explicit (MLM, variation_id) candidate from synced ML tables for admin approval.
     *
     * @return ?array<string, mixed>
     */
    public function resolveExplicitCandidate(
        string $mlm,
        ?string $variationId = null,
        ?int $meliAccountId = null,
    ): ?array {
        $normalizedMlm = $this->normalizedMlm($mlm);
        if ($normalizedMlm === null) {
            return null;
        }

        $hasPublications = Schema::hasTable('meli_publications');
        $hasPriceManager = Schema::hasTable('meli_price_manager_items');
        if (! $hasPublications && ! $hasPriceManager) {
            return null;
        }

        $mlmVariants = $this->caseVariants([$normalizedMlm]);
        $snapshots = [];

        if ($hasPublications) {
            $pubColumns = Schema::getColumnListing('meli_publications');
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
                in_array('permalink', $pubColumns, true) ? 'permalink' : null,
                $pubHasIsCurrent ? 'is_current' : null,
                $pubHasPictures ? 'pictures' : null,
            ]));

            $pubs = $this->queryPublications($pubSelect, $pubHasIsCurrent, [], $mlmVariants);
            foreach ($pubs as $publication) {
                if ($meliAccountId !== null && (int) ($publication->meli_account_id ?? 0) !== $meliAccountId) {
                    continue;
                }
                $detail = $this->extractImageFromPublication($publication, '', $variationId, true, true);
                if ($detail['url'] !== null) {
                    $snapshots[] = $this->buildPublicationSnapshotCandidate(
                        $publication,
                        $normalizedMlm,
                        $detail,
                        $variationId !== null ? self::SOURCE_APPROVED_VARIATION : self::SOURCE_APPROVED_PUBLICATION,
                    );
                }
            }
        }

        if ($hasPriceManager) {
            $priceSelect = $this->priceManagerSelectColumns();
            $priceItems = $this->queryPriceManagerItems([], $mlmVariants, $priceSelect);
            foreach ($priceItems as $priceItem) {
                if ($meliAccountId !== null && (int) ($priceItem->meli_account_id ?? 0) !== $meliAccountId) {
                    continue;
                }
                $detail = $this->extractImageFromPriceManagerItem($priceItem, '', $variationId, true, true);
                if ($detail['url'] !== null) {
                    $snapshots[] = $this->buildPriceManagerSnapshotCandidate(
                        $priceItem,
                        $normalizedMlm,
                        $detail,
                        $variationId !== null ? self::SOURCE_APPROVED_VARIATION : self::SOURCE_APPROVED_PUBLICATION,
                    );
                }
            }
        }

        if ($snapshots === []) {
            return null;
        }

        $reconciled = $this->reconcileSnapshotsForSameListing($snapshots);
        $winner = $reconciled['winner'] ?? $snapshots[0];

        return [
            'mlm' => $normalizedMlm,
            'variation_id' => $winner['variation_id'] ?? $variationId,
            'variation_label' => $winner['variation_label'] ?? null,
            'listing_title' => $winner['listing_title'] ?? null,
            'meli_account_id' => $winner['meli_account_id'],
            'listing_status' => $winner['listing_status'],
            'url' => $winner['url'],
        ];
    }

    /**
     * Discover candidate Mercado Libre publications and variations for an item using:
     * 1. Approved / linked / exact-SKU listings (preferring normalized SKU index)
     * 2. Title & keyword discovery (ONLY to propose candidates for manual review/approval).
     *
     * @param  ?Collection<int, MeliAccount>  $preloadedAccountsById
     * @return list<array<string, mixed>>
     */
    public function discoverCandidatesForItem(
        PurchaseOrderItem $item,
        ?string $customQuery = null,
        int $limit = 30,
        bool $skipVariationRawScan = false,
        ?Collection $preloadedAccountsById = null,
        ?float $maxSeconds = null,
    ): array {
        $hasPublications = Schema::hasTable('meli_publications');
        $hasPriceManager = Schema::hasTable('meli_price_manager_items');
        if (! $hasPublications && ! $hasPriceManager) {
            return [];
        }

        $deadline = $maxSeconds !== null
            ? ($maxSeconds > 0 ? microtime(true) + $maxSeconds : microtime(true) - 1.0)
            : null;

        $item->loadMissing(['product:id,sku,name,brand']);

        $sku = $this->itemSku($item);
        $supplierSku = trim((string) ($item->supplier_sku ?? ''));
        $productName = trim((string) ($item->product?->name ?? $item->product_name ?? ''));
        $searchText = trim((string) ($customQuery ?? ''));
        if ($searchText === '') {
            $searchText = $productName;
        }

        $approvedMlm = $this->normalizedMlm($item->getAttribute('approved_mlm'));
        $approvedVarId = $this->nullableTrim($item->getAttribute('approved_variation_id'));
        $photoMode = $item->getAttribute('photo_source_mode');
        $rejectedMlms = $this->normalizedRejectedMlms($item);

        if ($preloadedAccountsById !== null) {
            $accountsById = $preloadedAccountsById;
        } else {
            $accountsById = Schema::hasTable('meli_accounts')
                ? MeliAccount::query()->get(['id', 'meli_user_id', 'nickname', 'is_default'])->keyBy('id')
                : collect();
        }

        $links = (Schema::hasTable('inventory_channel_links') && $item->inventory_product_id)
            ? InventoryChannelLink::query()
                ->where('inventory_product_id', (int) $item->inventory_product_id)
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->get(['external_listing_id', 'external_product_id', 'external_variant_id'])
            : collect();

        $linkedMlms = [];
        foreach ($links as $link) {
            $linkMlm = $this->linkMlm($link);
            if ($linkMlm !== '') {
                $linkedMlms[$linkMlm] = $this->nullableTrim($link->external_variant_id);
            }
        }

        $explicitMlms = array_values(array_unique(array_filter([
            $approvedMlm,
            ...array_keys($linkedMlms),
            ...$this->extractMlmTokens($searchText),
        ])));

        $skuTerms = array_values(array_unique(array_filter([$sku, $supplierSku])));
        $tokens = $this->extractSearchTokens($searchText);
        $skuVariants = $this->caseVariants($skuTerms);
        $mlmVariants = $this->caseVariants($explicitMlms);

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
            in_array('permalink', $pubColumns, true) ? 'permalink' : null,
            $pubHasIsCurrent ? 'is_current' : null,
            $pubHasPictures ? 'pictures' : null,
        ]));

        $priceSelect = $hasPriceManager ? $this->priceManagerSelectColumns() : [];

        $publications = collect();
        $priceItems = collect();

        // Step 1: Indexed exact SKU / MLM queries first (cheap, bounded).
        if ($hasPublications && ($skuVariants !== [] || $mlmVariants !== [])) {
            $publications = $publications->merge(
                $this->queryPublications($pubSelect, $pubHasIsCurrent, $skuVariants, $mlmVariants)
            );
        }

        if ($hasPriceManager && ($skuVariants !== [] || $mlmVariants !== [])) {
            $priceItems = $priceItems->merge(
                $this->queryPriceManagerItems($skuVariants, $mlmVariants, $priceSelect)
            );
        }

        // Step 2: Variation SKU lookup (verified active generation first; bounded raw scan only if allowed).
        if ($sku !== '') {
            $skuUpper = strtoupper($sku);
            $activeVariationGeneration = $this->resolveActiveVariationSkuIndexGeneration();
            if ($activeVariationGeneration !== null) {
                $indexed = $this->lookupVariationRowsFromSkuIndex(
                    $activeVariationGeneration,
                    [$skuUpper],
                    [$skuUpper => true],
                    $hasPublications,
                    $pubSelect,
                    $publications->pluck('id')->all(),
                    $hasPriceManager,
                    $priceSelect,
                    $priceItems->pluck('id')->all(),
                );
                if (isset($indexed['pub_by_sku'][$skuUpper])) {
                    $publications = $publications->merge($indexed['pub_by_sku'][$skuUpper]);
                }
                if (isset($indexed['price_by_sku'][$skuUpper])) {
                    $priceItems = $priceItems->merge($indexed['price_by_sku'][$skuUpper]);
                }
            } elseif (! $skipVariationRawScan && ! $this->isDeadlineOrMemoryExceeded($deadline)) {
                if ($hasPublications) {
                    $pubScan = $this->scanVariationPublicationsByRaw(
                        $pubSelect,
                        [$sku],
                        $publications->pluck('id')->all(),
                        [$skuUpper => true],
                        $deadline,
                    );
                    if (isset($pubScan['by_sku'][$skuUpper])) {
                        $publications = $publications->merge($pubScan['by_sku'][$skuUpper]);
                    }
                }
                if ($hasPriceManager && ! $this->isDeadlineOrMemoryExceeded($deadline)) {
                    $priceScan = $this->scanVariationPriceManagerItemsByRaw(
                        [$sku],
                        $priceItems->pluck('id')->all(),
                        [$skuUpper => true],
                        $priceSelect,
                        $deadline,
                    );
                    if (isset($priceScan['by_sku'][$skuUpper])) {
                        $priceItems = $priceItems->merge($priceScan['by_sku'][$skuUpper]);
                    }
                }
            }
        }

        // Step 3: Title/keyword discovery — search compact `meli_price_manager_items.title` FIRST
        // before scanning larger `meli_publications.raw`, always respecting time/memory budgets.
        if ($tokens !== []) {
            if ($hasPriceManager && ! $this->isDeadlineOrMemoryExceeded($deadline)) {
                $priceItems = $priceItems->merge(
                    $this->searchPriceManagerItemsByTokens($tokens, $priceItems->pluck('id')->all(), $priceSelect)
                );
            }

            if ($hasPublications && ! $this->isDeadlineOrMemoryExceeded($deadline)) {
                $publications = $publications->merge(
                    $this->searchPublicationsByTokens($pubSelect, $tokens, $publications->pluck('id')->all())
                );
            }
        }

        $candidatesByIdentity = [];

        foreach ($publications as $publication) {
            $mlm = $this->normalizedMlm($publication->mlm);
            if ($mlm === null) {
                continue;
            }
            $isCurrent = array_key_exists('is_current', $publication->getAttributes())
                ? (bool) $publication->is_current
                : true;
            $syncedAtTs = $publication->last_sync_at ? $publication->last_sync_at->getTimestamp() : null;

            $this->expandPublicationToDiscoveryCandidates(
                $publication,
                $mlm,
                $isCurrent,
                $syncedAtTs,
                0,
                (int) $publication->id,
                $candidatesByIdentity,
            );
        }

        foreach ($priceItems as $priceItem) {
            $mlm = $this->normalizedMlm($priceItem->meli_item_id);
            if ($mlm === null) {
                continue;
            }
            $rawItem = is_array($priceItem->raw_item) ? $priceItem->raw_item : [];
            if ($rawItem === [] || ! empty($rawItem['truncated'])) {
                continue;
            }
            if (empty($rawItem['attributes']) && is_array($priceItem->raw_attributes) && $priceItem->raw_attributes !== []) {
                $rawItem['attributes'] = $priceItem->raw_attributes;
            }
            if (empty($rawItem['thumbnail']) && is_string($priceItem->thumbnail) && trim($priceItem->thumbnail) !== '') {
                $rawItem['thumbnail'] = $priceItem->thumbnail;
            }
            if (empty($rawItem['title']) && is_string($priceItem->title) && trim($priceItem->title) !== '') {
                $rawItem['title'] = trim($priceItem->title);
            }

            $snapshot = new MeliPublication;
            $snapshot->mlm = $priceItem->meli_item_id;
            $snapshot->meli_account_id = $priceItem->meli_account_id;
            $snapshot->status = $priceItem->status;
            $snapshot->permalink = $priceItem->permalink;
            $snapshot->raw = $rawItem;
            $snapshot->sku = (string) ($priceItem->sku ?? '');

            $syncedAtTs = $priceItem->last_synced_at ? $priceItem->last_synced_at->getTimestamp() : null;

            $this->expandPublicationToDiscoveryCandidates(
                $snapshot,
                $mlm,
                true,
                $syncedAtTs,
                1,
                (int) $priceItem->id,
                $candidatesByIdentity,
            );
        }

        $scoreTokens = $this->extractSearchTokens($searchText !== '' ? $searchText : $productName);
        $mlmLookupSet = array_flip($this->extractMlmTokens($searchText));

        $formatted = [];
        foreach ($candidatesByIdentity as $snapshots) {
            $reconciled = $this->reconcileSnapshotsForSameListing($snapshots);
            $winner = $reconciled['winner'] ?? $snapshots[0];
            $mlm = $winner['mlm'];
            $varId = $winner['variation_id'];
            $candidateSku = (string) ($winner['sku'] ?? '');

            $isSelected = $photoMode === PurchaseOrderItem::PHOTO_MODE_APPROVED_ML
                && $approvedMlm === $mlm
                && (string) ($approvedVarId ?? '') === (string) ($varId ?? '');

            $isExactSku = $sku !== '' && $candidateSku !== '' && strcasecmp($candidateSku, $sku) === 0;
            $isLinked = array_key_exists($mlm, $linkedMlms)
                && ($linkedMlms[$mlm] === null || (string) $linkedMlms[$mlm] === (string) ($varId ?? ''));

            $matchType = match (true) {
                $isSelected => 'approved',
                $isLinked => 'channel_link',
                $isExactSku => 'exact_sku',
                isset($mlmLookupSet[$mlm]) => 'mlm_lookup',
                default => 'title_match',
            };

            $matchPriority = match ($matchType) {
                'approved' => 0,
                'channel_link' => 1,
                'exact_sku' => 2,
                'mlm_lookup' => 3,
                default => 4,
            };

            $haystack = mb_strtolower(trim(implode(' ', array_filter([
                $winner['listing_title'] ?? '',
                $winner['variation_label'] ?? '',
                $candidateSku,
                $mlm,
            ]))));

            $tokenHits = 0;
            foreach ($scoreTokens as $token) {
                if (str_contains($haystack, mb_strtolower($token))) {
                    $tokenHits++;
                }
            }

            // If a custom query was provided (without exact SKU/MLM), require at least one match in the candidate.
            if ($customQuery !== null && trim($customQuery) !== '' && $matchPriority === 4 && $tokenHits === 0) {
                continue;
            }

            $account = $winner['meli_account_id'] ? $accountsById->get((int) $winner['meli_account_id']) : null;
            $accountLabel = $account
                ? (trim((string) ($account->nickname ?? '')) ?: trim((string) ($account->meli_user_id ?? '')) ?: ('Cuenta #'.$account->id))
                : ($winner['meli_account_id'] ? ('Cuenta #'.$winner['meli_account_id']) : null);

            $formatted[] = [
                'mlm' => $mlm,
                'variation_id' => $varId,
                'variation_label' => $winner['variation_label'],
                'meli_account_id' => $winner['meli_account_id'],
                'account_label' => $accountLabel,
                'title' => $winner['listing_title'],
                'sku' => $candidateSku !== '' ? $candidateSku : null,
                'listing_status' => $winner['listing_status'],
                'permalink' => $winner['permalink'],
                'url' => $winner['url'],
                'match_type' => $matchType,
                'is_rejected' => isset($rejectedMlms[$mlm]),
                'is_selected' => $isSelected,
                '_sort_selected' => $isSelected ? 0 : 1,
                '_sort_rejected' => isset($rejectedMlms[$mlm]) ? 1 : 0,
                '_sort_priority' => $matchPriority,
                '_sort_token_hits' => $tokenHits,
                '_sort_status' => $winner['status_rank'],
                '_sort_current' => $winner['is_current'] ? 0 : 1,
                '_sort_ts' => $winner['synced_at_ts'] ?? PHP_INT_MIN,
            ];
        }

        usort($formatted, static function (array $a, array $b): int {
            return ($a['_sort_selected'] <=> $b['_sort_selected'])
                ?: ($a['_sort_rejected'] <=> $b['_sort_rejected'])
                ?: ($a['_sort_priority'] <=> $b['_sort_priority'])
                ?: ($b['_sort_token_hits'] <=> $a['_sort_token_hits'])
                ?: ($a['_sort_status'] <=> $b['_sort_status'])
                ?: ($a['_sort_current'] <=> $b['_sort_current'])
                ?: ($b['_sort_ts'] <=> $a['_sort_ts']);
        });

        return array_map(static function (array $row): array {
            unset(
                $row['_sort_selected'],
                $row['_sort_rejected'],
                $row['_sort_priority'],
                $row['_sort_token_hits'],
                $row['_sort_status'],
                $row['_sort_current'],
                $row['_sort_ts'],
            );

            return $row;
        }, array_slice($formatted, 0, max(1, $limit)));
    }

    /**
     * Check whether title/keyword discovery finds at least one candidate for an unresolved item.
     */
    public function hasDiscoveryCandidatesForItem(PurchaseOrderItem $item): bool
    {
        return $this->discoverCandidatesForItem($item, null, 1) !== [];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $candidatesByIdentity
     */
    private function expandPublicationToDiscoveryCandidates(
        MeliPublication $publication,
        string $mlm,
        bool $isCurrent,
        ?int $syncedAtTs,
        int $sourceRank,
        int $rowId,
        array &$candidatesByIdentity,
    ): void {
        $item = MeliPublication::itemArrayFromRaw($publication->raw);
        $modelPictures = is_array($publication->pictures ?? null) ? $publication->pictures : [];
        if ($item === [] && $modelPictures === []) {
            return;
        }

        $listingTitle = $this->extractListingTitle($item, $publication);
        $permalink = $this->nullableTrim($publication->permalink ?? ($item['permalink'] ?? null));
        $variations = is_array($item['variations'] ?? null) ? $item['variations'] : [];
        $pictures = is_array($item['pictures'] ?? null) && $item['pictures'] !== []
            ? $item['pictures']
            : $modelPictures;

        if ($variations !== []) {
            $addedVariation = false;
            foreach ($variations as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                $varId = isset($variation['id']) ? trim((string) $variation['id']) : '';
                if ($varId === '') {
                    continue;
                }
                $pic = $this->variationPicture($variation, $pictures);
                if ($pic['url'] === null) {
                    continue;
                }

                $addedVariation = true;
                $identityKey = $this->listingIdentityKey($mlm, $varId, 'pub#'.$rowId);
                $candidatesByIdentity[$identityKey][] = [
                    'identity_key' => $identityKey,
                    'url' => $pic['url'],
                    'source' => 'discovery_variation',
                    'mlm' => $mlm,
                    'variation_id' => $varId,
                    'variation_label' => $this->extractVariationLabel($variation),
                    'listing_title' => $listingTitle,
                    'sku' => $this->sellerSku($variation),
                    'permalink' => $permalink,
                    'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
                    'listing_status' => $publication->status,
                    'status_rank' => $this->statusRank($publication->status),
                    'is_current' => $isCurrent,
                    'synced_at_ts' => $syncedAtTs,
                    'source_rank' => $sourceRank,
                    'row_id' => $rowId,
                ];
            }

            if ($addedVariation) {
                return;
            }
        }

        $pic = $this->firstValidPicture($pictures, $item['thumbnail'] ?? null);
        if ($pic['url'] === null) {
            return;
        }

        $identityKey = $this->listingIdentityKey($mlm, null, 'pub#'.$rowId);
        $candidatesByIdentity[$identityKey][] = [
            'identity_key' => $identityKey,
            'url' => $pic['url'],
            'source' => 'discovery_publication',
            'mlm' => $mlm,
            'variation_id' => null,
            'variation_label' => null,
            'listing_title' => $listingTitle,
            'sku' => $this->sellerSku($item) ?: trim((string) ($publication->sku ?? '')),
            'permalink' => $permalink,
            'meli_account_id' => $publication->meli_account_id ? (int) $publication->meli_account_id : null,
            'listing_status' => $publication->status,
            'status_rank' => $this->statusRank($publication->status),
            'is_current' => $isCurrent,
            'synced_at_ts' => $syncedAtTs,
            'source_rank' => $sourceRank,
            'row_id' => $rowId,
        ];
    }

    /**
     * Index bulk-queried variation publications strictly by the verified SKUs present in each row.
     * Prevents an unrelated MLM from leaking into another item's diagnostics or candidates.
     *
     * @param  Collection<int, MeliPublication>  $publications
     * @param  array<string, true>  $targetSkusUpper
     * @return array<string, Collection<int, MeliPublication>>
     */
    private function indexPublicationsByVerifiedSku(Collection $publications, array $targetSkusUpper): array
    {
        $indexed = [];

        foreach ($publications as $publication) {
            $item = MeliPublication::itemArrayFromRaw($publication->raw);
            $matchedSkus = [];

            $colSku = strtoupper(trim((string) ($publication->sku ?? '')));
            if ($colSku !== '' && isset($targetSkusUpper[$colSku])) {
                $matchedSkus[$colSku] = true;
            }

            if ($item !== []) {
                $itemSku = strtoupper($this->sellerSku($item));
                if ($itemSku !== '' && isset($targetSkusUpper[$itemSku])) {
                    $matchedSkus[$itemSku] = true;
                }

                foreach ((array) ($item['variations'] ?? []) as $variation) {
                    if (! is_array($variation)) {
                        continue;
                    }
                    $varSku = strtoupper($this->sellerSku($variation));
                    if ($varSku !== '' && isset($targetSkusUpper[$varSku])) {
                        $matchedSkus[$varSku] = true;
                    }
                }
            }

            foreach (array_keys($matchedSkus) as $skuUpper) {
                if (! isset($indexed[$skuUpper])) {
                    $indexed[$skuUpper] = collect();
                }
                $indexed[$skuUpper]->push($publication);
            }
        }

        return $indexed;
    }

    /**
     * Index bulk-queried price manager items strictly by verified SKUs inside raw_item.
     *
     * @param  Collection<int, MeliPriceManagerItem>  $priceItems
     * @param  array<string, true>  $targetSkusUpper
     * @return array<string, Collection<int, MeliPriceManagerItem>>
     */
    private function indexPriceManagerItemsByVerifiedSku(Collection $priceItems, array $targetSkusUpper): array
    {
        $indexed = [];

        foreach ($priceItems as $priceItem) {
            $rawItem = is_array($priceItem->raw_item) ? $priceItem->raw_item : [];
            if ($rawItem === [] || ! empty($rawItem['truncated'])) {
                continue;
            }

            if (empty($rawItem['attributes']) && is_array($priceItem->raw_attributes) && $priceItem->raw_attributes !== []) {
                $rawItem['attributes'] = $priceItem->raw_attributes;
            }

            $matchedSkus = [];
            $itemSku = strtoupper($this->sellerSku($rawItem));
            if ($itemSku !== '' && isset($targetSkusUpper[$itemSku])) {
                $matchedSkus[$itemSku] = true;
            }

            foreach ((array) ($rawItem['variations'] ?? []) as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                $varSku = strtoupper($this->sellerSku($variation));
                if ($varSku !== '' && isset($targetSkusUpper[$varSku])) {
                    $matchedSkus[$varSku] = true;
                }
            }

            foreach (array_keys($matchedSkus) as $skuUpper) {
                if (! isset($indexed[$skuUpper])) {
                    $indexed[$skuUpper] = collect();
                }
                $indexed[$skuUpper]->push($priceItem);
            }
        }

        return $indexed;
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

    /** @return list<string> */
    private function priceManagerSelectColumns(): array
    {
        $columns = Schema::getColumnListing('meli_price_manager_items');

        return array_values(array_filter([
            'id',
            'meli_account_id',
            'meli_item_id',
            'sku',
            in_array('title', $columns, true) ? 'title' : null,
            in_array('permalink', $columns, true) ? 'permalink' : null,
            'raw_item',
            'raw_attributes',
            'thumbnail',
            'status',
            'last_synced_at',
        ]));
    }

    /**
     * @param  list<string>  $skuVariants
     * @param  list<string>  $mlmVariants
     * @param  list<string>  $select
     * @return Collection<int, MeliPriceManagerItem>
     */
    private function queryPriceManagerItems(array $skuVariants, array $mlmVariants, array $select = []): Collection
    {
        $columns = $select !== [] ? $select : $this->priceManagerSelectColumns();

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
            ->get($columns);
    }

    /**
     * Return the active verified generation from `meli_listing_sku_index_state`, or null when
     * the index is missing, uninitialized, building its initial generation, or failed without a
     * prior complete generation. Row existence in `meli_listing_sku_index` alone NEVER indicates readiness.
     */
    private function resolveActiveVariationSkuIndexGeneration(): ?int
    {
        if (! Schema::hasTable(MeliListingSkuIndexService::STATE_TABLE)) {
            return null;
        }

        $state = DB::table(MeliListingSkuIndexService::STATE_TABLE)
            ->where('id', MeliListingSkuIndexService::STATE_ROW_ID)
            ->first(['active_generation', 'status']);

        if ($state === null) {
            return null;
        }

        $activeGeneration = (int) $state->active_generation;

        return $activeGeneration > 0 ? $activeGeneration : null;
    }

    /**
     * Resolve variation listings by querying the verified active generation of the B-tree indexed
     * `meli_listing_sku_index` table (`mlsi_gen_sku_idx`) and hydrating only matched primary keys
     * (zero `raw LIKE` scans). Every hydrated row is still verified against its raw payload so
     * stale index entries can never misassign a photo.
     *
     * @param  list<string>  $skusUpper
     * @param  array<string, true>  $targetSkusUpper
     * @param  list<string>  $pubSelect
     * @param  list<int>  $excludePubIds
     * @param  list<string>  $priceSelect
     * @param  list<int>  $excludePriceIds
     * @return array{pub_by_sku: array<string, Collection<int, MeliPublication>>, price_by_sku: array<string, Collection<int, MeliPriceManagerItem>>}
     */
    private function lookupVariationRowsFromSkuIndex(
        int $activeGeneration,
        array $skusUpper,
        array $targetSkusUpper,
        bool $hasPublications,
        array $pubSelect,
        array $excludePubIds,
        bool $hasPriceManager,
        array $priceSelect,
        array $excludePriceIds,
    ): array {
        if ($skusUpper === [] || $activeGeneration <= 0) {
            return ['pub_by_sku' => [], 'price_by_sku' => []];
        }

        $excludePubSet = array_fill_keys(array_map('intval', $excludePubIds), true);
        $excludePriceSet = array_fill_keys(array_map('intval', $excludePriceIds), true);

        $pubIds = [];
        $priceIds = [];

        foreach (array_chunk($skusUpper, 500) as $skuChunk) {
            $rows = DB::table(MeliListingSkuIndexService::TABLE)
                ->where('generation', $activeGeneration)
                ->whereIn('sku_normalized', $skuChunk)
                ->get(['source_table', 'source_row_id']);

            foreach ($rows as $row) {
                $rowId = (int) $row->source_row_id;
                if ($row->source_table === MeliListingSkuIndexService::SOURCE_PUBLICATIONS) {
                    if ($hasPublications && ! isset($excludePubSet[$rowId])) {
                        $pubIds[$rowId] = $rowId;
                    }
                } elseif ($row->source_table === MeliListingSkuIndexService::SOURCE_PRICE_MANAGER) {
                    if ($hasPriceManager && ! isset($excludePriceSet[$rowId])) {
                        $priceIds[$rowId] = $rowId;
                    }
                }
            }
        }

        $pubBySku = [];
        if ($pubIds !== [] && $pubSelect !== []) {
            $fetchedPubs = collect();
            foreach (array_chunk(array_values($pubIds), 500) as $idChunk) {
                $fetchedPubs = $fetchedPubs->merge(
                    MeliPublication::query()->whereIn('id', $idChunk)->get($pubSelect)
                );
            }
            $pubBySku = $this->indexPublicationsByVerifiedSku($fetchedPubs, $targetSkusUpper);
        }

        $priceBySku = [];
        if ($priceIds !== []) {
            $columns = $priceSelect !== [] ? $priceSelect : $this->priceManagerSelectColumns();
            $fetchedPrice = collect();
            foreach (array_chunk(array_values($priceIds), 500) as $idChunk) {
                $fetchedPrice = $fetchedPrice->merge(
                    MeliPriceManagerItem::query()->whereNotNull('raw_item')->whereIn('id', $idChunk)->get($columns)
                );
            }
            $priceBySku = $this->indexPriceManagerItemsByVerifiedSku($fetchedPrice, $targetSkusUpper);
        }

        return [
            'pub_by_sku' => $pubBySku,
            'price_by_sku' => $priceBySku,
        ];
    }

    private function isDeadlineOrMemoryExceeded(?float $deadline): bool
    {
        if ($deadline !== null && microtime(true) >= $deadline) {
            return true;
        }

        return $this->isMemoryBudgetExceeded();
    }

    private function isMemoryBudgetExceeded(): bool
    {
        $limitBytes = $this->parseMemoryLimitBytes((string) ini_get('memory_limit'));
        if ($limitBytes === null || $limitBytes <= 0) {
            return false;
        }

        return memory_get_usage(true) >= (int) ($limitBytes * 0.80);
    }

    private function parseMemoryLimitBytes(string $limit): ?int
    {
        $trimmed = trim($limit);
        if ($trimmed === '' || $trimmed === '-1') {
            return null;
        }

        if (! preg_match('/^(\d+)\s*([KMG])?$/i', $trimmed, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = strtoupper((string) ($matches[2] ?? ''));

        return match ($unit) {
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Keyset-paginated streaming scan for secondary variation SKUs embedded in meli_publications.raw.
     * Used ONLY as fallback when `meli_listing_sku_index` is unavailable or empty.
     * Scans primary-key pages (id > lastId) without filesort, retains only verified SKU matches in
     * memory, handles >500 matching rows exhaustively, and reports truncation with explicit reason
     * if page, time, or memory ceilings are reached.
     *
     * @param  list<string>  $select
     * @param  list<string>  $skus
     * @param  list<int>  $excludeIds
     * @param  array<string, true>  $targetSkusUpper
     * @return array{by_sku: array<string, Collection<int, MeliPublication>>, truncated: bool, truncation_reason: ?string, scanned_rows: int, pages_scanned: int, max_rows: int}
     */
    private function scanVariationPublicationsByRaw(
        array $select,
        array $skus,
        array $excludeIds,
        array $targetSkusUpper,
        ?float $deadline = null,
    ): array {
        $pageSize = max(1, $this->variationScanPageSize);
        $maxPages = max(1, $this->variationScanMaxPages);
        $batchSize = max(1, $this->variationScanSkuBatchSize);
        $maxRows = $pageSize * $maxPages;

        if ($skus === []) {
            return ['by_sku' => [], 'truncated' => false, 'truncation_reason' => null, 'scanned_rows' => 0, 'pages_scanned' => 0, 'max_rows' => $maxRows];
        }

        $indexed = [];
        $seenPerSku = [];
        $truncated = false;
        $truncationReason = null;
        $scannedRows = 0;
        $pagesScanned = 0;
        $applyExcludeSql = $excludeIds !== [] && count($excludeIds) <= 500;
        $excludeSet = $applyExcludeSql ? [] : array_fill_keys($excludeIds, true);

        foreach (array_chunk($skus, $batchSize) as $skuBatch) {
            $lastId = 0;
            $batchPages = 0;

            while ($batchPages < $maxPages) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $truncated = true;
                    $truncationReason = 'time_budget_exceeded';
                    break 2;
                }

                if ($this->isMemoryBudgetExceeded()) {
                    $truncated = true;
                    $truncationReason = 'memory_budget_exceeded';
                    break 2;
                }

                $query = MeliPublication::query()
                    ->whereNotNull('raw')
                    ->where('id', '>', $lastId)
                    ->where(function ($nested) use ($skuBatch): void {
                        foreach ($skuBatch as $index => $sku) {
                            $jsonToken = json_encode($sku, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $needle = is_string($jsonToken) ? $jsonToken : '"'.$sku.'"';
                            $pattern = '%'.$this->escapeLike($needle).'%';
                            if ($index === 0) {
                                $nested->where('raw', 'like', $pattern);
                            } else {
                                $nested->orWhere('raw', 'like', $pattern);
                            }
                        }
                    });

                if ($applyExcludeSql) {
                    $query->whereNotIn('id', $excludeIds);
                }

                $rows = $query
                    ->orderBy('id')
                    ->limit($pageSize + 1)
                    ->get($select);

                $batchPages++;
                $pagesScanned++;

                $hasMore = $rows->count() > $pageSize;
                $page = $hasMore ? $rows->slice(0, $pageSize)->values() : $rows;
                $scannedRows += $page->count();

                if ($page->isNotEmpty()) {
                    $filteredPage = $excludeSet === []
                        ? $page
                        : $page->reject(static fn (MeliPublication $pub): bool => isset($excludeSet[(int) $pub->id]));

                    $pageIndexed = $this->indexPublicationsByVerifiedSku($filteredPage, $targetSkusUpper);
                    foreach ($pageIndexed as $skuUpper => $matchedCollection) {
                        if (! isset($indexed[$skuUpper])) {
                            $indexed[$skuUpper] = collect();
                        }
                        foreach ($matchedCollection as $pub) {
                            $pubId = (int) $pub->id;
                            if (isset($seenPerSku[$skuUpper][$pubId])) {
                                continue;
                            }
                            $seenPerSku[$skuUpper][$pubId] = true;
                            $indexed[$skuUpper]->push($pub);
                        }
                    }
                    unset($filteredPage, $pageIndexed);
                }

                $lastRowId = $page->isNotEmpty() ? (int) $page->last()->id : $lastId;
                unset($rows, $page);

                if (! $hasMore || $lastRowId === $lastId) {
                    break;
                }

                $lastId = $lastRowId;

                if ($batchPages >= $maxPages) {
                    $truncated = true;
                    $truncationReason = 'page_limit_reached';
                    break;
                }
            }
        }

        return [
            'by_sku' => $indexed,
            'truncated' => $truncated,
            'truncation_reason' => $truncationReason,
            'scanned_rows' => $scannedRows,
            'pages_scanned' => $pagesScanned,
            'max_rows' => $maxRows,
        ];
    }

    /**
     * Keyset-paginated streaming scan for secondary variation SKUs embedded in
     * meli_price_manager_items.raw_item (fallback when `meli_listing_sku_index` is unavailable).
     *
     * @param  list<string>  $skus
     * @param  list<int>  $excludeIds
     * @param  array<string, true>  $targetSkusUpper
     * @param  list<string>  $select
     * @return array{by_sku: array<string, Collection<int, MeliPriceManagerItem>>, truncated: bool, truncation_reason: ?string, scanned_rows: int, pages_scanned: int, max_rows: int}
     */
    private function scanVariationPriceManagerItemsByRaw(
        array $skus,
        array $excludeIds,
        array $targetSkusUpper,
        array $select = [],
        ?float $deadline = null,
    ): array {
        $pageSize = max(1, $this->variationScanPageSize);
        $maxPages = max(1, $this->variationScanMaxPages);
        $batchSize = max(1, $this->variationScanSkuBatchSize);
        $maxRows = $pageSize * $maxPages;

        if ($skus === []) {
            return ['by_sku' => [], 'truncated' => false, 'truncation_reason' => null, 'scanned_rows' => 0, 'pages_scanned' => 0, 'max_rows' => $maxRows];
        }

        $columns = $select !== [] ? $select : $this->priceManagerSelectColumns();
        $indexed = [];
        $seenPerSku = [];
        $truncated = false;
        $truncationReason = null;
        $scannedRows = 0;
        $pagesScanned = 0;
        $applyExcludeSql = $excludeIds !== [] && count($excludeIds) <= 500;
        $excludeSet = $applyExcludeSql ? [] : array_fill_keys($excludeIds, true);

        foreach (array_chunk($skus, $batchSize) as $skuBatch) {
            $lastId = 0;
            $batchPages = 0;

            while ($batchPages < $maxPages) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $truncated = true;
                    $truncationReason = 'time_budget_exceeded';
                    break 2;
                }

                if ($this->isMemoryBudgetExceeded()) {
                    $truncated = true;
                    $truncationReason = 'memory_budget_exceeded';
                    break 2;
                }

                $query = MeliPriceManagerItem::query()
                    ->whereNotNull('raw_item')
                    ->where('id', '>', $lastId)
                    ->where(function ($nested) use ($skuBatch): void {
                        foreach ($skuBatch as $index => $sku) {
                            $jsonToken = json_encode($sku, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $needle = is_string($jsonToken) ? $jsonToken : '"'.$sku.'"';
                            $pattern = '%'.$this->escapeLike($needle).'%';
                            if ($index === 0) {
                                $nested->where('raw_item', 'like', $pattern);
                            } else {
                                $nested->orWhere('raw_item', 'like', $pattern);
                            }
                        }
                    });

                if ($applyExcludeSql) {
                    $query->whereNotIn('id', $excludeIds);
                }

                $rows = $query
                    ->orderBy('id')
                    ->limit($pageSize + 1)
                    ->get($columns);

                $batchPages++;
                $pagesScanned++;

                $hasMore = $rows->count() > $pageSize;
                $page = $hasMore ? $rows->slice(0, $pageSize)->values() : $rows;
                $scannedRows += $page->count();

                if ($page->isNotEmpty()) {
                    $filteredPage = $excludeSet === []
                        ? $page
                        : $page->reject(static fn (MeliPriceManagerItem $pm): bool => isset($excludeSet[(int) $pm->id]));

                    $pageIndexed = $this->indexPriceManagerItemsByVerifiedSku($filteredPage, $targetSkusUpper);
                    foreach ($pageIndexed as $skuUpper => $matchedCollection) {
                        if (! isset($indexed[$skuUpper])) {
                            $indexed[$skuUpper] = collect();
                        }
                        foreach ($matchedCollection as $pm) {
                            $pmId = (int) $pm->id;
                            if (isset($seenPerSku[$skuUpper][$pmId])) {
                                continue;
                            }
                            $seenPerSku[$skuUpper][$pmId] = true;
                            $indexed[$skuUpper]->push($pm);
                        }
                    }
                    unset($filteredPage, $pageIndexed);
                }

                $lastRowId = $page->isNotEmpty() ? (int) $page->last()->id : $lastId;
                unset($rows, $page);

                if (! $hasMore || $lastRowId === $lastId) {
                    break;
                }

                $lastId = $lastRowId;

                if ($batchPages >= $maxPages) {
                    $truncated = true;
                    $truncationReason = 'page_limit_reached';
                    break;
                }
            }
        }

        return [
            'by_sku' => $indexed,
            'truncated' => $truncated,
            'truncation_reason' => $truncationReason,
            'scanned_rows' => $scannedRows,
            'pages_scanned' => $pagesScanned,
            'max_rows' => $maxRows,
        ];
    }

    /**
     * Merge two SKU-indexed model collections while deduplicating by primary key ID.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  array<string, Collection<int, TModel>>  $base
     * @param  array<string, Collection<int, TModel>>  $extra
     * @return array<string, Collection<int, TModel>>
     */
    private function mergeIndexedCollections(array $base, array $extra): array
    {
        foreach ($extra as $skuUpper => $collection) {
            if (! isset($base[$skuUpper])) {
                $base[$skuUpper] = $collection;
                continue;
            }

            $seenIds = [];
            foreach ($base[$skuUpper] as $model) {
                $seenIds[(int) $model->getKey()] = true;
            }

            foreach ($collection as $model) {
                $id = (int) $model->getKey();
                if (! isset($seenIds[$id])) {
                    $seenIds[$id] = true;
                    $base[$skuUpper]->push($model);
                }
            }
        }

        return $base;
    }

    /**
     * Token-based administrative discovery over meli_publications.
     * Ordered strictly by primary key (`ORDER BY id DESC`) to avoid MariaDB filesort on
     * large JSON/TEXT rows; PHP ranks candidates by status/currency/token hits afterward.
     *
     * @param  list<string>  $select
     * @param  list<string>  $tokens
     * @param  list<int>  $excludeIds
     * @return Collection<int, MeliPublication>
     */
    private function searchPublicationsByTokens(array $select, array $tokens, array $excludeIds): Collection
    {
        if ($tokens === []) {
            return collect();
        }

        $query = MeliPublication::query()
            ->whereNotNull('raw')
            ->where(function ($nested) use ($tokens): void {
                foreach (array_slice($tokens, 0, 5) as $index => $token) {
                    $pattern = '%'.$this->escapeLike($token).'%';
                    if ($index === 0) {
                        $nested->where('raw', 'like', $pattern)
                            ->orWhere('sku', 'like', $pattern)
                            ->orWhere('mlm', 'like', $pattern);
                    } else {
                        $nested->orWhere('raw', 'like', $pattern)
                            ->orWhere('sku', 'like', $pattern)
                            ->orWhere('mlm', 'like', $pattern);
                    }
                }
            });

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query
            ->orderByDesc('id')
            ->limit(60)
            ->get($select);
    }

    /**
     * Token-based administrative discovery over compact `meli_price_manager_items.title`.
     * Ordered strictly by primary key (`ORDER BY id DESC`) to avoid filesort overhead.
     *
     * @param  list<string>  $tokens
     * @param  list<int>  $excludeIds
     * @param  list<string>  $select
     * @return Collection<int, MeliPriceManagerItem>
     */
    private function searchPriceManagerItemsByTokens(array $tokens, array $excludeIds, array $select = []): Collection
    {
        if ($tokens === []) {
            return collect();
        }

        $columns = $select !== [] ? $select : $this->priceManagerSelectColumns();

        $query = MeliPriceManagerItem::query()
            ->whereNotNull('raw_item')
            ->where(function ($nested) use ($tokens): void {
                foreach (array_slice($tokens, 0, 5) as $index => $token) {
                    $pattern = '%'.$this->escapeLike($token).'%';
                    if ($index === 0) {
                        $nested->where('title', 'like', $pattern)
                            ->orWhere('sku', 'like', $pattern)
                            ->orWhere('meli_item_id', 'like', $pattern);
                    } else {
                        $nested->orWhere('title', 'like', $pattern)
                            ->orWhere('sku', 'like', $pattern)
                            ->orWhere('meli_item_id', 'like', $pattern);
                    }
                }
            });

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query
            ->orderByDesc('id')
            ->limit(60)
            ->get($columns);
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

    private function normalizedMlm(mixed $value): ?string
    {
        $trimmed = strtoupper(trim((string) ($value ?? '')));

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return array<string, true> */
    private function normalizedRejectedMlms(PurchaseOrderItem $item): array
    {
        $raw = $item->getAttribute('rejected_mlms');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $set = [];
        foreach ($raw as $entry) {
            $mlm = $this->normalizedMlm($entry);
            if ($mlm !== null) {
                $set[$mlm] = true;
            }
        }

        return $set;
    }

    private function isValidStoredManualPhoto(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));
        if (
            $normalized === ''
            || ! str_starts_with($normalized, PurchaseOrderMeliImageCacheService::MANUAL_DIR.'/')
            || str_contains($normalized, '..')
        ) {
            return false;
        }

        return Storage::disk('local')->exists($normalized);
    }

    /** @param array<string, mixed> $item */
    private function extractListingTitle(array $item, MeliPublication $publication): ?string
    {
        $title = $item['title'] ?? null;
        if (! is_string($title) || trim($title) === '') {
            $raw = is_array($publication->raw) ? $publication->raw : [];
            $title = $raw['title'] ?? null;
        }

        return $this->nullableTrim($title);
    }

    /** @param array<string, mixed> $variation */
    private function extractVariationLabel(array $variation): ?string
    {
        $parts = [];
        foreach ((array) ($variation['attribute_combinations'] ?? []) as $combo) {
            if (! is_array($combo)) {
                continue;
            }
            $name = trim((string) ($combo['name'] ?? $combo['id'] ?? ''));
            $value = trim((string) ($combo['value_name'] ?? $combo['value_id'] ?? ''));
            if ($value === '') {
                continue;
            }
            $parts[] = $name !== '' ? "{$name}: {$value}" : $value;
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        foreach ((array) ($variation['attributes'] ?? []) as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $id = strtoupper(trim((string) ($attr['id'] ?? '')));
            if (in_array($id, ['SELLER_SKU', 'SKU'], true)) {
                continue;
            }
            $name = trim((string) ($attr['name'] ?? $id));
            $value = trim((string) ($attr['value_name'] ?? $attr['value_id'] ?? ''));
            if ($value !== '') {
                $parts[] = $name !== '' ? "{$name}: {$value}" : $value;
            }
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /** @return list<string> */
    private function extractMlmTokens(string $text): array
    {
        if ($text === '' || ! preg_match_all('/\bMLM-?\d+\b/i', $text, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $m): string => strtoupper(str_replace('-', '', $m)),
            $matches[0]
        )));
    }

    /** @return list<string> */
    private function extractSearchTokens(string $text): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($text));
        if (! is_string($clean) || $clean === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $clean) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);
            if (mb_strlen($trimmed) < 2) {
                continue;
            }
            $lower = mb_strtolower($trimmed);
            if (in_array($lower, self::TITLE_STOPWORDS, true)) {
                continue;
            }
            if (preg_match('/^MLM\d+$/i', $trimmed) === 1) {
                continue;
            }
            $tokens[$lower] = $trimmed;
        }

        return array_values(array_slice($tokens, 0, 8));
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

    /**
     * @param  list<array<string, mixed>>  $ambiguousMatches
     * @return array<string, mixed>
     */
    private function emptyResolution(
        PurchaseOrderItem $item,
        string $sku,
        string $reason,
        string $auditCategory = self::AUDIT_PENDING_NO_CANDIDATE,
        ?string $source = null,
        ?string $photoSourceMode = null,
        ?string $photoNotes = null,
        bool $ambiguous = false,
        array $ambiguousMatches = [],
    ): array {
        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => null,
            'manual_path' => null,
            'source' => $source,
            'mlm' => null,
            'variation_id' => null,
            'variation_label' => null,
            'listing_title' => null,
            'meli_account_id' => null,
            'listing_status' => null,
            'photo_source_mode' => $photoSourceMode ?? $item->getAttribute('photo_source_mode'),
            'photo_notes' => $photoNotes ?? $this->nullableTrim($item->getAttribute('photo_notes')),
            'reason' => $reason,
            'audit_category' => $auditCategory,
            'ambiguous' => $ambiguous,
            'ambiguous_matches' => $ambiguousMatches,
            'variation_index_used' => false,
            'variation_scan_truncated' => false,
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
        ?string $listingTitle = null,
        ?string $variationLabel = null,
        string $reason = 'resolved',
        ?string $auditCategory = null,
    ): array {
        $resolvedAuditCategory = $auditCategory
            ?? ($variationId !== null ? self::AUDIT_RESOLVED_VARIATION : self::AUDIT_RESOLVED_SKU_LINK);

        return [
            'item_id' => (int) $item->id,
            'inventory_product_id' => $item->inventory_product_id ? (int) $item->inventory_product_id : null,
            'sku' => $sku,
            'url' => $url,
            'manual_path' => null,
            'source' => $source,
            'mlm' => $mlm,
            'variation_id' => $variationId,
            'variation_label' => $variationLabel,
            'listing_title' => $listingTitle,
            'meli_account_id' => $meliAccountId,
            'listing_status' => $listingStatus,
            'photo_source_mode' => $item->getAttribute('photo_source_mode'),
            'photo_notes' => $this->nullableTrim($item->getAttribute('photo_notes')),
            'reason' => $reason,
            'audit_category' => $resolvedAuditCategory,
            'ambiguous' => $ambiguous,
            'ambiguous_matches' => $ambiguousMatches,
            'variation_index_used' => false,
            'variation_scan_truncated' => false,
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
