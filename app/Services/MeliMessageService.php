<?php

namespace App\Services;

use App\Models\MeliChatFlow;
use App\Models\User;
use App\Support\MeliPostSaleMessaging;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío posventa según ML: POST /messages/packs/{pack_id}/sellers/{seller_id}?tag=post_sale
 *
 * @see MeliPostSaleMessaging::DOCS_URL
 */
class MeliMessageService
{
    /**
     * @return array{ok: bool, error: ?string, status: ?int}
     */
    public function trySendMessage(MeliChatFlow $flow, string $text): array
    {
        try {
            $token = $this->resolveAccessToken($flow);

            if (! $token) {
                Log::warning('MeliMessageService: no se encontró access token', [
                    'flow_id' => $flow->id,
                    'order_id' => $flow->order_id,
                    'user_id' => $flow->user_id,
                ]);

                return [
                    'ok' => false,
                    'error' => 'No hay token de acceso de Mercado Libre. Refrescá el token desde el dashboard o volvé a vincular la cuenta.',
                    'status' => null,
                ];
            }

            $user = User::find($flow->user_id);
            if (! $user || ! $user->meli_id) {
                Log::warning('MeliMessageService: usuario sin meli_id', [
                    'flow_id' => $flow->id,
                    'user_id' => $flow->user_id,
                ]);

                return [
                    'ok' => false,
                    'error' => 'Tu usuario no tiene cuenta de Mercado Libre vinculada (meli_id).',
                    'status' => null,
                ];
            }

            $packId = $flow->pack_id ?: $flow->order_id;
            if (! $packId || str_starts_with((string) $packId, 'no-order-')) {
                Log::warning('MeliMessageService: falta pack_id u order_id válido para enviar', [
                    'flow_id' => $flow->id,
                    'pack_id' => $flow->pack_id,
                    'order_id' => $flow->order_id,
                ]);

                return [
                    'ok' => false,
                    'error' => 'Esta conversación no tiene pack u orden válido para enviar mensajes.',
                    'status' => null,
                ];
            }

            $sellerMeliId = (string) $user->meli_id;
            $endpoint = sprintf(
                '%s/messages/packs/%s/sellers/%s',
                rtrim(MeliPostSaleMessaging::API_BASE, '/'),
                rawurlencode((string) $packId),
                rawurlencode($sellerMeliId)
            );

            $truncated = $this->truncateSellerText($text);
            $payload = $this->buildPayload($flow, $truncated, $sellerMeliId);

            Log::info('MeliMessageService: intentando enviar mensaje posventa', [
                'flow_id' => $flow->id,
                'order_id' => $flow->order_id,
                'pack_id' => $packId,
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);

            $query = http_build_query(['tag' => MeliPostSaleMessaging::TAG_POST_SALE]);

            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->acceptJson()
                ->post($endpoint . '?' . $query, $payload);

            if ($response->successful()) {
                Log::info('Mensaje enviado a Mercado Libre', [
                    'flow_id' => $flow->id,
                    'order_id' => $flow->order_id,
                    'response' => $response->json(),
                ]);

                return ['ok' => true, 'error' => null, 'status' => $response->status()];
            }

            $respJson = $response->json();
            $respCode = strtolower((string) data_get($respJson, 'code', ''));
            $respMsg = strtolower((string) data_get($respJson, 'message', ''));
            $status = (int) $response->status();

            if ($this->isExpectedMeliBlockedCase($status, $respCode, $respMsg)) {
                Log::info('MeliMessageService: mensaje no enviado por politica de ML (caso esperado)', [
                    'flow_id' => $flow->id,
                    'order_id' => $flow->order_id,
                    'status' => $status,
                    'code' => $respCode,
                    'message' => $respMsg,
                ]);

                return [
                    'ok' => false,
                    'error' => 'Mercado Libre no permite enviar este mensaje en este momento (política de la plataforma).',
                    'status' => $status,
                ];
            }

            Log::warning('Error enviando mensaje a Mercado Libre', [
                'flow_id' => $flow->id,
                'order_id' => $flow->order_id,
                'status' => $status,
                'body' => $response->body(),
                'payload' => $payload,
            ]);

            $human = trim((string) data_get($respJson, 'message', ''));
            if ($human === '') {
                $human = 'Error HTTP ' . $status;
            }

            return ['ok' => false, 'error' => $human, 'status' => $status];
        } catch (\Throwable $e) {
            Log::error('Excepción enviando mensaje a Mercado Libre', [
                'flow_id' => $flow->id ?? null,
                'order_id' => $flow->order_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'status' => null];
        }
    }

    public function sendMessage(MeliChatFlow $flow, string $text): bool
    {
        return $this->trySendMessage($flow, $text)['ok'];
    }

    public function truncateSellerText(string $text): string
    {
        $max = max(1, (int) config('meli_menu.seller_max_message_length', 350));
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    protected function resolveAccessToken(MeliChatFlow $flow): ?string
    {
        $user = User::find($flow->user_id);

        if (!$user) {
            Log::warning('MeliMessageService: no se encontró usuario', [
                'flow_id' => $flow->id,
                'user_id' => $flow->user_id,
            ]);

            return null;
        }

        return $user->access_token ?: null;
    }

    protected function buildPayload(MeliChatFlow $flow, string $text, string $sellerMeliId): array
    {
        $siteId = (string) (data_get($flow->meta, 'site_id') ?: config('meli_menu.default_site_id', 'MLM'));
        $agentId = MeliPostSaleMessaging::agentUserIdForSite($siteId)
            ?? MeliPostSaleMessaging::agentUserIdForSite('MLM')
            ?? '3037204279';

        $preferAgent = (bool) config('meli_menu.use_message_agent', true);
        $mandatoryAgent = MeliPostSaleMessaging::mustSendToMessagingAgent($siteId);
        $useAgent = $mandatoryAgent || $preferAgent;

        $toUserId = $useAgent
            ? (string) $agentId
            : (string) ($flow->buyer_id ?? '');

        // Cuerpo alineado al ejemplo JSON de ML: user_id como string, sin adjuntos si no hay.
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

    protected function isExpectedMeliBlockedCase(int $status, string $respCode, string $respMsg): bool
    {
        if ($status !== 403 || $respCode !== 'forbidden') {
            return false;
        }

        return str_starts_with($respMsg, 'blocked_by_');
    }
}
