<?php

namespace App\Jobs;

use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Models\User;
use App\Services\SyscomMeliPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueueCategorizedSyscomMarketmaxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $userId,
        public ?int $limit = null
    ) {
        $this->onQueue('meli');
    }

    public function handle(
        SyscomMeliPublishService $publishService
    ): void {
        $user = User::query()->find(
            $this->userId
        );

        if (! $user || ! $user->access_token) {
            Log::warning(
                'SYSCOM Marketmax bulk: usuario inválido o sin token',
                [
                    'user_id' => $this->userId,
                ]
            );

            return;
        }

        $marketmaxId = (int) (
            $publishService->resolveOfficialStoreId(
                'marketmax'
            )
            ?? 0
        );

        if ($marketmaxId !== 281112) {
            throw new \RuntimeException(
                'Marketmax official_store_id inválido: '
                .$marketmaxId
            );
        }

        /*
         * Productos con mapping global aprobado.
         */
        $mappedCategoryIds = DB::table(
            'syscom_meli_category_maps'
        )
            ->where('approved', true)
            ->pluck('syscom_category_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        /*
         * Productos con override aprobado.
         */
        $overrideProductIds = DB::table(
            'syscom_meli_product_category_overrides'
        )
            ->where('approved', true)
            ->pluck('syscom_product_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (
            $mappedCategoryIds === []
            && $overrideProductIds === []
        ) {
            Log::warning(
                'SYSCOM Marketmax bulk: no existen categorías aprobadas'
            );

            return;
        }

        /*
         * No reencolar:
         * - publicados
         * - con MLM
         * - jobs ya esperando
         * - jobs procesándose
         * - errores que requieren revisión manual
         */
        $blockedProductIds = SyscomMeliQueue::query()
            ->where(
                'user_id',
                $this->userId
            )
            ->where(function ($w) {
                $w->where(function ($q) {
                    $q
                        ->whereNotNull('mlm')
                        ->where('mlm', '!=', '');
                })
                    ->orWhereIn(
                        'status',
                        [
                            'published',
                            'queued_publish',
                            'publishing',
                            'error',
                        ]
                    );
            })
            ->pluck('syscom_product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $query = SyscomProduct::query()
            ->where(
                'stock_hermosillo',
                '>',
                0
            )
            ->where(function ($w) use (
                $mappedCategoryIds,
                $overrideProductIds
            ) {
                $hasCondition = false;

                if ($mappedCategoryIds !== []) {
                    $w->whereIn(
                        'syscom_primary_category_id',
                        $mappedCategoryIds
                    );

                    $hasCondition = true;
                }

                if ($overrideProductIds !== []) {
                    if ($hasCondition) {
                        $w->orWhereIn(
                            'id',
                            $overrideProductIds
                        );
                    } else {
                        $w->whereIn(
                            'id',
                            $overrideProductIds
                        );
                    }
                }
            })
            ->orderBy('id');

        if ($blockedProductIds !== []) {
            $query->whereNotIn(
                'id',
                $blockedProductIds
            );
        }

        if (
            $this->limit !== null
            && $this->limit > 0
        ) {
            $query->limit(
                min(
                    2500,
                    $this->limit
                )
            );
        }

        $products = $query->get([
            'id',
            'syscom_producto_id',
        ]);

        $queued = 0;
        $failed = 0;

        foreach ($products as $product) {
            $queue = SyscomMeliQueue::query()
                ->firstOrCreate(
                    [
                        'user_id' =>
                            $this->userId,

                        'syscom_producto_id' =>
                            $product->syscom_producto_id,
                    ],
                    [
                        'syscom_product_id' =>
                            $product->id,

                        'status' =>
                            'pending_price',
                    ]
                );

            if (
                trim(
                    (string) (
                        $queue->mlm
                        ?? ''
                    )
                ) !== ''
            ) {
                continue;
            }

            if (
                in_array(
                    (string) $queue->status,
                    [
                        'published',
                        'queued_publish',
                        'publishing',
                        'error',
                    ],
                    true
                )
            ) {
                continue;
            }

            $queue->update([
                'syscom_product_id' =>
                    $product->id,

                'status' =>
                    'queued_publish',

                'publish_error' =>
                    null,
            ]);

            try {
                PublishSyscomMeliProductJob::dispatch(
                    $this->userId,
                    (int) $product->id
                )->onQueue('meli');

                $queued++;
            } catch (\Throwable $e) {
                $failed++;

                $queue->update([
                    'status' =>
                        'error',

                    'publish_error' =>
                        'No se pudo encolar publicación: '
                        .$e->getMessage(),
                ]);
            }
        }

        Log::info(
            'SYSCOM Marketmax bulk: coordinador terminado',
            [
                'user_id' =>
                    $this->userId,

                'marketmax_id' =>
                    $marketmaxId,

                'limit' =>
                    $this->limit,

                'candidatos' =>
                    $products->count(),

                'encolados' =>
                    $queued,

                'errores_encolando' =>
                    $failed,
            ]
        );
    }
}
