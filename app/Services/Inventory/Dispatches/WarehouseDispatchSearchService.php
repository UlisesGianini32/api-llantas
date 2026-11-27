<?php

namespace App\Services\Inventory\Dispatches;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Policies\WarehouseDispatchPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseDispatchSearchService
{
    public function __construct(
        private readonly WarehouseDispatchFeatureGuard $guard,
        private readonly WarehouseDispatchPolicy $policy,
    ) {}

    /**
     * Unified scan/search endpoint backing HID gun, CameraBarcodeScanner, and manual lookup.
     *
     * @return array<string, mixed>
     */
    public function search(
        string $query,
        string $mode,
        User $user,
        ?string $brand = null,
        ?string $tone = null,
        ?int $preferredLocationId = null,
    ): array {
        $this->guard->assertEnabled();

        $q = trim($query);
        $brandFilter = trim((string) ($brand ?? ''));
        $toneFilter = trim((string) ($tone ?? ''));
        $normalizedMode = $mode === 'scan' ? 'scan' : 'search';

        if ($normalizedMode === 'scan') {
            return $this->handleScan($q, $user, $preferredLocationId);
        }

        return $this->handleManualSearch($q, $brandFilter, $toneFilter, $user, $preferredLocationId);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleScan(string $rawCode, User $user, ?int $preferredLocationId): array
    {
        if ($rawCode === '') {
            return [
                'mode' => 'scan',
                'match_status' => 'NOT_FOUND',
                'query' => '',
                'item' => null,
                'candidates' => [],
                'registered' => [],
                'provisional' => [],
            ];
        }

        $variants = $this->codeVariants($rawCode);

        $matches = InventoryProduct::query()
            ->with(['primaryLocation:id,code,name', 'secondaryLocation:id,code,name'])
            ->where(function ($builder) use ($variants): void {
                foreach ($variants as $variant) {
                    $builder->orWhere('barcode', $variant)
                        ->orWhere('barcode_secondary', $variant)
                        ->orWhere('sku', $variant);
                }
            })
            ->orderBy('id')
            ->limit(25)
            ->get();

        if ($matches->count() > 1) {
            $hydrated = $this->hydrateRegisteredProducts($matches, $user, $preferredLocationId);

            return [
                'mode' => 'scan',
                'match_status' => 'AMBIGUOUS',
                'message' => 'Se encontraron múltiples productos coincidentes para el código escaneado. Selecciona manualmente o captura como provisional.',
                'query' => $rawCode,
                'item' => null,
                'candidates' => $hydrated,
                'registered' => $hydrated,
                'provisional' => [],
            ];
        }

        if ($matches->count() === 1) {
            /** @var InventoryProduct $product */
            $product = $matches->first();

            if ($product->isKit() || $product->isBundleOrKit()) {
                return [
                    'mode' => 'scan',
                    'match_status' => 'BLOCKED_KIT',
                    'message' => "El producto '{$product->name}' ({$product->sku}) es un kit o paquete. En Fase 1 debes capturar las piezas físicas individuales.",
                    'query' => $rawCode,
                    'item' => [
                        'id' => (int) $product->getKey(),
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'product_type' => $product->product_type,
                    ],
                    'candidates' => [],
                    'registered' => [],
                    'provisional' => [],
                ];
            }

            $hydrated = $this->hydrateRegisteredProducts($matches, $user, $preferredLocationId);
            $item = $hydrated[0];

            if (! $product->is_active) {
                return [
                    'mode' => 'scan',
                    'match_status' => 'INACTIVE_PRODUCT',
                    'message' => "El producto '{$product->name}' ({$product->sku}) está inactivo / en alta controlada. Puede documentarse, pero no descontará inventario automáticamente.",
                    'query' => $rawCode,
                    'item' => $item,
                    'candidates' => $hydrated,
                    'registered' => $hydrated,
                    'provisional' => [],
                ];
            }

            return [
                'mode' => 'scan',
                'match_status' => 'EXACT_REGISTERED',
                'message' => null,
                'query' => $rawCode,
                'item' => $item,
                'candidates' => [],
                'registered' => $hydrated,
                'provisional' => [],
            ];
        }

        // Not found in master catalog -> check active provisional items
        $provisionalMatches = InventoryProvisionalItem::query()
            ->whereIn('status', [
                InventoryProvisionalItem::STATUS_PENDING_LINK,
                InventoryProvisionalItem::STATUS_AMBIGUOUS_REVIEW,
            ])
            ->where(function ($builder) use ($variants): void {
                foreach ($variants as $variant) {
                    $builder->orWhere('barcode', $variant)
                        ->orWhere('known_sku', $variant);
                }
            })
            ->orderBy('id')
            ->limit(15)
            ->get();

        $presentedProvisional = $provisionalMatches
            ->map(fn (InventoryProvisionalItem $item): array => $this->presentProvisionalSearchResult($item))
            ->values()
            ->all();

        if ($provisionalMatches->count() === 1) {
            return [
                'mode' => 'scan',
                'match_status' => 'EXACT_PROVISIONAL',
                'message' => 'Se encontró una ficha provisional existente para este código.',
                'query' => $rawCode,
                'item' => $presentedProvisional[0],
                'candidates' => [],
                'registered' => [],
                'provisional' => $presentedProvisional,
            ];
        }

        if ($provisionalMatches->count() > 1) {
            return [
                'mode' => 'scan',
                'match_status' => 'AMBIGUOUS_PROVISIONAL',
                'message' => 'Existen varias fichas provisionales con este código. Selecciona cuál reutilizar o captura una nueva.',
                'query' => $rawCode,
                'item' => null,
                'candidates' => $presentedProvisional,
                'registered' => [],
                'provisional' => $presentedProvisional,
            ];
        }

        return [
            'mode' => 'scan',
            'match_status' => 'NOT_FOUND',
            'message' => 'Código no registrado en catálogo ni en fichas provisionales. Puedes registrarlo como partida provisional.',
            'query' => $rawCode,
            'item' => null,
            'candidates' => [],
            'registered' => [],
            'provisional' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleManualSearch(
        string $query,
        string $brand,
        string $tone,
        User $user,
        ?int $preferredLocationId,
    ): array {
        $tokens = $this->tokenize($query);
        $toneVariants = $tone !== '' ? $this->toneVariants($tone) : [];

        $registeredQuery = InventoryProduct::query()
            ->with(['primaryLocation:id,code,name', 'secondaryLocation:id,code,name'])
            ->where('product_type', InventoryProduct::SIMPLE);

        if ($brand !== '') {
            $registeredQuery->where('brand', 'like', "%{$brand}%");
        }

        if ($toneVariants !== []) {
            $registeredQuery->where(function ($builder) use ($toneVariants): void {
                foreach ($toneVariants as $variant) {
                    $builder->orWhere('name', 'like', "%{$variant}%")
                        ->orWhere('sku', 'like', "%{$variant}%");
                }
            });
        }

        foreach ($tokens as $token) {
            $tokenVariants = $this->toneVariants($token);
            $registeredQuery->where(function ($builder) use ($tokenVariants): void {
                foreach ($tokenVariants as $variant) {
                    $builder->orWhere('name', 'like', "%{$variant}%")
                        ->orWhere('sku', 'like', "%{$variant}%")
                        ->orWhere('barcode', 'like', "%{$variant}%")
                        ->orWhere('barcode_secondary', 'like', "%{$variant}%")
                        ->orWhere('brand', 'like', "%{$variant}%");
                }
            });
        }

        // Prioritize active items first while keeping inactive items visible when explicitly matched
        $rawProducts = $registeredQuery
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->filter(fn (InventoryProduct $product): bool => ! $product->isBundleOrKit())
            ->take(25)
            ->values();

        $registered = $this->hydrateRegisteredProducts($rawProducts, $user, $preferredLocationId);

        $provisionalQuery = InventoryProvisionalItem::query()
            ->whereIn('status', [
                InventoryProvisionalItem::STATUS_PENDING_LINK,
                InventoryProvisionalItem::STATUS_AMBIGUOUS_REVIEW,
            ]);

        if ($brand !== '') {
            $provisionalQuery->where('brand', 'like', "%{$brand}%");
        }

        if ($toneVariants !== []) {
            $provisionalQuery->where(function ($builder) use ($toneVariants): void {
                foreach ($toneVariants as $variant) {
                    $builder->orWhere('tone', 'like', "%{$variant}%")
                        ->orWhere('name', 'like', "%{$variant}%");
                }
            });
        }

        foreach ($tokens as $token) {
            $tokenVariants = $this->toneVariants($token);
            $provisionalQuery->where(function ($builder) use ($tokenVariants): void {
                foreach ($tokenVariants as $variant) {
                    $builder->orWhere('name', 'like', "%{$variant}%")
                        ->orWhere('provisional_code', 'like', "%{$variant}%")
                        ->orWhere('barcode', 'like', "%{$variant}%")
                        ->orWhere('known_sku', 'like', "%{$variant}%")
                        ->orWhere('brand', 'like', "%{$variant}%")
                        ->orWhere('line', 'like', "%{$variant}%")
                        ->orWhere('tone', 'like', "%{$variant}%");
                }
            });
        }

        $provisional = $provisionalQuery
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (InventoryProvisionalItem $item): array => $this->presentProvisionalSearchResult($item))
            ->values()
            ->all();

        return [
            'mode' => 'search',
            'match_status' => 'SEARCH_RESULTS',
            'query' => $query,
            'registered' => $registered,
            'provisional' => $provisional,
        ];
    }

    /**
     * @param  Collection<int, InventoryProduct>  $products
     * @return list<array<string, mixed>>
     */
    private function hydrateRegisteredProducts(
        Collection $products,
        User $user,
        ?int $preferredLocationId,
    ): array {
        if ($products->isEmpty()) {
            return [];
        }

        $productIds = $products->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $activeLocations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'sort_order'])
            ->keyBy('id');

        $physicalByProdLoc = InventoryMovement::query()
            ->select('inventory_product_id', 'inventory_location_id', DB::raw('SUM(quantity) as qty'))
            ->whereIn('inventory_product_id', $productIds)
            ->groupBy('inventory_product_id', 'inventory_location_id')
            ->get();

        $physicalMap = [];
        $globalPhysicalMap = [];
        foreach ($physicalByProdLoc as $row) {
            $pid = (int) $row->inventory_product_id;
            $lid = (int) $row->inventory_location_id;
            $qty = (int) $row->qty;
            $physicalMap[$pid][$lid] = $qty;
            $globalPhysicalMap[$pid] = ($globalPhysicalMap[$pid] ?? 0) + $qty;
        }

        $activeReservations = InventoryReservation::query()
            ->select('inventory_product_id', 'inventory_location_id', DB::raw('SUM(quantity) as qty'))
            ->active()
            ->whereIn('inventory_product_id', $productIds)
            ->groupBy('inventory_product_id', 'inventory_location_id')
            ->get();

        $locReservedMap = [];
        $unlocatedReservedMap = [];
        $globalReservedMap = [];
        foreach ($activeReservations as $row) {
            $pid = (int) $row->inventory_product_id;
            $qty = (int) $row->qty;
            $globalReservedMap[$pid] = ($globalReservedMap[$pid] ?? 0) + $qty;
            if ($row->inventory_location_id === null) {
                $unlocatedReservedMap[$pid] = ($unlocatedReservedMap[$pid] ?? 0) + $qty;
            } else {
                $lid = (int) $row->inventory_location_id;
                $locReservedMap[$pid][$lid] = $qty;
            }
        }

        $canViewCosts = $this->policy->viewCosts($user);
        $canViewStylist = $this->policy->viewStylistPrices($user);

        return $products->map(function (InventoryProduct $product) use (
            $activeLocations,
            $physicalMap,
            $globalPhysicalMap,
            $locReservedMap,
            $unlocatedReservedMap,
            $globalReservedMap,
            $preferredLocationId,
            $canViewCosts,
            $canViewStylist
        ): array {
            $pid = (int) $product->getKey();
            $unlocatedReserved = $unlocatedReservedMap[$pid] ?? 0;
            $globalPhysical = $globalPhysicalMap[$pid] ?? 0;
            $globalReserved = $globalReservedMap[$pid] ?? 0;
            $globalAvailable = $globalPhysical - $globalReserved;

            $locationStocks = [];
            $candidateLocationIds = array_unique(array_filter([
                $product->primary_location_id ? (int) $product->primary_location_id : null,
                $product->secondary_location_id ? (int) $product->secondary_location_id : null,
                $preferredLocationId,
                ...array_keys($physicalMap[$pid] ?? []),
            ]));

            foreach ($candidateLocationIds as $lid) {
                $loc = $activeLocations->get($lid);
                if (! $loc) {
                    continue;
                }
                $phys = $physicalMap[$pid][$lid] ?? 0;
                $res = ($locReservedMap[$pid][$lid] ?? 0) + $unlocatedReserved;
                $avail = $phys - $res;

                $locationStocks[] = [
                    'inventory_location_id' => (int) $loc->id,
                    'code' => (string) $loc->code,
                    'name' => $loc->name,
                    'physical' => $phys,
                    'reserved' => $res,
                    'available' => $avail,
                    'is_primary' => (int) $product->primary_location_id === (int) $loc->id,
                ];
            }

            usort($locationStocks, static function (array $a, array $b): int {
                if ($a['available'] !== $b['available']) {
                    return $b['available'] <=> $a['available'];
                }
                if ($a['is_primary'] !== $b['is_primary']) {
                    return $a['is_primary'] ? -1 : 1;
                }

                return strcmp($a['code'], $b['code']);
            });

            $suggestedLocationId = $this->suggestLocationId($product, $locationStocks, $activeLocations, $preferredLocationId);

            $row = [
                'kind' => 'registered',
                'inventory_product_id' => $pid,
                'sku' => (string) $product->sku,
                'barcode' => $product->barcode,
                'barcode_secondary' => $product->barcode_secondary,
                'name' => (string) $product->name,
                'brand' => $product->brand,
                'is_active' => (bool) $product->is_active,
                'primary_location_id' => $product->primary_location_id ? (int) $product->primary_location_id : null,
                'primary_location_code' => $product->primaryLocation?->code,
                'suggested_location_id' => $suggestedLocationId,
                'physical_global' => $globalPhysical,
                'reserved_global' => $globalReserved,
                'unlocated_reserved' => $unlocatedReserved,
                'available_global' => $globalAvailable,
                'location_stocks' => $locationStocks,
            ];

            if ($canViewCosts) {
                $row['cost'] = $product->cost !== null ? (float) $product->cost : null;
            }
            if ($canViewStylist) {
                $row['price_stylist'] = $product->price_stylist !== null ? (float) $product->price_stylist : null;
            }

            return $row;
        })->values()->all();
    }

