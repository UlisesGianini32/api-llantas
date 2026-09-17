<?php

namespace App\Console\Commands;

use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Services\MeliSyncService;
use App\Services\SyscomApiService;
use App\Support\SyscomHermosilloStock;
use App\Support\SyscomPrecioExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyscomSyncStockFastCommand extends Command
{
    protected $signature = 'syscom:sync-stock-fast
                            {--user_id= : Restringir a publicaciones SYSCOM de un usuario}
                            {--all : Procesar todo syscom_products, no solo productos publicados}
                            {--chunk=300 : Productos por petición; máximo permitido por SYSCOM: 300}
                            {--no-sync-ml : Solo actualizar la base de datos}
                            {--progress : Mostrar el resultado de cada producto}';

    protected $description = 'Actualiza stock y precios SYSCOM por lotes de hasta 300 IDs y alinea Mercado Libre si hubo cambios';

    public function handle(SyscomApiService $api, MeliSyncService $meliSync): int
    {
        try {
            $token = $api->getAccessToken();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $branchName = trim((string) config('syscom.sucursal_nombre', 'hermosillo'));
        $branchCode = $api->resolveBranchCodeByName($token, $branchName);

        if (! $branchCode) {
            $this->error("No se encontró la sucursal SYSCOM configurada: {$branchName}");

            return self::FAILURE;
        }

        $products = $this->resolveProducts();

        if ($products->isEmpty()) {
            $this->line(
                $this->option('all')
                    ? 'No hay productos en syscom_products.'
                    : 'No hay publicaciones SYSCOM con MLM para actualizar.'
            );

            return self::SUCCESS;
        }

        $chunkSize = max(1, min(300, (int) $this->option('chunk')));
        $showProgress = (bool) $this->option('progress');

        $processed = 0;
        $changed = 0;
        $stockChanged = 0;
        $missing = 0;
        $failedBatches = 0;

        $this->info(sprintf(
            'Sincronización rápida SYSCOM: %d producto(s), lotes de %d, sucursal %s (%s).',
            $products->count(),
            $chunkSize,
            $branchName,
            $branchCode
        ));

        foreach ($products->chunk($chunkSize) as $chunkIndex => $chunk) {
            $ids = $chunk
                ->pluck('syscom_producto_id')
                ->map(static fn ($id) => (int) $id)
                ->filter(static fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                continue;
            }

            try {
                $items = $api->getProductsByIds(
                    $token,
                    $ids,
                    true,
                    'usd'
                );
            } catch (\Throwable $e) {
                $failedBatches++;

                Log::warning('syscom fast stock batch failed', [
                    'batch' => $chunkIndex + 1,
                    'count' => count($ids),
                    'first_id' => $ids[0] ?? null,
                    'last_id' => $ids[count($ids) - 1] ?? null,
                    'error' => $e->getMessage(),
                ]);

                $this->warn(sprintf(
                    'Lote %d falló (%d productos): %s',
                    $chunkIndex + 1,
                    count($ids),
                    $e->getMessage()
                ));

                continue;
            }

            $byId = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemId = (int) (
                    $item['producto_id']
                    ?? $item['id']
                    ?? 0
                );

                if ($itemId > 0) {
                    $byId[$itemId] = $item;
                }
            }

            foreach ($chunk as $product) {
                if (! $product instanceof SyscomProduct) {
                    continue;
                }

                $productId = (int) $product->syscom_producto_id;
                $item = $byId[$productId] ?? null;

                if (! is_array($item)) {
                    $missing++;

                    Log::notice('syscom fast stock product missing from batch response', [
                        'syscom_producto_id' => $productId,
                    ]);

                    if ($showProgress) {
                        $this->line("  id={$productId} · sin respuesta; se conserva el stock anterior");
                    }

                    continue;
                }

                $processed++;

                $existencia = is_array($item['existencia'] ?? null)
                    ? $item['existencia']
                    : [];

                $newBranchStock = max(
                    0,
                    SyscomHermosilloStock::forBranch(
                        $existencia,
                        (string) $branchCode,
                        $branchName
                    )
                );

                $newTotalStock = array_key_exists('total_existencia', $item)
                    ? max(0, (int) $item['total_existencia'])
                    : (int) ($product->total_existencia ?? 0);

                $oldBranchStock = (int) ($product->stock_hermosillo ?? 0);

                $product->stock_hermosillo = $newBranchStock;
                $product->total_existencia = $newTotalStock;
                $product->raw_detail = $item;

                $prices = SyscomPrecioExtractor::fromProductLike(
                    is_array($product->raw_list) ? $product->raw_list : [],
                    $item
                );

                foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $priceKey) {
                    if ((float) ($prices[$priceKey] ?? 0) > 0) {
                        $product->{$priceKey} = $prices[$priceKey];
                    }
                }

                $meaningfulChange = $product->isDirty([
                    'stock_hermosillo',
                    'total_existencia',
                    'precio_lista',
                    'precio_especial',
                    'precio_descuento',
                ]);

                $branchStockChanged = $oldBranchStock !== $newBranchStock;

                $product->last_synced_at = now();
                $product->save();

                if ($meaningfulChange) {
                    $changed++;
                }

                if ($branchStockChanged) {
                    $stockChanged++;
                }

                if ($showProgress) {
                    $this->line(sprintf(
                        '  id=%d  %-24s  Hermosillo %d → %d  total=%d  %s',
                        $productId,
                        mb_strimwidth((string) ($product->modelo ?: '—'), 0, 24, '…'),
                        $oldBranchStock,
                        $newBranchStock,
                        $newTotalStock,
                        $meaningfulChange ? '✓ actualizado' : '· sin cambios'
                    ));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'SYSCOM terminado: procesados=%d, modificados=%d, stock_cambió=%d, sin_respuesta=%d, lotes_fallidos=%d.',
            $processed,
            $changed,
            $stockChanged,
            $missing,
            $failedBatches
        ));

        if (
            $changed > 0
            && ! (bool) $this->option('no-sync-ml')
            && ! (bool) $this->option('all')
        ) {
            try {
                $meliSync->syncSyscomPublicationsOnly();
                $this->info('Mercado Libre: publicaciones SYSCOM alineadas.');
            } catch (\Throwable $e) {
                Log::warning('syscom fast stock: fallo sync ML SYSCOM', [
                    'error' => $e->getMessage(),
                ]);

                $this->warn('La base SYSCOM sí se actualizó, pero Mercado Libre falló: '.$e->getMessage());
            }
        } elseif ($changed === 0) {
            $this->line('Mercado Libre no se ejecutó porque no hubo cambios.');
        } elseif ((bool) $this->option('all')) {
            $this->line('Con --all no se sincroniza Mercado Libre automáticamente.');
        }

        return $failedBatches > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return Collection<int, SyscomProduct>
     */
    private function resolveProducts(): Collection
    {
        if ((bool) $this->option('all')) {
            return SyscomProduct::query()
                ->whereNotNull('syscom_producto_id')
                ->where('syscom_producto_id', '>', 0)
                ->orderBy('id')
                ->get();
        }

        $query = SyscomMeliQueue::query()
            ->whereNotNull('mlm')
            ->with('product');

        $userId = (int) $this->option('user_id');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }

        return $query
            ->orderBy('id')
            ->get()
            ->map(static fn (SyscomMeliQueue $row) => $row->product)
            ->filter(static fn ($product) => $product instanceof SyscomProduct)
            ->filter(static fn (SyscomProduct $product) => (int) $product->syscom_producto_id > 0)
            ->unique(static fn (SyscomProduct $product) => (int) $product->id)
            ->values();
    }
}
