<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramPostSaleService;
use App\Services\Telegram\TelegramPostSaleSyncCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncMeliPostSaleForTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 20;

    private const REQUEST_INTERVAL_MICROSECONDS = 250000;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public string $chatId,
        public int $afterId = 0,
        public int $synced = 0,
        public int $failed = 0,
        public string $lockOwner = '',
    ) {}

    public function handle(
        TelegramPostSaleService $postSale,
        TelegramBotClient $telegram,
        TelegramPostSaleSyncCoordinator $coordinator,
    ): void {
        if ($this->lockOwner === '' || ! $coordinator->owns($this->lockOwner)) {
            return;
        }
        $flows = $postSale->syncableFlowsAfter($this->afterId, self::CHUNK_SIZE);
        $synced = $this->synced;
        $failed = $this->failed;

        foreach ($flows as $index => $flow) {
            $postSale->syncFlow($flow) === null ? $failed++ : $synced++;
            if ($index < $flows->count() - 1) {
                usleep(self::REQUEST_INTERVAL_MICROSECONDS);
            }
        }

        if ($flows->count() === self::CHUNK_SIZE) {
            self::dispatch($this->chatId, (int) $flows->last()->id, $synced, $failed, $this->lockOwner)
                ->delay(now()->addSecond());

            return;
        }

        $coordinator->release($this->lockOwner);
        $telegram->sendMessage($this->chatId, "✅ Sincronización de mensajería posventa terminada.\nActualizadas: {$synced}\nFallidas: {$failed}", [
            [['text' => '💬 Ver posventa', 'callback_data' => 'p']],
            [['text' => '🏠 Menú principal', 'callback_data' => 'm']],
        ]);
    }

    public function failed(?Throwable $error): void
    {
        app(TelegramPostSaleSyncCoordinator::class)->release($this->lockOwner);
    }
}
