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
        $location = InventoryLocation::findOrFail($locationId);

        return DB::transaction(function () use ($data, $supplierName, $items, $location, $buyer) {
            $orderNumber = $this->generateOrderNumber();

            $subtotal = 0.0;
            $totalUnitsOrdered = 0;
            $validatedItems = [];

            foreach ($items as $item) {
                $productId = (int) ($item['inventory_product_id'] ?? 0);
                $qty = (int) ($item['quantity_ordered'] ?? 0);
                $cost = isset($item['unit_cost']) ? (float) $item['unit_cost'] : null;

                if ($productId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException('Cada partida debe tener un producto válido y cantidad mayor a cero.');
                }

                $product = InventoryProduct::findOrFail($productId);
                $unitCost = $cost !== null ? $cost : (float) ($product->cost ?? 0);
                $itemSubtotal = round($qty * $unitCost, 2);

                $subtotal += $itemSubtotal;
                $totalUnitsOrdered += $qty;

                $validatedItems[] = [
                    'inventory_product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity_ordered' => $qty,
                    'quantity_received' => 0,
                    'unit_cost' => $unitCost,
                    'subtotal' => $itemSubtotal,
                    'status' => PurchaseOrderItem::STATUS_PENDING,
                ];
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
        ?string $expectedDelivery = null
    ): PurchaseOrder {
        if (! $order->isDraft()) {
            throw new InvalidArgumentException("Solo las órdenes en estado BORRADOR pueden ser enviadas al proveedor. Estado actual: {$order->status}");
        }

        $order->update([
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now(),
            'supplier_quote_reference' => $supplierQuoteRef ? trim($supplierQuoteRef) : $order->supplier_quote_reference,
            'expected_delivery_date' => $expectedDelivery ? Carbon::parse($expectedDelivery) : $order->expected_delivery_date,
        ]);

        return $order->fresh(['location', 'buyer', 'items']);
    }

    /**
     * @param  array<int, array{item_id: int, quantity_received: int}>  $itemsToReceive
     */
    public function receiveItems(
        PurchaseOrder $order,
        array $itemsToReceive,
        User $receiver,
        ?int $locationId = null,
        ?string $carrier = null,
        ?string $tracking = null,
        ?string $notes = null
    ): PurchaseOrderReceipt {
        if (! $order->canBeReceived()) {
            throw new InvalidArgumentException("La orden de compra {$order->order_number} no está lista para recepción. Estado actual: {$order->status}");
        }

        if (empty($itemsToReceive)) {
            throw new InvalidArgumentException('Debes indicar al menos un producto a recepcionar.');
        }

        $targetLocationId = $locationId ?: $order->inventory_location_id;
        $location = InventoryLocation::findOrFail($targetLocationId);

        return DB::transaction(function () use (
            $order,
            $itemsToReceive,
            $receiver,
            $location,
            $carrier,
            $tracking,
            $notes
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
                $itemId = (int) ($receivedEntry['item_id'] ?? 0);
                $qty = (int) ($receivedEntry['quantity_received'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $orderItem = PurchaseOrderItem::where('purchase_order_id', $order->id)
                    ->where('id', $itemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $pendingQty = $orderItem->quantityPending();
                if ($qty > $pendingQty) {
                    throw new InvalidArgumentException(
                        "No se pueden recibir {$qty} unidades del SKU {$orderItem->sku}. Cantidad pendiente por recibir: {$pendingQty}."
                    );
                }

                $newTotalReceived = $orderItem->quantity_received + $qty;
                $isItemComplete = $newTotalReceived >= $orderItem->quantity_ordered;

                $orderItem->update([
                    'quantity_received' => $newTotalReceived,
                    'status' => $isItemComplete ? PurchaseOrderItem::STATUS_RECEIVED : PurchaseOrderItem::STATUS_PARTIAL,
                ]);

                // Guardar renglón del comprobante de recepción
                PurchaseOrderReceiptItem::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $orderItem->id,
                    'inventory_product_id' => $orderItem->inventory_product_id,
                    'quantity_received' => $qty,
                ]);

                // Generar movimiento atómico de entrada física en el ledger
                InventoryMovement::create([
                    'inventory_product_id' => $orderItem->inventory_product_id,
                    'inventory_location_id' => $location->id,
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

            $order->update([
                'total_units_received' => $newOrderTotalReceived,
                'status' => $allReceived ? PurchaseOrder::STATUS_RECEIVED : PurchaseOrder::STATUS_PARTIAL,
                'received_at' => $allReceived ? now() : null,
            ]);

            return $receipt->load(['order', 'receivedBy', 'location', 'items.product', 'items.orderItem']);
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

            return $order->fresh(['location', 'buyer', 'cancelledBy', 'items']);
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
