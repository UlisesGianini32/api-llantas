<?php

namespace App\Services\Inventory\Igora;

use App\Models\InventoryChannelLink;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliSharedStockMember;
use App\Services\InventoryKitStockService;
use App\Services\InventoryStockService;
use App\Services\MercadoLibre\PriceManager\MeliBeautyKitDetector;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IgoraGroupingAssistantService
{
    public const LINE_ROYAL = 'IGORA_ROYAL';

    public const LINE_VIBRANCE = 'IGORA_VIBRANCE';

    public const LINE_COLOR10 = 'IGORA_COLOR10';

    public const LINE_ZERO_AMM = 'IGORA_ZERO_AMM';

    public const LINE_OTHER = 'IGORA_OTRA_O_AMBIGUA';

    public const PRES_SINGLE_DYE = 'SINGLE_DYE';

    public const PRES_DYE_WITH_PEROXIDE = 'DYE_WITH_PEROXIDE';

    public const PRES_MULTIPACK = 'MULTIPACK';

    public const PRES_STANDALONE_PEROXIDE = 'STANDALONE_PEROXIDE';

    public const PRES_UNKNOWN = 'UNKNOWN_PRESENTATION';

    public const CONFIDENCE_HIGH = 'HIGH';

    public const CONFIDENCE_MEDIUM = 'MEDIUM';

    public const CONFIDENCE_LOW = 'LOW';

    /** @var array<string, string> */
    public const LINE_LABELS = [
        self::LINE_ROYAL => 'Igora Royal',
        self::LINE_VIBRANCE => 'Igora Vibrance',
        self::LINE_COLOR10 => 'Igora Color 10',
        self::LINE_ZERO_AMM => 'Igora Zero Amm',
        self::LINE_OTHER => 'Otra / Línea ambigua',
    ];

    /** @var array<string, string> */
    public const PRESENTATION_LABELS = [
        self::PRES_SINGLE_DYE => 'Tinte individual',
        self::PRES_DYE_WITH_PEROXIDE => 'Tinte con peróxido',
        self::PRES_MULTIPACK => 'Multipack',
        self::PRES_STANDALONE_PEROXIDE => 'Peróxido independiente',
        self::PRES_UNKNOWN => 'Presentación desconocida',
    ];

    public function __construct(
        private readonly IgoraCatalogDiscoveryService $discovery,
        private readonly InventoryStockService $stockService,
        private readonly InventoryKitStockService $kitStockService,
        private readonly MeliBeautyKitDetector $kitDetector,
    ) {}

    /**
     * Genera la vista previa de agrupación en modo estrictamente solo lectura.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     groups: list<array<string, mixed>>,
     *     counts: array<string, int>,
     *     facets: array<string, list<string|array<string, mixed>>>,
     *     filters: array<string, mixed>,
     *     pagination: ?array<string, mixed>,
     *     read_only: bool
     * }
     */
    public function preview(array $filters = []): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);

        // Descubrir sobre el universo completo de la cuenta (o todas) para detectar
        // conflictos cruzados (SKU duplicados, user_product_id compartido, etc.)
        // antes de aplicar filtros de visualización.
        $discovered = $this->discovery->discover([
            'account_key' => $normalizedFilters['account_key'],
        ]);

        if ($discovered->isEmpty()) {
            return [
                'rows' => [],
                'groups' => [],
                'counts' => $this->emptyCounts(),
                'facets' => [
                    'lines' => array_keys(self::LINE_LABELS),
                    'tones' => [],
                    'presentations' => array_keys(self::PRESENTATION_LABELS),
                    'statuses' => [],
                ],
                'filters' => $normalizedFilters,
                'pagination' => $this->buildPaginationPayload(collect(), $normalizedFilters),
                'read_only' => true,
            ];
        }

        $context = $this->loadReadOnlyInventoryContext($discovered);

        $analyzed = $discovered->map(
            fn (array $row): array => $this->analyzeRow($row, $context)
        )->values();

        $analyzed = $this->applyCrossRowDiagnostics($analyzed);

        $sorted = $analyzed->sort(fn (array $a, array $b): int => $this->compareRows($a, $b))->values();

        $facets = [
            'lines' => array_keys(self::LINE_LABELS),
            'tones' => $sorted->pluck('normalized_tone')->filter()->unique()->values()->all(),
            'presentations' => array_keys(self::PRESENTATION_LABELS),
            'statuses' => $sorted->pluck('status')->filter()->unique()->sort()->values()->all(),
        ];

        $filtered = $sorted->filter(fn (array $row): bool => $this->matchesFilters($row, $normalizedFilters))->values();
        $groups = $this->buildGroupSummaries($filtered);
        $counts = $this->computeCounts($filtered, $groups);
        $pagination = $this->buildPaginationPayload($filtered, $normalizedFilters);
        $pagedRows = $pagination !== null
            ? $filtered->slice(($pagination['current_page'] - 1) * $pagination['per_page'], $pagination['per_page'])->values()->all()
            : $filtered->all();

        return [
            'rows' => $pagedRows,
            'groups' => $groups,
            'counts' => $counts,
            'facets' => $facets,
            'filters' => $normalizedFilters,
            'pagination' => $pagination,
            'read_only' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $perPageRaw = $filters['per_page'] ?? null;
        $perPage = is_numeric($perPageRaw) && (int) $perPageRaw > 0
            ? min(250, max(1, (int) $perPageRaw))
            : null;
        $page = is_numeric($filters['page'] ?? null) ? max(1, (int) $filters['page']) : 1;

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'line' => strtoupper(trim((string) ($filters['line'] ?? ''))),
            'tone' => $this->normalizeToneString((string) ($filters['tone'] ?? '')) ?? trim((string) ($filters['tone'] ?? '')),
            'presentation' => strtoupper(trim((string) ($filters['presentation'] ?? ''))),
            'status' => strtolower(trim((string) ($filters['status'] ?? ''))),
            'account_key' => trim((string) ($filters['account_key'] ?? '')),
            'ambiguous_only' => filter_var($filters['ambiguous_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'per_page' => $perPage,
            'page' => $page,
            'pagination_path' => isset($filters['pagination_path']) ? (string) $filters['pagination_path'] : null,
        ];
    }

    /**
     * Construye metadatos de paginación solo cuando se solicita `per_page` (vista web).
     *
     * @param  Collection<int, array<string, mixed>>  $filtered
     * @param  array<string, mixed>  $filters
     * @return ?array<string, mixed>
     */
    private function buildPaginationPayload(Collection $filtered, array $filters): ?array
    {
        $perPage = $filters['per_page'];
        if ($perPage === null) {
            return null;
        }

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($filters['page'], $lastPage);
        $slice = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        $queryParams = array_filter([
            'search' => $filters['search'] !== '' ? $filters['search'] : null,
            'line' => $filters['line'] !== '' ? $filters['line'] : null,
            'tone' => $filters['tone'] !== '' ? $filters['tone'] : null,
            'presentation' => $filters['presentation'] !== '' ? $filters['presentation'] : null,
            'status' => $filters['status'] !== '' ? $filters['status'] : null,
            'account_key' => $filters['account_key'] !== '' ? $filters['account_key'] : null,
            'ambiguous_only' => $filters['ambiguous_only'] ? 1 : null,
            'per_page' => $perPage,
        ], fn (mixed $v): bool => $v !== null);

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => $filters['pagination_path'] ?: '/almacen/canales/mercado-libre/igora-royal',
                'pageName' => 'page',
            ]
        );
        $paginator->appends($queryParams);

        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem() ?? 0,
            'to' => $paginator->lastItem() ?? 0,
            'links' => $paginator->linkCollection()->toArray(),
        ];
    }

    /**
     * Precarga en memoria y en modo lectura el catálogo maestro Inventory*,
     * los vínculos actuales de canal y los grupos legacy.
     *
     * @param  Collection<int, array<string, mixed>>  $discovered
     * @return array<string, mixed>
     */
    private function loadReadOnlyInventoryContext(Collection $discovered): array
    {
        $products = Schema::hasTable('inventory_products')
            ? InventoryProduct::query()
                ->with(['kitComponents.component:id,sku,barcode,barcode_secondary,name,brand,product_type,is_active'])
                ->get()
            : collect();

        $simpleProducts = $products->filter(fn (InventoryProduct $p): bool => $p->isSimple())->values();
        $kitProducts = $products->filter(fn (InventoryProduct $p): bool => $p->isKit())->values();

        $physicalByProduct = [];
        $availableByProduct = [];

        if ($simpleProducts->isNotEmpty()) {
            $simpleIds = $simpleProducts->pluck('id')->all();

            // Agregación en bloque equivalente a InventoryStockService::physicalStock() / availableStock()
            // evitando consultas N+1 cuando el catálogo tiene cientos de SKUs.
            $movementsByProduct = Schema::hasTable('inventory_movements')
                ? InventoryMovement::query()
                    ->whereIn('inventory_product_id', $simpleIds)
                    ->select('inventory_product_id')
                    ->selectRaw('SUM(quantity) AS quantity')
                    ->groupBy('inventory_product_id')
                    ->pluck('quantity', 'inventory_product_id')
                : collect();

            $reservedByProduct = Schema::hasTable('inventory_reservations')
                ? InventoryReservation::query()
                    ->active()
                    ->whereIn('inventory_product_id', $simpleIds)
                    ->select('inventory_product_id')
                    ->selectRaw('SUM(quantity) AS quantity')
                    ->groupBy('inventory_product_id')
                    ->pluck('quantity', 'inventory_product_id')
                : collect();

            foreach ($simpleProducts as $product) {
                $physical = (int) $movementsByProduct->get($product->id, 0);
                $reserved = (int) $reservedByProduct->get($product->id, 0);
                $physicalByProduct[$product->id] = $physical;
                $availableByProduct[$product->id] = $physical - $reserved;
            }
        }

        if ($kitProducts->isNotEmpty()) {
            $kitStocks = $this->kitStockService->stocksForKits($kitProducts);
            foreach ($kitProducts as $kit) {
                $stock = $kitStocks->get($kit->id, ['physical_stock' => 0, 'available_stock' => 0]);
                $physicalByProduct[$kit->id] = (int) $stock['physical_stock'];
                $availableByProduct[$kit->id] = (int) $stock['available_stock'];
            }
        }

        $productsByCode = [];
        foreach ($products as $product) {
            foreach ([$product->sku, $product->barcode, $product->barcode_secondary] as $code) {
                $clean = $this->normalizeCode((string) ($code ?? ''));
                if ($clean === '') {
                    continue;
                }
                $productsByCode[$clean] ??= collect();
                $productsByCode[$clean]->push($product);

                $trimmed = ltrim($clean, '0');
                if ($trimmed !== '' && $trimmed !== $clean) {
                    $productsByCode[$trimmed] ??= collect();
                    $productsByCode[$trimmed]->push($product);
                }
            }
        }
        foreach ($productsByCode as $code => $bucket) {
            $productsByCode[$code] = $bucket->unique('id')->values();
        }

        $channelLinksByIdentity = collect();
        if (Schema::hasTable('inventory_channel_links')) {
            $mlms = $discovered->pluck('mlm')->filter()->unique()->values()->all();
            if ($mlms !== []) {
                $channelLinksByIdentity = InventoryChannelLink::query()
                    ->with('product:id,sku,name,brand,product_type,is_active')
                    ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                    ->whereIn('external_listing_id', $mlms)
                    ->get()
                    ->keyBy(fn (InventoryChannelLink $link): string => $this->linkIdentityTuple(
                        (string) ($link->account_key ?? ''),
                        (string) ($link->external_listing_id ?? ''),
                        $link->external_variant_id !== null ? (string) $link->external_variant_id : null,
                    ));
            }
        }

        $sharedMembersByIdentity = collect();
        if (Schema::hasTable('meli_shared_stock_members')) {
            $mlms = $discovered->pluck('mlm')->filter()->unique()->values()->all();
            if ($mlms !== []) {
                $sharedMembersByIdentity = MeliSharedStockMember::query()
                    ->with('group:id,stock,is_enabled,master_mlm')
                    ->where('is_active', true)
                    ->whereIn('mlm', $mlms)
                    ->get()
                    ->groupBy(fn (MeliSharedStockMember $member): string => $this->linkIdentityTuple(
                        (string) $member->meli_account_id,
                        strtoupper((string) $member->mlm),
                        $member->variation_id !== null ? (string) $member->variation_id : null,
                    ));
            }
        }

        $pmItemsById = collect();
        $pmIds = $discovered->pluck('meli_price_manager_item_id')->filter()->unique()->values()->all();
        if ($pmIds !== [] && Schema::hasTable('meli_price_manager_items')) {
            $pmItemsById = MeliPriceManagerItem::query()->whereIn('id', $pmIds)->get()->keyBy('id');
        }

        $simpleDyes = $simpleProducts->filter(fn (InventoryProduct $p): bool => ! $p->isBundleOrKit() && ! $this->looksLikePeroxideText($p->name.' '.$p->sku))->values();
        $simplePeroxides = $simpleProducts->filter(fn (InventoryProduct $p): bool => $this->looksLikePeroxideText($p->name.' '.$p->sku))->values();

        return [
            'products' => $products,
            'simple_dyes' => $simpleDyes,
            'simple_peroxides' => $simplePeroxides,
            'kit_products' => $kitProducts,
            'physical_by_product' => $physicalByProduct,
            'available_by_product' => $availableByProduct,
            'products_by_code' => $productsByCode,
            'channel_links' => $channelLinksByIdentity,
            'shared_members' => $sharedMembersByIdentity,
            'pm_items_by_id' => $pmItemsById,
        ];
    }

    /**
     * Analiza una fila descubierta: línea, tono, presentación, componentes candidatos y coincidencia Inventory.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function analyzeRow(array $row, array $context): array
    {
        $conflicts = [];
        $notes = [];

        $lineResult = $this->detectLine($row);
        $line = $lineResult['line'];
        $conflicts = array_merge($conflicts, $lineResult['conflicts']);

        $toneResult = $this->extractTone($row);
        $normalizedTone = $toneResult['tone'];
        $conflicts = array_merge($conflicts, $toneResult['conflicts']);

        /** @var MeliPriceManagerItem|null $pmItem */
        $pmItem = $row['meli_price_manager_item_id']
            ? $context['pm_items_by_id']->get($row['meli_price_manager_item_id'])
            : null;

        $presentationResult = $this->classifyPresentation($row, $normalizedTone, $pmItem);
        $presentation = $presentationResult['presentation'];
        $peroxideInfo = $presentationResult['peroxide'];
        $dyeQuantity = $presentationResult['dye_quantity'];
        $requiresHumanReview = $presentationResult['requires_human_review'];
        $conflicts = array_merge($conflicts, $presentationResult['conflicts']);

        if (in_array($presentation, [self::PRES_STANDALONE_PEROXIDE], true)) {
            // Un peróxido independiente no requiere tono de tinte; removemos TONE_NOT_DETECTED si se hubiera marcado.
            $conflicts = array_values(array_diff($conflicts, ['TONE_NOT_DETECTED']));
            $normalizedTone = null;
        } elseif ($normalizedTone === null && ! in_array('MULTIPLE_TONES_IN_SINGLE_LISTING', $conflicts, true)) {
            $conflicts[] = 'TONE_NOT_DETECTED';
            $requiresHumanReview = true;
        }

        if ($row['sku'] === '') {
            $conflicts[] = 'MISSING_SKU';
            $requiresHumanReview = true;
        }
        $skuGtinConflict = $this->detectSkuGtinInconsistency((string) ($row['sku'] ?? ''), $row['ean'] ?? null);
        if ($skuGtinConflict !== null) {
            $conflicts[] = $skuGtinConflict;
            $requiresHumanReview = true;
        }
        if ($row['is_full']) {
            $conflicts[] = 'FULFILLMENT_MANAGED_BY_MELI';
        }
        if ($row['is_paused']) {
            $conflicts[] = 'PUBLICATION_PAUSED';
        }
        if ($row['is_moderated_or_denounced']) {
            $conflicts[] = 'PUBLICATION_MODERATED_OR_DENOUNCED';
            $requiresHumanReview = true;
        }
        if ($row['snapshot_stale']) {
            $conflicts[] = 'SNAPSHOT_STALE';
        }
        if ($row['data_incomplete']) {
            $conflicts[] = 'DATA_INCOMPLETE';
            $requiresHumanReview = true;
        }

        $identityTuple = $this->linkIdentityTuple(
            (string) ($row['account_key'] ?? ''),
            (string) $row['mlm'],
            $row['variation_id'],
        );
        /** @var InventoryChannelLink|null $existingLink */
        $existingLink = $context['channel_links']->get($identityTuple);

        $legacyMembers = $context['shared_members']->get($identityTuple, collect());
        $hasActiveLegacyShared = $legacyMembers->contains(
            fn (MeliSharedStockMember $member): bool => (bool) ($member->group?->is_enabled ?? false)
        );
        if ($hasActiveLegacyShared) {
            $conflicts[] = 'LEGACY_SHARED_STOCK_ACTIVE';
            $requiresHumanReview = true;
        }

        $matchResult = $this->resolveInventoryCandidate(
            $row,
            $line,
            $normalizedTone,
            $presentation,
            $peroxideInfo,
            $dyeQuantity,
            $existingLink,
            $context,
            skuIsSuspicious: $skuGtinConflict !== null,
        );

        $conflicts = array_merge($conflicts, $matchResult['conflicts']);
        $requiresHumanReview = $requiresHumanReview || $matchResult['requires_human_review'];

        $groupInfo = $this->buildProposedGroupKey(
            $row,
            $line,
            $normalizedTone,
            $presentation,
            $peroxideInfo,
            $dyeQuantity,
        );

        $conflicts = array_values(array_unique($conflicts));

        $confidence = $this->computeConfidence(
            $line,
            $normalizedTone,
            $presentation,
            $requiresHumanReview,
            $conflicts,
            $matchResult,
        );

        $recommendedAction = $this->determineRecommendedAction(
            $row,
            $presentation,
            $requiresHumanReview,
            $conflicts,
            $matchResult,
            $existingLink,
        );

        $matchedProduct = $matchResult['matched_product'];
        $localPhysical = $matchedProduct ? ($context['physical_by_product'][$matchedProduct->id] ?? 0) : null;
        $localAvailable = $matchedProduct ? ($context['available_by_product'][$matchedProduct->id] ?? 0) : null;

        return [
            ...$row,
            'line' => $line,
            'line_label' => self::LINE_LABELS[$line] ?? $line,
            'normalized_tone' => $normalizedTone,
            'tone_source' => $toneResult['source'],
            'detected_tones_raw' => $toneResult['all_detected'],
            'presentation_type' => $presentation,
            'presentation_label' => self::PRESENTATION_LABELS[$presentation] ?? $presentation,
            'dye_quantity' => $dyeQuantity,
            'peroxide_info' => $peroxideInfo,
            'candidate_components' => $matchResult['candidate_components'],
            'proposed_group_key' => $groupInfo['key'],
            'proposed_group_label' => $groupInfo['label'],
            'proposed_match_type' => $matchResult['match_type'],
            'proposed_match_label' => $matchResult['match_label'],
            'proposed_inventory_product_id' => $matchedProduct?->id,
            'proposed_inventory_product_sku' => $matchedProduct?->sku,
            'proposed_inventory_product_name' => $matchedProduct?->name,
            'proposed_inventory_product_type' => $matchedProduct?->product_type,
            'local_physical_stock' => $localPhysical,
            'local_available_stock' => $localAvailable,
            'local_stock_source' => $matchedProduct ? 'inventory_ledger' : 'sin_producto_en_inventory',
            'existing_channel_link_id' => $existingLink?->id,
            'existing_channel_link_product_id' => $existingLink?->inventory_product_id,
            'existing_channel_link_product_sku' => $existingLink?->product?->sku,
            'existing_channel_link_stock_sync' => $existingLink ? (bool) $existingLink->stock_sync_enabled : null,
            'existing_channel_link_order_reservation' => $existingLink ? (bool) $existingLink->order_reservation_enabled : null,
            'has_active_legacy_shared_stock' => $hasActiveLegacyShared,
            'requires_human_review' => $requiresHumanReview,
            'conflicts' => $conflicts,
            'notes' => $notes,
            'confidence' => $confidence,
            'recommended_action' => $recommendedAction,
            'is_ambiguous_or_conflicted' => $requiresHumanReview || $this->hasBlockingOrAmbiguousConflict($conflicts),
        ];
    }

    /**
     * Detecta estrictamente la línea del producto evitando mezclar Igora Royal con Vibrance u otras.
     *
     * @param  array<string, mixed>  $row
     * @return array{line: string, conflicts: list<string>}
     */
    private function detectLine(array $row): array
    {
        $attrs = (array) ($row['item_attributes'] ?? []);
        $varAttrs = (array) ($row['variation_attributes'] ?? []);
        $lineAttr = $this->attributeValue($attrs, ['LINE', 'PRODUCT_LINE', 'MODEL'])
            .' '.$this->attributeValue($varAttrs, ['LINE', 'PRODUCT_LINE']);

        $text = Str::ascii(mb_strtolower(implode(' ', array_filter([
            (string) ($row['title'] ?? ''),
            (string) ($row['variation_label'] ?? ''),
            $lineAttr,
            (string) ($row['meli_brand'] ?? ''),
            (string) ($row['meli_brand_group'] ?? ''),
        ])), 'UTF-8'));

        $hasRoyal = preg_match('/\b(?:igora?\s+)?royal\b|\b(?:highlifts|absolutes|fashion\s+lights|metallics|silver\s*whites)\b/', $text) === 1;
        $hasVibrance = preg_match('/\bvibrance\b/', $text) === 1;
        $hasColor10 = preg_match('/\bcolor\s*10\b/', $text) === 1;
        $hasZeroAmm = preg_match('/\bzero\s*amm\b/', $text) === 1;

        $matchedLines = array_filter([
            $hasRoyal ? self::LINE_ROYAL : null,
            $hasVibrance ? self::LINE_VIBRANCE : null,
            $hasColor10 ? self::LINE_COLOR10 : null,
            $hasZeroAmm ? self::LINE_ZERO_AMM : null,
        ]);

        if (count($matchedLines) > 1) {
            return [
                'line' => self::LINE_OTHER,
                'conflicts' => [$hasRoyal && $hasVibrance ? 'LINE_CONFLICT_ROYAL_VS_VIBRANCE' : 'LINE_AMBIGUOUS'],
            ];
        }

        if ($hasVibrance) {
            return ['line' => self::LINE_VIBRANCE, 'conflicts' => []];
        }
        if ($hasColor10) {
            return ['line' => self::LINE_COLOR10, 'conflicts' => []];
        }
        if ($hasZeroAmm) {
            return ['line' => self::LINE_ZERO_AMM, 'conflicts' => []];
        }
        if ($hasRoyal) {
            return ['line' => self::LINE_ROYAL, 'conflicts' => []];
        }

        return ['line' => self::LINE_OTHER, 'conflicts' => ['LINE_AMBIGUOUS']];
    }

    /**
     * Extrae y normaliza el tono (ej. 0-22, 1-0, 7-77, 9.5-1) sin confundir EANs,
     * volúmenes, concentraciones o MLMs con tonos.
     *
     * @param  array<string, mixed>  $row
     * @return array{tone: ?string, source: ?string, all_detected: list<string>, conflicts: list<string>}
     */
    private function extractTone(array $row): array
    {
        $conflicts = [];

        $attrToneRaw = $this->attributeValue((array) ($row['variation_attributes'] ?? []), [
            'HAIR_TONE', 'TONO', 'TONE', 'SHADE', 'COLOR', 'MAIN_COLOR', 'COLOR_NAME', 'TONE_NAME', 'FABRIC_DESIGN',
        ]);
        if ($attrToneRaw === '') {
            $attrToneRaw = $this->attributeValue((array) ($row['item_attributes'] ?? []), [
                'HAIR_TONE', 'TONO', 'TONE', 'SHADE', 'COLOR', 'MAIN_COLOR', 'COLOR_NAME', 'TONE_NAME',
            ]);
        }

        $attrTones = $this->extractTonesFromText($attrToneRaw, (string) ($row['sku'] ?? ''), (string) ($row['ean'] ?? ''));
        $titleTones = $this->extractTonesFromText((string) ($row['title'] ?? ''), (string) ($row['sku'] ?? ''), (string) ($row['ean'] ?? ''));

        if (! $row['has_variations'] && count($titleTones) > 1) {
            $conflicts[] = 'MULTIPLE_TONES_IN_SINGLE_LISTING';

            return [
                'tone' => null,
                'source' => 'ambiguous_title',
                'all_detected' => $titleTones,
                'conflicts' => $conflicts,
            ];
        }

        if ($attrTones !== [] && $titleTones !== []) {
            $attrTone = $attrTones[0];
            $titleTone = $titleTones[0];
            if ($attrTone !== $titleTone && ! $row['has_variations']) {
                $conflicts[] = 'TONE_MISMATCH_ATTRIBUTE_VS_TITLE';

                return [
                    'tone' => $attrTone,
                    'source' => 'attribute_conflict_with_title',
                    'all_detected' => array_values(array_unique([...$attrTones, ...$titleTones])),
                    'conflicts' => $conflicts,
                ];
            }

            return [
                'tone' => $attrTone,
                'source' => 'attribute',
                'all_detected' => array_values(array_unique([...$attrTones, ...$titleTones])),
                'conflicts' => $conflicts,
            ];
        }

        if ($attrTones !== []) {
            if (count($attrTones) > 1) {
                $conflicts[] = 'MULTIPLE_TONES_IN_ATTRIBUTE';

                return [
                    'tone' => null,
                    'source' => 'ambiguous_attribute',
                    'all_detected' => $attrTones,
                    'conflicts' => $conflicts,
                ];
            }

            return [
                'tone' => $attrTones[0],
                'source' => 'attribute',
                'all_detected' => $attrTones,
                'conflicts' => $conflicts,
            ];
        }

        if ($titleTones !== []) {
            return [
                'tone' => $titleTones[0],
                'source' => 'title',
                'all_detected' => $titleTones,
                'conflicts' => $conflicts,
            ];
        }

        return [
            'tone' => null,
            'source' => null,
            'all_detected' => [],
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractTonesFromText(string $text, string $sku, ?string $ean): array
    {
        $clean = trim($text);
        if ($clean === '') {
            return [];
        }

        // Remover explícitamente SKU, EAN y códigos largos antes de buscar tonos.
        foreach (array_filter([$sku, (string) $ean]) as $code) {
            $code = trim($code);
            if ($code !== '') {
                $clean = str_ireplace($code, ' ', $clean);
            }
        }

        // Remover MLMs, códigos numéricos largos (5+ dígitos), volúmenes, porcentajes y rangos de volúmenes
        // evitando recortar el reflejo de un tono seguido de palabra de empaque (ej. "7/77 Tubo", "0-22 Unidad").
        $clean = preg_replace([
            '/\bMLM[U]?\d+\b/iu',
            '/\b\d{5,}\b/u',
            '/(?<![-\/\d.,])\b\d+(?:[.,]\d+)?\s*(?:ml|g|gr|oz|lt|l|vol(?:umenes|úmenes)?|v|pzas?|piezas?|tubos?|unidades?|uds?|dias?)\b/iu',
            '/(?<!\d)\d+(?:[.,]\d+)?\s*%(?!\d)/u',
            '/\b(?:10|20|30|40)\s*[-\/]\s*(?:10|20|30|40)\s*(?:vol|v)\b/iu',
        ], ' ', $clean) ?? $clean;

        $found = [];

        // Tonos numéricos oficiales Igora: base 0..12 o 9.5 + '-' o '/' + reflejo 1..3 dígitos.
        if (preg_match_all('/(?<![\dA-Za-z.,])(0|1|2|3|4|5|6|7|8|9\.5|9|10|11|12)\s*[-\/]\s*(\d{1,3})(?![\dA-Za-z])/u', $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $normalized = $this->normalizeToneString($m[1].'-'.$m[2]);
                if ($normalized !== null) {
                    $found[$normalized] = true;
                }
            }
        }

        // Tonos especiales con prefijo de letra:
        // - Fashion Lights: L-00, L-44, L-57, L-77, L-88, L-89, L-99, etc. (L- + 2 dígitos)
        // - Extracts / Specialities: E-0, E-1, E-111, S-00, D-0
        if (preg_match_all('/(?<![\dA-Za-z])(L)\s*[-\/]\s*(\d{2})(?![\dA-Za-z])/iu', $clean, $fashionMatches, PREG_SET_ORDER)) {
            foreach ($fashionMatches as $m) {
                $normalized = 'L-'.$m[2];
                $found[$normalized] = true;
            }
        }
        if (preg_match_all('/(?<![\dA-Za-z])(E|S|D)\s*[-\/]\s*(0|00|1|111)(?![\dA-Za-z])/iu', $clean, $letterMatches, PREG_SET_ORDER)) {
            foreach ($letterMatches as $m) {
                $normalized = strtoupper($m[1]).'-'.$m[2];
                $found[$normalized] = true;
            }
        }

        return array_keys($found);
    }

    private function normalizeToneString(string $raw): ?string
    {
        $raw = str_replace('/', '-', trim($raw));
        if (preg_match('/^(0|1|2|3|4|5|6|7|8|9\.5|9|10|11|12)-(\d{1,3})$/', $raw, $m) === 1) {
            return $m[1].'-'.$m[2];
        }
        if (preg_match('/^L-(\d{2})$/i', $raw, $m) === 1) {
            return 'L-'.$m[1];
        }
        if (preg_match('/^(E|S|D)-(0|00|1|111)$/i', $raw, $m) === 1) {
            return strtoupper($m[1]).'-'.$m[2];
        }

        return null;
    }

    /**
     * Clasifica la presentación y extrae señales de peróxido/multipack sin inferir composiciones ambiguas.
     *
     * @param  array<string, mixed>  $row
     * @return array{
     *     presentation: string,
     *     peroxide: array<string, mixed>,
     *     dye_quantity: ?int,
     *     requires_human_review: bool,
     *     conflicts: list<string>
     * }
     */
    private function classifyPresentation(array $row, ?string $normalizedTone, ?MeliPriceManagerItem $pmItem): array
    {
        $conflicts = [];
        $text = Str::ascii(mb_strtolower(implode(' ', array_filter([
            (string) ($row['title'] ?? ''),
            (string) ($row['variation_label'] ?? ''),
            (string) ($row['description'] ?? ''),
        ])), 'UTF-8'));

        $peroxideNoun = '(?:peroxido|oxidante|revelador|activador|locion\s+activadora|developer|agua\s+oxigenada)';

        // 1) Señales explícitas de exclusión o venta como tinte solo
        $hasPeroxideExclusion = preg_match(
            '/\bsin\s+(?:el\s+|la\s+)?'.$peroxideNoun.'\b'
            .'|\b(?:solo\s+tinte|tinte\s+solo|tinte\s+individual)\b'
            .'|\bno\s+(?:incluye|trae|contiene)\s+(?:el\s+|la\s+)?'.$peroxideNoun.'\b'
            .'|\b(?:el\s+|la\s+)?'.$peroxideNoun.'\s+no\s+(?:esta\s+)?incluido\b'
            .'|\b(?:se\s+vende|vendido)\s+por\s+separado\b/u',
            $text
        ) === 1;

        // 2) Lenguaje de instrucciones, mezcla o compatibilidad (NO demuestra inclusión física)
        $nonInclusionPatterns = [
            '/\b(?:para\s+)?(?:mezclar|usar|utilizar|aplicar|combinar|compatible|recomendado|requiere|necesita|para\s+uso)\s+(?:con\s+)?(?:el\s+|la\s+|un\s+|una\s+)?'.$peroxideNoun.'(?:\s+(?:de|al))?(?:\s+(?:(?:10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)|(?:3|6|9|12)\s*%(?!\d)))?/u',
            '/\b(?:para\s+)?(?:mezclar|usar|utilizar|aplicar|combinar|compatible|recomendado)\s+(?:con\s+)?(?:(?:10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)|(?:3|6|9|12)\s*%(?!\d))/u',
            '/\bno\s+(?:incluye|trae|contiene)\s+(?:el\s+|la\s+)?'.$peroxideNoun.'\b/u',
            '/\b(?:el\s+|la\s+)?'.$peroxideNoun.'\s+no\s+(?:esta\s+)?incluido\b/u',
            '/\b(?:el\s+|la\s+)?'.$peroxideNoun.'\s+(?:se\s+vende|vendido)\s+por\s+separado\b/u',
            '/\bsin\s+(?:el\s+|la\s+)?'.$peroxideNoun.'\b/u',
        ];
        $textForInclusion = preg_replace($nonInclusionPatterns, ' ', $text) ?? $text;

        $hasAnyPeroxideWord = preg_match('/\b(?:'.$peroxideNoun.'|c\/\s*perox)\b/u', $text) === 1;
        $hasUnexplainedPeroxideWord = preg_match('/\b(?:'.$peroxideNoun.'|c\/\s*perox)\b/u', $textForInclusion) === 1;
        $hasTextConcentration = preg_match('/\b(?:10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)\b/u', $text) === 1
            || preg_match('/(?<!\d)(?:3|6|9|12)\s*%(?!\d)/u', $text) === 1;
        $hasUnexplainedTextConcentration = preg_match('/\b(?:10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)\b/u', $textForInclusion) === 1
            || preg_match('/(?<!\d)(?:3|6|9|12)\s*%(?!\d)/u', $textForInclusion) === 1;

        // 3) Evidencia positiva inequívoca de inclusión física de peróxido en texto
        $hasPositivePeroxideInclusionText = ! $hasPeroxideExclusion && (
            preg_match('/\+\s*(?:[1-9]\s*)?'.$peroxideNoun.'\b/u', $textForInclusion) === 1
            || preg_match('/\b(?:con|c\/)\s*(?:[1-9]\s*)?(?:'.$peroxideNoun.'|perox)\b/u', $textForInclusion) === 1
            || preg_match('/\b(?:incluye|trae|contiene)\s+(?:[^,.]{0,35}\s+)?'.$peroxideNoun.'\b/u', $textForInclusion) === 1
            || preg_match('/\b'.$peroxideNoun.'\s+incluido\b/u', $textForInclusion) === 1
            || preg_match('/\b(?:tinte|tubo|coloracion)\s+(?:y|\+)\s+(?:[1-9]\s*)?'.$peroxideNoun.'\b/u', $textForInclusion) === 1
        );

        $hasDyeWord = preg_match('/\b(?:tinte|coloracion|crema\s+colorante|tubo)\b/', $text) === 1 || $normalizedTone !== null;
        $structuredPackQty = $this->extractStructuredPackQuantity($row, $pmItem);
        $structuredKit = $this->analyzeStructuredKitAndActivatorAttributes($row, $pmItem);
        $textPackQty = $this->extractTextPackQuantity($text);
        $hasGenericKitWord = preg_match('/\b(?:kit|combo|set|paquete)\b/', $text) === 1
            || $structuredKit['is_kit'] === true
            || $structuredKit['sale_format_is_kit']
            || $structuredKit['pm_detector_is_kit'];
        $hasMultipackWord = preg_match('/\b(?:duo|trio|pack|multipack|lote|caja)\b/', $text) === 1
            || $textPackQty !== null
            || ($structuredPackQty !== null && $structuredPackQty > 1);

        $hasStructuredPeroxideInclusion = $structuredKit['includes_activator'] === true
            || ($structuredKit['kit_content_mentions_peroxide'] && $structuredKit['includes_activator'] !== false);

        $hasPositivePeroxideInclusion = ! $hasPeroxideExclusion
            && $structuredKit['includes_activator'] !== false
            && ($hasPositivePeroxideInclusionText || $hasStructuredPeroxideInclusion);

        // Solo marcar contradicción real cuando una negación (INCLUDES_ACTIVATOR=No, IS_KIT=No o exclusión textual)
        // choca con una afirmación positiva de kit o inclusión de peróxido (nunca por una simple recomendación de concentración).
        $hasContradictoryKitAttributes =
            ($structuredKit['includes_activator'] === false && (
                $structuredKit['is_kit'] === true
                || $structuredKit['kit_content_mentions_peroxide']
                || $hasPositivePeroxideInclusionText
                || $hasGenericKitWord
            ))
            || ($structuredKit['is_kit'] === false && (
                $structuredKit['includes_activator'] === true
                || $structuredKit['kit_content_mentions_peroxide']
                || ($hasPositivePeroxideInclusionText && $hasDyeWord)
            ))
            || ($hasPeroxideExclusion && (
                $structuredKit['is_kit'] === true
                || $structuredKit['includes_activator'] === true
                || $structuredKit['kit_content_mentions_peroxide']
                || $hasPositivePeroxideInclusionText
            ));

        $peroxideInfo = [
            'included' => false,
            'concentration_vol' => null,
            'concentration_label' => null,
            'volume_ml' => null,
            'peroxide_quantity' => null,
            'ambiguous_details' => false,
        ];

        // 1. Peróxido independiente (sin tinte ni tono)
        if (($hasAnyPeroxideWord || $hasTextConcentration) && ! $hasPeroxideExclusion && ! $hasDyeWord) {
            $peroxideDetails = $this->extractPeroxideDetails($text, standalone: true);
            $requiresReview = $peroxideDetails['concentration_vol'] === null || $peroxideDetails['volume_ml'] === null;
            if ($requiresReview) {
                $conflicts[] = 'PEROXIDE_COMPOSITION_INCOMPLETE';
            }

            return [
                'presentation' => self::PRES_STANDALONE_PEROXIDE,
                'peroxide' => [
                    'included' => false,
                    'concentration_vol' => $peroxideDetails['concentration_vol'],
                    'concentration_label' => $peroxideDetails['concentration_label'],
                    'volume_ml' => $peroxideDetails['volume_ml'],
                    'peroxide_quantity' => $peroxideDetails['peroxide_quantity'] ?? 1,
                    'ambiguous_details' => $requiresReview,
                ],
                'dye_quantity' => null,
                'requires_human_review' => $requiresReview,
                'conflicts' => $conflicts,
            ];
        }

        // 2. Atributos de kit/activador contradictorios: nunca asumir tinte individual ni proponer BOM completo.
        if ($hasContradictoryKitAttributes) {
            $conflicts[] = 'KIT_ATTRIBUTES_CONTRADICTORY';

            return [
                'presentation' => self::PRES_UNKNOWN,
                'peroxide' => [
                    ...$peroxideInfo,
                    'ambiguous_details' => true,
                ],
                'dye_quantity' => null,
                'requires_human_review' => true,
                'conflicts' => $conflicts,
            ];
        }

        // 3. Tinte con peróxido confirmado por evidencia positiva inequívoca y consistente
        if ($hasPositivePeroxideInclusion && $hasDyeWord) {
            $peroxideDetailsText = trim($text.' '.Str::ascii(mb_strtolower($structuredKit['kit_content'], 'UTF-8')));
            $peroxideDetails = $this->extractPeroxideDetails($peroxideDetailsText, standalone: false);
            $dyeQty = $this->extractDyeQuantityInKit($text, $textPackQty);

            $ambiguousDetails = $peroxideDetails['concentration_vol'] === null
                || $peroxideDetails['volume_ml'] === null
                || $peroxideDetails['peroxide_quantity'] === null
                || $dyeQty === null
                || $peroxideDetails['multiple_concentrations'];

            if ($ambiguousDetails) {
                $conflicts[] = 'PEROXIDE_COMPOSITION_INCOMPLETE';
            }

            return [
                'presentation' => self::PRES_DYE_WITH_PEROXIDE,
                'peroxide' => [
                    'included' => true,
                    'concentration_vol' => $peroxideDetails['concentration_vol'],
                    'concentration_label' => $peroxideDetails['concentration_label'],
                    'volume_ml' => $peroxideDetails['volume_ml'],
                    'peroxide_quantity' => $peroxideDetails['peroxide_quantity'],
                    'ambiguous_details' => $ambiguousDetails,
                ],
                'dye_quantity' => $dyeQty,
                'requires_human_review' => true, // Todo kit con peróxido exige confirmación humana de componentes antes de activar.
                'conflicts' => $conflicts,
            ];
        }

        // 4. Mención ambigua de peróxido o concentración sin confirmación de inclusión ni exclusión explícita
        //    (si INCLUDES_ACTIVATOR=No o hay exclusión/instrucción clara y no es kit, no bloquear como ambiguo).
        $confirmedNoPeroxide = $hasPeroxideExclusion || $structuredKit['includes_activator'] === false;
        $hasAmbiguousUnexplainedSignal = ! $confirmedNoPeroxide
            && ($hasUnexplainedPeroxideWord || $hasUnexplainedTextConcentration || $structuredKit['oxidant_volumes'] !== '');
        if ($hasAmbiguousUnexplainedSignal && $hasDyeWord) {
            $conflicts[] = 'AMBIGUOUS_KIT_PRESENTATION';

            return [
                'presentation' => self::PRES_UNKNOWN,
                'peroxide' => [
                    ...$peroxideInfo,
                    'ambiguous_details' => true,
                ],
                'dye_quantity' => null,
                'requires_human_review' => true,
                'conflicts' => $conflicts,
            ];
        }

        // 5. Multipack sin peróxido
        if ($hasMultipackWord && ! $hasPositivePeroxideInclusion) {
            $qty = $textPackQty ?? ($structuredPackQty !== null && $structuredPackQty > 1 ? $structuredPackQty : null);
            $requiresReview = $qty === null;
            if ($requiresReview) {
                $conflicts[] = 'MULTIPACK_QUANTITY_AMBIGUOUS';
            }

            return [
                'presentation' => self::PRES_MULTIPACK,
                'peroxide' => $peroxideInfo,
                'dye_quantity' => $qty,
                'requires_human_review' => $requiresReview,
                'conflicts' => $conflicts,
            ];
        }

        // 6. Dice "kit/combo/paquete/set" o tiene IS_KIT=Sí, pero no aclara si incluye peróxido o cuántas piezas trae
        if ($hasGenericKitWord) {
            $conflicts[] = 'AMBIGUOUS_KIT_PRESENTATION';

            return [
                'presentation' => self::PRES_UNKNOWN,
                'peroxide' => $peroxideInfo,
                'dye_quantity' => null,
                'requires_human_review' => true,
                'conflicts' => $conflicts,
            ];
        }

        // 7. Tinte individual claramente confirmado
        if ($hasDyeWord || $normalizedTone !== null) {
            return [
                'presentation' => self::PRES_SINGLE_DYE,
                'peroxide' => $peroxideInfo,
                'dye_quantity' => 1,
                'requires_human_review' => false,
                'conflicts' => $conflicts,
            ];
        }

        $conflicts[] = 'UNKNOWN_PRESENTATION';

        return [
            'presentation' => self::PRES_UNKNOWN,
            'peroxide' => $peroxideInfo,
            'dye_quantity' => null,
            'requires_human_review' => true,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array{concentration_vol: ?int, concentration_label: ?string, volume_ml: ?int, peroxide_quantity: ?int, multiple_concentrations: bool}
     */
    private function extractPeroxideDetails(string $text, bool $standalone): array
    {
        $concentrations = [];
        if (preg_match_all('/\b(10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)\b/', $text, $volMatches)) {
            foreach ($volMatches[1] as $vol) {
                $concentrations[(int) $vol] = true;
            }
        }
        if (preg_match_all('/(?<!\d)(3|6|9|12)\s*%(?!\d)/', $text, $pctMatches)) {
            $map = [3 => 10, 6 => 20, 9 => 30, 12 => 40];
            foreach ($pctMatches[1] as $pct) {
                if (isset($map[(int) $pct])) {
                    $concentrations[$map[(int) $pct]] = true;
                }
            }
        }

        $multipleConcentrations = count($concentrations) > 1;
        $concentrationVol = count($concentrations) === 1 ? (int) array_key_first($concentrations) : null;
        $concentrationLabel = $concentrationVol !== null ? $concentrationVol.' Vol' : null;

        // Volumen en ml del peróxido: remover concentraciones ("20 vol", "6%") antes de buscar ml asociados al peróxido.
        $textForVol = preg_replace([
            '/\b(?:10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)\b/u',
            '/(?<!\d)(?:3|6|9|12)\s*%(?!\d)/u',
        ], ' ', $text) ?? $text;

        $volumeMl = null;
        if ($standalone) {
            if (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(ml|lt|l)\b/', $textForVol, $m)) {
                $val = (float) str_replace(',', '.', $m[1]);
                $volumeMl = str_starts_with($m[2], 'l') ? (int) round($val * 1000) : (int) round($val);
            }
        } else {
            if (preg_match('/(?:peroxido|oxidante|activador|revelador|developer)\s*(?:de\s*)?(\d+(?:[.,]\d+)?)\s*(ml|lt|l)\b/', $textForVol, $m)) {
                $val = (float) str_replace(',', '.', $m[1]);
                $volumeMl = str_starts_with($m[2], 'l') ? (int) round($val * 1000) : (int) round($val);
            } elseif (preg_match('/\b(\d+(?:[.,]\d+)?)\s*(ml|lt|l)\s*(?:de\s+)?(?:peroxido|oxidante|activador|revelador)\b/', $textForVol, $m)) {
                $val = (float) str_replace(',', '.', $m[1]);
                $volumeMl = str_starts_with($m[2], 'l') ? (int) round($val * 1000) : (int) round($val);
            } elseif (preg_match('/\+\s*(?:peroxido|oxidante|activador)[^0-9]{0,25}(\d+)\s*ml\b/', $textForVol, $m)) {
                $volumeMl = (int) $m[1];
            } elseif (preg_match_all('/\b(\d+)\s*ml\b/', $textForVol, $mlMatches) && count($mlMatches[1]) >= 2) {
                // Ej: "tinte 60ml + peroxido 60ml" o "60ml + 90ml"
                $volumeMl = (int) $mlMatches[1][1];
            }
        }

        $peroxideQuantity = null;
        if (preg_match('/\b([1-9])\s*(?:peroxidos?|oxidantes?|activadores?)\b/', $text, $qm)) {
            $peroxideQuantity = (int) $qm[1];
        } elseif ($concentrationVol !== null && $volumeMl !== null) {
            $peroxideQuantity = 1;
        }

        return [
            'concentration_vol' => $concentrationVol,
            'concentration_label' => $concentrationLabel,
            'volume_ml' => $volumeMl,
            'peroxide_quantity' => $peroxideQuantity,
            'multiple_concentrations' => $multipleConcentrations,
        ];
    }

    private function extractDyeQuantityInKit(string $text, ?int $textPackQty): ?int
    {
        if (preg_match('/\b([1-9])\s*(?:tintes?|tubos?)\b/', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b(?:duo|2\s*tintes)\b/', $text) === 1) {
            return 2;
        }
        if (preg_match('/\b(?:trio|3\s*tintes)\b/', $text) === 1) {
            return 3;
        }
        // Si menciona "2 pzas" en un kit con peróxido (1 tinte + 1 peróxido), no asumir cantidad sin verificación si no especifica qué pieza es doble.
        if ($textPackQty !== null && $textPackQty > 2) {
            return null;
        }
        if (preg_match('/\btinte\b/', $text) === 1) {
            return 1;
        }

        return null;
    }

    private function extractTextPackQuantity(string $text): ?int
    {
        if (preg_match('/\bduo\b/', $text) === 1) {
            return 2;
        }
        if (preg_match('/\btrio\b/', $text) === 1) {
            return 3;
        }
        if (preg_match('/\b([2-9]|1[0-2])\s*(?:pzas?|piezas?|tubos?|tintes?|unidades?|uds?)\b/', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\bx\s*([2-9]|1[0-2])\b/', $text, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\bpack\s*(?:de\s*)?([2-9]|1[0-2])\b/', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractStructuredPackQuantity(array $row, ?MeliPriceManagerItem $pmItem): ?int
    {
        foreach ([$row['variation_attributes'] ?? [], $row['item_attributes'] ?? [], $pmItem?->raw_attributes ?? []] as $attrs) {
            foreach (['UNITS_PER_PACK', 'PACK_QUANTITY', 'PACKAGE_QUANTITY'] as $id) {
                $val = $this->attributeValue((array) $attrs, [$id]);
                if ($val !== '' && is_numeric($val)) {
                    return (int) $val;
                }
            }
        }

        return null;
    }

    /**
     * Analiza atributos estructurados de kit y activador (IS_KIT, KIT_CONTENT, INCLUDES_ACTIVATOR,
     * OXIDANT_VOLUMES, SALE_FORMAT) soportando valores booleanos en español e inglés.
     *
     * @param  array<string, mixed>  $row
     * @return array{
     *     is_kit: ?bool,
     *     includes_activator: ?bool,
     *     kit_content: string,
     *     kit_content_mentions_peroxide: bool,
     *     oxidant_volumes: string,
     *     sale_format_is_kit: bool,
     *     pm_detector_is_kit: bool
     * }
     */
    private function analyzeStructuredKitAndActivatorAttributes(array $row, ?MeliPriceManagerItem $pmItem): array
    {
        $isKit = null;
        $includesActivator = null;
        $kitContent = '';
        $oxidantVolumes = '';
        $saleFormatIsKit = false;

        $attributeSets = [
            (array) ($row['variation_attributes'] ?? []),
            (array) ($row['item_attributes'] ?? []),
            (array) ($pmItem?->raw_attributes ?? []),
        ];

        foreach ($attributeSets as $attrs) {
            if ($isKit === null) {
                $rawIsKit = $this->attributeValue($attrs, ['IS_KIT', 'KIT']);
                if ($rawIsKit !== '') {
                    $isKit = $this->parseLocalizedBool($rawIsKit);
                }
            }

            if ($includesActivator === null) {
                $rawIncludes = $this->attributeValue($attrs, [
                    'INCLUDES_ACTIVATOR',
                    'INCLUDES_OXIDANT',
                    'INCLUDES_PEROXIDE',
                    'INCLUYE_ACTIVADOR',
                    'INCLUYE_OXIDANTE',
                    'INCLUYE_PEROXIDO',
                ]);
                if ($rawIncludes !== '') {
                    $includesActivator = $this->parseLocalizedBool($rawIncludes);
                }
            }

            if ($kitContent === '') {
                $kitContent = $this->attributeValue($attrs, ['KIT_CONTENT', 'PACKAGE_CONTENT', 'CONTENIDO_DEL_KIT']);
            }

            if ($oxidantVolumes === '') {
                $oxidantVolumes = $this->attributeValue($attrs, ['OXIDANT_VOLUMES', 'OXIDANT_VOLUME', 'PEROXIDE_VOLUME']);
            }

            if (! $saleFormatIsKit) {
                $saleFormat = $this->attributeValue($attrs, ['SALE_FORMAT']);
                if ($saleFormat !== '' && preg_match('/kit|combo|pack|set|duo|trio/i', $saleFormat) === 1) {
                    $saleFormatIsKit = true;
                }
            }
        }

        $kitContentAscii = Str::ascii(mb_strtolower($kitContent, 'UTF-8'));
        $kitContentMentionsPeroxide = $kitContentAscii !== ''
            && preg_match('/\b(?:peroxido|oxidante|activador|revelador|developer|agua\s+oxigenada)\b/', $kitContentAscii) === 1;

        return [
            'is_kit' => $isKit,
            'includes_activator' => $includesActivator,
            'kit_content' => $kitContent,
            'kit_content_mentions_peroxide' => $kitContentMentionsPeroxide,
            'oxidant_volumes' => $oxidantVolumes,
            'sale_format_is_kit' => $saleFormatIsKit,
            'pm_detector_is_kit' => $pmItem !== null && $this->kitDetector->isKit($pmItem),
        ];
    }

    private function parseLocalizedBool(string $raw): ?bool
    {
        $normalized = Str::ascii(mb_strtolower(trim($raw), 'UTF-8'));

        if (in_array($normalized, ['1', 'true', 'yes', 'si', 'y', 's', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Detecta inconsistencias sospechosas entre Seller SKU y GTIN únicamente cuando ambos
     * son códigos numéricos de 13 dígitos y difieren con señales claras de error de captura
     * (distancia <= 2, dígito verificador EAN-13 inválido o mismo prefijo de fabricante GS1).
     * Mantiene total compatibilidad con SKUs internos alfanuméricos o de otra longitud.
     */
    private function detectSkuGtinInconsistency(string $sku, ?string $gtin): ?string
    {
        $skuClean = trim($sku);
        $gtinClean = trim((string) ($gtin ?? ''));

        if ($skuClean === '' || $gtinClean === '' || $skuClean === $gtinClean) {
            return null;
        }

        if (preg_match('/^\d{13}$/', $skuClean) !== 1 || preg_match('/^\d{13}$/', $gtinClean) !== 1) {
            return null;
        }

        $distance = levenshtein($skuClean, $gtinClean);
        $skuValidEan = $this->isValidEan13($skuClean);
        $gtinValidEan = $this->isValidEan13($gtinClean);
        $sameGs1Prefix = substr($skuClean, 0, 7) === substr($gtinClean, 0, 7);

        if ($distance <= 2 || ! $skuValidEan || ! $gtinValidEan || $sameGs1Prefix) {
            return 'SKU_GTIN_MISMATCH_SUSPECT';
        }

        return null;
    }

    private function isValidEan13(string $digits): bool
    {
        if (preg_match('/^\d{13}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $digits[$i];
            $sum += ($i % 2 === 0) ? $digit : ($digit * 3);
        }

        $expectedCheck = (10 - ($sum % 10)) % 10;

        return $expectedCheck === (int) $digits[12];
    }

    /**
     * Resuelve el producto maestro candidato y los componentes candidatos en `inventory_products`
     * SIN crear ni modificar nada en BD.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $peroxideInfo
     * @param  array<string, mixed>  $context
     * @return array{
     *     matched_product: ?InventoryProduct,
     *     match_type: string,
     *     match_label: string,
     *     candidate_components: list<array<string, mixed>>,
     *     requires_human_review: bool,
     *     conflicts: list<string>
     * }
     */
    private function resolveInventoryCandidate(
        array $row,
        string $line,
        ?string $normalizedTone,
        string $presentation,
        array $peroxideInfo,
        ?int $dyeQuantity,
        ?InventoryChannelLink $existingLink,
        array $context,
        bool $skuIsSuspicious = false,
    ): array {
        $conflicts = [];
        $requiresHumanReview = false;
        $candidateComponents = [];

        if ($presentation === self::PRES_SINGLE_DYE) {
            $dyeMatch = $this->matchSimpleDye($row, $line, $normalizedTone, $context, allowSkuFallback: ! $skuIsSuspicious, skuIsSuspicious: $skuIsSuspicious);
            $matchedProduct = $dyeMatch['product'];
            $conflicts = array_merge($conflicts, $dyeMatch['conflicts']);

            if ($existingLink && $matchedProduct && (int) $existingLink->inventory_product_id !== (int) $matchedProduct->id) {
                $conflicts[] = 'EXISTING_LINK_CONFLICT';
                $requiresHumanReview = true;
            }

            if ($matchedProduct) {
                $candidateComponents[] = [
                    'role' => 'tinte_base',
                    'inventory_product_id' => $matchedProduct->id,
                    'sku' => $matchedProduct->sku,
                    'name' => $matchedProduct->name,
                    'quantity' => 1,
                    'local_available_stock' => $context['available_by_product'][$matchedProduct->id] ?? 0,
                    'status' => 'MATCHED',
                ];

                return [
                    'matched_product' => $matchedProduct,
                    'match_type' => 'EXISTING_SIMPLE',
                    'match_label' => 'Coincide con InventoryProduct SIMPLE #'.$matchedProduct->id.' ('.$matchedProduct->sku.')',
                    'candidate_components' => $candidateComponents,
                    'requires_human_review' => $requiresHumanReview || $dyeMatch['requires_review'],
                    'conflicts' => $conflicts,
                ];
            }

            $suggestedSku = $row['sku'] !== ''
                ? $row['sku']
                : $this->suggestedMasterSku($line, $normalizedTone);

            $candidateComponents[] = [
                'role' => 'tinte_base',
                'inventory_product_id' => null,
                'sku' => $suggestedSku,
                'name' => trim((self::LINE_LABELS[$line] ?? 'Igora').' '.($normalizedTone ?? '')),
                'quantity' => 1,
                'local_available_stock' => null,
                'status' => $skuIsSuspicious ? 'NEEDS_HUMAN_REVIEW' : 'PROPOSE_CREATE_SIMPLE',
            ];

            return [
                'matched_product' => null,
                'match_type' => $skuIsSuspicious ? 'MANUAL_REVIEW_REQUIRED' : 'PROPOSE_CREATE_SIMPLE',
                'match_label' => $skuIsSuspicious
                    ? 'SKU/GTIN inconsistente — requiere validación humana antes de vincular'
                    : 'Proponer alta/vínculo de SIMPLE central ('.($normalizedTone ?? 'sin tono').')',
                'candidate_components' => $candidateComponents,
                'requires_human_review' => $requiresHumanReview || $dyeMatch['requires_review'],
                'conflicts' => $conflicts,
            ];
        }

        if ($presentation === self::PRES_STANDALONE_PEROXIDE) {
            $peroxMatch = $this->matchSimplePeroxide($row, $peroxideInfo, $context, allowSkuFallback: ! $skuIsSuspicious);
            $matchedProduct = $peroxMatch['product'];
            $conflicts = array_merge($conflicts, $peroxMatch['conflicts']);

            $candidateComponents[] = [
                'role' => 'peroxido_base',
                'inventory_product_id' => $matchedProduct?->id,
                'sku' => $matchedProduct?->sku ?? ($row['sku'] !== '' ? $row['sku'] : null),
                'name' => $matchedProduct?->name ?? 'Peróxido '.($peroxideInfo['concentration_label'] ?? 'por confirmar').' '.($peroxideInfo['volume_ml'] ? $peroxideInfo['volume_ml'].'ml' : ''),
                'quantity' => 1,
                'local_available_stock' => $matchedProduct ? ($context['available_by_product'][$matchedProduct->id] ?? 0) : null,
                'status' => $matchedProduct ? 'MATCHED' : 'NEEDS_HUMAN_REVIEW',
            ];

            return [
                'matched_product' => $matchedProduct,
                'match_type' => $matchedProduct ? 'EXISTING_SIMPLE' : 'PROPOSE_CREATE_SIMPLE',
                'match_label' => $matchedProduct
                    ? 'Coincide con peróxido SIMPLE #'.$matchedProduct->id.' ('.$matchedProduct->sku.')'
                    : 'Proponer alta/selección de peróxido SIMPLE',
                'candidate_components' => $candidateComponents,
                'requires_human_review' => $peroxMatch['requires_review'] || $matchedProduct === null,
                'conflicts' => $conflicts,
            ];
        }

        if ($presentation === self::PRES_DYE_WITH_PEROXIDE) {
            $dyeMatch = $this->matchSimpleDye($row, $line, $normalizedTone, $context, allowSkuFallback: false);
            $dyeProduct = $dyeMatch['product'];
            $conflicts = array_merge($conflicts, $dyeMatch['conflicts']);

            $peroxMatch = $this->matchSimplePeroxide($row, $peroxideInfo, $context, allowSkuFallback: false);
            $peroxProduct = $peroxMatch['product'];
            $conflicts = array_merge($conflicts, $peroxMatch['conflicts']);

            $candidateComponents[] = [
                'role' => 'tinte_componente',
                'inventory_product_id' => $dyeProduct?->id,
                'sku' => $dyeProduct?->sku,
                'name' => $dyeProduct?->name ?? trim((self::LINE_LABELS[$line] ?? 'Igora').' '.($normalizedTone ?? 'tono por confirmar')),
                'quantity' => $dyeQuantity,
                'local_available_stock' => $dyeProduct ? ($context['available_by_product'][$dyeProduct->id] ?? 0) : null,
                'status' => ($dyeProduct && $dyeQuantity !== null) ? 'CANDIDATE_CONFIRM' : 'NEEDS_HUMAN_REVIEW',
            ];

            $candidateComponents[] = [
                'role' => 'peroxido_componente',
                'inventory_product_id' => $peroxProduct?->id,
                'sku' => $peroxProduct?->sku,
                'name' => $peroxProduct?->name ?? trim('Peróxido '.($peroxideInfo['concentration_label'] ?? 'concentración desconocida').' '.($peroxideInfo['volume_ml'] ? $peroxideInfo['volume_ml'].'ml' : 'volumen desconocido')),
                'quantity' => $peroxideInfo['peroxide_quantity'],
                'local_available_stock' => $peroxProduct ? ($context['available_by_product'][$peroxProduct->id] ?? 0) : null,
                'status' => ($peroxProduct && ! $peroxideInfo['ambiguous_details']) ? 'CANDIDATE_CONFIRM' : 'NEEDS_HUMAN_REVIEW',
            ];

            $matchedKit = ($dyeProduct && $peroxProduct && $dyeQuantity !== null && $peroxideInfo['peroxide_quantity'] !== null)
                ? $this->findMatchingExistingKit($context['kit_products'], [
                    $dyeProduct->id => $dyeQuantity,
                    $peroxProduct->id => (int) $peroxideInfo['peroxide_quantity'],
                ])
                : null;

            return [
                'matched_product' => $matchedKit,
                'match_type' => $matchedKit ? 'EXISTING_KIT_REVIEW' : 'PROPOSE_KIT_REVIEW',
                'match_label' => $matchedKit
                    ? 'Kit existente #'.$matchedKit->id.' ('.$matchedKit->sku.') — requiere confirmación humana'
                    : 'Requiere revisión humana de componentes del KIT (tinte + peróxido)',
                'candidate_components' => $candidateComponents,
                'requires_human_review' => true,
                'conflicts' => $conflicts,
            ];
        }

        if ($presentation === self::PRES_MULTIPACK) {
            $dyeMatch = $this->matchSimpleDye($row, $line, $normalizedTone, $context, allowSkuFallback: false);
            $dyeProduct = $dyeMatch['product'];
            $conflicts = array_merge($conflicts, $dyeMatch['conflicts']);

            $candidateComponents[] = [
                'role' => 'tinte_componente',
                'inventory_product_id' => $dyeProduct?->id,
                'sku' => $dyeProduct?->sku,
                'name' => $dyeProduct?->name ?? trim((self::LINE_LABELS[$line] ?? 'Igora').' '.($normalizedTone ?? 'tono por confirmar')),
                'quantity' => $dyeQuantity,
                'local_available_stock' => $dyeProduct ? ($context['available_by_product'][$dyeProduct->id] ?? 0) : null,
                'status' => ($dyeProduct && $dyeQuantity !== null) ? 'CANDIDATE_CONFIRM' : 'NEEDS_HUMAN_REVIEW',
            ];

            $matchedKit = ($dyeProduct && $dyeQuantity !== null)
                ? $this->findMatchingExistingKit($context['kit_products'], [$dyeProduct->id => $dyeQuantity])
                : null;

            return [
                'matched_product' => $matchedKit,
                'match_type' => $matchedKit ? 'EXISTING_KIT_REVIEW' : 'PROPOSE_MULTIPACK_KIT',
                'match_label' => $matchedKit
                    ? 'Multipack KIT existente #'.$matchedKit->id.' ('.$matchedKit->sku.')'
                    : 'Proponer KIT multipack ('.($dyeQuantity !== null ? "x{$dyeQuantity}" : 'cantidad por confirmar').')',
                'candidate_components' => $candidateComponents,
                'requires_human_review' => $dyeQuantity === null || $dyeProduct === null,
                'conflicts' => $conflicts,
            ];
        }

        return [
            'matched_product' => $existingLink?->product,
            'match_type' => 'MANUAL_REVIEW_REQUIRED',
            'match_label' => 'Presentación ambigua o desconocida — requiere clasificación humana',
            'candidate_components' => [],
            'requires_human_review' => true,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return array{product: ?InventoryProduct, conflicts: list<string>, requires_review: bool}
     */
    private function matchSimpleDye(
        array $row,
        string $line,
        ?string $normalizedTone,
        array $context,
        bool $allowSkuFallback = true,
        bool $skuIsSuspicious = false,
    ): array {
        $conflicts = [];
        $requiresReview = false;

        if ($skuIsSuspicious) {
            return ['product' => null, 'conflicts' => $conflicts, 'requires_review' => true];
        }

        if ($allowSkuFallback) {
            foreach (array_filter([$row['sku'], $row['ean'] ?? null]) as $code) {
                $normCode = $this->normalizeCode((string) $code);
                if ($normCode === '') {
                    continue;
                }
                $candidates = $context['products_by_code'][$normCode]
                    ?? $context['products_by_code'][ltrim($normCode, '0')]
                    ?? collect();

                if ($candidates->isEmpty()) {
                    continue;
                }

                $verifiedCandidates = collect();
                $skuToneMismatch = false;
                $skuLineMismatch = false;
                $toneUnverified = false;

                foreach ($candidates as $p) {
                    if ($p->isKit() || $p->isBundleOrKit()) {
                        continue;
                    }
                    if (! $this->inventoryProductCompatibleWithLine($p, $line)) {
                        $conflicts[] = 'INVENTORY_LINE_MISMATCH';
                        $skuLineMismatch = true;

                        continue;
                    }

                    $productTones = $this->extractTonesFromText(
                        trim((string) $p->name.' '.(string) $p->sku),
                        (string) ($p->barcode ?? ''),
                        $p->barcode_secondary,
                    );

                    // Si falta el tono en la publicación o en el producto físico, no aprobar
                    // automáticamente por SKU/EAN sin revisión humana.
                    if ($normalizedTone === null || $productTones === []) {
                        $conflicts[] = 'INVENTORY_TONE_UNVERIFIED';
                        $toneUnverified = true;
                        $requiresReview = true;

                        continue;
                    }

                    if (count($productTones) > 1) {
                        $conflicts[] = 'AMBIGUOUS_INVENTORY_MATCH';
                        $requiresReview = true;

                        continue;
                    }

                    if ($productTones[0] !== $normalizedTone) {
                        $conflicts[] = 'SKU_TONE_MISMATCH';
                        $skuToneMismatch = true;
                        $requiresReview = true;

                        continue;
                    }

                    $verifiedCandidates->push($p);
                }

                if ($skuToneMismatch || $skuLineMismatch || $toneUnverified) {
                    return [
                        'product' => null,
                        'conflicts' => array_values(array_unique($conflicts)),
                        'requires_review' => true,
                    ];
                }

                if ($verifiedCandidates->count() === 1) {
                    return [
                        'product' => $verifiedCandidates->first(),
                        'conflicts' => array_values(array_unique($conflicts)),
                        'requires_review' => $requiresReview || $conflicts !== [],
                    ];
                }
                if ($verifiedCandidates->count() > 1) {
                    $conflicts[] = 'AMBIGUOUS_INVENTORY_MATCH';

                    return [
                        'product' => null,
                        'conflicts' => array_values(array_unique($conflicts)),
                        'requires_review' => true,
                    ];
                }
            }
        }

        if ($normalizedTone !== null && $line !== self::LINE_OTHER) {
            $byLineAndTone = $context['simple_dyes']->filter(function (InventoryProduct $p) use ($line, $normalizedTone): bool {
                if (! $this->inventoryProductCompatibleWithLine($p, $line, strictLineMatch: true)) {
                    return false;
                }
                $pTones = $this->extractTonesFromText($p->name.' '.$p->sku, '', null);

                return in_array($normalizedTone, $pTones, true);
            })->values();

            if ($byLineAndTone->count() === 1) {
                return [
                    'product' => $byLineAndTone->first(),
                    'conflicts' => $conflicts,
                    'requires_review' => $conflicts !== [],
                ];
            }
            if ($byLineAndTone->count() > 1) {
                $conflicts[] = 'AMBIGUOUS_INVENTORY_MATCH';

                return ['product' => null, 'conflicts' => $conflicts, 'requires_review' => true];
            }
        }

        return ['product' => null, 'conflicts' => $conflicts, 'requires_review' => $requiresReview];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $peroxideInfo
     * @param  array<string, mixed>  $context
     * @return array{product: ?InventoryProduct, conflicts: list<string>, requires_review: bool}
     */
    private function matchSimplePeroxide(
        array $row,
        array $peroxideInfo,
        array $context,
        bool $allowSkuFallback = true,
    ): array {
        $conflicts = [];

        if ($allowSkuFallback && $row['sku'] !== '') {
            $normCode = $this->normalizeCode((string) $row['sku']);
            $candidates = ($context['products_by_code'][$normCode] ?? collect())
                ->filter(fn (InventoryProduct $p): bool => $p->isSimple() && $this->looksLikePeroxideText($p->name.' '.$p->sku))
                ->values();

            if ($candidates->count() === 1) {
                return ['product' => $candidates->first(), 'conflicts' => [], 'requires_review' => false];
            }
        }

        $vol = $peroxideInfo['concentration_vol'] ?? null;
        $ml = $peroxideInfo['volume_ml'] ?? null;

        if ($vol === null || $ml === null) {
            return ['product' => null, 'conflicts' => [], 'requires_review' => true];
        }

        $matches = $context['simple_peroxides']->filter(function (InventoryProduct $p) use ($vol, $ml): bool {
            $text = Str::ascii(mb_strtolower($p->name.' '.$p->brand.' '.$p->sku, 'UTF-8'));
            $isIgoraOrSchwarzkopf = str_contains($text, 'igora') || str_contains($text, 'schwarzkopf');
            if (! $isIgoraOrSchwarzkopf) {
                return false;
            }
            $details = $this->extractPeroxideDetails($text, standalone: true);

            return $details['concentration_vol'] === $vol && $details['volume_ml'] === $ml;
        })->values();

        if ($matches->count() === 1) {
            return ['product' => $matches->first(), 'conflicts' => [], 'requires_review' => false];
        }

        if ($matches->count() > 1) {
            $conflicts[] = 'AMBIGUOUS_PEROXIDE_INVENTORY_MATCH';
        }

        return ['product' => null, 'conflicts' => $conflicts, 'requires_review' => true];
    }

    /**
     * @param  Collection<int, InventoryProduct>  $kits
     * @param  array<int, int>  $expectedComponents  [component_product_id => quantity]
     */
    private function findMatchingExistingKit(Collection $kits, array $expectedComponents): ?InventoryProduct
    {
        ksort($expectedComponents);

        foreach ($kits as $kit) {
            $actual = [];
            foreach ($kit->kitComponents as $comp) {
                $actual[(int) $comp->component_product_id] = (int) $comp->quantity;
            }
            ksort($actual);

            if ($actual === $expectedComponents) {
                return $kit;
            }
        }

        return null;
    }

    private function inventoryProductCompatibleWithLine(
        InventoryProduct $product,
        string $rowLine,
        bool $strictLineMatch = false,
    ): bool {
        $text = Str::ascii(mb_strtolower(trim($product->name.' '.$product->brand.' '.$product->sku), 'UTF-8'));
        $isVibrance = str_contains($text, 'vibrance');
        $isRoyal = str_contains($text, 'royal');
        $isColor10 = preg_match('/\bcolor\s*10\b/', $text) === 1;
        $isZeroAmm = preg_match('/\bzero\s*amm\b/', $text) === 1;

        if ($strictLineMatch) {
            return match ($rowLine) {
                self::LINE_ROYAL => $isRoyal && ! $isVibrance && ! $isColor10 && ! $isZeroAmm,
                self::LINE_VIBRANCE => $isVibrance && ! $isRoyal,
                self::LINE_COLOR10 => $isColor10,
                self::LINE_ZERO_AMM => $isZeroAmm,
                default => false,
            };
        }

        if ($rowLine === self::LINE_ROYAL && ($isVibrance || $isColor10 || $isZeroAmm)) {
            return false;
        }
        if ($rowLine === self::LINE_VIBRANCE && $isRoyal) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $peroxideInfo
     * @return array{key: string, label: string}
     */
    private function buildProposedGroupKey(
        array $row,
        string $line,
        ?string $normalizedTone,
        string $presentation,
        array $peroxideInfo,
        ?int $dyeQuantity,
    ): array {
        $lineLabel = self::LINE_LABELS[$line] ?? $line;

        if ($presentation === self::PRES_SINGLE_DYE && $normalizedTone !== null) {
            return [
                'key' => "{$line}|{$normalizedTone}|SIMPLE",
                'label' => "{$lineLabel} · Tono {$normalizedTone} · Tinte individual",
            ];
        }

        if ($presentation === self::PRES_DYE_WITH_PEROXIDE && $normalizedTone !== null) {
            $peroxSig = ($peroxideInfo['concentration_vol'] !== null && $peroxideInfo['volume_ml'] !== null)
                ? 'PEROX-'.$peroxideInfo['concentration_vol'].'V-'.$peroxideInfo['volume_ml'].'ML'
                : 'PEROX-REVISION';
            $qtySig = $dyeQuantity !== null ? 'X'.$dyeQuantity : 'XQTY';

            return [
                'key' => "{$line}|{$normalizedTone}|KIT|{$qtySig}|{$peroxSig}",
                'label' => trim("{$lineLabel} · Tono {$normalizedTone} · Kit c/ Peróxido ".($peroxideInfo['concentration_label'] ?? '(revisión humana)')),
            ];
        }

        if ($presentation === self::PRES_MULTIPACK && $normalizedTone !== null) {
            $qtySig = $dyeQuantity !== null ? 'X'.$dyeQuantity : 'XQTY';

            return [
                'key' => "{$line}|{$normalizedTone}|MULTIPACK|{$qtySig}",
                'label' => "{$lineLabel} · Tono {$normalizedTone} · Multipack ".($dyeQuantity !== null ? "x{$dyeQuantity}" : '(cantidad ambigua)'),
            ];
        }

        if ($presentation === self::PRES_STANDALONE_PEROXIDE) {
            $vol = $peroxideInfo['concentration_vol'];
            $ml = $peroxideInfo['volume_ml'];
            if ($vol !== null && $ml !== null) {
                return [
                    'key' => "IGORA_PEROXIDE|{$vol}V|{$ml}ML",
                    'label' => "Peróxido Igora · {$vol} Vol · {$ml} ml",
                ];
            }

            return [
                'key' => 'IGORA_PEROXIDE|REVIEW|'.$row['mlm'].($row['variation_id'] ? ':'.$row['variation_id'] : ''),
                'label' => 'Peróxido · Especificaciones incompletas',
            ];
        }

        return [
            'key' => 'UNGROUPED|'.$row['mlm'].($row['variation_id'] ? ':'.$row['variation_id'] : ''),
            'label' => 'Sin agrupar · Revisión manual ('.$row['mlm'].')',
        ];
    }

    /**
     * Detecta conflictos cruzados entre múltiples filas descubiertas:
     * - Mismo SKU usado en presentaciones o líneas distintas.
     * - Mismo user_product_id dentro de la misma cuenta asignado a grupos distintos.
     * - Múltiples SKUs incompatibles dentro de un mismo grupo.
     * - Múltiples user_product_id dentro del mismo grupo (informativo: requiere vínculos separados).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function applyCrossRowDiagnostics(Collection $rows): Collection
    {
        $bySku = $rows->filter(fn (array $r): bool => $r['sku'] !== '')
            ->groupBy(fn (array $r): string => mb_strtoupper(trim($r['sku']), 'UTF-8'));

        $byAccountUserProduct = $rows->filter(fn (array $r): bool => filled($r['account_key']) && filled($r['user_product_id']))
            ->groupBy(fn (array $r): string => $r['account_key'].'|'.$r['user_product_id']);

        $byGroup = $rows->groupBy('proposed_group_key');

        return $rows->map(function (array $row) use ($bySku, $byAccountUserProduct, $byGroup): array {
            $conflicts = $row['conflicts'];
            $notes = $row['notes'];
            $requiresReview = $row['requires_human_review'];

            $skuKey = mb_strtoupper(trim((string) $row['sku']), 'UTF-8');
            if ($skuKey !== '' && $bySku->has($skuKey)) {
                $sameSkuRows = $bySku->get($skuKey);
                if ($sameSkuRows->pluck('presentation_type')->unique()->count() > 1) {
                    $conflicts[] = 'DUPLICATE_SKU_CROSS_PRESENTATION';
                    $requiresReview = true;
                }
                if ($sameSkuRows->pluck('line')->unique()->count() > 1) {
                    $conflicts[] = 'DUPLICATE_SKU_CROSS_LINE';
                    $requiresReview = true;
                }
                $sameLineTones = $sameSkuRows
                    ->filter(fn (array $candidate): bool => $candidate['line'] === $row['line'])
                    ->pluck('normalized_tone')
                    ->filter(fn (mixed $tone): bool => is_string($tone) && trim($tone) !== '')
                    ->unique();
                if ($sameLineTones->count() > 1) {
                    $conflicts[] = 'DUPLICATE_SKU_CROSS_TONE';
                    $requiresReview = true;
                }
            }

            if (filled($row['account_key']) && filled($row['user_product_id'])) {
                $upKey = $row['account_key'].'|'.$row['user_product_id'];
                $sameUpRows = $byAccountUserProduct->get($upKey, collect());
                if ($sameUpRows->pluck('proposed_group_key')->unique()->count() > 1) {
                    $conflicts[] = 'USER_PRODUCT_SHARED_DIFFERENT_GROUPS';
                    $requiresReview = true;
                }
            }

            $groupRows = $byGroup->get($row['proposed_group_key'], collect());
            $distinctSkus = $groupRows->pluck('sku')->filter()->map(fn ($s) => mb_strtoupper(trim((string) $s), 'UTF-8'))->unique();
            $distinctProductIds = $groupRows->pluck('proposed_inventory_product_id')->filter()->unique();
            if ($distinctSkus->count() > 1 && $distinctProductIds->count() !== 1) {
                $conflicts[] = 'GROUP_HAS_MULTIPLE_SKUS';
                $requiresReview = true;
            }

            $distinctUserProducts = $groupRows->pluck('user_product_id')->filter()->unique();
            if ($distinctUserProducts->count() > 1) {
                $notes[] = 'INDEPENDENT_USER_PRODUCTS_IN_GROUP';
            }

            $conflicts = array_values(array_unique($conflicts));
            $notes = array_values(array_unique($notes));

            $row['conflicts'] = $conflicts;
            $row['notes'] = $notes;
            $row['requires_human_review'] = $requiresReview;
            $row['is_ambiguous_or_conflicted'] = $requiresReview || $this->hasBlockingOrAmbiguousConflict($conflicts);
            $row['confidence'] = $this->computeConfidence(
                $row['line'],
                $row['normalized_tone'],
                $row['presentation_type'],
                $requiresReview,
                $conflicts,
                ['matched_product' => $row['proposed_inventory_product_id'] ? true : null],
            );
            $row['recommended_action'] = $this->determineRecommendedAction(
                $row,
                $row['presentation_type'],
                $requiresReview,
                $conflicts,
                ['matched_product' => $row['proposed_inventory_product_id'] ? true : null],
                null,
                $row['existing_channel_link_id'] !== null,
            );

            return $row;
        });
    }

    /**
     * @param  list<string>  $conflicts
     * @param  array<string, mixed>  $matchResult
     */
    private function computeConfidence(
        string $line,
        ?string $normalizedTone,
        string $presentation,
        bool $requiresHumanReview,
        array $conflicts,
        array $matchResult,
    ): string {
        $severe = [
            'LINE_CONFLICT_ROYAL_VS_VIBRANCE',
            'LINE_AMBIGUOUS',
            'TONE_NOT_DETECTED',
            'MULTIPLE_TONES_IN_SINGLE_LISTING',
            'TONE_MISMATCH_ATTRIBUTE_VS_TITLE',
            'AMBIGUOUS_KIT_PRESENTATION',
            'KIT_ATTRIBUTES_CONTRADICTORY',
            'INVENTORY_LINE_MISMATCH',
            'SKU_TONE_MISMATCH',
            'SKU_GTIN_MISMATCH_SUSPECT',
            'AMBIGUOUS_INVENTORY_MATCH',
            'EXISTING_LINK_CONFLICT',
            'USER_PRODUCT_SHARED_DIFFERENT_GROUPS',
            'DUPLICATE_SKU_CROSS_LINE',
            'DUPLICATE_SKU_CROSS_TONE',
            'PUBLICATION_MODERATED_OR_DENOUNCED',
        ];

        if ($presentation === self::PRES_UNKNOWN || array_intersect($conflicts, $severe) !== []) {
            return self::CONFIDENCE_LOW;
        }

        if ($requiresHumanReview
            || in_array('INVENTORY_TONE_UNVERIFIED', $conflicts, true)
            || in_array('DUPLICATE_SKU_CROSS_PRESENTATION', $conflicts, true)
            || in_array('GROUP_HAS_MULTIPLE_SKUS', $conflicts, true)
            || in_array('MISSING_SKU', $conflicts, true)
            || in_array('DATA_INCOMPLETE', $conflicts, true)
            || in_array('SNAPSHOT_STALE', $conflicts, true)
            || $line === self::LINE_OTHER) {
            return self::CONFIDENCE_MEDIUM;
        }

        if ($presentation === self::PRES_SINGLE_DYE && $normalizedTone !== null && $line === self::LINE_ROYAL) {
            return self::CONFIDENCE_HIGH;
        }

        if ($presentation === self::PRES_STANDALONE_PEROXIDE && ! empty($matchResult['matched_product'])) {
            return self::CONFIDENCE_HIGH;
        }

        return self::CONFIDENCE_MEDIUM;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $conflicts
     * @param  array<string, mixed>  $matchResult
     */
    private function determineRecommendedAction(
        array $row,
        string $presentation,
        bool $requiresHumanReview,
        array $conflicts,
        array $matchResult,
        ?InventoryChannelLink $existingLink,
        bool $hasExistingLink = false,
    ): string {
        if ($row['is_full']) {
            return 'EXCLUIR_FULL_CONTROLADO_POR_ML';
        }
        if (in_array('USER_PRODUCT_SHARED_DIFFERENT_GROUPS', $conflicts, true)) {
            return 'RESOLVER_CONFLICTO_USER_PRODUCT';
        }
        if (in_array('LINE_CONFLICT_ROYAL_VS_VIBRANCE', $conflicts, true) || in_array('DUPLICATE_SKU_CROSS_LINE', $conflicts, true)) {
            return 'REVISAR_LINEA_ROYAL_VS_VIBRANCE';
        }
        if (in_array('EXISTING_LINK_CONFLICT', $conflicts, true)) {
            return 'REVISAR_VINCULO_EXISTENTE_EN_CANAL';
        }
        if (in_array('SKU_TONE_MISMATCH', $conflicts, true) || in_array('DUPLICATE_SKU_CROSS_TONE', $conflicts, true)) {
            return 'REVISAR_CONFLICTO_SKU_TONO';
        }
        if (in_array('KIT_ATTRIBUTES_CONTRADICTORY', $conflicts, true)) {
            return 'REVISAR_ATRIBUTOS_KIT_CONTRADICTORIOS';
        }
        if (in_array('SKU_GTIN_MISMATCH_SUSPECT', $conflicts, true)) {
            return 'REVISAR_CONFLICTO_SKU_GTIN';
        }
        if (in_array('PUBLICATION_MODERATED_OR_DENOUNCED', $conflicts, true)) {
            return 'REVISAR_PUBLICACION_MODERADA';
        }
        if ($presentation === self::PRES_DYE_WITH_PEROXIDE) {
            return 'CONFIRMAR_COMPONENTES_KIT_CON_PEROXIDO';
        }

        $classificationConflicts = [
            'TONE_NOT_DETECTED',
            'INVENTORY_TONE_UNVERIFIED',
            'MULTIPLE_TONES_IN_SINGLE_LISTING',
            'TONE_MISMATCH_ATTRIBUTE_VS_TITLE',
            'AMBIGUOUS_KIT_PRESENTATION',
            'MULTIPACK_QUANTITY_AMBIGUOUS',
            'AMBIGUOUS_INVENTORY_MATCH',
            'AMBIGUOUS_PEROXIDE_INVENTORY_MATCH',
            'DUPLICATE_SKU_CROSS_PRESENTATION',
            'GROUP_HAS_MULTIPLE_SKUS',
            'LINE_AMBIGUOUS',
        ];
        if ($presentation === self::PRES_UNKNOWN || array_intersect($conflicts, $classificationConflicts) !== []) {
            return 'REVISION_HUMANA_CLASIFICACION';
        }
        if (in_array('MISSING_SKU', $conflicts, true) || in_array('DATA_INCOMPLETE', $conflicts, true)) {
            return 'VALIDAR_IDENTIDAD_Y_DATOS_INCOMPLETOS';
        }
        if ($requiresHumanReview) {
            return 'REVISION_HUMANA_CLASIFICACION';
        }
        if ($existingLink !== null || $hasExistingLink) {
            return 'YA_VINCULADO_VALIDAR_ACTIVACION_FUTURA';
        }
        if (in_array('SNAPSHOT_STALE', $conflicts, true)) {
            return 'REFRESCAR_SNAPSHOT_ANTES_DE_VINCULAR';
        }
        if (! empty($matchResult['matched_product'])) {
            return 'CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE';
        }

        return 'CANDIDATO_A_CREAR_Y_AGRUPAR_EN_INVENTORY';
    }

    /**
     * @param  list<string>  $conflicts
     */
    private function hasBlockingOrAmbiguousConflict(array $conflicts): bool
    {
        $nonAmbiguous = ['PUBLICATION_PAUSED', 'SNAPSHOT_STALE'];

        return array_diff($conflicts, $nonAmbiguous) !== [];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildGroupSummaries(Collection $rows): array
    {
        return $rows->groupBy('proposed_group_key')
            ->map(function (Collection $groupRows, string $groupKey): array {
                $first = $groupRows->first();
                $allConflicts = $groupRows->flatMap(fn (array $r): array => $r['conflicts'])->unique()->values()->all();
                $allNotes = $groupRows->flatMap(fn (array $r): array => $r['notes'])->unique()->values()->all();

                return [
                    'group_key' => $groupKey,
                    'label' => $first['proposed_group_label'],
                    'line' => $first['line'],
                    'line_label' => $first['line_label'],
                    'normalized_tone' => $first['normalized_tone'],
                    'presentation_type' => $first['presentation_type'],
                    'presentation_label' => $first['presentation_label'],
                    'members_count' => $groupRows->count(),
                    'accounts_count' => $groupRows->pluck('account_id')->filter()->unique()->count(),
                    'distinct_user_products_count' => $groupRows->pluck('user_product_id')->filter()->unique()->count(),
                    'proposed_inventory_product_id' => $first['proposed_inventory_product_id'],
                    'proposed_inventory_product_sku' => $first['proposed_inventory_product_sku'],
                    'local_physical_stock' => $first['local_physical_stock'],
                    'local_available_stock' => $first['local_available_stock'],
                    'requires_human_review' => $groupRows->contains(fn (array $r): bool => $r['requires_human_review']),
                    'has_full_members' => $groupRows->contains(fn (array $r): bool => $r['is_full']),
                    'conflicts' => $allConflicts,
                    'notes' => $allNotes,
                    'mlms' => $groupRows->pluck('mlm')->unique()->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, int>
     */
    private function computeCounts(Collection $rows, array $groups): array
    {
        return [
            'total_rows' => $rows->count(),
            'total_groups' => count($groups),
            'royal_rows' => $rows->where('line', self::LINE_ROYAL)->count(),
            'other_line_rows' => $rows->where('line', '!=', self::LINE_ROYAL)->count(),
            'single_dye_rows' => $rows->where('presentation_type', self::PRES_SINGLE_DYE)->count(),
            'dye_with_peroxide_rows' => $rows->where('presentation_type', self::PRES_DYE_WITH_PEROXIDE)->count(),
            'multipack_rows' => $rows->where('presentation_type', self::PRES_MULTIPACK)->count(),
            'standalone_peroxide_rows' => $rows->where('presentation_type', self::PRES_STANDALONE_PEROXIDE)->count(),
            'unknown_presentation_rows' => $rows->where('presentation_type', self::PRES_UNKNOWN)->count(),
            'ambiguous_or_conflicted_rows' => $rows->where('is_ambiguous_or_conflicted', true)->count(),
            'full_rows' => $rows->where('is_full', true)->count(),
            'paused_rows' => $rows->where('is_paused', true)->count(),
            'moderated_rows' => $rows->where('is_moderated_or_denounced', true)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return [
            'total_rows' => 0,
            'total_groups' => 0,
            'royal_rows' => 0,
            'other_line_rows' => 0,
            'single_dye_rows' => 0,
            'dye_with_peroxide_rows' => 0,
            'multipack_rows' => 0,
            'standalone_peroxide_rows' => 0,
            'unknown_presentation_rows' => 0,
            'ambiguous_or_conflicted_rows' => 0,
            'full_rows' => 0,
            'paused_rows' => 0,
            'moderated_rows' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        if ($filters['line'] !== '' && $row['line'] !== $filters['line']) {
            return false;
        }
        if ($filters['tone'] !== '' && strcasecmp((string) ($row['normalized_tone'] ?? ''), (string) $filters['tone']) !== 0) {
            return false;
        }
        if ($filters['presentation'] !== '' && $row['presentation_type'] !== $filters['presentation']) {
            return false;
        }
        if ($filters['status'] !== '' && strtolower((string) $row['status']) !== $filters['status']) {
            return false;
        }
        if ($filters['ambiguous_only'] && ! $row['is_ambiguous_or_conflicted']) {
            return false;
        }
        if ($filters['search'] !== '') {
            $needle = Str::ascii(mb_strtolower($filters['search'], 'UTF-8'));
            $haystack = Str::ascii(mb_strtolower(implode(' ', array_filter([
                $row['sku'],
                $row['mlm'],
                $row['variation_id'],
                $row['user_product_id'],
                $row['normalized_tone'],
                $row['title'],
                $row['variation_label'],
                $row['account_name'],
                $row['proposed_inventory_product_sku'],
                $row['proposed_inventory_product_name'],
            ])), 'UTF-8'));

            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ordena estrictamente por línea -> tono -> presentación -> cuenta -> MLM -> variación.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareRows(array $a, array $b): int
    {
        $lineOrder = [
            self::LINE_ROYAL => 1,
            self::LINE_VIBRANCE => 2,
            self::LINE_COLOR10 => 3,
            self::LINE_ZERO_AMM => 4,
            self::LINE_OTHER => 5,
        ];

        $cmp = ($lineOrder[$a['line']] ?? 9) <=> ($lineOrder[$b['line']] ?? 9);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = $this->compareTones($a['normalized_tone'], $b['normalized_tone']);
        if ($cmp !== 0) {
            return $cmp;
        }

        $presOrder = [
            self::PRES_SINGLE_DYE => 1,
            self::PRES_DYE_WITH_PEROXIDE => 2,
            self::PRES_MULTIPACK => 3,
            self::PRES_STANDALONE_PEROXIDE => 4,
            self::PRES_UNKNOWN => 5,
        ];
        $cmp = ($presOrder[$a['presentation_type']] ?? 9) <=> ($presOrder[$b['presentation_type']] ?? 9);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = ((int) ($a['account_id'] ?? 0)) <=> ((int) ($b['account_id'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = strcmp((string) $a['mlm'], (string) $b['mlm']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['variation_id'] ?? ''), (string) ($b['variation_id'] ?? ''));
    }

    private function compareTones(?string $a, ?string $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        [$aBase, $aRef] = array_pad(explode('-', $a, 2), 2, '');
        [$bBase, $bRef] = array_pad(explode('-', $b, 2), 2, '');

        $aNum = is_numeric($aBase) ? (float) $aBase : 999.0;
        $bNum = is_numeric($bBase) ? (float) $bBase : 999.0;

        if ($aNum !== $bNum) {
            return $aNum <=> $bNum;
        }
        if ($aBase !== $bBase) {
            return strcmp($aBase, $bBase);
        }

        return strnatcmp($aRef, $bRef);
    }

    /**
     * @param  array<int, mixed>  $attributes
     * @param  list<string>  $targetIds
     */
    private function attributeValue(array $attributes, array $targetIds): string
    {
        $upperTargets = array_map('strtoupper', $targetIds);

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $id = strtoupper(trim((string) ($attribute['id'] ?? '')));
            $name = strtoupper(trim((string) ($attribute['name'] ?? '')));

            if (! in_array($id, $upperTargets, true) && ! in_array($name, $upperTargets, true)) {
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

    private function looksLikePeroxideText(string $text): bool
    {
        $normalized = Str::ascii(mb_strtolower($text, 'UTF-8'));

        return preg_match('/\b(?:peroxido|oxidante|revelador|activador|locion\s+activadora|developer)\b/', $normalized) === 1;
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '', 'UTF-8');
    }

    private function linkIdentityTuple(string $accountKey, string $mlm, ?string $variationId): string
    {
        return trim($accountKey).'|'.strtoupper(trim($mlm)).'|'.trim((string) ($variationId ?? ''));
    }

    private function suggestedMasterSku(string $line, ?string $tone): string
    {
        $prefix = match ($line) {
            self::LINE_ROYAL => 'IGORA-ROYAL',
            self::LINE_VIBRANCE => 'IGORA-VIBRANCE',
            self::LINE_COLOR10 => 'IGORA-COLOR10',
            self::LINE_ZERO_AMM => 'IGORA-ZERO',
            default => 'IGORA',
        };

        return $tone !== null ? "{$prefix}-{$tone}" : $prefix;
    }
}
