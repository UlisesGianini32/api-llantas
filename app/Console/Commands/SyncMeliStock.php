<?php

namespace App\Console\Commands;

use App\Services\MeliSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMeliStock extends Command
{
    protected $signature = 'meli:sync-stock';

    protected $description = 'Sincroniza el stock y precio local con Mercado Libre';

    public function handle(MeliSyncService $service): int
    {
        $this->info('Iniciando sincronización con Mercado Libre...');
        Log::info('ARTISAN meli:sync-stock ejecutado');

        try {
            $service->syncStock();

            $this->info('¡Sincronización completada!');
            Log::info('ARTISAN meli:sync-stock completado OK');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());
            Log::error('ARTISAN meli:sync-stock ERROR: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
