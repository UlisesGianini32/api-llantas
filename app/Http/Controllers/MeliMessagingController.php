<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\User;
use App\Services\MeliApi;
use App\Services\MeliMessageService;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeliMessagingController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $owner */
        $owner = $request->user();

        $accounts = $owner->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('nickname')
            ->orderBy('id')
            ->get();

        $selectedAccount = $this->resolveSelectedAccount($request, $accounts);

        $flows = collect();
        $selectedFlowId = null;

        if ($selectedAccount) {
            $flows = MeliChatFlow::query()
                ->where('user_id', $owner->id)
                ->where('meli_account_id', $selectedAccount->id)
                ->whereNotNull('order_id')
                ->where('order_id', '!=', '')
                ->orderByDesc('updated_at')
                ->limit(150)
                ->get();

            if (
                $request->filled('flow')
                && ctype_digit((string) $request->query('flow'))
            ) {
                $candidate = (int) $request->query('flow');

                if (
                    MeliChatFlow::query()
                        ->where('user_id', $owner->id)
                        ->where('meli_account_id', $selectedAccount->id)
                        ->whereKey($candidate)
                        ->exists()
                ) {
                    $selectedFlowId = $candidate;
                }
            }
        }

        $accountOptions = $accounts
            ->map(static function (MeliAccount $account): array {
                $nickname = trim((string) ($account->nickname ?? ''));

                return [
                    'id' => $account->id,
                    'meli_user_id' => (string) $account->meli_user_id,
                    'nickname' => $nickname !== ''
                        ? $nickname
                        : 'Cuenta '.$account->meli_user_id,
                    'is_default' => (bool) $account->is_default,
                    'has_access_token' => filled($account->access_token),
                ];
            })
            ->values();

        return Inertia::render('MeliMessaging/Index', [
            'flows' => $flows->map(static function (MeliChatFlow $flow): array {
                return [
                    'id' => $flow->id,
                    'meli_account_id' => $flow->meli_account_id,
                    'order_id' => $flow->order_id,
                    'pack_id' => $flow->pack_id,
                    'buyer_id' => $flow->buyer_id,
                    'sku' => $flow->sku,
                    'item_id' => $flow->item_id,
                    'site_id' => (string) (data_get($flow->meta, 'site_id') ?: ''),
                    'requires_human' => (bool) $flow->requires_human,
                    'updated_at' => $flow->updated_at?->toIso8601String(),
                    'menu_sent' => (bool) $flow->menu_sent,
                ];
            })->values(),
            'accounts' => $accountOptions,
            'selectedAccountId' => $selectedAccount?->id,
            'selectedAccountLinked' => (bool) (
                $selectedAccount
                && filled($selectedAccount->meli_user_id)
                && filled($selectedAccount->access_token)
            ),
            'sellerMaxLength' => max(
                1,
                (int) config('meli_menu.seller_max_message_length', 350)
            ),
            'selectedFlowId' => $selectedFlowId,
        ]);
    }

    public function messages(
        Request $request,
        MeliApi $meli,
        string $flow
    ): JsonResponse {
        $flowModel = $this->findOwnedFlow($request, $flow);
        $apiUser = $this->makeApiUserForFlow($request->user(), $flowModel);

        if (! $apiUser) {
            return response()->json([
                'ok' => false,
                'message' => 'La cuenta de Mercado Libre de esta conversación no está vinculada o no tiene token.',
            ], 422);
        }

        $packId = $flowModel->pack_id ?: $flowModel->order_id;

        if (! $packId || str_starts_with((string) $packId, 'no-order-')) {
            return response()->json([
                'ok' => false,
                'message' => 'Esta conversación no tiene pack u orden válido para consultar en Mercado Libre.',
            ], 422);
        }

        $limit = max(1, min(100, (int) $request->query('limit', 50)));
        $offset = max(0, (int) $request->query('offset', 0));
        $markAsRead = filter_var(
            $request->query('mark_as_read', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        try {
            $raw = $meli->getPackPostSaleMessages(
                $apiUser,
                (string) $packId,
                $limit,
                $offset,
                $markAsRead
            );
        } catch (ClientException $e) {
            $status = $e->hasResponse()
                ? $e->getResponse()->getStatusCode()
                : 502;
            $body = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : '';

            return response()->json([
                'ok' => false,
                'message' => 'Mercado Libre rechazó la consulta (HTTP '.$status.').',
                'detail' => $body,
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudieron cargar los mensajes.',
            ], 500);
        }

        $sellerMeliId = (string) $apiUser->meli_id;
        $messages = [];

        foreach ((array) ($raw['messages'] ?? []) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $messages[] = $this->normalizePackMessage(
                $message,
                $sellerMeliId
            );
        }

        usort(
            $messages,
            static fn (array $a, array $b): int => strcmp(
                (string) ($a['created'] ?? ''),
                (string) ($b['created'] ?? '')
            )
        );

        return response()->json([
            'ok' => true,
            'messages' => $messages,
            'paging' => $raw['paging'] ?? null,
            'conversation_status' => $raw['conversation_status'] ?? null,
            'seller_max_message_length' => $raw['seller_max_message_length'] ?? null,
        ]);
    }

    public function saleDetails(
        Request $request,
        MeliApi $meli,
        string $flow
    ): JsonResponse {
        $flowModel = $this->findOwnedFlow($request, $flow);
        $apiUser = $this->makeApiUserForFlow($request->user(), $flowModel);

        if (! $apiUser) {
            return response()->json([
                'ok' => false,
                'message' => 'La cuenta de Mercado Libre de esta conversación no está vinculada o no tiene token.',
            ], 422);
        }

        $orderId = $this->firstNumericOrderIdFromFlow($flowModel);

        if ($orderId === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Esta conversación no tiene un número de orden válido para consultar el detalle de venta.',
            ], 422);
        }

        try {
            $order = $meli->getOrder($apiUser, $orderId);
        } catch (ClientException $e) {
            $status = $e->hasResponse()
                ? $e->getResponse()->getStatusCode()
                : 502;

            return response()->json([
                'ok' => false,
                'message' => 'Mercado Libre no devolvió la orden (HTTP '.$status.').',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo cargar el detalle de la venta.',
            ], 500);
        }

        if (! isset($order['id'])) {
            return response()->json([
                'ok' => false,
                'message' => 'La respuesta de Mercado Libre no incluye datos de orden.',
            ], 422);
        }

        $buyer = (array) ($order['buyer'] ?? []);
        $buyerName = $this->resolveBuyerDisplayName($buyer);
        $currencyId = (string) ($order['currency_id'] ?? '');
        $lines = [];

        foreach ((array) ($order['order_items'] ?? []) as $orderItem) {
            if (! is_array($orderItem)) {
                continue;
            }

            $item = (array) ($orderItem['item'] ?? []);
            $title = trim((string) ($item['title'] ?? ''));
            $sku = trim((string) ($item['seller_sku'] ?? ''));

            if ($sku === '') {
                $sku = trim((string) ($item['seller_custom_field'] ?? ''));
            }

            $quantity = (int) ($orderItem['quantity'] ?? 0);
            $unitPrice = $orderItem['unit_price'] ?? null;
            $itemId = isset($item['id']) ? (string) $item['id'] : '';

            if ($title === '' && $itemId === '') {
                continue;
            }

            if (
                $sku === ''
                && $itemId !== ''
                && (string) $flowModel->item_id === $itemId
                && filled($flowModel->sku)
            ) {
                $sku = trim((string) $flowModel->sku);
            }

            $lines[] = [
                'item_id' => $itemId,
                'publication_title' => $title,
                'product_title' => $title,
                'sku' => $sku !== '' ? $sku : null,
                'quantity' => $quantity > 0 ? $quantity : null,
                'unit_price' => is_numeric($unitPrice)
                    ? (float) $unitPrice
                    : null,
            ];
        }

        return response()->json([
            'ok' => true,
            'order_id' => (string) ($order['id'] ?? $orderId),
            'buyer_id' => isset($buyer['id'])
                ? (string) $buyer['id']
                : null,
            'buyer_name' => $buyerName,
            'currency_id' => $currencyId,
            'lines' => $lines,
        ]);
    }

    public function reply(
        Request $request,
        MeliMessageService $messages,
        string $flow
    ): RedirectResponse {
        $flowModel = $this->findOwnedFlow($request, $flow);

        $max = max(
            1,
            (int) config('meli_menu.seller_max_message_length', 350)
        );

        $request->validate([
            'text' => 'required|string|min:1|max:'.$max,
        ]);

        $result = $messages->trySendMessage(
            $flowModel,
            (string) $request->input('text')
        );

        if ($result['ok']) {
            return back()->with('ok', 'Mensaje enviado a Mercado Libre.');
        }

        return back()->with(
            'err',
            $result['error'] ?? 'No se pudo enviar el mensaje.'
        );
    }

    private function resolveSelectedAccount(
        Request $request,
        $accounts
    ): ?MeliAccount {
        if ($accounts->isEmpty()) {
            return null;
        }

        $requestedId = $request->integer('account_id');

        if ($requestedId) {
            $requested = $accounts->firstWhere('id', $requestedId);

            if ($requested) {
                return $requested;
            }
        }

        return $accounts->firstWhere('is_default', true)
            ?? $accounts->first();
    }

    private function findOwnedFlow(
        Request $request,
        string $flow
    ): MeliChatFlow {
        return MeliChatFlow::query()
            ->with('meliAccount')
            ->where('user_id', $request->user()->id)
            ->whereKey($flow)
            ->firstOrFail();
    }

    private function makeApiUserForFlow(
        User $owner,
        MeliChatFlow $flow
    ): ?User {
        $account = $flow->meliAccount;

        if (
            ! $account
            || (int) $account->user_id !== (int) $owner->id
            || ! filled($account->meli_user_id)
            || ! filled($account->access_token)
        ) {
            return null;
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

    private function firstNumericOrderIdFromFlow(
        MeliChatFlow $flow
    ): ?int {
        $raw = trim((string) ($flow->order_id ?? ''));

        if ($raw === '' || str_starts_with($raw, 'no-order-')) {
            return null;
        }

        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            $digits = preg_replace('/\D+/', '', (string) $part);

            if ($digits !== '' && ctype_digit($digits)) {
                return (int) $digits;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $buyer
     */
    private function resolveBuyerDisplayName(array $buyer): ?string
    {
        $nickname = trim((string) ($buyer['nickname'] ?? ''));

        if ($nickname !== '') {
            return $nickname;
        }

        $first = trim((string) ($buyer['first_name'] ?? ''));
        $last = trim((string) ($buyer['last_name'] ?? ''));
        $full = trim($first.' '.$last);

        return $full !== '' ? $full : null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function normalizePackMessage(
        array $message,
        string $sellerMeliId
    ): array {
        $text = $message['text'] ?? '';

        if (is_array($text)) {
            $text = (string) ($text['plain'] ?? $text['text'] ?? '');
        }

        $text = trim((string) $text);
        $from = trim((string) data_get($message, 'from.user_id', ''));
        $role = $from !== '' && $from === $sellerMeliId
            ? 'seller'
            : 'customer';

        $dates = (array) ($message['message_date'] ?? []);

        return [
            'id' => (string) ($message['id'] ?? ''),
            'role' => $role,
            'from_user_id' => $from,
            'text' => $text,
            'created' => $dates['created'] ?? $dates['received'] ?? null,
            'read' => $dates['read'] ?? null,
            'status' => (string) ($message['status'] ?? ''),
        ];
    }
}
