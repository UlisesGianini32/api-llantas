<?php

namespace App\Console\Commands;

use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Services\SyscomApiService;
use App\Services\SyscomProductPricingService;
use App\Support\SyscomPrecioExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyscomBackfillPricesCommand extends Command
{
    protected $signature = 'syscom:backfill-prices
                            {--user_id= : Solo productos en cola de este usuario (vacío = todo el catálogo)}
                            {--pending : Solo filas pending_price sin MLM (no usar tras sync completo)}
                            {--published : Solo cola con MLM (ya publicados en Mercado Libre)}
                            {--usd-only : Criterio antiguo: lista Y descuento en cero en BD (no usa costo MXN)}
                            {--limit=200 : Máximo de productos a consultar en detalle}
                            {--batches=1 : Repetir el lote hasta agotar sin costo o alcanzar este número}
                            {--sleep-ms=350 : Pausa entre llamadas a detalle SYSCOM}';

    protected $description = 'Consulta detalle SYSCOM y completa precios USD cuando el panel muestra — / revisar costo';

    public function handle(SyscomApiService $api, SyscomProductPricingService $pricing): int
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
            $this->error("No se encontró sucursal: {$branchName}");

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $batches = max(1, (int) $this->option('batches'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $userId = $this->option('user_id') !== null && $this->option('user_id') !== ''
            ? (int) $this->option('user_id')
            : null;

        $totalOk = 0;
        $totalFailed = 0;

        for ($batch = 1; $batch <= $batches; $batch++) {
            $queueProductIds = null;
            if ($userId !== null) {
                $ids = SyscomMeliQueue::query()
                    ->where('user_id', $userId)
                    ->when($this->option('pending'), function ($q) {
                        $q->where('status', 'pending_price')
                            ->where(function ($w) {
                                $w->whereNull('mlm')->orWhere('mlm', '');
                            });
                    })
                    ->when($this->option('published'), function ($q) {
                        $q->whereNotNull('mlm')->where('mlm', '!=', '');
                    })
                    ->pluck('syscom_product_id');
                $queueProductIds = $ids->isEmpty() ? [] : $ids->all();
            }

            $products = $this->option('usd-only')
                ? $this->collectProductsUsdOnlyEmpty($limit, $queueProductIds)
                : $this->collectProductsWithZeroCostoMx($pricing, $limit, $queueProductIds);

            if ($products->isEmpty()) {
                if ($batch === 1) {
                    $scope = $userId !== null ? "cola user_id={$userId}" : 'catálogo completo';
                    $this->info("No hay productos con costo MXN en 0 ({$scope}).");
                    $this->line('Si el panel sigue en “revisar costo”, probá sin --user_id o inspeccioná un SKU:');
                    $this->line('  php artisan syscom:inspect-precios --id=ID_SYSCOM --live');
                } else {
                    $this->info("Lote {$batch}: no quedan productos sin costo.");
                }
                break;
            }

            $this->info("Lote {$batch}/{$batches}: completando precios desde detalle SYSCOM (".$products->count().' producto(s)).');

            foreach ($products as $p) {
                try {
                    $detail = $api->getProduct($token, (int) $p->syscom_producto_id, $branchCode);
                } catch (\Throwable $e) {
                    $totalFailed++;
                    Log::warning('syscom:backfill-prices getProduct', [
                        'syscom_producto_id' => $p->syscom_producto_id,
                        'err' => $e->getMessage(),
                    ]);
                    $this->warn("  ✗ {$p->syscom_producto_id}: ".$e->getMessage());
                    continue;
                }

                if (! is_array($detail)) {
                    $totalFailed++;

                    continue;
                }

                $item = is_array($p->raw_list) ? $p->raw_list : [];
                $prices = SyscomPrecioExtractor::fromProductLike($item, $detail);
                $changed = false;

                foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $pk) {
                    if ((float) ($prices[$pk] ?? 0) > 0) {
                        $p->{$pk} = $prices[$pk];
                        $changed = true;
                    }
                }

                if ($changed) {
                    $p->raw_detail = $detail;
                    $p->last_synced_at = now();
                    $p->save();
                    $totalOk++;
                    $this->line(sprintf(
                        '  ✓ %d — lista %.2f / desc %.2f USD',
                        $p->syscom_producto_id,
                        (float) $p->precio_lista,
                        (float) $p->precio_descuento
                    ));
                } else {
                    $totalFailed++;
                    $this->warn("  — {$p->syscom_producto_id}: detalle sin precios legibles");
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $this->info("Precios completados: {$totalOk}. Sin precio en API / error: {$totalFailed}.");

        return self::SUCCESS;
    }

    /**
     * Mismo criterio que el panel (costo MXN / precio fórmula en 0).
     *
     * @param  array<int, int>|null  $queueProductIds  syscom_products.id en cola; null = sin filtro
     */
    protected function collectProductsWithZeroCostoMx(
        SyscomProductPricingService $pricing,
        int $limit,
        ?array $queueProductIds
    ): \Illuminate\Support\Collection {
        $found = collect();
        $scanBatch = max($limit * 8, 400);

        $query = SyscomProduct::query()->orderByDesc('id');
        if ($queueProductIds !== null) {
            if ($queueProductIds === []) {
                return $found;
            }
            $query->whereIn('id', $queueProductIds);
        }

        $query->chunkById($scanBatch, function ($chunk) use ($pricing, $limit, &$found) {
            foreach ($chunk as $p) {
                try {
                    if ($pricing->costoMxParaFormula($p) <= 0) {
                        $found->push($p);
                    }
                } catch (\Throwable) {
                    $found->push($p);
                }
                if ($found->count() >= $limit) {
                    return false;
                }
            }

            return true;
        });

        return $found->take($limit)->values();
    }

    /**
     * Criterio anterior: lista y descuento ambos vacíos en columnas BD.
     *
     * @param  array<int, int>|null  $queueProductIds
     */
    protected function collectProductsUsdOnlyEmpty(int $limit, ?array $queueProductIds): \Illuminate\Support\Collection
    {
        $query = SyscomProduct::query()
            ->where(function ($w) {
                $w->where('precio_descuento', '<=', 0)
                    ->orWhereNull('precio_descuento');
            })
            ->where(function ($w) {
                $w->where('precio_lista', '<=', 0)
                    ->orWhereNull('precio_lista');
            })
            ->orderByDesc('id');

        if ($queueProductIds !== null) {
            $query->whereIn('id', $queueProductIds === [] ? [-1] : $queueProductIds);
        }

        return $query->limit($limit)->get();
    }
}
