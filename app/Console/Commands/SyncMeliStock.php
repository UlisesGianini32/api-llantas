<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MeliSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SyncMeliStock extends Command
{
    protected $signature = 'meli:sync-stock';
    protected $description = 'Sincroniza el stock de llantas con MercadoLibre';

    public function handle(MeliSyncService $service)
    {
        $this->info('Actualizando stock SYSCOM (Hermosillo) antes de sincronizar ML...');
        try {
            Artisan::call('syscom:refresh-hermosillo-for-published', ['--no-sync-ml' => true]);
            $refreshOut = trim(Artisan::output());
            if ($refreshOut !== '') {
                $this->line($refreshOut);
            }
        } catch (\Throwable $e) {
            Log::warning('meli:sync-stock: refresh SYSCOM falló', ['e' => $e->getMessage()]);
            $this->warn('Refresh SYSCOM omitido: '.$e->getMessage());
        }

        $this->info('Iniciando sincronización...');
        Log::info('ARTISAN meli:sync-stock ejecutado');

        try {
            $service->syncStock();
            $this->info('¡Sincronización completada!');
            Log::info('ARTISAN meli:sync-stock completado OK');
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('ARTISAN meli:sync-stock ERROR: ' . $e->getMessage());
        }
    }
}
