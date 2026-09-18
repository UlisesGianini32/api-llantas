<?php

namespace App\Services\Telegram;

use App\Jobs\ImportExcelFromTelegramJob;
use App\Jobs\SyncMeliOpenClaimsForTelegramJob;
use App\Models\MeliChatFlow;
use App\Models\MeliClaim;
use App\Models\TelegramProcessedUpdate;
use App\Services\MeliMessageService;
use App\Services\MercadoLibre\Claims\MeliClaimActionCatalog;
use App\Services\MercadoLibre\Claims\MeliClaimActionService;
use App\Services\MercadoLibre\Claims\MeliClaimMessagePolicy;
use App\Services\MercadoLibre\Claims\MeliClaimMessageSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class TelegramOperationsService
{
    public function __construct(
        private TelegramBotClient $telegram,
        private TelegramStateService $states,
        private TelegramClaimService $claims,
        private TelegramPostSaleService $postSale,
        private TelegramPostSaleSyncCoordinator $postSaleSync,
        private MeliClaimActionCatalog $claimActionCatalog,
        private MeliClaimActionService $claimActions,
        private MeliClaimMessagePolicy $claimPolicy,
        private MeliClaimMessageSender $claimSender,
        private MeliMessageService $postSaleSender,
    ) {}

    public function handle(array $update): void
    {
        $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
        $message = $callback['message'] ?? $update['message'] ?? $update['channel_post'] ?? null;
        if (! is_array($message)) {
            return;
        }
        $chatId = (string) data_get($message, 'chat.id', '');
        $callbackId = (string) ($callback['id'] ?? '');

        if ($chatId === '' || ! $this->authorized($chatId)) {
            if ($chatId !== '') {
                Log::warning('Telegram webhook: chat no autorizado', ['chat_id' => $chatId]);
            }
            if ($callbackId !== '') {
                $this->telegram->answerCallback($callbackId);
            }

            return;
        }

        if (! $this->claimUpdate($update, $chatId, $callbackId)) {
            if ($callbackId !== '') {
                $this->telegram->answerCallback($callbackId);
            }

            return;
        }

        try {
            if ($callback) {
                $this->handleCallback($chatId, (string) data_get($message, 'message_id', ''), (string) ($callback['data'] ?? ''));
            } else {
                $this->handleMessage($chatId, $message);
            }
        } catch (Throwable $error) {
            report($error);
            $this->telegram->sendMessage($chatId, 'No fue posible completar la operación. Intenta nuevamente desde el menú.', $this->homeKeyboard());
        } finally {
            if ($callbackId !== '') {
                $this->telegram->answerCallback($callbackId);
            }
        }
    }

    private function handleMessage(string $chatId, array $message): void
    {
        $document = is_array($message['document'] ?? null) ? $message['document'] : null;
        if ($document) {
            $fileId = (string) ($document['file_id'] ?? '');
            $fileName = (string) ($document['file_name'] ?? 'archivo.xlsx');
            if ($fileId !== '' && in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) {
                ImportExcelFromTelegramJob::dispatch($chatId, $fileId, $fileName);
                $this->states->clear($chatId);
            }

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $command = mb_strtolower(preg_replace('/@[^\s]+$/u', '', $text) ?? $text);
        if (in_array($command, ['/start', '/menu', 'hola'], true)) {
            $this->states->clear($chatId);
            $this->showMainMenu($chatId);

            return;
        }
        if (in_array($command, ['/cancel', '/cancelar'], true)) {
            $this->states->clear($chatId);
            $this->telegram->sendMessage($chatId, '❌ Operación cancelada.', $this->homeKeyboard());

            return;
        }

        $state = $this->states->get($chatId);
        if (! $state) {
            return;
        }
        if ($state->mode === 'awaiting_claim_reply') {
            $this->captureReply($chatId, 'claim', (int) $state->entity_id, $text);
        } elseif ($state->mode === 'awaiting_claim_partial_refund_amount') {
            $this->capturePartialRefundAmount($chatId, (int) $state->entity_id, $text);
        } elseif ($state->mode === 'awaiting_post_sale_reply') {
            $this->captureReply($chatId, 'post_sale', (int) $state->entity_id, $text);
        }
    }

    private function handleCallback(string $chatId, string $messageId, string $data): void
    {
        if ($data === 'm') {
            $this->states->clear($chatId);
            $this->showMainMenu($chatId, $messageId);

            return;
        }
        if ($data === 'x') {
            $this->states->put($chatId, 'awaiting_excel');
            $this->telegram->editOrSend($chatId, $messageId, "📊 SUBIR EXCEL\n\nEnvíame el archivo Excel que deseas importar.", $this->homeKeyboard());

            return;
        }
        if ($data === 'c') {
            $this->render($chatId, $messageId, $this->claims->menu());

            return;
        }
        if ($data === 'p') {
            $this->render($chatId, $messageId, $this->postSale->menu());

            return;
        }
        if ($data === 'pu') {
            $started = $this->postSaleSync->start($chatId);
            $message = $started
                ? '🔄 La sincronización de mensajería posventa quedó en cola.'
                : '🔄 La sincronización de mensajería posventa ya está en curso.';
            $this->telegram->editOrSend($chatId, $messageId, $message, [[['text' => '💬 Posventa', 'callback_data' => 'p']], ...$this->homeKeyboard()]);

            return;
        }
        if ($data === 'cs') {
            SyncMeliOpenClaimsForTelegramJob::dispatch($chatId);
            $this->telegram->editOrSend($chatId, $messageId, '🔄 La sincronización de reclamos abiertos quedó en cola.', [[['text' => '🚨 Reclamos', 'callback_data' => 'c']], ...$this->homeKeyboard()]);

            return;
        }
        if ($data === 'cancel') {
            $this->states->clear($chatId);
            $this->telegram->editOrSend($chatId, $messageId, '❌ Operación cancelada.', $this->homeKeyboard());

            return;
        }

        $parts = explode(':', $data);
        $action = array_shift($parts);
        match ($action) {
            'cl' => $this->render($chatId, $messageId, $this->claims->listing($parts[0] ?? 'o', (int) ($parts[1] ?? 1))),
            'cd' => $this->renderNullable($chatId, $messageId, $this->claims->detail((int) ($parts[0] ?? 0), $parts[1] ?? 'o', (int) ($parts[2] ?? 1))),
            'cc' => $this->renderNullable($chatId, $messageId, $this->claims->conversation((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0))),
            'cr' => $this->startClaimReply($chatId, $messageId, (int) ($parts[0] ?? 0)),
            'cok' => $this->confirmClaimReply($chatId, $messageId, (int) ($parts[0] ?? 0)),
            'ced' => $this->editReply($chatId, $messageId, 'claim', (int) ($parts[0] ?? 0)),
            'ca' => $this->renderNullable($chatId, $messageId, $this->claims->actions((int) ($parts[0] ?? 0))),
            'caa' => $this->startClaimAction($chatId, $messageId, (int) ($parts[0] ?? 0), (string) ($parts[1] ?? '')),
            'cac' => $this->confirmClaimAction($chatId, $messageId, (int) ($parts[0] ?? 0)),
            'cam' => $this->changePartialRefundAmount($chatId, $messageId, (int) ($parts[0] ?? 0)),
            'pl' => $this->render($chatId, $messageId, $this->postSale->listing($parts[0] ?? 'a', (int) ($parts[1] ?? 1))),
            'pd' => $this->renderNullable($chatId, $messageId, $this->postSale->detail((int) ($parts[0] ?? 0), $parts[1] ?? 'a', (int) ($parts[2] ?? 1))),
            'pc' => $this->renderNullable($chatId, $messageId, $this->postSale->conversation((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0))),
            'pr' => $this->startPostSaleReply($chatId, $messageId, (int) ($parts[0] ?? 0)),
            'pok' => $this->confirmPostSaleReply($chatId, $messageId, (int) ($parts[0] ?? 0)),
            'ped' => $this->editReply($chatId, $messageId, 'post_sale', (int) ($parts[0] ?? 0)),
            default => $this->invalid($chatId, $messageId),
        };
    }

    private function captureReply(string $chatId, string $type, int $entityId, string $text): void
    {
        $max = $type === 'post_sale' ? max(1, (int) config('meli_menu.seller_max_message_length', 350)) : 2000;
        if ($text === '' || mb_strlen($text) > $max) {
            $this->telegram->sendMessage($chatId, "El mensaje debe tener entre 1 y {$max} caracteres.", [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);

            return;
        }
        $mode = $type === 'claim' ? 'confirm_claim_reply' : 'confirm_post_sale_reply';
        $currentPayload = $this->states->get($chatId)?->payload ?? [];
        $this->states->put($chatId, $mode, $type, $entityId, [...$currentPayload, 'text' => $text]);
        $prefix = $type === 'claim' ? 'c' : 'p';
        $this->telegram->sendMessage($chatId, "¿Enviar este mensaje?\n\n“{$text}”", [
            [['text' => '✅ Enviar', 'callback_data' => "{$prefix}ok:{$entityId}"]],
            [['text' => '✏️ Editar', 'callback_data' => "{$prefix}ed:{$entityId}"], ['text' => '❌ Cancelar', 'callback_data' => 'cancel']],
        ]);
    }

    private function startClaimAction(string $chatId, string $messageId, int $claimId, string $callback): void
    {
        $claim = MeliClaim::query()->with(['meliAccount.user', 'order.items'])->find($claimId);
        $descriptor = $this->claimActionCatalog->fromCallback($callback);
        $available = $claim ? collect($this->claimActionCatalog->available($claim))
            ->first(fn (array $item): bool => $item['callback'] === $callback) : null;
        $actor = $claim?->meliAccount?->user;
        if (! $claim || ! $actor || ! $descriptor || ! $available) {
            $this->invalid($chatId, $messageId, 'Esta acción ya no está disponible en Mercado Libre.');

            return;
        }
        if (! $this->claimActions->canAct($actor, $claim)) {
            $this->invalid($chatId, $messageId, 'Este operador no tiene permiso para ejecutar acciones sobre el reclamo.');

            return;
        }
        if ($descriptor['flow'] === 'message') {
            $this->startClaimReply($chatId, $messageId, $claimId, $descriptor['receiver_role']);

            return;
        }

        try {
            $prepared = $this->claimActions->prepare($actor, $claim, $descriptor['action']);
        } catch (Throwable $error) {
            report($error);
            $this->invalid($chatId, $messageId, 'No fue posible verificar esta acción con Mercado Libre.');

            return;
        }
        if (! $prepared['ok']) {
            $this->invalid($chatId, $messageId, '⚠️ '.$prepared['message']);

            return;
        }
        if ($descriptor['action'] === 'partial_refund') {
            $offers = collect($prepared['offers'])->filter(fn (array $offer): bool => is_numeric($offer['amount'] ?? null)
                && filled($offer['currency_id'] ?? null))->values()->all();
            if ($offers === []) {
                $this->invalid($chatId, $messageId, 'Mercado Libre no devolvió importes válidos para el reembolso parcial.');

                return;
            }
            $this->states->put($chatId, 'awaiting_claim_partial_refund_amount', 'claim', $claimId, [
                'action' => 'partial_refund',
                'offers' => $offers,
            ]);
            $currencies = collect($offers)->pluck('currency_id')->unique()->implode(', ');
            $this->telegram->editOrSend(
                $chatId,
                $messageId,
                "💵 REEMBOLSO PARCIAL\n\nEscribe el importe exacto a ofrecer en {$currencies}. Usa hasta dos decimales.",
                [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]
            );

            return;
        }

        $this->states->put($chatId, 'confirm_claim_action', 'claim', $claimId, ['action' => $descriptor['action']]);
        $this->renderClaimActionConfirmation($chatId, $messageId, $claim, $descriptor['action']);
    }

    private function capturePartialRefundAmount(string $chatId, int $claimId, string $text): void
    {
        $state = $this->states->get($chatId);
        if (! $state || $state->mode !== 'awaiting_claim_partial_refund_amount' || (int) $state->entity_id !== $claimId) {
            return;
        }
        $value = trim($text);
        if (! preg_match('/^\d+(?:[.,]\d{1,2})?$/', $value)) {
            $this->telegram->sendMessage($chatId, 'El importe debe ser numérico, mayor que cero y tener como máximo dos decimales.', [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);

            return;
        }
        $amount = (float) str_replace(',', '.', $value);
        if ($amount <= 0) {
            $this->telegram->sendMessage($chatId, 'El importe debe ser mayor que cero.', [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);

            return;
        }
        $matches = collect((array) data_get($state->payload, 'offers', []))
            ->filter(fn (mixed $offer): bool => is_array($offer) && is_numeric($offer['amount'] ?? null)
                && (int) round((float) $offer['amount'] * 100) === (int) round($amount * 100))
            ->values();
        if ($matches->count() !== 1) {
            $allowed = collect((array) data_get($state->payload, 'offers', []))
                ->filter(fn (mixed $offer): bool => is_array($offer) && is_numeric($offer['amount'] ?? null))
                ->map(fn (array $offer): string => $this->money($offer['amount']).' '.($offer['currency_id'] ?? ''))
                ->unique()->implode(', ');
            $this->telegram->sendMessage($chatId, 'El importe no coincide con una oferta vigente de Mercado Libre.'.($allowed !== '' ? " Importes permitidos: {$allowed}." : ''), [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);

            return;
        }

        $offer = $matches->first();
        $this->states->put($chatId, 'confirm_claim_action', 'claim', $claimId, [
            'action' => 'partial_refund',
            'offer' => $offer,
        ]);
        $claim = MeliClaim::query()->with(['meliAccount.user', 'order.items'])->find($claimId);
        if (! $claim) {
            $this->states->clear($chatId);

            return;
        }
        $this->renderClaimActionConfirmation($chatId, null, $claim, 'partial_refund', $offer);
    }

    private function changePartialRefundAmount(string $chatId, string $messageId, int $claimId): void
    {
        $state = $this->states->get($chatId);
        $claim = MeliClaim::query()->with('meliAccount.user')->find($claimId);
        $actor = $claim?->meliAccount?->user;
        if (! $state || $state->mode !== 'confirm_claim_action' || (int) $state->entity_id !== $claimId
            || data_get($state->payload, 'action') !== 'partial_refund' || ! $claim || ! $actor) {
            $this->invalid($chatId, $messageId, 'La confirmación expiró.');

            return;
        }
        try {
            $prepared = $this->claimActions->prepare($actor, $claim, 'partial_refund');
        } catch (Throwable $error) {
            report($error);
            $this->invalid($chatId, $messageId, 'No fue posible actualizar las ofertas de Mercado Libre.');

            return;
        }
        if (! $prepared['ok']) {
            $this->invalid($chatId, $messageId, '⚠️ '.$prepared['message']);

            return;
        }
        $this->states->put($chatId, 'awaiting_claim_partial_refund_amount', 'claim', $claimId, [
            'action' => 'partial_refund', 'offers' => $prepared['offers'],
        ]);
        $this->telegram->editOrSend($chatId, $messageId, 'Escribe el nuevo importe exacto del reembolso parcial.', [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);
    }

    private function confirmClaimAction(string $chatId, string $messageId, int $claimId): void
    {
        $payload = $this->states->claimConfirmation($chatId, 'confirm_claim_action', $claimId);
        if (! $payload) {
            $this->invalid($chatId, $messageId, 'Esta confirmación ya fue procesada o expiró.');

            return;
        }
        $claim = MeliClaim::query()->with('meliAccount.user')->find($claimId);
        $actor = $claim?->meliAccount?->user;
        if (! $claim || ! $actor || ! $this->claimActions->canAct($actor, $claim)) {
            $this->states->clear($chatId);
            $this->invalid($chatId, $messageId, 'El operador no tiene permiso para ejecutar esta acción.');

            return;
        }
        $action = (string) ($payload['action'] ?? '');
        $offer = is_array($payload['offer'] ?? null) ? $payload['offer'] : null;
        try {
            $result = $this->claimActions->execute(
                $actor,
                $claim,
                $action,
                $offer !== null ? (float) ($offer['percentage'] ?? 0) : null,
                'telegram',
                $offer,
            );
        } catch (ValidationException $error) {
            $result = ['ok' => false, 'message' => collect($error->errors())->flatten()->first() ?: 'La acción ya no es válida.'];
        } catch (Throwable $error) {
            report($error);
            $result = ['ok' => false, 'message' => 'No fue posible completar la acción con Mercado Libre.'];
        }
        $this->states->clear($chatId);
        if (! $result['ok']) {
            $this->invalid($chatId, $messageId, '⚠️ '.$result['message']);

            return;
        }

        $text = $result['refresh_failed']
            ? '✅ La acción fue aceptada, pero no fue posible actualizar el reclamo. Actualízalo manualmente.'
            : '✅ Acción realizada correctamente.';
        $this->telegram->editOrSend($chatId, $messageId, $text, [
            [['text' => '🚨 Ver reclamo', 'callback_data' => "cd:{$claimId}:o:1"]],
            [['text' => '⚙️ Acciones disponibles', 'callback_data' => "ca:{$claimId}"]],
            [['text' => '🏠 Menú', 'callback_data' => 'm']],
        ]);
    }

    private function renderClaimActionConfirmation(string $chatId, ?string $messageId, MeliClaim $claim, string $action, ?array $offer = null): void
    {
        $claim->loadMissing('order.items');
        $title = match ($action) {
            'refund' => '💰 REEMBOLSO TOTAL',
            'allow_return' => '📦 HABILITAR DEVOLUCIÓN',
            default => '💵 REEMBOLSO PARCIAL',
        };
        $warning = match ($action) {
            'refund' => 'Esta acción puede devolver el dinero al comprador.',
            'allow_return' => '¿Permitir que el comprador realice la devolución?',
            default => 'Esta oferta puede devolver parte del importe al comprador.',
        };
        $item = $claim->order?->items?->first();
        $body = "{$title}\n\nReclamo: #{$claim->claim_id}\nPedido: ".($claim->order_id ?: '—')
            ."\nProducto: ".($item?->title ?: '—');
        $orderTotal = data_get($claim->order?->raw, 'total_amount');
        $orderCurrency = data_get($claim->order?->raw, 'currency_id');
        if ($action === 'refund' && is_numeric($orderTotal) && filled($orderCurrency)) {
            $body .= "\nImporte del pedido: ".$this->money($orderTotal).' '.$orderCurrency;
        }
        if ($offer !== null) {
            $body .= "\nReembolso parcial: ".$this->money($offer['amount'] ?? 0).' '.($offer['currency_id'] ?? '')
                ."\nPorcentaje: ".($offer['percentage'] ?? '—').'%';
        }
        $body .= "\n\n{$warning}\n\n¿Confirmar la acción?";
        $keyboard = [[['text' => '✅ Confirmar', 'callback_data' => "cac:{$claim->id}"]]];
        if ($action === 'partial_refund') {
            $keyboard[] = [['text' => '✏️ Cambiar importe', 'callback_data' => "cam:{$claim->id}"]];
        }
        $keyboard[] = [['text' => '❌ Cancelar', 'callback_data' => 'cancel']];
        $this->telegram->editOrSend($chatId, $messageId, $body, $keyboard);
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    private function startClaimReply(string $chatId, string $messageId, int $claimId, ?string $receiverRole = null): void
    {
        $claim = MeliClaim::query()->find($claimId);
        $receiver = $claim ? $this->claimPolicy->recipient($claim, $receiverRole) : null;
        if (! $claim || $receiver === null) {
            $this->invalid($chatId, $messageId, 'El reclamo ya no admite respuestas.');

            return;
        }
        $this->states->put($chatId, 'awaiting_claim_reply', 'claim', $claimId, ['receiver_role' => $receiver]);
        $recipient = $receiver === 'mediator' ? 'el mediador' : 'el comprador';
        $this->telegram->editOrSend($chatId, $messageId, "✍️ Escribe tu respuesta para {$recipient} en el reclamo #{$claim->claim_id}.", [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);
    }

    private function startPostSaleReply(string $chatId, string $messageId, int $flowId): void
    {
        if (! MeliChatFlow::query()->whereKey($flowId)->exists()) {
            $this->invalid($chatId, $messageId);

            return;
        }
        $this->states->put($chatId, 'awaiting_post_sale_reply', 'post_sale', $flowId);
        $this->telegram->editOrSend($chatId, $messageId, '✍️ Escribe tu respuesta para esta conversación posventa.', [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);
    }

    private function editReply(string $chatId, string $messageId, string $type, int $entityId): void
    {
        $state = $this->states->get($chatId);
        $expected = $type === 'claim' ? 'confirm_claim_reply' : 'confirm_post_sale_reply';
        if (! $state || $state->mode !== $expected || (int) $state->entity_id !== $entityId) {
            $this->invalid($chatId, $messageId, 'La confirmación expiró.');

            return;
        }
        $mode = $type === 'claim' ? 'awaiting_claim_reply' : 'awaiting_post_sale_reply';
        $payload = $state->payload ?? [];
        unset($payload['text']);
        $this->states->put($chatId, $mode, $type, $entityId, $payload);
        $this->telegram->editOrSend($chatId, $messageId, '✏️ Escribe el mensaje corregido.', [[['text' => '❌ Cancelar', 'callback_data' => 'cancel']]]);
    }

    private function confirmClaimReply(string $chatId, string $messageId, int $claimId): void
    {
        $payload = $this->states->claimConfirmation($chatId, 'confirm_claim_reply', $claimId);
        if (! $payload) {
            $this->invalid($chatId, $messageId, 'Esta confirmación ya fue procesada o expiró.');

            return;
        }
        $claim = MeliClaim::query()->with('meliAccount.user')->find($claimId);
        $actor = $claim?->meliAccount?->user;
        if (! $claim || ! $actor) {
            $this->states->clear($chatId);
            $this->invalid($chatId, $messageId);

            return;
        }
        try {
            $receiver = filled($payload['receiver_role'] ?? null) ? (string) $payload['receiver_role'] : null;
            $result = $receiver === null
                ? $this->claimSender->send($actor, $claim, (string) ($payload['text'] ?? ''), collect(), 'telegram')
                : $this->claimSender->send($actor, $claim, (string) ($payload['text'] ?? ''), collect(), 'telegram', $receiver);
        } catch (ValidationException $error) {
            $result = ['ok' => false, 'error' => collect($error->errors())->flatten()->first() ?: 'El reclamo ya no admite respuestas.'];
        }
        $this->states->clear($chatId);
        if (! $result['ok']) {
            $this->invalid($chatId, $messageId, (string) $result['error']);

            return;
        }
        $this->telegram->editOrSend($chatId, $messageId, '✅ Mensaje enviado correctamente.', [
            [['text' => '💬 Ver conversación', 'callback_data' => "cc:{$claimId}:0"]],
            [['text' => '➡️ Siguiente pendiente', 'callback_data' => 'cl:a:1']],
            [['text' => '🏠 Menú', 'callback_data' => 'm']],
        ]);
    }

    private function confirmPostSaleReply(string $chatId, string $messageId, int $flowId): void
    {
        $payload = $this->states->claimConfirmation($chatId, 'confirm_post_sale_reply', $flowId);
        if (! $payload) {
            $this->invalid($chatId, $messageId, 'Esta confirmación ya fue procesada o expiró.');

            return;
        }
        $flow = MeliChatFlow::query()->find($flowId);
        if (! $flow) {
            $this->states->clear($chatId);
            $this->invalid($chatId, $messageId);

            return;
        }
        $result = $this->postSaleSender->trySendMessage($flow, (string) ($payload['text'] ?? ''));
        $this->states->clear($chatId);
        if (! $result['ok']) {
            $this->invalid($chatId, $messageId, (string) ($result['error'] ?? 'No se pudo enviar el mensaje.'));

            return;
        }
        $this->telegram->editOrSend($chatId, $messageId, '✅ Mensaje enviado correctamente.', [
            [['text' => '📖 Ver conversación', 'callback_data' => "pc:{$flowId}:0"]],
            [['text' => '➡️ Siguiente pendiente', 'callback_data' => 'pl:p:1']],
            [['text' => '🏠 Menú', 'callback_data' => 'm']],
        ]);
    }

    private function showMainMenu(string $chatId, ?string $messageId = null): void
    {
        $this->telegram->editOrSend($chatId, $messageId, "👋 Panel de operaciones\n\n¿Qué quieres hacer?", [
            [['text' => '📊 Subir Excel', 'callback_data' => 'x']],
            [['text' => '💬 Mensajería posventa', 'callback_data' => 'p']],
            [['text' => '🚨 Reclamos', 'callback_data' => 'c']],
        ]);
    }

    private function render(string $chatId, string $messageId, array $view): void
    {
        $this->telegram->editOrSend($chatId, $messageId, $view[0], $view[1]);
    }

    private function renderNullable(string $chatId, string $messageId, ?array $view): void
    {
        $view ? $this->render($chatId, $messageId, $view) : $this->invalid($chatId, $messageId);
    }

    private function invalid(string $chatId, string $messageId, string $text = 'La opción o entidad ya no está disponible.'): void
    {
        $this->telegram->editOrSend($chatId, $messageId, $text, $this->homeKeyboard());
    }

    private function homeKeyboard(): array
    {
        return [[['text' => '🏠 Menú principal', 'callback_data' => 'm']]];
    }

    private function authorized(string $chatId): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_ALLOWED_CHAT_IDS', ''))));

        return in_array($chatId, $allowed, true);
    }

    private function claimUpdate(array $update, string $chatId, string $callbackId): bool
    {
        $key = isset($update['update_id']) ? 'u:'.$update['update_id'] : ($callbackId !== '' ? 'c:'.$callbackId : 'm:'.$chatId.':'.data_get($update, 'message.message_id', data_get($update, 'channel_post.message_id', 'unknown')));
        try {
            TelegramProcessedUpdate::query()->create(['update_key' => $key, 'processed_at' => now()]);
            if (random_int(1, 100) === 1) {
                TelegramProcessedUpdate::query()->where('processed_at', '<', now()->subDays(7))->delete();
            }

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (Throwable $error) {
            report($error);
            throw $error;
        }
    }
}
