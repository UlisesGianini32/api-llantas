<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\PriceManager\MeliPriceManagerSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncMeliPriceManagerItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $meliAccountId)
    {
        $this->onQueue('meli');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return 'meli-price-manager-sync:'.$this->meliAccountId;
    }

    public function handle(MeliPriceManagerSyncService $service): void
    {
        try {
            $account = MeliAccount::query()->findOrFail($this->meliAccountId);
            $summary = $service->syncAccount($account);

            Log::info('[MeliPriceManager] Job completed', [
                'meli_account_id' => $this->meliAccountId,
                'summary' => $summary,
            ]);
        } finally {
            Cache::forget(self::statusCacheKey($this->meliAccountId));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget(self::statusCacheKey($this->meliAccountId));
        Log::error('[MeliPriceManager] Job failed', [
            'meli_account_id' => $this->meliAccountId,
            'exception_class' => $exception !== null ? $exception::class : null,
            'message' => $this->sanitizeMessage($exception?->getMessage()),
        ]);
    }

    public static function statusCacheKey(int $meliAccountId): string
    {
        return 'meli-price-manager:sync-status:'.$meliAccountId;
    }

    private function sanitizeMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = preg_replace([
            '/Bearer\s+[A-Za-z0-9._~-]+/i',
            '/\b(access_token|refresh_token|client_secret|authorization)\b\s*[=:]\s*[^\s,;]+/i',
            '/\bAPP_USR-[A-Za-z0-9_-]+\b/',
        ], ['Bearer [REDACTED]', '$1=[REDACTED]', '[REDACTED]'], $message) ?? 'Error sanitizado';

        return Str::limit($message, 1000, '…');
    }
}
