<?php

namespace App\Jobs;

use App\Services\MeliSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMeliStockAndPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200; // 20 min
    public $tries = 1;      // importante: no reintentar miles de requests

    public function handle(MeliSyncService $service): void
    {
        Log::info("MELI SYNC JOB: Iniciando");
        $service->syncStock(); // tu método actual
        Log::info("MELI SYNC JOB: Terminado");
    }
}