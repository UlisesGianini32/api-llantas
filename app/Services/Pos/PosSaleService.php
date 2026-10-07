<?php

namespace App\Services\Pos;

use App\Exceptions\PosInsufficientStockException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
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
            ->whereIn('code', ['MOSTRADOR', 'SALON', 'TIENDA', 'BODEGA', 'ALMACEN'])
            ->first();

        if ($location) {
            return $location;
        }

        return InventoryLocation::firstOrCreate(
            ['code' => 'MOSTRADOR'],
            [
                'name' => 'Salón / Mostrador',
                'description' => 'Ubicación física predeterminada para ventas en mostrador',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchProducts(string $query, ?int $locationId = null): array
    {
        $query = trim($query);
        $productsQuery = InventoryProduct::query()
            ->with(['primaryLocation:id,code,name', 'secondaryLocation:id,code,name'])
            ->where('is_active', true);

        if ($query === '') {
            $products = $productsQuery
                ->orderBy('name')
                ->limit(30)
                ->get();
        } else {
            $products = $productsQuery
                ->where(function ($q) use ($query) {
                    $q->where('sku', 'like', "%{$query}%")
                        ->orWhere('barcode', $query)
                        ->orWhere('barcode', 'like', "%{$query}%")
                        ->orWhere('name', 'like', "%{$query}%");
                })
                ->limit(40)
                ->get();
        }

        $location = ($locationId && $locationId > 0)
            ? InventoryLocation::find($locationId)
            : null;

        // Batch pre-fetch stock for simple products to eradicate N+1 queries
        $simpleProductIds = $products->where('product_type', InventoryProduct::SIMPLE)->pluck('id')->all();

        $physicalStocks = [];
        $reservedStocks = [];
        $globalPhysical = [];
        $globalReserved = [];

        if (! empty($simpleProductIds)) {
            // Global physical stock (across all locations)
            $globalPhysical = InventoryMovement::query()
                ->whereIn('inventory_product_id', $simpleProductIds)
                ->groupBy('inventory_product_id')
                ->selectRaw('inventory_product_id, SUM(quantity) as total')
                ->pluck('total', 'inventory_product_id')
                ->all();

            // Global reserved stock
            $globalReserved = InventoryReservation::query()
                ->whereIn('inventory_product_id', $simpleProductIds)
                ->where('status', InventoryReservation::ACTIVE)
                ->groupBy('inventory_product_id')
                ->selectRaw('inventory_product_id, SUM(quantity) as total')
                ->pluck('total', 'inventory_product_id')
                ->all();

            if ($location) {
                $physicalStocks = InventoryMovement::query()
                    ->whereIn('inventory_product_id', $simpleProductIds)
                    ->where('inventory_location_id', $location->id)
                    ->groupBy('inventory_product_id')
                    ->selectRaw('inventory_product_id, SUM(quantity) as total')
                    ->pluck('total', 'inventory_product_id')
                    ->all();

                $reservedStocks = InventoryReservation::query()
                    ->whereIn('inventory_product_id', $simpleProductIds)
                    ->where('status', InventoryReservation::ACTIVE)
                    ->where('inventory_location_id', $location->id)
                    ->groupBy('inventory_product_id')
                    ->selectRaw('inventory_product_id, SUM(quantity) as total')
                    ->pluck('total', 'inventory_product_id')
                    ->all();
            } else {
                $physicalStocks = $globalPhysical;
                $reservedStocks = $globalReserved;
            }
        }

        return $products->map(function (InventoryProduct $product) use ($physicalStocks, $reservedStocks, $globalPhysical, $globalReserved, $location) {
            if ($product->isKit()) {
                $physical = $this->kitStockService->physicalStock($product);
                $available = $this->kitStockService->availableStock($product);
                $reserved = max(0, $physical - $available);
                $globalAvailable = $available;
            } else {
                $physical = (int) ($physicalStocks[$product->id] ?? 0);
                $reserved = (int) ($reservedStocks[$product->id] ?? 0);
                $available = max(0, $physical - $reserved);

                $gPhys = (int) ($globalPhysical[$product->id] ?? 0);
                $gRes = (int) ($globalReserved[$product->id] ?? 0);
                $globalAvailable = max(0, $gPhys - $gRes);
            }

            // Fallback inteligente para precio público si no está configurado
            $publicPrice = (float) ($product->price_public ?? 0);
            if ($publicPrice <= 0) {
                $publicPrice = (float) ($product->price_mercado_libre ?: ($product->price_amazon ?: ($product->cost ? round($product->cost * 1.30, 2) : 0)));
            }

            // Fallback inteligente para precio estilista
            $stylistPrice = (float) ($product->price_stylist ?? 0);
            if ($stylistPrice <= 0) {
                $stylistPrice = $publicPrice > 0 ? $publicPrice : (float) ($product->cost ? round($product->cost * 1.15, 2) : 0);
            }

            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'name' => $product->name,
                'brand' => $product->brand,
                'product_type' => $product->product_type,
                'is_kit' => $product->isKit(),
                'price_public' => $publicPrice,
                'price_stylist' => $stylistPrice,
                'cost' => (float) ($product->cost ?? 0),
                'physical_stock' => $physical,
                'reserved_stock' => $reserved,
                'available_stock' => $available,
                'global_available_stock' => $globalAvailable,
                'has_specific_location' => $location !== null,
                'primary_location_id' => $product->primary_location_id,
                'primary_location_name' => $product->primaryLocation?->name ?? $product->primaryLocation?->code,
                'secondary_location_name' => $product->secondaryLocation?->name ?? $product->secondaryLocation?->code,
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
            $locationId = ! empty($data['inventory_location_id']) ? (int) $data['inventory_location_id'] : null;
            $location = ($locationId && $locationId > 0)
                ? InventoryLocation::findOrFail($locationId)
                : null;

            $saleLocation = $location ?? $this->getDefaultLocation();

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

                if ($defaultPrice <= 0) {
                    $defaultPrice = (float) ($product->price_mercado_libre ?: ($product->price_amazon ?: ($product->cost ? round($product->cost * 1.30, 2) : 0)));
                }

                $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : $defaultPrice;
                $itemDiscount = isset($item['discount']) ? max(0, (float) $item['discount']) : 0.0;
                $itemSubtotal = max(0, ($quantity * $unitPrice) - $itemDiscount);

                // Verificación estricta de stock disponible (Físico - Reservas activas)
                if ($product->isSimple()) {
                    if ($location) {
                        $physical = $this->stockService->physicalStockByLocation($product, $location);
                        $reserved = $this->stockService->reservedStockByLocation($product, $location);
                        $available = $this->stockService->availableStockByLocation($product, $location);
                    } else {
                        $physical = $this->stockService->physicalStock($product);
                        $reserved = $this->stockService->reservedStock($product);
                        $available = $this->stockService->availableStock($product);
                    }

                    if ($quantity > $available) {
                        throw new PosInsufficientStockException($product, $quantity, $available, $physical, $reserved);
                    }
                } elseif ($product->isKit()) {
                    $components = $product->kitComponents()->with('component')->get();
                    foreach ($components as $compRelation) {
                        $compProduct = $compRelation->component;
                        $neededUnits = $quantity * (int) $compRelation->quantity;

                        if ($location) {
                            $compPhysical = $this->stockService->physicalStockByLocation($compProduct, $location);
                            $compReserved = $this->stockService->reservedStockByLocation($compProduct, $location);
                            $compAvailable = $this->stockService->availableStockByLocation($compProduct, $location);
                        } else {
                            $compPhysical = $this->stockService->physicalStock($compProduct);
                            $compReserved = $this->stockService->reservedStock($compProduct);
                            $compAvailable = $this->stockService->availableStock($compProduct);
                        }

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

            $paymentMethod = $data['payment_method'] ?? PosSale::PAYMENT_CASH;
            $customerId = ! empty($data['customer_id']) ? (int) $data['customer_id'] : null;
            $customer = null;

            if ($customerId) {
                $customer = Customer::find($customerId);
            }

            // Crear cliente sobre la marcha si se solicita o si es crédito sin ID previo pero con nombre válido
            if (! $customer && (! empty($data['create_customer']) || $paymentMethod === PosSale::PAYMENT_CREDIT)) {
                $rawCustomerName = trim((string) ($data['customer_name'] ?? ''));
                if ($rawCustomerName !== '' && $rawCustomerName !== 'Público en general') {
                    $customer = Customer::create([
                        'name' => $rawCustomerName,
                        'phone' => ! empty($data['customer_phone']) ? trim((string) $data['customer_phone']) : null,
                        'business_name' => ! empty($data['customer_business_name']) ? trim((string) $data['customer_business_name']) : null,
                        'address' => ! empty($data['customer_address']) ? trim((string) $data['customer_address']) : null,
                        'credit_limit' => isset($data['customer_credit_limit']) ? (float) $data['customer_credit_limit'] : 5000.0,
                        'credit_days_default' => isset($data['credit_days']) ? (int) $data['credit_days'] : 15,
                        'is_active' => true,
                    ]);
                    $customerId = $customer->id;
                }
            }

            if ($paymentMethod === PosSale::PAYMENT_CREDIT && ! $customer) {
                throw new InvalidArgumentException('Para realizar una venta a crédito es obligatorio seleccionar o registrar un cliente/estilista.');
            }

            $paymentStatus = PosSale::PAYMENT_STATUS_PAID;
            $creditDays = null;
            $creditDueDate = null;
            $balanceDue = 0.0;
            $amountPaid = $total;
            $downpayment = 0.0;

            if ($paymentMethod === PosSale::PAYMENT_CREDIT) {
                $creditDays = in_array((int) ($data['credit_days'] ?? 15), [7, 15, 30], true)
                    ? (int) $data['credit_days']
                    : (int) ($customer->credit_days_default ?: 15);
                $creditDueDate = Carbon::today()->addDays($creditDays)->toDateString();

                $downpayment = $amountTendered !== null && $amountTendered > 0 ? min($total, $amountTendered) : 0.0;
                $balanceDue = max(0.0, round($total - $downpayment, 2));
                $amountPaid = $downpayment;
                $paymentStatus = $balanceDue <= 0
                    ? PosSale::PAYMENT_STATUS_PAID
                    : ($downpayment > 0 ? PosSale::PAYMENT_STATUS_CREDIT_PARTIAL : PosSale::PAYMENT_STATUS_CREDIT_PENDING);
            }

            // Buscar turno activo del cajero en esta ubicación o cualquiera
            $activeShift = $this->shiftService->getActiveShift($cashier, $location?->id);

            // Generar folio de venta único para la fecha
            $saleNumber = $this->generateSaleNumber();

            $customerFinalName = $customer ? $customer->name : (trim((string) ($data['customer_name'] ?? 'Público en general')) ?: 'Público en general');
            $customerFinalPhone = $customer?->phone ?: (! empty($data['customer_phone']) ? trim((string) $data['customer_phone']) : null);

            // 2. Guardar venta en base de datos
            $sale = PosSale::create([
                'sale_number' => $saleNumber,
                'user_id' => $cashier->id,
                'inventory_location_id' => $saleLocation->id,
                'pos_shift_id' => $activeShift?->id,
                'customer_id' => $customerId,
                'customer_name' => $customerFinalName,
                'customer_phone' => $customerFinalPhone,
                'customer_type' => $customerType,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'credit_days' => $creditDays,
                'credit_due_date' => $creditDueDate,
                'balance_due' => $balanceDue,
                'amount_paid' => $amountPaid,
                'subtotal' => $computedSubtotal,
                'discount_amount' => $totalDiscount,
                'tax_amount' => $taxAmount,
                'total' => $total,
                'amount_tendered' => $amountTendered,
                'change_due' => $changeDue,
                'status' => PosSale::STATUS_COMPLETED,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
            ]);

            // Si es venta a crédito con anticipo / enganche, registrar el abono inicial
            if ($paymentMethod === PosSale::PAYMENT_CREDIT && $downpayment > 0 && $customer) {
                CustomerPayment::create([
                    'customer_id' => $customer->id,
                    'pos_sale_id' => $sale->id,
                    'user_id' => $cashier->id,
                    'amount' => $downpayment,
                    'payment_method' => 'cash',
                    'payment_date' => now(),
                    'receipt_number' => 'ENG-'.$sale->sale_number,
                    'notes' => 'Anticipo/Enganche inicial en venta a crédito',
                ]);
            }

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
                    $itemLocationId = $location?->id ?? ($this->resolveLocationForProductStock($product->id) ?? $saleLocation->id);

                    InventoryMovement::create([
                        'inventory_product_id' => $product->id,
                        'inventory_location_id' => $itemLocationId,
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
                        $compLocationId = $location?->id ?? ($this->resolveLocationForProductStock($compProduct->id) ?? $saleLocation->id);

                        InventoryMovement::create([
                            'inventory_product_id' => $compProduct->id,
                            'inventory_location_id' => $compLocationId,
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

            return $sale->load(['items.product', 'cashier', 'location', 'customer', 'payments']);
        });
    }

    public function resolveLocationForProductStock(int $productId): ?int
    {
        $product = InventoryProduct::find($productId);
        if ($product && $product->primary_location_id) {
            return $product->primary_location_id;
        }

        $movement = InventoryMovement::query()
            ->where('inventory_product_id', $productId)
            ->whereNotNull('inventory_location_id')
            ->groupBy('inventory_location_id')
            ->selectRaw('inventory_location_id, SUM(quantity) as total')
            ->having('total', '>', 0)
            ->first();

        return $movement ? (int) $movement->inventory_location_id : null;
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
                'payment_status' => PosSale::PAYMENT_STATUS_CANCELLED,
                'balance_due' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => trim($reason),
            ]);

            return $sale->fresh(['items.product', 'cashier', 'location', 'cancelledBy', 'customer', 'payments']);
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
