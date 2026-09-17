<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliClaim;

class MeliClaimMessagePolicy
{
    private const RECIPIENTS = [
        'send_message_to_complainant' => 'complainant',
        'send_message_to_mediator' => 'mediator',
        'send_message_to_respondent' => 'respondent',
    ];

    public function recipient(MeliClaim $claim): ?string
    {
        if (in_array($claim->status, ['closed', 'resolved'], true)) return null;

        $recipients = collect((array) $claim->available_actions)
            ->map(fn (mixed $action): ?string => $this->actionName($action))
            ->filter(fn (?string $action): bool => isset(self::RECIPIENTS[$action]))
            ->map(fn (string $action): string => self::RECIPIENTS[$action])
            ->unique()->values();

        return $recipients->count() === 1 ? $recipients->first() : null;
    }

    private function actionName(mixed $action): ?string
    {
        if (is_string($action)) return $action;
        if (! is_array($action)) return null;

        return $action['action'] ?? $action['action_name'] ?? $action['name'] ?? null;
    }
}
