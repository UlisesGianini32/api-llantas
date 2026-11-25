<?php

namespace App\Console\Commands;

use App\Services\Purchasing\MeliListingSkuIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds or incrementally reconciles the generation-versioned auxiliary SKU/variation
 * index (`meli_listing_sku_index`) without disrupting active reads.
 */
class SyncMeliPhotoSkuIndexCommand extends Command
{
    private const ADVISORY_LOCK_PREFIX = 'sbs_meli_photo_idx_';

    protected $signature = 'purchasing:sync-meli-photo-sku-index
        {--chunk=200 : Cantidad de registros por lote en memoria}
        {--incremental : Reconciliar únicamente filas modificadas/eliminadas sobre la generación activa}
        {--scheduled : Ejecucion periodica segura; omite estados no preparados}
        {--since= : Fecha/hora opcional de corte para --incremental (ej. "2026-11-20 00:00:00")}
        {--force-takeover : Forzar la toma de una reconstrucción marcada como en curso si el proceso anterior fue interrumpido}';

    protected $description = 'Reconstruye por generación atómica o reconcilia incrementalmente el índice auxiliar de SKUs y variaciones ML.';

    public function handle(MeliListingSkuIndexService $indexService): int
    {
        $scheduled = (bool) $this->option('scheduled');

        if ($scheduled && ! (bool) $this->option('incremental')) {
            $this->error('--scheduled requiere --incremental.');

            return self::FAILURE;
        }

        if ($scheduled && ! $indexService->isTableAvailable()) {
            $this->line('Reconciliacion omitida: tablas no inicializadas.');

            return self::SUCCESS;
        }

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

        // Advisory lock shared by CLI rebuilds and incremental commands.
        // It is scoped to the database and released when the connection closes.
        $driver = DB::connection()->getDriverName();
        $supportsLock = in_array($driver, ['mysql', 'mariadb'], true);

        if ($scheduled && ! $supportsLock) {
            $this->error('La tarea programada requiere MariaDB/MySQL.');

            return self::FAILURE;
        }

        $lockName = self::ADVISORY_LOCK_PREFIX
            .substr(sha1((string) DB::connection()->getDatabaseName()), 0, 32);
        $lockHeld = false;

        try {
            if ($supportsLock) {
                $claim = DB::selectOne(
                    'SELECT GET_LOCK(?, 0) AS acquired',
                    [$lockName]
                );

                if ((int) ($claim->acquired ?? 0) !== 1) {
                    if ($scheduled) {
                        $this->line('Reconciliacion omitida: sincronizacion en curso.');

                        return self::SUCCESS;
                    }

                    $this->error('Ya existe otra sincronizacion del indice.');

                    return self::FAILURE;
                }

                $lockHeld = true;
            }

            if ($scheduled) {
                $state = DB::table(MeliListingSkuIndexService::STATE_TABLE)
                    ->where('id', MeliListingSkuIndexService::STATE_ROW_ID)
                    ->first(['active_generation', 'building_generation']);

                if (
                    $state === null
                    || (int) $state->active_generation <= 0
                    || $state->building_generation !== null
                ) {
                    $this->line('Reconciliacion omitida: indice no listo o rebuild pendiente.');

                    return self::SUCCESS;
                }
            }

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
        } finally {
            if ($lockHeld) {
                try {
                    $released = DB::selectOne(
                        'SELECT RELEASE_LOCK(?) AS released',
                        [$lockName]
                    );

                    if ((int) ($released->released ?? 0) !== 1) {
                        Log::warning(
                            '[Purchasing][MeliPhotos] Advisory lock not released',
                            ['lock' => $lockName]
                        );
                    }
                } catch (Throwable $unlockError) {
                    Log::warning(
                        '[Purchasing][MeliPhotos] Advisory lock release failed',
                        [
                            'lock' => $lockName,
                            'error' => $unlockError->getMessage(),
                        ]
                    );
                }
            }
        }
    }
}
