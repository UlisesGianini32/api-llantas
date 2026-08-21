<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MeliFullStockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncMeliFullStockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public int $userId,
        public int $meliAccountId,
        public ?string $mlm = null,
    ) {
        $this->onQueue('meli');
    }

    public function handle(MeliFullStockService $service): void
    {
        $account = MeliAccount::query()
            ->where('user_id', $this->userId)
            ->find($this->meliAccountId);

        if (! $account) {
            throw new RuntimeException('La cuenta de Mercado Libre ya no existe o no pertenece al usuario.');
        }

        $stats = $service->syncAccount($account, $this->mlm);

        Log::info('MELI FULL: sincronización terminada', [
            'user_id' => $this->userId,
            'meli_account_id' => $this->meliAccountId,
            'mlm' => $this->mlm,
            'stats' => $stats,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('MELI FULL: trabajo fallido', [
            'user_id' => $this->userId,
            'meli_account_id' => $this->meliAccountId,
            'mlm' => $this->mlm,
            'error' => $exception?->getMessage(),
        ]);
    }
}
