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

class RunMeliCompareSync implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;

    // Ajusta si quieres
    public $timeout = 1200; // 20 min
    public $tries = 1;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->onQueue('default');
    }

    // 👇 clave única por usuario (no deja correr dos a la vez)
    public function uniqueId(): string
    {
        return 'ml_compare_sync_user_' . $this->userId;
    }

    // 👇 cuánto tiempo se reserva el "unique lock" (por si se muere el worker)
    public function uniqueFor(): int
    {
        return 1800; // 30 min
    }

    public function handle(): void
    {
        $runningKey = "ml_compare:running:user:{$this->userId}";
        $lastRunKey = "ml_compare:last_run_at:user:{$this->userId}";
        $lastResKey = "ml_compare:last_result:user:{$this->userId}";

        Cache::put($runningKey, true, now()->addMinutes(30));
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

            Log::info('ML COMPARE SYNC OK', [
                'user_id' => $this->userId,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            Cache::put($lastResKey, [
                'ok' => false,
                'error' => $e->getMessage(),
                'finished_at' => now()->toDateTimeString(),
            ], now()->addDays(7));

            Log::error('ML COMPARE SYNC FAIL', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            Cache::forget($runningKey);
        }
    }
}