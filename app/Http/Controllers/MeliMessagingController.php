<?php

namespace App\Http\Controllers;

use App\Models\MeliChatFlow;
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
        $user = $request->user();

        $flows = MeliChatFlow::query()
            ->where('user_id', $user->id)
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->orderByDesc('updated_at')
            ->limit(150)
            ->get();

        $selected = null;
        if ($request->filled('flow') && ctype_digit((string) $request->query('flow'))) {
            $sid = (int) $request->query('flow');
            if (MeliChatFlow::query()->where('user_id', $user->id)->whereKey($sid)->exists()) {
                $selected = $sid;
            }
        }

        return Inertia::render('MeliMessaging/Index', [
            'flows' => $flows->map(static function (MeliChatFlow $f) {
                return [
                    'id' => $f->id,
                    'order_id' => $f->order_id,
                    'pack_id' => $f->pack_id,
                    'buyer_id' => $f->buyer_id,
                    'sku' => $f->sku,
                    'item_id' => $f->item_id,
                    'site_id' => (string) (data_get($f->meta, 'site_id') ?: ''),
                    'requires_human' => (bool) $f->requires_human,
                    'updated_at' => $f->updated_at?->toIso8601String(),
                    'menu_sent' => (bool) $f->menu_sent,
                ];
            })->values(),
            'meliLinked' => filled($user->meli_id),
            'sellerMaxLength' => max(1, (int) config('meli_menu.seller_max_message_length', 350)),
            'selectedFlowId' => $selected,
        ]);
    }

    public function messages(Request $request, MeliApi $meli, string $flow): JsonResponse
    {
        $flowModel = MeliChatFlow::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($flow)
            ->firstOrFail();

        $user = $request->user();

        if (! filled($user->meli_id) || ! filled($user->access_token)) {
            return response()->json([
                'ok' => false,
                'message' => 'Cuenta de Mercado Libre no vinculada o sin token. Usá “Refrescar token” en el dashboard si hace falta.',
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
        $markAsRead = filter_var($request->query('mark_as_read', 'false'), FILTER_VALIDATE_BOOLEAN);

        try {
            $raw = $meli->getPackPostSaleMessages($user, (string) $packId, $limit, $offset, $markAsRead);
        } catch (ClientException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 502;
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            return response()->json([
                'ok' => false,
                'message' => 'Mercado Libre rechazó la consulta (HTTP ' . $status . ').',
                'detail' => $body,
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudieron cargar los mensajes.',
            ], 500);
        }

        $sellerMeliId = (string) $user->meli_id;
        $messages = [];
        foreach ((array) ($raw['messages'] ?? []) as $m) {
            if (! is_array($m)) {
                continue;
            }
            $messages[] = $this->normalizePackMessage($m, $sellerMeliId);
        }

        usort($messages, static function (array $a, array $b): int {
            return strcmp((string) ($a['created'] ?? ''), (string) ($b['created'] ?? ''));
        });

        return response()->json([
            'ok' => true,
            'messages' => $messages,
            'paging' => $raw['paging'] ?? null,
            'conversation_status' => $raw['conversation_status'] ?? null,
            'seller_max_message_length' => $raw['seller_max_message_length'] ?? null,
        ]);
    }

    public function saleDetails(Request $request, MeliApi $meli, string $flow): JsonResponse
    {
        $flowModel = MeliChatFlow::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($flow)
            ->firstOrFail();

        $user = $request->user();

        if (! filled($user->meli_id) || ! filled($user->access_token)) {
            return response()->json([
                'ok' => false,
                'message' => 'Cuenta de Mercado Libre no vinculada o sin token.',
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
            $order = $meli->getOrder($user, $orderId);
        } catch (ClientException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 502;

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
        foreach ((array) ($order['order_items'] ?? []) as $oi) {
            if (! is_array($oi)) {
                continue;
            }
            $item = (array) ($oi['item'] ?? []);
            $title = trim((string) ($item['title'] ?? ''));
            $sku = trim((string) ($item['seller_sku'] ?? ''));
            if ($sku === '') {
                $sku = trim((string) ($item['seller_custom_field'] ?? ''));
            }
            $qty = (int) ($oi['quantity'] ?? 0);
            $unitPrice = $oi['unit_price'] ?? null;
            $itemId = isset($item['id']) ? (string) $item['id'] : '';

            if ($title === '' && $itemId === '') {
                continue;
            }

            if ($sku === '' && $itemId !== '' && (string) $flowModel->item_id === $itemId && filled($flowModel->sku)) {
                $sku = trim((string) $flowModel->sku);
            }

            $lines[] = [
                'item_id' => $itemId,
                'publication_title' => $title,
                'product_title' => $title,
                'sku' => $sku !== '' ? $sku : null,
                'quantity' => $qty > 0 ? $qty : null,
                'unit_price' => is_numeric($unitPrice) ? (float) $unitPrice : null,
            ];
        }

        return response()->json([
            'ok' => true,
            'order_id' => (string) ($order['id'] ?? $orderId),
            'buyer_id' => isset($buyer['id']) ? (string) $buyer['id'] : null,
            'buyer_name' => $buyerName,
            'currency_id' => $currencyId,
            'lines' => $lines,
        ]);
    }

    private function firstNumericOrderIdFromFlow(MeliChatFlow $flow): ?int
    {
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
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function normalizePackMessage(array $m, string $sellerMeliId): array
    {
        $text = $m['text'] ?? '';
        if (is_array($text)) {
            $text = (string) ($text['plain'] ?? $text['text'] ?? '');
        }
        $text = trim((string) $text);
        $from = trim((string) data_get($m, 'from.user_id', ''));
        $role = $from !== '' && $from === $sellerMeliId ? 'seller' : 'customer';

        $dates = (array) ($m['message_date'] ?? []);

        return [
            'id' => (string) ($m['id'] ?? ''),
            'role' => $role,
            'from_user_id' => $from,
            'text' => $text,
            'created' => $dates['created'] ?? $dates['received'] ?? null,
            'read' => $dates['read'] ?? null,
            'status' => (string) ($m['status'] ?? ''),
        ];
    }

    public function reply(Request $request, MeliMessageService $messages, string $flow): RedirectResponse
    {
        $flowModel = MeliChatFlow::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($flow)
            ->firstOrFail();

        $max = max(1, (int) config('meli_menu.seller_max_message_length', 350));

        $request->validate([
            'text' => 'required|string|min:1|max:' . $max,
        ]);

        $result = $messages->trySendMessage($flowModel, (string) $request->input('text'));

        if ($result['ok']) {
            return back()->with('ok', 'Mensaje enviado a Mercado Libre.');
        }

        return back()->with('err', $result['error'] ?? 'No se pudo enviar el mensaje.');
    }
}
