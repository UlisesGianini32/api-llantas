<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\MeliPublication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MeliMenuAutomationService
{
    public function __construct(
        protected MeliMessageService $meliMessageService,
        protected MeliApi $meliApi,
        protected TelegramAlertService $telegramAlerts,
    ) {
    }

    public function handleIncomingEvent(array $payload, ?int $userId = null): void
    {
        $data = $this->normalizePayload($payload, $userId);

        if (($data['event_type'] ?? null) === 'order_created') {
            Log::info('MeliMenuAutomationService: order_created ignorado (MeLi exige que el comprador escriba primero en posventa).');

            return;
        }

        if (!$data['order_id'] && !$data['conversation_id']) {
            Log::warning('MeliMenuAutomationService: evento sin order_id ni conversation_id', [
                'payload' => $payload,
            ]);

            return;
        }

        if ($this->isBuyerMessageWithThreadKeys($data)) {
            [$duplicate, $flow] = $this->claimInboundMessageOrSkip($data);
            if ($duplicate) {
                Log::info('MeliMenuAutomationService: duplicado omitido (lock DB, mismo message_id)', [
                    'message_id' => $data['message_id'],
                    'order_id' => $data['order_id'],
                ]);

                return;
            }
        } else {
            $flow = $this->findOrCreateFlow($data);
        }

        $this->syncFlowContext($flow, $data);

        if (($data['event_type'] ?? null) === 'buyer_message') {
            if (!$flow->fresh()->menu_sent) {
                $this->sendMenuIfNeeded($flow->fresh());

                return;
            }

            $buyerText = (string) ($data['message_text'] ?? '');
            $this->handleBuyerReply($flow->fresh(), $buyerText);

            return;
        }
    }

    /**
     * Serializa workers concurrentes: un solo proceso por (order, buyer, message_id).
     * last_inbound_message_id se fija aquí antes de enviar a ML.
     *
     * @return array{0: bool, 1: MeliChatFlow} [duplicateSkipped, flow]
     */
    protected function claimInboundMessageOrSkip(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $keys = [
                'meli_account_id' => $data['meli_account_id'],
                'order_id' => $data['order_id'],
                'buyer_id' => $data['buyer_id'],
            ];

            try {
                $flow = MeliChatFlow::firstOrCreate($keys, $this->newFlowCreateAttributes($data));
            } catch (QueryException $e) {
                if (!$this->isUniqueConstraintViolation($e)) {
                    throw $e;
                }
                $flow = MeliChatFlow::query()->where($keys)->firstOrFail();
            }

            $locked = MeliChatFlow::query()->whereKey($flow->id)->lockForUpdate()->firstOrFail();

            if (($locked->last_inbound_message_id ?? null) === $data['message_id']) {
                return [true, $locked];
            }

            $locked->update(['last_inbound_message_id' => $data['message_id']]);

            return [false, $locked->fresh()];
        });
    }

    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 1062) {
            return true;
        }
        if ($driverCode === 19) {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'duplicate') || str_contains($msg, 'unique constraint');
    }

    protected function isBuyerMessageWithThreadKeys(array $data): bool
    {
        return ($data['event_type'] ?? null) === 'buyer_message'
            && !empty($data['message_id'])
            && !empty($data['order_id'])
            && !empty($data['buyer_id']);
    }

    /** @return array<string, mixed> */
    protected function newFlowCreateAttributes(array $data): array
    {
        return [
            'user_id' => $data['user_id'],
            'meli_account_id' => $data['meli_account_id'],
            'pack_id' => $data['pack_id'],
            'conversation_id' => $data['conversation_id'],
            'message_id' => $data['message_id'],
            'item_id' => $data['item_id'],
            'sku' => $data['sku'],
            'product_pdf_url' => $data['product_pdf_url'],
            'catalog_pdf_url' => config('meli_menu.catalog_pdf_url'),
            'invoice_url' => config('meli_menu.invoice_url'),
            'meta' => array_merge(
                is_array($data['meta'] ?? null) ? $data['meta'] : [],
                array_filter([
                    'site_id' => $data['site_id'] ?? null,
                ])
            ),
        ];
    }

    protected function syncFlowContext(MeliChatFlow $flow, array $data): void
    {
        $updates = [];

        if (!empty($data['pack_id'])) {
            $updates['pack_id'] = $data['pack_id'];
        }

        if (!empty($data['user_id'])) {
            $updates['user_id'] = $data['user_id'];
        }

        if (!empty($data['meli_account_id'])) {
            $updates['meli_account_id'] = $data['meli_account_id'];
        }

        if (!empty($data['site_id'])) {
            $updates['meta'] = array_merge($flow->meta ?? [], [
                'site_id' => $data['site_id'],
            ]);
        }

        if ($updates !== []) {
            $flow->update($updates);
            $flow->refresh();
        }
    }

    public function sendMenuIfNeeded(MeliChatFlow $flow): bool
    {
        if ($flow->menu_sent) {
            return false;
        }

        $message = $this->buildMainMenuMessage();

        $sent = $this->meliMessageService->sendMessage(
            flow: $flow,
            text: $message
        );

        if ($sent) {
            $flow->update([
                'menu_sent' => true,
                'menu_sent_at' => now(),
            ]);
        }

        return $sent;
    }

    public function handleBuyerReply(MeliChatFlow $flow, string $buyerText): void
    {
        $flow = $flow->fresh();
        $trimmed = trim($buyerText);

        if ($this->isBotMutedUntilMenu($flow)) {
            if ($trimmed !== '' && $this->isMenuResumeKeyword($trimmed)) {
                $this->resumeMenuAfterMenuKeyword($flow);
            }

            return;
        }

        if ($trimmed === '') {
            return;
        }

        $option = $this->extractOption($buyerText);

        if (!$option) {
            $this->meliMessageService->sendMessage(
                flow: $flow,
                text: $this->buildInvalidOptionMessage()
            );

            return;
        }

        $flow->update([
            'last_option_selected' => $option,
            'last_option_selected_at' => now(),
        ]);

        match ($option) {
            '1' => $this->handleProductDetails($flow),
            '2' => $this->handleCatalog($flow),
            '3' => $this->handleBilling($flow),
            '4' => $this->handleHumanSupport($flow),
            default => $this->meliMessageService->sendMessage(
                flow: $flow,
                text: $this->buildInvalidOptionMessage()
            ),
        };
    }

    protected function handleProductDetails(MeliChatFlow $flow): void
    {
        $flow = $this->ensureItemIdFromOrderIfMissing($flow);
        $detailUrl = $flow->product_pdf_url ?: $this->resolveProductDetailUrl($flow);

        if (!$detailUrl) {
            $this->meliMessageService->sendMessage(
                flow: $flow,
                text: 'No tenemos un enlace de ficha listo para este producto. Un asesor te apoyara en breve.'
            );

            $this->markRequiresHuman($flow);

            return;
        }

        $message = "Detalle del producto:\n{$detailUrl}\n\nSi necesitas otra opcion, responde solo con un numero (1, 2, 3 o 4).";
        $this->meliMessageService->sendMessage(flow: $flow, text: $message);

        $flow->update([
            'product_pdf_url' => $detailUrl,
        ]);
    }

    protected function handleCatalog(MeliChatFlow $flow): void
    {
        $catalogUrl = trim((string) ($flow->catalog_pdf_url ?: config('meli_menu.catalog_pdf_url')));
        if ($catalogUrl === '') {
            $catalogUrl = trim((string) config('meli_menu.catalog_fallback_url', ''));
        }

        if ($catalogUrl === '') {
            $this->meliMessageService->sendMessage(
                flow: $flow,
                text: 'Catalogo: aun no tenemos un enlace general configurado. Un asesor te lo comparte en breve. Responde 4 para asistencia.'
            );
            $this->markRequiresHuman($flow);

            return;
        }

        $message = "Catalogo:\n{$catalogUrl}\n\nSi necesitas otra opcion, responde solo con un numero (1, 2, 3 o 4).";
        $this->meliMessageService->sendMessage(flow: $flow, text: $message);

        $flow->update([
            'catalog_pdf_url' => $catalogUrl,
        ]);
    }

    protected function handleBilling(MeliChatFlow $flow): void
    {
        $invoiceUrl = trim((string) ($flow->invoice_url ?: config('meli_menu.invoice_url', '')));
        $orderId = trim((string) ($flow->order_id ?? ''));

        if ($invoiceUrl === '') {
            $invoiceUrl = 'No disponible por ahora, un asesor te compartira el enlace.';
        }

        if ($orderId === '') {
            $orderId = 'No disponible por ahora, compartenos captura de tu compra para ayudarte.';
        }

        $message = "Facturacion:\n"
            . "Pagina para facturar: {$invoiceUrl}\n"
            . "ID de la venta: {$orderId}\n"
            . "Importante: tienes 9 dias a partir de tu compra para facturar.\n\n"
            . "Si necesitas otra opcion, responde solo con un numero (1, 2, 3 o 4).";
        $this->meliMessageService->sendMessage(flow: $flow, text: $message);

        $flow->update([
            'invoice_url' => $invoiceUrl,
        ]);
    }

    protected function handleHumanSupport(MeliChatFlow $flow): void
    {
        $this->setBotMutedUntilMenu($flow);

        $this->meliMessageService->sendMessage(
            flow: $flow,
            text: 'Un asesor te contactara en breve para asistencia personalizada. Si necesitas el menu otra vez, escribe la palabra: menu'
        );

        $this->markRequiresHuman($flow);
        $this->notifyTelegramAdvisorRequest($flow->fresh());
    }

    protected function markRequiresHuman(MeliChatFlow $flow): void
    {
        $flow->update([
            'requires_human' => true,
            'requires_human_at' => now(),
        ]);
    }

    protected function isBotMutedUntilMenu(MeliChatFlow $flow): bool
    {
        return ! empty(($flow->meta ?? [])['bot_muted_until_menu']);
    }

    protected function setBotMutedUntilMenu(MeliChatFlow $flow): void
    {
        $meta = $flow->meta ?? [];
        $meta['bot_muted_until_menu'] = true;
        $flow->update(['meta' => $meta]);
    }

    protected function isMenuResumeKeyword(string $trimmed): bool
    {
        return strcasecmp(trim($trimmed), 'menu') === 0;
    }

    protected function resumeMenuAfterMenuKeyword(MeliChatFlow $flow): void
    {
        $meta = $flow->meta ?? [];
        unset($meta['bot_muted_until_menu']);
        $flow->update(['meta' => $meta]);

        $this->meliMessageService->sendMessage(
            flow: $flow->fresh(),
            text: $this->buildMainMenuMessage()
        );
    }

    protected function notifyTelegramAdvisorRequest(MeliChatFlow $flow): void
    {
        $orderId = trim((string) ($flow->order_id ?? ''));
        $buyerName = '—';
        $lines = '—';

        $user = $this->resolveApiUserForFlow($flow);
        if ($user && $orderId !== '' && ctype_digit($orderId)) {
            $order = $this->meliApi->getOrder($user, (int) $orderId);
            if ($order !== []) {
                $buyerName = $this->formatBuyerNameFromOrder($order);
                $lines = $this->formatOrderLinesForTelegram($order);
            }
        }

        $this->telegramAlerts->notifyMeliAdvisorRequest($orderId, $buyerName, $lines);
    }

    /** @param  array<string, mixed>  $order */
    protected function formatBuyerNameFromOrder(array $order): string
    {
        $b = (array) ($order['buyer'] ?? []);
        $nick = trim((string) ($b['nickname'] ?? ''));
        $fn = trim((string) ($b['first_name'] ?? ''));
        $ln = trim((string) ($b['last_name'] ?? ''));
        $full = trim($fn.' '.$ln);
        if ($full !== '') {
            return $full;
        }

        return $nick !== '' ? $nick : '—';
    }

    /** @param  array<string, mixed>  $order */
    protected function formatOrderLinesForTelegram(array $order): string
    {
        $out = [];
        foreach ((array) ($order['order_items'] ?? []) as $oi) {
            if (! is_array($oi)) {
                continue;
            }
            $item = (array) ($oi['item'] ?? []);
            $title = trim((string) ($item['title'] ?? ''));
            $q = $oi['quantity'] ?? null;
            if ($title === '') {
                continue;
            }
            $out[] = $title.' x'.($q !== null ? (string) $q : '?');
        }

        return $out !== [] ? implode("\n", $out) : '—';
    }

    protected function ensureItemIdFromOrderIfMissing(MeliChatFlow $flow): MeliChatFlow
    {
        if ($flow->item_id || ! $flow->user_id || ! $flow->order_id) {
            return $flow;
        }

        $oid = trim((string) $flow->order_id);
        if ($oid === '' || ! ctype_digit($oid)) {
            return $flow;
        }

        $user = $this->resolveApiUserForFlow($flow);
        if (! $user || $user->access_token === null || $user->access_token === '') {
            return $flow;
        }

        $order = $this->meliApi->getOrder($user, (int) $oid);
        $items = (array) ($order['order_items'] ?? []);
        if ($items === []) {
            return $flow;
        }

        $first = (array) $items[0];
        $itemInfo = (array) ($first['item'] ?? []);
        $id = isset($itemInfo['id']) ? (string) $itemInfo['id'] : '';
        if ($id === '') {
            return $flow;
        }

        $flow->update(['item_id' => $id]);

        return $flow->fresh();
    }

    protected function extractOption(string $buyerText): ?string
    {
        $text = trim($buyerText);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^[1-4]$/u', $text)) {
            return $text;
        }

        if (config('meli_menu.menu_keyword_synonyms')) {
            $lower = Str::lower($text);

            return $this->matchKeywordOption($lower);
        }

        return null;
    }

    /**
     * Solo si MELI_MENU_KEYWORD_SYNONYMS=true en .env.
     *
     * @deprecated  Preferir solo numeros; se mantiene por compatibilidad.
     */
    protected function matchKeywordOption(string $text): ?string
    {
        if (str_contains($text, 'factura')
            || str_contains($text, 'facturación')
            || str_contains($text, 'facturacion')) {
            return '3';
        }

        if (str_contains($text, 'catálogo') || str_contains($text, 'catalogo')) {
            return '2';
        }

        if (str_contains($text, 'asesor')
            || str_contains($text, 'soporte')
            || str_contains($text, 'operador')
            || (str_contains($text, 'ticket') && ! str_contains($text, 'entrada'))) {
            return '4';
        }

        if (str_contains($text, 'detalle') || str_contains($text, 'ficha')) {
            return '1';
        }

        if (mb_strlen($text) <= 48 && preg_match('/\bayuda\b/u', $text)) {
            return '4';
        }

        return null;
    }

    protected function buildMainMenuMessage(): string
    {
        return "Hola, gracias por tu compra.\n"
            . "Menu (responde solo con un numero):\n"
            . "1 Detalle del producto\n"
            . "2 Catalogo\n"
            . "3 Facturacion\n"
            . "4 Ticket / Asesor";
    }

    protected function buildInvalidOptionMessage(): string
    {
        return "Opcion no reconocida. Responde solo con un numero: 1, 2, 3 o 4.\n"
            . "1 Detalle del producto\n"
            . "2 Catalogo\n"
            . "3 Facturacion\n"
            . "4 Ticket / Asesor";
    }

    protected function findOrCreateFlow(array $data): MeliChatFlow
    {
        return MeliChatFlow::firstOrCreate(
            [
                'meli_account_id' => $data['meli_account_id'],
                'order_id' => $data['order_id'] ?: 'no-order-' . ($data['conversation_id'] ?: uniqid()),
                'buyer_id' => $data['buyer_id'] ?: 'unknown',
            ],
            $this->newFlowCreateAttributes($data)
        );
    }

    protected function normalizePayload(array $payload, ?int $userId = null): array
    {
        return [
            'user_id' => $userId,
            'meli_account_id' => isset($payload['meli_account_id'])
                ? (int) $payload['meli_account_id']
                : null,
            'event_type' => $payload['event_type'] ?? null,
            'order_id' => isset($payload['order_id']) ? (string) $payload['order_id'] : null,
            'pack_id' => isset($payload['pack_id']) ? (string) $payload['pack_id'] : null,
            'conversation_id' => $payload['conversation_id'] ?? null,
            'message_id' => $payload['message_id'] ?? null,
            'buyer_id' => isset($payload['buyer_id']) ? (string) $payload['buyer_id'] : null,
            'item_id' => $payload['item_id'] ?? null,
            'sku' => $payload['sku'] ?? null,
            'site_id' => $payload['site_id'] ?? null,
            'message_text' => $payload['message_text'] ?? null,
            'product_pdf_url' => $payload['product_pdf_url'] ?? null,
            'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        ];
    }

    /**
     * Prioridad: permalink de publicación ML en BD → URL de respaldo en .env → (opcional) ruta /pdfs/...
     */
    protected function resolveProductDetailUrl(MeliChatFlow $flow): ?string
    {
        $permalink = $this->resolvePublicationPermalink($flow);
        if ($permalink) {
            return $permalink;
        }

        $apiPermalink = $this->resolveItemPermalinkFromMeliApi($flow);
        if ($apiPermalink) {
            return $apiPermalink;
        }

        $fallback = trim((string) config('meli_menu.product_detail_fallback_url', ''));
        if ($fallback !== '') {
            return $fallback;
        }

        if (config('meli_menu.use_product_pdf_path')) {
            return $this->resolveProductPdfUrl($flow);
        }

        return null;
    }

    protected function resolveItemPermalinkFromMeliApi(MeliChatFlow $flow): ?string
    {
        if (! $flow->item_id || ! $flow->user_id) {
            return null;
        }

        $user = $this->resolveApiUserForFlow($flow);
        if (! $user || $user->access_token === null || $user->access_token === '') {
            return null;
        }

        return $this->meliApi->tryGetItemPermalink($user, (string) $flow->item_id);
    }

    protected function resolvePublicationPermalink(MeliChatFlow $flow): ?string
    {
        $scoped = function () use ($flow) {
            $q = MeliPublication::query()
                ->whereNotNull('permalink')
                ->where('permalink', '!=', '');

            if ($flow->user_id) {
                $q->where('user_id', $flow->user_id);
            }

            return $q;
        };

        if ($flow->item_id) {
            $p = $scoped()->where('mlm', (string) $flow->item_id)->orderByDesc('id')->value('permalink');
            if ($p) {
                return $p;
            }
        }

        if ($flow->sku) {
            $p = $scoped()->where('sku', (string) $flow->sku)->orderByDesc('id')->value('permalink');
            if ($p) {
                return $p;
            }
        }

        return null;
    }


    protected function resolveApiUserForFlow(MeliChatFlow $flow): ?User
    {
        $owner = $flow->user_id
            ? User::query()->find($flow->user_id)
            : null;

        if (! $owner) {
            return null;
        }

        $account = null;

        if ($flow->meli_account_id) {
            $account = MeliAccount::query()
                ->where('user_id', $owner->id)
                ->whereKey($flow->meli_account_id)
                ->first();
        }

        if (! $account) {
            $account = $owner->meliAccounts()
                ->where('is_default', true)
                ->first()
                ?? $owner->meliAccounts()->orderBy('id')->first();
        }

        if (
            ! $account
            || ! filled($account->meli_user_id)
            || ! filled($account->access_token)
        ) {
            return null;
        }

        if (! $flow->meli_account_id) {
            $flow->forceFill([
                'meli_account_id' => $account->id,
            ])->save();
        }

        /** @var User $apiUser */
        $apiUser = clone $owner;

        $apiUser->forceFill([
            'meli_id' => $account->meli_user_id,
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_at' => $account->expires_at,
            'official_store_id' => $account->official_store_id,
        ]);

        $apiUser->setAttribute('id', $owner->id);
        $apiUser->setAttribute('meli_account_id', $account->id);

        return $apiUser;
    }

    protected function resolveProductPdfUrl(MeliChatFlow $flow): ?string
    {
        if ($flow->sku) {
            return url('/pdfs/productos/' . $flow->sku . '.pdf');
        }

        if ($flow->item_id) {
            return url('/pdfs/productos/' . $flow->item_id . '.pdf');
        }

        return null;
    }
}
