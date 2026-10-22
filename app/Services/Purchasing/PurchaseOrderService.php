<?php

namespace App\Services\Purchasing;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PurchaseOrderService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createOrder(array $data, User $buyer): PurchaseOrder
    {
        $supplierName = trim((string) ($data['supplier_name'] ?? ''));
        if ($supplierName === '') {
            throw new InvalidArgumentException('El nombre del proveedor es obligatorio.');
        }

        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('La orden de compra debe contener al menos un producto.');
        }

        $locationId = (int) ($data['inventory_location_id'] ?? 0);
        $location = $locationId > 0 ? InventoryLocation::find($locationId) : null;
        if (! $location) {
            $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->firstOrFail();
        }

        return DB::transaction(function () use ($data, $supplierName, $items, $location, $buyer) {
            $orderNumber = $this->generateOrderNumber();

            $subtotal = 0.0;
            $totalUnitsOrdered = 0;
            $validatedItems = [];
            $productIndexMap = [];

            foreach ($items as $item) {
                $productId = (int) ($item['inventory_product_id'] ?? 0);
                $qty = (int) ($item['quantity_ordered'] ?? 0);
                $cost = isset($item['unit_cost']) ? (float) $item['unit_cost'] : null;

                if ($productId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException('Cada partida debe tener un producto válido y cantidad mayor a cero.');
                }

                // Consolidar cantidades si el producto ya fue agregado en una partida previa
                if (isset($productIndexMap[$productId])) {
                    $existingIdx = $productIndexMap[$productId];
                    $validatedItems[$existingIdx]['quantity_ordered'] += $qty;
                    $validatedItems[$existingIdx]['subtotal'] = round(
                        $validatedItems[$existingIdx]['quantity_ordered'] * $validatedItems[$existingIdx]['unit_cost'],
                        2
                    );
                    $subtotal += round($qty * $validatedItems[$existingIdx]['unit_cost'], 2);
                    $totalUnitsOrdered += $qty;
                    continue;
                }

                $product = InventoryProduct::findOrFail($productId);
                $unitCost = $cost !== null ? $cost : (float) ($product->cost ?? 0);
                $itemSubtotal = round($qty * $unitCost, 2);

                $subtotal += $itemSubtotal;
                $totalUnitsOrdered += $qty;

                // Resolver o guardar el código de catálogo del proveedor
                $supplierSku = isset($item['supplier_sku']) ? trim((string) $item['supplier_sku']) : null;
                if ($supplierSku === '') {
                    $supplierSku = null;
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('supplier_products')) {
                    if (! $supplierSku) {
                        // Buscar si existe un código registrado para este producto y proveedor
                        $supProd = \App\Models\SupplierProduct::where('inventory_product_id', $product->id)
                            ->where(function ($q) use ($supplierName) {
                                $q->where('supplier_name', $supplierName)
                                    ->orWhere('supplier_name', 'like', "%{$supplierName}%");
                            })
                            ->orderByDesc('is_primary')
                            ->first();

                        if (! $supProd) {
                            $supProd = \App\Models\SupplierProduct::where('inventory_product_id', $product->id)
                                ->where('is_primary', true)
                                ->first();
                        }

                        if ($supProd) {
                            $supplierSku = $supProd->supplier_sku;
                        }
                    } elseif ($supplierSku) {
                        // Guardar / actualizar en supplier_products para futuras órdenes
                        $supplierId = null;
                        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers')) {
                            $supObj = \App\Models\Supplier::where('name', $supplierName)->first();
                            $supplierId = $supObj?->id;
                        }

                        \App\Models\SupplierProduct::updateOrCreate(
                            [
                                'supplier_name' => $supplierName,
                                'inventory_product_id' => $product->id,
                                'supplier_sku' => $supplierSku,
                            ],
                            [
                                'supplier_id' => $supplierId,
                                'supplier_product_name' => $product->name,
                                'supplier_cost' => $unitCost,
                                'is_primary' => true,
                            ]
                        );
                    }
                }

                $validatedItems[] = [
                    'inventory_product_id' => $product->id,
                    'sku' => $product->sku,
                    'supplier_sku' => $supplierSku,
                    'product_name' => $product->name,
                    'quantity_ordered' => $qty,
                    'quantity_received' => 0,
                    'unit_cost' => $unitCost,
                    'subtotal' => $itemSubtotal,
                    'status' => PurchaseOrderItem::STATUS_PENDING,
                ];

                $productIndexMap[$productId] = count($validatedItems) - 1;
            }

            $taxAmount = isset($data['tax_amount']) ? max(0, (float) $data['tax_amount']) : 0.0;
            $shippingCost = isset($data['shipping_cost']) ? max(0, (float) $data['shipping_cost']) : 0.0;
            $totalCost = round($subtotal + $taxAmount + $shippingCost, 2);

            $order = PurchaseOrder::create([
                'order_number' => $orderNumber,
                'supplier_name' => $supplierName,
                'brand' => ! empty($data['brand']) ? trim((string) $data['brand']) : null,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'inventory_location_id' => $location->id,
                'user_id' => $buyer->id,
                'expected_delivery_date' => ! empty($data['expected_delivery_date']) ? Carbon::parse($data['expected_delivery_date']) : null,
                'total_items_count' => count($validatedItems),
                'total_units_ordered' => $totalUnitsOrdered,
                'total_units_received' => 0,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_cost' => $shippingCost,
                'total_cost' => $totalCost,
                'supplier_quote_reference' => ! empty($data['supplier_quote_reference']) ? trim((string) $data['supplier_quote_reference']) : null,
                'notes' => ! empty($data['notes']) ? trim((string) $data['notes']) : null,
            ]);

            foreach ($validatedItems as $vItem) {
                $order->items()->create($vItem);
            }

            return $order->fresh(['location', 'buyer', 'items.product']);
        });
    }

    public function markAsOrdered(
        PurchaseOrder $order,
        ?string $supplierQuoteRef = null,
        ?string $orderedAt = null,
        ?string $expectedDelivery = null
    ): PurchaseOrder {
        if (! $order->isDraft()) {
            throw new InvalidArgumentException("Solo las órdenes en estado BORRADOR pueden ser enviadas al proveedor. Estado actual: {$order->status}");
        }

        $orderDate = $orderedAt ? Carbon::parse($orderedAt) : now();

        $order->update([
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => $orderDate,
            'supplier_quote_reference' => $supplierQuoteRef ? trim($supplierQuoteRef) : $order->supplier_quote_reference,
            'expected_delivery_date' => $expectedDelivery ? Carbon::parse($expectedDelivery) : $orderDate,
        ]);

        return $order->fresh(['location', 'buyer', 'items']);
    }

    /**
     * @param  array<int, array{item_id?: int, product_id?: int, quantity_received: int, allow_over_receipt?: bool}>  $itemsToReceive
     */
    public function receiveItems(
        PurchaseOrder $order,
        array $itemsToReceive,
        User $receiver,
        ?int $locationId = null,
        ?string $carrier = null,
        ?string $tracking = null,
        ?string $notes = null,
        bool $closeOrder = false
    ): PurchaseOrderReceipt {
        if (! $order->canBeReceived()) {
            if ($order->isPartialClosed()) {
                throw new InvalidArgumentException("La orden de compra {$order->order_number} ya fue finalizada y cerrada como recepción parcial. No se permiten más ingresos para evitar duplicar inventario.");
            }
            throw new InvalidArgumentException("La orden de compra {$order->order_number} no está lista para recepción. Estado actual: {$order->status}");
        }

        if (empty($itemsToReceive)) {
            throw new InvalidArgumentException('Debes indicar al menos un producto a recepcionar.');
        }

        $targetLocationId = $locationId ?: $order->inventory_location_id;
        $location = $targetLocationId ? InventoryLocation::find($targetLocationId) : null;
        if (! $location) {
            $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->firstOrFail();
        }

        return DB::transaction(function () use (
            $order,
            $itemsToReceive,
            $receiver,
            $location,
            $locationId,
            $carrier,
            $tracking,
            $notes,
            $closeOrder
        ) {
            $receiptNumber = $this->generateReceiptNumber();

            $receipt = PurchaseOrderReceipt::create([
                'purchase_order_id' => $order->id,
                'user_id' => $receiver->id,
                'inventory_location_id' => $location->id,
                'receipt_number' => $receiptNumber,
                'received_at' => now(),
                'total_units_received' => 0,
                'carrier' => $carrier,
                'tracking_number' => $tracking,
                'notes' => $notes,
            ]);

            $totalBatchUnits = 0;

            foreach ($itemsToReceive as $receivedEntry) {
                $itemId = ! empty($receivedEntry['item_id']) ? (int) $receivedEntry['item_id'] : null;
                $productId = ! empty($receivedEntry['product_id']) ? (int) $receivedEntry['product_id'] : null;
                $qty = (int) ($receivedEntry['quantity_received'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $product = null;

                if ($itemId) {
                    $orderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)
                        ->where('id', $itemId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $pending = $orderItem->quantityPending();
                    $allowOverReceipt = ! empty($receivedEntry['allow_over_receipt']);

                    // Protección anti-duplicados: Si la partida ya fue 100% recibida previamente y no se solicita explícitamente excedente
                    if ($pending === 0 && ! $allowOverReceipt) {
                        throw new InvalidArgumentException(
                            "El producto '{$orderItem->product_name}' (SKU: {$orderItem->sku}) ya fue recibido completamente ({$orderItem->quantity_received} de {$orderItem->quantity_ordered}). " .
                            "Para evitar ingresos duplicados en almacén, esta partida no admite más unidades."
                        );
                    }

                    // Protección anti-duplicados: No permitir recibir más del saldo pendiente salvo excedente explícito
                    if ($qty > $pending && ! $allowOverReceipt) {
                        throw new InvalidArgumentException(
                            "No se pueden recibir {$qty} unidades de '{$orderItem->product_name}'. Solo restan {$pending} unidades pendientes. " .
                            "Verifica la cantidad para evitar duplicar entradas en almacén."
                        );
                    }

                    $product = $orderItem->product ?? InventoryProduct::find($orderItem->inventory_product_id);

                    $newTotalReceived = $orderItem->quantity_received + $qty;
                    if ($newTotalReceived > $orderItem->quantity_ordered) {
                        $orderItem->quantity_ordered = $newTotalReceived;
                    }
                    $isItemComplete = $newTotalReceived >= $orderItem->quantity_ordered;

                    $orderItem->update([
                        'quantity_ordered' => $orderItem->quantity_ordered,
                        'quantity_received' => $newTotalReceived,
                        'subtotal' => $orderItem->unit_cost * $orderItem->quantity_ordered,
                        'status' => $isItemComplete ? PurchaseOrderItem::STATUS_RECEIVED : PurchaseOrderItem::STATUS_PARTIAL,
                    ]);
                } elseif ($productId) {
                    $product = InventoryProduct::findOrFail($productId);
                    $unitCost = (float) ($product->cost ?? 0);

                    $orderItem = PurchaseOrderItem::create([
                        'purchase_order_id' => $order->id,
                        'inventory_product_id' => $product->id,
                        'sku' => $product->sku,
                        'product_name' => $product->name,
                        'quantity_ordered' => $qty,
                        'quantity_received' => $qty,
                        'unit_cost' => $unitCost,
                        'subtotal' => $unitCost * $qty,
                        'status' => PurchaseOrderItem::STATUS_RECEIVED,
                    ]);
                } else {
                    continue;
                }

                // Guardar renglón del comprobante de recepción
                PurchaseOrderReceiptItem::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'inventory_product_id' => $orderItem->inventory_product_id,
                    'quantity_received' => $qty,
                ]);

                // Ubicación física de entrada:
                $itemLocationId = null;
                if (! empty($locationId)) {
                    $itemLocationId = (int) $locationId;
                } elseif ($product && $product->primary_location_id) {
                    $itemLocationId = (int) $product->primary_location_id;
                } else {
                    $itemLocationId = (int) $location->id;
                }

                // Generar movimiento atómico de entrada física en el ledger
                InventoryMovement::create([
                    'inventory_product_id' => $orderItem->inventory_product_id,
                    'inventory_location_id' => $itemLocationId,
                    'type' => InventoryMovement::RECEIPT,
                    'quantity' => $qty,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $order->id,
                    'reference' => "Recepción OC #{$order->order_number} ({$receipt->receipt_number})",
                    'notes' => "Entrada por compra proveedor '{$order->supplier_name}' recibida por {$receiver->name}",
                    'created_by' => $receiver->id,
                    'occurred_at' => now(),
                ]);

                $totalBatchUnits += $qty;
            }

            if ($totalBatchUnits <= 0) {
                throw new InvalidArgumentException('La cantidad total recepcionada en todas las partidas debe ser mayor a cero.');
            }

            $receipt->update(['total_units_received' => $totalBatchUnits]);

            // Actualizar totales y estado de la orden de compra
            $order->refresh();
            $allItems = $order->items;
            $allReceived = $allItems->every(fn (PurchaseOrderItem $item) => $item->isFullyReceived());
            $newOrderTotalReceived = (int) $allItems->sum('quantity_received');
            $newOrderTotalOrdered = (int) $allItems->sum('quantity_ordered');
            $newOrderTotalCost = (float) $allItems->sum('subtotal');

            $finalStatus = PurchaseOrder::STATUS_PARTIAL;
            if ($allReceived) {
                $finalStatus = PurchaseOrder::STATUS_RECEIVED;
            } elseif ($closeOrder) {
                $finalStatus = PurchaseOrder::STATUS_PARTIAL_CLOSED;
            }

            $finalNotes = $order->notes;
            if ($closeOrder && ! $allReceived) {
                $pendingUnits = max(0, $newOrderTotalOrdered - $newOrderTotalReceived);
                $closeNotice = "[Cierre Parcial Definitivo]: Recepción finalizada el " . now()->format('d/m/Y H:i') . " por {$receiver->name}. Se cancelaron {$pendingUnits} unidades faltantes y se cerró la orden para evitar entradas duplicadas.";
                $finalNotes = trim(($finalNotes ? $finalNotes . "\n" : '') . $closeNotice);
            }

            $order->update([
                'total_items_count' => $allItems->count(),
                'total_units_ordered' => $newOrderTotalOrdered,
                'total_units_received' => $newOrderTotalReceived,
                'total_cost' => $newOrderTotalCost,
                'subtotal' => $newOrderTotalCost,
                'status' => $finalStatus,
                'received_at' => ($allReceived || $closeOrder) ? now() : null,
                'notes' => $finalNotes,
            ]);

            return $receipt->load(['order', 'receivedBy', 'location', 'items.product', 'items.orderItem']);
        });
    }

    /**
     * Cerrar definitivamente una orden que tiene recepción parcial activa (evita recepciones duplicadas)
     */
    public function closePartialOrder(PurchaseOrder $order, User $user, ?string $reason = null): PurchaseOrder
    {
        if (! $order->isPartial()) {
            throw new InvalidArgumentException("Solo se pueden cerrar órdenes en recepción parcial activa (PARTIAL). Estado actual: {$order->status}");
        }

        return DB::transaction(function () use ($order, $user, $reason) {
            $pendingUnits = max(0, $order->total_units_ordered - $order->total_units_received);
            $reasonText = $reason ? trim($reason) : 'Cierre de orden con faltantes para evitar ingresos duplicados.';
            $closeNotice = "[Cierre Parcial Definitivo]: Orden finalizada el " . now()->format('d/m/Y H:i') . " por {$user->name}. {$pendingUnits} unidades no entregadas quedan canceladas. Motivo: {$reasonText}";

            $order->update([
                'status' => PurchaseOrder::STATUS_PARTIAL_CLOSED,
                'received_at' => now(),
                'notes' => trim(($order->notes ? $order->notes . "\n" : '') . $closeNotice),
            ]);

            return $order->fresh(['location', 'buyer', 'items.product', 'receipts']);
        });
    }

    /**
     * Anular y revertir un comprobante de recepción erróneo o duplicado, descontando el inventario ingresado
     */
    public function voidReceipt(PurchaseOrderReceipt $receipt, User $user, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($receipt, $user, $reason) {
            $order = $receipt->order()->lockForUpdate()->firstOrFail();
            $receipt->load(['items.orderItem']);

            $reasonText = $reason ? trim($reason) : 'Anulación de recepción duplicada / errónea';

            foreach ($receipt->items as $rItem) {
                $qty = (int) $rItem->quantity_received;
                if ($qty <= 0) {
                    continue;
                }

                $orderItem = $rItem->orderItem;
                if ($orderItem) {
                    $newReceived = max(0, $orderItem->quantity_received - $qty);
                    $orderItem->update([
                        'quantity_received' => $newReceived,
                        'status' => $newReceived >= $orderItem->quantity_ordered
                            ? PurchaseOrderItem::STATUS_RECEIVED
                            : ($newReceived > 0 ? PurchaseOrderItem::STATUS_PARTIAL : PurchaseOrderItem::STATUS_PENDING),
                    ]);
                }

                // Generar movimiento atómico de reversión de inventario físico en el ledger
                $locId = $receipt->inventory_location_id ?: $order->inventory_location_id;
                InventoryMovement::create([
                    'inventory_product_id' => $rItem->inventory_product_id,
                    'inventory_location_id' => $locId,
                    'type' => InventoryMovement::ADJUSTMENT_OUT,
                    'quantity' => -$qty,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $order->id,
                    'reference' => "Anulación Comprobante {$receipt->receipt_number} (OC #{$order->order_number})",
                    'notes' => "Reversión por anulación de recibo duplicado por {$user->name}. Motivo: {$reasonText}",
                    'created_by' => $user->id,
                    'occurred_at' => now(),
                ]);
            }

            // Eliminar recibo
            $receiptNumber = $receipt->receipt_number;
            $units = $receipt->total_units_received;
            $receipt->delete();

            // Recalcular orden
            $order->refresh();
            $allItems = $order->items;
            $totalReceived = (int) $allItems->sum('quantity_received');
            $allReceived = $allItems->every(fn ($it) => $it->isFullyReceived());

            $newStatus = PurchaseOrder::STATUS_ORDERED;
            if ($totalReceived > 0) {
                $newStatus = $allReceived ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIAL;
            }

            $order->update([
                'total_units_received' => $totalReceived,
                'status' => $newStatus,
                'received_at' => $allReceived ? now() : null,
                'notes' => trim(($order->notes ? $order->notes . "\n" : '') . "[Anulación]: Se anuló el recibo {$receiptNumber} ({$units} pzas revertidas en almacén) el " . now()->format('d/m/Y H:i') . " por {$user->name}."),
            ]);

            return $order->fresh(['location', 'buyer', 'items.product', 'receipts.items']);
        });
    }

    public function cancelOrder(PurchaseOrder $order, User $user, string $reason): PurchaseOrder
    {
        if ($order->total_units_received > 0) {
            throw new InvalidArgumentException("No se puede cancelar la orden {$order->order_number} porque ya cuenta con mercancía recepcionada en almacén.");
        }

        if ($order->isCancelled()) {
            throw new InvalidArgumentException("La orden {$order->order_number} ya se encuentra cancelada.");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('El motivo de cancelación es obligatorio.');
        }

        return DB::transaction(function () use ($order, $user, $reason) {
            $order->update([
                'status' => PurchaseOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            $order->items()->update(['status' => PurchaseOrderItem::STATUS_CANCELLED]);

        });
    }

    public function updateOrder(PurchaseOrder $order, array $data, User $user): PurchaseOrder
    {
        if ($order->receipts()->exists()) {
            throw new InvalidArgumentException('No se puede modificar una orden que ya cuenta con recepciones de mercancía.');
        }

        if ($order->status === PurchaseOrder::STATUS_CANCELLED) {
            throw new InvalidArgumentException('No se puede modificar una orden cancelada.');
        }

        $items = $data['items'] ?? [];
        if (empty($items) || ! is_array($items)) {
            throw new InvalidArgumentException('La orden de compra debe contener al menos un producto.');
        }

        return DB::transaction(function () use ($order, $data, $items) {
            $existingItemIds = [];
            $seenProductItemMap = [];
            $subtotal = 0.0;
            $totalUnitsOrdered = 0;

            foreach ($items as $itemData) {
                $qty = (int) ($itemData['quantity_ordered'] ?? 0);
                if ($qty <= 0) {
                    continue; // omitir partidas en 0
                }

                $itemId = isset($itemData['id']) ? (int) $itemData['id'] : 0;
                $productId = (int) ($itemData['inventory_product_id'] ?? 0);

                // Si ya procesamos este producto en la orden, consolidar la cantidad
                if ($productId > 0 && isset($seenProductItemMap[$productId])) {
                    $existingItem = $seenProductItemMap[$productId];
                    $newQty = $existingItem->quantity_ordered + $qty;
                    $existingItem->update([
                        'quantity_ordered' => $newQty,
                        'subtotal' => round($newQty * (float) $existingItem->unit_cost, 2),
                    ]);
                    $subtotal += round($qty * (float) $existingItem->unit_cost, 2);
                    $totalUnitsOrdered += $qty;
                    continue;
                }

                if ($itemId > 0) {
                    $orderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)->find($itemId);
                } else {
                    $orderItem = null;
                }

                // Si no viene con ID de partida, pero ya existe una partida en la orden con este producto, reutilizarla
                if (! $orderItem && $productId > 0) {
                    $orderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)
                        ->where('inventory_product_id', $productId)
                        ->first();
                }

                if (! $orderItem && $productId > 0) {
                    $product = InventoryProduct::findOrFail($productId);
                    $unitCost = isset($itemData['unit_cost']) ? (float) $itemData['unit_cost'] : (float) ($product->cost ?? 0);
                    $itemSubtotal = round($qty * $unitCost, 2);

                    $orderItem = $order->items()->create([
                        'inventory_product_id' => $product->id,
                        'sku' => $product->sku,
                        'supplier_sku' => ! empty($itemData['supplier_sku']) ? trim((string) $itemData['supplier_sku']) : null,
                        'product_name' => $product->name,
                        'quantity_ordered' => $qty,
                        'quantity_received' => 0,
                        'unit_cost' => $unitCost,
                        'subtotal' => $itemSubtotal,
                        'status' => PurchaseOrderItem::STATUS_PENDING,
                    ]);
                } elseif ($orderItem) {
                    $unitCost = isset($itemData['unit_cost']) ? (float) $itemData['unit_cost'] : (float) ($orderItem->unit_cost ?? 0);
                    $itemSubtotal = round($qty * $unitCost, 2);

                    $orderItem->update([
                        'quantity_ordered' => $qty,
                        'unit_cost' => $unitCost,
                        'subtotal' => $itemSubtotal,
                        'supplier_sku' => array_key_exists('supplier_sku', $itemData) ? (trim((string) $itemData['supplier_sku']) ?: null) : $orderItem->supplier_sku,
                    ]);
                }

                if ($orderItem) {
                    $existingItemIds[] = $orderItem->id;
                    $seenProductItemMap[$orderItem->inventory_product_id] = $orderItem;
                    $subtotal += round($qty * (float) $orderItem->unit_cost, 2);
                    $totalUnitsOrdered += $qty;
                }
            }

            if (empty($existingItemIds)) {
                throw new InvalidArgumentException('La orden no puede quedar vacía.');
            }

            // Eliminar partidas que no vienen en la lista
            $order->items()->whereNotIn('id', $existingItemIds)->delete();

            $shippingCost = isset($data['shipping_cost']) ? (float) $data['shipping_cost'] : (float) ($order->shipping_cost ?? 0);
            $taxAmount = isset($data['tax_amount']) ? (float) $data['tax_amount'] : (float) ($order->tax_amount ?? 0);
            $totalCost = round($subtotal + $shippingCost + $taxAmount, 2);

            $updatePayload = [
                'total_items_count' => count($existingItemIds),
                'total_units_ordered' => $totalUnitsOrdered,
                'subtotal' => $subtotal,
                'total_cost' => $totalCost,
            ];

            if (array_key_exists('notes', $data)) {
                $updatePayload['notes'] = $data['notes'];
            }
            if (array_key_exists('supplier_quote_reference', $data)) {
                $updatePayload['supplier_quote_reference'] = $data['supplier_quote_reference'];
            }
            if (! empty($data['expected_delivery_date'])) {
                $updatePayload['expected_delivery_date'] = $data['expected_delivery_date'];
            }

            $order->update($updatePayload);

            return $order->fresh(['location', 'buyer', 'items.product.primaryLocation', 'receipts']);
        });
    }

    public function generateOrderNumber(): string
    {
        $datePrefix = 'OC-'.Carbon::now()->format('Ymd');

        $latestToday = PurchaseOrder::query()
            ->where('order_number', 'like', "{$datePrefix}-%")
            ->orderByDesc('id')
            ->value('order_number');

        $nextSeq = 1;
        if ($latestToday && preg_match('/-(\d+)$/', $latestToday, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%04d', $datePrefix, $nextSeq);
    }

    public function generateReceiptNumber(): string
    {
        $datePrefix = 'REC-'.Carbon::now()->format('Ymd');

        $latestToday = PurchaseOrderReceipt::query()
            ->where('receipt_number', 'like', "{$datePrefix}-%")
            ->orderByDesc('id')
            ->value('receipt_number');

        $nextSeq = 1;
        if ($latestToday && preg_match('/-(\d+)$/', $latestToday, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%04d', $datePrefix, $nextSeq);
    }
}
