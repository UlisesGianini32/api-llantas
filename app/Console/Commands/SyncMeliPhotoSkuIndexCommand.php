<?php

namespace App\Console\Commands;

use App\Services\Purchasing\MeliListingSkuIndexService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuilds or incrementally reconciles the generation-versioned auxiliary SKU/variation
 * index (`meli_listing_sku_index`) without disrupting active reads.
 */
class SyncMeliPhotoSkuIndexCommand extends Command
{
    protected $signature = 'purchasing:sync-meli-photo-sku-index
        {--chunk=200 : Cantidad de registros por lote en memoria}
        {--incremental : Reconciliar únicamente filas modificadas/eliminadas sobre la generación activa}
        {--since= : Fecha/hora opcional de corte para --incremental (ej. "2026-11-20 00:00:00")}
        {--force-takeover : Forzar la toma de una reconstrucción marcada como en curso si el proceso anterior fue interrumpido}';

    protected $description = 'Reconstruye por generación atómica o reconcilia incrementalmente el índice auxiliar de SKUs y variaciones ML.';

    public function handle(MeliListingSkuIndexService $indexService): int
    {
        if (! $indexService->isTableAvailable()) {
            $this->error('Las tablas meli_listing_sku_index / meli_listing_sku_index_state no existen todavía. Ejecuta la migración correspondiente antes de sincronizar.');

            return self::FAILURE;
        }

        $chunk = max(25, min(1000, (int) $this->option('chunk')));
        $incremental = (bool) $this->option('incremental');
        $since = $this->option('since');
        $sinceStr = is_string($since) && trim($since) !== '' ? trim($since) : null;
        $forceTakeover = (bool) $this->option('force-takeover');

        $startedAt = microtime(true);

        try {
            if ($incremental) {
                $stats = $indexService->reconcileIncremental($chunk, $sinceStr);
                $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
                $peakMb = round(memory_get_peak_usage(true) / 1048576, 2);

                $this->info("Reconciliación incremental completada sobre la generación activa #{$stats['active_generation']}.");
                $this->line(sprintf(
                    'Corte: %s | Publicaciones sincronizadas: %d | Price Manager sincronizados: %d | Eliminados purgados: %d | Filas activas: %d | Errores JSON: %d | Tiempo: %.1f ms | Memoria pico: %.2f MB',
                    $stats['cutoff'] ?? 'completo',
                    $stats['publications_synced'],
                    $stats['price_manager_synced'],
                    $stats['pruned_deleted_rows'],
                    $stats['active_rows_count'],
                    $stats['json_errors'],
                    $elapsedMs,
                    $peakMb,
                ));

                return self::SUCCESS;
            }

            $stats = $indexService->rebuildIndex($chunk, null, $forceTakeover);
            $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
            $peakMb = round(memory_get_peak_usage(true) / 1048576, 2);

            $this->info("Índice meli_listing_sku_index publicado atómicamente en la generación #{$stats['generation']}.");
            $this->line(sprintf(
                'Generación previa: %d | Publicaciones: %d | Price Manager: %d | Filas activas: %d | Catch-up: %d pub / %d pm | Purgadas borradas: %d | Obsoletas limpiadas: %d | Errores JSON: %d | Tiempo: %.1f ms | Memoria pico: %.2f MB',
                $stats['previous_generation'],
                $stats['publications_scanned'],
                $stats['price_manager_scanned'],
                $stats['active_rows_count'],
                $stats['catchup_publications'],
                $stats['catchup_price_manager'],
                $stats['pruned_deleted_rows'],
                $stats['cleaned_obsolete_rows'],
                $stats['json_errors'],
                $elapsedMs,
                $peakMb,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $activeGen = $indexService->getActiveReadyGeneration();
            $this->error("Error al sincronizar el índice de fotografías ML: {$e->getMessage()}");
            if ($activeGen !== null) {
                $this->warn("La generación válida anterior (#{$activeGen}) fue preservada intacta sin pérdida de fotografías.");
            } else {
                $this->warn('No existe todavía una generación completa publicada; el resolver continuará utilizando el fallback acotado.');
            }

            return self::FAILURE;
        }
    }
}
