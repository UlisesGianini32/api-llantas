<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMeliStockAndPriceJob;
use App\Models\Llanta;
use App\Models\MeliOrder;
use App\Models\ProductoCompuesto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->search);

        // ✅ Ordenamiento (para STOCK BAJO)
        $sort = $request->get('sort', 'stock');
        $dir  = $request->get('dir', 'asc');
        $dir  = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';

        // ✅ Solo columnas permitidas (evita SQL injection)
        $allowedSort = [
            'sku', 'marca', 'medida', 'descripcion',
            'costo', 'precio_ML', 'title_familyname', 'MLM', 'stock',
        ];

        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'stock';
        }

        // ✅ función reusable para aplicar búsqueda por SKU / Título / MLM
        $applySearch = function ($q) use ($search) {
            if ($search !== '') {
                $q->where(function ($qq) use ($search) {
                    $qq->where('sku', 'like', "%{$search}%")
                        ->orWhere('title_familyname', 'like', "%{$search}%")
                        ->orWhere('MLM', 'like', "%{$search}%");
                });
            }
        };

        $stockBajo = Llanta::query()
            ->where('stock', '<=', 4)
            ->when($search !== '', $applySearch)
            ->select(
                'id',
                'sku',
                'marca',
                'medida',
                'descripcion',
                'costo',
                'precio_ML',
                'title_familyname',
                'MLM',
                'stock'
            )
            ->orderBy($sort, $dir)
            ->paginate(10)
            ->withQueryString();

        $today = now()->toDateString();
        $syscomSyncOkToday = MeliOrder::query()
            ->whereDate('syscom_order_synced_at', $today)
            ->count();
        $syscomSyncSkipToday = MeliOrder::query()
            ->whereDate('updated_at', $today)
            ->where('syscom_order_error', 'like', 'SKIP_NO_SYSCOM_ITEMS:%')
            ->count();
        $syscomSyncErrToday = MeliOrder::query()
            ->whereDate('updated_at', $today)
            ->whereNotNull('syscom_order_error')
            ->where('syscom_order_error', 'not like', 'SKIP_NO_SYSCOM_ITEMS:%')
            ->count();

        $syscomPedidosRecientes = MeliOrder::query()
            ->whereNotNull('syscom_order_folio')
            ->where('syscom_order_folio', '!=', '')
            ->orderByDesc('syscom_order_synced_at')
            ->limit(40)
            ->get([
                'order_id',
                'status',
                'syscom_order_folio',
                'syscom_order_synced_at',
                'syscom_order_cancelled_at',
                'syscom_order_cancel_error',
            ])
            ->map(static function (MeliOrder $o) {
                $status = mb_strtolower(trim((string) ($o->status ?? '')));
                $mlCancelled = in_array($status, ['cancelled', 'canceled', 'invalid', 'expired'], true);
                $syscomCancelled = $o->syscom_order_cancelled_at !== null;

                return [
                    'order_id' => (string) $o->order_id,
                    'referencia_ml' => 'ML-' . $o->order_id,
                    'syscom_order_folio' => (string) $o->syscom_order_folio,
                    'syscom_order_synced_at' => $o->syscom_order_synced_at?->format('d/m/Y H:i'),
                    'ml_cancelled' => $mlCancelled,
                    'syscom_cancelled' => $syscomCancelled,
                    'syscom_order_cancelled_at' => $o->syscom_order_cancelled_at?->format('d/m/Y H:i'),
                    'syscom_order_cancel_error' => $syscomCancelled
                        ? null
                        : ($mlCancelled ? (string) ($o->syscom_order_cancel_error ?? '') : null),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Dashboard/Index', [
            // ======================
            // TOTALES (NO se filtran)
            // ======================
            'totalLlantas' => Llanta::count(),
            'totalCompuestos' => ProductoCompuesto::count(),
            'existenciasLlantas' => (int) Llanta::sum('stock'),
            'llantasSinStock' => Llanta::where('stock', 0)->count(),
            'compuestosSinStock' => ProductoCompuesto::where('stock', 0)->count(),
            'llantasConStockSaludable' => Llanta::where('stock', '>', 4)->count(),

            // ======================
            // VALORES
            // ======================
            'valorInventarioLlantas' => (float) Llanta::sum(DB::raw('costo * stock')),
            'valorInventarioCompuestos' => (float) ProductoCompuesto::sum(DB::raw('costo * stock')),
            'syscomSyncOkToday' => $syscomSyncOkToday,
            'syscomSyncSkipToday' => $syscomSyncSkipToday,
            'syscomSyncErrToday' => $syscomSyncErrToday,
            'syscomPedidosRecientes' => $syscomPedidosRecientes,

            // ======================
            // FILTROS / ESTADO UI
            // ======================
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'dir' => $dir,
            ],

            // ======================
            // STOCK BAJO (FILTRABLE + ORDENABLE)
            // ======================
            'stockBajo' => [
                'data' => $stockBajo->items(),
                'current_page' => $stockBajo->currentPage(),
                'last_page' => $stockBajo->lastPage(),
                'per_page' => $stockBajo->perPage(),
                'total' => $stockBajo->total(),
                'from' => $stockBajo->firstItem(),
                'to' => $stockBajo->lastItem(),
                'links' => $stockBajo->linkCollection(),
            ],
        ]);
    }

    public function stats()
    {
        return response()->json([
            'totales' => [
                'llantas' => Llanta::count(),
                'compuestos' => ProductoCompuesto::count(),
                'existencias_llantas' => Llanta::sum('stock'),
            ],
            'valores' => [
                'llantas' => Llanta::sum(DB::raw('costo * stock')),
                'pares' => ProductoCompuesto::where('tipo', 'par')->sum(DB::raw('costo * stock')),
                'juego4' => ProductoCompuesto::where('tipo', 'juego4')->sum(DB::raw('costo * stock')),
            ],
        ]);
    }

    /**
     * ✅ Poner stock en 0 (llantas + producto_compuestos)
     */
    public function zeroStock(Request $request)
    {
        $userId = auth()->id();
        $ip = $request->ip();
        $t0 = microtime(true);

        Log::info('[DASHBOARD] zeroStock START', [
            'user_id' => $userId,
            'ip' => $ip,
            'url' => $request->fullUrl(),
        ]);

        try {
            DB::transaction(function () {
                Llanta::query()->update(['stock' => 0]);
                ProductoCompuesto::query()->update(['stock' => 0]);
            });

            $ms = (int) ((microtime(true) - $t0) * 1000);

            Log::info('[DASHBOARD] zeroStock OK', [
                'user_id' => $userId,
                'ip' => $ip,
                'duration_ms' => $ms,
            ]);

            return back()->with('success', 'Stock puesto en 0 para llantas y productos compuestos.');
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $t0) * 1000);

            Log::error('[DASHBOARD] zeroStock FAIL', [
                'user_id' => $userId,
                'ip' => $ip,
                'duration_ms' => $ms,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Error al poner stock en 0: ' . $e->getMessage());
        }
    }

    /**
     * 🔄 Refrescar token MercadoLibre (php artisan meli:refresh-token)
     */
    public function refreshMeliToken(Request $request)
    {
        $userId = auth()->id();
        $ip = $request->ip();
        $t0 = microtime(true);

        Log::info('[DASHBOARD] refreshMeliToken START', [
            'user_id' => $userId,
            'ip' => $ip,
            'url' => $request->fullUrl(),
        ]);

        try {
            Artisan::call('meli:refresh-token');
            $output = Artisan::output();

            $ms = (int) ((microtime(true) - $t0) * 1000);

            Log::info('[DASHBOARD] refreshMeliToken OK', [
                'user_id' => $userId,
                'ip' => $ip,
                'duration_ms' => $ms,
                'artisan_output' => $output,
            ]);

            return back()->with('success', 'Token de MercadoLibre refrescado correctamente.');
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $t0) * 1000);

            Log::error('[DASHBOARD] refreshMeliToken FAIL', [
                'user_id' => $userId,
                'ip' => $ip,
                'duration_ms' => $ms,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Error al refrescar token: ' . $e->getMessage());
        }
    }

    /**
     * ▶️ Sync manual — AHORA se manda a QUEUE para evitar 504
     */
    public function syncMeliManual(Request $request)
    {
        $userId = auth()->id();
        $ip = $request->ip();

        Log::info('[DASHBOARD] syncMeliManual DISPATCH', [
            'user_id' => $userId,
            'ip' => $ip,
            'url' => $request->fullUrl(),
        ]);

        try {
            SyncMeliStockAndPriceJob::dispatch();

            return back()->with('success', 'Sincronización iniciada en segundo plano ✅. Revisa logs para ver el avance.');
        } catch (\Throwable $e) {
            Log::error('[DASHBOARD] syncMeliManual DISPATCH FAIL', [
                'user_id' => $userId,
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'No se pudo iniciar la sincronización: ' . $e->getMessage());
        }
    }
}