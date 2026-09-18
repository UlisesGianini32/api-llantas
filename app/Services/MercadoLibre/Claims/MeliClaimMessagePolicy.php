<?php

namespace App\Services\MercadoLibre\Claims;

use App\Models\MeliClaim;

class MeliClaimMessagePolicy
{
    public function __construct(private MeliClaimActionCatalog $catalog) {}

    public function recipient(MeliClaim $claim, ?string $requestedReceiver = null): ?string
    {
        if (in_array($claim->status, ['closed', 'resolved'], true)) {
            return null;
        }

        $recipients = $this->recipients($claim);
        if ($requestedReceiver !== null) {
            return in_array($requestedReceiver, $recipients, true) ? $requestedReceiver : null;
        }

        return count($recipients) === 1 ? $recipients[0] : null;
    }

    /** @return list<string> */
    public function recipients(MeliClaim $claim): array
    {
        return collect((array) $claim->available_actions)
            ->map(fn (mixed $action): ?string => $this->catalog->messageReceiver($this->catalog->name($action)))
            ->filter()->unique()->values()->all();
    }
}
