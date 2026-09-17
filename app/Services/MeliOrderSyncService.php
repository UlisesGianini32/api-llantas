<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

class MeliOrderSyncService
{
    public function __construct(
        protected StockService $stockService,
        protected SyscomOrderFromMeliService $syscomOrderFromMeli
    ) {}

    /**
     * Sincroniza todas las órdenes de un día para la cuenta de Mercado Libre
     * representada por el User recibido.
     *
     * El User puede ser:
     * - El usuario principal real.
     * - Un clon temporal con el token y meli_id de una cuenta secundaria.
     */
    public function syncDay(User $user, string $date): array
    {
        $sellerId = $this->resolveSellerId($user);

        if (!$sellerId) {
            throw new \RuntimeException(
                'No se pudo resolver el seller_id del usuario.'
            );
        }

        $meliAccountId = $this->resolveMeliAccountId($user);

        if (!$meliAccountId) {
            throw new \RuntimeException(
                'No se pudo identificar la cuenta de Mercado Libre vinculada.'
            );
        }

        $dateObj = Carbon::parse($date)->startOfDay();

        $from = $dateObj->copy()->format('Y-m-d')
            . 'T00:00:00.000-00:00';

        $to = $dateObj->copy()->format('Y-m-d')
            . 'T23:59:59.999-00:00';

        $offset = 0;
        $limit = 50;
        $totalSynced = 0;
        $ordersCount = 0;

        do {
            $response = Http::withToken($user->access_token)
                ->timeout(60)
                ->acceptJson()
                ->get(
                    'https://api.mercadolibre.com/orders/search',
                    [
                        'seller' => $sellerId,
                        'sort' => 'date_desc',
                        'offset' => $offset,
                        'limit' => $limit,
                        'order.date_created.from' => $from,
                        'order.date_created.to' => $to,
                    ]
                );

            if (!$response->successful()) {
                Log::error('ML orders search failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'seller_id' => $sellerId,
                    'meli_account_id' => $meliAccountId,
                    'date' => $date,
                    'offset' => $offset,
                ]);

                throw new \RuntimeException(
                    'Falló la consulta de órdenes a Mercado Libre: '
                    . $response->body()
                );
            }

            $data = $response->json();

            $results = is_array($data['results'] ?? null)
                ? $data['results']
                : [];

            $pagingTotal = (int) data_get(
                $data,
                'paging.total',
                0
            );

            foreach ($results as $orderData) {
                if (!is_array($orderData)) {
                    continue;
                }

                $savedItems = $this->storeOrder(
                    $user,
                    $orderData
                );

                $ordersCount++;
                $totalSynced += $savedItems;
            }

            $offset += $limit;
        } while ($offset < $pagingTotal);

