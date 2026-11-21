<?php

namespace App\Console\Commands;

use App\Services\Purchasing\MeliListingSkuIndexService;
use Illuminate\Console\Command;

/**
 * Strictly read-only diagnostic command reporting the completeness, generation,
 * rebuild history, and incremental synchronization health of `meli_listing_sku_index`.
 */
class MeliPhotoSkuIndexStatusCommand extends Command
{
    protected $signature = 'purchasing:meli-photo-sku-index-status
        {--json : Emitir el diagnóstico en formato JSON}';

    protected $description = 'Muestra en modo solo lectura el estado de completitud, generación activa y salud de sincronización del índice auxiliar de fotografías ML.';

    public function handle(MeliListingSkuIndexService $indexService): int
    {
        $report = $indexService->getDiagnosticStatus();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Diagnóstico del índice auxiliar de fotografías ML (solo lectura)');
        $this->line(sprintf(
            'Estado: %s | Salud efectiva: %s | Usable por resolver: %s',
            $report['status'],
            $report['effective_health'],
            $report['usable_by_resolver'] ? 'SÍ' : 'NO (usará fallback acotado)',
        ));
        $this->line(sprintf(
            'Generación activa: %d | Generación en construcción: %s%s | Último rebuild: %s',
            $report['active_generation'],
            $report['building_generation'] !== null ? (string) $report['building_generation'] : 'ninguna',
            $report['build_is_stale'] ? ' (STALE / INTERRUMPIDO)' : '',
            $report['last_rebuild_status'] ?? 'ninguno',
        ));
        $this->line(sprintf(
            'Última reconstrucción correcta: %s | Última reconciliación: %s',
            $report['last_completed_at'] ?? 'nunca',
            $report['last_reconciled_at'] ?? 'nunca',
        ));
        $this->line(sprintf(
            'Filas índice activo: %d (registradas: %d) | Filas huérfanas/otras generaciones: %d',
            $report['counts']['live_active_rows'],
            $report['counts']['recorded_active_rows'],
            $report['counts']['orphan_rows'],
        ));
        $this->line(sprintf(
            'Publicaciones examinadas: %d (vivas: %d) | Price Manager examinados: %d (vivos: %d)',
            $report['counts']['publications_scanned'],
            $report['counts']['live_publications_total'],
            $report['counts']['price_manager_scanned'],
            $report['counts']['live_price_manager_total'],
        ));
        $this->line(sprintf(
            'Rebuilds fallidos acumulados: %d | Errores sync incremental: %d | Deriva sospechada: %s',
            $report['failed_rebuilds_count'],
            $report['incremental_sync']['errors_count'],
            $report['incremental_sync']['drift_suspected'] ? 'SÍ' : 'NO',
        ));

        if ($report['incremental_sync']['publications_updated_since_reconcile'] !== null) {
            $this->line(sprintf(
                'Actualizaciones desde última reconciliación -> meli_publications: %d | meli_price_manager_items: %d | Último updated_at en índice: %s',
                $report['incremental_sync']['publications_updated_since_reconcile'],
                $report['incremental_sync']['price_manager_updated_since_reconcile'] ?? 0,
                $report['incremental_sync']['latest_index_updated_at'] ?? 'n/a',
            ));
        }

        if (! empty($report['last_error'])) {
            $this->warn("Último error de rebuild ({$report['last_failed_at']}): {$report['last_error']}");
        }

        if (! empty($report['incremental_sync']['last_error'])) {
            $this->warn("Último error incremental ({$report['incremental_sync']['last_error_at']}): {$report['incremental_sync']['last_error']}");
        }

        return self::SUCCESS;
    }
}
