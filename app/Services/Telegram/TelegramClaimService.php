<?php

namespace App\Services\Telegram;

use App\Models\MeliClaim;
use App\Services\MercadoLibre\Claims\MeliClaimOperationalCriteria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TelegramClaimService
{
    private const PAGE_SIZE = 6;

    public function __construct(
        private MeliClaimOperationalCriteria $criteria,
        private TelegramText $text,
    ) {}

    public function menu(): array
    {
        $base = MeliClaim::query();
        $open = $this->criteria->applyOpen(clone $base)->count();
        $attention = $this->criteria->applyAttention(clone $base)->count();
        $urgent = $this->criteria->applyUrgent(clone $base)->count();
        $reputation = $this->criteria->applyOpen(clone $base)->where('affects_reputation', true)->count();

        return [
            "🚨 RECLAMOS MERCADO LIBRE\n\nAbiertos: {$open}\nNecesitan atención: {$attention}\nUrgentes: {$urgent}\nAfectan reputación: {$reputation}",
            [
                [['text' => '🔴 Necesitan mi atención', 'callback_data' => 'cl:a:1']],
                [['text' => '⚠️ Urgentes', 'callback_data' => 'cl:u:1']],
                [['text' => '⭐ Afectan reputación', 'callback_data' => 'cl:r:1']],
                [['text' => '📂 Todos los abiertos', 'callback_data' => 'cl:o:1']],
                [['text' => '🔄 Sincronizar abiertos', 'callback_data' => 'cs']],
                [['text' => '🏠 Menú principal', 'callback_data' => 'm']],
            ],
        ];
    }

    public function listing(string $filter, int $page): array
    {
        $claims = $this->ordered($filter);
        $lastPage = max(1, (int) ceil($claims->count() / self::PAGE_SIZE));
        $page = max(1, min($page, $lastPage));
        $slice = $claims->slice(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $keyboard = $slice->map(fn (MeliClaim $claim): array => [[
            'text' => ($this->criteria->urgent($claim) ? '⚠️ ' : '').'#'.$claim->claim_id.' · '.($claim->detail_title ?: $claim->reason?->name ?: 'Sin motivo'),
            'callback_data' => "cd:{$claim->id}:{$filter}:{$page}",
        ]])->values()->all();
        $navigation = [];
        if ($page > 1) {
            $navigation[] = ['text' => '⬅️', 'callback_data' => "cl:{$filter}:".($page - 1)];
        }
        $navigation[] = ['text' => "{$page}/{$lastPage}", 'callback_data' => "cl:{$filter}:{$page}"];
        if ($page < $lastPage) {
            $navigation[] = ['text' => '➡️', 'callback_data' => "cl:{$filter}:".($page + 1)];
        }
        $keyboard[] = $navigation;
        $keyboard[] = [['text' => '🚨 Reclamos', 'callback_data' => 'c'], ['text' => '🏠 Menú', 'callback_data' => 'm']];

        return ["📋 Reclamos · {$this->filterName($filter)}\nPágina {$page} de {$lastPage}\nTotal: {$claims->count()}", $keyboard];
    }

    public function detail(int $id, string $filter = 'o', int $page = 1): ?array
    {
        $claim = MeliClaim::query()->with(['meliAccount', 'reason', 'order.items'])->find($id);
        if (! $claim) {
            return null;
        }
        $item = $claim->order?->items?->first();
        $account = trim((string) $claim->meliAccount?->nickname) ?: 'Cuenta '.$claim->meliAccount?->meli_user_id;
        $due = $claim->due_date?->format('d/m/Y H:i') ?? '—';
        $body = "🚨 Reclamo #{$claim->claim_id}\n\n"
            ."Cuenta: {$account}\nPedido: ".($claim->order_id ?: '—')."\n"
            .'Producto: '.($item?->title ?: '—')."\nSKU: ".($item?->sku ?: '—')."\nCantidad: ".($item?->quantity ?: '—')."\n\n"
            .'Estado: '.($claim->status ?: '—')."\nEtapa: ".($claim->stage ?: '—')."\nResponsable: ".($claim->action_responsible ?: '—')."\n\n"
            .'⚠️ Urgente: '.($this->criteria->urgent($claim) ? 'Sí' : 'No')."\n"
            .'⭐ Afecta reputación: '.($claim->affects_reputation ? 'Sí' : 'No')."\n⏰ Vence: {$due}\n\n"
            .'Motivo:'."\n".($claim->reason?->detail ?: $claim->reason?->name ?: $claim->detail_title ?: '—');
        $webUrl = url('/meli-claims/'.$claim->id);
        $ordered = $this->ordered($filter);
        $position = $ordered->search(fn (MeliClaim $candidate): bool => $candidate->is($claim));
        $navigation = [];
        if ($position !== false && $position > 0) {
            $navigation[] = ['text' => '⬅️ Anterior', 'callback_data' => 'cd:'.$ordered[$position - 1]->id.":{$filter}:{$page}"];
        }
        if ($position !== false && $position < $ordered->count() - 1) {
            $navigation[] = ['text' => 'Siguiente ➡️', 'callback_data' => 'cd:'.$ordered[$position + 1]->id.":{$filter}:{$page}"];
        }

        $keyboard = [
            [['text' => '💬 Conversación', 'callback_data' => "cc:{$claim->id}:0"]],
            [['text' => '✍️ Responder', 'callback_data' => "cr:{$claim->id}"]],
            [['text' => '🌐 Abrir en sistema', 'url' => $webUrl]],
            $navigation,
            [['text' => '📋 Lista', 'callback_data' => "cl:{$filter}:{$page}"], ['text' => '🏠 Menú', 'callback_data' => 'm']],
        ];

        return [$body, array_values(array_filter($keyboard))];
    }

    public function conversation(int $id, int $offset = 0): ?array
    {
        $claim = MeliClaim::query()->find($id);
        if (! $claim) {
            return null;
        }
        $messages = collect((array) $claim->messages)->filter(fn (mixed $message): bool => is_array($message))->values();
        $offset = max(0, $offset);
        $chunk = $messages->reverse()->slice($offset, 5)->reverse()->values();
        $parts = $chunk->map(function (array $message): string {
            $from = $this->role((string) ($message['sender_role'] ?? ''));
            $to = $this->role((string) ($message['receiver_role'] ?? ''));
            $date = data_get($message, 'date_created') ?? data_get($message, 'message_date.created') ?? data_get($message, 'created_at');

            return trim("{$from} → {$to}\n".($date ? date('d/m/Y H:i', strtotime((string) $date)) : '')."\n\n".$this->text->fromMeli($message['message'] ?? $message['translated_message'] ?? ''));
        });
        $body = "💬 Conversación del reclamo #{$claim->claim_id}\n\n".($parts->isEmpty() ? 'Sin mensajes.' : $parts->implode("\n\n────────\n\n"));
        $keyboard = [];
        if ($messages->count() > $offset + 5) {
            $keyboard[] = [['text' => '⬅️ Anteriores', 'callback_data' => "cc:{$id}:".($offset + 5)]];
        }
        if ($offset > 0) {
            $keyboard[] = [['text' => '➡️ Más recientes', 'callback_data' => "cc:{$id}:".max(0, $offset - 5)]];
        }
        $keyboard[] = [['text' => '✍️ Responder', 'callback_data' => "cr:{$id}"], ['text' => '🔙 Reclamo', 'callback_data' => "cd:{$id}:o:1"]];

        return [$body, $keyboard];
    }

    private function filtered(string $filter): Builder
    {
        $query = MeliClaim::query();

        return match ($filter) {
            'a' => $this->criteria->applyAttention($query),
            'u' => $this->criteria->applyUrgent($query),
            'r' => $this->criteria->applyOpen($query)->where('affects_reputation', true),
            default => $this->criteria->applyOpen($query),
        };
    }

    private function ordered(string $filter): Collection
    {
        return $this->filtered($filter)->with(['meliAccount', 'reason', 'order.items'])->get()
            ->sort(function (MeliClaim $a, MeliClaim $b): int {
                $urgent = (int) $this->criteria->urgent($b) <=> (int) $this->criteria->urgent($a);
                if ($urgent !== 0) {
                    return $urgent;
                }
                $aDue = $a->due_date?->timestamp ?? PHP_INT_MAX;
                $bDue = $b->due_date?->timestamp ?? PHP_INT_MAX;

                return $aDue <=> $bDue ?: (($b->last_updated?->timestamp ?? 0) <=> ($a->last_updated?->timestamp ?? 0));
            })->values();
    }

    private function filterName(string $filter): string
    {
        return ['a' => 'Necesitan atención', 'u' => 'Urgentes', 'r' => 'Reputación', 'o' => 'Abiertos'][$filter] ?? 'Abiertos';
    }

    private function role(string $role): string
    {
        return ['complainant' => 'Comprador', 'respondent' => 'Vendedor', 'mediator' => 'Mediador'][$role] ?? ucfirst($role ?: 'Participante');
    }
}
