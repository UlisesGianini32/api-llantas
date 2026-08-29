<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMeliClaimJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $meliAccountId, public string $claimId) {}

    public function handle(MeliClaimsService $service): void
    {
        $account = MeliAccount::query()->find($this->meliAccountId);
        if ($account) $service->syncClaim($account, $this->claimId);
    }
}
