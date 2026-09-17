<?php

namespace App\Services\Telegram;

use App\Models\MeliChatFlow;
use App\Services\MeliApi;
use App\Services\MeliMessageService;
use Throwable;

class TelegramPostSaleService
{
    private const PAGE_SIZE = 6;

    public function __construct(
        private MeliApi $api,
        private MeliMessageService $messages,
        private TelegramText $text,
    ) {}

    public function menu(bool $refresh = false): array
    {
        $snapshots = $this->snapshots($refresh);
        $pending = $snapshots->where('pending', true)->count();

        return [
            "💬 MENSAJERÍA POSVENTA\n\nPendientes de respuesta: {$pending}",
            [
                [['text' => '🔴 Pendientes', 'callback_data' => 'pl:p:1']],
                [['text' => '📂 Activas', 'callback_data' => 'pl:a:1']],
                [['text' => '🔄 Actualizar', 'callback_data' => 'pu']],
                [['text' => '🏠 Menú principal', 'callback_data' => 'm']],
            ],
        ];
    }

    public function listing(string $filter, int $page): array
    {
        $snapshots = $this->snapshots();
        if ($filter === 'p') {
            $snapshots = $snapshots->where('pending', true)->values();
        }
        $lastPage = max(1, (int) ceil($snapshots->count() / self::PAGE_SIZE));
        $page = max(1, min($page, $lastPage));
        $keyboard = $snapshots->slice(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)
            ->map(fn (array $snapshot): array => [[
                'text' => ($snapshot['pending'] ? '🔴 ' : '').'Pedido '.$snapshot['flow']->order_id,
                'callback_data' => "pd:{$snapshot['flow']->id}:{$filter}:{$page}",
            ]])->values()->all();
        $navigation = [];
        if ($page > 1) {
            $navigation[] = ['text' => '⬅️', 'callback_data' => "pl:{$filter}:".($page - 1)];
        }
        $navigation[] = ['text' => "{$page}/{$lastPage}", 'callback_data' => "pl:{$filter}:{$page}"];
        if ($page < $lastPage) {
            $navigation[] = ['text' => '➡️', 'callback_data' => "pl:{$filter}:".($page + 1)];
        }
        $keyboard[] = $navigation;
        $keyboard[] = [['text' => '💬 Posventa', 'callback_data' => 'p'], ['text' => '🏠 Menú', 'callback_data' => 'm']];

        return ['💬 '.($filter === 'p' ? 'Pendientes' : 'Conversaciones activas')."\nPágina {$page} de {$lastPage}\nTotal: {$snapshots->count()}", $keyboard];
    }

    public function detail(int $id, string $filter = 'a', int $page = 1): ?array
    {
        $flow = MeliChatFlow::query()->with('meliAccount')->find($id);
        if (! $flow) {
            return null;
        }
        $snapshot = $this->snapshot($flow, true);
        $account = trim((string) $flow->meliAccount?->nickname) ?: 'Cuenta '.$flow->meliAccount?->meli_user_id;
        $product = $flow->sku ? 'SKU '.$flow->sku : ($flow->item_id ?: '—');
        $last = $snapshot['last'];
        $lastText = $last ? $this->text->fromMeli($last['text'] ?? '') : 'No disponible';
        $received = $last['created'] ?? '—';
        $body = '💬 '.($snapshot['pending'] ? 'MENSAJE PENDIENTE' : 'CONVERSACIÓN POSVENTA')."\n\n"
            ."Cuenta: {$account}\nPedido: ".($flow->order_id ?: '—')."\nProducto: {$product}\n\n"
            ."Último mensaje:\n“{$lastText}”\n\nRecibido: {$received}";
        $navigation = $this->detailNavigation($flow, $filter, $page);
        $keyboard = [
            [['text' => '📖 Conversación', 'callback_data' => "pc:{$id}:0"]],
            [['text' => '✍️ Responder', 'callback_data' => "pr:{$id}"]],
            [['text' => '🌐 Abrir en sistema', 'url' => url('/meli/mensajeria?flow='.$id)]],
            $navigation,
            [['text' => '📋 Lista', 'callback_data' => "pl:{$filter}:{$page}"], ['text' => '🏠 Menú', 'callback_data' => 'm']],
        ];

        return [$body, array_values(array_filter($keyboard))];
    }

