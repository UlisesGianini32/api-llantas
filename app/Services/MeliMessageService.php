<?php

namespace App\Services;

use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\User;
use App\Support\MeliPostSaleMessaging;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío posventa:
 * POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale
 */
class MeliMessageService
{
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
}
