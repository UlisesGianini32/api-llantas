<?php

namespace App\Services\Telegram;

use App\Models\MeliChatFlow;
use App\Services\MeliApi;
use App\Services\MeliMessageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramPostSaleService
{
    private const PAGE_SIZE = 6;

    public function __construct(
        private MeliApi $api,
        private MeliMessageService $messages,
        private TelegramText $text,
    ) {}

    public function menu(): array
    {
        $pending = $this->pendingQuery()->count();

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
        $filter = $filter === 'p' ? 'p' : 'a';
        $query = $filter === 'p' ? $this->pendingQuery() : $this->baseQuery();
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($page, $lastPage));
        $flows = $query->with('meliAccount')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->forPage($page, self::PAGE_SIZE)
            ->get();
        $keyboard = $flows->map(fn (MeliChatFlow $flow): array => [[
            'text' => ($this->isPending($flow) ? '🔴 ' : '').'Pedido '.$flow->order_id,
            'callback_data' => "pd:{$flow->id}:{$filter}:{$page}",
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

        return ['💬 '.($filter === 'p' ? 'Pendientes' : 'Conversaciones activas')."\nPágina {$page} de {$lastPage}\nTotal: {$total}", $keyboard];
    }

    public function detail(int $id, string $filter = 'a', int $page = 1): ?array
    {
        $flow = MeliChatFlow::query()->with('meliAccount')->find($id);
        if (! $flow) {
            return null;
        }
        $this->syncFlow($flow);
        $flow->refresh()->load('meliAccount');
        $snapshot = $this->snapshot($flow);
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
        $history = $this->syncFlow($flow) ?? [];
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

    public function snapshot(MeliChatFlow $flow): array
    {
        $last = null;
        if ($flow->last_message_text !== null || $flow->last_message_at !== null || $flow->last_message_role !== null) {
            $last = [
                'role' => $flow->last_message_role,
                'text' => $flow->last_message_text ?? '',
                'created' => $flow->last_message_at?->format('d/m/Y H:i'),
            ];
        }

        return [
            'flow' => $flow,
            'last' => $last,
            'pending' => $this->isPending($flow),
        ];
    }

    /** @return Collection<int, MeliChatFlow> */
    public function syncableFlowsAfter(int $afterId, int $limit): Collection
    {
        return $this->baseQuery()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /** @return array<int, array<string, string|null>>|null */
    public function syncFlow(MeliChatFlow $flow): ?array
    {
        $apiUser = $this->messages->resolveApiUser($flow);
        $packId = $flow->pack_id ?: $flow->order_id;
        if (! $apiUser || ! $packId) {
            return null;
        }

        try {
            $raw = $this->api->getPackPostSaleMessages($apiUser, (string) $packId, 50, 0, false);
        } catch (Throwable $error) {
            Log::warning('Telegram posventa: no se pudo sincronizar una conversación', [
                'flow_id' => $flow->id,
                'error_type' => $error::class,
            ]);

            return null;
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
        $values = ['last_message_synced_at' => now()];
        if ($last) {
            $values += [
                'last_message_role' => $last['role'],
                'last_message_at' => $last['sort'] !== '' ? $last['sort'] : null,
                'last_message_text' => $last['text'],
            ];
            if ($last['role'] === 'seller') {
                $values['requires_human'] = false;
                $values['requires_human_at'] = null;
            }
        }
        $flow->forceFill($values)->save();

        return $history;
    }

    private function detailNavigation(MeliChatFlow $flow, string $filter, int $page): array
    {
        $filter = $filter === 'p' ? 'p' : 'a';
        $query = $filter === 'p' ? $this->pendingQuery() : $this->baseQuery();
        $newer = (clone $query)->where(function (Builder $query) use ($flow): void {
            $query->where('updated_at', '>', $flow->updated_at)
                ->orWhere(function (Builder $query) use ($flow): void {
                    $query->where('updated_at', $flow->updated_at)->where('id', '>', $flow->id);
                });
        })->orderBy('updated_at')->orderBy('id')->first();
        $older = (clone $query)->where(function (Builder $query) use ($flow): void {
            $query->where('updated_at', '<', $flow->updated_at)
                ->orWhere(function (Builder $query) use ($flow): void {
                    $query->where('updated_at', $flow->updated_at)->where('id', '<', $flow->id);
                });
        })->orderByDesc('updated_at')->orderByDesc('id')->first();
        $navigation = [];
        if ($newer) {
            $navigation[] = ['text' => '⬅️ Anterior', 'callback_data' => "pd:{$newer->id}:{$filter}:{$page}"];
        }
        if ($older) {
            $navigation[] = ['text' => 'Siguiente ➡️', 'callback_data' => "pd:{$older->id}:{$filter}:{$page}"];
        }

        return $navigation;
    }

    private function baseQuery(): Builder
    {
        return MeliChatFlow::query()
            ->whereHas('meliAccount', fn (Builder $query) => $query->whereNotNull('access_token'))
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->where('order_id', 'not like', 'no-order-%');
    }

    private function pendingQuery(): Builder
    {
        return $this->baseQuery()->where(function (Builder $query): void {
            $query->where('requires_human', true)->orWhere('last_message_role', 'customer');
        });
    }

    private function isPending(MeliChatFlow $flow): bool
    {
        return (bool) $flow->requires_human || $flow->last_message_role === 'customer';
    }
}
