<?php

namespace App\Services\Inventory\Igora;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Servicio estrictamente READ-ONLY para previsualizar y auditar la propuesta de alta
 * de seis productos maestros SIMPLE de Schwarzkopf Igora Royal en Inventory*.
 *
 * Restricciones de seguridad:
 * - NO crea registros en inventory_products.
 * - NO inserta movimientos INITIAL en inventory_movements.
 * - NO crea ni modifica inventory_channel_links ni componentes BOM.
 * - NO habilita stock_sync_enabled ni order_reservation_enabled.
 * - NO realiza llamadas HTTP a Mercado Libre ni usa snapshots antiguos (2026-10-27) como stock físico.
 * - NO inventa costos, precios, proveedores ni cantidades disponibles para canales.
 */
class IgoraMasterBootstrapPreviewService
{
    public const PROPOSAL_ID = 'IGORA-ROYAL-MASTER-BOOTSTRAP-2026-11-19';

    public const PHYSICAL_COUNT_DATE = '2026-11-19';

    public const MELI_SNAPSHOT_REFERENCE_DATE = '2026-10-27';

    public const EXPECTED_PRODUCT_COUNT = 6;

    public const EXPECTED_PHYSICAL_UNITS = 89;

    public const BRAND = 'Schwarzkopf';

    public const LINE = 'Igora Royal';

    public const PRESENTATION = 'Tubo individual 60 ml (sin oxidante ni componentes)';

    public const STATUS_READY = 'READY_FOR_MANUAL_APPROVAL';

    public const STATUS_DUPLICATE = 'DUPLICATE_OR_EXISTING_PRODUCT';

    public const STATUS_LOCATION_ERROR = 'LOCATION_ERROR';

    public const STATUS_MANUAL_REVIEW = 'MANUAL_REVIEW_REQUIRED';

    /**
     * Ubicaciones físicas confirmadas el 19 de noviembre de 2026.
     *
     * @var array<int, string>
     */
    public const EXPECTED_LOCATIONS = [
        342 => 'D3-7',
        343 => 'D3-8',
        351 => 'D4-7',
        361 => 'D5-8',
    ];

    /**
     * Propuesta auditada de los 6 productos maestros SIMPLE (6 productos / 89 unidades físicas).
     * Los campos de costo, proveedor, precios y cantidades de canal permanecen intencionalmente sin inventar.
     *
     * @return list<array<string, mixed>>
     */
    public function auditedProposalItems(): array
    {
        $items = [
            [
                'tone' => '4-13',
                'sku' => '7702045538977',
                'barcode' => '7702045538977',
                'physical_count' => 11,
                'location_id' => 342,
                'location_code' => 'D3-7',
                'audit_mlm' => 'MLM2603516345',
            ],
            [
                'tone' => '4-46',
                'sku' => '7702045552393',
                'barcode' => '7702045552393',
                'physical_count' => 12,
                'location_id' => 342,
                'location_code' => 'D3-7',
                'audit_mlm' => 'MLM2603567103',
            ],
            [
                'tone' => '4-63',
                'sku' => '7702045538946',
                'barcode' => '7702045538946',
                'physical_count' => 14,
                'location_id' => 351,
                'location_code' => 'D4-7',
                'audit_mlm' => 'MLM4465266682',
            ],
            [
                'tone' => '7-24',
                'sku' => '7702045119718',
                'barcode' => '7702045119718',
                'physical_count' => 10,
                'location_id' => 343,
                'location_code' => 'D3-8',
                'audit_mlm' => 'MLM2603541111',
            ],
            [
                'tone' => '7-50',
                'sku' => '7702045548860',
                'barcode' => '7702045548860',
                'physical_count' => 28,
                'location_id' => 343,
                'location_code' => 'D3-8',
                'audit_mlm' => 'MLM2603554113',
            ],
            [
                'tone' => '8-50',
                'sku' => '7702045548877',
                'barcode' => '7702045548877',
                'physical_count' => 14,
                'location_id' => 361,
                'location_code' => 'D5-8',
                'audit_mlm' => 'MLM2603516353',
            ],
        ];

        return array_map(function (array $item): array {
            $proposedName = sprintf('Tinte %s %s %s 60 ml', self::BRAND, self::LINE, $item['tone']);

            return [
                ...$item,
                'proposed_name' => $proposedName,
                'product_type' => InventoryProduct::SIMPLE,
                'brand' => self::BRAND,
                'line' => self::LINE,
                'presentation' => self::PRESENTATION,
                'includes_oxidant' => false,
                'count_date' => self::PHYSICAL_COUNT_DATE,
                'proposed_product_payload' => [
                    'sku' => $item['sku'],
                    'barcode' => $item['barcode'],
                    'barcode_secondary' => null,
                    'name' => $proposedName,
                    'brand' => self::BRAND,
                    'product_type' => InventoryProduct::SIMPLE,
                    'primary_location_id' => $item['location_id'],
                    'is_active' => true,
                    // Campos deliberadamente omitidos / sin inventar:
                    'cost' => null,
                    'supplier' => null,
                    'price_mercado_libre' => null,
                    'price_amazon' => null,
                    'price_stylist' => null,
                    'price_public' => null,
                ],
                'proposed_initial_movement_payload' => [
                    'type' => InventoryMovement::INITIAL,
                    'quantity' => $item['physical_count'],
                    'inventory_location_id' => $item['location_id'],
                    'occurred_at' => self::PHYSICAL_COUNT_DATE,
                    'reference' => self::PROPOSAL_ID,
                    'notes' => 'Propuesta read-only (sin escritura): reconfirmar conteo físico del 2026-11-19 frente a ventas, reservas y movimientos posteriores antes del alta real.',
                ],
            ];
        }, $items);
    }

