<?php

namespace App\Console\Commands;

use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Services\MeliSyncService;
use App\Services\SyscomApiService;
use App\Services\SyscomPortalScraper;
use App\Support\SyscomPrecioExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyscomRefreshHermosilloStockCommand extends Command
{
    protected $signature = 'syscom:refresh-hermosillo-for-published
                            {--user_id= : Restringir a cola de un usuario}
                            {--id= : Refrescar SOLO ese syscom_producto_id (rapido, sin sync ML masivo)}
                            {--limit= : Procesar como mucho N productos (para tandas, evita timeouts)}
                            {--no-sync-ml : No pausar/actualizar publicaciones en Mercado Libre}
                            {--progress : Imprimir una linea por producto procesado (cuenta y stock)}';

    protected $description = 'Lee detalle SYSCOM por producto y actualiza existencia, total_existencia y stock_hermosillo en BD (publicaciones con MLM)';

    public function handle(SyscomApiService $api, MeliSyncService $meliSync, SyscomPortalScraper $scraper): int
    {
        try {
            $token = $api->getAccessToken();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $branchName = (string) config('syscom.sucursal_nombre', 'hermosillo');
        $branchCode = $api->resolveBranchCodeByName($token, $branchName);
        if (! $branchCode) {
            $this->error("No se encontró la sucursal: {$branchName}");

            return self::FAILURE;
        }

        $singleId = trim((string) $this->option('id'));
        $limit = (int) $this->option('limit');
        $progress = (bool) $this->option('progress') || $singleId !== '';

        $scraperEnabled = $scraper->isEnabled();
        if ($progress) {
            $this->line('Portal scraper: '.($scraperEnabled ? 'ACTIVO (fuente primaria)' : 'inactivo (uso buscador SYSCOM)'));
        }

        $q = SyscomMeliQueue::query()->whereNotNull('mlm');
        if ($this->option('user_id')) {
            $q->where('user_id', (int) $this->option('user_id'));
        }
        if ($singleId !== '') {
            $q->where('syscom_producto_id', (int) $singleId);
        }
        if ($limit > 0) {
            $q->orderBy('id')->limit($limit);
        }
        $queues = $q->with('product')->get();

        if ($queues->isEmpty()) {
            $this->line($singleId !== ''
                ? "No hay cola con MLM para syscom_producto_id={$singleId}."
                : 'Nada en cola con MLM.');

            return self::SUCCESS;
        }

        $totalQueues = $queues->count();
        $idx = 0;
        $n = 0;
        $scrapeOk = 0;
        $scrapeFail = 0;
        foreach ($queues as $row) {
            $idx++;
            $p = $row->product;
            if (! $p instanceof SyscomProduct) {
                continue;
            }

            $code = (string) ($row->branch_code ?: $branchCode);

            // 1) Fuente primaria: portal SYSCOM (www.syscom.mx). Trae el desglose REAL por
            //    sucursal (api/productos/{id}/existencias). Si está activo y devuelve datos,
            //    usamos ese número exacto.
            $h = null;
            $stockSource = '';
            if ($scraperEnabled) {
                try {
                    $h = $scraper->branchStockNuevo($p->syscom_producto_id, $branchName);
                    if ($h !== null) {
                        $scrapeOk++;
                        $stockSource = 'portal';
                    } else {
                        $scrapeFail++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('syscom refresh stock portal scrape', [
                        'id' => $p->syscom_producto_id,
                        'e' => $e->getMessage(),
                    ]);
                    $scrapeFail++;
                }
            }

            // 2) Fallback: buscador filtrado (sucursal=hermosillo&stock=1). Solo dice
            //    si hay >=1 unidad, no la cantidad real. Conservador.
            if ($h === null) {
                $busqueda = trim((string) ($p->modelo ?: $p->titulo));
                if ($busqueda !== '') {
                    try {
                        $h = $api->getBranchStock($token, $code, $p->syscom_producto_id, $busqueda);
                        $stockSource = 'buscador';
                    } catch (\Throwable $e) {
                        Log::warning('syscom refresh stock branch search', [
                            'id' => $p->syscom_producto_id,
                            'e' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            }
            $h = (int) ($h ?? 0);

            // Detalle solo para refrescar precios y total nacional (no para stock de sucursal).
            try {
                $detail = $api->getProduct($token, $p->syscom_producto_id, null);
            } catch (\Throwable $e) {
                Log::warning('syscom refresh stock getProduct', [
                    'id' => $p->syscom_producto_id,
                    'e' => $e->getMessage(),
                ]);
                $detail = is_array($p->raw_detail) ? $p->raw_detail : [];
            }
            unset($detail['__branch_scoped_existencia']);

            $totalExistencia = array_key_exists('total_existencia', $detail)
                ? (int) $detail['total_existencia']
                : (int) ($p->total_existencia ?? 0);

            $p->total_existencia = $totalExistencia;
            $p->stock_hermosillo = $h;
            if (is_array($detail) && $detail !== []) {
                $p->raw_detail = $detail;
                $prices = SyscomPrecioExtractor::fromProductLike(
                    is_array($p->raw_list) ? $p->raw_list : [],
                    $detail
                );
                foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $pk) {
                    if ((float) ($prices[$pk] ?? 0) > 0) {
                        $p->{$pk} = $prices[$pk];
                    }
                }
            }
            $p->last_synced_at = now();

            $changed = $p->isDirty();
            if ($changed) {
                $p->save();
                $n++;
            }

            if ($progress) {
                $this->line(sprintf(
                    '  %d/%d  id=%d  %s  stock_hermosillo=%d  total=%d  src=%s  %s',
                    $idx,
                    $totalQueues,
                    (int) $p->syscom_producto_id,
                    str_pad((string) ($p->modelo ?: '—'), 24),
                    (int) $p->stock_hermosillo,
                    (int) $p->total_existencia,
                    $stockSource ?: '—',
                    $changed ? '✓ actualizado' : '· sin cambios'
                ));
            }
        }

        $this->info("Stock Hermosillo actualizado en: {$n} producto(s).");
        if ($scraperEnabled) {
            $this->line("Portal scrape: ok={$scrapeOk}  fallback={$scrapeFail}");
        }

        $skipMlSync = (bool) $this->option('no-sync-ml') || $singleId !== '';
        if ($n > 0 && ! $skipMlSync) {
            try {
                $meliSync->syncSyscomPublicationsOnly();
                $this->info('Mercado Libre: stock/pausa SYSCOM alineados.');
            } catch (\Throwable $e) {
                Log::warning('syscom refresh stock: fallo sync ML SYSCOM', ['e' => $e->getMessage()]);
                $this->warn('No se pudo sincronizar ML: '.$e->getMessage());
            }
        } elseif ($n > 0 && $singleId !== '') {
            $this->line('Para empujar este producto a Mercado Libre, usá el botón "Sync ML" del panel o `php artisan meli:sync-stock`.');
        }

        return self::SUCCESS;
    }
}
