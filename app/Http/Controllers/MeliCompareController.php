<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Jobs\RunMeliCompareSync;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;
use Inertia\Inertia;

class MeliCompareController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $perPage = (int) $request->get('per_page', 25);
        $perPage = in_array($perPage, [10,25,50,100,200], true) ? $perPage : 25;

        // ==========================================
        // ✅ CONTADORES: MLM únicos por tipo
        // - Si un SKU tiene varios MLM -> TODOS cuentan
        // - DISTINCT por mlm evita duplicados del mismo MLM
        // ==========================================
        $mlmLlantasCount = DB::table('meli_publications as mp')
            ->join('llantas as l', 'l.sku', '=', 'mp.sku')
            ->whereNotNull('mp.mlm')
            ->where('mp.mlm', '!=', '')
            ->distinct('mp.mlm')
            ->count('mp.mlm');

        $mlmCompuestosCount = DB::table('meli_publications as mp')
            ->join('producto_compuestos as pc', 'pc.sku', '=', 'mp.sku')
            ->whereNotNull('mp.mlm')
            ->where('mp.mlm', '!=', '')
            ->distinct('mp.mlm')
            ->count('mp.mlm');

        // ==========================
        // Base query: productos publicados (tabla products)
        // ==========================
        $productsQ = Product::query();

        if ($search !== '') {
            $productsQ->where(function ($q) use ($search) {
                $q->where('ml', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('category_name', 'like', "%{$search}%");
            });
        }

        $products = $productsQ
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $pageMlms = collect($products->items())->pluck('ml')->filter()->values();

        // ==========================
        // Mapas del sistema (llantas + compuestos)
        // ==========================
        $systemSkus = DB::table('llantas')->pluck('sku')->merge(
            DB::table('producto_compuestos')->pluck('sku')
        )->unique()->values();

        $systemSkusSet = array_fill_keys($systemSkus->all(), true);

        $pubsByMlm = DB::table('meli_publications')
            ->select('mlm', 'sku', 'status', 'permalink', 'updated_at')
            ->when($pageMlms->count() > 0, fn($q) => $q->whereIn('mlm', $pageMlms))
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('mlm');

        $missingInSystem = [];
        $skuMismatch = [];
        $dupByMlm = [];

        foreach ($products->items() as $p) {
            $mlm = (string) $p->ml;
            if ($mlm === '') continue;

            $pubs = $pubsByMlm->get($mlm, collect());
            $pubSkus = $pubs->pluck('sku')->filter()->unique()->values();

            // C) duplicados por MLM (mismo MLM con varios SKUs)
            if ($pubSkus->count() >= 2) {
                $dupByMlm[] = [
                    'product' => $p,
                    'pub_skus' => $pubSkus->all(),
                    'pubs' => $pubs,
                ];
            }

            // A) faltante (si no hay pubs)
            if ($pubs->count() === 0) {
                $missingInSystem[] = [
                    'product' => $p,
                    'reason' => 'No existe en meli_publications (sistema no lo conoce)',
                    'pubs' => $pubs,
                ];
                continue;
            }

            $anySkuExistsInSystem = $pubSkus->contains(fn($sku) => isset($systemSkusSet[$sku]));

            if (!$anySkuExistsInSystem) {
                $missingInSystem[] = [
                    'product' => $p,
                    'reason' => 'Existe en meli_publications, pero el/los SKU(s) no existen en llantas ni compuestos',
                    'pubs' => $pubs,
                ];
            }

            // B) SKU mismatch
            $mlSku = trim((string) $p->sku);
            $mainPubSku = trim((string) optional($pubs->first())->sku);

            if ($mainPubSku !== '') {
                if ($mlSku === '' || strcasecmp($mlSku, $mainPubSku) !== 0) {
                    $skuMismatch[] = [
                        'product' => $p,
                        'ml_sku' => $mlSku,
                        'sys_sku' => $mainPubSku,
                        'pubs' => $pubs,
                    ];
                }
            }
        }

        $uid = auth()->id();
        $running = cache("ml_compare:running:user:{$uid}", false);
        $lastRun = cache("ml_compare:last_run_at:user:{$uid}");
        $lastRes = cache("ml_compare:last_result:user:{$uid}");

        return Inertia::render('Ml/Compare', [
            'products' => $products,
            'missingInSystem' => $missingInSystem,
            'skuMismatch' => $skuMismatch,
            'dupByMlm' => $dupByMlm,
            'running' => (bool) $running,
            'lastRun' => $lastRun,
            'lastRes' => is_array($lastRes) ? $lastRes : null,
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
            ],

            'mlmLlantasCount' => $mlmLlantasCount,
            'mlmCompuestosCount' => $mlmCompuestosCount,
        ]);
    }

    public function run(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return back()->with('error', 'Usuario no autenticado.');
        }

        $runningKey = "ml_compare:running:user:{$user->id}";

        if (Cache::get($runningKey)) {
            return back()->with('error', 'Ya hay una sincronización corriendo. Espera a que termine.');
        }

        Cache::put($runningKey, true, now()->addMinutes(30));

        RunMeliCompareSync::dispatch($user->id);

        return back()->with('success', 'Sincronización iniciada en background. Refresca en un momento.');
    }
}