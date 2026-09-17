<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Models\User;
use App\Services\MeliApi;
use App\Services\MeliMenuAutomationService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMeliMessageNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /** Evita que ML (varios workers) procese el mismo message_id en paralelo. */
    public int $uniqueFor = 3600;

    public function __construct(public array $payload) {}

    public function uniqueId(): string
    {
        $mid = trim((string) ($this->payload['resource'] ?? ''));
        $uid = (string) ($this->payload['user_id'] ?? '');

        return 'meli-postsale-msg:' . $uid . ':' . $mid;
    }

    public function handle(MeliApi $meli, MeliMenuAutomationService $menu): void
    {
        $messageId = trim((string) ($this->payload['resource'] ?? ''));
        if ($messageId === '' || str_contains($messageId, '/')) {
            Log::warning('ProcessMeliMessageNotification: resource de mensaje inválido', [
                'payload' => $this->payload,
            ]);

            return;
        }

        $actions = (array) ($this->payload['actions'] ?? []);
        if ($actions !== [] && !$this->actionsIndicateNewInboundMessage($actions)) {
            Log::info('ProcessMeliMessageNotification: ignorado por actions', [
                'actions' => $actions,
                'message_id' => $messageId,
            ]);

            return;
        }

        $recipientMeliId = (string) ($this->payload['user_id'] ?? '');

        $user = $this->resolveUserForMeliSellerId($recipientMeliId);

        if (! $user) {
            Log::warning('ProcessMeliMessageNotification: vendedor no encontrado por meli_id', [
                'recipient_meli_id' => $recipientMeliId,
                'message_id' => $messageId,
            ]);

            return;
        }

        $this->runExclusiveForMessageId($messageId, function () use ($meli, $menu, $messageId, $actions, $user): void {
            $this->processMeliMessageAfterLock($meli, $menu, $messageId, $actions, $user);
        });
    }

    /**
     * Un solo worker a la vez por message_id: GET_LOCK en MySQL (varios php-fpm/workers)
     * o Cache::lock si la BD no es mysql/mariadb.
     */
    private function runExclusiveForMessageId(string $messageId, \Closure $callback): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $lockName = substr(hash('sha256', 'meli_ps_msg|' . $messageId), 0, 64);
            $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS v', [$lockName]);
            if ((int) ($row->v ?? 0) !== 1) {
                Log::info('ProcessMeliMessageNotification: omitido — otro worker tiene el lock (GET_LOCK)', [
                    'message_id' => $messageId,
                ]);

                return;
            }
            try {
                $callback();
            } finally {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS r', [$lockName]);
            }

            return;
        }

        $lock = Cache::lock('meli-msg-notify:' . hash('sha256', $messageId), 120);
        if (!$lock->get()) {
            Log::info('ProcessMeliMessageNotification: omitido — Cache::lock ocupado', [
                'message_id' => $messageId,
                'cache_store' => (string) config('cache.default'),
            ]);

            return;
        }
        try {
            $callback();
        } finally {
            $lock->release();
        }
    }

    private function processMeliMessageAfterLock(MeliApi $meli, MeliMenuAutomationService $menu, string $messageId, array $actions, User $user): void
    {
        Log::info('ProcessMeliMessageNotification: inicio', [
            'message_id' => $messageId,
            'actions' => $actions,
        ]);

        try {
            $raw = $meli->getMessage($user, $messageId);
        } catch (ClientException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($status === 404) {
                Log::warning('ProcessMeliMessageNotification: mensaje 404 en ML (id de prueba, inexistente o fuera de posventa)', [
                    'message_id' => $messageId,
                ]);
            } else {
                Log::error('ProcessMeliMessageNotification: fallo GET mensaje', [
                    'message_id' => $messageId,
                    'status' => $status,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        } catch (\Throwable $e) {
            Log::error('ProcessMeliMessageNotification: fallo GET mensaje', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $msg = $this->normalizeMessagePayload($raw);

        $sellerId = (string) ($user->meli_id ?? '');
        $fromId = (string) data_get($msg, 'from.user_id', '');

        $orderId = $this->resolvePostSaleOrderIdFromMessage($msg);
        if ($orderId === null) {
            $orderId = $this->resolveOrderIdFromPackMessage($meli, $user, $msg);
        }
        if ($orderId === null && $this->messageMentionsPackResource($msg)) {
            try {
                $rawAlt = $meli->getMessage($user, $messageId, false);
                $msgAlt = $this->normalizeMessagePayload($rawAlt);
                $orderId = $this->resolvePostSaleOrderIdFromMessage($msgAlt)
                    ?? $this->resolveOrderIdFromPackMessage($meli, $user, $msgAlt);
            } catch (ClientException $e) {
                Log::warning('ProcessMeliMessageNotification: GET mensaje sin tag=post_sale falló', [
                    'message_id' => $messageId,
                    'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
                ]);
            } catch (\Throwable $e) {
                Log::warning('ProcessMeliMessageNotification: GET mensaje sin tag=post_sale falló', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($orderId === null) {
            $resourceNames = [];
            foreach ((array) ($msg['message_resources'] ?? []) as $r) {
                if (is_array($r) && isset($r['name'])) {
                    $resourceNames[] = (string) $r['name'];
                }
            }
            Log::info('ProcessMeliMessageNotification: mensaje no asociado a orden, ignorado', [
                'message_id' => $messageId,
                'resource' => $msg['resource'] ?? null,
                'message_resource_names' => $resourceNames,
            ]);

            return;
        }

        $text = $msg['text'] ?? '';
        if (is_array($text)) {
            $text = (string) ($text['plain'] ?? $text['text'] ?? '');
        }
        $text = trim((string) $text);

        $order = $meli->getOrder($user, (int) $orderId);
        if ($order === []) {
            Log::warning('ProcessMeliMessageNotification: orden vacía desde API', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $buyerId = (string) data_get($order, 'buyer.id', '');
        if ($buyerId === '') {
            Log::warning('ProcessMeliMessageNotification: comprador no resuelto', [
                'order_id' => $orderId,
            ]);

            return;
        }

        $fromBuyer = $fromId !== '' && $buyerId !== '' && $fromId === $buyerId;
        if (!$fromBuyer && $this->shouldIgnoreAsSellerOutboundAutomation($text, $sellerId, $fromId, $buyerId)) {
            Log::info('ProcessMeliMessageNotification: ignorado — salida del vendedor o aviso sin texto útil (evita re-procesar menú / lecturas)', [
                'message_id' => $messageId,
                'from_id' => $fromId,
                'buyer_id' => $buyerId,
                'actions' => $actions,
                'text_preview' => mb_substr($text, 0, 80),
            ]);

            return;
        }

        $siteId = (string) (data_get($order, 'context.site')
            ?? data_get($order, 'site_id')
            ?? config('meli_menu.default_site_id', 'MLM'));

        $packId = $this->extractMessageResourceIdByName($msg, 'packs');
        if (!$packId && data_get($order, 'pack_id')) {
            $packId = (string) $order['pack_id'];
        }
        if (!$packId) {
            $packId = $orderId;
        }

        $itemId = null;
        $sku = null;
        $orderItems = (array) ($order['order_items'] ?? []);
        if ($orderItems !== []) {
            $first = $orderItems[0];
            $itemInfo = (array) ($first['item'] ?? []);
            $itemId = isset($itemInfo['id']) ? (string) $itemInfo['id'] : null;
            if ($itemId) {
                $sku = $meli->resolveSkuFromItem($user, $itemId);
            }
        }

        Log::info('ProcessMeliMessageNotification: menú posventa', [
            'message_id' => $messageId,
            'order_id' => $orderId,
            'buyer_id' => $buyerId,
            'from_id' => $fromId,
        ]);

        $menu->handleIncomingEvent([
            'event_type' => 'buyer_message',
            'order_id' => $orderId,
            'buyer_id' => $buyerId,
            'pack_id' => $packId,
            'message_id' => $messageId,
            'message_text' => $text,
            'item_id' => $itemId,
            'sku' => $sku,
            'site_id' => $siteId,
            'meta' => [
                'webhook' => $this->payload,
                'message_snapshot' => $msg,
            ],
        ], $user->id);
    }

    /**
     * ML envía distintas actions: "created", "read", vacío, etc.
     * En logs reales llegó solo ["read"] para posventa; si solo aceptábamos "created", el bot nunca corría.
     * Idempotencia: GET_LOCK/Cache en este job + claim en MeliMenuAutomationService.
     *
     * Orden: resource/resource_id, message_resources "orders", o GET /packs/{id} cuando solo hay "packs".
     */
    private function resolvePostSaleOrderIdFromMessage(array $msg): ?string
    {
        if (($msg['resource'] ?? '') === 'orders') {
            $id = (string) ($msg['resource_id'] ?? '');
            if ($id !== '' && ctype_digit($id)) {
                return $id;
            }
        }

        foreach ((array) ($msg['message_resources'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (strtolower((string) ($r['name'] ?? '')) !== 'orders') {
                continue;
            }
            $rawId = (string) ($r['id'] ?? '');
            if ($rawId === '') {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $rawId);
            if ($digits !== '' && ctype_digit($digits)) {
                return $digits;
            }
        }

        return null;
    }

    private function resolveOrderIdFromPackMessage(MeliApi $meli, User $user, array $msg): ?string
    {
        $packId = $this->extractMessageResourceIdByName($msg, 'packs');
        if ($packId === null || $packId === '') {
            if ($this->messageMentionsPackResource($msg)) {
                Log::warning('ProcessMeliMessageNotification: hay recurso packs pero sin id usable', [
                    'message_id' => $msg['id'] ?? null,
                ]);
            }

            return null;
        }

        Log::info('ProcessMeliMessageNotification: resolviendo orden vía recurso packs', [
            'pack_or_resource_id' => $packId,
        ]);

        $orderId = $meli->resolveFirstOrderIdFromPackResource($user, $packId);
        if ($orderId !== null) {
            return $orderId;
        }

        Log::warning('ProcessMeliMessageNotification: no se resolvió orden (GET /packs, marketplace/pack ni /orders)', [
            'pack_or_resource_id' => $packId,
        ]);

        return null;
    }

    /**
     * En posventa ML puede notificar eventos sobre mensajes del propio vendedor (incluyendo "read").
     * Para evitar bucles, ignoramos salidas claras del bot/vendedor cuando from coincide con seller.
     */
    private function shouldIgnoreAsSellerOutboundAutomation(string $text, string $sellerId, string $fromId, string $buyerId): bool
    {
        if ($sellerId === '' || $fromId === '' || $fromId !== $sellerId) {
            return false;
        }
        if ($buyerId !== '' && $fromId === $buyerId) {
            return false;
        }
        $t = trim($text);

        return $t === '' || $this->messageTextLooksLikeAutomatedReply($t);
    }

    /**
     * Coherente con textos generados en \App\Services\MeliMenuAutomationService.
     * Usamos inicios/fragmentos estables para tolerar ajustes menores de copy sin romper el filtro.
     */
    private function messageTextLooksLikeAutomatedReply(string $text): bool
    {
        if (str_contains($text, 'Hola, gracias por tu compra')
            && str_contains($text, 'Menu (responde')
            && str_contains($text, '1 Detalle del producto')
            && str_contains($text, '4 Ticket / Asesor')) {
            return true;
        }

        if (str_contains($text, 'Opcion no reconocida')
            && str_contains($text, '1, 2, 3 o 4')) {
            return true;
        }

        if (str_starts_with($text, 'Detalle del producto:')
            || str_starts_with($text, 'Catalogo:')
            || str_starts_with($text, 'Factura (solicitala a tiempo):')
            || str_starts_with($text, 'Facturacion:')
            || str_contains($text, 'Pagina para facturar:')
            || str_contains($text, 'No tenemos un enlace de ficha listo para este producto')
            || str_contains($text, 'Un asesor te contactara en breve para asistencia personalizada.')
            || str_contains($text, 'escribe la palabra: menu')) {
            return true;
        }

        return false;
    }

    private function normalizeMessagePayload(array $raw): array
    {
        if (isset($raw['messages'][0]) && is_array($raw['messages'][0])) {
            return $raw['messages'][0];
        }

        return $raw;
    }

    private function messageMentionsPackResource(array $msg): bool
    {
        foreach ((array) ($msg['message_resources'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (strtolower((string) ($r['name'] ?? '')) === 'packs') {
                return true;
            }
        }

        return false;
    }

    private function extractMessageResourceIdByName(array $msg, string $resourceName): ?string
    {
        $want = strtolower($resourceName);
        foreach ((array) ($msg['message_resources'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (strtolower((string) ($r['name'] ?? '')) !== $want) {
                continue;
            }
            foreach (['id', 'resource_id', 'resourceId'] as $key) {
                if (!array_key_exists($key, $r)) {
                    continue;
                }
                $v = $r[$key];
                if (is_array($v) && isset($v['id'])) {
                    $v = $v['id'];
                }
                $s = trim((string) $v);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        $top = trim((string) data_get($msg, 'pack_id', ''));
        if ($top !== '' && $want === 'packs') {
            return $top;
        }

        return null;
    }

    /**
     * Varias tiendas MeLi por usuario: el webhook trae el user_id del vendedor (cualquier cuenta vinculada).
     */
    private function resolveUserForMeliSellerId(string $recipientMeliId): ?User
    {
        if ($recipientMeliId === '') {
            return null;
        }

        $account = MeliAccount::query()
            ->where('meli_user_id', $recipientMeliId)
            ->whereNotNull('access_token')
            ->first();

        if ($account && $account->user) {
            $user = $account->user;
            $user->forceFill([
                'meli_id' => $account->meli_user_id,
                'access_token' => $account->access_token,
                'refresh_token' => $account->refresh_token,
                'expires_at' => $account->expires_at,
            ]);

            return $user;
        }

        return User::query()
            ->where('meli_id', $recipientMeliId)
            ->whereNotNull('access_token')
            ->first();
    }

    private function actionsIndicateNewInboundMessage(array $actions): bool
    {
        foreach ($actions as $a) {
            $s = strtolower((string) $a);
            if ($s === 'created' || $s === 'read') {
                return true;
            }
            if (str_contains($s, 'created') && !str_contains($s, 'destroy')) {
                return true;
            }
            if (str_contains($s, 'read') && !str_contains($s, 'unread')) {
                return true;
            }
        }

        return false;
    }
}
