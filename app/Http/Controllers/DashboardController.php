<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMeliStockAndPriceJob;
use App\Models\Llanta;
use App\Models\MeliClaim;
use App\Models\MeliOrder;
use App\Models\MeliQuestion;
use App\Models\PosSale;
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

        // ✅ Ordenamiento (para Stock Crítico)
        $sort = $request->get('sort', 'stock');
        $dir  = $request->get('dir', 'asc');
        $dir  = in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc';

        // ✅ Solo columnas permitidas
        $allowedSort = [
            'sku', 'marca', 'medida', 'descripcion',
            'costo', 'precio_ML', 'title_familyname', 'MLM', 'stock',
        ];

        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'stock';
        }

        // ✅ Búsqueda por SKU, título, descripción, marca o MLM
        $applySearch = function ($q) use ($search) {
            if ($search !== '') {
                $q->where(function ($qq) use ($search) {
                    $qq->where('sku', 'like', "%{$search}%")
                        ->orWhere('title_familyname', 'like', "%{$search}%")
                        ->orWhere('descripcion', 'like', "%{$search}%")
                        ->orWhere('marca', 'like', "%{$search}%")
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
            ->paginate(15)
            ->withQueryString();

        $today = now()->toDateString();

        // 1. VENTAS PUNTO DE VENTA (POS) HOY
        $posTodayQuery = PosSale::query()
            ->whereDate('created_at', $today)
            ->where('status', '!=', PosSale::STATUS_CANCELLED);

        $posTotalToday = (float) (clone $posTodayQuery)->sum('total');
        $posOrdersToday = (int) (clone $posTodayQuery)->count();

        $posCashToday = (float) (clone $posTodayQuery)->where('payment_method', PosSale::PAYMENT_CASH)->sum('total');
        $posCardToday = (float) (clone $posTodayQuery)->where('payment_method', PosSale::PAYMENT_CARD)->sum('total');
        $posTransferToday = (float) (clone $posTodayQuery)->where('payment_method', PosSale::PAYMENT_TRANSFER)->sum('total');
        $posCreditToday = (float) (clone $posTodayQuery)->where('payment_method', PosSale::PAYMENT_CREDIT)->sum('total');

        // 2. VENTAS MERCADO LIBRE HOY
        $meliOrdersTodayQuery = MeliOrder::query()
            ->whereDate('created_at', $today)
            ->whereNotIn('status', ['cancelled', 'canceled', 'invalid']);

        $meliOrdersToday = (int) (clone $meliOrdersTodayQuery)->count();

        $meliSalesTotalToday = (float) DB::table('meli_order_items')
            ->join('meli_orders', 'meli_order_items.meli_order_id', '=', 'meli_orders.id')
            ->whereDate('meli_orders.created_at', $today)
            ->whereNotIn('meli_orders.status', ['cancelled', 'canceled', 'invalid'])
            ->sum(DB::raw('meli_order_items.unit_price * meli_order_items.quantity'));

        if ($meliSalesTotalToday <= 0 && $meliOrdersToday > 0) {
            $orders = (clone $meliOrdersTodayQuery)->get(['raw']);
            foreach ($orders as $ord) {
                $meliSalesTotalToday += (float) data_get($ord->raw, 'total_amount', 0);
            }
        }

        // Totales combinados
        $totalSalesToday = $posTotalToday + $meliSalesTotalToday;
        $totalOrdersToday = $posOrdersToday + $meliOrdersToday;
        $avgTicketToday = $totalOrdersToday > 0 ? round($totalSalesToday / $totalOrdersToday, 2) : 0.0;

        // 3. FULFILLMENT / DESPACHO DE PEDIDOS
        $pendingDispatch = MeliOrder::query()
            ->whereNotIn('status', ['cancelled', 'canceled', 'invalid'])
            ->where(function ($q) {
                $q->whereNull('shipping_status')
                  ->orWhereIn('shipping_status', ['pending', 'handling', 'ready_to_ship']);
            })
            ->count();

        $inTransit = MeliOrder::query()
            ->where('shipping_status', 'shipped')
            ->count();

        $deliveredToday = MeliOrder::query()
            ->where('shipping_status', 'delivered')
            ->whereDate('updated_at', $today)
            ->count();

        // 4. ATENCIÓN AL CLIENTE / REPUTACIÓN
        $unansweredQuestions = MeliQuestion::query()
            ->where('status', 'UNANSWERED')
            ->count();

        $openClaims = MeliClaim::query()
            ->where('stage', '!=', 'closed')
            ->count();

        // 5. INVENTARIO & CATÁLOGO SBS
        $totalProducts = (int) Llanta::count();
        $totalCombos = (int) ProductoCompuesto::count();
        $totalPieces = (int) Llanta::sum('stock');
        $outOfStock = (int) Llanta::where('stock', 0)->count();
        $criticalStock = (int) Llanta::where('stock', '>', 0)->where('stock', '<=', 4)->count();
        $healthyStock = (int) Llanta::where('stock', '>', 4)->count();

        $inventoryValueCost = (float) Llanta::sum(DB::raw('costo * stock'));
        $combosTheoreticalValue = (float) ProductoCompuesto::sum(DB::raw('costo * stock'));

        return Inertia::render('Dashboard/Index', [
            // Métricas Principales de Negocio E-commerce
            'ecommerce' => [
                'totalSalesToday' => $totalSalesToday,
                'totalOrdersToday' => $totalOrdersToday,
                'avgTicketToday' => $avgTicketToday,
                'pos' => [
                    'totalToday' => $posTotalToday,
                    'ordersToday' => $posOrdersToday,
                    'cash' => $posCashToday,
                    'card' => $posCardToday,
                    'transfer' => $posTransferToday,
                    'credit' => $posCreditToday,
                ],
                'meli' => [
                    'totalToday' => $meliSalesTotalToday,
                    'ordersToday' => $meliOrdersToday,
                    'pendingDispatch' => $pendingDispatch,
                    'inTransit' => $inTransit,
                    'deliveredToday' => $deliveredToday,
                ],
                'support' => [
                    'unansweredQuestions' => $unansweredQuestions,
                    'openClaims' => $openClaims,
                ],
            ],

            // Inventario y Catálogo SBS
            'catalog' => [
                'totalProducts' => $totalProducts,
                'totalCombos' => $totalCombos,
                'totalPieces' => $totalPieces,
                'outOfStock' => $outOfStock,
                'criticalStock' => $criticalStock,
                'healthyStock' => $healthyStock,
                'inventoryValueCost' => $inventoryValueCost,
                'combosTheoreticalValue' => $combosTheoreticalValue,
            ],

            // Filtros / Estado UI
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'dir' => $dir,
            ],

            // Alertas de Reabastecimiento (Stock Crítico)
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
                'productos' => Llanta::count(),
                'compuestos' => ProductoCompuesto::count(),
                'existencias' => Llanta::sum('stock'),
            ],
            'valores' => [
                'catalogo' => Llanta::sum(DB::raw('costo * stock')),
                'compuestos' => ProductoCompuesto::sum(DB::raw('costo * stock')),
            ],
        ]);
    }

    /**
     * Poner stock en 0 (Mantenimiento)
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

            return back()->with('success', 'Stock puesto en 0 para catálogo y productos compuestos.');
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
     * Refrescar token MercadoLibre
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
     * Sync manual Meli
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