    /**
     * Ejecuta la auditoría y vista previa read-only completa contra las tablas reales de Inventory*.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $proposalItems = $this->auditedProposalItems();
        $locationChecks = $this->validateExpectedLocations();
        $existingProducts = $this->loadCandidateInventoryProducts($proposalItems);
        $existingLinks = $this->loadRelevantChannelLinks($proposalItems, $existingProducts);
        $existingMovements = $this->loadRelevantMovements($proposalItems, $existingProducts);

        $rows = [];
        foreach ($proposalItems as $item) {
            $rows[] = $this->evaluateItem($item, $locationChecks, $existingProducts, $existingLinks, $existingMovements);
        }

        $rowCollection = collect($rows);
        $locationCollection = collect($locationChecks);
        $totalUnits = (int) $rowCollection->sum('physical_count');

        $summary = [
            'total_products' => $rowCollection->count(),
            'expected_products' => self::EXPECTED_PRODUCT_COUNT,
            'total_physical_units' => $totalUnits,
            'expected_physical_units' => self::EXPECTED_PHYSICAL_UNITS,
            'totals_match_expected' => $rowCollection->count() === self::EXPECTED_PRODUCT_COUNT
                && $totalUnits === self::EXPECTED_PHYSICAL_UNITS,
            'locations_expected' => $locationCollection->count(),
            'locations_valid' => $locationCollection->where('is_valid', true)->count(),
            'locations_invalid' => $locationCollection->where('is_valid', false)->count(),
            'ready_for_manual_approval' => $rowCollection->where('status', self::STATUS_READY)->count(),
            'duplicate_or_existing_product' => $rowCollection->where('status', self::STATUS_DUPLICATE)->count(),
            'location_error' => $rowCollection->where('status', self::STATUS_LOCATION_ERROR)->count(),
            'manual_review_required' => $rowCollection->where('status', self::STATUS_MANUAL_REVIEW)->count(),
            'all_ready_for_manual_approval' => $rowCollection->count() === self::EXPECTED_PRODUCT_COUNT
                && $totalUnits === self::EXPECTED_PHYSICAL_UNITS
                && $locationCollection->every(fn (array $loc): bool => $loc['is_valid'] === true)
                && $rowCollection->every(fn (array $row): bool => $row['status'] === self::STATUS_READY),
        ];

        return [
            'mode' => 'READ_ONLY_PREVIEW',
            'proposal' => [
                'proposal_id' => self::PROPOSAL_ID,
                'physical_count_date' => self::PHYSICAL_COUNT_DATE,
                'meli_snapshot_reference_date' => self::MELI_SNAPSHOT_REFERENCE_DATE,
                'brand' => self::BRAND,
                'line' => self::LINE,
                'presentation' => self::PRESENTATION,
                'product_type' => InventoryProduct::SIMPLE,
                'writes_executed' => false,
                'external_http_calls' => false,
                'uses_remote_meli_stock' => false,
                'invents_commercial_fields' => false,
                'mandatory_pre_execution_checks' => [
                    'Reconfirmar los conteos físicos del 2026-11-19 frente a ventas, reservas activas y movimientos de almacén posteriores al conteo antes de cualquier alta real.',
                    'No utilizar cantidades de snapshots antiguos de Mercado Libre (2026-10-27) como inventario físico.',
                    'No inventar costos, precios de venta, proveedores ni cantidades disponibles para canales durante el alta inicial.',
                    'No habilitar stock_sync_enabled ni order_reservation_enabled ni crear vínculos de canal en esta fase.',
                    'La escritura real de productos maestros y movimientos INITIAL se diseñará y aprobará en un PR separado.',
                ],
            ],
            'locations' => array_values($locationChecks),
            'items' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * Valida existencia, coincidencia exacta de ID/código y estado activo de las 4 ubicaciones esperadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateExpectedLocations(): array
    {
        $expectedIds = array_keys(self::EXPECTED_LOCATIONS);
        $expectedCodes = array_values(self::EXPECTED_LOCATIONS);

        $byId = InventoryLocation::query()
            ->whereIn('id', $expectedIds)
            ->get()
            ->keyBy('id');

        $byCode = InventoryLocation::query()
            ->whereIn('code', $expectedCodes)
            ->get()
            ->keyBy(fn (InventoryLocation $loc): string => $this->normalizeCode($loc->code));

        $results = [];

        foreach (self::EXPECTED_LOCATIONS as $expectedId => $expectedCode) {
            $normalizedExpectedCode = $this->normalizeCode($expectedCode);
            /** @var ?InventoryLocation $foundById */
            $foundById = $byId->get($expectedId);
            /** @var ?InventoryLocation $foundByCode */
            $foundByCode = $byCode->get($normalizedExpectedCode);