        return [
            'orders' => $ordersCount,
            'items' => $totalSynced,
            'seller_id' => $sellerId,
            'meli_account_id' => $meliAccountId,
            'date' => $date,
        ];
    }

    /**
     * Sincroniza una orden específica o intenta resolver el valor recibido
     * como pack de Mercado Libre.
     */
    public function syncOrderById(
        User $user,
        string $orderId
    ): array {
        $orderId = trim($orderId);

        if ($orderId === '' || !ctype_digit($orderId)) {
            throw new \RuntimeException('order_id inválido.');
        }

        $meliAccountId = $this->resolveMeliAccountId($user);

        if (!$meliAccountId) {
            throw new \RuntimeException(
                'No se pudo identificar la cuenta de Mercado Libre vinculada.'
            );
        }

        $savedItems = 0;
        $storedOrderIds = [];

        $response = Http::withToken($user->access_token)
            ->timeout(60)
            ->acceptJson()
            ->get(
                "https://api.mercadolibre.com/orders/{$orderId}"
            );

        if ($response->successful()) {
            $orderData = $response->json();

            if (
                !is_array($orderData)
                || !isset($orderData['id'])
            ) {
                throw new \RuntimeException(
                    'Respuesta inválida al consultar la orden en Mercado Libre.'
                );
            }

            $savedItems += $this->storeOrder(
                $user,
                $orderData
            );

            $storedOrderIds[] = (string) (
                $orderData['id'] ?? $orderId
            );
        } else {
            /*
             * Si /orders/{id} falla, intentar resolver como PACK.
             * En AMS a veces se utiliza el número PACK en lugar de la orden.
             */
            $packOrderIds = $this->resolveOrderIdsFromPackId(
                $user,
                $orderId
            );

            if ($packOrderIds === []) {
                Log::error('ML get order/pack by id failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'input_id' => $orderId,
                    'user_id' => $user->id,
                    'meli_account_id' => $meliAccountId,
                    'meli_user_id' => $user->meli_id,
                ]);

                throw new \RuntimeException(
                    'No se encontró como orden ni como pack en Mercado Libre: '
                    . $orderId
                );
            }

            foreach ($packOrderIds as $oid) {
                $orderResp = Http::withToken(
                    $user->access_token
                )
                    ->timeout(60)
                    ->acceptJson()
                    ->get(
                        "https://api.mercadolibre.com/orders/{$oid}"
                    );

                if (!$orderResp->successful()) {
                    Log::warning(
                        'ML get order from pack failed',
                        [
                            'pack_id' => $orderId,
                            'order_id' => $oid,
                            'status' => $orderResp->status(),
                            'body' => $orderResp->body(),
                            'meli_account_id' => $meliAccountId,
                        ]
                    );

                    continue;
                }

                $orderData = $orderResp->json();

                if (
                    !is_array($orderData)
                    || !isset($orderData['id'])
                ) {
                    continue;
                }

                $savedItems += $this->storeOrder(
                    $user,
                    $orderData
                );

                $storedOrderIds[] = (string) (
                    $orderData['id'] ?? $oid
                );
            }

            if ($storedOrderIds === []) {
                throw new \RuntimeException(
                    'Se encontró el pack, pero no se pudieron sincronizar '
                    . 'órdenes del pack: '
                    . $orderId
                );
            }
        }

        return [
            'order_id' => implode(
                ',',
                array_values(
                    array_unique($storedOrderIds)
                )
            ),
            'items' => $savedItems,
            'seller_id' => $this->resolveSellerId($user),
            'meli_account_id' => $meliAccountId,
        ];
    }

    /**
     * Intenta obtener los IDs de órdenes que pertenecen a un pack.
     *
     * @return list<string>
     */
    protected function resolveOrderIdsFromPackId(
        User $user,
        string $packId
    ): array {
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

            $rows = $data['orders']
                ?? $data['results']
                ?? [];

            foreach ((array) $rows as $row) {
                $rawId = is_array($row)
                    ? ($row['id'] ?? null)
                    : $row;

                $id = trim((string) $rawId);

                if ($id !== '' && ctype_digit($id)) {
                    $ids[$id] = true;
                }
            }

            /*
             * En algunos casos /packs/{id} devuelve una sola orden
             * con estructura similar a /orders/{id}.
             */
            if (
                $ids === []
                && isset($data['id'])
                && isset($data['order_items'])
            ) {
                $single = trim((string) $data['id']);

                if (
                    $single !== ''
                    && ctype_digit($single)
                ) {
                    $ids[$single] = true;
                }
            }
        }

        return array_values(array_keys($ids));
    }

    /**
     * Guarda la orden y sus artículos en la base de datos.
     *
     * La cuenta se identifica utilizando:
     * - user.id: dueño local de las cuentas.
     * - user.meli_id: seller_id de la cuenta usada para la API.
     */
    protected function storeOrder(
        User $user,
        array $orderData
    ): int {
        return DB::transaction(
            function () use ($user, $orderData) {
                $orderId = trim(
                    (string) ($orderData['id'] ?? '')
                );

                if ($orderId === '') {
                    throw new \RuntimeException(
                        'La orden no trae id válido.'
                    );
                }

                $meliAccountId = $this->resolveMeliAccountId(
                    $user
                );

                if (!$meliAccountId) {
                    throw new \RuntimeException(
                        'No se pudo identificar la cuenta de Mercado Libre '
                        . 'para guardar la orden '
                        . $orderId
                        . '.'
                    );
                }

                $status = (string) (
                    $orderData['status'] ?? ''
                );

                $resource = '/orders/' . $orderId;

                $dateCreated = $orderData['date_created']
                    ?? null;

                $dateUpdated = $orderData['last_updated']
                    ?? (
                        $orderData['date_last_updated']
                        ?? null
                    );

                $createdAt = $dateCreated
                    ? Carbon::parse($dateCreated)
                        ->timezone(config('app.timezone'))
                    : now();

                $updatedAt = $dateUpdated
                    ? Carbon::parse($dateUpdated)
                        ->timezone(config('app.timezone'))
                    : now();

                $shipping = $this->resolveShippingData(
                    $user,
                    $orderData,
                    $createdAt
                );

                $displayId = $this->resolveDisplayId(
                    $orderData,
                    $shipping
                );

                /*
                 * order_id es único en Mercado Libre.
                 *
                 * Cuando una orden antigua ya existe sin meli_account_id,
                 * esta actualización le asignará la cuenta correcta.
                 */
                $order = MeliOrder::updateOrCreate(
                    [
                        'order_id' => $orderId,
                    ],
                    [
                        'meli_account_id' => $meliAccountId,
                        'topic' => 'orders_v2',
                        'resource' => $resource,
                        'status' => $status,
                        'raw' => $orderData,

                        'shipping_id' => $shipping['shipping_id'],
                        'shipping_mode' => $shipping['shipping_mode'],
                        'shipping_type' => $shipping['shipping_type'],
                        'shipping_status' => $shipping['shipping_status'],
                        'shipping_substatus' => $shipping['shipping_substatus'],
                        'shipping_logistic_type' => $shipping[
                            'shipping_logistic_type'
                        ],
                        'shipping_process_date' => $shipping[
                            'shipping_process_date'
                        ],
                        'shipping_raw' => $shipping[
                            'shipping_raw'
                        ] ?? null,

                        'display_id' => $displayId,

                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]
                );

                /*
                 * Reemplazamos los artículos para evitar duplicados
                 * cuando la misma orden se vuelve a sincronizar.
                 */
                MeliOrderItem::where(
                    'meli_order_id',
                    $order->id
                )->delete();

                $items = is_array(
                    $orderData['order_items'] ?? null
                )
                    ? $orderData['order_items']
                    : [];

                $savedItems = 0;

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $itemInfo = is_array(
                        $item['item'] ?? null
                    )
                        ? $item['item']
                        : [];

                    $variationText = $this->buildVariationText(
                        $itemInfo,
                        $item
                    );

                    MeliOrderItem::create([
                        'meli_order_id' => $order->id,
                        'item_id' => (string) (
                            $itemInfo['id'] ?? ''
                        ),
                        'sku' => (string) (
                            $itemInfo['seller_sku']
                            ?? $itemInfo['seller_custom_field']
                            ?? ''
                        ),
                        'title' => (string) (
                            $itemInfo['title'] ?? ''
                        ),
                        'variation_text' => $variationText,
                        'quantity' => (int) (
                            $item['quantity'] ?? 0
                        ),
                        'unit_price' => (float) (
                            $item['unit_price'] ?? 0
                        ),
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ]);

                    $savedItems++;
                }

                Log::info('ML order synced', [
                    'order_id' => $orderId,
                    'meli_account_id' => $meliAccountId,
                    'meli_user_id' => (string) $user->meli_id,
                    'status' => $status,
                    'shipping_id' => $shipping['shipping_id'],
                    'shipping_mode' => $shipping['shipping_mode'],
                    'shipping_type' => $shipping['shipping_type'],
                    'shipping_status' => $shipping['shipping_status'],
                    'shipping_substatus' => $shipping[
                        'shipping_substatus'
                    ],
                    'shipping_logistic_type' => $shipping[
                        'shipping_logistic_type'
                    ],
                    'shipping_process_date' => $shipping[
                        'shipping_process_date'
                    ],
                    'display_id' => $displayId,
                    'items' => $savedItems,
                ]);

                $order->load('items');

                /*
                 * Mantiene el comportamiento actual:
                 * descuenta el inventario según SKU vendido.
                 */
                $this->stockService
                    ->applyStockFromMeliOrderIfNeeded($order);

                /*
                 * Concilia la venta/cancelación contra el stock compartido
                 * de las cuentas 1 y 2. Los errores no detienen el sync normal.
                 */
                try {
                    app(\App\Services\MeliSharedStockOrderService::class)->reconcile($order);
                } catch (\Throwable $sharedStockException) {
                    report($sharedStockException);
                    \Illuminate\Support\Facades\Log::warning(
                        'MELI SHARED STOCK: no se pudo conciliar la orden',
                        [
                            'meli_order_id' => $order->id ?? null,
                            'order_id' => $order->order_id ?? null,
                            'meli_account_id' => $order->meli_account_id ?? null,
                            'error' => $sharedStockException->getMessage(),
                        ]
                    );
                }

                /*
                 * Mantiene la creación/sincronización de pedidos SYSCOM.
                 *
                 * El User recibido incluye el token y meli_id de la cuenta
                 * que originó la venta.
                 */
                $this->syscomOrderFromMeli
                    ->handleAfterMeliSync($user, $order);

                return $savedItems;
            }
        );
    }

    /**
     * Busca la fila de meli_accounts que corresponde al User/API User.
     *
     * Para una cuenta secundaria, el controlador crea un clon del usuario
     * principal y reemplaza meli_id y los tokens con los de MeliAccount.
     */
    protected function resolveMeliAccountId(
        User $user
    ): ?int {
        $ownerUserId = (int) ($user->id ?? 0);

        $meliUserId = trim(
            (string) ($user->meli_id ?? '')
        );

        if (
            $ownerUserId <= 0
            || $meliUserId === ''
        ) {
            return null;
        }

        $accountId = MeliAccount::query()
            ->where('user_id', $ownerUserId)
            ->where(
                'meli_user_id',
                $meliUserId
            )
            ->value('id');

        if ($accountId) {
            return (int) $accountId;
        }

        /*
         * Fallback para instalaciones donde la cuenta principal aún no fue
         * migrada completamente a meli_accounts.
         */
        $fallback = MeliAccount::query()
            ->where('user_id', $ownerUserId)
            ->where('is_default', true)
            ->value('id');

        return $fallback
            ? (int) $fallback
            : null;
    }

    /**
     * Consulta /users/me para comprobar a qué vendedor pertenece el token.
     */
    protected function resolveSellerId(
        User $user
    ): ?int {
        $response = Http::withToken($user->access_token)
            ->timeout(30)
            ->acceptJson()
            ->get(
                'https://api.mercadolibre.com/users/me'
            );

        if (!$response->successful()) {
            Log::error('ML users/me failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $user->id,
                'configured_meli_id' => $user->meli_id,
            ]);

            return null;
        }

        $sellerId = (int) (
            $response->json('id') ?? 0
        );

        if (
            $sellerId > 0
            && trim((string) $user->meli_id) !== ''
            && (string) $sellerId
                !== trim((string) $user->meli_id)
        ) {
            Log::warning(
                'ML token seller_id differs from configured meli_id',
                [
                    'user_id' => $user->id,
                    'token_seller_id' => $sellerId,
                    'configured_meli_id' => $user->meli_id,
                ]
            );
        }

        return $sellerId > 0
            ? $sellerId
            : null;
    }

    /**
     * Obtiene y normaliza la información de envío de una orden.
     */
    protected function resolveShippingData(
        User $user,
        array $orderData,
        Carbon $createdAt
    ): array {
        $orderShipping = is_array(
            $orderData['shipping'] ?? null
        )
            ? $orderData['shipping']
            : [];

        $shippingId = data_get(
            $orderData,
            'shipping.id'
        ) ?? data_get(
            $orderShipping,
            'id'
        );

        $fulfilled = data_get(
            $orderData,
            'fulfilled'
        );

        $fallbackMode = $this->normalizeShippingModeFromOrder(
            $orderData,
            $orderShipping
        );

        $fallbackType = $this->resolveShippingTypeFromOrder(
            $orderData,
            $orderShipping
        );

        $data = [
            'shipping_id' => $shippingId
                ? (string) $shippingId
                : null,

            'shipping_mode' => $fallbackMode,
            'shipping_type' => $fallbackType,

            'shipping_status' => $this->stringOrNull(
                data_get(
                    $orderShipping,
                    'status'
                )
            ),

            'shipping_substatus' => $this->stringOrNull(
                data_get(
                    $orderShipping,
                    'substatus'
                )
            ),

            'shipping_logistic_type' => $this->stringOrNull(
                data_get(
                    $orderShipping,
                    'logistic_type'
                )
            ),

            'shipping_process_date' => null,
            'shipping_raw' => null,
        ];

        if ($fulfilled === true) {
            $data['shipping_mode'] = 'fulfillment';
        }

        if ($shippingId) {
            $shipment = $this->fetchShipment(
                $user,
                (string) $shippingId
            );

            if (is_array($shipment)) {
                $data = array_merge(
                    $data,
                    $this->shippingAttributesFromShipment(
                        $shipment,
                        $orderData,
                        $createdAt,
                        $data['shipping_mode'],
                        $data['shipping_type']
                    )
                );
            }
        }

        if (empty($data['shipping_process_date'])) {
            $data['shipping_process_date'] =
                $this->resolveShippingProcessDateFromOrder(
                    $orderData,
                    $createdAt,
                    $data['shipping_mode']
                );
        }

        return $data;
    }

    /**
     * Actualiza en BD los estados de envíos obtenidos desde:
     *
     * GET /shipments/{id}
     *
     * Solo actualiza órdenes pertenecientes a la cuenta cuyo token se usó.
     *
     * @return int Cantidad de filas actualizadas.
     */
    public function refreshShipmentsByShippingIds(
        User $user,
        array $shippingIds
    ): int {
        $ids = [];

        foreach ($shippingIds as $id) {
            $id = trim((string) $id);

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);

        $meliAccountId = $this->resolveMeliAccountId(
            $user
        );

        if (!$meliAccountId) {
            Log::warning(
                'ML refresh shipments skipped: account not resolved',
                [
                    'user_id' => $user->id,
                    'meli_user_id' => $user->meli_id,
                    'shipping_ids' => $ids,
                ]
            );

            return 0;
        }

        $delayUs = max(
            0,
            (int) config(
                'ams_colecta.refresh_shipments_delay_micros',
                80000
            )
        );

        $touched = 0;

        foreach ($ids as $shippingId) {
            $shipment = $this->fetchShipment(
                $user,
                $shippingId
            );

            if (!is_array($shipment)) {
                continue;
            }

            /*
             * Es importante filtrar por meli_account_id.
             * No se deben actualizar órdenes de otra cuenta usando este token.
             */
            $orders = MeliOrder::query()
                ->where(
                    'shipping_id',
                    $shippingId
                )
                ->where(
                    'meli_account_id',
                    $meliAccountId
                )
                ->get();

            if ($orders->isEmpty()) {
                continue;
            }

            $first = $orders->first();

            $orderData = $this->orderDataArrayFromModel(
                $first
            );

            $createdAt = $first->created_at
                ? Carbon::parse($first->created_at)
                : now();

            $attrs = $this->shippingAttributesFromShipment(
                $shipment,
                $orderData,
                $createdAt,
                $first->shipping_mode,
                $first->shipping_type
            );

            $row = $this->meliOrderShippingUpdateRow(
                $attrs
            );

            $n = MeliOrder::query()
                ->where(
                    'shipping_id',
                    $shippingId
                )
                ->where(
                    'meli_account_id',
                    $meliAccountId
                )
                ->update($row);

            $touched += $n;

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        return $touched;
    }

    /**
     * Prepara los campos de envío para una actualización SQL.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    protected function meliOrderShippingUpdateRow(
        array $attrs
    ): array {
        $raw = $attrs['shipping_raw'] ?? null;

        if (is_array($raw)) {
            try {
                $attrs['shipping_raw'] = json_encode(
                    $raw,
                    JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                $attrs['shipping_raw'] = null;
            }
        }

        $attrs['updated_at'] = now();

        return $attrs;
    }

    /**
     * Devuelve el contenido raw de la orden como arreglo.
     */
    protected function orderDataArrayFromModel(
        MeliOrder $order
    ): array {
        $raw = $order->raw;

        if (is_array($raw)) {
            return $raw;
        }

        if (
            is_string($raw)
            && $raw !== ''
        ) {
            try {
                $decoded = json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                return is_array($decoded)
                    ? $decoded
                    : [];
            } catch (JsonException) {
                return [];
            }
        }

        return [];
    }

    /**
     * Convierte el JSON de /shipments/{id} en columnas persistibles.
     *
     * @return array{
     *     shipping_raw: array,
     *     shipping_mode: ?string,
     *     shipping_type: ?string,
     *     shipping_status: ?string,
     *     shipping_substatus: ?string,
     *     shipping_logistic_type: ?string,
     *     shipping_process_date: string
     * }
     */
    protected function shippingAttributesFromShipment(
        array $shipment,
        array $orderData,
        Carbon $createdAt,
        ?string $fallbackMode,
        ?string $fallbackType
    ): array {
        $mode = $this->normalizeShipmentMode(
            $shipment,
            $orderData,
            $fallbackMode
        );

        return [
            'shipping_raw' => $shipment,

            'shipping_mode' => $mode,

            'shipping_type' => $this->resolveShipmentType(
                $shipment,
                $fallbackType
            ),

            'shipping_status' => $this->stringOrNull(
                data_get(
                    $shipment,
                    'status'
                )
            ),

            'shipping_substatus' => $this->stringOrNull(
                data_get(
                    $shipment,
                    'substatus'
                )
            ),

            'shipping_logistic_type' => $this->stringOrNull(
                data_get(
                    $shipment,
                    'logistic_type'
                )
                ?? data_get(
                    $shipment,
                    'shipping_option.logistic_type'
                )
            ),

            'shipping_process_date' =>
                $this->resolveShippingProcessDateFromShipment(
                    $shipment,
                    $orderData,
                    $createdAt,
                    $mode
                ),
        ];
    }

    /**
     * Consulta el detalle de un envío utilizando el token de la cuenta.
     */
    protected function fetchShipment(
        User $user,
        string $shippingId
    ): ?array {
        $response = Http::withToken($user->access_token)
            ->timeout(30)
            ->acceptJson()
            ->get(
                "https://api.mercadolibre.com/shipments/{$shippingId}"
            );

        if (!$response->successful()) {
            Log::warning('ML shipment lookup failed', [
                'shipping_id' => $shippingId,
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $user->id,
                'meli_user_id' => $user->meli_id,
                'meli_account_id' => $this->resolveMeliAccountId(
                    $user
                ),
            ]);

            return null;
        }

        $json = $response->json();

        return is_array($json)
            ? $json
            : null;
    }

    /**
     * Determina el modo logístico usando los datos de la orden.
     */
    protected function normalizeShippingModeFromOrder(
        array $orderData,
        array $shipping
    ): ?string {
        $fulfilled = data_get(
            $orderData,
            'fulfilled'
        );

        if ($fulfilled === true) {
            return 'fulfillment';
        }

        $mode = data_get(
            $shipping,
            'mode'
        ) ?? data_get(
            $orderData,
            'shipping.mode'
        );

        if (
            is_string($mode)
            && trim($mode) !== ''
        ) {
            return trim($mode);
        }

        $logisticType = data_get(
            $shipping,
            'logistic_type'
        ) ?? data_get(
            $orderData,
            'shipping.logistic_type'
        );

        if (
            is_string($logisticType)
            && trim($logisticType) !== ''
        ) {
            return trim($logisticType);
        }

        return null;
    }

    /**
     * Determina el modo logístico usando la respuesta de shipments.
     */
    protected function normalizeShipmentMode(
        array $shipment,
        array $orderData,
        ?string $fallback
    ): ?string {
        $fulfilled = data_get(
            $orderData,
            'fulfilled'
        );

        if ($fulfilled === true) {
            return 'fulfillment';
        }

        $mode = data_get(
            $shipment,
            'mode'
        );

        if (
            is_string($mode)
            && trim($mode) !== ''
        ) {
            return trim($mode);
        }

        $logisticType = data_get(
            $shipment,
            'logistic_type'
        ) ?? data_get(
            $shipment,
            'shipping_option.logistic_type'
        );

        if (
            is_string($logisticType)
            && trim($logisticType) !== ''
        ) {
            return trim($logisticType);
        }

        return $fallback;
    }

    /**
     * Obtiene una descripción legible del tipo de envío desde la orden.
     */
    protected function resolveShippingTypeFromOrder(
        array $orderData,
        array $shipping
    ): ?string {
        $shippingType = data_get(
            $shipping,
            'shipping_option.name'
        )
            ?? data_get(
                $shipping,
                'shipping_option.shipping_method_name'
            )
            ?? data_get(
                $shipping,
                'shipping_type'
            )
            ?? data_get(
                $orderData,
                'shipping.shipping_option.name'
            );

        return $this->stringOrNull($shippingType);
    }

    /**
     * Obtiene una descripción legible del tipo de envío desde shipments.
     */
    protected function resolveShipmentType(
        array $shipment,
        ?string $fallback
    ): ?string {
        $shippingType = data_get(
            $shipment,
            'shipping_option.name'
        )
            ?? data_get(
                $shipment,
                'shipping_option.shipping_method_name'
            )
            ?? data_get(
                $shipment,
                'shipping_option.delivery_type'
            )
            ?? data_get(
                $shipment,
                'shipping_option.type'
            )
            ?? data_get(
                $shipment,
                'shipping_type'
            );

        return $this->stringOrNull($shippingType)
            ?? $fallback;
    }

    /**
     * Define el identificador visible en AMS.
     */
    protected function resolveDisplayId(
        array $orderData,
        array $shipping
    ): string {
        $packId = data_get(
            $orderData,
            'pack_id'
        );

        $shippingId = $shipping['shipping_id']
            ?? data_get(
                $orderData,
                'shipping.id'
            );

        $orderId = data_get(
            $orderData,
            'id'
        );

        if (!empty($packId)) {
            return 'PACK-' . $packId;
        }

        if (!empty($shippingId)) {
            return 'SHIP-' . $shippingId;
        }

        return (string) $orderId;
    }

    /**
     * Resuelve la fecha de procesamiento usando la respuesta del envío.
     */
    protected function resolveShippingProcessDateFromShipment(
        array $shipment,
        array $orderData,
        Carbon $createdAt,
        ?string $shippingMode
    ): string {
        $fulfilled = data_get(
            $orderData,
            'fulfilled'
        );

        if (
            $fulfilled === true
            || $shippingMode === 'fulfillment'
        ) {
            return $createdAt
                ->copy()
                ->toDateString();
        }

        $candidates = [
            data_get(
                $shipment,
                'shipping_option.estimated_schedule.limit_date'
            ),
            data_get(
                $shipment,
                'shipping_option.estimated_delivery_limit.date'
            ),
            data_get(
                $shipment,
                'shipping_option.estimated_delivery_time.date'
            ),
            data_get(
                $shipment,
                'lead_time.estimated_delivery_time.date'
            ),
            data_get(
                $shipment,
                'lead_time.estimated_schedule.limit_date'
            ),
            data_get(
                $shipment,
                'estimated_delivery_time.date'
            ),
            data_get(
                $shipment,
                'estimated_handling_limit.date'
            ),
        ];

        foreach ($candidates as $candidate) {
            if (
                is_string($candidate)
                && trim($candidate) !== ''
            ) {
                try {
                    return Carbon::parse($candidate)
                        ->timezone(config('app.timezone'))
                        ->toDateString();
                } catch (\Throwable) {
                    // Continuar al siguiente candidato.
                }
            }
        }

        return $this->resolveShippingProcessDateFromOrder(
            $orderData,
            $createdAt,
            $shippingMode
        );
    }

    /**
     * Resuelve la fecha de procesamiento usando únicamente la orden.
     */
    protected function resolveShippingProcessDateFromOrder(
        array $orderData,
        Carbon $createdAt,
        ?string $shippingMode
    ): string {
        $fulfilled = data_get(
            $orderData,
            'fulfilled'
        );

        if (
            $fulfilled === true
            || $shippingMode === 'fulfillment'
        ) {
            return $createdAt
                ->copy()
                ->toDateString();
        }

        $candidates = [
            data_get(
                $orderData,
                'shipping.shipping_option.estimated_delivery_time.date'
            ),
            data_get(
                $orderData,
                'shipping.shipping_option.estimated_schedule_limit.date'
            ),
            data_get(
                $orderData,
                'shipping.estimated_delivery_time.date'
            ),
            data_get(
                $orderData,
                'shipping.estimated_schedule_limit.date'
            ),
            data_get(
                $orderData,
                'shipping.receiver_address.delivery_preference.date'
            ),
        ];

        foreach ($candidates as $candidate) {
            if (
                is_string($candidate)
                && trim($candidate) !== ''
            ) {
                try {
                    return Carbon::parse($candidate)
                        ->timezone(config('app.timezone'))
                        ->toDateString();
                } catch (\Throwable) {
                    // Continuar con el fallback.
                }
            }
        }

        $hour = (int) $createdAt->format('H');

        if ($hour >= 15) {
            return $createdAt
                ->copy()
                ->addDay()
                ->toDateString();
        }

        return $createdAt
            ->copy()
            ->toDateString();
    }

    /**
     * Construye el texto de variación visible en AMS.
     */
    protected function buildVariationText(
        array $itemInfo,
        array $item
    ): ?string {
        $attrs = $itemInfo['variation_attributes']
            ?? [];

        if (
            !is_array($attrs)
            || empty($attrs)
        ) {
            $attrs = $item['variation_attributes']
                ?? [];
        }

        if (
            !is_array($attrs)
            || empty($attrs)
        ) {
            return null;
        }

        $parts = [];

        foreach ($attrs as $attr) {
            if (!is_array($attr)) {
                continue;
            }

            $name = trim(
                (string) ($attr['name'] ?? '')
            );

            $value = trim(
                (string) ($attr['value_name'] ?? '')
            );

            if ($value === '') {
                $value = trim(
                    (string) (
                        $attr['value_struct']['name']
                        ?? ''
                    )
                );
            }

            if ($value === '') {
                continue;
            }

            if (
                $name !== ''
                && str_contains(
                    mb_strtolower($name),
                    'tono'
                )
            ) {
                $parts[] = $value;

                continue;
            }

            $parts[] = $name !== ''
                ? "{$name}: {$value}"
                : $value;
        }

        if (empty($parts)) {
            return null;
        }

        return implode(
            ' | ',
            array_unique($parts)
        );
    }

    /**
     * Devuelve un string limpio o null.
     */
    protected function stringOrNull(
        mixed $value
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            ? $value
            : null;
    }
}