<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SyscomApiService;
use App\Support\SyscomCategoryTree;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncSyscomCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(
        public int $userId
    ) {
        $this->onQueue('default');
    }

    public function handle(SyscomApiService $syscom): void
    {
        $user = User::query()->find($this->userId);
        if (! $user) {
            return;
        }
        if (! $user->access_token) {
            return;
        }

        $branchName = (string) config('syscom.sucursal_nombre', 'hermosillo');

        $baseOptions = [
            '--user_id' => (string) $this->userId,
            '--max-pages' => '0',
            '--with-detail' => true,
            '--no-progress' => true,
        ];
        $options = $baseOptions;
        if (config('syscom.default_sync_sweep', true)) {
            $options['--sweep'] = true;
        }
        Log::info('SYSCOM sync job: inicio catálogo', [
            'user_id' => $this->userId,
            'sweep' => isset($options['--sweep']),
            'sucursal' => $branchName,
        ]);

        $exit = Artisan::call('syscom:sync-products', $options);
        $syncOut = trim(Artisan::output());
        if ($syncOut !== '') {
            Log::info('SYSCOM sync job: salida sync-products', ['output' => $syncOut]);
        }
        if ($exit !== 0) {
            throw new \RuntimeException($syncOut ?: 'syscom:sync-products falló');
        }

        if (config('syscom.marca_sweep_enabled', true)) {
            foreach (config('syscom.marca_sweep_terms', []) as $marca) {
                Log::info('SYSCOM sync job: marca sweep', ['marca' => $marca, 'sucursal' => $branchName]);
                $marcaExit = Artisan::call('syscom:sync-products', array_merge($baseOptions, [
                    '--marca' => $marca,
                ]));
                if ($marcaExit !== 0) {
                    Log::warning('SYSCOM sync job: marca sweep falló', [
                        'marca' => $marca,
                        'output' => trim(Artisan::output()),
                    ]);
                }
            }
        }

        if (config('syscom.categoria_sweep_enabled', true)) {
            $this->runCategorySweep($syscom, $baseOptions, $branchName);
        }

        Log::info('SYSCOM sync job: refresh stock publicados');
        Artisan::call('syscom:refresh-hermosillo-for-published', [
            '--user_id' => (string) $this->userId,
        ]);

        // Respaldo: productos que la API no trae con precio ni en detalle (pocos).
        $backfillLimit = (int) config('syscom.backfill_prices_limit', 500);
        $backfillBatches = min(5, (int) config('syscom.backfill_prices_max_batches', 5));
        Log::info('SYSCOM sync job: backfill residual', ['limit' => $backfillLimit, 'batches' => $backfillBatches]);
        for ($b = 1; $b <= $backfillBatches; $b++) {
            Artisan::call('syscom:backfill-prices', [
                '--user_id' => (string) $this->userId,
                '--limit' => (string) $backfillLimit,
            ]);
            if (str_contains(trim(Artisan::output()), 'No hay productos con costo MXN en 0')) {
                break;
            }
        }

        Log::info('SYSCOM sync job: finalizado', ['user_id' => $this->userId]);
    }

    /**
     * @param  array<string, string|bool>  $baseOptions
     */
    protected function runCategorySweep(SyscomApiService $syscom, array $baseOptions, string $branchName): void
    {
        try {
            $token = $syscom->getAccessToken();
        } catch (\Throwable $e) {
            Log::warning('SYSCOM sync job: categoria sweep sin token', ['error' => $e->getMessage()]);

            return;
        }

        $cacheMinutes = max(5, (int) config('syscom.categoria_tree_cache_minutes', 1440));
        $roots = config('syscom.categoria_sweep_root_ids', []);
        $extra = config('syscom.categoria_sweep_extra_ids', []);
        $maxIds = (int) config('syscom.categoria_sweep_max_ids', 400);
        $rootsKey = implode(',', $roots ?: ['all']);

        $categoryIds = Cache::remember(
            "syscom.categoria_sweep_ids.{$rootsKey}.{$maxIds}",
            now()->addMinutes($cacheMinutes),
            function () use ($syscom, $token, $roots, $extra, $maxIds) {
                $resolvedRoots = $roots;
                if ($resolvedRoots === []) {
                    $resolvedRoots = array_map(
                        static fn (array $row) => (string) ($row['id'] ?? ''),
                        $syscom->getCategories($token)
                    );
                    $resolvedRoots = array_values(array_filter($resolvedRoots, static fn (string $id) => $id !== ''));
                }

                $ids = SyscomCategoryTree::collectIds($syscom, $token, $resolvedRoots, $maxIds);

                foreach ($extra as $extraId) {
                    $extraId = trim((string) $extraId);
                    if ($extraId !== '' && ! in_array($extraId, $ids, true)) {
                        $ids[] = $extraId;
                    }
                }

                return array_values(array_unique($ids));
            }
        );

        if ($categoryIds === []) {
            Log::warning('SYSCOM sync job: categoria sweep sin IDs');

            return;
        }

        Log::info('SYSCOM sync job: categoria sweep', [
            'sucursal' => $branchName,
            'categories' => count($categoryIds),
        ]);

        $pause = max(0, (int) config('syscom.categoria_sweep_pause_s', 1));
        $i = 0;
        foreach ($categoryIds as $catId) {
            if ($i++ > 0 && $pause > 0) {
                sleep($pause);
            }

            Log::info('SYSCOM sync job: categoria', ['categoria_id' => $catId, 'sucursal' => $branchName]);
            $catExit = Artisan::call('syscom:sync-products', array_merge($baseOptions, [
                '--categoria' => (string) $catId,
            ]));
            if ($catExit !== 0) {
                Log::warning('SYSCOM sync job: categoria sweep falló', [
                    'categoria_id' => $catId,
                    'output' => trim(Artisan::output()),
                ]);
            }
        }
    }
}
