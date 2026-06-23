<?php

namespace App\Services;

use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use Carbon\Carbon;
use JsonException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MeliOrderSyncService
{
    public function __construct(
        protected StockService $stockService,
        protected SyscomOrderFromMeliService $syscomOrderFromMeli
    ) {}

    public function syncDay(User $user, string $date): array
    {
        $sellerId = $this->resolveSellerId($user);

        if (!$sellerId) {
            throw new \RuntimeException('No se pudo resolver el seller_id del usuario.');
        }

        $dateObj = Carbon::parse($date)->startOfDay();
        $from = $dateObj->copy()->format('Y-m-d') . 'T00:00:00.000-00:00';
        $to   = $dateObj->copy()->format('Y-m-d') . 'T23:59:59.999-00:00';

        $offset = 0;
        $limit = 50;
        $totalSynced = 0;
        $ordersCount = 0;

        do {
            $response = Http::withToken($user->access_token)
                ->timeout(60)
                ->acceptJson()
                ->get('https://api.mercadolibre.com/orders/search', [
                    'seller' => $sellerId,
                    'sort' => 'date_desc',
                    'offset' => $offset,
                    'limit' => $limit,
                    'order.date_created.from' => $from,
                    'order.date_created.to' => $to,
                ]);

            if (!$response->successful()) {
                Log::error('ML orders search failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'seller_id' => $sellerId,
                    'date' => $date,
                    'offset' => $offset,
                ]);

                throw new \RuntimeException('Falló la consulta de órdenes a Mercado Libre: ' . $response->body());
            }

            $data = $response->json();
            $results = $data['results'] ?? [];
            $pagingTotal = (int) data_get($data, 'paging.total', 0);

            foreach ($results as $orderData) {
                $savedItems = $this->storeOrder($user, $orderData);
                $ordersCount++;
                $totalSynced += $savedItems;
            }

            $offset += $limit;
        } while ($offset < $pagingTotal);

        return [
            'orders' => $ordersCount,
            'items' => $totalSynced,
            'seller_id' => $sellerId,
            'date' => $date,
        ];
    }

    public function syncOrderById(User $user, string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '' || !ctype_digit($orderId)) {
            throw new \RuntimeException('order_id inválido.');
        }

        $savedItems = 0;
        $storedOrderIds = [];

        $response = Http::withToken($user->access_token)
            ->timeout(60)
            ->acceptJson()
            ->get("https://api.mercadolibre.com/orders/{$orderId}");

        if ($response->successful()) {
            $orderData = $response->json();
            if (!is_array($orderData) || !isset($orderData['id'])) {
                throw new \RuntimeException('Respuesta inválida al consultar la orden en Mercado Libre.');
            }

            $savedItems += $this->storeOrder($user, $orderData);
            $storedOrderIds[] = (string) ($orderData['id'] ?? $orderId);
        } else {
            // Si /orders/{id} falla, intentar resolver como PACK (en AMS a veces ves PACK-...).
            $packOrderIds = $this->resolveOrderIdsFromPackId($user, $orderId);

            if ($packOrderIds === []) {
                Log::error('ML get order/pack by id failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'input_id' => $orderId,
                    'user_id' => $user->id,
                ]);

                throw new \RuntimeException('No se encontró como orden ni como pack en Mercado Libre: ' . $orderId);
            }

            foreach ($packOrderIds as $oid) {
                $orderResp = Http::withToken($user->access_token)
                    ->timeout(60)
                    ->acceptJson()
                    ->get("https://api.mercadolibre.com/orders/{$oid}");

                if (!$orderResp->successful()) {
                    Log::warning('ML get order from pack failed', [
                        'pack_id' => $orderId,
                        'order_id' => $oid,
                        'status' => $orderResp->status(),
                        'body' => $orderResp->body(),
                    ]);
                    continue;
                }

                $orderData = $orderResp->json();
                if (!is_array($orderData) || !isset($orderData['id'])) {
                    continue;
                }

                $savedItems += $this->storeOrder($user, $orderData);
                $storedOrderIds[] = (string) ($orderData['id'] ?? $oid);
            }

            if ($storedOrderIds === []) {
                throw new \RuntimeException('Se encontró el pack, pero no se pudieron sincronizar órdenes del pack: ' . $orderId);
            }
        }

        return [
            'order_id' => implode(',', array_values(array_unique($storedOrderIds))),
            'items' => $savedItems,
            'seller_id' => $this->resolveSellerId($user),
        ];
    }

    /**
     * @return list<string>
     */
    protected function resolveOrderIdsFromPackId(User $user, string $packId): array
    {
        $packId = trim($packId);
        if ($packId === '' || !ctype_digit($packId)) {
            return [];
        }

        $ids = [];
        $endpoints = [
            "https://api.mercadolibre.com/packs/{$packId}",
            "https://api.mercadolibre.com/marketplace/orders/pack/{$packId}",
        ];

        foreach ($endpoints as $url) {
            $resp = Http::withToken($user->access_token)
                ->timeout(60)
                ->acceptJson()
                ->get($url);

            if (!$resp->successful()) {
                continue;
            }

            $data = $resp->json();
            if (!is_array($data)) {
                continue;
            }

            foreach ((array) ($data['orders'] ?? $data['results'] ?? []) as $row) {
                $rawId = is_array($row) ? ($row['id'] ?? null) : $row;
                $id = trim((string) $rawId);
                if ($id !== '' && ctype_digit($id)) {
                    $ids[$id] = true;
                }
            }

            // En algunos casos /packs/{id} devuelve una sola orden "tipo order".
            if ($ids === [] && isset($data['id']) && isset($data['order_items'])) {
                $single = trim((string) $data['id']);
                if ($single !== '' && ctype_digit($single)) {
                    $ids[$single] = true;
                }
            }
        }

        return array_values(array_keys($ids));
    }

    protected function storeOrder(User $user, array $orderData): int
    {
        return DB::transaction(function () use ($user, $orderData) {
            $orderId = (string) ($orderData['id'] ?? '');

            if ($orderId === '') {
                throw new \RuntimeException('La orden no trae id válido.');
            }

            $status = (string) ($orderData['status'] ?? '');
            $resource = '/orders/' . $orderId;

            $dateCreated = $orderData['date_created'] ?? null;
            $dateUpdated = $orderData['last_updated'] ?? ($orderData['date_last_updated'] ?? null);

            $createdAt = $dateCreated
                ? Carbon::parse($dateCreated)->timezone(config('app.timezone'))
                : now();

            $updatedAt = $dateUpdated
                ? Carbon::parse($dateUpdated)->timezone(config('app.timezone'))
                : now();

            $shipping = $this->resolveShippingData($user, $orderData, $createdAt);
            $displayId = $this->resolveDisplayId($orderData, $shipping);

            $order = MeliOrder::updateOrCreate(
                ['order_id' => $orderId],
                [
                    'topic' => 'orders_v2',
                    'resource' => $resource,
                    'status' => $status,
                    'raw' => $orderData,

                    'shipping_id' => $shipping['shipping_id'],
                    'shipping_mode' => $shipping['shipping_mode'],
                    'shipping_type' => $shipping['shipping_type'],
                    'shipping_status' => $shipping['shipping_status'],
                    'shipping_substatus' => $shipping['shipping_substatus'],
                    'shipping_logistic_type' => $shipping['shipping_logistic_type'],
                    'shipping_process_date' => $shipping['shipping_process_date'],
                    'shipping_raw' => $shipping['shipping_raw'] ?? null,

                    'display_id' => $displayId,

                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]
            );

            MeliOrderItem::where('meli_order_id', $order->id)->delete();

            $items = $orderData['order_items'] ?? [];
            $savedItems = 0;

            foreach ($items as $item) {
                $itemInfo = $item['item'] ?? [];
                $variationText = $this->buildVariationText($itemInfo, $item);

                MeliOrderItem::create([
                    'meli_order_id' => $order->id,
                    'item_id' => (string) ($itemInfo['id'] ?? ''),
                    'sku' => (string) ($itemInfo['seller_sku'] ?? ''),
                    'title' => (string) ($itemInfo['title'] ?? ''),
                    'variation_text' => $variationText,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]);

                $savedItems++;
            }

            Log::info('ML order synced', [
                'order_id' => $orderId,
                'status' => $status,
                'shipping_id' => $shipping['shipping_id'],
                'shipping_mode' => $shipping['shipping_mode'],
                'shipping_type' => $shipping['shipping_type'],
                'shipping_status' => $shipping['shipping_status'],
                'shipping_substatus' => $shipping['shipping_substatus'],
                'shipping_logistic_type' => $shipping['shipping_logistic_type'],
                'shipping_process_date' => $shipping['shipping_process_date'],
                'display_id' => $displayId,
                'items' => $savedItems,
            ]);

            $order->load('items');
            $this->stockService->applyStockFromMeliOrderIfNeeded($order);
            $this->syscomOrderFromMeli->handleAfterMeliSync($user, $order);

            return $savedItems;
        });
    }

    protected function resolveSellerId(User $user): ?int
    {
        $response = Http::withToken($user->access_token)
            ->timeout(30)
            ->acceptJson()
            ->get('https://api.mercadolibre.com/users/me');

        if (!$response->successful()) {
            Log::error('ML users/me failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $user->id,
            ]);

            return null;
        }

        return (int) ($response->json('id') ?? 0);
    }

    protected function resolveShippingData(User $user, array $orderData, Carbon $createdAt): array
    {
        $orderShipping = is_array($orderData['shipping'] ?? null) ? $orderData['shipping'] : [];
        $shippingId = data_get($orderData, 'shipping.id') ?? data_get($orderShipping, 'id');
        $fulfilled = data_get($orderData, 'fulfilled');

        $fallbackMode = $this->normalizeShippingModeFromOrder($orderData, $orderShipping);
        $fallbackType = $this->resolveShippingTypeFromOrder($orderData, $orderShipping);

        $data = [
            'shipping_id' => $shippingId ? (string) $shippingId : null,
            'shipping_mode' => $fallbackMode,
            'shipping_type' => $fallbackType,
            'shipping_status' => data_get($orderShipping, 'status'),
            'shipping_substatus' => data_get($orderShipping, 'substatus'),
            'shipping_logistic_type' => data_get($orderShipping, 'logistic_type'),
            'shipping_process_date' => null,
            'shipping_raw' => null,
        ];

        if ($fulfilled === true) {
            $data['shipping_mode'] = 'fulfillment';
        }

        if ($shippingId) {
            $shipment = $this->fetchShipment($user, (string) $shippingId);

            if (is_array($shipment)) {
                $data = array_merge($data, $this->shippingAttributesFromShipment(
                    $shipment,
                    $orderData,
                    $createdAt,
                    $data['shipping_mode'],
                    $data['shipping_type']
                ));
            }
        }

        if (empty($data['shipping_process_date'])) {
            $data['shipping_process_date'] = $this->resolveShippingProcessDateFromOrder(
                $orderData,
                $createdAt,
                $data['shipping_mode']
            );
        }

        return $data;
    }

    /**
     * Actualiza en BD el estado del envío desde GET /shipments/{id} para todos los meli_orders con ese shipping_id.
     * Usado en AMS Procesar: el sync por día de creación no vuelve a leer envíos de órdenes viejas.
     *
     * @return int filas meli_orders tocadas
     */
    public function refreshShipmentsByShippingIds(User $user, array $shippingIds): int
    {
        $ids = [];
        foreach ($shippingIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        $delayUs = max(0, (int) config('ams_colecta.refresh_shipments_delay_micros', 80000));
        $touched = 0;

        foreach ($ids as $shippingId) {
            $shipment = $this->fetchShipment($user, $shippingId);
            if (!is_array($shipment)) {
                continue;
            }

            $orders = MeliOrder::query()->where('shipping_id', $shippingId)->get();
            if ($orders->isEmpty()) {
                continue;
            }

            $first = $orders->first();
            $orderData = $this->orderDataArrayFromModel($first);
            $createdAt = $first->created_at ? Carbon::parse($first->created_at) : now();

            $attrs = $this->shippingAttributesFromShipment(
                $shipment,
                $orderData,
                $createdAt,
                $first->shipping_mode,
                $first->shipping_type
            );

            $row = $this->meliOrderShippingUpdateRow($attrs);

            $n = MeliOrder::query()->where('shipping_id', $shippingId)->update($row);
            $touched += $n;

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        return $touched;
    }

    /**
     * @param  array<string, mixed>  $attrs  salida de shippingAttributesFromShipment
     * @return array<string, mixed>
     */
    protected function meliOrderShippingUpdateRow(array $attrs): array
    {
        $raw = $attrs['shipping_raw'] ?? null;
        if (is_array($raw)) {
            try {
                $attrs['shipping_raw'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $attrs['shipping_raw'] = null;
            }
        }

        $attrs['updated_at'] = now();

        return $attrs;
    }

    protected function orderDataArrayFromModel(MeliOrder $order): array
    {
        $raw = $order->raw;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $decoded : [];
            } catch (JsonException) {
                return [];
            }
        }

        return [];
    }

    /**
     * Campos de envío persistibles a partir del JSON de /shipments/{id}.
     *
     * @return array{shipping_raw: array, shipping_mode: ?string, shipping_type: ?string, shipping_status: ?string, shipping_substatus: ?string, shipping_logistic_type: ?string, shipping_process_date: string}
     */
    protected function shippingAttributesFromShipment(
        array $shipment,
        array $orderData,
        Carbon $createdAt,
        ?string $fallbackMode,
        ?string $fallbackType
    ): array {
        $mode = $this->normalizeShipmentMode($shipment, $orderData, $fallbackMode);

        return [
            'shipping_raw' => $shipment,
            'shipping_mode' => $mode,
            'shipping_type' => $this->resolveShipmentType($shipment, $fallbackType),
            'shipping_status' => $this->stringOrNull(data_get($shipment, 'status')),
            'shipping_substatus' => $this->stringOrNull(data_get($shipment, 'substatus')),
            'shipping_logistic_type' => $this->stringOrNull(
                data_get($shipment, 'logistic_type')
                ?? data_get($shipment, 'shipping_option.logistic_type')
            ),
            'shipping_process_date' => $this->resolveShippingProcessDateFromShipment(
                $shipment,
                $orderData,
                $createdAt,
                $mode
            ),
        ];
    }

    protected function fetchShipment(User $user, string $shippingId): ?array
    {
        $response = Http::withToken($user->access_token)
            ->timeout(30)
            ->acceptJson()
            ->get("https://api.mercadolibre.com/shipments/{$shippingId}");

        if (!$response->successful()) {
            Log::warning('ML shipment lookup failed', [
                'shipping_id' => $shippingId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    protected function normalizeShippingModeFromOrder(array $orderData, array $shipping): ?string
    {
        $fulfilled = data_get($orderData, 'fulfilled');

        if ($fulfilled === true) {
            return 'fulfillment';
        }

        $mode = data_get($shipping, 'mode')
            ?? data_get($orderData, 'shipping.mode')
            ?? null;

        if (is_string($mode) && trim($mode) !== '') {
            return trim($mode);
        }

        $logisticType = data_get($shipping, 'logistic_type')
            ?? data_get($orderData, 'shipping.logistic_type')
            ?? null;

        if (is_string($logisticType) && trim($logisticType) !== '') {
            return trim($logisticType);
        }

        return null;
    }

    protected function normalizeShipmentMode(array $shipment, array $orderData, ?string $fallback): ?string
    {
        $fulfilled = data_get($orderData, 'fulfilled');

        if ($fulfilled === true) {
            return 'fulfillment';
        }

        $mode = data_get($shipment, 'mode');
        if (is_string($mode) && trim($mode) !== '') {
            return trim($mode);
        }

        $logisticType = data_get($shipment, 'logistic_type')
            ?? data_get($shipment, 'shipping_option.logistic_type');

        if (is_string($logisticType) && trim($logisticType) !== '') {
            return trim($logisticType);
        }

        return $fallback;
    }

    protected function resolveShippingTypeFromOrder(array $orderData, array $shipping): ?string
    {
        $shippingType = data_get($shipping, 'shipping_option.name')
            ?? data_get($shipping, 'shipping_option.shipping_method_name')
            ?? data_get($shipping, 'shipping_type')
            ?? data_get($orderData, 'shipping.shipping_option.name')
            ?? null;

        return $this->stringOrNull($shippingType);
    }

    protected function resolveShipmentType(array $shipment, ?string $fallback): ?string
    {
        $shippingType = data_get($shipment, 'shipping_option.name')
            ?? data_get($shipment, 'shipping_option.shipping_method_name')
            ?? data_get($shipment, 'shipping_option.delivery_type')
            ?? data_get($shipment, 'shipping_option.type')
            ?? data_get($shipment, 'shipping_type')
            ?? null;

        return $this->stringOrNull($shippingType) ?? $fallback;
    }

    protected function resolveDisplayId(array $orderData, array $shipping): string
    {
        $packId = data_get($orderData, 'pack_id');
        $shippingId = $shipping['shipping_id'] ?? data_get($orderData, 'shipping.id');
        $orderId = data_get($orderData, 'id');

        if (!empty($packId)) {
            return 'PACK-' . $packId;
        }

        if (!empty($shippingId)) {
            return 'SHIP-' . $shippingId;
        }

        return (string) $orderId;
    }

    protected function resolveShippingProcessDateFromShipment(
        array $shipment,
        array $orderData,
        Carbon $createdAt,
        ?string $shippingMode
    ): string {
        $fulfilled = data_get($orderData, 'fulfilled');

        if ($fulfilled === true || $shippingMode === 'fulfillment') {
            return $createdAt->copy()->toDateString();
        }

        $candidates = [
            data_get($shipment, 'shipping_option.estimated_schedule.limit_date'),
            data_get($shipment, 'shipping_option.estimated_delivery_limit.date'),
            data_get($shipment, 'shipping_option.estimated_delivery_time.date'),
            data_get($shipment, 'lead_time.estimated_delivery_time.date'),
            data_get($shipment, 'lead_time.estimated_schedule.limit_date'),
            data_get($shipment, 'estimated_delivery_time.date'),
            data_get($shipment, 'estimated_handling_limit.date'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                try {
                    return Carbon::parse($candidate)
                        ->timezone(config('app.timezone'))
                        ->toDateString();
                } catch (\Throwable $e) {
                    // seguir al fallback
                }
            }
        }

        return $this->resolveShippingProcessDateFromOrder($orderData, $createdAt, $shippingMode);
    }

    protected function resolveShippingProcessDateFromOrder(
        array $orderData,
        Carbon $createdAt,
        ?string $shippingMode
    ): string {
        $fulfilled = data_get($orderData, 'fulfilled');

        if ($fulfilled === true || $shippingMode === 'fulfillment') {
            return $createdAt->copy()->toDateString();
        }

        $candidates = [
            data_get($orderData, 'shipping.shipping_option.estimated_delivery_time.date'),
            data_get($orderData, 'shipping.shipping_option.estimated_schedule_limit.date'),
            data_get($orderData, 'shipping.estimated_delivery_time.date'),
            data_get($orderData, 'shipping.estimated_schedule_limit.date'),
            data_get($orderData, 'shipping.receiver_address.delivery_preference.date'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                try {
                    return Carbon::parse($candidate)
                        ->timezone(config('app.timezone'))
                        ->toDateString();
                } catch (\Throwable $e) {
                    // seguir con fallback
                }
            }
        }

        $hour = (int) $createdAt->format('H');

        if ($hour >= 15) {
            return $createdAt->copy()->addDay()->toDateString();
        }

        return $createdAt->copy()->toDateString();
    }

    protected function buildVariationText(array $itemInfo, array $item): ?string
    {
        $attrs = $itemInfo['variation_attributes'] ?? [];

        if (!is_array($attrs) || empty($attrs)) {
            $attrs = $item['variation_attributes'] ?? [];
        }

        if (!is_array($attrs) || empty($attrs)) {
            return null;
        }

        $parts = [];

        foreach ($attrs as $attr) {
            $name = trim((string) ($attr['name'] ?? ''));
            $value = trim((string) ($attr['value_name'] ?? ''));

            if ($value === '') {
                $value = trim((string) ($attr['value_struct']['name'] ?? ''));
            }

            if ($value === '') {
                continue;
            }

            if ($name !== '' && str_contains(mb_strtolower($name), 'tono')) {
                $parts[] = $value;
                continue;
            }

            $parts[] = $name !== '' ? "{$name}: {$value}" : $value;
        }

        if (empty($parts)) {
            return null;
        }

        return implode(' | ', array_unique($parts));
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}