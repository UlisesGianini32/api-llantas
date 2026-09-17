<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSyscomCatalogJob;
use App\Models\MeliPublication;
use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Models\User;
use App\Services\MeliPublishService;
use App\Services\SyscomApiService;
use App\Services\SyscomMeliPublishService;
use App\Services\SyscomProductPricingService;
use App\Support\SyscomHermosilloStock;
use App\Support\SyscomPrecioExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SyscomMeliController extends Controller
{
    public function index(Request $request, SyscomProductPricingService $pricing, SyscomMeliPublishService $publishService): Response
    {
        $q = trim((string) $request->get('q', ''));
        $cola = trim((string) $request->get('cola', ''));

        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $queueCounts = $this->syscomMeliQueueCounts((int) $user->id);

        $query = SyscomProduct::query()
            ->orderByDesc('id');

        // No ocultar productos con stock Hermosillo = 0: siguen en BD y en cola ML;
        // solo el sync de importación evita traer productos nuevos sin stock local.

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('titulo', 'like', "%{$q}%")
                    ->orWhere('modelo', 'like', "%{$q}%")
                    ->orWhere('marca', 'like', "%{$q}%");
            });
        }

        $this->applyColaFilter($query, (int) $user->id, $cola);

        $paginator = $query->paginate(20)->withQueryString();

        $ids = $paginator->getCollection()->pluck('id')->all();
        try {
            $queues = SyscomMeliQueue::query()
                ->where('user_id', $user->id)
                ->whereIn('syscom_product_id', $ids)
                ->get()
                ->keyBy('syscom_product_id');
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'price_mode') || str_contains($e->getMessage(), 'price_locked_at')) {
                abort(503, 'Falta ejecutar migraciones en el servidor: php artisan migrate');
            }

            throw $e;
        }

        $mlms = $queues->pluck('mlm')->filter()->values()->all();
        $pubsByMlm = $this->loadMeliPublicationSnapshots((int) $user->id, $mlms);

        $listPage = (int) $request->get('page', 1);

        $skuList = $paginator->getCollection()
            ->map(fn (SyscomProduct $p) => $publishService->makeSku((int) $p->syscom_producto_id))
            ->unique()
            ->values()
            ->all();
        $pubCountsBySku = $skuList === []
            ? []
            : DB::table('meli_publications')
                ->where('user_id', $user->id)
                ->whereIn('sku', $skuList)
                ->groupBy('sku')
                ->selectRaw('sku, COUNT(*) as aggregate')
                ->pluck('aggregate', 'sku')
                ->all();

        $hasPriceModeColumn = Schema::hasColumn('syscom_meli_queues', 'price_mode');

        $rows = $paginator->getCollection()->map(function (SyscomProduct $p) use (
            $queues,
            $pricing,
            $pubsByMlm,
            $pubCountsBySku,
            $publishService,
            $listPage,
            $q,
            $cola,
            $hasPriceModeColumn
        ) {
            try {
                return $this->buildSyscomIndexRow(
                    $p,
                    $queues->get($p->id),
                    $pricing,
                    $publishService,
                    $pubsByMlm,
                    $pubCountsBySku,
                    $listPage,
                    $q,
                    $cola,
                    $hasPriceModeColumn
                );
            } catch (\Throwable $e) {
                Log::error('syscom.meli.index row failed', [
                    'product_id' => $p->id,
                    'syscom_producto_id' => $p->syscom_producto_id,
                    'err' => $e->getMessage(),
                ]);

                return [
                    'id' => $p->id,
                    'syscom_producto_id' => (int) $p->syscom_producto_id,
                    'titulo' => (string) ($p->titulo ?? 'Error al cargar fila'),
                    'marca' => (string) ($p->marca ?? ''),
                    'modelo' => (string) ($p->modelo ?? ''),
                    'stock_hermosillo' => (int) ($p->stock_hermosillo ?? 0),
                    'precio_meli' => 0,
                    'precio_formula_mxn' => 0,
                    'price_mode' => 'auto',
                    'meli_price_ml' => null,
                    'price_desync' => false,
                    'costo_mxn' => 0,
                    'recibes_estimado_mxn' => null,
                    'mlm' => null,
                    'ml_permalink' => null,
                    'meli_estado' => null,
                    'meli_status_raw' => null,
                    'meli_sub_status' => null,
                    'meli_block_hint' => 'Error: '.$e->getMessage(),
                    'publicaciones_mismo_sku' => 0,
                    'seller_sku' => $publishService->makeSku((int) $p->syscom_producto_id),
                    'puede_republicar' => true,
                    'can_sync_price_ml' => false,
                    'queue_status' => null,
                    'publish_error' => null,
                    'edit_url' => route('syscom.meli.edit', ['id' => $p->id]),
                ];
            }
        });
        $paginator->setCollection($rows);

        return Inertia::render('Syscom/Index', [
            'sucursal' => (string) config('syscom.sucursal_nombre', 'hermosillo'),
            'products' => $paginator,
            'recibesEstimateConfigured' => $pricing->recibesEstimateConfigured(),
            'meliLinked' => (bool) ($user->access_token ?? ''),
            'filters' => [
                'q' => $q,
                'cola' => $cola,
                'page' => $listPage,
            ],
            'queueCounts' => $queueCounts,
        ]);
    }

    public function editWeb(
        Request $request,
        int $id,
        SyscomProductPricingService $pricing,
        SyscomMeliPublishService $publishService
    ): Response {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $product = SyscomProduct::query()->findOrFail($id);
        $queue = SyscomMeliQueue::query()
            ->where('user_id', $user->id)
            ->where('syscom_product_id', $product->id)
            ->first();

        $scope = (string) ($queue?->price_scope ?? 'llanta');
        $precioFormula = 0.0;
        $costoMx = 0.0;
        try {
            $precioFormula = $pricing->priceFor($product, $scope, null);
            $costoMx = round($pricing->costoMxParaFormula($product), 2);
        } catch (\Throwable) {
        }

        $sellerSku = $publishService->makeSku((int) $product->syscom_producto_id);
        $meliPubs = MeliPublication::query()
            ->where('user_id', $user->id)
            ->where('sku', $sellerSku)
            ->orderByDesc('id')
            ->get(['id', 'mlm', 'status', 'sub_status', 'permalink', 'created_at']);

        $mlmCola = trim((string) ($queue?->mlm ?? ''));
        $pubCola = $mlmCola !== ''
            ? $meliPubs->first(fn (MeliPublication $p) => (string) $p->mlm === $mlmCola)
            : null;
        $mlStatusCola = $pubCola?->status;

        return Inertia::render('Syscom/Edit', [
            'product' => [
                'id' => $product->id,
                'syscom_producto_id' => (int) $product->syscom_producto_id,
                'sku' => $sellerSku,
                'marca' => (string) ($product->marca ?? ''),
                'modelo' => (string) ($product->modelo ?? ''),
                'titulo' => (string) ($product->titulo ?? ''),
                'descripcion' => (string) ($product->descripcion ?? ''),
                'precio_lista' => (float) ($product->precio_lista ?? 0),
                'precio_especial' => (float) ($product->precio_especial ?? 0),
                'precio_descuento' => (float) ($product->precio_descuento ?? 0),
                'stock_hermosillo' => (int) ($product->stock_hermosillo ?? 0),
                'costo_mxn' => $costoMx,
                'precio_formula_mxn' => round($precioFormula, 2),
                'precio_meli' => $queue
                    ? (float) $pricing->priceFor($product, $scope, $queue)
                    : round($precioFormula, 2),
                'mlm' => $queue?->mlm,
                'meli_status_raw' => $mlStatusCola,
                'can_sync_price_ml' => $mlmCola !== '' && MeliPublication::permiteActualizarPrecioStock($mlStatusCola),
                'puede_republicar' => ! $mlmCola || MeliPublication::permiteRepublicarSegunEstadoMl($mlStatusCola),
                'price_scope' => $scope,
                'price_mode' => $queue?->price_mode ?? 'auto',
                'queue_status' => $queue?->status,
                'publish_error' => $queue?->publish_error,
                'meli_publications' => $meliPubs->map(fn (MeliPublication $pub) => [
                    'id' => $pub->id,
                    'mlm' => $pub->mlm,
                    'status' => $pub->status,
                    'sub_status' => $pub->sub_status_text,
                    'permalink' => $pub->permalink,
                    'created_at' => $pub->created_at?->format('Y-m-d H:i:s'),
                ])->values(),
            ],
            'filters' => [
                'page' => (int) $request->query('page', 1),
                'q' => (string) $request->query('q', ''),
                'cola' => (string) $request->query('cola', ''),
            ],
            'meliLinked' => (bool) ($user->access_token ?? ''),
        ]);
    }

    public function updateWeb(
        Request $request,
        int $id,
        SyscomProductPricingService $pricing,
        SyscomMeliPublishService $publishService
    ): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $product = SyscomProduct::query()->findOrFail($id);
        $queue = SyscomMeliQueue::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'syscom_producto_id' => $product->syscom_producto_id,
            ],
            [
                'syscom_product_id' => $product->id,
                'status' => 'pending_price',
            ]
        );

        $validated = $request->validate([
            'marca' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'titulo' => ['nullable', 'string', 'max:500'],
            'descripcion' => ['nullable', 'string'],
            'precio_lista' => ['nullable', 'numeric', 'min:0'],
            'precio_especial' => ['nullable', 'numeric', 'min:0'],
            'precio_descuento' => ['nullable', 'numeric', 'min:0'],
            'stock_hermosillo' => ['required', 'integer', 'min:0'],
            'precio_meli' => ['required', 'numeric', 'min:0'],
            'mlm' => ['nullable', 'string', 'max:50'],
            'price_scope' => ['nullable', 'in:llanta,par,juego4'],
            'page' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string'],
            'cola' => ['nullable', 'string'],
            'sync_to_meli' => ['nullable', 'boolean'],
        ]);

        $product->update([
            'marca' => $validated['marca'] ?? '',
            'modelo' => $validated['modelo'] ?? '',
            'titulo' => $validated['titulo'] ?? '',
            'descripcion' => $validated['descripcion'] ?? '',
            'precio_lista' => $validated['precio_lista'] ?? 0,
            'precio_especial' => $validated['precio_especial'] ?? 0,
            'precio_descuento' => $validated['precio_descuento'] ?? 0,
            'stock_hermosillo' => $validated['stock_hermosillo'],
        ]);

        $scope = (string) ($validated['price_scope'] ?? $queue->price_scope ?? 'llanta');
        $precioAnterior = (float) ($queue->desired_price ?? 0);
        if ($precioAnterior <= 0) {
            try {
                $precioAnterior = $pricing->priceFor($product, $scope, $queue);
            } catch (\Throwable) {
                $precioAnterior = 0.0;
            }
        }
        $nuevoPrecio = (float) $validated['precio_meli'];

        $queueUpdate = [
            'syscom_product_id' => $product->id,
            'mlm' => trim((string) ($validated['mlm'] ?? '')) ?: null,
            'price_scope' => $scope,
            'desired_price' => $nuevoPrecio,
        ];

        if ($precioAnterior <= 0 || abs($precioAnterior - $nuevoPrecio) > 0.01) {
            $queueUpdate['price_mode'] = 'manual';
            $queueUpdate['price_locked_at'] = now();
        }

        $queue->update($queueUpdate);
        $queue->refresh();

        $flash = 'Producto SYSCOM actualizado.';
        if ($request->boolean('sync_to_meli') && $user->access_token) {
            $mlmSync = trim((string) ($queue->mlm ?? ''));
            if ($mlmSync !== '') {
                try {
                    $res = $publishService->syncPublishedItemFromProduct($user, $product, $mlmSync, null, $queue);
                    $flash .= sprintf(
                        ' ML: $%s, stock %d u.',
                        number_format($res['price'], 2, '.', ','),
                        $res['stock']
                    );
                } catch (\Throwable $e) {
                    return back()->with('error', 'Guardado en BD pero falló ML: '.MeliPublishService::friendlyMlErrorMessage($e->getMessage()));
                }
            }
        }

        return redirect()
            ->route('syscom.meli.index', array_filter([
                'page' => $request->input('page', 1),
                'q' => $request->input('q', ''),
                'cola' => $request->input('cola', ''),
            ]))
            ->with('success', $flash);
    }

    public function setPriceManual(
        Request $request,
        int $id,
        SyscomProductPricingService $pricing
    ): RedirectResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $product = SyscomProduct::query()->findOrFail($id);
        $queue = $this->resolveUserQueue($user, $product);
        $scope = (string) ($queue->price_scope ?? 'llanta');

        $validated = $request->validate([
            'precio_meli' => ['nullable', 'numeric', 'min:0'],
        ]);
        $fromForm = (float) ($validated['precio_meli'] ?? 0);
        if ($fromForm > 0) {
            $queue->desired_price = round($fromForm, 2);
        } elseif ((float) ($queue->desired_price ?? 0) > 0) {
            $queue->desired_price = round((float) $queue->desired_price, 2);
        } else {
            try {
                $queue->desired_price = round($pricing->priceFor($product, $scope, null), 2);
            } catch (\Throwable) {
                $queue->desired_price = null;
            }
        }

        $queue->price_mode = 'manual';
        $queue->price_locked_at = now();
        $queue->save();

        return back()->with(
            'success',
            'Precio bloqueado (MANUAL) en $'.number_format((float) ($queue->desired_price ?? 0), 2, '.', ',')
            .'. Se respeta en la sync automática (meli:sync-stock). Para aplicarlo ya en ML, usá «Sync precio ML».'
        );
    }

    public function setPriceAuto(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $product = SyscomProduct::query()->findOrFail($id);
        $queue = $this->resolveUserQueue($user, $product);
        $queue->price_mode = 'auto';
        $queue->desired_price = null;
        $queue->price_locked_at = null;
        $queue->save();

        return back()->with('success', 'Precio en modo automático (fórmula SYSCOM).');
    }

    public function recalcPrice(
        Request $request,
        int $id,
        SyscomProductPricingService $pricing
    ): RedirectResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $product = SyscomProduct::query()->findOrFail($id);
        $queue = $this->resolveUserQueue($user, $product);
        $scope = (string) ($queue->price_scope ?? 'llanta');

        $queue->price_mode = 'auto';
        $queue->price_locked_at = null;
        try {
            $queue->desired_price = round($pricing->priceFor($product, $scope, null), 2);
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo recalcular: '.$e->getMessage());
        }
        $queue->save();

        return back()->with('success', 'Precio recalculado con la fórmula activa.');
    }

    /**
     * @param  array<string>  $mlms
     * @return array<string, array{status: ?string, permalink: ?string, sub_status_text: ?string, block_hint: string, price: ?float}>
     */
    private function loadMeliPublicationSnapshots(int $userId, array $mlms): array
    {
        if ($mlms === []) {
            return [];
        }

        $rows = DB::table('meli_publications')
            ->where('user_id', $userId)
            ->whereIn('mlm', $mlms)
            ->orderByDesc('id')
            ->get(['mlm', 'status', 'permalink', 'sub_status', 'raw']);

        $out = [];
        foreach ($rows as $row) {
            $mlm = (string) ($row->mlm ?? '');
            if ($mlm === '' || isset($out[$mlm])) {
                continue;
            }

            $raw = $this->decodeJsonToArray($row->raw ?? null);
            $price = MeliPublication::listPriceFromRaw($raw);

            $out[$mlm] = [
                'status' => $row->status !== null ? (string) $row->status : null,
                'permalink' => $row->permalink !== null ? (string) $row->permalink : null,
                'sub_status_text' => $this->formatMeliSubStatus($row->sub_status ?? null),
                'block_hint' => $this->meliBlockHintFromRaw($raw),
                'price' => $price,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{status: ?string, permalink: ?string, sub_status_text: ?string, block_hint: string, price: ?float}>  $pubsByMlm
     * @param  array<string, int|string>  $pubCountsBySku
     * @return array<string, mixed>
     */
    private function buildSyscomIndexRow(
        SyscomProduct $p,
        ?SyscomMeliQueue $row,
        SyscomProductPricingService $pricing,
        SyscomMeliPublishService $publishService,
        array $pubsByMlm,
        array $pubCountsBySku,
        int $listPage,
        string $q,
        string $cola,
        bool $hasPriceModeColumn = true
    ): array {
        $scope = (string) ($row?->price_scope ?? 'llanta');
        $price = 0.0;
        $precioFormula = 0.0;
        try {
            $price = $pricing->priceFor($p, $scope, $row);
            $precioFormula = $pricing->priceFor($p, $scope, null);
        } catch (\Throwable) {
        }

        $costoMx = 0.0;
        try {
            $costoMx = round($pricing->costoMxParaFormula($p), 2);
        } catch (\Throwable) {
        }

        $recibesEst = $pricing->estimateRecibesMercadoLibreMx((float) $price);
        $stock = (int) ($p->stock_hermosillo ?? 0);

        $mlm = $row?->mlm;
        $pub = ($mlm && isset($pubsByMlm[$mlm])) ? $pubsByMlm[$mlm] : null;
        $meliPriceMl = $pub !== null ? ($pub['price'] ?? null) : null;
        $mlStatus = $pub !== null ? ($pub['status'] ?? null) : null;
        $meliEstado = MeliPublication::etiquetaEstadoPublicacion($mlStatus);
        $puedeRepublicar = ! $mlm
            || ($row?->status === 'error')
            || MeliPublication::permiteRepublicarSegunEstadoMl($mlStatus);

        $sellerSku = $publishService->makeSku((int) $p->syscom_producto_id);
        $publicacionesMismoSku = (int) ($pubCountsBySku[$sellerSku] ?? 0);

        $priceMode = 'auto';
        if ($hasPriceModeColumn && $row !== null) {
            $priceMode = strtolower((string) ($row->price_mode ?? 'auto'));
        }

        return [
            'id' => $p->id,
            'syscom_producto_id' => (int) $p->syscom_producto_id,
            'titulo' => (string) ($p->titulo ?? ''),
            'marca' => (string) ($p->marca ?? ''),
            'modelo' => (string) ($p->modelo ?? ''),
            'stock_hermosillo' => $stock,
            'precio_meli' => round($price, 2),
            'precio_formula_mxn' => round($precioFormula, 2),
            'price_mode' => $priceMode,
            'meli_price_ml' => $meliPriceMl,
            'price_desync' => $meliPriceMl !== null && $price > 0 && abs($meliPriceMl - $price) > 0.02,
            'costo_mxn' => $costoMx,
            'recibes_estimado_mxn' => $recibesEst,
            'mlm' => $mlm,
            'ml_permalink' => $pub !== null ? ($pub['permalink'] ?? null) : null,
            'meli_estado' => $meliEstado,
            'meli_status_raw' => $mlStatus,
            'meli_sub_status' => $pub !== null ? ($pub['sub_status_text'] ?? null) : null,
            'meli_block_hint' => $pub !== null ? ($pub['block_hint'] ?? '') : '',
            'publicaciones_mismo_sku' => $publicacionesMismoSku,
            'seller_sku' => $sellerSku,
            'puede_republicar' => $puedeRepublicar,
            'can_sync_price_ml' => $mlm && $price > 0 && MeliPublication::permiteActualizarPrecioStock($mlStatus),
            'queue_status' => $row?->status,
            'publish_error' => $row?->publish_error,
            'edit_url' => route('syscom.meli.edit', ['id' => $p->id]).'?'.http_build_query(array_filter([
                'page' => $listPage > 1 ? $listPage : null,
                'q' => $q !== '' ? $q : null,
                'cola' => $cola !== '' ? $cola : null,
            ])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function formatMeliSubStatus(mixed $subStatus): ?string
    {
        if (is_string($subStatus) && $subStatus !== '') {
            return $subStatus;
        }
        $arr = $this->decodeJsonToArray($subStatus);
        if ($arr === []) {
            return null;
        }

        return implode(', ', array_map('strval', $arr));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function meliBlockHintFromRaw(array $raw): string
    {
        $mods = $raw['moderations'] ?? null;
        if (! is_array($mods)) {
            return '';
        }

        return (string) ($mods['message'] ?? $mods['reason'] ?? '');
    }

    private function resolveUserQueue(User $user, SyscomProduct $product): SyscomMeliQueue
    {
        return SyscomMeliQueue::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'syscom_producto_id' => $product->syscom_producto_id,
            ],
            [
                'syscom_product_id' => $product->id,
                'status' => 'pending_price',
            ]
        );
    }

    /**
     * @return array{total: int, pendiente: int, publicado: int, error: int}
     */
    private function syscomMeliQueueCounts(int $userId): array
    {
        $base = SyscomMeliQueue::query()->where('user_id', $userId);

        return [
            'total' => (clone $base)->count(),
            'pendiente' => (clone $base)
                ->where('status', 'pending_price')
                ->where(function ($w) {
                    $w->whereNull('mlm')->orWhere('mlm', '');
                })
                ->count(),
            'publicado' => (clone $base)
                ->where(function ($w) {
                    $w->whereNotNull('mlm')->where('mlm', '!=', '')
                        ->orWhere('status', 'published');
                })
                ->count(),
            'error' => (clone $base)->where('status', 'error')->count(),
        ];
    }

    /**
     * Filtro de cola SYSCOM→ML (`?cola=pendiente|publicado|error|en_cola`).
     */
    private function applyColaFilter($query, int $userId, string $cola): void
    {
        $cola = strtolower($cola);
        if ($cola === '' || $cola === 'todos') {
            return;
        }

        $productIds = match ($cola) {
            'pendiente' => SyscomMeliQueue::query()
                ->where('user_id', $userId)
                ->where('status', 'pending_price')
                ->where(function ($w) {
                    $w->whereNull('mlm')->orWhere('mlm', '');
                })
                ->pluck('syscom_product_id'),
            'publicado' => SyscomMeliQueue::query()
                ->where('user_id', $userId)
                ->where(function ($w) {
                    $w->where(function ($q) {
                        $q->whereNotNull('mlm')->where('mlm', '!=', '');
                    })->orWhere('status', 'published');
                })
                ->pluck('syscom_product_id'),
            'error' => SyscomMeliQueue::query()
                ->where('user_id', $userId)
                ->where('status', 'error')
                ->pluck('syscom_product_id'),
            'en_cola' => SyscomMeliQueue::query()
                ->where('user_id', $userId)
                ->pluck('syscom_product_id'),
            default => collect(),
        };

        $ids = $productIds->unique()->values()->all();
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('id', $ids);
    }

    /**
     * Árbol de categorías ML México (API pública): raíz o hijos de `parent`.
     */
    public function meliCategoriesBrowse(Request $request, MeliPublishService $meli): JsonResponse
    {
        $parent = trim((string) $request->query('parent', ''));

        if ($parent === '') {
            $pack = $meli->getSiteRootCategories();
            $children = $pack['children'] ?? [];
            $err = $pack['error'] ?? null;

            return response()->json([
                'ok' => $err === null,
                'message' => $err,
                'node' => [
                    'id' => '',
                    'name' => 'México · categorías principales',
                    'path_from_root' => [],
                    'children' => $children,
                ],
            ]);
        }

        $node = $meli->getCategoryBrowseNode($parent);
        $err = $node['error'] ?? null;
        unset($node['error']);

        return response()->json([
            'ok' => $err === null,
            'message' => $err,
            'node' => $node,
        ]);
    }

    /**
     * Búsqueda por texto (domain_discovery; requiere cuenta ML vinculada).
     */
    public function meliCategoriesSearch(Request $request, MeliPublishService $meli): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $user = $request->user();
        if (! $user || ! $user->access_token) {
            return response()->json([
                'ok' => false,
                'message' => 'Vinculá Mercado Libre para buscar categorías.',
            ], 422);
        }

        try {
            $data = $meli->suggestCategories($user, (string) $request->query('q'), 8);

            return response()->json([
                'ok' => true,
                'results' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('syscom-ml meliCategoriesSearch', ['err' => $e->getMessage()]);

            $msg = $e->getMessage();
            $friendly = 'No se pudieron obtener sugerencias de Mercado Libre.';
            if (str_starts_with($msg, 'ML_ERROR:')) {
                $parts = explode(':', $msg, 3);
                $status = isset($parts[1]) ? (int) $parts[1] : 0;
                $body = $parts[2] ?? '';
                $decoded = json_decode($body, true);
                $detail = '';
                if (is_array($decoded)) {
                    $detail = trim((string) ($decoded['message'] ?? $decoded['error'] ?? ''));
                }
                if ($detail === '' && $body !== '') {
                    $detail = substr(strip_tags($body), 0, 220);
                }
                $tail = $detail !== '' ? ' '.$detail : '';
                $friendly = 'Mercado Libre rechazó la búsqueda de categorías (HTTP '.$status.').'.$tail;
            }

            return response()->json([
                'ok' => false,
                'message' => $friendly,
            ], 422);
        }
    }

    public function requestCatalogSync(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! $user->access_token) {
            return back()->with('error', 'Vinculá Mercado Libre antes de sincronizar el catálogo SYSCOM.');
        }

        SyncSyscomCatalogJob::dispatch($user->id)
            ->onConnection(config('queue.default', 'sync'));

        $sweepNote = config('syscom.default_sync_sweep', true)
            ? ' Incluye barrido a–z, marcas y categorías SYSCOM (solo stock Hermosillo). Puede tardar bastante.'
            : ' Solo búsqueda por defecto ("a"); para más cobertura activá SYSCOM_SYNC_SWEEP=true.';

        return back()->with('ok', 'Sincronización SYSCOM (sucursal '.config('syscom.sucursal_nombre', 'hermosillo').') encolada.'.$sweepNote);
    }

    /**
     * Importa desde la API SYSCOM (Hermosillo) lo que coincida con el texto buscado.
     * La API no devuelve todo el catálogo: hace falta buscar por modelo/marca como en syscom.mx.
     */
    public function importSearchFromSyscom(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! $user->access_token) {
            return back()->with('error', 'Vinculá Mercado Libre antes de importar desde SYSCOM.');
        }

        $validated = $request->validate([
            'q' => 'required|string|min:2|max:120',
            'cola' => 'nullable|string|max:32',
        ]);

        $q = trim((string) $validated['q']);
        $maxPages = (int) config('syscom.on_demand_import_max_pages', 50);

        try {
            $exit = Artisan::call('syscom:sync-products', [
                '--user_id' => (string) $user->id,
                '--busqueda' => $q,
                '--with-detail' => true,
                '--max-pages' => (string) $maxPages,
                '--no-progress' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('syscom.meli.import_search failed', ['q' => $q, 'err' => $e->getMessage()]);

            return back()->with('error', 'No se pudo consultar SYSCOM: '.$e->getMessage());
        }

        $output = trim(Artisan::output());
        if ($exit !== 0) {
            return back()->with('error', $output !== '' ? $output : 'Importación SYSCOM falló.');
        }

        $imported = SyscomProduct::query()
            ->where(function ($w) use ($q) {
                $w->where('modelo', 'like', "%{$q}%")
                    ->orWhere('titulo', 'like', "%{$q}%");
            })
            ->count();

        $params = ['q' => $q];
        if (! empty($validated['cola'])) {
            $params['cola'] = $validated['cola'];
        }

        $msg = $imported > 0
            ? "Importados desde SYSCOM (búsqueda «{$q}», sucursal ".config('syscom.sucursal_nombre', 'hermosillo')."): {$imported} coincidencia(s) en catálogo local."
            : "SYSCOM respondió a «{$q}» pero no quedaron filas locales; probá el modelo exacto o la marca (ej. EPCOM POWERLINE).";

        if ($output !== '') {
            Log::info('syscom.meli.import_search', ['q' => $q, 'output' => $output]);
        }

        return redirect()
            ->route('syscom.meli.index', $params)
            ->with($imported > 0 ? 'ok' : 'error', $msg);
    }

    public function publish(
        Request $request,
        int $id,
        SyscomMeliPublishService $pub,
        SyscomApiService $syscom,
        MeliPublishService $meli
    ): RedirectResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        if (! $user->access_token) {
            return back()->with('error', 'Falta vincular Mercado Libre.');
        }

        $request->validate([
            'category_id' => 'nullable|string|max:50',
            'official_store_mode' => 'nullable|in:marketmax,tobeauty,none',
            'price_scope' => 'nullable|in:llanta,par,juego4',
            'universal_code' => 'nullable|string|max:32',
        ]);

        $product = SyscomProduct::findOrFail($id);
        $priceScope = (string) ($request->input('price_scope', 'llanta'));

        $queue = SyscomMeliQueue::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'syscom_producto_id' => $product->syscom_producto_id,
            ],
            [
                'syscom_product_id' => $product->id,
                'status' => 'pending_price',
            ]
        );

        $categoryId = trim((string) $request->input('category_id', ''));
        $forceRepublishForCategory = $categoryId !== '';

        if ($queue->mlm) {
            $mlStatus = null;
            $mlPub = MeliPublication::query()
                ->where('user_id', $user->id)
                ->where('mlm', $queue->mlm)
                ->first();
            if ($mlPub) {
                $mlStatus = $mlPub->status;
            } else {
                try {
                    $item = $meli->getItem($user, (string) $queue->mlm);
                    $mlStatus = $item['status'] ?? null;
                    $sku = $pub->makeSku((int) $product->syscom_producto_id);
                    $meli->upsertPublication($user, $sku, $item);
                } catch (\Throwable) {
                    $mlStatus = null;
                }
            }

            $allowRepublish = ($queue->status === 'error')
                || MeliPublication::permiteRepublicarSegunEstadoMl($mlStatus)
                || $forceRepublishForCategory;

            if (! $allowRepublish) {
                $et = MeliPublication::etiquetaEstadoPublicacion($mlStatus) ?? $mlStatus;

                return back()->with(
                    'error',
                    'Este producto ya tiene una publicación en ML ('.$queue->mlm.'). Estado: '.$et.
                    '. Para corregir categoría, pegá el MLM correcto en «Categoría ML» (ej. MLM437575 cámaras, MLM439043 montaje solar) y volvé a republicar, o finalizá la publicación actual en Mercado Libre.'
                );
            }
        }

        $token = $syscom->getAccessToken();
        $branchName = (string) config('syscom.sucursal_nombre', 'hermosillo');
        $branchCode = $queue->branch_code ?: $syscom->resolveBranchCodeByName($token, $branchName);
        if (! $branchCode) {
            return back()->with('error', 'No se pudo resolver la sucursal SYSCOM "'.$branchName.'". Revisa credenciales o el nombre de sucursal.');
        }

        try {
            $detail = $syscom->getProduct($token, $product->syscom_producto_id, $branchCode);
        } catch (\Throwable $e) {
            return back()->with('error', 'Error leyendo producto en SYSCOM: '.$e->getMessage());
        }

        $exist = is_array($detail['existencia'] ?? null) ? $detail['existencia'] : [];
        $branchScoped = (bool) ($detail['__branch_scoped_existencia'] ?? false);
        $herm = SyscomHermosilloStock::fromProductDetail(
            $exist,
            $branchCode,
            $branchName,
            (int) ($detail['total_existencia'] ?? 0),
            $branchScoped
        );
        $product->stock_hermosillo = $herm;
        if (is_array($detail)) {
            $product->descripcion = (string) ($product->descripcion ?: ($detail['descripcion'] ?? ''));
            $item = is_array($product->raw_list) ? $product->raw_list : [];
            $prices = SyscomPrecioExtractor::fromProductLike($item, $detail);
            foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $pk) {
                if ((float) ($prices[$pk] ?? 0) > 0) {
                    $product->{$pk} = $prices[$pk];
                }
            }
            $product->raw_detail = $detail;
        }
        $product->save();

        $categoryId = trim((string) $request->input('category_id', ''));
        try {
            $res = $pub->publish(
                $user,
                $product,
                $branchCode,
                $categoryId !== '' ? $categoryId : null,
                (string) $request->input('official_store_mode', 'tobeauty'),
                $priceScope,
                (string) $request->input('universal_code', '')
            );
        } catch (\Throwable $e) {
            $queue->update([
                'branch_code' => $branchCode,
                'status' => 'error',
                'publish_error' => $e->getMessage(),
            ]);

            return back()->with('error', 'No se pudo publicar: '.$e->getMessage());
        }

        $queue->update([
            'branch_code' => $branchCode,
            'status' => 'published',
            'mlm' => $res['mlm'],
            'price_scope' => $priceScope,
            'publish_error' => null,
            'last_price_synced_at' => now(),
            'last_stock_synced_at' => now(),
        ]);

        $successMsg = 'Publicado en ML: '.$res['mlm'];
        if ($forceRepublishForCategory) {
            $successMsg .= '. Si la publicación anterior sigue activa en ML, finalizala manualmente para evitar duplicados.';
        }

        return back()->with('success', $successMsg);
    }

    public function refreshPublicationStatus(
        Request $request,
        MeliPublishService $meli,
        SyscomMeliPublishService $pub
    ): RedirectResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        if (! $user->access_token) {
            return back()->with('error', 'Vinculá Mercado Libre antes de actualizar estados.');
        }

        $q = trim((string) $request->get('q', ''));
        $cola = trim((string) $request->get('cola', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = SyscomProduct::query()->orderByDesc('id');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('titulo', 'like', "%{$q}%")
                    ->orWhere('modelo', 'like', "%{$q}%")
                    ->orWhere('marca', 'like', "%{$q}%");
            });
        }
        $this->applyColaFilter($query, (int) $user->id, $cola);

        $paginator = $query->paginate(20, ['*'], 'page', $page);
        $ids = $paginator->getCollection()->pluck('id')->all();

        $queues = SyscomMeliQueue::query()
            ->where('user_id', $user->id)
            ->whereIn('syscom_product_id', $ids)
            ->get()
            ->keyBy('syscom_product_id');

        $ok = 0;
        $failed = 0;
        $closedNotFound = 0;
        foreach ($paginator->getCollection() as $product) {
            $row = $queues->get($product->id);
            $mlm = $row?->mlm;
            if (! $mlm) {
                continue;
            }
            try {
                $updated = $meli->refreshStatus($user, (string) $mlm, $pub->makeSku((int) $product->syscom_producto_id));
                $ok++;
                if (($updated->status ?? '') === 'closed' && in_array('not_found', $updated->sub_status ?? [], true)) {
                    $closedNotFound++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('syscom.meli.refresh_status failed', [
                    'mlm' => $mlm,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        if ($ok === 0 && $failed === 0) {
            return back()->with('ok', 'En esta página no hay publicaciones ML (MLM vacío). Publicá primero o probá otra página.');
        }

        $msg = "Estados actualizados desde Mercado Libre: {$ok} publicación(es).";
        if ($closedNotFound > 0) {
            $msg .= " {$closedNotFound} ya no existe(n) en ML (marcada(s) como CERRADA).";
        }
        if ($failed > 0) {
            $msg .= " No se pudo consultar {$failed} (token ML, MLM inválido o rate limit).";
        }

        return back()->with('ok', $msg);
    }

    /**
     * Consulta detalle SYSCOM para filas de esta página sin precio USD (costo / precio ML en tabla).
     */
    public function refreshPricesOnPage(
        Request $request,
        SyscomApiService $api,
        SyscomProductPricingService $pricing
    ): RedirectResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $q = trim((string) $request->get('q', ''));
        $cola = trim((string) $request->get('cola', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = SyscomProduct::query()->orderByDesc('id');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('titulo', 'like', "%{$q}%")
                    ->orWhere('modelo', 'like', "%{$q}%")
                    ->orWhere('marca', 'like', "%{$q}%");
            });
        }
        $this->applyColaFilter($query, (int) $user->id, $cola);

        $paginator = $query->paginate(20, ['*'], 'page', $page);

        try {
            $token = $api->getAccessToken();
        } catch (\Throwable $e) {
            return back()->with('error', 'SYSCOM API: '.$e->getMessage());
        }

        $branchName = (string) config('syscom.sucursal_nombre', 'hermosillo');
        $branchCode = $api->resolveBranchCodeByName($token, $branchName);
        if (! $branchCode) {
            return back()->with('error', "No se encontró sucursal SYSCOM: {$branchName}");
        }

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $sleepMs = max(0, (int) config('syscom.backfill_sleep_ms', 300));

        foreach ($paginator->getCollection() as $product) {
            try {
                if ($pricing->costoMxParaFormula($product) > 0) {
                    $skipped++;

                    continue;
                }
            } catch (\Throwable) {
            }

            try {
                $detail = $api->getProduct($token, (int) $product->syscom_producto_id, $branchCode);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('syscom.meli.refresh_prices_page', [
                    'syscom_producto_id' => $product->syscom_producto_id,
                    'err' => $e->getMessage(),
                ]);
                continue;
            }

            if (! is_array($detail)) {
                $failed++;

                continue;
            }

            $item = is_array($product->raw_list) ? $product->raw_list : [];
            $prices = SyscomPrecioExtractor::fromProductLike($item, $detail);
            $changed = false;
            foreach (['precio_lista', 'precio_especial', 'precio_descuento'] as $pk) {
                if ((float) ($prices[$pk] ?? 0) > 0) {
                    $product->{$pk} = $prices[$pk];
                    $changed = true;
                }
            }

            if ($changed) {
                $product->raw_detail = $detail;
                $product->last_synced_at = now();
                $product->save();
                $ok++;
            } else {
                $failed++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        if ($ok === 0 && $failed === 0 && $skipped > 0) {
            return back()->with('ok', 'En esta página los productos ya tenían costo calculable.');
        }

        $msg = "Precios SYSCOM actualizados: {$ok}.";
        if ($skipped > 0) {
            $msg .= " Ya tenían costo: {$skipped}.";
        }
        if ($failed > 0) {
            $msg .= " Sin precio en API o error: {$failed}.";
        }

        return back()->with($ok > 0 ? 'success' : 'error', $msg);
    }

    /**
     * Envía precio y stock calculados (o MANUAL en cola) a un ítem ya publicado en ML.
     */
    public function syncPriceToMl(
        Request $request,
        int $id,
        SyscomMeliPublishService $publishService
    ): RedirectResponse {
        try {
            $user = $request->user();
            if (! $user) {
                abort(401);
            }
            if (! $user->access_token) {
                return back()->with('error', 'Vinculá Mercado Libre antes de sincronizar precios.');
            }

            $product = SyscomProduct::query()->findOrFail($id);
            $queue = $this->resolveUserQueue($user, $product);
            $mlm = trim((string) ($queue->mlm ?? ''));
            if ($mlm === '') {
                return back()->with('error', 'Este producto no tiene MLM. Publicalo primero o pegá el MLM en Editar.');
            }

            $res = $publishService->syncPublishedItemFromProduct($user, $product, $mlm, null, $queue);

            return back()->with(
                'success',
                sprintf(
                    'Mercado Libre actualizado (%s): precio $%s MXN, stock %d u.',
                    $res['mlm'],
                    number_format((float) $res['price'], 2, '.', ','),
                    (int) $res['stock']
                )
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'price_mode') || str_contains($e->getMessage(), 'price_locked_at')) {
                return back()->with('error', 'Falta migración en el servidor: ejecutá php artisan migrate');
            }
            Log::error('syscom.meli.sync_price db', ['product_id' => $id, 'err' => $e->getMessage()]);

            return back()->with('error', 'Error de base de datos al sincronizar. Revisá storage/logs/laravel.log');
        } catch (\Throwable $e) {
            Log::warning('syscom.meli.sync_price failed', [
                'product_id' => $id,
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', MeliPublishService::friendlyMlErrorMessage($e->getMessage()));
        }
    }

    /**
     * Sincroniza precio + stock en ML para todas las filas de la página actual que tengan MLM.
     */
    public function syncPricesOnPage(
        Request $request,
        SyscomMeliPublishService $publishService
    ): RedirectResponse {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }
        if (! $user->access_token) {
            return back()->with('error', 'Vinculá Mercado Libre antes de sincronizar precios.');
        }

        $q = trim((string) $request->get('q', ''));
        $cola = trim((string) $request->get('cola', ''));
        $page = max(1, (int) $request->input('page', 1));

        $query = SyscomProduct::query()->orderByDesc('id');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('titulo', 'like', "%{$q}%")
                    ->orWhere('modelo', 'like', "%{$q}%")
                    ->orWhere('marca', 'like', "%{$q}%");
            });
        }
        $this->applyColaFilter($query, (int) $user->id, $cola);

        $paginator = $query->paginate(20, ['*'], 'page', $page);
        $ids = $paginator->getCollection()->pluck('id')->all();

        $queues = SyscomMeliQueue::query()
            ->where('user_id', $user->id)
            ->whereIn('syscom_product_id', $ids)
            ->get()
            ->keyBy('syscom_product_id');

        $ok = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($paginator->getCollection() as $product) {
            $queue = $queues->get($product->id);
            $mlm = trim((string) ($queue?->mlm ?? ''));
            if ($mlm === '') {
                $skipped++;

                continue;
            }

            try {
                $publishService->syncPublishedItemFromProduct($user, $product, $mlm, null, $queue);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = ($product->titulo ? mb_substr((string) $product->titulo, 0, 40).'…' : 'ID '.$product->id)
                        .': '.MeliPublishService::friendlyMlErrorMessage($e->getMessage());
                }
                Log::warning('syscom.meli.sync_prices_page failed', [
                    'product_id' => $product->id,
                    'mlm' => $mlm,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        if ($ok === 0 && $failed === 0) {
            return back()->with('ok', 'En esta página no hay productos con MLM para sincronizar precio.');
        }

        $msg = "Precios/stock enviados a Mercado Libre: {$ok} publicación(es).";
        if ($skipped > 0) {
            $msg .= " Omitidos sin MLM: {$skipped}.";
        }
        if ($failed > 0) {
            $msg .= " Fallos: {$failed}.";
            if ($errors !== []) {
                $msg .= ' '.implode(' | ', $errors);
            }
        }

        return back()->with($failed > 0 && $ok === 0 ? 'error' : 'success', $msg);
    }
}
