<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MeliAccountPublicationSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncMeliAccountPublicationsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 2;
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $userId,
        public readonly int $accountId,
    ) {
        $this->onQueue('meli');
    }

    public function uniqueId(): string
    {
        return "meli-account-publications:{$this->userId}:{$this->accountId}";
    }

    public function handle(MeliAccountPublicationSyncService $service): void
    {
        $account = MeliAccount::query()
            ->where('user_id', $this->userId)
            ->findOrFail($this->accountId);

        $key = MeliAccountPublicationSyncService::cacheKey($this->userId, $this->accountId);
        $startedAt = now()->toDateTimeString();

        Cache::put($key, [
            'status' => 'running',
            'phase' => 'starting',
            'message' => 'Iniciando sincronización de todas las publicaciones...',
            'account_id' => $this->accountId,
            'started_at' => $startedAt,
        ], now()->addDay());

        try {
            $summary = $service->sync($account, function (array $state) use ($key, $startedAt): void {
                Cache::put($key, [
                    'status' => ($state['phase'] ?? null) === 'finished' ? 'finished' : 'running',
                    'account_id' => $this->accountId,
                    'started_at' => $startedAt,
                    ...$state,
                ], now()->addDay());
            });

            Cache::put($key, [
                'status' => 'finished',
                'phase' => 'finished',
                'message' => "Sincronización terminada: {$summary['saved']} publicaciones guardadas.",
                ...$summary,
            ], now()->addDay());
        } catch (Throwable $e) {
            Cache::put($key, [
                'status' => 'failed',
                'phase' => 'failed',
                'message' => $e->getMessage(),
                'account_id' => $this->accountId,
                'started_at' => $startedAt,
                'finished_at' => now()->toDateTimeString(),
            ], now()->addDay());

            throw $e;
        }
    }
}
