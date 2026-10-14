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
     * Devuelve la lista de productos esperados en una ubicación (anaquel)
     * basándose en:
     * 1. Productos con stock físico actual registrado en esa ubicación.
     * 2. Productos que tienen asignado ese anaquel como su ubicación oficial en el catálogo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getShelfExpectedProducts(int $locationId): Collection
    {
        $location = InventoryLocation::findOrFail($locationId);

        // 1. Stock agrupado por producto en esta ubicación según el ledger
        /** @var array<int, int> $stockByProduct */
        $stockByProduct = InventoryMovement::query()
            ->select('inventory_product_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as stock')
            ->where('inventory_location_id', $location->id)
            ->groupBy('inventory_product_id')
            ->pluck('stock', 'inventory_product_id')
            ->map(fn ($val) => (int) $val)
            ->toArray();

        // 2. Productos que tienen esta ubicación como estante asignado en el catálogo
        $assignedProductIds = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->where('primary_location_id', $location->id)
            ->pluck('id')
            ->toArray();

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
            ->map(function (InventoryProduct $product) use ($stockByProduct, $location) {
                $stock = (int) ($stockByProduct[$product->id] ?? 0);
                $isAssigned = (int) $product->primary_location_id === (int) $location->id;

                return [
                    'id' => $product->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'barcode_secondary' => $product->barcode_secondary,
                    'name' => $product->name,
                    'brand' => $product->brand,
                    'cost' => (float) ($product->cost ?? 0),
                    'primary_location_id' => $product->primary_location_id,
                    'primary_location' => $product->primaryLocation?->code,
                    'primary_location_code' => $product->primaryLocation?->code,
                    'is_assigned_location' => $isAssigned,
                    'system_stock' => $stock,
                    'system_quantity' => $stock,
                ];
            })
            ->keyBy('id');
    }

    /**
     * Crea un registro de auditoría de anaquel (Borrador o Aplicado de inmediato).
     *
     * @param array<string, mixed> $data
     */
    public function createAudit(array $data, User $user, bool $applyImmediately = false): InventoryShelfAudit
    {
        $locationId = (int) ($data['inventory_location_id'] ?? 0);
        $location = InventoryLocation::findOrFail($locationId);

        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('Debes indicar al menos un producto a auditar.');
        }

        return DB::transaction(function () use ($location, $items, $data, $user, $applyImmediately) {
            $auditNumber = $this->generateAuditNumber($location);

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

                $isAssigned = (int) $product->primary_location_id === (int) $location->id;

                $preparedItems[] = [
                    'inventory_product_id' => $product->id,
                    'system_quantity' => $systemQty,
                    'counted_quantity' => $countedQty,
                    'difference' => $diff,
                    'is_assigned_location' => $isAssigned,
                    'notes' => ! empty($item['notes']) ? trim((string) $item['notes']) : null,
                    'adjusted' => false,
                ];
            }

            $audit = InventoryShelfAudit::create([
                'audit_number' => $auditNumber,
                'inventory_location_id' => $location->id,
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
     * Aplica los movimientos de ajuste físico en el inventario para cuadrar el anaquel
     * exactamente con lo que el usuario contó.
     */
    public function applyAuditAdjustment(InventoryShelfAudit $audit, User $user, ?string $notes = null): InventoryShelfAudit
    {
        if ($audit->isApplied()) {
            throw new InvalidArgumentException("La auditoría {$audit->audit_number} ya fue aplicada previamente.");
        }

        return DB::transaction(function () use ($audit, $user, $notes) {
            $location = $audit->location ?? InventoryLocation::findOrFail($audit->inventory_location_id);
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

                $ref = "Auditoría Anaquel {$location->code} (#{$audit->audit_number})";

                if ($diff > 0) {
                    // Sobrante físico: Contado > Sistema -> Entrada por ajuste
                    $movementNotes = "Ajuste físico por auditoría (Sobrante: {$diff} pza".($diff === 1 ? '' : 's')."). Sistema: {$item->system_quantity} → Contado: {$item->counted_quantity}";
                    if ($auditNotes !== '') {
                        $movementNotes .= " | {$auditNotes}";
                    }

                    $this->movementService->recordManual([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $location->id,
                        'type' => InventoryMovement::ADJUSTMENT_IN,
                        'quantity' => $diff,
                        'reference' => $ref,
                        'notes' => $movementNotes,
                        'occurred_at' => now(),
                    ], $user);
                } else {
                    // Faltante físico: Contado < Sistema -> Salida por ajuste
                    $missingUnits = abs($diff);
                    $movementNotes = "Ajuste físico por auditoría (Faltante: -{$missingUnits} pza".($missingUnits === 1 ? '' : 's')."). Sistema: {$item->system_quantity} → Contado: {$item->counted_quantity}";
                    if ($auditNotes !== '') {
                        $movementNotes .= " | {$auditNotes}";
                    }

                    $this->movementService->recordManual([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $location->id,
                        'type' => InventoryMovement::ADJUSTMENT_OUT,
                        'quantity' => $missingUnits,
                        'reference' => $ref,
                        'notes' => $movementNotes,
                        'occurred_at' => now(),
                    ], $user);
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
     * AUD-{CODIGO_UBICACION}-YYYYMMDD-0001
     */
    protected function generateAuditNumber(InventoryLocation $location): string
    {
        $cleanCode = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($location->code)) ?: 'ANAQUEL';
        $prefix = 'AUD-'.$cleanCode.'-'.now()->format('Ymd');

        $latestToday = InventoryShelfAudit::where('audit_number', 'like', "{$prefix}-%")
            ->orderByDesc('id')
            ->value('audit_number');

        if (! $latestToday) {
            return "{$prefix}-0001";
        }

        $parts = explode('-', $latestToday);
        $seq = (int) end($parts);

        return sprintf('%s-%04d', $prefix, $seq + 1);
    }
}
