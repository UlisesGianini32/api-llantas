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

            $classification = $this->freshnessGuard->classifyOrderPayload($orderData);

            $existing = MeliOrder::query()
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $classification['valid']) {
                Log::warning('Orden ML descartada por payload inválido', [
                    'meli_order_id' => $existing?->id,
                    'order_id' => $orderId,
                    'reason' => $classification['reason'],
                ]);

                if ($existing) {
                    $existing->load('items');

                    return $existing;
                }

                throw new \RuntimeException('El payload de la orden es inválido: '.(string) $classification['reason']);
            }

            $replaceItems = $classification['kind'] === MeliOrderRemoteFreshnessGuard::PAYLOAD_COMPLETE;
            $advanceFullRaw = $classification['kind'] !== MeliOrderRemoteFreshnessGuard::PAYLOAD_PARTIAL;

            if ($existing) {
                $existing->load('items');

                $freshness = $this->freshnessGuard->evaluateOrderUpdate($existing, $orderData);
                if (! $freshness['accept']) {
                    Log::warning('Orden ML descartada por control temporal o integridad de payload', [
                        'meli_order_id' => $existing->id,
                        'order_id' => $orderId,
                        'reason' => $freshness['reason'],
                        'payload_kind' => $freshness['payload_kind'],
                        'stored_timestamp_utc' => $freshness['stored_timestamp_utc'],
                        'incoming_timestamp_utc' => $freshness['incoming_timestamp_utc'],
                    ]);

                    return $existing;
                }

                $replaceItems = $freshness['replace_items'];
                $advanceFullRaw = $freshness['advance_full_raw_timestamp'];
            }

            $previousFingerprint = $existing ? $this->fingerprint->forOrder($existing) : null;

            $effectiveStatus = array_key_exists('status', $orderData) && $orderData['status'] !== null
                ? (string) $orderData['status']
                : ($existing?->status);

            $effectiveRaw = ($existing && ! $advanceFullRaw)
                ? $this->freshnessGuard->mergePartialOrderRaw(
                    is_array($existing->raw) ? $existing->raw : [],
                    $orderData,
                )
                : $orderData;

            $order = MeliOrder::updateOrCreate(
                ['order_id' => $orderId],
                [
                    'topic' => 'orders_v2',
                    'resource' => $orderData['id'] ?? $existing?->resource,
                    'status' => $effectiveStatus,
                    'raw' => $effectiveRaw,
                    'processed_at' => now(),
                ]
            );

            $items = is_array($orderData['order_items'] ?? null) ? $orderData['order_items'] : [];

            if ($replaceItems) {
                MeliOrderItem::where('meli_order_id', $order->id)->delete();

                $storesLineIdentity = Schema::hasColumn('meli_order_items', 'variation_id')
                    && Schema::hasColumn('meli_order_items', 'remote_line_key');

                foreach ($items as $item) {
                    $itemInfo = $item['item'] ?? [];
                    $variationId = $itemInfo['variation_id'] ?? $item['variation_id'] ?? null;
                    $listingId = trim((string) ($itemInfo['id'] ?? ''));

                    MeliOrderItem::create([
                        'meli_order_id' => $order->id,
                        'item_id' => $listingId,
                        ...($storesLineIdentity ? [
                            'variation_id' => ($variationId !== null && trim((string) $variationId) !== '') ? trim((string) $variationId) : null,
                            'remote_line_key' => $listingId !== '' ? $listingId.':'.(string) ($variationId ?? '') : null,
                        ] : []),
                        'sku' => $itemInfo['seller_sku'] ?? null,
                        'quantity' => (int) ($item['quantity'] ?? 0),
                        'unit_price' => (float) ($item['unit_price'] ?? 0),
                    ]);
                }
            }

            $order->load('items');

            Log::info('Orden ML guardada correctamente', [
                'order_id' => $order->order_id,
                'items' => $order->items->count(),
                'items_replaced' => $replaceItems,
            ]);

            $this->reservationDispatcher->dispatchAfterCommit($order, $previousFingerprint);

            return $order;
        });
    }
}
