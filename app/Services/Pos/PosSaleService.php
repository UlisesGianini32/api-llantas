<?php

namespace App\Services\Pos;

use App\Exceptions\PosInsufficientStockException;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\User;
use App\Services\InventoryKitStockService;
use App\Services\InventoryStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PosSaleService
{
    public function __construct(
        private readonly InventoryStockService $stockService,
        private readonly InventoryKitStockService $kitStockService,
        private readonly PosShiftService $shiftService
    ) {}

    public function getDefaultLocation(): InventoryLocation
    {
        $location = InventoryLocation::query()
            ->whereIn('code', ['MOSTRADOR', 'SALON', 'TIENDA'])
            ->first();

        if ($location) {
            return $location;
        }

        $active = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($active) {
            return $active;
        }

        return InventoryLocation::create([
            'code' => 'MOSTRADOR',
            'name' => 'Salón / Mostrador',
            'description' => 'Ubicación física predeterminada para ventas en mostrador',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $query, ?int $locationId = null): array
    {
        $query = trim($query);
        if ($query === '') {
            $products = InventoryProduct::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(20)
                ->get();
        } else {
            $products = InventoryProduct::query()
                ->where('is_active', true)
                ->where(function ($q) use ($query) {
                    $q->where('sku', 'like', "%{$query}%")
                        ->orWhere('barcode', $query)
                        ->orWhere('barcode', 'like', "%{$query}%")
                        ->orWhere('name', 'like', "%{$query}%");
                })
                ->limit(25)
                ->get();
        }

        $location = $locationId
            ? InventoryLocation::find($locationId)
            : $this->getDefaultLocation();

        return $products->map(function (InventoryProduct $product) use ($location) {
            if ($product->isKit()) {
                $physical = $this->kitStockService->physicalStock($product);
                $available = $this->kitStockService->availableStock($product);
                $reserved = max(0, $physical - $available);
            } elseif ($location) {
                $physical = $this->stockService->physicalStockByLocation($product, $location);
                $reserved = $this->stockService->reservedStockByLocation($product, $location);
                $available = $this->stockService->availableStockByLocation($product, $location);
            } else {
                $physical = $this->stockService->physicalStock($product);
                $reserved = $this->stockService->reservedStock($product);
                $available = $this->stockService->availableStock($product);
            }

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'product_type' => $product->product_type,
                'is_kit' => $product->isKit(),
                'price_public' => (float) ($product->price_public ?? 0),
                'price_stylist' => (float) ($product->price_stylist ?? $product->price_public ?? 0),
                'cost' => (float) ($product->cost ?? 0),
                'physical_stock' => $physical,
                'reserved_stock' => $reserved,
                'available_stock' => $available,
                'primary_location_id' => $product->primary_location_id,
            ];
        })->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSale(array $data, User $cashier): PosSale
    {
        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('El carrito de venta no puede estar vacío.');
        }

        return DB::transaction(function () use ($data, $items, $cashier) {
            $locationId = $data['inventory_location_id'] ?? null;
            $location = $locationId
                ? InventoryLocation::findOrFail($locationId)
                : $this->getDefaultLocation();

            $customerType = in_array($data['customer_type'] ?? null, [PosSale::CUSTOMER_PUBLIC, PosSale::CUSTOMER_STYLIST], true)
                ? $data['customer_type']
                : PosSale::CUSTOMER_PUBLIC;

            $validatedItems = [];
            $computedSubtotal = 0.0;
            $computedDiscount = 0.0;

            // 1. Validar existencias y calcular montos
            foreach ($items as $item) {
                $productId = (int) ($item['inventory_product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);

                if ($productId <= 0 || $quantity <= 0) {
                    throw new InvalidArgumentException('Cada ítem debe tener un producto válido y una cantidad mayor a cero.');
                }

                $product = InventoryProduct::findOrFail($productId);

                // Determinar precio base según tipo de cliente
                $defaultPrice = $customerType === PosSale::CUSTOMER_STYLIST
                    ? (float) ($product->price_stylist ?? $product->price_public ?? 0)
                    : (float) ($product->price_public ?? 0);

                $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : $defaultPrice;
                $itemDiscount = isset($item['discount']) ? max(0, (float) $item['discount']) : 0.0;
                $itemSubtotal = max(0, ($quantity * $unitPrice) - $itemDiscount);

                // Verificación estricta de stock disponible (Físico - Reservas activas)
                if ($product->isSimple()) {
                    $physical = $this->stockService->physicalStockByLocation($product, $location);
                    $reserved = $this->stockService->reservedStockByLocation($product, $location);
                    $available = $this->stockService->availableStockByLocation($product, $location);

                    if ($quantity > $available) {
                        throw new PosInsufficientStockException($product, $quantity, $available, $physical, $reserved);
                    }
                } elseif ($product->isKit()) {
                    $components = $product->kitComponents()->with('component')->get();
                    foreach ($components as $compRelation) {
                        $compProduct = $compRelation->component;
                        $neededUnits = $quantity * (int) $compRelation->quantity;

                        $compPhysical = $this->stockService->physicalStockByLocation($compProduct, $location);
                        $compReserved = $this->stockService->reservedStockByLocation($compProduct, $location);
                        $compAvailable = $this->stockService->availableStockByLocation($compProduct, $location);

                        if ($neededUnits > $compAvailable) {
                            throw new PosInsufficientStockException(
                                $compProduct,
                                $neededUnits,
                                $compAvailable,
                                $compPhysical,
                                $compReserved,
                                true,
                                $product
                            );
                        }
                    }
                }

                $computedSubtotal += ($quantity * $unitPrice);
                $computedDiscount += $itemDiscount;

                $validatedItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $itemDiscount,
                    'subtotal' => $itemSubtotal,
                ];
            }

            // Descuento global adicional si aplica
            $globalDiscount = isset($data['discount_amount']) ? max(0, (float) $data['discount_amount']) : 0.0;
            $totalDiscount = $computedDiscount + $globalDiscount;
            $taxAmount = isset($data['tax_amount']) ? max(0, (float) $data['tax_amount']) : 0.0;
            $total = max(0, $computedSubtotal - $totalDiscount + $taxAmount);

            $amountTendered = isset($data['amount_tendered']) && $data['amount_tendered'] !== ''
                ? (float) $data['amount_tendered']
                : null;

            $changeDue = 0.0;
            if ($amountTendered !== null && $amountTendered >= $total) {
                $changeDue = $amountTendered - $total;
            }

            // Buscar turno activo del cajero en esta ubicación
            $activeShift = $this->shiftService->getActiveShift($cashier, $location->id);

            // Generar folio de venta único para la fecha
            $saleNumber = $this->generateSaleNumber();

            // 2. Guardar venta en base de datos
            $sale = PosSale::create([
                'sale_number' => $saleNumber,
                'user_id' => $cashier->id,
                'inventory_location_id' => $location->id,
                'pos_shift_id' => $activeShift?->id,
                'customer_name' => trim((string) ($data['customer_name'] ?? 'Público en general')) ?: 'Público en general',
                'customer_phone' => ! empty($data['customer_phone']) ? trim((string) $data['customer_phone']) : null,
                'customer_type' => $customerType,
                'payment_method' => $data['payment_method'] ?? PosSale::PAYMENT_CASH,
                'subtotal' => $computedSubtotal,
                'discount_amount' => $totalDiscount,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'amount_tendered' => $amountTendered,
                'change_due' => $changeDue,
                'status' => PosSale::STATUS_COMPLETED,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
            ]);

            // 3. Crear ítems y movimientos de inventario atómicos
            foreach ($validatedItems as $vItem) {
                $product = $vItem['product'];
                $qty = $vItem['quantity'];

                PosSaleItem::create([
                    'pos_sale_id' => $sale->id,
                    'inventory_product_id' => $product->id,
                    'product_type' => $product->product_type,
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $vItem['unit_price'],
                    'discount' => $vItem['discount'],
                    'subtotal' => $vItem['subtotal'],
                ]);

                if ($product->isSimple()) {
                    InventoryMovement::create([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $location->id,
                        'type' => InventoryMovement::SALE,
                        'quantity' => -$qty,
                        'reference_type' => 'pos_sale',
                        'reference_id' => $sale->id,
                        'reference' => "Venta Mostrador #{$sale->sale_number}",
                        'notes' => "Venta POS cobrada por {$cashier->name}",
                        'created_by' => $cashier->id,
                        'occurred_at' => now(),
                    ]);
                } elseif ($product->isKit()) {
                    $components = $product->kitComponents()->with('component')->get();
                    foreach ($components as $compRelation) {
                        $compProduct = $compRelation->component;
                        $neededUnits = $qty * (int) $compRelation->quantity;

                        InventoryMovement::create([
                            'inventory_product_id' => $compProduct->id,
                            'inventory_location_id' => $location->id,
                            'type' => InventoryMovement::SALE,
                            'quantity' => -$neededUnits,
                            'reference_type' => 'pos_sale',
                            'reference_id' => $sale->id,
                            'reference' => "Venta Mostrador #{$sale->sale_number} (Kit: {$product->sku})",
                            'notes' => "Salida por venta de kit '{$product->name}' x{$qty} (cajero: {$cashier->name})",
                            'created_by' => $cashier->id,
                            'occurred_at' => now(),
                        ]);
                    }
                }
            }

            return $sale->load(['items.product', 'cashier', 'location']);
        });
    }

    public function cancelSale(PosSale $sale, User $user, string $reason): PosSale
    {
        if ($sale->status !== PosSale::STATUS_COMPLETED) {
            throw new InvalidArgumentException('Solo se pueden cancelar ventas con estado completado.');
        }

        return DB::transaction(function () use ($sale, $user, $reason) {
            $sale->loadMissing(['items.product']);

            foreach ($sale->items as $item) {
                $product = $item->product;
                $qty = (int) $item->quantity;

                if ($product->isSimple()) {
                    InventoryMovement::create([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $sale->inventory_location_id,
                        'type' => InventoryMovement::RETURN,
                        'quantity' => $qty,
                        'reference_type' => 'pos_sale_cancel',
                        'reference_id' => $sale->id,
                        'reference' => "Devolución Venta Mostrador #{$sale->sale_number}",
                        'notes' => "Venta cancelada por {$user->name}. Motivo: {$reason}",
                        'created_by' => $user->id,
                        'occurred_at' => now(),
                    ]);
                } elseif ($product->isKit()) {
                    $components = $product->kitComponents()->with('component')->get();
                    foreach ($components as $compRelation) {
                        $compProduct = $compRelation->component;
                        $returnedUnits = $qty * (int) $compRelation->quantity;

                        InventoryMovement::create([
                            'inventory_product_id' => $compProduct->id,
                            'inventory_location_id' => $sale->inventory_location_id,
                            'type' => InventoryMovement::RETURN,
                            'quantity' => $returnedUnits,
                            'reference_type' => 'pos_sale_cancel',
                            'reference_id' => $sale->id,
                            'reference' => "Devolución Venta Mostrador #{$sale->sale_number} (Kit: {$product->sku})",
                            'notes' => "Reintegro por cancelación de kit '{$product->name}'. Motivo: {$reason}",
                            'created_by' => $user->id,
                            'occurred_at' => now(),
                        ]);
                    }
                }
            }

            $sale->update([
                'status' => PosSale::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => trim($reason),
            ]);

            return $sale->fresh(['items.product', 'cashier', 'location', 'cancelledBy']);
        });
    }

    protected function generateSaleNumber(): string
    {
        $datePrefix = 'POS-'.Carbon::now()->format('Ymd').'-';

        $lastNumber = PosSale::query()
            ->where('sale_number', 'like', "{$datePrefix}%")
            ->orderByDesc('id')
            ->value('sale_number');

        if ($lastNumber) {
            $seq = (int) substr($lastNumber, strlen($datePrefix)) + 1;
        } else {
            $seq = 1;
        }

        $saleNumber = $datePrefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        // Garantizar unicidad absoluta
        while (PosSale::where('sale_number', $saleNumber)->exists()) {
            $seq++;
            $saleNumber = $datePrefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        }

        return $saleNumber;
    }
}
