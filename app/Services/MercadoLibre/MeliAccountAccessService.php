<?php

namespace App\Services\MercadoLibre;

use App\Models\MeliAccount;
use App\Models\MeliAccountUserAccess;
use App\Models\User;
use Illuminate\Support\Collection;

class MeliAccountAccessService
{
    public function hasAccess(User $actor, MeliAccount|int $account): bool
    {
        $accountId = $account instanceof MeliAccount ? $account->getKey() : $account;

        return $actor->meliAccounts()->whereKey($accountId)->exists()
            || MeliAccountUserAccess::query()
                ->where('meli_account_id', $accountId)
                ->where('user_id', $actor->getKey())
                ->where('active', true)
                ->where('can_claim_actions', true)
                ->exists();
    }

    /** @return Collection<int, int> */
    public function accessibleAccountIds(User $actor): Collection
    {
        $owned = $actor->meliAccounts()->pluck('id');
        $delegated = MeliAccountUserAccess::query()
            ->where('user_id', $actor->getKey())
            ->where('active', true)
            ->where('can_claim_actions', true)
            ->pluck('meli_account_id');

        return $owned->merge($delegated)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}
