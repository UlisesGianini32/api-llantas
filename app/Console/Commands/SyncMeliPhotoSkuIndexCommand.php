<?php

namespace App\Console\Commands;

use App\Services\Purchasing\MeliListingSkuIndexService;
use Illuminate\Console\Command;

/**
 * Rebuilds the normalized auxiliary SKU/variation index (`meli_listing_sku_index`)
 * in constant-memory primary-key chunks so purchase order photos resolve without
 * full-table `LIKE` scans on JSON payloads.
 */
class SyncMeliPhotoSkuIndexCommand extends Command
{
    protected $signature = 'purchasing:sync-meli-photo-sku-index
        {--chunk=200 : Cantidad de registros por lote en memoria}';

    protected $description = 'Sincroniza el índice auxiliar normalizado de SKUs y variaciones ML para fotografías de órdenes de compra.';

    public function handle(MeliListingSkuIndexService $indexService): int
    {
        if (! $indexService->isTableAvailable()) {
            $this->error('La tabla meli_listing_sku_index no existe todavía. Ejecuta la migración correspondiente antes de sincronizar.');

            return self::FAILURE;
        }

        $chunk = max(25, min(1000, (int) $this->option('chunk')));
        $startedAt = microtime(true);

        $stats = $indexService->rebuildIndex($chunk);

        $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
        $peakMb = round(memory_get_peak_usage(true) / 1048576, 2);

        $this->info('Índice meli_listing_sku_index sincronizado correctamente.');
        $this->line(sprintf(
            'Publicaciones escaneadas: %d | Price Manager escaneados: %d | Filas indexadas: %d | Tiempo: %.1f ms | Memoria pico: %.2f MB',
            $stats['publications_scanned'],
            $stats['price_manager_scanned'],
            $stats['rows_indexed'],
            $elapsedMs,
            $peakMb,
        ));

        return self::SUCCESS;
    }
}