    public function conversation(int $id, int $offset = 0): ?array
    {
        $flow = MeliChatFlow::query()->find($id);
        if (! $flow) {
            return null;
        }
        $history = $this->history($flow);
        $offset = max(0, $offset);
        $chunk = collect($history)->reverse()->slice($offset, 5)->reverse()->values();
        $parts = $chunk->map(fn (array $message): string => ($message['role'] === 'seller' ? 'Vendedor' : 'Comprador')
            ."\n".($message['created'] ?? '')."\n\n".$this->text->fromMeli($message['text'] ?? ''));
        $body = "📖 Conversación posventa · Pedido {$flow->order_id}\n\n".($parts->isEmpty() ? 'Sin mensajes disponibles.' : $parts->implode("\n\n────────\n\n"));
        $keyboard = [];
        if (count($history) > $offset + 5) {
            $keyboard[] = [['text' => '⬅️ Anteriores', 'callback_data' => "pc:{$id}:".($offset + 5)]];
        }
        if ($offset > 0) {
            $keyboard[] = [['text' => '➡️ Más recientes', 'callback_data' => "pc:{$id}:".max(0, $offset - 5)]];
        }
        $keyboard[] = [['text' => '✍️ Responder', 'callback_data' => "pr:{$id}"], ['text' => '🔙 Conversación', 'callback_data' => "pd:{$id}:a:1"]];

        return [$body, $keyboard];
    }

    public function snapshot(MeliChatFlow $flow, bool $refresh = false): array
    {
        $last = null;
        if ($refresh || $flow->last_message_role === null) {
            $last = collect($this->history($flow))->last();
            $flow->refresh();
        } elseif ($flow->last_message_text !== null || $flow->last_message_at !== null) {
            $last = [
                'role' => $flow->last_message_role,
                'text' => $flow->last_message_text ?? '',
                'created' => $flow->last_message_at?->format('d/m/Y H:i'),
            ];
        }

        return [
            'flow' => $flow,
            'last' => $last,
            'pending' => (bool) $flow->requires_human || ($flow->last_message_role ?? ($last['role'] ?? null)) === 'customer',
        ];
    }

    private function snapshots(bool $refresh = false)
    {
        return MeliChatFlow::query()->with('meliAccount')
            ->whereHas('meliAccount', fn ($query) => $query->whereNotNull('access_token'))
            ->whereNotNull('order_id')->where('order_id', '!=', '')
            ->where('order_id', 'not like', 'no-order-%')
            ->orderByDesc('updated_at')->limit(150)->get()
            ->map(fn (MeliChatFlow $flow): array => $this->snapshot($flow, $refresh));
    }

    private function detailNavigation(MeliChatFlow $flow, string $filter, int $page): array
    {
        $query = MeliChatFlow::query()
            ->whereHas('meliAccount', fn ($query) => $query->whereNotNull('access_token'))
            ->whereNotNull('order_id')->where('order_id', '!=', '')
            ->where('order_id', 'not like', 'no-order-%');
        if ($filter === 'p') {
            $query->where(fn ($query) => $query->where('requires_human', true)->orWhere('last_message_role', 'customer'));
        }
        $ids = $query->orderByDesc('updated_at')->limit(150)->pluck('id')->values();
        $position = $ids->search($flow->id);
        $navigation = [];
        if ($position !== false && $position > 0) {
            $navigation[] = ['text' => '⬅️ Anterior', 'callback_data' => 'pd:'.$ids[$position - 1].":{$filter}:{$page}"];
        }
        if ($position !== false && $position < $ids->count() - 1) {
            $navigation[] = ['text' => 'Siguiente ➡️', 'callback_data' => 'pd:'.$ids[$position + 1].":{$filter}:{$page}"];
        }

        return $navigation;
    }

    private function history(MeliChatFlow $flow): array
    {
        $apiUser = $this->messages->resolveApiUser($flow);
        $packId = $flow->pack_id ?: $flow->order_id;
        if (! $apiUser || ! $packId) {
            return [];
        }

        try {
            $raw = $this->api->getPackPostSaleMessages($apiUser, (string) $packId, 50, 0, false);
        } catch (Throwable) {
            return [];
        }

        $sellerId = (string) $apiUser->meli_id;
        $history = [];
        foreach ((array) ($raw['messages'] ?? []) as $message) {
            if (! is_array($message)) {
                continue;
            }
            $value = $message['text'] ?? '';
            if (is_array($value)) {
                $value = $value['plain'] ?? $value['text'] ?? '';
            }
            $created = data_get($message, 'message_date.created') ?? data_get($message, 'message_date.received');
            $history[] = [
                'id' => (string) ($message['id'] ?? ''),
                'role' => (string) data_get($message, 'from.user_id') === $sellerId ? 'seller' : 'customer',
                'text' => (string) $value,
                'created' => $created ? date('d/m/Y H:i', strtotime((string) $created)) : null,
                'sort' => $created ?: '',
            ];
        }
        usort($history, fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));
        $last = collect($history)->last();
        if ($last) {
            $flow->forceFill([
                'last_message_role' => $last['role'],
                'last_message_at' => $last['sort'] !== '' ? $last['sort'] : null,
                'last_message_text' => $last['text'],
                'last_message_synced_at' => now(),
            ])->save();
        }

        return $history;
    }
}
