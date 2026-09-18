<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramPostSaleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMeliPostSaleForTelegramJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 20;

    private const REQUEST_INTERVAL_MICROSECONDS = 250000;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public string $chatId,
        public int $afterId = 0,
        public int $synced = 0,
        public int $failed = 0,
    ) {}

    public function uniqueId(): string
    {
        return 'telegram-post-sale-sync:'.$this->afterId;
    }

    public function handle(TelegramPostSaleService $postSale, TelegramBotClient $telegram): void
    {
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
            self::dispatch($this->chatId, (int) $flows->last()->id, $synced, $failed)
                ->delay(now()->addSecond());

            return;
        }

        $telegram->sendMessage($this->chatId, "✅ Sincronización de mensajería posventa terminada.\nActualizadas: {$synced}\nFallidas: {$failed}", [
            [['text' => '💬 Ver posventa', 'callback_data' => 'p']],
            [['text' => '🏠 Menú principal', 'callback_data' => 'm']],
        ]);
    }
}
