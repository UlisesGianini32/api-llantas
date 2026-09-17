<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncMeliOpenClaimsForTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public string $chatId) {}

    public function handle(MeliClaimsService $claims, TelegramBotClient $telegram): void
    {
        $totals = ['received' => 0, 'saved' => 0, 'skipped' => 0, 'reconciled' => 0, 'failed' => 0];
        foreach (MeliAccount::query()->whereNotNull('access_token')->get() as $account) {
            try {
                $result = $claims->syncAccount($account, 'opened', 0, false);
                foreach ($totals as $key => $value) {
                    $totals[$key] += (int) ($result[$key] ?? 0);
                }
            } catch (Throwable) {
                $totals['failed']++;
            }
        }

        $telegram->sendMessage($this->chatId, "✅ Sincronización de reclamos terminada.\nRevisados: {$totals['received']}\nActualizados: {$totals['saved']}\nSin cambios: {$totals['skipped']}\nReconciliados: {$totals['reconciled']}\nFallidos: {$totals['failed']}", [
            [['text' => '🚨 Ver reclamos', 'callback_data' => 'c']],
            [['text' => '🏠 Menú principal', 'callback_data' => 'm']],
        ]);
    }
}
