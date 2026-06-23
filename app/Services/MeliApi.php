<?php

namespace App\Services;

use App\Models\User;
use App\Support\MeliPostSaleMessaging;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * Cliente API api.mercadolibre.com (órdenes, packs, mensajes posventa con tag según doc ML).
 *
 * @see \App\Support\MeliPostSaleMessaging::DOCS_URL
 */
class MeliApi
{
    public function __construct(private Client $http = new Client([
        'base_uri' => 'https://api.mercadolibre.com',
        'timeout'  => 20,
    ])) {}

    public function getOrder(User $user, int $orderId): array
    {
        return $this->getJson($user, "/orders/{$orderId}");
    }

    /**
     * Posventa: muchos mensajes enlazan solo "packs" en message_resources; las órdenes vienen aquí.
     */
    public function getPack(User $user, string $packId): array
    {
        $id = rawurlencode($packId);

        return $this->getJson($user, "/packs/{$id}");
    }

    /**
     * El id en message_resources "packs" suele ser pack_id; a veces GET /packs devuelve 404 y sirve
     * /marketplace/orders/pack/{id} o el mismo número es en realidad order_id.
     */
    public function resolveFirstOrderIdFromPackResource(User $user, string $resourceId): ?string
    {
        $id = rawurlencode($resourceId);
        $paths = [
            "/packs/{$id}",
            "/marketplace/orders/pack/{$id}",
        ];
        foreach ($paths as $path) {
            $data = $this->tryGetJson($user, $path);
            if ($data === null) {
                continue;
            }
            $oid = $this->firstOrderIdFromPackLikeResponse($data);
            if ($oid !== null) {
                return $oid;
            }
        }

        $order = $this->tryGetJson($user, "/orders/{$id}");
        if ($order !== null && isset($order['id']) && $order['id'] !== '') {
            return $this->normalizeNumericOrderId($order['id']);
        }

        return null;
    }

    private function tryGetJson(User $user, string $path): ?array
    {
        try {
            $data = $this->getJson($user, $path);

            return $data;
        } catch (ClientException) {
            return null;
        }
    }

    private function firstOrderIdFromPackLikeResponse(array $data): ?string
    {
        if (!empty($data['orders']) && is_array($data['orders'])) {
            return $this->firstOrderIdFromOrdersList($data['orders']);
        }
        if (!empty($data['results']) && is_array($data['results'])) {
            return $this->firstOrderIdFromOrdersList($data['results']);
        }
        if (isset($data['id'], $data['order_items']) && is_array($data['order_items'])) {
            return $this->normalizeNumericOrderId($data['id']);
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $orders
     */
    private function firstOrderIdFromOrdersList(array $orders): ?string
    {
        foreach ($orders as $o) {
            if (is_array($o)) {
                $oid = $o['id'] ?? null;
            } else {
                $oid = $o;
            }
            if ($oid === null || $oid === '') {
                continue;
            }

            return $this->normalizeNumericOrderId($oid);
        }

        return null;
    }

    private function normalizeNumericOrderId(mixed $oid): string
    {
        $s = preg_replace('/\D+/', '', (string) $oid);

        return ($s !== '' && ctype_digit($s)) ? $s : (string) $oid;
    }

    /**
     * @param  bool  $postSaleTag  false = GET sin query (a veces ML devuelve message_resources completos solo así)
     */
    public function getMessage(User $user, string $messageId, bool $postSaleTag = true): array
    {
        $id = rawurlencode($messageId);
        $suffix = $postSaleTag
            ? ('?tag=' . rawurlencode(MeliPostSaleMessaging::TAG_POST_SALE))
            : '';

        return $this->getJson($user, "/messages/{$id}{$suffix}");
    }

    /**
     * Historial posventa del pack (GET marca como leídos salvo mark_as_read=false).
     *
     * @see https://developers.mercadolibre.com.mx/es_ar/mensajeria-post-venta
     */
    public function getPackPostSaleMessages(
        User $user,
        string $packId,
        int $limit = 50,
        int $offset = 0,
        bool $markAsRead = false
    ): array {
        $pid = rawurlencode($packId);
        $sid = rawurlencode((string) $user->meli_id);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $query = http_build_query([
            'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
            'limit' => $limit,
            'offset' => $offset,
            'mark_as_read' => $markAsRead ? 'true' : 'false',
        ]);

        return $this->getJson($user, "/messages/packs/{$pid}/sellers/{$sid}?{$query}");
    }

    /**
     * Permalink público del ítem (detalle en ML). Útil cuando no hay fila en meli_publications.
     */
    public function tryGetItemPermalink(User $user, string $itemId): ?string
    {
        $id = rawurlencode($itemId);
        try {
            $item = $this->getJson($user, "/items/{$id}");
        } catch (ClientException) {
            return null;
        }

        $p = trim((string) ($item['permalink'] ?? ''));

        return $p !== '' ? $p : null;
    }

    public function resolveSkuFromItem(User $user, string $itemId): ?string
    {
        // Trae el item. Puedes pedir solo algunos campos, pero lo dejamos simple.
        $item = $this->getJson($user, "/items/{$itemId}");

        // 1) seller_custom_field (súper recomendado guardarlo al publicar)
        $scf = trim((string)($item['seller_custom_field'] ?? ''));
        if ($scf !== '') return $scf;

        // 2) atributo SELLER_SKU (a veces viene como atributo)
        $attrs = (array)($item['attributes'] ?? []);
        foreach ($attrs as $a) {
            if (($a['id'] ?? '') === 'SELLER_SKU') {
                $v = trim((string)($a['value_name'] ?? ''));
                if ($v !== '') return $v;
            }
        }

        return null;
    }

    private function getJson(User $user, string $path): array
    {
        $res = $this->http->get($path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $user->access_token,
                'Accept' => 'application/json',
            ],
        ]);

        $body = (string)$res->getBody();
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
    }
}