<?php

namespace App\Services\MercadoLibre\Claims;

class MeliClaimResolutionPolicy
{
    public function __construct(private MeliClaimActionCatalog $catalog) {}

    /** @return list<string> */
    public function available(array $rawClaim): array
    {
        return collect((array) ($rawClaim['players'] ?? []))
            ->filter(fn (mixed $player): bool => is_array($player)
                && (($player['role'] ?? null) === 'respondent' || ($player['type'] ?? null) === 'seller'))
            ->flatMap(fn (array $player): array => (array) ($player['available_actions'] ?? []))
            ->map(fn (mixed $action): ?string => $this->catalog->economicAction($this->catalog->name($action)))
            ->filter()->unique()->values()->all();
    }

    public function allows(array $rawClaim, string $action): bool
    {
        return in_array($action, $this->available($rawClaim), true);
    }
}
