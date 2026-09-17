<?php

namespace App\Jobs;

use App\Models\MeliPublication;
use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Models\User;
use App\Services\SyscomApiService;
use App\Services\SyscomMeliCategoryResolverService;
use App\Services\SyscomMeliPublishService;
use App\Support\SyscomHermosilloStock;
use App\Support\SyscomPrecioExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishSyscomMeliProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $userId,
        public int $productId
    ) {
        $this->onQueue('meli');
    }

    public function handle(
        SyscomMeliPublishService $publishService,
        SyscomMeliCategoryResolverService $resolver,
        SyscomApiService $syscom
    ): void {
        $queue = null;

        try {
            $user = User::query()->find(
                $this->userId
            );

            $product = SyscomProduct::query()->find(
                $this->productId
            );

            if (! $user) {
                Log::warning(
                    'SYSCOM Marketmax publish: usuario no encontrado',
                    [
                        'user_id' =>
                            $this->userId,

                        'product_id' =>
                            $this->productId,
                    ]
                );

                return;
            }

            if (! $product) {
                Log::warning(
                    'SYSCOM Marketmax publish: producto no encontrado',
                    [
                        'user_id' =>
                            $this->userId,

                        'product_id' =>
                            $this->productId,
                    ]
                );

                return;
            }

            $queue = SyscomMeliQueue::query()
                ->firstOrCreate(
                    [
                        'user_id' =>
                            $user->id,

                        'syscom_producto_id' =>
                            $product->syscom_producto_id,
                    ],
                    [
                        'syscom_product_id' =>
                            $product->id,

                        'status' =>
                            'queued_publish',
                    ]
                );

            /*
             * Si otro proceso ya lo publicó, no duplicar.
             */
            if (
                trim(
                    (string) (
                        $queue->mlm
                        ?? ''
                    )
                ) !== ''
            ) {
                $queue->update([
                    'status' =>
                        'published',

                    'publish_error' =>
                        null,
                ]);

                return;
            }

            $queue->update([
                'syscom_product_id' =>
                    $product->id,

                'status' =>
                    'publishing',

                'publish_error' =>
                    null,
            ]);

            if (! $user->access_token) {
                throw new \RuntimeException(
                    'Usuario Mercado Libre sin access_token.'
                );
            }

            /*
             * Seguridad: solo MARKETMAX.
             */
            $marketmaxId = (int) (
                $publishService->resolveOfficialStoreId(
                    'marketmax'
                )
                ?? 0
            );

            if ($marketmaxId !== 281112) {
                throw new \RuntimeException(
                    'Marketmax official_store_id esperado 281112, obtenido '
                    .$marketmaxId
                );
            }

            /*
             * Protección local por Seller SKU.
             */
            $sellerSku =
                $publishService->makeSku(
                    (int) $product->syscom_producto_id
                );

            $existingPublication =
                MeliPublication::query()
                    ->where(
                        'user_id',
                        $user->id
                    )
                    ->where(
                        'sku',
                        $sellerSku
                    )
                    ->whereNotNull('mlm')
                    ->where('mlm', '!=', '')
                    ->orderByDesc('id')
                    ->first();

            if ($existingPublication) {
                $queue->update([
                    'mlm' =>
                        $existingPublication->mlm,

                    'status' =>
                        'published',

                    'publish_error' =>
                        null,
                ]);

                Log::info(
                    'SYSCOM Marketmax publish: publicación ya existente vinculada',
                    [
                        'product_id' =>
                            $product->id,

                        'syscom_producto_id' =>
                            $product->syscom_producto_id,

                        'mlm' =>
                            $existingPublication->mlm,
                    ]
                );

                return;
            }

            /*
             * Autoridad de categoría:
             * override > mapping global.
             */
            $override = DB::table(
                'syscom_meli_product_category_overrides'
            )
                ->where(
                    'syscom_product_id',
                    $product->id
                )
                ->where(
                    'approved',
                    true
                )
                ->first();

            $map = null;

            if (! $override) {
                $map = DB::table(
                    'syscom_meli_category_maps'
                )
                    ->where(
                        'syscom_category_id',
                        $product->syscom_primary_category_id
                    )
                    ->where(
                        'approved',
                        true
                    )
                    ->first();
            }

            if (! $override && ! $map) {
                throw new \RuntimeException(
                    'El producto ya no tiene mapping ni override aprobado.'
                );
            }

            $expectedCategory = strtoupper(
                trim(
                    (string) (
                        $override
                            ? $override->meli_category_id
                            : $map->meli_category_id
                    )
                )
            );

            $expectedSource = $override
                ? 'product_category_override'
                : 'syscom_category_map';

            if (
                ! preg_match(
                    '/^MLM\d+$/',
                    $expectedCategory
                )
            ) {
                throw new \RuntimeException(
                    'Categoría aprobada inválida: '
                    .$expectedCategory
                );
            }

            /*
             * Confirmar que el resolver realmente entrega
             * la autoridad aprobada y no una heurística.
             */
            $resolved = $resolver->resolve(
                $user,
                $product
            );

            $resolvedCategory = strtoupper(
                trim(
                    (string) (
                        $resolved['category_id']
                        ?? ''
                    )
                )
            );

            $resolvedSource = (string) (
                $resolved['source']
                ?? ''
            );

            if (
                $resolvedCategory !== $expectedCategory
                || $resolvedSource !== $expectedSource
            ) {
                throw new \RuntimeException(
                    'Resolver no coincide con categoría aprobada. '
                    .'Esperado '
                    .$expectedCategory
                    .' / '
                    .$expectedSource
                    .'; obtenido '
                    .$resolvedCategory
                    .' / '
                    .$resolvedSource
                );
            }

            /*
             * Stock fresco de Hermosillo.
             */
            $token =
                $syscom->getAccessToken();

            $branchName = (string) config(
                'syscom.sucursal_nombre',
                'hermosillo'
            );

            $branchCode =
                $queue->branch_code
                ?: $syscom->resolveBranchCodeByName(
                    $token,
                    $branchName
                );

            if (! $branchCode) {
                throw new \RuntimeException(
                    'No se pudo resolver sucursal SYSCOM Hermosillo.'
                );
            }

            try {
                $detail = $syscom->getProduct(
                    $token,
                    $product->syscom_producto_id,
                    $branchCode
                );
            } catch (Throwable $e) {
                /*
                 * En publicación masiva somos conservadores:
                 * product_not_available NO usa datos viejos.
                 */
                throw new \RuntimeException(
                    'Error leyendo producto fresco en SYSCOM: '
                    .$e->getMessage()
                );
            }

            $existencia = is_array(
                $detail['existencia']
                ?? null
            )
                ? $detail['existencia']
                : [];

            $branchScoped = (bool) (
                $detail[
                    '__branch_scoped_existencia'
                ]
                ?? false
            );

            $stock =
                SyscomHermosilloStock::fromProductDetail(
                    $existencia,
                    $branchCode,
                    $branchName,
                    (int) (
                        $detail[
                            'total_existencia'
                        ]
                        ?? 0
                    ),
                    $branchScoped
                );

            $product->stock_hermosillo =
                $stock;

            if (is_array($detail)) {
                $product->descripcion =
                    (string) (
                        $product->descripcion
                        ?: (
                            $detail['descripcion']
                            ?? ''
                        )
                    );

                $rawList = is_array(
                    $product->raw_list
                )
                    ? $product->raw_list
                    : [];

                $prices =
                    SyscomPrecioExtractor::fromProductLike(
                        $rawList,
                        $detail
                    );

                foreach (
                    [
                        'precio_lista',
                        'precio_especial',
                        'precio_descuento',
                    ]
                    as $priceKey
                ) {
                    if (
                        (float) (
                            $prices[$priceKey]
                            ?? 0
                        ) > 0
                    ) {
                        $product->{$priceKey} =
                            $prices[$priceKey];
                    }
                }

                $product->raw_detail =
                    $detail;
            }

            $product->save();

            /*
             * Sin stock no es error de categoría/publicación.
             * Sale de la cola masiva y queda pendiente.
             */
            if ($stock <= 0) {
                $queue->update([
                    'branch_code' =>
                        $branchCode,

                    'status' =>
                        'pending_price',

                    'publish_error' =>
                        null,
                ]);

                Log::info(
                    'SYSCOM Marketmax publish omitido por stock 0',
                    [
                        'product_id' =>
                            $product->id,

                        'syscom_producto_id' =>
                            $product->syscom_producto_id,
                    ]
                );

                return;
            }

            /*
             * categoryId = null:
             * obligamos al servicio a usar su resolver,
             * el cual acabamos de verificar contra mapping/override.
             *
             * officialStoreMode = marketmax explícito.
             */
            $result =
                $publishService->publish(
                    $user,
                    $product,
                    $branchCode,
                    null,
                    'marketmax',
                    'llanta',
                    ''
                );

            $mlm = trim(
                (string) (
                    $result['mlm']
                    ?? ''
                )
            );

            if ($mlm === '') {
                throw new \RuntimeException(
                    'Mercado Libre no devolvió MLM después de publicar.'
                );
            }

            $queue->update([
                'syscom_product_id' =>
                    $product->id,

                'branch_code' =>
                    $branchCode,

                'status' =>
                    'published',

                'mlm' =>
                    $mlm,

                'price_scope' =>
                    'llanta',

                'publish_error' =>
                    null,

                'last_price_synced_at' =>
                    now(),

                'last_stock_synced_at' =>
                    now(),
            ]);

            Log::info(
                'SYSCOM Marketmax publish OK',
                [
                    'product_id' =>
                        $product->id,

                    'syscom_producto_id' =>
                        $product->syscom_producto_id,

                    'modelo' =>
                        $product->modelo,

                    'category_id' =>
                        $expectedCategory,

                    'category_source' =>
                        $expectedSource,

                    'marketmax_id' =>
                        $marketmaxId,

                    'stock' =>
                        $stock,

                    'mlm' =>
                        $mlm,
                ]
            );
        } catch (Throwable $e) {
            if ($queue) {
                $queue->update([
                    'status' =>
                        'error',

                    'publish_error' =>
                        $e->getMessage(),
                ]);
            }

            Log::warning(
                'SYSCOM Marketmax publish ERROR',
                [
                    'user_id' =>
                        $this->userId,

                    'product_id' =>
                        $this->productId,

                    'error' =>
                        $e->getMessage(),
                ]
            );
        }
    }

    public function failed(Throwable $e): void
    {
        $queue = SyscomMeliQueue::query()
            ->where(
                'user_id',
                $this->userId
            )
            ->where(
                'syscom_product_id',
                $this->productId
            )
            ->first();

        if (
            $queue
            && trim(
                (string) (
                    $queue->mlm
                    ?? ''
                )
            ) === ''
        ) {
            $queue->update([
                'status' =>
                    'error',

                'publish_error' =>
                    'Job falló: '
                    .$e->getMessage(),
            ]);
        }
    }
}
