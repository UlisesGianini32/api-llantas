<?php

namespace App\Services\MercadoLibre\Claims;

class MeliClaimResolutionPolicy
{
    private const ACTIONS = [
        'refund' => 'refund',
        'allow_return' => 'allow_return',
        'allow_return_label' => 'allow_return',
        'allow_partial_refund' => 'partial_refund',
    ];

    /** @return list<string> */
    public function available(array $rawClaim): array
    {
        return collect((array) ($rawClaim['players'] ?? []))
            ->filter(fn (mixed $player): bool => is_array($player)
                && (($player['role'] ?? null) === 'respondent' || ($player['type'] ?? null) === 'seller'))
            ->flatMap(fn (array $player): array => (array) ($player['available_actions'] ?? []))
                ->map(fn (mixed $action): ?string => self::ACTIONS[$this->name($action)] ?? null)
                ->filter()->unique()->values()->all();
    }

    public function allows(array $rawClaim, string $action): bool
    {
        return in_array($action, $this->available($rawClaim), true);
    }

    private function name(mixed $action): ?string
    {
        if (is_string($action)) return $action;
        if (! is_array($action)) return null;

        return $action['action'] ?? $action['action_name'] ?? $action['name'] ?? null;
    }
}