    /**
     * Deterministic suggestion:
     * 1. Product primary_location_id if active and available > 0
     * 2. Active location with highest available > 0
     * 3. Product primary_location_id if active
     * 4. Preferred header location if active
     * 5. null
     *
     * @param  list<array<string, mixed>>  $locationStocks
     * @param  Collection<int, InventoryLocation>  $activeLocations
     */
    private function suggestLocationId(
        InventoryProduct $product,
        array $locationStocks,
        Collection $activeLocations,
        ?int $preferredLocationId,
    ): ?int {
        $primaryId = $product->primary_location_id ? (int) $product->primary_location_id : null;

        if ($primaryId !== null && $activeLocations->has($primaryId)) {
            foreach ($locationStocks as $ls) {
                if ($ls['inventory_location_id'] === $primaryId && $ls['available'] > 0) {
                    return $primaryId;
                }
            }
        }

        foreach ($locationStocks as $ls) {
            if ($ls['available'] > 0) {
                return (int) $ls['inventory_location_id'];
            }
        }

        if ($primaryId !== null && $activeLocations->has($primaryId)) {
            return $primaryId;
        }

        if ($preferredLocationId !== null && $activeLocations->has($preferredLocationId)) {
            return $preferredLocationId;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentProvisionalSearchResult(InventoryProvisionalItem $item): array
    {
        return [
            'kind' => 'provisional',
            'provisional_item_id' => (int) $item->getKey(),
            'provisional_code' => (string) $item->provisional_code,
            'barcode' => $item->barcode,
            'known_sku' => $item->known_sku,
            'name' => (string) $item->name,
            'brand' => $item->brand,
            'line' => $item->line,
            'tone' => $item->tone,
            'presentation' => $item->presentation,
            'unit_of_measure' => (string) $item->unit_of_measure,
            'default_units_per_container' => $item->default_units_per_container,
            'status' => (string) $item->status,
        ];
    }

    /**
     * @return list<string>
     */
    private function codeVariants(string $raw): array
    {
        $clean = trim($raw);
        $variants = [$clean];
        if (ctype_digit($clean) && strlen($clean) >= 6) {
            $trimmed = ltrim($clean, '0');
            if ($trimmed !== '' && $trimmed !== $clean) {
                $variants[] = $trimmed;
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $query) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $t): bool => $t !== ''));
    }

    /**
     * @return list<string>
     */
    private function toneVariants(string $token): array
    {
        $variants = [$token];
        if (str_contains($token, '/')) {
            $variants[] = str_replace('/', '-', $token);
        } elseif (str_contains($token, '-')) {
            $variants[] = str_replace('-', '/', $token);
        }

        return array_values(array_unique($variants));
    }
}
