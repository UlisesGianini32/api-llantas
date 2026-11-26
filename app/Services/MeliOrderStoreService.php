<?php

namespace App\Services;

use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MeliOrderStoreService
{
    public function __construct(
        private readonly InventoryMeliOrderReservationDispatcher $reservationDispatcher,
        private readonly InventoryMeliOrderReconciliationFingerprint $fingerprint,
        private readonly MeliOrderRemoteFreshnessGuard $freshnessGuard,
    ) {}

    public function storeFromOrderApiResponse(array $orderData): MeliOrder
    {
        return DB::transaction(function () use ($orderData) {
            $orderId = (int) ($orderData['id'] ?? 0);

            if ($orderId <= 0) {
                throw new \RuntimeException('El payload de la orden no trae id válido.');
            }

            $existing = MeliOrder::query()
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $existing->load('items');

                $freshness = $this->freshnessGuard->evaluateOrderUpdate($existing, $orderData);
                if (! $freshness['accept']) {
                    Log::warning('Orden ML descartada por control temporal de frescura', [
                        'meli_order_id' => $existing->id,
                        'order_id' => $orderId,
                        'reason' => $freshness['reason'],
                        'stored_timestamp_utc' => $freshness['stored_timestamp_utc'],
                        'incoming_timestamp_utc' => $freshness['incoming_timestamp_utc'],
                    ]);

                    return $existing;
                }
            }
            $previousFingerprint = $existing ? $this->fingerprint->forOrder($existing) : null;

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
            $storesLineIdentity = Schema::hasColumn('meli_order_items', 'variation_id')
                && Schema::hasColumn('meli_order_items', 'remote_line_key');

            foreach ($items as $item) {
                $itemInfo = $item['item'] ?? [];
                $variationId = $itemInfo['variation_id'] ?? $item['variation_id'] ?? null;
                $listingId = trim((string) ($itemInfo['id'] ?? ''));

                MeliOrderItem::create([
                    'meli_order_id' => $order->id,
                    'item_id' => (string) ($itemInfo['id'] ?? ''),
                    ...($storesLineIdentity ? [
                        'variation_id' => $variationId !== null ? (string) $variationId : null,
                        'remote_line_key' => $listingId !== '' ? $listingId.':'.(string) ($variationId ?? '') : null,
                    ] : []),
                    'sku' => $itemInfo['seller_sku'] ?? null,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                ]);
            }

            $order->load('items');

            Log::info('Orden ML guardada correctamente', [
                'order_id' => $order->order_id,
                'items' => count($items),
            ]);

            $this->reservationDispatcher->dispatchAfterCommit($order, $previousFingerprint);

            return $order;
        });
    }
}
