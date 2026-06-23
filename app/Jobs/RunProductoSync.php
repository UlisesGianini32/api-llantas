<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ProductMelisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunProductoSync implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;
    public $timeout = 1800; // 30 min
    public $tries = 1;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'producto_sync_user_' . $this->userId;
    }

    public function uniqueFor(): int
    {
        return 3600; // 60 min
    }

    public function handle(): void
    {
        $runningKey = "producto_sync:running:user:{$this->userId}";
        $lastRunKey = "producto_sync:last_run_at:user:{$this->userId}";
        $lastResKey = "producto_sync:last_result:user:{$this->userId}";

        Cache::put($runningKey, true, now()->addHour());
        Cache::put($lastRunKey, now()->toDateTimeString(), now()->addDays(7));

        try {
            /** @var User $user */
            $user = User::findOrFail($this->userId);

            /** @var ProductMelisService $service */
            $service = app(ProductMelisService::class);
            $result = $service->sync($user);

            Cache::put($lastResKey, [
                'ok' => true,
                'inserted' => $result['inserted'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'finished_at' => now()->toDateTimeString(),
            ], now()->addDays(7));

            Log::info('PRODUCTO SYNC OK', [
                'user_id' => $this->userId,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            Cache::put($lastResKey, [
                'ok' => false,
                'error' => $e->getMessage(),
                'finished_at' => now()->toDateTimeString(),
            ], now()->addDays(7));

            Log::error('PRODUCTO SYNC FAIL', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            Cache::forget($runningKey);
        }
    }
}
