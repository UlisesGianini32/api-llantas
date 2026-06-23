<?php

namespace App\Services;

use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeliOrderStoreService
{
    public function storeFromOrderApiResponse(array $orderData): MeliOrder
    {
        return DB::transaction(function () use ($orderData) {
            $orderId = (int) ($orderData['id'] ?? 0);

            if ($orderId <= 0) {
                throw new \RuntimeException('El payload de la orden no trae id válido.');
            }

            $order = MeliOrder::updateOrCreate(
                ['order_id' => $orderId],
                [
                    'topic' => 'orders_v2',
                    'resource' => $orderData['id'] ?? null,
                    'status' => $orderData['status'] ?? null,
                    'raw' => $orderData,
                    'processed_at' => now(),
                ]
            );

            MeliOrderItem::where('meli_order_id', $order->id)->delete();

            $items = $orderData['order_items'] ?? [];

            foreach ($items as $item) {
                $itemInfo = $item['item'] ?? [];

                MeliOrderItem::create([
                    'meli_order_id' => $order->id,
                    'item_id' => (string) ($itemInfo['id'] ?? ''),
                    'sku' => $itemInfo['seller_sku'] ?? null,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                ]);
            }

            Log::info('Orden ML guardada correctamente', [
                'order_id' => $order->order_id,
                'items' => count($items),
            ]);

            return $order;
        });
    }
}