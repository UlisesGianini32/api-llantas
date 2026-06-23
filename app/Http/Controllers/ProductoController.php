<?php

namespace App\Http\Controllers;

use App\Jobs\ResolveShopifyCategoriesJob;
use App\Models\Product;
use App\Support\ApplyMlProductListingFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductoController extends Controller
{
    /**
     * Mismos filtros que el listado: búsqueda, tienda oficial, categorías ML.
     */
    protected function applyProductListFilters(Builder $query, Request $request): void
    {
        $categories = $request->input('categories', []);

        if (! is_array($categories)) {
            $categories = array_filter(explode(',', (string) $categories));
        }

        ApplyMlProductListingFilters::to($query, [
            'search' => trim((string) $request->input('search', '')),
            'official_store_id' => (string) $request->input('official_store_id', ''),
            'categories' => $categories,
        ]);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $perPage = (int) $request->input('perPage', 25);
        $officialStoreId = (string) $request->input('official_store_id', '');
        $categories = $request->input('categories', []);
        $sort = (string) $request->input('sort', 'name');
        $dir = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (!is_array($categories)) {
            $categories = array_filter(explode(',', (string) $categories));
        }

        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        $sortable = [
            'name' => 'name',
            'brand' => 'brand',
            'ml' => 'ml',
            'sku' => 'sku',
            'official_store_id' => 'official_store_id',
            'category_name' => 'category_name',
            'shopify_category_name' => 'shopify_category_name',
            'shopify_category_source' => 'shopify_category_source',
            'price' => 'price',
            'stock' => 'stock',
            'status_ml' => 'status_ml',
        ];

        $sortColumn = $sortable[$sort] ?? 'name';

        $query = Product::query()
            ->select([
                'id',
                'name',
                'ml',
                'sku',
                'official_store_id',
                'category_name',
                'category_id',
                'shopify_category_id',
                'shopify_category_name',
                'shopify_category_source',
                'price',
                'stock',
                'status_ml',
                'thumbnail',
                'permalink',
                'brand',
                'pictures',
                'description',
            ]);

        $this->applyProductListFilters($query, $request);

        $products = $query
            ->orderBy($sortColumn, $dir)
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Product $product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'ml' => $product->ml,
                    'sku' => $product->sku,
                    'official_store_id' => $product->official_store_id,
                    'category_name' => $product->category_name,
                    'category_id' => $product->category_id,
                    'shopify_category_id' => $product->shopify_category_id,
                    'shopify_category_name' => $product->shopify_category_name,
                    'shopify_category_source' => $product->shopify_category_source,
                    'price' => $product->price,
                    'stock' => $product->stock,
                    'status_ml' => $product->status_ml,
                    'thumbnail' => $product->thumbnail,
                    'permalink' => $product->permalink,
                    'brand' => $product->brand,
                    'description' => $product->description,
                ];
            });

        $allCategories = Product::query()
            ->whereNotNull('category_name')
            ->where('category_name', '!=', '')
            ->distinct()
            ->orderBy('category_name')
            ->pluck('category_name')
            ->values();

        $officialStores = Product::query()
            ->whereNotNull('official_store_id')
            ->where('official_store_id', '!=', '')
            ->distinct()
            ->orderBy('official_store_id')
            ->pluck('official_store_id')
            ->values();

        return Inertia::render('Producto/Index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'perPage' => $perPage,
                'official_store_id' => $officialStoreId,
                'categories' => array_values($categories),
                'sort' => $sort,
                'dir' => $dir,
            ],
            'categories' => $allCategories,
            'officialStores' => $officialStores,
        ]);
    }

    /**
     * Valor para la columna de categoría estándar en el CSV de Shopify.
     * Shopify acepta el breadcrumb en inglés o el ID corto (p. ej. hb-3-2-5-2);
     * el ID evita rechazos por diferencias mínimas en el texto del fullName.
     *
     * @see https://help.shopify.com/en/manual/products/import-export/using-csv
     */
    protected function shopifyCategoryForCsvExport(Product $product): string
    {
        $gid = trim((string) ($product->shopify_category_id ?? ''));
        if ($gid !== '' && preg_match('#^gid://shopify/TaxonomyCategory/(.+)$#', $gid, $m)) {
            return $m[1];
        }

        return trim((string) ($product->shopify_category_name ?? ''));
    }

    /**
     * Mercado Libre suele servir miniaturas con sufijo -O en mlstatic; -F apunta a mayor resolución.
     */
    protected function mercadoLibreImageUrlPreferFullSize(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? mb_strtolower($host) : '';
        if ($host === '' || (! str_contains($host, 'mlstatic') && ! str_contains($host, 'mercadolibre'))) {
            return $url;
        }

        $upgraded = preg_replace('#-O\.(jpg|jpeg|png|webp)(\?|$)#i', '-F.$1$2', $url);

        return is_string($upgraded) && $upgraded !== '' ? $upgraded : $url;
    }

    /**
     * Deduplica exportación Shopify para el import en Shopify:
     * 1) Un MLM = una fila CSV (evita la misma publicación ML duplicada aunque SKU o firma difieran).
     * 2) Sin MLM: por SKU de vendedor; si no hay SKU, firma estable (nombre/marca/categoría/precio).
     */
    protected function shopifyExportDedupKey(Product $product): string
    {
        $ml = trim((string) ($product->ml ?? ''));
        if ($ml !== '') {
            return 'ml:'.mb_strtolower($ml);
        }

        $sku = trim((string) ($product->sku ?? ''));
        if ($sku !== '') {
            return 'sku:'.mb_strtolower($sku);
        }

        $normalize = function (?string $value): string {
            $value = mb_strtolower(trim((string) $value));
            $value = preg_replace('/\s+/', ' ', $value);

            return is_string($value) ? $value : '';
        };

        return implode('|', [
            'sig',
            $normalize($product->name),
            $normalize($product->brand),
            $normalize($product->category_name),
            (string) $product->price,
        ]);
    }

    public function exportShopifyTobeauty(Request $request): StreamedResponse
    {
        $fileName = 'productos_shopify_tobeauty_' . now()->format('Ymd_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename={$fileName}",
        ];

        $sort = (string) $request->input('sort', 'name');
        $dir = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortable = [
            'name' => 'name',
            'brand' => 'brand',
            'ml' => 'ml',
            'sku' => 'sku',
            'official_store_id' => 'official_store_id',
            'category_name' => 'category_name',
            'shopify_category_name' => 'shopify_category_name',
            'shopify_category_source' => 'shopify_category_source',
            'price' => 'price',
            'stock' => 'stock',
            'status_ml' => 'status_ml',
        ];

        $sortColumn = $sortable[$sort] ?? 'name';

        $query = Product::query();
        $this->applyProductListFilters($query, $request);

        $products = $query
            ->orderBy($sortColumn, $dir)
            ->get([
                'name',
                'description',
                'brand',
                'sku',
                'price',
                'stock',
                'category_name',
                'shopify_category_id',
                'shopify_category_name',
                'thumbnail',
                'pictures',
            ])
            ->unique(fn (Product $product) => $this->shopifyExportDedupKey($product))
            ->values();

        return response()->stream(function () use ($products) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'Title',
                'Body (HTML)',
                'Vendor',
                'Type',
                'Variant SKU',
                'Variant Inventory Tracker',
                'Variant Inventory Policy',
                'Variant Fulfillment Service',
                'Variant Price',
                'Variant Inventory Qty',
                'Product category',
                'Image Src',
            ]);

            foreach ($products as $product) {
                $pictures = is_array($product->pictures) ? $product->pictures : [];
                $raw = $pictures[0] ?? $product->thumbnail;
                $imageSrc = $this->mercadoLibreImageUrlPreferFullSize($raw);
                $type = mb_substr(trim((string) ($product->category_name ?? '')), 0, 255);

                fputcsv($handle, [
                    $product->name,
                    $product->description,
                    $product->brand,
                    $type,
                    $product->sku,
                    'shopify',
                    'deny',
                    'manual',
                    $product->price,
                    $product->stock,
                    $this->shopifyCategoryForCsvExport($product),
                    $imageSrc,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    public function resolveShopifyCategories(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'official_store_id' => 'nullable|string|max:64',
            'categories' => 'nullable|array',
            'categories.*' => 'nullable|string|max:255',
            'only_empty' => 'nullable|boolean',
            'force' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:0|max:3000',
        ]);

        $onlyEmpty = (bool) ($validated['only_empty'] ?? true);
        $force = (bool) ($validated['force'] ?? false);
        $limit = (int) ($validated['limit'] ?? 0);

        $categories = $validated['categories'] ?? [];
        if (! is_array($categories)) {
            $categories = array_filter(explode(',', (string) $categories));
        }

        $userId = (int) ($request->user()?->id ?? 0);

        $payload = [
            'search' => trim((string) ($validated['search'] ?? '')),
            'official_store_id' => trim((string) ($validated['official_store_id'] ?? '')),
            'categories' => array_values(array_filter(array_map(static fn ($c) => is_string($c) ? trim($c) : '', $categories))),
            'only_empty' => $onlyEmpty,
            'force' => $force,
            'limit' => $limit,
        ];

        $connection = (string) config('queue.default', 'sync');

        try {
            // Importante: con QUEUE_CONNECTION=sync el job corría en esta misma petición y el navegador
            // quedaba "colgado" hasta terminar (API Shopify / taxonomía). afterResponse() envía primero
            // el redirect y ejecuta o encola el job al cerrar la respuesta HTTP.
            ResolveShopifyCategoriesJob::dispatch($userId, $payload)
                ->onConnection($connection)
                ->afterResponse();

            Log::info('[SHOPIFY] Resolver encolado (inserción en cola en este mismo request)', [
                'connection' => $connection,
                'queue' => config('queue.connections.'.$connection.'.queue', 'default'),
                'trigger_user_id' => $userId,
                'categorias_filtradas' => count($payload['categories']),
                'force' => $force,
                'only_empty' => $onlyEmpty,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SHOPIFY] No se pudo despachar ResolveShopifyCategoriesJob', [
                'trigger_user_id' => $userId,
                'connection' => $connection,
                'exception' => $e->getMessage(),
            ]);

            return back()->with(
                'error',
                'No se pudo iniciar la resolución de categorías (fallo al encolar). '
                    .'Revisa que la cola esté configurada: si usás database/redis, ejecutá las migraciones y '
                    .'php artisan queue:work. Detalle técnico: '.$e->getMessage()
            );
        }

        $queueHint =
            $connection === 'sync'
                ? 'El trabajo arranca en el servidor justo después de cargar esta página (cola sync). '
                    .'Si tarda mucho, en .env usá QUEUE_CONNECTION=database y un worker: php artisan queue:work.'
                : 'Cola "'.$connection.'": debe haber un worker activo (p. ej. php artisan queue:work). ';

        return back()->with(
            'success',
            'La resolución Shopify quedó programada. '
                .$queueHint
                .'Puede tardar varios minutos; actualizá el listado o revisá storage/logs/laravel.log buscando [SHOPIFY].'
        );
    }
}