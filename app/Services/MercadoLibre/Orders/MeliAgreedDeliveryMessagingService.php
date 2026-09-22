<?php

namespace App\Services\MercadoLibre\Orders;

use App\Models\MeliChatFlow;
use App\Models\MeliOrder;
use App\Models\User;
use App\Services\MeliMessageService;
use App\Services\MercadoLibre\MeliAccountAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MeliAgreedDeliveryMessagingService
{
    public const MESSAGE = "Buen día, ¿nos podría proporcionar sus datos de envío?\n"
        ."- Nombre\n"
        ."- Teléfono\n"
        ."- Calle\n"
        ."- Número\n"
        ."- Código postal\n"
        ."- Colonia\n"
        ."- Ciudad\n"
        ."- Estado\n"
        .'- Referencia';

    private const OPERATIONAL_STATUSES = ['paid', 'partially_paid'];

    public function __construct(
        private MeliMessageService $messages,
        private MeliAccountAccessService $accountAccess,
    ) {}

    /** @return array<string, mixed> */
    public function request(User $operator, MeliOrder $order, bool $resend = false): array
    {
        $lock = Cache::lock($this->lockName($order), 60);
        if (! $lock->get()) {
            return $this->result(false, 'sending', 'La solicitud ya se está enviando.', 409);
        }

        try {
            $order->refresh()->load('meliAccount');
            $eligibility = $this->eligibilityError($operator, $order);
            if ($eligibility !== null) {
                return $eligibility;
            }

            $flow = $this->findOrCreateFlow($order);
            $previous = $this->state($flow);
            if (! $resend && in_array((string) ($previous['status'] ?? ''), [
                'sent', 'pending_moderation', 'sending', 'uncertain',
            ], true)) {
                return $this->result(
                    false,
                    (string) $previous['status'],
                    'La solicitud ya fue enviada o está siendo procesada.',
                    409,
                    $flow,
                    $previous
                );
            }

            $humanLock = Cache::lock($this->messages->humanStartLockName($flow), 180);
            if (! $humanLock->get()) {
                return $this->result(false, 'sending', 'La solicitud ya se está enviando.', 409, $flow, $previous);
            }

            try {
                $prepared = $this->messages->prepareHumanStarted($flow, $operator->id, 'ams');
                $flow = $prepared['flow'];

                $this->saveState($flow, array_merge($previous, [
                    'status' => 'sending',
                    'attempted_at' => now()->toIso8601String(),
                    'error_code' => null,
                    'technical_error' => null,
                ]));

                $remote = $this->messages->tryStartConversation($flow->fresh(), self::MESSAGE);
                $status = (string) ($remote['outcome'] ?? 'error');
                $state = [
                    'status' => $status,
                    'message_id' => $remote['message_id'] ?? null,
                    'message_status' => $remote['message_status'] ?? null,
                    'moderation_status' => $remote['moderation_status'] ?? null,
                    'moderation_reason' => $remote['moderation_reason'] ?? null,
                    'requested_at' => $status === 'sent'
                        ? now()->toIso8601String()
                        : ($previous['requested_at'] ?? null),
                    'attempted_at' => now()->toIso8601String(),
                    'error_code' => $remote['error_code'] ?? null,
                    'technical_error' => $this->technicalError($remote),
                ];
                $this->saveState($flow->fresh(), $state);

                if ($prepared['created']
                    && $this->messages->isDefinitiveStartFailure($remote)) {
                    $flow = $this->messages->rollbackHumanStarted($flow, $prepared['token']);
                } elseif ($prepared['created']) {
                    $flow = $this->messages->commitHumanStarted($flow, $prepared['token']);
                }

                return $this->result(
                    (bool) ($remote['ok'] ?? false),
                    $status,
                    (string) ($remote['message'] ?? 'No se pudo enviar el mensaje.'),
                    match ($status) {
                        'sent' => 200,
                        'pending_moderation', 'uncertain' => 202,
                        default => 422,
                    },
                    $flow,
                    $state
                );
            } finally {
                $humanLock->release();
            }
        } finally {
            $lock->release();
        }
    }

    public function lockName(MeliOrder $order): string
    {
        return 'meli-order-delivery-details-request:'.$order->id;
    }

    /** @return array<string, mixed> */
    public function state(MeliChatFlow $flow): array
    {
        $state = data_get($flow->meta, 'delivery_details_request', []);

        return is_array($state) ? $state : [];
    }

    private function eligibilityError(User $operator, MeliOrder $order): ?array
    {
        $account = $order->meliAccount;
        if (! $account || ! $this->accountAccess->hasAccess($operator, $account)) {
            return $this->result(false, 'forbidden', 'No tienes acceso a esta orden.', 404);
        }
        if (! filled($account->meli_user_id) || ! filled($account->access_token)) {
            return $this->result(false, 'unavailable', 'La cuenta de Mercado Libre no tiene un token válido.', 422);
        }
        if ($order->delivery_type !== MeliOrderDeliveryClassifier::AGREED_WITH_BUYER) {
            return $this->result(false, 'unavailable', 'Esta acción sólo está disponible para entregas acordadas.', 422);
        }
        if (! in_array(strtolower((string) $order->status), self::OPERATIONAL_STATUSES, true)
            || $this->isCancelled($order)) {
            return $this->result(false, 'unavailable', 'La orden no está pagada o ya no está operativa.', 422);
        }
        $shippingModes = array_map(
            fn (mixed $value): string => strtolower(trim((string) $value)),
            [$order->shipping_mode, $order->shipping_type, $order->shipping_logistic_type]
        );
        if (filled($order->shipping_id) || array_intersect($shippingModes, ['full', 'fulfillment'])) {
            return $this->result(false, 'unavailable', 'Esta acción no corresponde a una orden con envío de Mercado Libre.', 422);
        }

        return null;
    }

    private function isCancelled(MeliOrder $order): bool
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        $tags = array_map(fn (mixed $tag): string => strtolower((string) $tag), (array) ($raw['tags'] ?? []));
        $feedback = is_array($raw['feedback'] ?? null) ? $raw['feedback'] : [];

        return (array_key_exists('cancel_detail', $raw) && $raw['cancel_detail'] !== null)
            || (array_key_exists('sale', $feedback) && $feedback['sale'] !== null)
            || (array_key_exists('seller', $feedback) && $feedback['seller'] !== null)
            || in_array('unfulfilled', $tags, true)
            || in_array(strtolower((string) ($raw['status'] ?? $order->status ?? '')), ['cancelled', 'canceled'], true);
    }

    private function findOrCreateFlow(MeliOrder $order): MeliChatFlow
    {
        $keys = [
            'meli_account_id' => $order->meli_account_id,
            'order_id' => (string) $order->order_id,
        ];
        $attributes = [
            'user_id' => $order->meliAccount->user_id,
            'pack_id' => $order->pack_id,
            'buyer_id' => data_get($order->raw, 'buyer.id'),
            'item_id' => $order->items()->value('item_id'),
            'sku' => $order->items()->value('sku'),
            'meta' => array_filter([
                'site_id' => data_get($order->raw, 'context.site') ?: data_get($order->raw, 'site_id'),
            ]),
        ];

        try {
            return MeliChatFlow::query()->firstOrCreate($keys, $attributes);
        } catch (QueryException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw $exception;
            }

            return MeliChatFlow::query()->where($keys)->firstOrFail();
        }
    }

    /** @param array<string, mixed> $state */
    private function saveState(MeliChatFlow $flow, array $state): void
    {
        $meta = $flow->meta ?? [];
        $meta['delivery_details_request'] = $state;
        if (($state['status'] ?? null) !== 'sending') {
            $history = is_array($meta['delivery_details_request_history'] ?? null)
                ? $meta['delivery_details_request_history']
                : [];
            $history[] = $state;
            $meta['delivery_details_request_history'] = array_slice($history, -20);
        }
        $flow->forceFill(['meta' => $meta])->save();
    }

    /** @param array<string, mixed> $remote */
    private function technicalError(array $remote): ?string
    {
        if (($remote['ok'] ?? false) === true) {
            return null;
        }

        $technical = $remote['technical'] ?? [];
        $value = is_array($technical) ? json_encode($technical, JSON_UNESCAPED_UNICODE) : (string) $technical;

        return filled($value) ? mb_substr((string) $value, 0, 2000) : null;
    }

    /** @return array<string, mixed> */
    private function result(
        bool $ok,
        string $status,
        string $message,
        int $httpStatus,
        ?MeliChatFlow $flow = null,
        array $state = []
    ): array {
        if (! $ok && ! in_array($status, ['sending', 'pending_moderation', 'uncertain'], true)) {
            Log::notice('Solicitud de datos de envío no completada', [
                'status' => $status,
                'flow_id' => $flow?->id,
                'error_code' => $state['error_code'] ?? null,
            ]);
        }

        return compact('ok', 'status', 'message', 'httpStatus', 'flow', 'state');
    }
}
