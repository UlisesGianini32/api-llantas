<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliClaim;

class MeliClaimActionCatalog
{
    /**
     * Mercado Libre action => Telegram behavior.
     *
     * @var array<string, array{action:string,label:string,callback:string,flow:string,receiver_role?:string,parameters:list<string>}>
     */
    private const ACTIONS = [
        'refund' => [
            'action' => 'refund', 'label' => '💰 Reembolso total', 'callback' => 'r',
            'flow' => 'economic', 'parameters' => [],
        ],
        'allow_return' => [
            'action' => 'allow_return', 'label' => '📦 Habilitar devolución', 'callback' => 'd',
            'flow' => 'economic', 'parameters' => [],
        ],
        'allow_return_label' => [
            'action' => 'allow_return', 'label' => '📦 Habilitar devolución', 'callback' => 'd',
            'flow' => 'economic', 'parameters' => [],
        ],
        'allow_partial_refund' => [
            'action' => 'partial_refund', 'label' => '💵 Reembolso parcial', 'callback' => 'p',
            'flow' => 'economic', 'parameters' => ['amount'],
        ],
        'send_message_to_complainant' => [
            'action' => 'send_message_to_complainant', 'label' => '💬 Responder comprador', 'callback' => 'c',
            'flow' => 'message', 'receiver_role' => 'complainant', 'parameters' => ['message'],
        ],
        'send_message_to_mediator' => [
            'action' => 'send_message_to_mediator', 'label' => '🧑‍⚖️ Responder mediador', 'callback' => 'm',
            'flow' => 'message', 'receiver_role' => 'mediator', 'parameters' => ['message'],
        ],
        'send_message_to_respondent' => [
            'action' => 'send_message_to_respondent', 'label' => '💬 Responder vendedor', 'callback' => 'v',
            'flow' => 'message', 'receiver_role' => 'respondent', 'parameters' => ['message'],
        ],
    ];

    /** @return list<array<string, mixed>> */
    public function available(MeliClaim $claim): array
    {
        return collect((array) $claim->available_actions)
            ->map(fn (mixed $value): ?array => $this->descriptor($this->name($value)))
            ->filter()
            ->unique(fn (array $item): string => $item['action'])
            ->values()->all();
    }

    public function fromCallback(string $callback): ?array
    {
        return collect(self::ACTIONS)->first(fn (array $item): bool => $item['callback'] === $callback);
    }

    public function descriptor(?string $remoteAction): ?array
    {
        return $remoteAction !== null ? (self::ACTIONS[$remoteAction] ?? null) : null;
    }

    public function economicAction(?string $remoteAction): ?string
    {
        $descriptor = $this->descriptor($remoteAction);

        return ($descriptor['flow'] ?? null) === 'economic' ? $descriptor['action'] : null;
    }

    public function messageReceiver(?string $remoteAction): ?string
    {
        $descriptor = $this->descriptor($remoteAction);

        return ($descriptor['flow'] ?? null) === 'message' ? ($descriptor['receiver_role'] ?? null) : null;
    }

    public function name(mixed $action): ?string
    {
        if (is_string($action)) {
            return $action;
        }
        if (! is_array($action)) {
            return null;
        }

        return $action['action'] ?? $action['action_name'] ?? $action['name'] ?? null;
    }
}
