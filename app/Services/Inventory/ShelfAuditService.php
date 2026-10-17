<?php

namespace App\Services\Inventory;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryShelfAudit;
use App\Models\InventoryShelfAuditItem;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShelfAuditService
{
    public function __construct(
        protected InventoryMovementService $movementService,
    ) {}

    /**
     * Extrae el código base del Anaquel a partir de un código de ubicación.
     * Ejemplo:
     * - 'I4-1', 'I4-2', 'I4-3' -> 'I4'
     * - 'F10-1', 'F10-9'       -> 'F10'
     * - 'A1-1', 'A1-9'         -> 'A1'
     * - 'G1-1'                 -> 'G1'
     * - 'MOSTRADOR'            -> 'MOSTRADOR'
     * - 'AMZ-A'                -> 'AMZ-A'
     */
    public static function extractShelfCode(string $locationCode): string
    {
        $code = trim($locationCode);
        if ($code === '') {
            return 'GENERAL';
        }

        // Detecta letra(s) seguidas de número(s), ej. I4-1 -> I4, F10-2 -> F10, A1-1 -> A1
        if (preg_match('/^([A-Za-z]+)-?([0-9]+)(?:-.*)?$/i', $code, $m)) {
            return strtoupper($m[1] . $m[2]);
        }

        return strtoupper($code);
    }

    /**
     * Devuelve la lista de Anaqueles únicos agrupados por su código base (ej: I4, F10, A1, MOSTRADOR).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getShelves(): Collection
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        $grouped = $locations->groupBy(fn (InventoryLocation $loc) => self::extractShelfCode($loc->code));

        $shelves = [];

        foreach ($grouped as $shelfCode => $shelfLocations) {
            /** @var Collection<int, InventoryLocation> $shelfLocations */
            $primaryLoc = $shelfLocations->first();
            $subCodes = $shelfLocations->pluck('code')->all();
            $subCount = count($subCodes);

            // Generar etiqueta descriptiva
            if ($subCount > 1) {
                $firstCode = $subCodes[0];
                $lastCode = end($subCodes);
                $range = "{$firstCode} al {$lastCode}";
                $label = "{$shelfCode} — {$primaryLoc->name} ({$subCount} casillas: {$range})";
            } else {
                $label = "{$shelfCode} — {$primaryLoc->name}";
            }

            $shelves[] = [
                'shelf_code' => $shelfCode,
                'name' => $primaryLoc->name,
                'display_label' => $label,
                'primary_location_id' => $primaryLoc->id,
                'sub_locations_count' => $subCount,
                'sub_locations' => $subCodes,
                'location_ids' => $shelfLocations->pluck('id')->all(),
            ];
        }

        // Ordenamiento natural (A1, A2, ..., A10, B1, ..., F9, F10, ..., I4, I5)
        usort($shelves, function ($a, $b) {
            return strnatcasecmp($a['shelf_code'], $b['shelf_code']);
        });

        return collect($shelves);
    }

    /**
     * Devuelve la lista de Marcas únicas que tienen productos en el catálogo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBrands(): Collection
    {
        $rows = InventoryProduct::query()
            ->where('is_active', true)
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->select('brand')
            ->selectRaw('COUNT(*) as products_count')
            ->groupBy('brand')
            ->orderBy('brand')
            ->get();

        return $rows->map(function ($row) {
            $brand = (string) $row->brand;
            $count = (int) $row->products_count;

            return [
                'brand' => $brand,
                'name' => $brand,
                'products_count' => $count,
                'display_label' => "{$brand} ({$count} productos)",
            ];
        });
    }

    /**
     * Devuelve la lista de productos esperados para una Marca específica en todo el almacén.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBrandExpectedProducts(string $brandName): Collection
    {
        $cleanBrand = trim($brandName);
        if ($cleanBrand === '') {
            return collect([]);
        }

        $products = InventoryProduct::query()
            ->with('primaryLocation:id,code,name')
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->where('brand', $cleanBrand)
            ->orderBy('name')
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'cost', 'primary_location_id']);

        if ($products->isEmpty()) {
            return collect([]);
        }

        $productIds = $products->pluck('id')->all();

        // 1. Stock global por producto y desglose de ubicaciones
        $movementRows = InventoryMovement::query()
            ->with('location:id,code,name')
            ->select(['inventory_product_id', 'inventory_location_id'])
            ->selectRaw('COALESCE(SUM(quantity), 0) as stock')
            ->whereIn('inventory_product_id', $productIds)
            ->groupBy('inventory_product_id', 'inventory_location_id')
            ->having('stock', '>', 0)
            ->get();

        $stockByProduct = [];
        $breakdownByProduct = [];

        foreach ($movementRows as $row) {
            $pId = (int) $row->inventory_product_id;
            $stock = (int) $row->stock;
            $stockByProduct[$pId] = ($stockByProduct[$pId] ?? 0) + $stock;
            $locCode = $row->location?->code ?? "Ubic #{$row->inventory_location_id}";
            $breakdownByProduct[$pId][] = "{$stock} en {$locCode}";
        }

        return $products->map(function (InventoryProduct $product) use ($stockByProduct, $breakdownByProduct, $cleanBrand) {
            $totalStock = (int) ($stockByProduct[$product->id] ?? 0);
            $breakdown = ! empty($breakdownByProduct[$product->id])
                ? implode(', ', $breakdownByProduct[$product->id])
                : ($product->primaryLocation ? "Oficial: {$product->primaryLocation->code} (sin stock)" : 'Sin ubicación asignada');

            return [
                'id' => $product->id,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'barcode_secondary' => $product->barcode_secondary,
                'name' => $product->name,
                'brand' => $product->brand,
                'cost' => (float) ($product->cost ?? 0),
                'shelf_code' => null,
                'brand_name' => $cleanBrand,
                'system_stock' => $totalStock,
                'system_quantity' => $totalStock,
                'location_breakdown' => $breakdown,
                'primary_location_id' => $product->primary_location_id,
                'primary_location' => $product->primaryLocation?->code,
                'primary_location_code' => $product->primaryLocation?->code,
                'is_assigned_location' => true,
            ];
        })->keyBy('id');
    }


    /**
     * Obtiene todas las sub-ubicaciones que pertenecen a un Anaquel.
     *
     * @return Collection<int, InventoryLocation>
     */
    public function getLocationsForShelf(string|int $shelfIdentifier): Collection
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->get();

        if (is_numeric($shelfIdentifier)) {
            $targetLoc = $locations->firstWhere('id', (int) $shelfIdentifier);
            $shelfCode = $targetLoc ? self::extractShelfCode($targetLoc->code) : (string) $shelfIdentifier;
        } else {
            $shelfCode = self::extractShelfCode((string) $shelfIdentifier);
        }

        $matched = $locations->filter(function (InventoryLocation $loc) use ($shelfCode) {
            return self::extractShelfCode($loc->code) === $shelfCode;
        });

        if ($matched->isEmpty()) {
            // Intentar por coincidencia exacta de código o ID
            $fallback = $locations->filter(function (InventoryLocation $loc) use ($shelfIdentifier) {
                return (string) $loc->id === (string) $shelfIdentifier || strtoupper($loc->code) === strtoupper((string) $shelfIdentifier);
            });

            if ($fallback->isNotEmpty()) {
                return $fallback;
            }

            if (is_numeric($shelfIdentifier)) {
                return collect([InventoryLocation::findOrFail((int) $shelfIdentifier)]);
            }
        }

        return $matched->sortBy('code', SORT_NATURAL);
    }

    /**
     * Devuelve la lista de productos esperados en un Anaquel completo (incluyendo todas sus sub-casillas: I4-1, I4-2, etc.)
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getShelfExpectedProducts(string|int $shelfIdentifier): Collection
    {
        $locations = $this->getLocationsForShelf($shelfIdentifier);
        if ($locations->isEmpty()) {
            return collect([]);
        }

        $locationIds = $locations->pluck('id')->all();
        $locationMap = $locations->keyBy('id');
        $shelfCode = self::extractShelfCode($locations->first()->code);

        // 1. Stock desglosado por producto y ubicación en este anaquel según el ledger
        $movementRows = InventoryMovement::query()
            ->select(['inventory_product_id', 'inventory_location_id'])
            ->selectRaw('COALESCE(SUM(quantity), 0) as stock')
            ->whereIn('inventory_location_id', $locationIds)
            ->groupBy('inventory_product_id', 'inventory_location_id')
            ->get();

        $stockByProduct = [];
        $breakdownByProduct = [];

        foreach ($movementRows as $row) {
            $pId = (int) $row->inventory_product_id;
            $locId = (int) $row->inventory_location_id;
            $stock = (int) $row->stock;

            $stockByProduct[$pId] = ($stockByProduct[$pId] ?? 0) + $stock;

            if ($stock > 0) {
                $locCode = $locationMap[$locId]?->code ?? "Ubic #{$locId}";
                $breakdownByProduct[$pId][] = "{$stock} en {$locCode}";
            }
        }

        // 2. Productos que tienen asignada alguna de las sub-ubicaciones de este anaquel como oficial
        $assignedProductIds = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->whereIn('primary_location_id', $locationIds)
            ->pluck('id')
            ->all();

        // 3. Unir IDs de ambos orígenes
        $allProductIds = array_values(array_unique(array_merge(array_keys($stockByProduct), $assignedProductIds)));

        if (empty($allProductIds)) {
            return collect([]);
        }

        return InventoryProduct::query()
            ->with('primaryLocation:id,code,name')
            ->whereIn('id', $allProductIds)
            ->orderBy('name')
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'cost', 'primary_location_id'])
            ->map(function (InventoryProduct $product) use ($stockByProduct, $breakdownByProduct, $locationIds, $shelfCode) {
                $totalStock = (int) ($stockByProduct[$product->id] ?? 0);
                $isAssigned = in_array((int) $product->primary_location_id, $locationIds, true);
                $breakdown = ! empty($breakdownByProduct[$product->id])
                    ? implode(', ', $breakdownByProduct[$product->id])
                    : ($totalStock > 0 ? "{$totalStock} en {$shelfCode}" : 'Sin stock previo');

                return [
                    'id' => $product->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'barcode_secondary' => $product->barcode_secondary,
                    'name' => $product->name,
                    'brand' => $product->brand,
                    'cost' => (float) ($product->cost ?? 0),
                    'shelf_code' => $shelfCode,
                    'system_stock' => $totalStock,
                    'system_quantity' => $totalStock,
                    'location_breakdown' => $breakdown,
                    'primary_location_id' => $product->primary_location_id,
                    'primary_location' => $product->primaryLocation?->code,
                    'primary_location_code' => $product->primaryLocation?->code,
                    'is_assigned_location' => $isAssigned,
                ];
            })
            ->keyBy('id');
    }

    /**
     * Crea un registro de auditoría (por Anaquel o por Marca, Borrador o Aplicado de inmediato).
     *
     * @param array<string, mixed> $data
     */
    public function createAudit(array $data, User $user, bool $applyImmediately = false): InventoryShelfAudit
    {
        $auditType = $data['audit_type'] ?? InventoryShelfAudit::AUDIT_TYPE_SHELF;
        if ($auditType === InventoryShelfAudit::AUDIT_TYPE_BRAND) {
            return $this->createBrandAudit($data, $user, $applyImmediately);
        }

        $shelfIdentifier = $data['shelf_code'] ?? ($data['inventory_location_id'] ?? null);
        if (! $shelfIdentifier) {
            throw new InvalidArgumentException('Debes indicar el anaquel a auditar.');
        }

        $locations = $this->getLocationsForShelf($shelfIdentifier);
        if ($locations->isEmpty()) {
            throw new InvalidArgumentException("No se encontraron ubicaciones para el anaquel '{$shelfIdentifier}'.");
        }

        $primaryLocation = $locations->first();
        $shelfCode = self::extractShelfCode($primaryLocation->code);

        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('Debes indicar al menos un producto a auditar.');
        }

        return DB::transaction(function () use ($locations, $primaryLocation, $shelfCode, $items, $data, $user, $applyImmediately) {
            $auditNumber = $this->generateAuditNumber($shelfCode);
            $locationIds = $locations->pluck('id')->all();

            $totalExpected = 0;
            $totalCountedProducts = 0;
            $totalSystemUnits = 0;
            $totalCountedUnits = 0;
            $totalDifferenceUnits = 0;
            $matchedCount = 0;
            $surplusCount = 0;
            $missingCount = 0;

            $preparedItems = [];

            foreach ($items as $item) {
                $productId = (int) ($item['inventory_product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $product = InventoryProduct::findOrFail($productId);
                $systemQty = (int) ($item['system_quantity'] ?? 0);
                $countedQty = max(0, (int) ($item['counted_quantity'] ?? 0));
                $diff = $countedQty - $systemQty;

                $totalExpected++;
                if ($countedQty > 0) {
                    $totalCountedProducts++;
                }

                $totalSystemUnits += $systemQty;
                $totalCountedUnits += $countedQty;
                $totalDifferenceUnits += $diff;

                if ($diff === 0) {
                    $matchedCount++;
                } elseif ($diff > 0) {
                    $surplusCount++;
                } else {
                    $missingCount++;
                }

                $isAssigned = in_array((int) $product->primary_location_id, $locationIds, true);

                $preparedItems[] = [
                    'inventory_product_id' => $product->id,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'difference' => $diff,
                    'location_breakdown' => ! empty($item['location_breakdown']) ? trim((string) $item['location_breakdown']) : null,
                    'is_assigned_location' => $isAssigned,
                    'notes' => ! empty($item['notes']) ? trim((string) $item['notes']) : null,
                    'adjusted' => false,
                ];
            }

            $audit = InventoryShelfAudit::create([
                'audit_number' => $auditNumber,
                'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
                'brand_name' => null,
                'shelf_code' => $shelfCode,
                'inventory_location_id' => $primaryLocation->id,
                'user_id' => $user->id,
                'status' => InventoryShelfAudit::STATUS_IN_PROGRESS,
                'total_expected_products' => $totalExpected,
                'total_counted_products' => $totalCountedProducts,
                'total_system_units' => $totalSystemUnits,
                'total_counted_units' => $totalCountedUnits,
                'total_difference_units' => $totalDifferenceUnits,
                'matched_items_count' => $matchedCount,
                'surplus_items_count' => $surplusCount,
                'missing_items_count' => $missingCount,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
            ]);

            foreach ($preparedItems as $prepItem) {
                $audit->items()->create($prepItem);
            }

            if ($applyImmediately) {
                $this->applyAuditAdjustment($audit, $user, $data['notes'] ?? null);
            }

            return $audit->fresh(['location', 'user', 'appliedBy', 'items.product.primaryLocation']);
        });
    }

    /**
     * Crea un registro de auditoría por Marca (Borrador o Aplicado de inmediato).
     *
     * @param array<string, mixed> $data
     */
    public function createBrandAudit(array $data, User $user, bool $applyImmediately = false): InventoryShelfAudit
    {
        $brandName = trim((string) ($data['brand_name'] ?? ''));
        if ($brandName === '') {
            throw new InvalidArgumentException('Debes indicar la marca a auditar.');
        }

        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('Debes indicar al menos un producto a auditar.');
        }

        $fallbackLocation = InventoryLocation::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
        if (! $fallbackLocation) {
            throw new InvalidArgumentException('No hay ubicaciones de almacén configuradas.');
        }

        return DB::transaction(function () use ($brandName, $fallbackLocation, $items, $data, $user, $applyImmediately) {
            $cleanBrand = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($brandName)) ?: 'MARCA';
            $auditNumber = $this->generateAuditNumber('MARCA-'.$cleanBrand);

            $totalExpected = 0;
            $totalCountedProducts = 0;
            $totalSystemUnits = 0;
            $totalCountedUnits = 0;
            $totalDifferenceUnits = 0;
            $matchedCount = 0;
            $surplusCount = 0;
            $missingCount = 0;

            $preparedItems = [];

            foreach ($items as $item) {
                $productId = (int) ($item['inventory_product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $product = InventoryProduct::findOrFail($productId);
                $systemQty = (int) ($item['system_quantity'] ?? 0);
                $countedQty = max(0, (int) ($item['counted_quantity'] ?? 0));
                $diff = $countedQty - $systemQty;

                $totalExpected++;
                if ($countedQty > 0) {
                    $totalCountedProducts++;
                }

                $totalSystemUnits += $systemQty;
                $totalCountedUnits += $countedQty;
                $totalDifferenceUnits += $diff;

                if ($diff === 0) {
                    $matchedCount++;
                } elseif ($diff > 0) {
                    $surplusCount++;
                } else {
                    $missingCount++;
                }

                $preparedItems[] = [
                    'inventory_product_id' => $product->id,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'difference' => $diff,
                    'location_breakdown' => ! empty($item['location_breakdown']) ? trim((string) $item['location_breakdown']) : null,
                    'is_assigned_location' => true,
                    'notes' => ! empty($item['notes']) ? trim((string) $item['notes']) : null,
                    'adjusted' => false,
                ];
            }

            $audit = InventoryShelfAudit::create([
                'audit_number' => $auditNumber,
                'audit_type' => InventoryShelfAudit::AUDIT_TYPE_BRAND,
                'brand_name' => $brandName,
                'shelf_code' => null,
                'inventory_location_id' => $fallbackLocation->id,
                'user_id' => $user->id,
                'status' => InventoryShelfAudit::STATUS_IN_PROGRESS,
                'total_expected_products' => $totalExpected,
                'total_counted_products' => $totalCountedProducts,
                'total_system_units' => $totalSystemUnits,
                'total_counted_units' => $totalCountedUnits,
                'total_difference_units' => $totalDifferenceUnits,
                'matched_items_count' => $matchedCount,
                'surplus_items_count' => $surplusCount,
                'missing_items_count' => $missingCount,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
            ]);

            foreach ($preparedItems as $prepItem) {
                $audit->items()->create($prepItem);
            }

            if ($applyImmediately) {
                $this->applyBrandAuditAdjustment($audit, $user, $data['notes'] ?? null);
            }

            return $audit->fresh(['location', 'user', 'appliedBy', 'items.product.primaryLocation']);
        });
    }

    /**
     * Actualiza una auditoría existente en estado Borrador (IN_PROGRESS).
     * Permite reanudar el conteo físico, modificar cantidades y guardar o aplicar de inmediato.
     *
     * @param array<string, mixed> $data
     */
    public function updateAudit(InventoryShelfAudit $audit, array $data, User $user, bool $applyImmediately = false): InventoryShelfAudit
    {
        if (strtoupper((string) $audit->status) !== InventoryShelfAudit::STATUS_IN_PROGRESS) {
            throw new InvalidArgumentException("Solo se pueden modificar auditorías en estado Borrador.");
        }

        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('Debes indicar al menos un producto a auditar.');
        }

        $isBrand = $audit->audit_type === InventoryShelfAudit::AUDIT_TYPE_BRAND;
        $locationIds = [];
        if (! $isBrand) {
            $shelfIdentifier = $audit->shelf_code ?: $audit->inventory_location_id;
            $locations = $this->getLocationsForShelf($shelfIdentifier);
            $locationIds = $locations->pluck('id')->all();
        }

        return DB::transaction(function () use ($audit, $items, $data, $user, $applyImmediately, $locationIds, $isBrand) {
            $totalExpected = 0;
            $totalCountedProducts = 0;
            $totalSystemUnits = 0;
            $totalCountedUnits = 0;
            $totalDifferenceUnits = 0;
            $matchedCount = 0;
            $surplusCount = 0;
            $missingCount = 0;

            $preparedItems = [];

            foreach ($items as $item) {
                $productId = (int) ($item['inventory_product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $product = InventoryProduct::findOrFail($productId);
                $systemQty = (int) ($item['system_quantity'] ?? 0);
                $countedQty = max(0, (int) ($item['counted_quantity'] ?? 0));
                $diff = $countedQty - $systemQty;

                $totalExpected++;
                if ($countedQty > 0) {
                    $totalCountedProducts++;
                }

                $totalSystemUnits += $systemQty;
                $totalCountedUnits += $countedQty;
                $totalDifferenceUnits += $diff;

                if ($diff === 0) {
                    $matchedCount++;
                } elseif ($diff > 0) {
                    $surplusCount++;
                } else {
                    $missingCount++;
                }

                $isAssigned = $isBrand ? true : in_array((int) $product->primary_location_id, $locationIds, true);

                $preparedItems[] = [
                    'inventory_product_id' => $product->id,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'difference' => $diff,
                    'location_breakdown' => ! empty($item['location_breakdown']) ? trim((string) $item['location_breakdown']) : null,
                    'is_assigned_location' => $isAssigned,
                    'notes' => ! empty($item['notes']) ? trim((string) $item['notes']) : null,
                    'adjusted' => false,
                ];
            }

            // Actualizar campos cabecera de la auditoría
            $audit->update([
                'total_expected_products' => $totalExpected,
                'total_counted_products' => $totalCountedProducts,
                'total_system_units' => $totalSystemUnits,
                'total_counted_units' => $totalCountedUnits,
                'total_difference_units' => $totalDifferenceUnits,
                'matched_items_count' => $matchedCount,
                'surplus_items_count' => $surplusCount,
                'missing_items_count' => $missingCount,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : $audit->notes,
            ]);

            // Reemplazar los items
            $audit->items()->delete();
            foreach ($preparedItems as $prepItem) {
                $audit->items()->create($prepItem);
            }

            if ($applyImmediately) {
                $this->applyAuditAdjustment($audit, $user, $data['notes'] ?? null);
            }

            return $audit->fresh(['location', 'user', 'appliedBy', 'items.product.primaryLocation']);
        });
    }

    /**
     * Aplica los movimientos de ajuste físico en el ledger para el anaquel o marca auditada.
     */
    public function applyAuditAdjustment(InventoryShelfAudit $audit, User $user, ?string $notes = null): InventoryShelfAudit
    {
        if ($audit->status === InventoryShelfAudit::STATUS_APPLIED) {
            throw new InvalidArgumentException("La auditoría {$audit->audit_number} ya fue aplicada previamente.");
        }

        if ($audit->audit_type === InventoryShelfAudit::AUDIT_TYPE_BRAND) {
            return $this->applyBrandAuditAdjustment($audit, $user, $notes);
        }


        return DB::transaction(function () use ($audit, $user, $notes) {
            $shelfIdentifier = $audit->shelf_code ?: $audit->inventory_location_id;
            $locations = $this->getLocationsForShelf($shelfIdentifier);
            $locationIds = $locations->pluck('id')->all();
            $primaryLocation = $locations->first();
            $shelfCode = $audit->shelf_code ?: self::extractShelfCode($primaryLocation->code);

            $items = $audit->items()->with('product')->get();
            $auditNotes = $notes ? trim($notes) : ($audit->notes ?: '');

            foreach ($items as $item) {
                $diff = $item->difference;

                if ($diff === 0 || $item->adjusted) {
                    continue;
                }

                $product = $item->product;
                if (! $product) {
                    continue;
                }

                $ref = "Auditoría Anaquel {$shelfCode} (#{$audit->audit_number})";

                if ($diff > 0) {
                    // Sobrante físico: Contado > Sistema en este anaquel -> Entrada por ajuste
                    // Ubicación destino: casilla asignada si pertenece a este anaquel, o primera casilla
                    $targetLocationId = (in_array((int) $product->primary_location_id, $locationIds, true))
                        ? (int) $product->primary_location_id
                        : (int) $primaryLocation->id;

                    $movementNotes = "Ajuste físico en Anaquel {$shelfCode} (Sobrante: +{$diff} pza".($diff === 1 ? '' : 's')."). Sistema: {$item->system_quantity} → Contado: {$item->counted_quantity}";
                    if ($auditNotes !== '') {
                        $movementNotes .= " | {$auditNotes}";
                    }

                    $this->movementService->recordManual([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $targetLocationId,
                        'type' => InventoryMovement::ADJUSTMENT_IN,
                        'quantity' => $diff,
                        'reference' => $ref,
                        'notes' => $movementNotes,
                        'occurred_at' => now(),
                    ], $user);
                } else {
                    // Faltante físico: Contado < Sistema en este anaquel -> Salida por ajuste
                    // Descontar ordenadamente de las sub-casillas que tengan stock positivo
                    $missingUnits = abs($diff);
                    $movementNotes = "Ajuste físico en Anaquel {$shelfCode} (Faltante: -{$missingUnits} pza".($missingUnits === 1 ? '' : 's')."). Sistema: {$item->system_quantity} → Contado: {$item->counted_quantity}";
                    if ($auditNotes !== '') {
                        $movementNotes .= " | {$auditNotes}";
                    }

                    $stockPerLoc = InventoryMovement::query()
                        ->select('inventory_location_id')
                        ->selectRaw('COALESCE(SUM(quantity), 0) as stock')
                        ->whereIn('inventory_location_id', $locationIds)
                        ->where('inventory_product_id', $product->id)
                        ->groupBy('inventory_location_id')
                        ->having('stock', '>', 0)
                        ->orderByDesc('stock')
                        ->pluck('stock', 'inventory_location_id')
                        ->map(fn ($val) => (int) $val)
                        ->toArray();

                    foreach ($stockPerLoc as $locId => $availStock) {
                        if ($missingUnits <= 0) {
                            break;
                        }

                        $toDeduct = min($availStock, $missingUnits);

                        $this->movementService->recordManual([
                            'inventory_product_id' => $product->id,
                            'inventory_location_id' => $locId,
                            'type' => InventoryMovement::ADJUSTMENT_OUT,
                            'quantity' => $toDeduct,
                            'reference' => $ref,
                            'notes' => $movementNotes,
                            'occurred_at' => now(),
                        ], $user);

                        $missingUnits -= $toDeduct;
                    }

                    // Si aún faltan unidades por descontar
                    if ($missingUnits > 0) {
                        $this->movementService->recordManual([
                            'inventory_product_id' => $product->id,
                            'inventory_location_id' => (int) $primaryLocation->id,
                            'type' => InventoryMovement::ADJUSTMENT_OUT,
                            'quantity' => $missingUnits,
                            'reference' => $ref,
                            'notes' => $movementNotes,
                            'occurred_at' => now(),
                        ], $user);
                    }
                }

                $item->update(['adjusted' => true]);
            }

            $audit->update([
                'status' => InventoryShelfAudit::STATUS_APPLIED,
                'applied_at' => now(),
                'applied_by' => $user->id,
                'notes' => $auditNotes !== '' ? $auditNotes : $audit->notes,
            ]);

            return $audit->fresh(['location', 'user', 'appliedBy', 'items.product.primaryLocation']);
        });
    }

    /**
     * Aplica los movimientos de ajuste físico para una auditoría por Marca.
     */
    public function applyBrandAuditAdjustment(InventoryShelfAudit $audit, User $user, ?string $notes = null): InventoryShelfAudit
    {
        return DB::transaction(function () use ($audit, $user, $notes) {
            $fallbackLocation = $audit->location ?: InventoryLocation::query()->where('is_active', true)->orderBy('sort_order')->first();
            $items = $audit->items()->with('product.primaryLocation')->get();
            $auditNotes = $notes ? trim($notes) : ($audit->notes ?: '');
            $brandName = $audit->brand_name ?: 'Marca';

            foreach ($items as $item) {
                $diff = $item->difference;
                if ($diff === 0 || $item->adjusted) {
                    continue;
                }

                $product = $item->product;
                if (! $product) {
                    continue;
                }

                $ref = "Auditoría Marca {$brandName} (#{$audit->audit_number})";

                if ($diff > 0) {
                    // Sobrante físico: Contado > Sistema -> Entrada por ajuste
                    $targetLocationId = (int) ($product->primary_location_id ?: $fallbackLocation->id);
                    $movementNotes = "Ajuste físico de Marca {$brandName} (Sobrante: +{$diff} pza".($diff === 1 ? '' : 's')."). Sistema: {$item->system_quantity} → Contado: {$item->counted_quantity}";
                    if ($auditNotes !== '') {
                        $movementNotes .= " | {$auditNotes}";
                    }

                    $this->movementService->recordManual([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $targetLocationId,
                        'type' => InventoryMovement::ADJUSTMENT_IN,
                        'quantity' => $diff,
                        'reference' => $ref,
                        'notes' => $movementNotes,
                        'occurred_at' => now(),
                    ], $user);
                } else {
                    // Faltante físico: Contado < Sistema -> Salida por ajuste
                    $missingUnits = abs($diff);
                    $movementNotes = "Ajuste físico de Marca {$brandName} (Faltante: -{$missingUnits} pza".($missingUnits === 1 ? '' : 's')."). Sistema: {$item->system_quantity} → Contado: {$item->counted_quantity}";
                    if ($auditNotes !== '') {
                        $movementNotes .= " | {$auditNotes}";
                    }

                    // Buscar qué ubicaciones tienen stock de este producto
                    $stockPerLoc = InventoryMovement::query()
                        ->select('inventory_location_id')
                        ->selectRaw('COALESCE(SUM(quantity), 0) as stock')
                        ->where('inventory_product_id', $product->id)
                        ->groupBy('inventory_location_id')
                        ->having('stock', '>', 0)
                        ->orderByDesc('stock')
                        ->pluck('stock', 'inventory_location_id')
                        ->map(fn ($val) => (int) $val)
                        ->toArray();

                    foreach ($stockPerLoc as $locId => $availStock) {
                        if ($missingUnits <= 0) {
                            break;
                        }

                        $toDeduct = min($availStock, $missingUnits);

                        $this->movementService->recordManual([
                            'inventory_product_id' => $product->id,
                            'inventory_location_id' => (int) $locId,
                            'type' => InventoryMovement::ADJUSTMENT_OUT,
                            'quantity' => $toDeduct,
                            'reference' => $ref,
                            'notes' => $movementNotes,
                            'occurred_at' => now(),
                        ], $user);

                        $missingUnits -= $toDeduct;
                    }

                    if ($missingUnits > 0) {
                        $targetLocId = (int) ($product->primary_location_id ?: $fallbackLocation->id);
                        $this->movementService->recordManual([
                            'inventory_product_id' => $product->id,
                            'inventory_location_id' => $targetLocId,
                            'type' => InventoryMovement::ADJUSTMENT_OUT,
                            'quantity' => $missingUnits,
                            'reference' => $ref,
                            'notes' => $movementNotes,
                            'occurred_at' => now(),
                        ], $user);
                    }
                }

                $item->update(['adjusted' => true]);
            }

            $audit->update([
                'status' => InventoryShelfAudit::STATUS_APPLIED,
                'applied_at' => now(),
                'applied_by' => $user->id,
                'notes' => $auditNotes !== '' ? $auditNotes : $audit->notes,
            ]);

            return $audit->fresh(['location', 'user', 'appliedBy', 'items.product.primaryLocation']);
        });
    }


    /**
     * Genera un número de folio de auditoría único y legible:
     * AUD-{CODIGO_ANAQUEL}-YYYYMMDD-0001
     */
    protected function generateAuditNumber(string $shelfCode): string
    {
        $cleanCode = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($shelfCode)) ?: 'ANAQUEL';
        $prefix = 'AUD-'.$cleanCode.'-'.now()->format('Ymd');

        $latestAudit = InventoryShelfAudit::query()
            ->where('audit_number', 'like', "{$prefix}-%")
            ->orderByDesc('id')
            ->value('audit_number');

        $sequence = 1;
        if ($latestAudit) {
            $parts = explode('-', $latestAudit);
            $lastSeq = (int) end($parts);
            if ($lastSeq > 0) {
                $sequence = $lastSeq + 1;
            }
        }

        return sprintf('%s-%04d', $prefix, $sequence);
    }
}
