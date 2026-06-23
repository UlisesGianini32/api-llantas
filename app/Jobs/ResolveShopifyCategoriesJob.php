<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\ShopifyCategoryResolverService;
use App\Support\ApplyMlProductListingFilters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResolveShopifyCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un solo proceso a la vez: evita que varios clicks o retry_after liberen jobs paralelos desde el mismo inicio. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('resolve-shopify-categories'))
                ->releaseAfter(120)
                ->expireAfter(max(3660, $this->timeout + 600)),
        ];
    }

    /** @var array{search:string,official_store_id:string,categories:array,only_empty:bool,force:bool,limit:int} */
    public function __construct(
        public readonly int $triggerUserId,
        public readonly array $filters
    ) {
        $this->onQueue('default');
    }

    /** Debe ser > que reintentos “fantasma” por timeout / retry_after del driver database. */
    public int $timeout = 7200;

    public int $tries = 10;

    public function failed(?\Throwable $e): void
    {
        Log::error('[SHOPIFY] Job fallido', [
            'message' => $e?->getMessage(),
            'file' => $e?->getFile(),
            'line' => $e?->getLine(),
        ]);
    }

    public function handle(ShopifyCategoryResolverService $resolver): void
    {
        Log::info('[SHOPIFY] Job iniciado', [
            'trigger_user_id' => $this->triggerUserId,
            'filtros' => $this->filters,
        ]);

        $stats = self::run($resolver, $this->filters);

        Log::info('[SHOPIFY] Resolución de categorías finalizada', [
            'trigger_user_id' => $this->triggerUserId,
            'filters' => $this->filters,
            'stats' => $stats,
        ]);
    }

    /**
     * @param  array{search:string,official_store_id:string,categories:array,only_empty:bool,force:bool,limit:int}  $filters
     * @return array{processed:int,resolved:int,changed:int,unresolved:int,errors:int}
     */
    public static function run(ShopifyCategoryResolverService $resolver, array $filters): array
    {
        $processed = $resolvedCount = $changedCount = $unresolvedCount = $errorCount = 0;

        $onlyEmpty = (bool) ($filters['only_empty'] ?? false);
        $force = (bool) ($filters['force'] ?? true);
        $limit = max(0, (int) ($filters['limit'] ?? 0));

        $query = Product::query();
        ApplyMlProductListingFilters::to($query, [
            'search' => $filters['search'] ?? '',
            'official_store_id' => $filters['official_store_id'] ?? '',
            'categories' => $filters['categories'] ?? [],
        ]);
        $query->orderBy('id');

        if ($onlyEmpty && ! $force) {
            $query->where(function ($q) {
                $q->whereNull('shopify_category_name')
                    ->orWhere('shopify_category_name', '');
            });
        }

        if ($limit > 0) {
            $ids = (clone $query)->limit($limit)->pluck('id');
            $query = Product::query()->whereIn('id', $ids)->orderBy('id');
        }

        $aproxTotal = (clone $query)->count();
        Log::info('[SHOPIFY] Recorriendo catálogo (progreso por chunks de 100)', [
            'aprox_productos_a_procesar' => $aproxTotal,
        ]);

        $query->chunkById(100, function ($products) use (
            $resolver,
            &$processed,
            &$resolvedCount,
            &$changedCount,
            &$unresolvedCount,
            &$errorCount,
            $aproxTotal
        ) {
            foreach ($products as $product) {
                $processed++;

                $before = [
                    'id' => trim((string) ($product->shopify_category_id ?? '')),
                    'name' => trim((string) ($product->shopify_category_name ?? '')),
                    'source' => trim((string) ($product->shopify_category_source ?? '')),
                ];

                // Antes de red/Shopify: si no hay línea siguiente, está colgado dentro del resolver.
                if ($aproxTotal <= 250) {
                    Log::info('[SHOPIFY] Empiezo producto', [
                        'n' => $processed,
                        'product_id' => $product->id,
                        'ml' => $product->ml,
                        'nombre_preview' => mb_substr((string) ($product->name ?? ''), 0, 100),
                    ]);
                }

                $resolvedFlag = null;
                try {
                    $resolvedFlag = $resolver->resolveAndSave($product, false);

                    if (! $resolvedFlag) {
                        $unresolvedCount++;
                    } else {
                        $resolvedCount++;
                        $product->refresh();

                        $after = [
                            'id' => trim((string) ($product->shopify_category_id ?? '')),
                            'name' => trim((string) ($product->shopify_category_name ?? '')),
                            'source' => trim((string) ($product->shopify_category_source ?? '')),
                        ];

                        if ($before !== $after) {
                            $changedCount++;
                        }
                    }
                } catch (\Throwable) {
                    $errorCount++;
                    $resolvedFlag = false;
                }

                if ($aproxTotal <= 5) {
                    Log::info('[SHOPIFY] Producto procesado', [
                        'product_id' => $product->id,
                        'ml' => $product->ml,
                        'resolved_flag' => $resolvedFlag,
                    ]);
                }

                $logCadaProducto = $aproxTotal <= 200;
                $logCadaN = max(25, (int) ceil($aproxTotal / 40));
                if ($logCadaProducto || $processed === 1 || ($processed % $logCadaN === 0)) {
                    Log::info('[SHOPIFY] Avance por producto', [
                        'n' => $processed,
                        'total_estimado' => $aproxTotal,
                        'product_id' => $product->id,
                        'resolved' => $resolvedCount,
                        'sin_match' => $unresolvedCount,
                        'errors' => $errorCount,
                    ]);
                }
            }

            Log::info('[SHOPIFY] Progreso', [
                'processed' => $processed,
                'resolved' => $resolvedCount,
                'changed' => $changedCount,
                'unresolved' => $unresolvedCount,
                'errors' => $errorCount,
            ]);
        });

        return [
            'processed' => $processed,
            'resolved' => $resolvedCount,
            'changed' => $changedCount,
            'unresolved' => $unresolvedCount,
            'errors' => $errorCount,
        ];
    }
}
