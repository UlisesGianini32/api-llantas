<?php

namespace App\Console\Commands;

use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Models\User;
use App\Services\SyscomApiService;
use App\Support\SyscomHermosilloStock;
use App\Support\SyscomPrecioExtractor;
use App\Support\SyscomProductPriceHydrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;

class SyscomSyncProductsCommand extends Command
{
    protected $signature = 'syscom:sync-products
                            {--user_id= : Usuario para crear cola de publicacion}
                            {--email= : Email del usuario para crear cola}
                            {--sucursal= : Dejar vacio para forzar la sucursal de config (hermosillo)}
                            {--max-pages=0 : Maximo de paginas (0 = todas)}
                            {--with-detail : Consulta detalle por producto}
                            {--busqueda= : Filtro busqueda (API requiere busqueda, marca o categoria)}
                            {--marca= : Filtro por marca}
                            {--categoria= : Filtro por categoria SYSCOM}
                            {--sweep : Recorre SYSCOM_BUSQUEDA_SWEEP (letras ñ; evita 0-9 suelto) — más productos}
                            {--no-progress : Sin barra de progreso (solo líneas de log)}';

    protected $description = 'Sincroniza catalogo de SYSCOM y prepara cola para publicar en Mercado Libre';

    public function handle(SyscomApiService $syscom): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            $this->error('No se encontro usuario con cuenta de ML. Usa --user_id= o --email=');
            return self::FAILURE;
        }

        try {
            $token = $syscom->getAccessToken();
        } catch (\Throwable $e) {
            $this->error('Error autenticando con SYSCOM: '.$e->getMessage());
            return self::FAILURE;
        }

        $branchInput = trim((string) $this->option('sucursal'));
        if ($branchInput === '') {
            $branchInput = (string) config('syscom.sucursal_nombre', 'hermosillo');
        }
        $this->info("Usando sucursal SYSCOM (exclusiva para catálogo ML): {$branchInput}");
        $branchCode = $syscom->resolveBranchCodeByName($token, $branchInput);

        if (! $branchCode) {
            $this->error("No se encontro sucursal en SYSCOM para: {$branchInput}");
            return self::FAILURE;
        }

        $this->info("Sucursal SYSCOM detectada: {$branchCode}");

        $filter = [];
        if ($b = trim((string) $this->option('busqueda'))) {
            $filter['busqueda'] = $b;
        }
        if ($m = trim((string) $this->option('marca'))) {
            $filter['marca'] = $m;
        }
        if ($c = trim((string) $this->option('categoria'))) {
            $filter['categoria'] = $c;
        }

        if ($this->option('sweep')) {
            if ($filter !== []) {
                $this->error('Con --sweep no uses --busqueda, --marca ni --categoria (o quitá --sweep).');

                return self::FAILURE;
            }
            $terms = config('syscom.busqueda_sweep_terms', []);
            if ($terms === []) {
                $this->error('config syscom.busqueda_sweep_terms está vacío. Revisa SYSCOM_BUSQUEDA_SWEEP en .env.');

                return self::FAILURE;
            }
            $this->info('Modo --sweep: '.count($terms).' búsquedas (cada término puede devolver 1+ páginas; los ids se unifican en BD).');
        } elseif ($filter === []) {
            $this->line('Filtro API: config syscom (SYSCOM_DEFAULT_BUSQUEDA / MARCA / CATEGORIA) — por defecto busqueda "'.config('syscom.default_productos_busqueda', 'a').'".');
        } else {
            $this->line('Filtro API: '.json_encode($filter, JSON_UNESCAPED_UNICODE));
        }

        $maxPages = max(0, (int) $this->option('max-pages'));
        $withDetail = (bool) $this->option('with-detail');
        $useProgress = ! $this->option('no-progress');

        $this->newLine();
        $this->comment('Progreso en esta consola: ejecutá este comando por SSH.');
        $this->comment('Desde la web (“Sincronizar catálogo”) corre en cola; seguí con: tail -f storage/logs/laravel.log | grep "SYSCOM sync"');
        if ($withDetail) {
            $this->comment('Detalle por producto: agregá -v (verbose).');
        }
        $this->newLine();

        $errors = 0;
        $touched = 0;
        $colaOps = 0;

        if ($this->option('sweep')) {
            $sweepI = 0;
            foreach (config('syscom.busqueda_sweep_terms', []) as $term) {
                if ($sweepI++ > 0) {
                    $pause = max(0, (int) config('syscom.sweep_pause_between_terms_s', 2));
                    if ($pause > 0) {
                        sleep($pause);
                    }
                }
                $f = ['busqueda' => $term];
                $this->line('');
                $this->line("── Búsqueda «{$term}» ──");
                $r = $this->runSyncForFilter(
                    $syscom,
                    $token,
                    $user,
                    $branchCode,
                    $branchInput,
                    $f,
                    $maxPages,
                    $withDetail,
                    $useProgress,
                    "«{$term}»"
                );
                $touched += $r['touched'];
                $colaOps += $r['cola_ops'];
                $errors += $r['errors'];
            }
        } else {
            $r = $this->runSyncForFilter(
                $syscom,
                $token,
                $user,
                $branchCode,
                $branchInput,
                $filter,
                $maxPages,
                $withDetail,
                $useProgress,
                null
            );
            $touched = $r['touched'];
            $colaOps = $r['cola_ops'];
            $errors = $r['errors'];
        }

        $this->info('Sincronizacion SYSCOM finalizada.');
        $this->line("Operaciones update en productos: {$touched} (mismo id en varias búsquedas cuenta varias veces; no implica filas distintas nuevas).");
        $this->line("Operaciones en cola ML: {$colaOps}");
        $this->line('Productos distintos en base ahora: '.SyscomProduct::query()->count());
        $this->line("Errores no fatales: {$errors}");

        return self::SUCCESS;
    }

    protected function apiPause(): void
    {
        $us = max(0, (int) config('syscom.api_delay_us', 350_000));
        if ($us > 0) {
            usleep($us);
        }
    }

    /**
     * @param  array{busqueda?:string,marca?:string,categoria?:string}  $filter
     * @return array{touched: int, cola_ops: int, errors: int}
     */
    protected function runSyncForFilter(
        SyscomApiService $syscom,
        string $token,
        User $user,
        string $branchCode,
        string $branchInput,
        array $filter,
        int $maxPages,
        bool $withDetail,
        bool $useProgress = true,
        ?string $label = null
    ): array {
        $touched = 0;
        $colaOps = 0;
        $errors = 0;
        $hydratedFromDetail = 0;
        $page = 1;
        $pages = 1;
        $verbose = $this->output->isVerbose();
        $hydratePrices = (bool) config('syscom.sync_hydrate_missing_prices', true);
        $filterLabel = $label ?? (json_encode($filter, JSON_UNESCAPED_UNICODE) ?: 'config default');

        $detailNote = $withDetail ? ' (con detalle por producto)' : ' (solo listado)';
        if ($hydratePrices) {
            $detailNote .= ' + precios automáticos si el listado viene sin USD';
        }
        $this->info('Sincronizando filtro '.$filterLabel.$detailNote);

        /** @var ProgressBar|null $pageBar */
        $pageBar = null;

        do {
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }

            try {
                $result = $syscom->searchProducts($token, $branchCode, $page, true, $filter);
                $this->apiPause();
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('SYSCOM sync page failed', ['page' => $page, 'error' => $e->getMessage()]);
                $this->warn("Fallo pagina {$page}: {$e->getMessage()}");
                $this->logSyncProgress($filterLabel, $page, $pages, $touched, $errors, 'page_failed');
                $page++;

                continue;
            }

            $pages = max(1, (int) ($result['paginas'] ?? $pages));
            $items = $result['productos'] ?? [];

            if ($page === 1 && $useProgress && $pages > 1) {
                $pageBar = $this->output->createProgressBar($maxPages > 0 ? min($pages, $maxPages) : $pages);
                $pageBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
                $pageBar->setMessage('páginas');
                $pageBar->start();
            }

            if (! is_array($items) || $items === []) {
                $this->logSyncProgress($filterLabel, $page, $pages, $touched, $errors, 'empty_page');
                if (! $useProgress) {
                    $this->line("Pagina {$page}/{$pages}: sin productos.");
                }
                $page++;

                continue;
            }

            $itemBar = null;
            if ($useProgress && $withDetail && count($items) > 1) {
                $itemBar = $this->output->createProgressBar(count($items));
                $itemBar->setFormat('   productos %current%/%max% [%bar%] %message%');
                $itemBar->setMessage("pág {$page}/{$pages}");
                $itemBar->start();
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $productoId = (int) ($item['producto_id'] ?? 0);
                if ($productoId <= 0) {
                    continue;
                }

                $titulo = Str::limit((string) ($item['titulo'] ?? ''), 55, '…');

                $existing = SyscomProduct::query()
                    ->where('syscom_producto_id', $productoId)
                    ->first();

                $detail = [];
                if ($withDetail) {
                    try {
                        $detail = $syscom->getProduct($token, $productoId, $branchCode);
                        $this->apiPause();
                    } catch (\Throwable $e) {
                        $errors++;
                        Log::warning('SYSCOM get product detail failed', [
                            'producto_id' => $productoId,
                            'error' => $e->getMessage(),
                        ]);
                        if ($verbose) {
                            $this->warn("  ✗ detalle {$productoId}: ".$e->getMessage());
                        }
                    }
                }

                if ($hydratePrices) {
                    $ensured = SyscomProductPriceHydrator::ensureDetailWithPrices(
                        $syscom,
                        $token,
                        $productoId,
                        $branchCode,
                        $item,
                        $detail,
                        $existing
                    );
                    $detail = $ensured['detail'];
                    if ($ensured['fetched_detail']) {
                        $hydratedFromDetail++;
                        $this->apiPause();
                    }
                }

                $productData = $this->buildProductData($item, $detail, $branchCode, $branchInput, $existing);

                if ($this->shouldSkipWithoutHermosilloStock($productData)) {
                    if ($verbose) {
                        $this->line(sprintf(
                            '  ⊘ %d — %s (sin stock en %s, omitido)',
                            $productoId,
                            $titulo,
                            $branchInput
                        ));
                    } elseif ($itemBar instanceof ProgressBar) {
                        $itemBar->advance();
                    }

                    continue;
                }

                $product = SyscomProduct::updateOrCreate(
                    ['syscom_producto_id' => $productoId],
                    $productData
                );
                $touched++;

                $queue = SyscomMeliQueue::query()->firstOrNew([
                    'user_id' => $user->id,
                    'syscom_producto_id' => $productoId,
                ]);
                $isNew = ! $queue->exists;
                $queue->syscom_product_id = $product->id;
                $queue->branch_code = $branchCode;
                if ($isNew && ! $queue->mlm) {
                    $queue->status = 'pending_price';
                }
                $queue->save();
                $colaOps++;

                if ($verbose) {
                    $this->line(sprintf(
                        '  ✓ %d — %s (stock %d)',
                        $productoId,
                        $titulo,
                        (int) ($product->stock_hermosillo ?? 0)
                    ));
                } elseif ($itemBar instanceof ProgressBar) {
                    $itemBar->setMessage("#{$productoId}");
                    $itemBar->advance();
                }
            }

            if ($itemBar instanceof ProgressBar) {
                $itemBar->finish();
                $this->newLine();
            }

            $this->logSyncProgress($filterLabel, $page, $pages, $touched, $errors, 'ok', count($items));

            if ($pageBar instanceof ProgressBar) {
                $pageBar->setMessage("pág {$page}/{$pages} · ".count($items).' ítems · acum '.$touched);
                $pageBar->advance();
            } elseif (! $useProgress) {
                $this->line("Pagina {$page}/{$pages}: ".count($items).' productos (acumulado '.$touched.')');
            }

            $page++;
        } while ($page <= $pages);

        if ($pageBar instanceof ProgressBar) {
            $pageBar->finish();
            $this->newLine();
        }

        if ($hydratedFromDetail > 0) {
            $this->line("Precios completados desde detalle SYSCOM: {$hydratedFromDetail} producto(s).");
        }

        return [
            'touched' => $touched,
            'cola_ops' => $colaOps,
            'errors' => $errors,
            'hydrated_from_detail' => $hydratedFromDetail,
        ];
    }

    /**
     * Para sync en cola (desde la web): tail -f storage/logs/laravel.log | grep "SYSCOM sync"
     */
    protected function logSyncProgress(
        string $filterLabel,
        int $page,
        int $pages,
        int $touched,
        int $errors,
        string $phase,
        int $itemsOnPage = 0
    ): void {
        Log::info('SYSCOM sync', [
            'filter' => $filterLabel,
            'page' => $page,
            'pages' => $pages,
            'items_on_page' => $itemsOnPage,
            'products_touched' => $touched,
            'errors' => $errors,
            'phase' => $phase,
        ]);
    }

    protected function resolveUser(): ?User
    {
        $userId = $this->option('user_id');
        $email = $this->option('email');

        if ($userId) {
            $user = User::query()->find($userId);
            if ($user && $user->access_token) {
                return $user;
            }
        }

        if ($email) {
            $user = User::query()->where('email', $email)->first();
            if ($user && $user->access_token) {
                return $user;
            }
        }

        return User::query()->whereNotNull('access_token')->first();
    }

    protected function buildProductData(
        array $item,
        array $detail,
        string $branchCode,
        string $branchNameLabel,
        ?SyscomProduct $existing = null
    ): array {
        $prices = SyscomProductPriceHydrator::mergeWithExistingProduct(
            SyscomPrecioExtractor::extractSyscomPrecios($item, $detail),
            $existing
        );

        $imagenes = [];
        if (is_array($detail['imagenes'] ?? null)) {
            foreach ($detail['imagenes'] as $img) {
                $url = trim((string) ($img['url'] ?? ''));
                if ($url !== '') {
                    $imagenes[] = ['url' => $url];
                }
            }
        }

        $itemExistencia = is_array($item['existencia'] ?? null) ? $item['existencia'] : [];
        $detailExistencia = is_array($detail['existencia'] ?? null) ? $detail['existencia'] : [];

        $existencia = $detailExistencia !== [] ? $detailExistencia : $itemExistencia;

        if (isset($detail['__branch_scoped_existencia'])) {
            unset($detail['__branch_scoped_existencia']);
        }

        // total_existencia (item o detalle) es NACIONAL; no sirve para stock por sucursal.
        // El stock real de Hermosillo debería venir desglosado dentro de `existencia` por
        // sucursal, pero algunas cuentas SYSCOM devuelven un bloque "status-only"
        // ({nuevo, asterisco, detalle}) que es NACIONAL aunque pidamos ?sucursal=X. Por eso:
        //  1) Intentamos extraer la cantidad real con el parser (busca claves por sucursal).
        //  2) Si no la encontró (bloque status-only o vacío), usamos 1 como conservador:
        //     este producto aparece en sucursal=X&stock=1, así que hay AL MENOS una unidad
        //     en la sucursal. Mejor publicar 1 (vendible) que 76 (sobreventa).
        $hermosillo = SyscomHermosilloStock::forBranch($itemExistencia, $branchCode, $branchNameLabel);
        if ($hermosillo <= 0) {
            $hermosillo = SyscomHermosilloStock::forBranch($detailExistencia, $branchCode, $branchNameLabel);
        }
        if ($hermosillo <= 0) {
            $hermosillo = 1;
        }

        $totalExistencia = (int) ($detail['total_existencia'] ?? $item['total_existencia'] ?? 0);

        $data = [
            'modelo' => (string) ($item['modelo'] ?? $detail['modelo'] ?? ''),
            'titulo' => (string) ($item['titulo'] ?? $detail['titulo'] ?? ''),
            'marca' => (string) ($item['marca'] ?? $detail['marca'] ?? ''),
            'sat_key' => (string) ($item['sat_key'] ?? $detail['sat_key'] ?? ''),
            'img_portada' => (string) ($item['img_portada'] ?? $detail['img_portada'] ?? ''),
            'total_existencia' => $totalExistencia,
            'stock_hermosillo' => $hermosillo,
            'existencia' => $existencia,
            'imagenes' => $imagenes,
            'descripcion' => (string) ($detail['descripcion'] ?? ''),
            'categorias' => $detail['categorias']
                ?? $detail['categorías']
                ?? $item['categorias']
                ?? $item['categorías']
                ?? [],
            'raw_list' => SyscomProductPriceHydrator::mergeListPayload(
                $item,
                $existing?->raw_list
            ),
            'raw_detail' => $detail !== [] ? $detail : ($existing?->raw_detail),
            'last_synced_at' => now(),
        ];

        foreach (SyscomProductPriceHydrator::pricesForDatabase($prices) as $pk => $value) {
            $data[$pk] = $value;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $productData
     */
    protected function shouldSkipWithoutHermosilloStock(array $productData): bool
    {
        if (! config('syscom.import_only_hermosillo_stock', true)) {
            return false;
        }

        return (int) ($productData['stock_hermosillo'] ?? 0) <= 0;
    }

    /**
     * Importes que vienen como "14,180.28" o "$ 14,180.28 MXN" (is_numeric falla con comas).
     */
    protected function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            return $this->parseMoneyString($value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Normaliza a float importes con separadores de miles o símbolos.
     */
    protected function parseMoneyString(string $value): ?float
    {
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        $s = str_replace(['$', 'MXN', 'mxn', "\xc2\xa0", "\xa0", ' '], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace(',', '', $s);
        } elseif (preg_match('/^\d+,\d{1,2}$/', $s)) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

}