            $errors = [];

            if ($foundById === null) {
                $errors[] = 'LOCATION_NOT_FOUND';
                if ($foundByCode !== null && (int) $foundByCode->id !== $expectedId) {
                    $errors[] = sprintf(
                        'LOCATION_CODE_EXISTS_WITH_DIFFERENT_ID:%s->#%d',
                        $expectedCode,
                        (int) $foundByCode->id
                    );
                }
            } else {
                $actualCode = $this->normalizeCode((string) $foundById->code);
                if ($actualCode !== $normalizedExpectedCode) {
                    $errors[] = sprintf(
                        'LOCATION_CODE_MISMATCH:expected=%s,actual=%s',
                        $expectedCode,
                        (string) $foundById->code
                    );
                }
                if (! (bool) $foundById->is_active) {
                    $errors[] = 'LOCATION_INACTIVE';
                }
            }

            $results[$expectedId] = [
                'expected_id' => $expectedId,
                'expected_code' => $expectedCode,
                'exists' => $foundById !== null,
                'actual_id' => $foundById ? (int) $foundById->id : null,
                'actual_code' => $foundById ? (string) $foundById->code : null,
                'actual_name' => $foundById ? (string) ($foundById->name ?? '') : null,
                'is_active' => $foundById ? (bool) $foundById->is_active : false,
                'code_found_at_other_id' => ($foundByCode !== null && (int) $foundByCode->id !== $expectedId)
                    ? (int) $foundByCode->id
                    : null,
                'is_valid' => $errors === [],
                'errors' => $errors,
            ];
        }

        return $results;
    }

    /**
     * Carga únicamente productos relevantes para detectar duplicados exactos (SKU/barcode/barcode_secondary)
     * y posibles coincidencias de identidad por nombre/línea/tono.
     *
     * @param  list<array<string, mixed>>  $proposalItems
     * @return Collection<int, InventoryProduct>
     */
    private function loadCandidateInventoryProducts(array $proposalItems): Collection
    {
        $codes = collect($proposalItems)
            ->flatMap(fn (array $item): array => [
                $item['sku'],
                $item['barcode'],
                ltrim((string) $item['sku'], '0'),
            ])
            ->filter(fn (mixed $v): bool => is_string($v) && trim($v) !== '')
            ->unique()
            ->values()
            ->all();

        return InventoryProduct::query()
            ->select([
                'id',
                'sku',
                'barcode',
                'barcode_secondary',
                'name',
                'brand',
                'product_type',
                'primary_location_id',
                'is_active',
            ])
            ->where(function ($query) use ($codes): void {
                $query->whereIn('sku', $codes)
                    ->orWhereIn('barcode', $codes)
                    ->orWhereIn('barcode_secondary', $codes)
                    ->orWhere('name', 'like', '%igora%')
                    ->orWhere('name', 'like', '%IGORA%')
                    ->orWhere('name', 'like', '%schwarzkopf%')
                    ->orWhere('name', 'like', '%SCHWARZKOPF%')
                    ->orWhere('brand', 'like', '%schwarzkopf%')
                    ->orWhere('brand', 'like', '%SCHWARZKOPF%')
                    ->orWhere('sku', 'like', '%igora%')
                    ->orWhere('sku', 'like', '%IGORA%');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Carga vínculos existentes en inventory_channel_links para los 6 MLM de auditoría
     * o asociados a productos candidatos existentes (solo lectura).
     *
     * @param  list<array<string, mixed>>  $proposalItems
     * @param  Collection<int, InventoryProduct>  $existingProducts
     * @return Collection<int, InventoryChannelLink>
     */
    private function loadRelevantChannelLinks(array $proposalItems, Collection $existingProducts): Collection
    {
        $mlms = collect($proposalItems)->pluck('audit_mlm')->filter()->unique()->values()->all();
        $productIds = $existingProducts->pluck('id')->filter()->unique()->values()->all();

        $columns = [
            'id',
            'inventory_product_id',
            'channel',
            'account_key',
            'external_product_id',
            'external_variant_id',
            'external_listing_id',
            'is_active',
            'stock_sync_enabled',
        ];
        if (Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled')) {
            $columns[] = 'order_reservation_enabled';
        }

        return InventoryChannelLink::query()
            ->select($columns)
            ->where(function ($query) use ($mlms, $productIds): void {
                $query->whereIn('external_listing_id', $mlms)
                    ->orWhereIn('external_product_id', $mlms);

                if ($productIds !== []) {
                    $query->orWhereIn('inventory_product_id', $productIds);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Carga movimientos existentes en inventory_movements relacionados con productos candidatos
     * o con referencias/notas/external_key vinculadas a los 6 SKU/MLM/propuesta.
     *
     * @param  list<array<string, mixed>>  $proposalItems
     * @param  Collection<int, InventoryProduct>  $existingProducts
     * @return Collection<int, InventoryMovement>
     */
    private function loadRelevantMovements(array $proposalItems, Collection $existingProducts): Collection
    {
        $productIds = $existingProducts->pluck('id')->filter()->unique()->values()->all();
        $terms = collect($proposalItems)
            ->flatMap(fn (array $item): array => [$item['sku'], $item['audit_mlm']])
            ->push(self::PROPOSAL_ID)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return InventoryMovement::query()
            ->select([
                'id',
                'inventory_product_id',
                'inventory_location_id',
                'type',
                'quantity',
                'reference',
                'notes',
                'external_key',
                'occurred_at',
            ])
            ->where(function ($query) use ($productIds, $terms): void {
                if ($productIds !== []) {
                    $query->whereIn('inventory_product_id', $productIds);
                }

                foreach ($terms as $term) {
                    $query->orWhere('reference', 'like', '%'.$term.'%')
                        ->orWhere('notes', 'like', '%'.$term.'%')
                        ->orWhere('external_key', 'like', '%'.$term.'%');
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $locationChecks
     * @param  Collection<int, InventoryProduct>  $existingProducts
     * @param  Collection<int, InventoryChannelLink>  $existingLinks
     * @param  Collection<int, InventoryMovement>  $existingMovements
     * @return array<string, mixed>
     */
    private function evaluateItem(
        array $item,
        array $locationChecks,
        Collection $existingProducts,
        Collection $existingLinks,
        Collection $existingMovements,
    ): array {
        $locationCheck = $locationChecks[$item['location_id']] ?? [
            'expected_id' => $item['location_id'],
            'expected_code' => $item['location_code'],
            'is_valid' => false,
            'errors' => ['LOCATION_NOT_FOUND'],
        ];

        $targetCode = $this->normalizeCode((string) $item['sku']);
        $targetCodeTrimZero = ltrim($targetCode, '0');
        $targetTone = (string) $item['tone'];
        $targetMlm = $this->normalizeCode((string) $item['audit_mlm']);

        $exactCodeMatches = [];
        $exactMatchIds = [];

        foreach ($existingProducts as $product) {
            $matchedFields = [];
            foreach (['sku', 'barcode', 'barcode_secondary'] as $field) {
                $val = $this->normalizeCode((string) ($product->{$field} ?? ''));
                if ($val === '') {
                    continue;
                }
                if ($val === $targetCode || ($targetCodeTrimZero !== '' && ltrim($val, '0') === $targetCodeTrimZero)) {
                    $matchedFields[] = $field;
                }
            }

            if ($matchedFields !== []) {
                $exactMatchIds[(int) $product->id] = true;
                $exactCodeMatches[] = $this->formatMatchedProduct($product, $matchedFields, $existingMovements);
            }
        }

        // Posibles coincidencias de identidad por nombre / línea / tono (sin coincidencia exacta de SKU/barcode)
        $identityReviewMatches = [];
        foreach ($existingProducts as $product) {
            if (isset($exactMatchIds[(int) $product->id])) {
                continue;
            }
            if (! $this->isPotentialRoyalIdentityMatch($product, $targetTone)) {
                continue;
            }

            $identityReviewMatches[] = $this->formatMatchedProduct($product, ['name_line_tone'], $existingMovements);
        }

        // Vínculos existentes en inventory_channel_links para el MLM de auditoría
        $mlmLinks = $existingLinks
            ->filter(function (InventoryChannelLink $link) use ($targetMlm): bool {
                $listing = $this->normalizeCode((string) ($link->external_listing_id ?? ''));
                $extProduct = $this->normalizeCode((string) ($link->external_product_id ?? ''));

                return $listing === $targetMlm || $extProduct === $targetMlm;
            })
            ->map(fn (InventoryChannelLink $link): array => [
                'id' => (int) $link->id,
                'inventory_product_id' => (int) $link->inventory_product_id,
                'channel' => (string) $link->channel,
                'account_key' => $link->account_key,
                'external_listing_id' => $link->external_listing_id,
                'external_product_id' => $link->external_product_id,
                'external_variant_id' => $link->external_variant_id,
                'is_active' => (bool) $link->is_active,
                'stock_sync_enabled' => (bool) $link->stock_sync_enabled,
                'order_reservation_enabled' => (bool) $link->order_reservation_enabled,
            ])
            ->values()
            ->all();

        // Movimientos INITIAL y movimientos generales relacionados con productos encontrados o referencias/notas/external_key
        $relatedProductIds = array_values(array_unique(array_merge(
            array_keys($exactMatchIds),
            array_map(fn (array $m): int => (int) $m['id'], $identityReviewMatches),
            array_map(fn (array $l): int => (int) $l['inventory_product_id'], $mlmLinks),
        )));

        $relatedMovements = $existingMovements
            ->filter(function (InventoryMovement $movement) use ($relatedProductIds, $item): bool {
                if (in_array((int) $movement->inventory_product_id, $relatedProductIds, true)) {
                    return true;
                }

                $haystack = Str::ascii(mb_strtolower(implode(' ', array_filter([
                    (string) ($movement->reference ?? ''),
                    (string) ($movement->notes ?? ''),
                    (string) ($movement->external_key ?? ''),
                ])), 'UTF-8'));

                if (str_contains($haystack, mb_strtolower((string) $item['sku'], 'UTF-8'))
                    || str_contains($haystack, mb_strtolower((string) $item['audit_mlm'], 'UTF-8'))) {
                    return true;
                }

                // Si existe un movimiento que referencia directamente este PROPOSAL_ID sin producto vinculado,
                // se marca para revisión manual cuando menciona el tono o la ubicación del ítem.
                $proposalNeedle = mb_strtolower(self::PROPOSAL_ID, 'UTF-8');
                if (str_contains($haystack, $proposalNeedle)) {
                    $mentionsTone = str_contains($haystack, mb_strtolower((string) $item['tone'], 'UTF-8'));
                    $mentionsSameLocation = (int) ($movement->inventory_location_id ?? 0) === (int) $item['location_id'];

                    return $mentionsTone || $mentionsSameLocation;
                }

                return false;
            })
            ->values();

        $initialMovements = $relatedMovements
            ->where('type', InventoryMovement::INITIAL)
            ->map(fn (InventoryMovement $m): array => [
                'id' => (int) $m->id,
                'inventory_product_id' => (int) $m->inventory_product_id,
                'inventory_location_id' => $m->inventory_location_id ? (int) $m->inventory_location_id : null,
                'type' => (string) $m->type,
                'quantity' => (int) $m->quantity,
                'reference' => $m->reference,
                'external_key' => $m->external_key,
                'occurred_at' => $m->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $reasons = [];

        if (! $locationCheck['is_valid']) {
            $reasons = array_merge($reasons, $locationCheck['errors']);
        }
        if ($exactCodeMatches !== []) {
            $reasons[] = 'DUPLICATE_SKU_OR_BARCODE_IN_INVENTORY';
        }
        if ($identityReviewMatches !== []) {
            $reasons[] = 'POSSIBLE_IDENTITY_MATCH_BY_NAME_LINE_TONE';
        }
        if ($mlmLinks !== []) {
            $reasons[] = 'EXISTING_CHANNEL_LINK_FOR_MLM';
        }
        if ($initialMovements !== []) {
            $reasons[] = 'EXISTING_INITIAL_MOVEMENT_DETECTED';
        }

        // Clasificación estricta en los 4 resultados definidos por el PASO 30
        if (! $locationCheck['is_valid']) {
            $status = self::STATUS_LOCATION_ERROR;
        } elseif ($exactCodeMatches !== []) {
            $status = self::STATUS_DUPLICATE;
        } elseif ($identityReviewMatches !== [] || $mlmLinks !== [] || $initialMovements !== []) {
            $status = self::STATUS_MANUAL_REVIEW;
        } else {
            $status = self::STATUS_READY;
        }

        return [
            'tone' => $item['tone'],
            'proposed_name' => $item['proposed_name'],
            'product_type' => $item['product_type'],
            'brand' => $item['brand'],
            'line' => $item['line'],
            'presentation' => $item['presentation'],
            'includes_oxidant' => $item['includes_oxidant'],
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'physical_count' => $item['physical_count'],
            'count_date' => $item['count_date'],
            'location_id' => $item['location_id'],
            'location_code' => $item['location_code'],
            'audit_mlm' => $item['audit_mlm'],
            'status' => $status,
            'reasons' => array_values(array_unique($reasons)),
            'location_validation' => $locationCheck,
            'exact_code_matches' => $exactCodeMatches,
            'identity_review_matches' => $identityReviewMatches,
            'existing_channel_links' => $mlmLinks,
            'existing_initial_movements' => $initialMovements,
            'existing_movements_count' => $relatedMovements->count(),
            'proposed_product_payload' => $item['proposed_product_payload'],
            'proposed_initial_movement_payload' => $item['proposed_initial_movement_payload'],
        ];
    }

    /**
     * @param  list<string>  $matchedFields
     * @param  Collection<int, InventoryMovement>  $existingMovements
     * @return array<string, mixed>
     */
    private function formatMatchedProduct(
        InventoryProduct $product,
        array $matchedFields,
        Collection $existingMovements,
    ): array {
        $productMovements = $existingMovements->where('inventory_product_id', $product->id);

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'barcode' => $product->barcode,
            'barcode_secondary' => $product->barcode_secondary,
            'name' => (string) $product->name,
            'brand' => $product->brand,
            'product_type' => (string) ($product->product_type ?? InventoryProduct::SIMPLE),
            'primary_location_id' => $product->primary_location_id ? (int) $product->primary_location_id : null,
            'is_active' => (bool) $product->is_active,
            'matched_by' => $matchedFields,
            'ledger_physical_stock' => (int) $productMovements->sum('quantity'),
            'movements_count' => $productMovements->count(),
            'initial_movements_count' => $productMovements->where('type', InventoryMovement::INITIAL)->count(),
        ];
    }

    private function isPotentialRoyalIdentityMatch(InventoryProduct $product, string $targetTone): bool
    {
        $text = Str::ascii(mb_strtolower(trim(implode(' ', array_filter([
            (string) $product->brand,
            (string) $product->name,
            (string) $product->sku,
        ]))), 'UTF-8'));

        if ($text === '') {
            return false;
        }

        // Debe pertenecer al universo Schwarzkopf / Igora
        if (! str_contains($text, 'igora') && ! str_contains($text, 'schwarzkopf')) {
            return false;
        }

        // Excluir explícitamente otras líneas de Igora para evitar falsos positivos
        if (preg_match('/\b(?:vibrance|color\s*10|zero\s*amm|zero\s+amoniaco)\b/', $text) === 1) {
            return false;
        }

        // Excluir peróxidos/oxidantes independientes (sin palabra tinte/coloración)
        if (preg_match('/\b(?:peroxido|oxidante|revelador|activador|oxigenta|oxygenta)\b/', $text) === 1
            && preg_match('/\b(?:tinte|coloracion|crema\s+colorante|tubo)\b/', $text) !== 1) {
            return false;
        }

        $tones = $this->extractTonesFromProduct($product);

        return in_array($targetTone, $tones, true);
    }

    /**
     * Extrae tonos normalizados del nombre y SKU de un producto sin confundir EAN/GTIN ni medidas en ml.
     *
     * @return list<string>
     */
    private function extractTonesFromProduct(InventoryProduct $product): array
    {
        $clean = trim((string) $product->name.' '.(string) $product->sku);
        foreach (array_filter([(string) ($product->barcode ?? ''), (string) ($product->barcode_secondary ?? '')]) as $code) {
            $clean = str_ireplace($code, ' ', $clean);
        }

        $clean = preg_replace([
            '/\b\d{8,14}\b/',
            '/\b\d+(?:[.,]\d+)?\s*(?:ml|g|gr|oz|lt|l)\b/i',
            '/\b(?:10|20|30|40)\s*(?:vol(?:umenes|umen)?|v)\b/i',
        ], ' ', $clean) ?? $clean;

        $found = [];
        if (preg_match_all('/(?<![\dA-Za-z])(0|1|2|3|4|5|6|7|8|9\.5|9|10|11|12)\s*[-\/]\s*(\d{1,3})(?![\dA-Za-z])/', $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $found[$m[1].'-'.$m[2]] = true;
            }
        }

        return array_keys($found);
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '', 'UTF-8');
    }
}
