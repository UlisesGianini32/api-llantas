<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MeliSyncService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SyncMeliStockSyscom extends Command
{
    protected $signature = 'meli:sync-stock-syscom
        {--skip-refresh : No refrescar stock_hermosillo desde SYSCOM antes de empujar a ML}';

    protected $description = 'Sincroniza con Mercado Libre SOLO las publicaciones SYSCOM (sin tocar llantas ni productos compuestos)';

    public function handle(MeliSyncService $service)
    {
        if (! $this->option('skip-refresh')) {
            $this->info('Actualizando stock SYSCOM (Hermosillo) antes de empujar a ML...');
            try {
                Artisan::call('syscom:refresh-hermosillo-for-published', ['--no-sync-ml' => true]);
                $refreshOut = trim(Artisan::output());
                if ($refreshOut !== '') {
                    $this->line($refreshOut);
                }
            } catch (\Throwable $e) {
                Log::warning('meli:sync-stock-syscom: refresh SYSCOM falló', ['e' => $e->getMessage()]);
                $this->warn('Refresh SYSCOM omitido: '.$e->getMessage());
            }
        } else {
            $this->info('Saltando refresh de stock_hermosillo (--skip-refresh).');
        }

        $this->info('Iniciando sincronización SOLO SYSCOM...');
        Log::info('ARTISAN meli:sync-stock-syscom ejecutado');

        try {
            $service->syncSyscomPublicationsOnly();
            $this->info('¡Sincronización SYSCOM completada!');
            Log::info('ARTISAN meli:sync-stock-syscom completado OK');
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('ARTISAN meli:sync-stock-syscom ERROR: ' . $e->getMessage());
        }
    }
}
