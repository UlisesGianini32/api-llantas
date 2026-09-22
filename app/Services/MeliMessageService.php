<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\User;
use App\Support\MeliPostSaleMessaging;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío posventa:
 * POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale
 */
class MeliMessageService
{
    /**
     * Inicia una conversación posventa mediante Action Guide o utiliza el
     * recurso normal cuando ya existe una conversación con el comprador.
     *
     * @return array<string, mixed>
     */
    public function tryStartConversation(MeliChatFlow $flow, string $text): array
    {
        try {
            $apiUser = $this->resolveApiUser($flow);
            $resourceId = $flow->pack_id ?: $flow->order_id;

            if (! $apiUser || ! filled($resourceId) || str_starts_with((string) $resourceId, 'no-order-')) {
                return $this->initialFailure(
                    'unavailable',
                    'La cuenta o la orden no están disponibles para enviar mensajes.',
                    'missing_account_or_resource'
                );
            }

            $resourceId = (string) $resourceId;
            $sellerId = (string) $apiUser->meli_id;
            $token = (string) $apiUser->access_token;

            $conversation = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($this->messagesEndpoint($resourceId, $sellerId), [
                    'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
                    'limit' => 1,
                    'offset' => 0,
                    'mark_as_read' => 'false',
                ]);

            if ($conversation->successful() && count((array) $conversation->json('messages', [])) > 0) {
                return $this->postInitialNormalMessage($flow, $text, $token, $resourceId, $sellerId);
            }

            $guide = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->get($this->actionGuideEndpoint($resourceId), [
                    'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
                ]);

            if (! $guide->successful()) {
                if ($this->mustUseNormalMessages($guide)) {
                    return $this->postInitialNormalMessage($flow, $text, $token, $resourceId, $sellerId);
                }

                return $this->initialHttpFailure($guide, 'action_guide');
            }

            $other = $this->findGuideOption((array) $guide->json('options', []), 'OTHER');
            if (! $other || ! ($other['enabled'] ?? false) || ! ($other['actionable'] ?? false)) {
                return $this->initialFailure(
                    'unavailable',
                    'Mercado Libre no habilitó mensajes para esta venta.',
                    'other_option_unavailable'
                );
            }

            $capAvailable = $other['cap_available'] ?? null;
            if ($capAvailable === null) {
                $caps = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(30)
                    ->get($this->actionGuideEndpoint($resourceId).'/caps_available', [
                        'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
                    ]);

                if (! $caps->successful()) {
                    return $this->initialHttpFailure($caps, 'caps_available');
                }

                $capAvailable = collect((array) $caps->json())
                    ->firstWhere('option_id', 'OTHER')['cap_available'] ?? 0;
            }

            if ((int) $capAvailable < 1) {
                return $this->initialFailure(
                    'unavailable',
                    'Mercado Libre indica que no hay mensajes disponibles para esta venta.',
                    'other_cap_unavailable'
                );
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->post($this->actionGuideEndpoint($resourceId).'/option?'.http_build_query([
                    'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
                ]), [
                    'option_id' => 'OTHER',
                    'text' => $this->truncateSellerText($text),
                ]);

            return $this->evaluateInitialMessageResponse($flow, $text, $response, 'action_guide');
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            Log::warning('Mensaje posventa con resultado incierto', [
                'flow_id' => $flow->id,
                'order_id' => $flow->order_id,
                'meli_account_id' => $flow->meli_account_id,
                'error' => $exception->getMessage(),
            ]);

            return $this->initialFailure(
                'uncertain',
                'No fue posible confirmar si Mercado Libre recibió el mensaje.',
                'uncertain_delivery'
            );
        } catch (\Throwable $exception) {
            Log::error('Error iniciando conversación posventa', [
                'flow_id' => $flow->id ?? null,
                'order_id' => $flow->order_id ?? null,
                'meli_account_id' => $flow->meli_account_id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return $this->initialFailure(
                'error',
                'No se pudo enviar el mensaje a Mercado Libre.',
                'unexpected_error'
            );
        }
    }

    /**
     * @return array{ok: bool, error: ?string, status: ?int}
     */
    public function trySendMessage(
        MeliChatFlow $flow,
        string $text
    ): array {
        try {
            $apiUser = $this->resolveApiUser($flow);

            if (! $apiUser) {
                Log::warning(
                    'MeliMessageService: no se encontró cuenta o token',
                    [
                        'flow_id' => $flow->id,
                        'order_id' => $flow->order_id,
                        'user_id' => $flow->user_id,
                        'meli_account_id' => $flow->meli_account_id,
                    ]
                );

                return [
                    'ok' => false,
                    'error' => 'La cuenta de Mercado Libre de esta conversación no tiene token de acceso.',
                    'status' => null,
                ];
            }

            $packId = $flow->pack_id ?: $flow->order_id;

            if (
                ! $packId
                || str_starts_with((string) $packId, 'no-order-')
            ) {
                return [
                    'ok' => false,
                    'error' => 'Esta conversación no tiene pack u orden válido para enviar mensajes.',
                    'status' => null,
                ];
            }

            $sellerMeliId = (string) $apiUser->meli_id;
            $endpoint = sprintf(
                '%s/messages/packs/%s/sellers/%s',
                rtrim(MeliPostSaleMessaging::API_BASE, '/'),
                rawurlencode((string) $packId),
                rawurlencode($sellerMeliId)
            );

            $truncated = $this->truncateSellerText($text);
            $payload = $this->buildPayload(
                $flow,
                $truncated,
                $sellerMeliId
            );

            Log::info(
                'MeliMessageService: intentando enviar mensaje posventa',
                [
                    'flow_id' => $flow->id,
                    'order_id' => $flow->order_id,
                    'pack_id' => $packId,
                    'meli_account_id' => $flow->meli_account_id,
                    'seller_meli_id' => $sellerMeliId,
                    'endpoint' => $endpoint,
                    'payload' => $payload,
                ]
            );

            $query = http_build_query([
                'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
            ]);

            $response = Http::withToken(
                (string) $apiUser->access_token
            )
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->acceptJson()
                ->post($endpoint.'?'.$query, $payload);

            if ($response->successful()) {
                $flow->forceFill([
                    'last_message_role' => 'seller',
                    'last_message_at' => now(),
                    'last_message_text' => $truncated,
                    'last_message_synced_at' => now(),
                    'requires_human' => false,
                    'requires_human_at' => null,
                ])->save();

                Log::info('Mensaje enviado a Mercado Libre', [
                    'flow_id' => $flow->id,
                    'order_id' => $flow->order_id,
                    'meli_account_id' => $flow->meli_account_id,
                    'response' => $response->json(),
                ]);

                return [
                    'ok' => true,
                    'error' => null,
                    'status' => $response->status(),
                ];
            }

            $responseJson = $response->json();
            $responseCode = strtolower(
                (string) data_get($responseJson, 'code', '')
            );
            $responseMessage = strtolower(
                (string) data_get($responseJson, 'message', '')
            );
            $status = (int) $response->status();

            if (
                $this->isExpectedMeliBlockedCase(
                    $status,
                    $responseCode,
                    $responseMessage
                )
            ) {
                return [
                    'ok' => false,
                    'error' => 'Mercado Libre no permite enviar este mensaje en este momento (política de la plataforma).',
                    'status' => $status,
                ];
            }

            Log::warning('Error enviando mensaje a Mercado Libre', [
                'flow_id' => $flow->id,
                'order_id' => $flow->order_id,
                'meli_account_id' => $flow->meli_account_id,
                'status' => $status,
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            $human = trim(
                (string) data_get($responseJson, 'message', '')
            );

            if ($human === '') {
                $human = 'Error HTTP '.$status;
            }

            return [
                'ok' => false,
                'error' => $human,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            Log::error('Excepción enviando mensaje a Mercado Libre', [
                'flow_id' => $flow->id ?? null,
                'order_id' => $flow->order_id ?? null,
                'meli_account_id' => $flow->meli_account_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'status' => null,
            ];
        }
    }

    public function sendMessage(
        MeliChatFlow $flow,
        string $text
    ): bool {
        return $this->trySendMessage($flow, $text)['ok'];
    }

    public function truncateSellerText(string $text): string
    {
        $max = max(
            1,
            (int) config('meli_menu.seller_max_message_length', 350)
        );
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    public function resolveApiUser(
        MeliChatFlow $flow
    ): ?User {
        $owner = User::query()->find($flow->user_id);

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

    protected function buildPayload(
        MeliChatFlow $flow,
        string $text,
        string $sellerMeliId
    ): array {
        $siteId = (string) (
            data_get($flow->meta, 'site_id')
            ?: config('meli_menu.default_site_id', 'MLM')
        );

        $agentId = MeliPostSaleMessaging::agentUserIdForSite($siteId)
            ?? MeliPostSaleMessaging::agentUserIdForSite('MLM')
            ?? '3037204279';

        $preferAgent = (bool) config(
            'meli_menu.use_message_agent',
            true
        );
        $mandatoryAgent = MeliPostSaleMessaging::mustSendToMessagingAgent(
            $siteId
        );
        $useAgent = $mandatoryAgent || $preferAgent;

        $toUserId = $useAgent
            ? (string) $agentId
            : (string) ($flow->buyer_id ?? '');

        return [
            'from' => [
                'user_id' => (string) $sellerMeliId,
            ],
            'to' => [
                'user_id' => (string) $toUserId,
            ],
            'text' => $text,
        ];
    }

    protected function isExpectedMeliBlockedCase(
        int $status,
        string $responseCode,
        string $responseMessage
    ): bool {
        if ($status !== 403 || $responseCode !== 'forbidden') {
            return false;
        }

        return str_starts_with(
            $responseMessage,
            'blocked_by_'
        );
    }

    /** @return array<string, mixed> */
    private function postInitialNormalMessage(
        MeliChatFlow $flow,
        string $text,
        string $token,
        string $resourceId,
        string $sellerId
    ): array {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post($this->messagesEndpoint($resourceId, $sellerId).'?'.http_build_query([
                'tag' => MeliPostSaleMessaging::TAG_POST_SALE,
            ]), $this->buildPayload($flow, $this->truncateSellerText($text), $sellerId));

        return $this->evaluateInitialMessageResponse($flow, $text, $response, 'messages');
    }

    /** @return array<string, mixed> */
    private function evaluateInitialMessageResponse(
        MeliChatFlow $flow,
        string $text,
        Response $response,
        string $channel
    ): array {
        if (! $response->successful()) {
            return $this->initialHttpFailure($response, $channel);
        }

        $body = is_array($response->json()) ? $response->json() : [];
        $messageId = trim((string) ($body['id'] ?? ''));
        $messageStatus = strtolower(trim((string) ($body['status'] ?? '')));
        $moderationStatus = strtolower(trim((string) data_get($body, 'message_moderation.status', '')));
        $moderationReason = trim((string) data_get($body, 'message_moderation.reason', ''));

        if ($messageId === '') {
            Log::warning('Mercado Libre respondió sin id de mensaje', [
                'flow_id' => $flow->id,
                'channel' => $channel,
                'status' => $response->status(),
                'response' => $body,
            ]);

            return $this->initialFailure(
                'error',
                'Mercado Libre no confirmó el envío del mensaje.',
                'missing_message_id',
                $response->status(),
                $body
            );
        }

        $outcome = 'sent';
        $message = 'Solicitud enviada al comprador.';
        if (in_array($messageStatus, ['moderated', 'rejected'], true)
            || in_array($moderationStatus, ['moderated', 'rejected'], true)) {
            $outcome = 'rejected';
            $message = 'Mercado Libre no permitió enviar este mensaje.';
        } elseif ($messageStatus === 'pending_translation'
            || in_array($moderationStatus, ['pending', 'pending_moderation', 'in_process'], true)) {
            $outcome = 'pending_moderation';
            $message = 'El mensaje está pendiente de moderación por Mercado Libre.';
        } elseif ($messageStatus !== '' && $messageStatus !== 'available') {
            $outcome = 'pending_moderation';
            $message = 'Mercado Libre recibió el mensaje y está procesándolo.';
        }

        if ($outcome === 'sent') {
            $truncated = $this->truncateSellerText($text);
            $flow->forceFill([
                'last_message_role' => 'seller',
                'last_message_at' => now(),
                'last_message_text' => $truncated,
                'last_message_synced_at' => now(),
                'requires_human' => false,
                'requires_human_at' => null,
            ])->save();
        }

        Log::info('Resultado de mensaje inicial posventa', [
            'flow_id' => $flow->id,
            'order_id' => $flow->order_id,
            'meli_account_id' => $flow->meli_account_id,
            'channel' => $channel,
            'outcome' => $outcome,
            'message_id' => $messageId,
            'message_status' => $messageStatus,
            'moderation_status' => $moderationStatus,
            'moderation_reason' => $moderationReason,
        ]);

        return [
            'ok' => $outcome === 'sent',
            'outcome' => $outcome,
            'message' => $message,
            'status' => $response->status(),
            'message_id' => $messageId,
            'message_status' => $messageStatus !== '' ? $messageStatus : null,
            'moderation_status' => $moderationStatus !== '' ? $moderationStatus : null,
            'moderation_reason' => $moderationReason !== '' ? $moderationReason : null,
            'error_code' => $outcome === 'sent' ? null : $outcome,
            'technical' => $body,
        ];
    }

    /** @return array<string, mixed> */
    private function initialHttpFailure(Response $response, string $channel): array
    {
        $body = is_array($response->json()) ? $response->json() : [];
        $technicalMessage = strtolower(trim(implode(' ', array_filter([
            (string) ($body['cause'] ?? ''),
            (string) ($body['code'] ?? ''),
            (string) ($body['error'] ?? ''),
            (string) ($body['message'] ?? ''),
        ]))));

        $outcome = str_contains($technicalMessage, 'blocked') ? 'blocked' : 'error';
        if (str_contains($technicalMessage, 'cap')
            || str_contains($technicalMessage, 'limit_exceeded')
            || str_contains($technicalMessage, 'not allowed to execute')) {
            $outcome = 'unavailable';
        }

        Log::warning('Mercado Libre rechazó mensaje inicial posventa', [
            'channel' => $channel,
            'status' => $response->status(),
            'response' => $body,
        ]);

        return $this->initialFailure(
            $outcome,
            $outcome === 'blocked'
                ? 'La conversación está bloqueada por Mercado Libre.'
                : ($outcome === 'unavailable'
                    ? 'Mercado Libre indica que no hay mensajes disponibles para esta venta.'
                    : 'Mercado Libre no permitió enviar este mensaje.'),
            trim((string) ($body['cause'] ?? $body['code'] ?? $body['error'] ?? 'remote_error')),
            $response->status(),
            $body
        );
    }

    /** @return array<string, mixed> */
    private function initialFailure(
        string $outcome,
        string $message,
        string $errorCode,
        ?int $status = null,
        array $technical = []
    ): array {
        return [
            'ok' => false,
            'outcome' => $outcome,
            'message' => $message,
            'status' => $status,
            'message_id' => null,
            'message_status' => null,
            'moderation_status' => null,
            'moderation_reason' => null,
            'error_code' => $errorCode,
            'technical' => $technical,
        ];
    }

    private function mustUseNormalMessages(Response $response): bool
    {
        $json = is_array($response->json()) ? $response->json() : [];
        $body = strtolower(json_encode($json) ?: '');
        $message = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($json['message'] ?? '')) ?? ''));
        $forbidden = collect([$json['error'] ?? null, $json['cause'] ?? null, $json['code'] ?? null])
            ->contains(fn (mixed $value): bool => strtolower(trim((string) $value)) === 'forbidden');

        if ($response->status() === 403
            && $forbidden
            && $message === 'this package has the conversation blocked, please check blocked messages') {
            return true;
        }

        return str_contains($body, 'use_message_api')
            || str_contains($body, 'use the messaging resource')
            || str_contains($body, 'open conversation');
    }

    /** @param array<int, mixed> $options */
    private function findGuideOption(array $options, string $id): ?array
    {
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            if (strtoupper((string) ($option['id'] ?? '')) === $id) {
                return $option;
            }
            $child = $this->findGuideOption((array) ($option['child_options'] ?? []), $id);
            if ($child) {
                return $child;
            }
        }

        return null;
    }

    private function messagesEndpoint(string $resourceId, string $sellerId): string
    {
        return sprintf(
            '%s/messages/packs/%s/sellers/%s',
            rtrim(MeliPostSaleMessaging::API_BASE, '/'),
            rawurlencode($resourceId),
            rawurlencode($sellerId)
        );
    }

    private function actionGuideEndpoint(string $resourceId): string
    {
        return sprintf(
            '%s/messages/action_guide/packs/%s',
            rtrim(MeliPostSaleMessaging::API_BASE, '/'),
            rawurlencode($resourceId)
        );
    }
}
