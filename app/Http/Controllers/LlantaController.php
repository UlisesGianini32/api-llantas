<?php

namespace App\Http\Controllers;

use App\Models\Llanta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LlantaController extends Controller
{
    public function indexWeb(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $perPage = (int) $request->input('per_page', 25);
        $sort = (string) $request->input('sort', 'sku');
        $dir = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $mlStatus = (string) $request->input('ml_status', '');

        $perPage = in_array($perPage, [10, 25, 50, 100, 250], true) ? $perPage : 25;

        $sortable = [
            'sku' => 'sku',
            'marca' => 'marca',
            'medida' => 'medida',
            'descripcion' => 'descripcion',
            'costo' => 'costo',
            'precio_ML' => 'precio_ML',
            'stock' => 'stock',
        ];

        $sortColumn = $sortable[$sort] ?? 'sku';

        $baseQuery = Llanta::query()
            ->with([
                'meliPublications:id,sku,mlm,status,sub_status,created_at',
                'latestMeliPublication',
            ]);

        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('marca', 'like', "%{$search}%")
                    ->orWhere('medida', 'like', "%{$search}%")
                    ->orWhere('descripcion', 'like', "%{$search}%")
                    ->orWhere('title_familyname', 'like', "%{$search}%");
            });
        }

        $tabsBaseQuery = clone $baseQuery;

        $this->applyMlStatusFilter($baseQuery, $mlStatus);

        $rows = $baseQuery
            ->orderBy($sortColumn, $dir)
            ->paginate($perPage)
            ->withQueryString()
            ->through(function ($llanta) {
                $latest = $llanta->latestMeliPublication;

                return [
                    'id' => $llanta->id,
                    'sku' => $llanta->sku,
                    'marca' => $llanta->marca,
                    'medida' => $llanta->medida,
                    'descripcion' => $llanta->descripcion,
                    'costo' => $llanta->costo,
                    'precio_ML' => $llanta->precio_ML,
                    'stock' => $llanta->stock,
                    'title_familyname' => $llanta->title_familyname ?? null,

                    'ml_status' => $this->resolveMlStatusLabel($latest),
                    'ml_status_key' => $this->resolveMlStatusKey($latest),

                    'publications' => $llanta->meliPublications->map(function ($pub) {
                        return [
                            'id' => $pub->id,
                            'mlm' => $pub->mlm,
                            'status' => $pub->status,
                            'sub_status' => $pub->sub_status,
                        ];
                    })->values(),

                    'latest_publication' => $latest ? [
                        'id' => $latest->id,
                        'mlm' => $latest->mlm,
                        'status' => $latest->status,
                        'sub_status' => $latest->sub_status,
                    ] : null,

                    'actions' => [
                        'edit_url' => url("/llantas/{$llanta->id}/editar"),
                        'publish_form_url' => route('llantas.ml.publish.form', $llanta->id),
                        'republish_url' => url("/llantas/{$llanta->id}/ml/republish"),
                        'refresh_status_url' => $latest ? url("/ml/publications/{$latest->id}/refresh") : null,
                    ],
                ];
            });

        $tabCounts = [
            'todas' => $this->countByStatus(clone $tabsBaseQuery, ''),
            'no_publicada' => $this->countByStatus(clone $tabsBaseQuery, 'no_publicada'),
            'activa' => $this->countByStatus(clone $tabsBaseQuery, 'activa'),
            'pausada' => $this->countByStatus(clone $tabsBaseQuery, 'pausada'),
            'en_revision' => $this->countByStatus(clone $tabsBaseQuery, 'en_revision'),
        ];

        return Inertia::render('Llantas/Index', [
            'llantas' => $rows,
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
                'sort' => $sort,
                'dir' => $dir,
                'ml_status' => $mlStatus,
            ],
            'tabCounts' => $tabCounts,
        ]);
    }

    public function agotadasWeb(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));

        $query = Llanta::query()
            ->where('stock', '<=', 0)
            ->when($search !== '', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%");
            });

        $rows = $query
            ->orderBy('sku')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Llantas/Agotadas', [
            'llantas' => $rows,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function noActualizadasWeb(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $sort = (string) $request->input('sort', 'sku');
        $dir = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $sortable = [
            'sku' => 'sku',
            'marca' => 'marca',
            'medida' => 'medida',
            'descripcion' => 'descripcion',
            'stock' => 'stock',
            'last_import_at' => 'last_import_at',
        ];

        $sortColumn = $sortable[$sort] ?? 'sku';

        $query = Llanta::query()
            ->where(function ($q) {
                $q->whereNull('updated_at')
                    ->orWhereDate('updated_at', '<', now()->subDays(7)->toDateString());
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('sku', 'like', "%{$search}%")
                        ->orWhere('title_familyname', 'like', "%{$search}%")
                        ->orWhere('MLM', 'like', "%{$search}%")
                        ->orWhere('marca', 'like', "%{$search}%")
                        ->orWhere('medida', 'like', "%{$search}%")
                        ->orWhere('descripcion', 'like', "%{$search}%");
                });
            });

        $rows = $query
            ->orderBy($sortColumn, $dir)
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Llantas/NoActualizadas', [
            'llantas' => $rows,
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function editWeb(Request $request, int $id): Response
    {
        $llanta = Llanta::query()
            ->with([
                'meliPublications:id,sku,mlm,status,sub_status,permalink,created_at',
                'latestMeliPublication',
            ])
            ->findOrFail($id);

        return Inertia::render('Llantas/Edit', [
            'llanta' => [
                'id' => $llanta->id,
                'sku' => $llanta->sku,
                'marca' => $llanta->marca,
                'medida' => $llanta->medida,
                'descripcion' => $llanta->descripcion,
                'title_familyname' => $llanta->title_familyname,
                'costo' => (float) ($llanta->costo ?? 0),
                'precio_ML' => (float) ($llanta->precio_ML ?? 0),
                'stock' => (int) ($llanta->stock ?? 0),
                'MLM' => $llanta->MLM ?? null,
                'price_mode' => $llanta->price_mode ?? 'auto',
                'meli_publications' => $llanta->meliPublications->map(function ($pub) {
                    return [
                        'id' => $pub->id,
                        'mlm' => $pub->mlm,
                        'status' => $pub->status,
                        'sub_status' => $pub->sub_status,
                        'permalink' => $pub->permalink ?? null,
                        'created_at' => $pub->created_at?->format('Y-m-d H:i:s'),
                    ];
                })->values(),
            ],
            'filters' => [
                'page' => (int) $request->query('page', 1),
                'search' => (string) $request->query('search', ''),
                'sort' => (string) $request->query('sort', 'sku'),
                'dir' => (string) $request->query('dir', 'asc'),
                'per_page' => (int) $request->query('per_page', 25),
                'ml_status' => (string) $request->query('ml_status', ''),
            ],
        ]);
    }

    public function updateWeb(Request $request, int $id): RedirectResponse
    {
        $llanta = Llanta::findOrFail($id);

        $validated = $request->validate([
            'marca' => ['required', 'string', 'max:255'],
            'medida' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'title_familyname' => ['required', 'string', 'max:255'],
            'costo' => ['required', 'numeric', 'min:0'],
            'precio_ML' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'MLM' => ['nullable', 'string', 'max:255'],

            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer'],
            'ml_status' => ['nullable', 'string'],
        ]);

        $precioAnterior = $llanta->precio_ML;
        $nuevoPrecio = (float) $validated['precio_ML'];

        $update = [
            'marca' => $validated['marca'],
            'medida' => $validated['medida'],
            'descripcion' => $validated['descripcion'] ?? null,
            'title_familyname' => $validated['title_familyname'],
            'costo' => $validated['costo'],
            'precio_ML' => $validated['precio_ML'],
            'stock' => $validated['stock'],
            'MLM' => $validated['MLM'] ?? null,
        ];

        if ($precioAnterior === null || abs(((float) $precioAnterior) - $nuevoPrecio) > 0.01) {
            $update['price_mode'] = 'manual';
            $update['price_locked_at'] = now();
        }

        $llanta->update($update);

        return redirect()
            ->route('llantas.index', [
                'page' => $request->input('page', 1),
                'search' => $request->input('search', ''),
                'sort' => $request->input('sort', 'sku'),
                'dir' => $request->input('dir', 'asc'),
                'per_page' => $request->input('per_page', 25),
                'ml_status' => $request->input('ml_status', ''),
            ])
            ->with('success', 'Llanta actualizada correctamente.');
    }

    public function setPriceManual(Request $request, Llanta $llanta): RedirectResponse
    {
        $llanta->price_mode = 'manual';
        $llanta->price_locked_at = now();
        $llanta->save();

        return back()->with('success', 'Modo manual activado. El import ya no cambiará el precio.');
    }

    public function setPriceAuto(Llanta $llanta): RedirectResponse
    {
        $llanta->price_mode = 'auto';
        $llanta->price_locked_at = null;
        $llanta->save();

        return back()->with('success', 'Modo automático activado.');
    }

    public function recalcPrice(Llanta $llanta): RedirectResponse
    {
        $llanta->price_mode = 'auto';
        $llanta->price_locked_at = null;

        if (isset($llanta->costo)) {
            $llanta->precio_ML = $llanta->costo;
        }

        $llanta->save();

        return back()->with('success', 'Precio recalculado correctamente.');
    }

    protected function applyMlStatusFilter($query, string $mlStatus): void
    {
        if ($mlStatus === '') {
            return;
        }

        if ($mlStatus === 'no_publicada') {
            $query->whereDoesntHave('latestMeliPublication');
            return;
        }

        if ($mlStatus === 'activa') {
            $query->whereHas('latestMeliPublication', function ($q) {
                $q->where('status', 'active');
            });
            return;
        }

        if ($mlStatus === 'pausada') {
            $query->whereHas('latestMeliPublication', function ($q) {
                $q->where('status', 'paused');
            });
            return;
        }

        if ($mlStatus === 'en_revision') {
            $query->whereHas('latestMeliPublication', function ($q) {
                $q->where(function ($sub) {
                    $sub->whereIn('status', ['under_review', 'pending'])
                        ->orWhereIn('sub_status', ['waiting_for_patch', 'warning', 'held']);
                });
            });
        }
    }

    protected function countByStatus($query, string $status): int
    {
        $this->applyMlStatusFilter($query, $status);
        return $query->count();
    }

    protected function resolveMlStatusKey($latest): string
    {
        if (!$latest) {
            return 'no_publicada';
        }

        if ($latest->status === 'active') {
            return 'activa';
        }

        if ($latest->status === 'paused') {
            return 'pausada';
        }

        if (
            in_array($latest->status, ['under_review', 'pending'], true) ||
            in_array($latest->sub_status, ['waiting_for_patch', 'warning', 'held'], true)
        ) {
            return 'en_revision';
        }

        return strtolower((string) $latest->status);
    }

    protected function resolveMlStatusLabel($latest): string
    {
        if (!$latest) {
            return 'No publicada';
        }

        if ($latest->status === 'active') {
            return 'Activa';
        }

        if ($latest->status === 'paused') {
            return 'Pausada';
        }

        if (
            in_array($latest->status, ['under_review', 'pending'], true) ||
            in_array($latest->sub_status, ['waiting_for_patch', 'warning', 'held'], true)
        ) {
            return 'En revisión';
        }

        return ucfirst((string) $latest->status);
    }
}