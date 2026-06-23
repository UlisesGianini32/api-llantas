<?php

namespace App\Http\Controllers;

use App\Models\ProductoCompuesto;
use App\Models\PriceRule;
use App\Services\FormulaEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductoCompuestoController extends Controller
{
    public function indexWeb(Request $request): Response
    {
        $search = trim((string) $request->get('search', ''));

        $allowedPerPage = [10, 25, 50, 100, 200];
        $perPage = (int) $request->get('per_page', 25);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 25;
        }

        $sort = (string) $request->get('sort', 'id');
        $dir = strtolower((string) $request->get('dir', 'desc'));
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc';

        $allowedSort = [
            'id',
            'sku',
            'descripcion',
            'costo',
            'precio_ML',
            'title_familyname',
            'MLM',
            'stock',
            'meli_pubs_count',
        ];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $mlStatus = (string) $request->get('ml_status', 'all');
        $allowedStatus = ['all', 'no_publicada', 'activa', 'pausada', 'en_revision'];
        if (!in_array($mlStatus, $allowedStatus, true)) {
            $mlStatus = 'all';
        }

        $query = ProductoCompuesto::query()
            ->with('llanta')
            ->withCount([
                'meliPublications as meli_pubs_count',
            ])
            ->with([
                'meliPublications' => function ($qq) {
                    $qq->select('id', 'sku', 'mlm', 'status', 'permalink', 'created_at')
                        ->orderByDesc('id')
                        ->limit(20);
                }
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('title_familyname', 'like', "%{$search}%")
                    ->orWhere('descripcion', 'like', "%{$search}%");
            })->orWhereHas('meliPublications', function ($mp) use ($search) {
                $mp->where('mlm', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%");
            });
        }

        if ($mlStatus !== 'all') {
            if ($mlStatus === 'no_publicada') {
                $query->whereDoesntHave('meliPublications');
            }

            if ($mlStatus === 'activa') {
                $query->whereHas('meliPublications', function ($q) {
                    $q->where('status', 'active');
                });
            }

            if ($mlStatus === 'pausada') {
                $query->whereHas('meliPublications', function ($q) {
                    $q->where('status', 'paused');
                });
            }

            if ($mlStatus === 'en_revision') {
                $query->whereHas('meliPublications', function ($q) {
                    $q->where('status', 'under_review');
                });
            }
        }

        if ($sort === 'meli_pubs_count') {
            $query->orderBy('meli_pubs_count', $dir)->orderBy('id', 'desc');
        } else {
            $query->orderBy($sort, $dir);
        }

        $compuestos = $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($compuesto) {
                $pubs = $compuesto->meliPublications ?? collect();
                $pubsCount = (int) ($compuesto->meli_pubs_count ?? 0);
                $latestPub = $pubs->first();

                return [
                    'id' => $compuesto->id,
                    'sku' => $compuesto->sku,
                    'tipo' => $compuesto->tipo,
                    'stock' => (int) ($compuesto->stock ?? 0),
                    'descripcion' => $compuesto->descripcion,
                    'title_familyname' => $compuesto->title_familyname,
                    'costo' => (float) ($compuesto->costo ?? 0),
                    'precio_ML' => (float) ($compuesto->precio_ML ?? 0),
                    'MLM' => $compuesto->MLM,

                    'marca' => $compuesto->llanta->marca ?? '—',
                    'medida' => $compuesto->llanta->medida ?? '—',

                    'meli_pubs_count' => $pubsCount,

                    'ml_status_key' => $this->resolveMlStatusKey($latestPub, $pubsCount),
                    'ml_status' => $this->resolveMlStatusLabel($latestPub, $pubsCount),

                    'publications' => $pubs->map(function ($p) {
                        return [
                            'id' => $p->id,
                            'mlm' => $p->mlm,
                            'status' => $p->status,
                            'permalink' => $p->permalink,
                        ];
                    })->values(),

                    'latest_publication' => $latestPub ? [
                        'id' => $latestPub->id,
                        'mlm' => $latestPub->mlm,
                        'status' => $latestPub->status,
                        'permalink' => $latestPub->permalink,
                    ] : null,

                    'actions' => [
                        'edit_url' => route('productos.edit', [
                            'id' => $compuesto->id,
                        ]),
                        'publish_form_url' => route('productos.ml.publish.form', $compuesto->id),
                        'republish_url' => route('productos.ml.republish', $compuesto->id),
                        'refresh_status_url' => $latestPub ? route('ml.publications.refresh', $latestPub->id) : null,
                    ],
                ];
            });

        $counts = [
            'all' => ProductoCompuesto::count(),
            'no_publicada' => ProductoCompuesto::whereDoesntHave('meliPublications')->count(),
            'activa' => ProductoCompuesto::whereHas('meliPublications', fn ($q) => $q->where('status', 'active'))->count(),
            'pausada' => ProductoCompuesto::whereHas('meliPublications', fn ($q) => $q->where('status', 'paused'))->count(),
            'en_revision' => ProductoCompuesto::whereHas('meliPublications', fn ($q) => $q->where('status', 'under_review'))->count(),
        ];

        return Inertia::render('ProductosCompuestos/Index', [
            'compuestos' => $compuestos,
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
                'sort' => $sort,
                'dir' => $dir,
                'ml_status' => $mlStatus,
            ],
            'tabCounts' => $counts,
        ]);
    }

    public function editWeb(Request $request, $id): \Inertia\Response
{
    $compuesto = ProductoCompuesto::with([
        'llanta',
        'meliPublications' => function ($q) {
            $q->select('id', 'sku', 'mlm', 'status', 'permalink', 'created_at')
              ->orderByDesc('id');
        },
    ])->findOrFail($id);

    return \Inertia\Inertia::render('ProductosCompuestos/Edit', [
        'compuesto' => [
            'id' => $compuesto->id,
            'sku' => $compuesto->sku,
            'tipo' => $compuesto->tipo,
            'stock' => (int) ($compuesto->stock ?? 0),
            'descripcion' => $compuesto->descripcion,
            'title_familyname' => $compuesto->title_familyname,
            'costo' => (float) ($compuesto->costo ?? 0),
            'precio_ML' => (float) ($compuesto->precio_ML ?? 0),
            'MLM' => $compuesto->MLM,
            'price_mode' => $compuesto->price_mode ?? 'auto',
            'llanta' => $compuesto->llanta ? [
                'id' => $compuesto->llanta->id,
                'sku' => $compuesto->llanta->sku,
                'marca' => $compuesto->llanta->marca,
                'medida' => $compuesto->llanta->medida,
                'costo' => (float) ($compuesto->llanta->costo ?? 0),
            ] : null,
            'meli_publications' => $compuesto->meliPublications->map(function ($pub) {
                return [
                    'id' => $pub->id,
                    'mlm' => $pub->mlm,
                    'status' => $pub->status,
                    'permalink' => $pub->permalink,
                    'created_at' => $pub->created_at?->format('Y-m-d H:i:s'),
                ];
            })->values(),
        ],
        'filters' => [
            'page' => (int) $request->query('page', 1),
            'search' => (string) $request->query('search', ''),
            'sort' => $request->query('sort', 'id'),
            'dir' => $request->query('dir', 'desc'),
            'per_page' => (int) $request->query('per_page', 25),
            'ml_status' => (string) $request->query('ml_status', 'all'),
        ],
    ]);
}

