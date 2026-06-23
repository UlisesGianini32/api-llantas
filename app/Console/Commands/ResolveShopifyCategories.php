<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ShopifyCategoryResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ResolveShopifyCategories extends Command
{
    /**
     * Opciones:
     * --store=280644               Solo una tienda oficial
     * --category=MLM192034         Solo una categoría ML (category_id exacto en DB)
     * --category-contains=...      Filtra por LIKE en category_name (si el id en DB no coincide con la UI)
     * --ml=MLM2890964274           Solo un producto por ML ID
     * --only-empty                 Solo productos sin categoría Shopify guardada
     * --force                      Recalcula aunque ya tengan categoría
     * --limit=100                  Limita la cantidad
     * --clear-unresolved           Si no hay match, borrar shopify_* (por defecto se conserva lo ya guardado)
     * --product-timeout=0        Segundos máximos por producto (Linux con pcntl; 0 = sin límite)
     */
    protected $signature = 'shopify:resolve-categories
                            {--store= : Filtrar por official_store_id}
                            {--category= : Filtrar por category_id (exacto, como viene de la API de ML)}
                            {--category-contains= : Filtrar por texto dentro de category_name}
                            {--ml= : Resolver solo un ML ID}
                            {--only-empty : Solo productos sin shopify_category_name}
                            {--force : Recalcular aunque ya exista shopify_category_name}
                            {--limit=0 : Limitar cantidad de productos a procesar}
                            {--trace : Imprimir progreso por producto (ML/ID)}
                            {--clear-unresolved : Borrar categoría Shopify cuando no haya match}
                            {--product-timeout=0 : Segundos máximos por producto (Linux+pcntl; 0=sin límite)}';

    protected $description = 'Recalcula y guarda la categoría Shopify para productos';

    public function handle(ShopifyCategoryResolverService $resolver): int
    {
        $store = trim((string) $this->option('store'));
        $category = trim((string) $this->option('category'));
        $categoryContains = trim((string) $this->option('category-contains'));
        $ml = trim((string) $this->option('ml'));
        $onlyEmpty = (bool) $this->option('only-empty');
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');
        $trace = (bool) $this->option('trace');
        $clearUnresolved = (bool) $this->option('clear-unresolved');
        $productTimeout = max(0, (int) $this->option('product-timeout'));

        $query = Product::query()->orderBy('id');

        if ($store !== '') {
            $query->where('official_store_id', $store);
        }

        if ($category !== '') {
            $query->whereRaw('LOWER(category_id) = ?', [strtolower($category)]);
        }

        if ($categoryContains !== '') {
            $safe = addcslashes($categoryContains, '%_\\');
            $query->where('category_name', 'like', '%' . $safe . '%');
        }

        $mlNormalized = '';
        if ($ml !== '') {
            $mlNormalized = $this->normalizeMeliItemId($ml);
            $query->where('ml', $mlNormalized);
        }

        if ($onlyEmpty && !$force) {
            $query->where(function ($q) {
                $q->whereNull('shopify_category_name')
                  ->orWhere('shopify_category_name', '');
            });
        }

        if (!$force && !$onlyEmpty && $ml === '') {
            $this->warn('No usaste --force ni --only-empty.');
            if (!$this->confirm('Eso recalculará productos aunque ya tengan categoría. ¿Continuar?', false)) {
                $this->info('Proceso cancelado.');
                return self::SUCCESS;
            }
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->warn('No se encontraron productos para procesar.');
            if ($category !== '') {
                $this->line("Filtro category_id usado: <fg=cyan>{$category}</> (comparación sin distinguir mayúsculas)");
                $this->comment('En Mercado Libre el id suele ser MLM… pero en tu DB puede ser otro (p. ej. MLM192034).');
                $this->printCategoryNameHints('Manos', 'Cremas para Manos');
                $this->printCategoryNameHints('Faja', 'Fajas');
            } elseif ($categoryContains !== '') {
                $this->line("Filtro category-contains usado: <fg=cyan>{$categoryContains}</>");
            } elseif ($ml !== '') {
                $this->line("Filtro ml usado: <fg=cyan>{$ml}</>" . ($mlNormalized !== '' && $mlNormalized !== $ml ? " → normalizado: <fg=cyan>{$mlNormalized}</>" : ''));
                $this->comment('Solo existen filas que ya pasaron por la sync de ítems (tabla products).');
                $digits = preg_replace('/\D+/', '', $ml);
                if ($digits !== '' && strlen($digits) >= 6) {
                    $near = Product::query()
                        ->where('ml', 'like', '%' . $digits . '%')
                        ->orderBy('id')
                        ->limit(8)
                        ->pluck('ml');
                    if ($near->isNotEmpty()) {
                        $this->info('Publicaciones en products cuyo ml contiene ese número (no coincide el id completo):');
                        foreach ($near as $rowMl) {
                            $this->line("  {$rowMl}");
                        }
                    }
                }
            }

            return self::SUCCESS;
        }

        $this->info("Productos a procesar: {$products->count()}");
        $this->comment(
            'La barra avanza al terminar cada producto. El primero suele tardar '
            .'(token OAuth a Shopify + búsquedas de taxonomía; hasta varios minutos si la API va lenta). '
            .'Progreso detallado (cada HTTP taxonomy): usá la ruta absoluta que imprime abajo (no `storage/...` desde otro cwd).'
        );

        $progressFile = storage_path('logs/shopify-category-resolve-progress.log');
        File::ensureDirectoryExists(dirname($progressFile));
        $resolver->setCategoryResolveProgressPath($progressFile);
        $banner = "\n=== shopify:resolve-categories ".date('c')." productos={$products->count()} ===\n";
        if (file_put_contents($progressFile, $banner, FILE_APPEND) === false) {
            $this->warn("No se pudo escribir el log de progreso: {$progressFile} (permisos o disco).");
        }
        $this->line('<fg=cyan>tail -f '.$progressFile.'</>');

        $useAlarm = $productTimeout > 0
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal');
        if ($productTimeout > 0) {
            if ($useAlarm) {
                $this->comment("Límite por producto: {$productTimeout}s (--product-timeout).");
            } else {
                $this->warn('--product-timeout ignorado: PHP sin pcntl o no disponible en este SAPI.');
                $productTimeout = 0;
                $useAlarm = false;
            }
        }
        if ($useAlarm) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, function () use ($productTimeout) {
                throw new \RuntimeException("Tiempo máximo por producto ({$productTimeout}s, --product-timeout).");
            });
        }

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        $resolvedCount = 0;
        $clearedCount = 0;
        $unchangedCount = 0;
        $stillUnresolved = 0;
        $keptExisting = 0;
        $errorCount = 0;

        foreach ($products as $idx => $product) {
            if ($useAlarm) {
                pcntl_alarm($productTimeout);
            }
            try {
                if ($trace) {
                    $this->newLine();
                    $this->line(sprintf(
                        '[%d/%d] Procesando ID=%s ML=%s CAT=%s',
                        $idx + 1,
                        $products->count(),
                        (string) ($product->id ?? ''),
                        (string) ($product->ml ?? ''),
                        (string) ($product->category_id ?? '')
                    ));
                } elseif ($idx === 0) {
                    $this->newLine();
                    $this->line(sprintf(
                        'Iniciando 1/%d — ID=%s ML=%s (esperá; al terminar este, la barra sube de 0%%)…',
                        $products->count(),
                        (string) ($product->id ?? ''),
                        (string) ($product->ml ?? '')
                    ));
                }

                $beforeName = trim((string) ($product->shopify_category_name ?? ''));
                $beforeId = trim((string) ($product->shopify_category_id ?? ''));
                $beforeSource = trim((string) ($product->shopify_category_source ?? ''));

                $resolved = $resolver->resolveAndSave($product, $clearUnresolved);

                $product->refresh();

                $afterName = trim((string) ($product->shopify_category_name ?? ''));
                $afterId = trim((string) ($product->shopify_category_id ?? ''));
                $afterSource = trim((string) ($product->shopify_category_source ?? ''));

                if ($resolved) {
                    if (
                        $beforeName !== $afterName ||
                        $beforeId !== $afterId ||
                        $beforeSource !== $afterSource
                    ) {
                        $resolvedCount++;
                    } else {
                        $unchangedCount++;
                    }
                } else {
                    if (
                        $clearUnresolved
                        && ($beforeName !== '' || $beforeId !== '' || $beforeSource !== '')
                    ) {
                        $clearedCount++;
                    } elseif ($beforeName === '' && $beforeId === '' && $beforeSource === '') {
                        $stillUnresolved++;
                    } else {
                        $keptExisting++;
                    }
                }
            } catch (\Throwable $e) {
                $errorCount++;

                $this->newLine();
                $this->error("Error con producto ID {$product->id} / ML {$product->ml}: {$e->getMessage()}");
            } finally {
                if ($useAlarm) {
                    pcntl_alarm(0);
                }
            }

            $bar->advance();
        }

        $resolver->setCategoryResolveProgressPath(null);

        $bar->finish();
        $this->newLine(2);

        $this->info("Resueltos/actualizados: {$resolvedCount}");
        $this->info("Limpiados: {$clearedCount}");
        $this->info("Sin cambios (ya correcto): {$unchangedCount}");
        if ($stillUnresolved > 0) {
            $this->warn("Sin categoría Shopify (resolver no obtuvo match): {$stillUnresolved}");
        }
        if ($keptExisting > 0) {
            $this->comment("Se mantuvo categoría anterior (sin match nuevo): {$keptExisting}");
        }

        if ($errorCount > 0) {
            $this->error("Errores: {$errorCount}");
        }

        if ($stillUnresolved > 0) {
            $this->newLine();
            $this->comment('Revisa .env (SHOPIFY_*), logs (storage/logs) y que el token tenga acceso a GraphQL taxonomy.');
        }

        $this->newLine();
        $this->line('Proceso terminado.');

        return self::SUCCESS;
    }

    /**
     * Id de ítem ML tal como lo devuelve la API (p. ej. MLM1984807743).
     * Acepta solo dígitos y antepone el site por defecto (config meli_menu.default_site_id).
     */
    protected function normalizeMeliItemId(string $raw): string
    {
        $s = strtoupper(trim($raw));
        if ($s === '') {
            return '';
        }
        if (preg_match('/(ML[A-Z]\d+)/', $s, $m)) {
            return $m[1];
        }
        $digits = preg_replace('/\D+/', '', $s);
        if ($digits !== '' && strlen($digits) >= 6) {
            $site = strtoupper(trim((string) config('meli_menu.default_site_id', 'MLM')));
            if ($site === '') {
                $site = 'MLM';
            }

            return $site . $digits;
        }

        return $s;
    }

    protected function printCategoryNameHints(string $nameFragment, string $containsExample): void
    {
        $safe = addcslashes($nameFragment, '%_\\');
        $hints = Product::query()
            ->whereNotNull('category_id')
            ->where('category_id', '!=', '')
            ->where('category_name', 'like', '%' . $safe . '%')
            ->selectRaw('category_id, COUNT(*) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(12)
            ->get();
        if ($hints->isEmpty()) {
            return;
        }
        $this->info("category_id reales en productos cuyo nombre de categoría contiene \"{$nameFragment}\":");
        foreach ($hints as $row) {
            $this->line("  {$row->category_id}\t({$row->total} productos)");
        }
        $this->comment("Alternativa: php artisan shopify:resolve-categories --force --category-contains=\"{$containsExample}\"");
    }
}