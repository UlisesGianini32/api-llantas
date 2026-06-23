<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncMeliAfterImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 min
    public int $tries = 1;

    public function __construct(public string $chatId) {}

    public function handle(): void
    {
        try {
            // Tu comando que ya hace refresh token + sync
            Artisan::call('meli:sync-stock');

            $this->send("✅ Sincronización con Mercado Libre terminada (stock/precio).");
        } catch (\Throwable $e) {
            Log::error('❌ Error Sync ML', ['err' => $e->getMessage()]);
            $this->send("❌ Error sincronizando con ML: " . $e->getMessage());
            throw $e;
        }
    }

    private function send(string $text): void
    {
        $token = (string) env('TELEGRAM_BOT_TOKEN');

        Http::timeout(20)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $this->chatId,
            'text'    => $text,
        ]);
    }
}