public function updateWeb(Request $request, $id)
{
    $compuesto = ProductoCompuesto::with('llanta')->findOrFail($id);

    $request->validate([
        'descripcion' => 'nullable|string',
        'title_familyname' => 'required|string|max:255',
        'costo' => 'nullable|numeric|min:0',
        'precio_ML' => 'nullable|numeric|min:0',
        'MLM' => 'nullable|string|max:255',

        'page' => 'nullable|integer|min:1',
        'search' => 'nullable|string',
        'sort' => 'nullable|string',
        'dir' => 'nullable|in:asc,desc',
        'per_page' => 'nullable|integer',
        'ml_status' => 'nullable|string',
    ]);

    $precioAnterior = $compuesto->precio_ML;
    $nuevoPrecio = $request->precio_ML;

    $update = [
        'descripcion' => $request->descripcion,
        'title_familyname' => $request->title_familyname,
        'costo' => $request->costo,
        'precio_ML' => $nuevoPrecio,
        'MLM' => $request->MLM,
    ];

    if (!is_null($nuevoPrecio)) {
        $pa = (float) ($precioAnterior ?? 0);
        $np = (float) $nuevoPrecio;

        if ($precioAnterior === null || abs($np - $pa) > 0.01) {
            $update['price_mode'] = 'manual';
        }
    }

    $compuesto->update($update);

    return redirect()
        ->route('productos.index', [
            'page' => $request->input('page', 1),
            'search' => $request->input('search', ''),
            'sort' => $request->input('sort', 'id'),
            'dir' => $request->input('dir', 'desc'),
            'per_page' => $request->input('per_page', 25),
            'ml_status' => $request->input('ml_status', 'all'),
        ])
        ->with('success', 'Producto compuesto actualizado correctamente');
}

    public function setPriceManual(Request $request, ProductoCompuesto $compuesto)
    {
        $compuesto->update(['price_mode' => 'manual']);

        return redirect()
            ->route('productos.edit', [
                $compuesto->id,
                'page' => $request->input('page', 1),
                'search' => $request->input('search', ''),
                'sort' => $request->input('sort'),
                'dir' => $request->input('dir'),
                'per_page' => $request->input('per_page', 25),
                'ml_status' => $request->input('ml_status', 'all'),
            ])
            ->with('success', 'Modo MANUAL activado. El import ya no cambiará el precio.');
    }

    public function setPriceAuto(Request $request, ProductoCompuesto $compuesto)
    {
        $compuesto->update(['price_mode' => 'auto']);

        return redirect()
            ->route('productos.edit', [
                $compuesto->id,
                'page' => $request->input('page', 1),
                'search' => $request->input('search', ''),
                'sort' => $request->input('sort'),
                'dir' => $request->input('dir'),
                'per_page' => $request->input('per_page', 25),
                'ml_status' => $request->input('ml_status', 'all'),
            ])
            ->with('success', 'Modo AUTO activado. El import podrá recalcular el precio.');
    }

    public function recalcPrice(Request $request, ProductoCompuesto $compuesto, FormulaEngine $engine)
    {
        $tipo = $compuesto->tipo;
        $scope = $tipo === 'par' ? 'par' : 'juego4';
        $piezas = $tipo === 'par' ? 2 : 4;

        $costoBase = (float) ($compuesto->llanta?->costo ?? 0);

        $precio = $this->calcFromRule($engine, $scope, $costoBase, $piezas);

        $compuesto->update([
            'price_mode' => 'auto',
            'costo' => $costoBase * $piezas,
            'precio_ML' => $precio,
        ]);

        return redirect()
            ->route('productos.edit', [
                $compuesto->id,
                'page' => $request->input('page', 1),
                'search' => $request->input('search', ''),
                'sort' => $request->input('sort'),
                'dir' => $request->input('dir'),
                'per_page' => $request->input('per_page', 25),
                'ml_status' => $request->input('ml_status', 'all'),
            ])
            ->with('success', 'Precio recalculado (AUTO): $' . number_format($precio, 2));
    }

    private function calcFromRule(FormulaEngine $engine, string $scope, float $costoBase, int $piezas): float
    {
        $fallback = match ($scope) {
            'par' => ($costoBase * 2) * 1.5,
            'juego4' => ($costoBase * 4) * 1.45,
            default => ($costoBase * $piezas) * 1.5,
        };

        $rule = PriceRule::where('rule_set', 'llantas')->where('scope', $scope)->where('active', true)->first();
        if (!$rule) {
            return $fallback;
        }

        try {
            $v = $engine->evaluate($rule->formula, [
                'costo' => $costoBase,
                'piezas' => $piezas,
            ]);

            return (float) $v;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function resolveMlStatusKey($latestPub, int $pubsCount): string
    {
        if ($pubsCount <= 0 || !$latestPub) {
            return 'no_publicada';
        }

        return match (strtolower((string) $latestPub->status)) {
            'active' => 'activa',
            'paused' => 'pausada',
            'under_review' => 'en_revision',
            'closed' => 'cerrada',
            'suspended' => 'bloqueada',
            default => strtolower((string) $latestPub->status),
        };
    }

    private function resolveMlStatusLabel($latestPub, int $pubsCount): string
    {
        if ($pubsCount <= 0 || !$latestPub) {
            return 'No publicada';
        }

        return match (strtolower((string) $latestPub->status)) {
            'active' => 'Activa',
            'paused' => 'Pausada',
            'under_review' => 'En revisión',
            'closed' => 'Cerrada',
            'suspended' => 'Bloqueada',
            default => ucfirst((string) $latestPub->status),
        };
    }
}