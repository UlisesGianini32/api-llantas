<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMeliFullStockJob;
use App\Models\MeliAccount;
use App\Models\MeliFullStock;
use App\Services\MeliFullRecommendationExportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeliFullStockController extends Controller
{
    public function index(Request $request): Response
    {
        $owner = $request->user();

        $accounts = MeliAccount::query()
            ->where('user_id', $owner->id)
            ->orderByDesc('is_default')
            ->orderBy('nickname')
            ->get(['id', 'nickname', 'meli_user_id', 'is_default']);

        $selectedAccountId = (int) $request->integer(
            'account_id',
            (int) ($accounts->firstWhere('is_default', true)?->id ?? $accounts->first()?->id ?? 0),
        );

        if ($selectedAccountId > 0 && ! $accounts->contains('id', $selectedAccountId)) {
            abort(403, 'La cuenta de Mercado Libre seleccionada no pertenece al usuario.');
        }

        $search = trim((string) $request->input('search', ''));
        $filter = strtolower(trim((string) $request->input('filter', 'all')));
        $section = strtolower(trim((string) $request->input('section', 'all')));
        $sort = strtolower(trim((string) $request->input('sort', 'connected')));
        $direction = strtolower(trim((string) $request->input('direction', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $perPage = max(20, min(100, (int) $request->integer('per_page', 40)));
        $salesDays = (int) $request->integer('sales_days', 30);
        $coverageDays = (int) $request->integer('coverage_days', 14);
        $recommendationFilter = strtolower(trim((string) $request->input('recommendation_filter', 'all')));

        if (! in_array($salesDays, [7, 15, 30, 60, 90], true)) {
            $salesDays = 30;
        }

        if (! in_array($coverageDays, [7, 14, 21, 30], true)) {
            $coverageDays = 14;
        }

        if (! in_array($recommendationFilter, ['all', 'minimum_one'], true)) {
            $recommendationFilter = 'all';
        }

        if (! in_array($section, ['all', 'variants', 'connected', 'out_of_stock', 'recommendations'], true)) {
            $section = 'all';
        }

        $baseAccountQuery = MeliFullStock::query()
            ->where('user_id', $owner->id)
            ->when(
                $selectedAccountId > 0,
                fn ($query) => $query->where('meli_account_id', $selectedAccountId),
                fn ($query) => $query->whereRaw('1 = 0'),
            );

        /*
         * Varias publicaciones o variantes pueden apuntar al mismo inventario
         * físico. Calculamos una llave común para reconocer esos grupos.
         */
        $physicalKeySql = $this->physicalKeySql();

        /*
         * La subconsulta evita el error de ONLY_FULL_GROUP_BY de MariaDB/MySQL.
         */
        $physicalRows = (clone $baseAccountQuery)
            ->selectRaw("{$physicalKeySql} as physical_key")
            ->addSelect([
                'mlm',
                'full_available_quantity',
                'full_not_available_quantity',
                'full_total_quantity',
            ]);

        $physicalGroups = DB::query()
            ->fromSub($physicalRows->toBase(), 'physical_rows')
            ->select('physical_key')
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COUNT(DISTINCT mlm) as publication_count')
            ->selectRaw('MAX(full_available_quantity) as available_quantity')
            ->selectRaw('MAX(COALESCE(full_not_available_quantity, 0)) as not_available_quantity')
            ->selectRaw('MAX(COALESCE(full_total_quantity, full_available_quantity, 0)) as total_quantity')
            ->groupBy('physical_key')
            ->get();

        $connectionMap = $physicalGroups
            ->mapWithKeys(fn ($group) => [
                (string) $group->physical_key => [
                    'rows' => (int) $group->row_count,
                    'publications' => (int) $group->publication_count,
                ],
            ])
            ->all();

        $stocks = null;
        $connectedGroups = null;
        $recommendations = null;
        $recommendationMeta = null;

        if ($section === 'connected') {
            $connectedGroups = $this->connectedGroupsPaginator(
                request: $request,
                baseAccountQuery: $baseAccountQuery,
                physicalGroups: $physicalGroups,
                connectionMap: $connectionMap,
                search: $search,
                filter: $filter,
                sort: $sort,
                direction: $direction,
                perPage: $perPage,
            );
        } elseif ($section === 'recommendations') {
            [$recommendations, $recommendationMeta] = $this->recommendationsPaginator(
                request: $request,
                baseAccountQuery: $baseAccountQuery,
                physicalGroups: $physicalGroups,
                connectionMap: $connectionMap,
                selectedAccountId: $selectedAccountId,
                search: $search,
                salesDays: $salesDays,
                coverageDays: $coverageDays,
                recommendationFilter: $recommendationFilter,
                direction: $direction,
                perPage: $perPage,
            );
        } else {
            $query = (clone $baseAccountQuery)
                ->with('meliAccount:id,nickname,is_default');

            if ($section === 'variants') {
                $query
                    ->whereNotNull('variation_id')
                    ->where('variation_id', '<>', '');
            }

            if ($section === 'out_of_stock') {
                $query->where('full_available_quantity', '<=', 0);
                $this->applyReplenishablePublicationFilter($query);
            }

            $this->applySearch($query, $search);
            $this->applyStatusFilter($query, $filter);
            $this->applySort($query, $sort, $direction, $physicalKeySql);

            $stocks = $query
                ->paginate($perPage)
                ->withQueryString()
                ->through(function (MeliFullStock $stock) use ($connectionMap) {
                    $physicalKey = $this->physicalKey($stock);
                    $connection = $connectionMap[$physicalKey] ?? [
                        'rows' => 1,
                        'publications' => 1,
                    ];

                    return $this->stockToArray($stock, $physicalKey, $connection);
                });
        }

        $lastSyncAt = (clone $baseAccountQuery)->max('synced_at');
        $variantBase = (clone $baseAccountQuery)
            ->whereNotNull('variation_id')
            ->where('variation_id', '<>', '');
        $sharedGroups = $physicalGroups
            ->filter(fn ($group) => (int) $group->row_count > 1);

        $replenishableOutOfStockQuery = (clone $baseAccountQuery)
            ->where('full_available_quantity', '<=', 0);
        $this->applyReplenishablePublicationFilter($replenishableOutOfStockQuery);

        $replenishableOutOfStockGroups = $replenishableOutOfStockQuery
            ->get()
            ->groupBy(fn (MeliFullStock $stock) => $this->physicalKey($stock));

        $excludedOutOfStockQuery = (clone $baseAccountQuery)
            ->where('full_available_quantity', '<=', 0);
        $this->applyBlockedOrReviewPublicationFilter($excludedOutOfStockQuery);

        $stats = [
            'products' => (clone $baseAccountQuery)->distinct()->count('mlm'),
            'rows' => (clone $baseAccountQuery)->count(),
            'variant_rows' => (clone $variantBase)->count(),
            'variant_products' => (clone $variantBase)->distinct()->count('mlm'),
            'physical_inventories' => $physicalGroups->count(),
            'shared_groups' => $sharedGroups->count(),
            'connected_rows' => (int) $sharedGroups->sum('row_count'),
            'out_of_stock' => $replenishableOutOfStockGroups->count(),
            'excluded_out_of_stock_rows' => (clone $excludedOutOfStockQuery)->count(),
            'excluded_out_of_stock_products' => (clone $excludedOutOfStockQuery)->distinct()->count('mlm'),
            'recommended_products' => (int) ($recommendationMeta['recommended_products'] ?? 0),
            'recommended_units' => (int) ($recommendationMeta['recommended_units'] ?? 0),
            'available' => (int) $physicalGroups->sum('available_quantity'),
            'not_available' => (int) $physicalGroups->sum('not_available_quantity'),
            'total' => (int) $physicalGroups->sum('total_quantity'),
            'errors' => (clone $baseAccountQuery)->whereNotNull('last_error')->count(),
            'last_sync_at' => filled($lastSyncAt)
                ? Carbon::parse((string) $lastSyncAt)->format('d/m/Y H:i')
                : null,
        ];

        return Inertia::render('MeliFull/Index', [
            'accounts' => $accounts->map(fn (MeliAccount $account) => [
                'id' => $account->id,
                'nickname' => $account->nickname ?: 'Cuenta '.$account->id,
                'meli_user_id' => (string) $account->meli_user_id,
                'is_default' => (bool) $account->is_default,
            ])->values()->all(),
            'selectedAccountId' => $selectedAccountId,
            'stocks' => $stocks,
            'connectedGroups' => $connectedGroups,
            'recommendations' => $recommendations,
            'recommendationMeta' => $recommendationMeta,
            'stats' => $stats,
            'filters' => [
                'search' => $search,
                'filter' => $filter,
                'section' => $section,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
                'sales_days' => $salesDays,
                'coverage_days' => $coverageDays,
                'recommendation_filter' => $recommendationFilter,
            ],
        ]);
    }


    public function exportRecommendations(
        Request $request,
        MeliFullRecommendationExportService $exporter,
    ): BinaryFileResponse {
        $owner = $request->user();
        $selectedAccountId = (int) $request->integer('account_id');
        $account = $this->ownedAccount($request, $selectedAccountId);

        $salesDays = (int) $request->integer('sales_days', 30);
        $coverageDays = (int) $request->integer('coverage_days', 14);

        if (! in_array($salesDays, [7, 15, 30, 60, 90], true)) {
            $salesDays = 30;
        }

        if (! in_array($coverageDays, [7, 14, 21, 30], true)) {
            $coverageDays = 14;
        }

        $baseAccountQuery = MeliFullStock::query()
            ->where('user_id', $owner->id)
            ->where('meli_account_id', $account->id);

        $physicalKeySql = $this->physicalKeySql();
        $physicalRows = (clone $baseAccountQuery)
            ->selectRaw("{$physicalKeySql} as physical_key")
            ->addSelect([
                'mlm',
                'full_available_quantity',
                'full_not_available_quantity',
                'full_total_quantity',
            ]);

        $physicalGroups = DB::query()
            ->fromSub($physicalRows->toBase(), 'physical_rows')
            ->select('physical_key')
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COUNT(DISTINCT mlm) as publication_count')
            ->selectRaw('MAX(full_available_quantity) as available_quantity')
            ->selectRaw('MAX(COALESCE(full_not_available_quantity, 0)) as not_available_quantity')
            ->selectRaw('MAX(COALESCE(full_total_quantity, full_available_quantity, 0)) as total_quantity')
            ->groupBy('physical_key')
            ->get();

        $connectionMap = $physicalGroups
            ->mapWithKeys(fn ($group) => [
                (string) $group->physical_key => [
                    'rows' => (int) $group->row_count,
                    'publications' => (int) $group->publication_count,
                ],
            ])
            ->all();

        [$recommendations] = $this->recommendationsPaginator(
            request: $request,
            baseAccountQuery: $baseAccountQuery,
            physicalGroups: $physicalGroups,
            connectionMap: $connectionMap,
            selectedAccountId: (int) $account->id,
            search: '',
            salesDays: $salesDays,
            coverageDays: $coverageDays,
            recommendationFilter: 'all',
            direction: 'desc',
            perPage: 100000,
        );

        $groups = collect($recommendations->items())
            ->filter(fn (array $group): bool => (int) ($group['recommended_quantity'] ?? 0) > 0)
            ->values();

        if ($groups->isEmpty()) {
            abort(422, 'No hay productos con cantidad recomendada para generar el Excel.');
        }

        $path = $exporter->create(
            groups: $groups,
            salesDays: $salesDays,
            coverageDays: $coverageDays,
        );

        $filename = sprintf(
            'Envio-de-stock-Full-recomendado-%d-dias-cobertura-%d-%s.xlsx',
            $salesDays,
            $coverageDays,
            now()->format('Y-m-d_H-i'),
        );

        return response()
            ->download(
                $path,
                $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            )
            ->deleteFileAfterSend(true);
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = $this->ownedAccount($request, (int) $request->integer('account_id'));

        SyncMeliFullStockJob::dispatch(
            (int) $request->user()->id,
            (int) $account->id,
        );

        return back()->with('ok', 'La sincronización FULL quedó en cola. La pantalla se actualizará al recargar cuando termine.');
    }

    public function syncOne(Request $request, string $mlm): RedirectResponse
    {
        $account = $this->ownedAccount($request, (int) $request->integer('account_id'));
        $mlm = strtoupper(trim($mlm));

        SyncMeliFullStockJob::dispatch(
            (int) $request->user()->id,
            (int) $account->id,
            $mlm,
        );

        return back()->with('ok', "La publicación {$mlm} quedó en cola para sincronizar FULL.");
    }

    private function ownedAccount(Request $request, int $accountId): MeliAccount
    {
        if ($accountId <= 0) {
            abort(422, 'Selecciona una cuenta de Mercado Libre.');
        }

        return MeliAccount::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($accountId);
    }

    private function connectedGroupsPaginator(
        Request $request,
        $baseAccountQuery,
        Collection $physicalGroups,
        array $connectionMap,
        string $search,
        string $filter,
        string $sort,
        string $direction,
        int $perPage,
    ): LengthAwarePaginator {
        $connectedKeySet = $physicalGroups
            ->filter(fn ($group) => (int) $group->row_count > 1)
            ->mapWithKeys(fn ($group) => [(string) $group->physical_key => true])
            ->all();

        $models = (clone $baseAccountQuery)
            ->with('meliAccount:id,nickname,is_default')
            ->get()
            ->filter(fn (MeliFullStock $stock) => isset($connectedKeySet[$this->physicalKey($stock)]));

        $groups = $models
            ->groupBy(fn (MeliFullStock $stock) => $this->physicalKey($stock))
            ->filter(function (Collection $rows) use ($search, $filter) {
                if ($search !== '' && ! $rows->contains(
                    fn (MeliFullStock $stock) => $this->stockMatchesSearch($stock, $search)
                )) {
                    return false;
                }

                return $this->groupMatchesStatus($rows, $filter);
            })
            ->map(function (Collection $rows, string $physicalKey) use ($connectionMap) {
                $orderedRows = $rows
                    ->sortBy([
                        ['mlm', 'asc'],
                        ['variation_label', 'asc'],
                        ['variation_id', 'asc'],
                    ])
                    ->values();

                $connection = $connectionMap[$physicalKey] ?? [
                    'rows' => $orderedRows->count(),
                    'publications' => $orderedRows->pluck('mlm')->unique()->count(),
                ];

                $firstInventoryId = $orderedRows
                    ->first(fn (MeliFullStock $stock) => filled($stock->inventory_id))
                    ?->inventory_id;
                $firstUserProductId = $orderedRows
                    ->first(fn (MeliFullStock $stock) => filled($stock->user_product_id))
                    ?->user_product_id;
                $updatedTimestamp = (int) $orderedRows
                    ->map(fn (MeliFullStock $stock) => $stock->synced_at?->getTimestamp() ?? 0)
                    ->max();

                return [
                    'physical_key' => $physicalKey,
                    'inventory_id' => $firstInventoryId,
                    'user_product_id' => $firstUserProductId,
                    'publication_count' => (int) $connection['publications'],
                    'row_count' => (int) $connection['rows'],
                    'variant_count' => $orderedRows
                        ->filter(fn (MeliFullStock $stock) => filled($stock->variation_id))
                        ->count(),
                    'available_quantity' => (int) ($orderedRows->max('full_available_quantity') ?? 0),
                    'not_available_quantity' => (int) ($orderedRows->max('full_not_available_quantity') ?? 0),
                    'total_quantity' => (int) ($orderedRows->max('full_total_quantity')
                        ?? $orderedRows->max('full_available_quantity')
                        ?? 0),
                    'updated_timestamp' => $updatedTimestamp,
                    'updated_at' => $updatedTimestamp > 0
                        ? Carbon::createFromTimestamp($updatedTimestamp)->format('d/m/Y H:i')
                        : null,
                    'title' => (string) ($orderedRows->first()?->title ?? ''),
                    'rows' => $orderedRows
                        ->map(fn (MeliFullStock $stock) => $this->stockToArray(
                            $stock,
                            $physicalKey,
                            $connection,
                        ))
                        ->all(),
                ];
            })
            ->values();

        $groups = $groups->sort(function (array $left, array $right) use ($sort, $direction) {
            $field = match ($sort) {
                'title' => 'title',
                'available' => 'available_quantity',
                'unavailable' => 'not_available_quantity',
                'total' => 'total_quantity',
                'updated' => 'updated_timestamp',
                default => 'row_count',
            };

            $leftValue = $left[$field] ?? 0;
            $rightValue = $right[$field] ?? 0;

            $comparison = is_string($leftValue) || is_string($rightValue)
                ? strnatcasecmp((string) $leftValue, (string) $rightValue)
                : ((int) $leftValue <=> (int) $rightValue);

            if ($comparison === 0) {
                $comparison = strnatcasecmp(
                    (string) $left['physical_key'],
                    (string) $right['physical_key'],
                );
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        })->values();

        $page = max(1, (int) $request->integer('page', 1));

        return new LengthAwarePaginator(
            $groups->forPage($page, $perPage)->values(),
            $groups->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->except('page'),
            ],
        );
    }


    /**
     * Genera recomendaciones por inventario físico para no duplicar cantidades
     * cuando dos MLM o variantes comparten el mismo inventory_id.
     *
     * Fórmula:
     * sugerido = promedio diario de ventas × días de cobertura
     *            - (disponible FULL + unidades en transferencia).
     *
     * @return array{0: LengthAwarePaginator, 1: array<string, mixed>}
     */
    private function recommendationsPaginator(
        Request $request,
        $baseAccountQuery,
        Collection $physicalGroups,
        array $connectionMap,
        int $selectedAccountId,
        string $search,
        int $salesDays,
        int $coverageDays,
        string $recommendationFilter,
        string $direction,
        int $perPage,
    ): array {
        $recommendableQuery = clone $baseAccountQuery;
        $this->applyReplenishablePublicationFilter($recommendableQuery);

        $models = $recommendableQuery
            ->with('meliAccount:id,nickname,is_default')
            ->get();

        $rowsByMlm = $models
            ->groupBy(fn (MeliFullStock $stock) => strtoupper(trim((string) $stock->mlm)));

        $mlms = $rowsByMlm->keys()
            ->filter()
            ->values()
            ->all();

        $sales = $this->buildSalesMaps(
            selectedAccountId: $selectedAccountId,
            mlms: $mlms,
            salesDays: $salesDays,
        );

        $groups = $models
            ->groupBy(fn (MeliFullStock $stock) => $this->physicalKey($stock))
            ->map(function (Collection $rows, string $physicalKey) use (
                $connectionMap,
                $rowsByMlm,
                $sales,
                $salesDays,
                $coverageDays,
            ) {
                $orderedRows = $rows
                    ->sortBy([
                        ['mlm', 'asc'],
                        ['variation_label', 'asc'],
                        ['variation_id', 'asc'],
                    ])
                    ->values();

                $connection = $connectionMap[$physicalKey] ?? [
                    'rows' => $orderedRows->count(),
                    'publications' => $orderedRows->pluck('mlm')->unique()->count(),
                ];

                $salesPeriod = 0;
                $sales7 = 0;
                $sales30 = 0;
                $unmatchedRows = 0;

                foreach ($orderedRows as $stock) {
                    $rowSales = $this->salesForStockRow(
                        stock: $stock,
                        rowsForMlm: $rowsByMlm->get(
                            strtoupper(trim((string) $stock->mlm)),
                            collect(),
                        ),
                        maps: $sales,
                    );

                    $salesPeriod += (int) $rowSales['period'];
                    $sales7 += (int) $rowSales['days_7'];
                    $sales30 += (int) $rowSales['days_30'];

                    if (! $rowSales['matched']) {
                        $unmatchedRows++;
                    }
                }

                $available = (int) ($orderedRows->max('full_available_quantity') ?? 0);
                $notAvailable = (int) ($orderedRows->max('full_not_available_quantity') ?? 0);
                $total = (int) ($orderedRows->max('full_total_quantity')
                    ?? $orderedRows->max('full_available_quantity')
                    ?? 0);

                $transfer = $orderedRows
                    ->map(fn (MeliFullStock $stock) => $this->transferQuantity(
                        is_array($stock->not_available_detail)
                            ? $stock->not_available_detail
                            : [],
                    ))
                    ->max() ?? 0;

                $stockConsidered = max(0, $available + (int) $transfer);
                $dailyAverage = $salesDays > 0 ? $salesPeriod / $salesDays : 0.0;
                $targetStock = (int) ceil($dailyAverage * $coverageDays);
                $recommended = max(0, $targetStock - $stockConsidered);

                // Regla de exhibición mínima:
                // si el inventario físico está completamente agotado, no hay unidades
                // en transferencia y no se pudieron asignar ventas a esta existencia
                // durante los últimos 30 días, sugerimos enviar una pieza.
                //
                // Una variante sin coincidencia exacta de venta NO bloquea la regla:
                // la advertencia se conserva únicamente como dato de auditoría.
                $minimumOneApplied = $stockConsidered === 0
                    && $sales30 === 0;

                if ($minimumOneApplied) {
                    $targetStock = max(1, $targetStock);
                    $recommended = max(1, $recommended);
                }

                $firstInventoryId = $orderedRows
                    ->first(fn (MeliFullStock $stock) => filled($stock->inventory_id))
                    ?->inventory_id;
                $firstUserProductId = $orderedRows
                    ->first(fn (MeliFullStock $stock) => filled($stock->user_product_id))
                    ?->user_product_id;

                return [
                    'physical_key' => $physicalKey,
                    'inventory_id' => $firstInventoryId,
                    'user_product_id' => $firstUserProductId,
                    'publication_count' => (int) $connection['publications'],
                    'row_count' => (int) $connection['rows'],
                    'variant_count' => $orderedRows
                        ->filter(fn (MeliFullStock $stock) => filled($stock->variation_id))
                        ->count(),
                    'available_quantity' => $available,
                    'not_available_quantity' => $notAvailable,
                    'total_quantity' => $total,
                    'transfer_quantity' => (int) $transfer,
                    'stock_considered' => $stockConsidered,
                    'sales_7_days' => $sales7,
                    'sales_30_days' => $sales30,
                    'sales_period' => $salesPeriod,
                    'sales_days' => $salesDays,
                    'daily_average' => round($dailyAverage, 2),
                    'coverage_days' => $coverageDays,
                    'target_stock' => $targetStock,
                    'recommended_quantity' => $recommended,
                    'minimum_one_applied' => $minimumOneApplied,
                    'unmatched_rows' => $unmatchedRows,
                    'title' => (string) ($orderedRows->first()?->title ?? ''),
                    'rows' => $orderedRows
                        ->map(fn (MeliFullStock $stock) => $this->stockToArray(
                            $stock,
                            $physicalKey,
                            $connection,
                        ))
                        ->all(),
                ];
            })
            ->filter(function (array $group) use ($search) {
                if ($search === '') {
                    return true;
                }

                $needle = mb_strtolower($search);
                $haystack = mb_strtolower(implode(' ', [
                    $group['title'] ?? '',
                    $group['inventory_id'] ?? '',
                    $group['user_product_id'] ?? '',
                    collect($group['rows'] ?? [])
                        ->flatMap(fn (array $row) => [
                            $row['title'] ?? '',
                            $row['sku'] ?? '',
                            $row['mlm'] ?? '',
                            $row['variation_label'] ?? '',
                            $row['variation_id'] ?? '',
                        ])
                        ->implode(' '),
                ]));

                return str_contains($haystack, $needle);
            })
            ->sort(function (array $left, array $right) use ($direction) {
                $comparison = ((int) ($left['recommended_quantity'] ?? 0))
                    <=> ((int) ($right['recommended_quantity'] ?? 0));

                if ($direction === 'desc') {
                    $comparison *= -1;
                }

                if ($comparison === 0) {
                    $comparison = ((int) ($left['sales_period'] ?? 0))
                        <=> ((int) ($right['sales_period'] ?? 0));

                    if ($direction === 'desc') {
                        $comparison *= -1;
                    }
                }

                if ($comparison === 0) {
                    $comparison = strnatcasecmp(
                        (string) ($left['title'] ?? ''),
                        (string) ($right['title'] ?? ''),
                    );
                }

                return $comparison;
            })
            ->values();

        $recommendedGroups = $groups
            ->filter(fn (array $group) => (int) $group['recommended_quantity'] > 0);

        $minimumOneGroups = $groups
            ->filter(fn (array $group) => (bool) ($group['minimum_one_applied'] ?? false));

        $visibleGroups = $recommendationFilter === 'minimum_one'
            ? $minimumOneGroups->values()
            : $groups;

        $page = max(1, (int) $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            $visibleGroups->forPage($page, $perPage)->values(),
            $visibleGroups->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->except('page'),
            ],
        );

        $lastOrderAt = DB::table('meli_orders')
            ->where('meli_account_id', $selectedAccountId)
            ->max('updated_at');

        return [
            $paginator,
            [
                'sales_days' => $salesDays,
                'coverage_days' => $coverageDays,
                'recommended_products' => $recommendedGroups->count(),
                'recommended_units' => (int) $recommendedGroups->sum('recommended_quantity'),
                'minimum_one_products' => $minimumOneGroups->count(),
                'recommendation_filter' => $recommendationFilter,
                'visible_groups' => $visibleGroups->count(),
                'groups_with_sales' => $groups
                    ->filter(fn (array $group) => (int) $group['sales_period'] > 0)
                    ->count(),
                'last_order_sync_at' => filled($lastOrderAt)
                    ? Carbon::parse((string) $lastOrderAt)->format('d/m/Y H:i')
                    : null,
                'formula' => 'Promedio diario × cobertura - (disponible FULL + transferencia). Mínimo 1 si está agotado y no tuvo ventas en 30 días',
            ],
        ];
    }

    /**
     * @param  array<int, string>  $mlms
     * @return array<string, array<string, int>>
     */
    private function buildSalesMaps(
        int $selectedAccountId,
        array $mlms,
        int $salesDays,
    ): array {
        $maps = [
            'period_variation' => [],
            'period_sku' => [],
            'period_mlm' => [],
            'days_7_variation' => [],
            'days_7_sku' => [],
            'days_7_mlm' => [],
            'days_30_variation' => [],
            'days_30_sku' => [],
            'days_30_mlm' => [],
        ];

        if ($selectedAccountId <= 0 || $mlms === []) {
            return $maps;
        }

        $startPeriod = now()->subDays($salesDays)->startOfDay();
        $start7 = now()->subDays(7)->startOfDay();
        $start30 = now()->subDays(30)->startOfDay();
        $queryStart = now()->subDays(max($salesDays, 30))->startOfDay();

        $orders = DB::table('meli_orders as o')
            ->join('meli_order_items as i', 'i.meli_order_id', '=', 'o.id')
            ->where('o.meli_account_id', $selectedAccountId)
            ->whereIn('i.item_id', $mlms)
            ->whereRaw("LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'invalid')")
            ->whereRaw(
                "COALESCE(
                    STR_TO_DATE(
                        LEFT(JSON_UNQUOTE(JSON_EXTRACT(o.raw, '$.date_created')), 19),
                        '%Y-%m-%dT%H:%i:%s'
                    ),
                    o.created_at
                ) >= ?",
                [$queryStart->format('Y-m-d H:i:s')],
            )
            ->select([
                'i.item_id',
                'i.sku',
                'i.quantity',
                'o.raw',
                'o.created_at',
            ])
            ->get();

        foreach ($orders as $order) {
            $mlm = strtoupper(trim((string) $order->item_id));
            $sku = strtoupper(trim((string) $order->sku));
            $quantity = max(0, (int) $order->quantity);

            if ($mlm === '' || $quantity <= 0) {
                continue;
            }

            $raw = $this->decodeJsonArray($order->raw);
            $orderDate = $this->orderDateFromRaw($raw, $order->created_at);
            $variationId = $this->orderVariationId($raw, $mlm, $sku);

            if ($orderDate->greaterThanOrEqualTo($startPeriod)) {
                $maps['period_mlm'][$mlm] = ($maps['period_mlm'][$mlm] ?? 0) + $quantity;

                if ($sku !== '') {
                    $skuKey = $mlm.'|'.$sku;
                    $maps['period_sku'][$skuKey] =
                        ($maps['period_sku'][$skuKey] ?? 0) + $quantity;
                }

                if ($variationId !== '') {
                    $variationKey = $mlm.'|'.$variationId;
                    $maps['period_variation'][$variationKey] =
                        ($maps['period_variation'][$variationKey] ?? 0) + $quantity;
                }
            }

            if ($orderDate->greaterThanOrEqualTo($start7)) {
                $maps['days_7_mlm'][$mlm] = ($maps['days_7_mlm'][$mlm] ?? 0) + $quantity;

                if ($sku !== '') {
                    $skuKey = $mlm.'|'.$sku;
                    $maps['days_7_sku'][$skuKey] =
                        ($maps['days_7_sku'][$skuKey] ?? 0) + $quantity;
                }

                if ($variationId !== '') {
                    $variationKey = $mlm.'|'.$variationId;
                    $maps['days_7_variation'][$variationKey] =
                        ($maps['days_7_variation'][$variationKey] ?? 0) + $quantity;
                }
            }


            if ($orderDate->greaterThanOrEqualTo($start30)) {
                $maps['days_30_mlm'][$mlm] = ($maps['days_30_mlm'][$mlm] ?? 0) + $quantity;

                if ($sku !== '') {
                    $skuKey = $mlm.'|'.$sku;
                    $maps['days_30_sku'][$skuKey] =
                        ($maps['days_30_sku'][$skuKey] ?? 0) + $quantity;
                }

                if ($variationId !== '') {
                    $variationKey = $mlm.'|'.$variationId;
                    $maps['days_30_variation'][$variationKey] =
                        ($maps['days_30_variation'][$variationKey] ?? 0) + $quantity;
                }
            }
        }

        return $maps;
    }

    /**
     * @param  Collection<int, MeliFullStock>  $rowsForMlm
     * @param  array<string, array<string, int>>  $maps
     * @return array{period:int, days_7:int, days_30:int, matched:bool}
     */
    private function salesForStockRow(
        MeliFullStock $stock,
        Collection $rowsForMlm,
        array $maps,
    ): array {
        $mlm = strtoupper(trim((string) $stock->mlm));
        $variationId = strtoupper(trim((string) $stock->variation_id));
        $sku = strtoupper(trim((string) $stock->sku));

        if ($variationId !== '') {
            $key = $mlm.'|'.$variationId;
            $hasVariationData = array_key_exists($key, $maps['period_variation'])
                || array_key_exists($key, $maps['days_7_variation'])
                || array_key_exists($key, $maps['days_30_variation']);

            if ($hasVariationData) {
                return [
                    'period' => (int) ($maps['period_variation'][$key] ?? 0),
                    'days_7' => (int) ($maps['days_7_variation'][$key] ?? 0),
                    'days_30' => (int) ($maps['days_30_variation'][$key] ?? 0),
                    'matched' => true,
                ];
            }
        }

        if ($sku !== '') {
            $key = $mlm.'|'.$sku;
            $hasSkuData = array_key_exists($key, $maps['period_sku'])
                || array_key_exists($key, $maps['days_7_sku'])
                || array_key_exists($key, $maps['days_30_sku']);

            if ($hasSkuData) {
                return [
                    'period' => (int) ($maps['period_sku'][$key] ?? 0),
                    'days_7' => (int) ($maps['days_7_sku'][$key] ?? 0),
                    'days_30' => (int) ($maps['days_30_sku'][$key] ?? 0),
                    'matched' => true,
                ];
            }
        }

        if ($rowsForMlm->count() === 1) {
            return [
                'period' => (int) ($maps['period_mlm'][$mlm] ?? 0),
                'days_7' => (int) ($maps['days_7_mlm'][$mlm] ?? 0),
                'days_30' => (int) ($maps['days_30_mlm'][$mlm] ?? 0),
                'matched' => true,
            ];
        }

        $mlmHasSales = (int) ($maps['period_mlm'][$mlm] ?? 0) > 0
            || (int) ($maps['days_30_mlm'][$mlm] ?? 0) > 0;

        // Si la publicación completa no registra ventas, podemos afirmar que
        // esta variante tampoco tuvo ventas aunque no exista una coincidencia
        // exacta de variation_id o SKU en los pedidos.
        if (! $mlmHasSales) {
            return [
                'period' => 0,
                'days_7' => 0,
                'days_30' => 0,
                'matched' => true,
            ];
        }

        return [
            'period' => 0,
            'days_7' => 0,
            'days_30' => 0,
            'matched' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function orderVariationId(array $raw, string $mlm, string $sku): string
    {
        foreach ((array) ($raw['order_items'] ?? []) as $orderItem) {
            if (! is_array($orderItem)) {
                continue;
            }

            $item = (array) ($orderItem['item'] ?? []);
            $candidateMlm = strtoupper(trim((string) ($item['id'] ?? '')));
            $candidateSku = strtoupper(trim((string) (
                $item['seller_sku']
                ?? $item['seller_custom_field']
                ?? ''
            )));

            if ($candidateMlm !== $mlm) {
                continue;
            }

            if ($sku !== '' && $candidateSku !== '' && $candidateSku !== $sku) {
                continue;
            }

            return strtoupper(trim((string) (
                $item['variation_id']
                ?? $orderItem['variation_id']
                ?? ''
            )));
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function orderDateFromRaw(array $raw, mixed $fallback): Carbon
    {
        try {
            if (filled($raw['date_created'] ?? null)) {
                return Carbon::parse((string) $raw['date_created']);
            }

            return Carbon::parse((string) $fallback);
        } catch (\Throwable) {
            return now()->subYears(10);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function transferQuantity(array $details): int
    {
        return collect($details)
            ->filter(function ($detail) {
                $status = mb_strtolower(trim((string) ($detail['status'] ?? '')));

                return str_contains($status, 'transfer');
            })
            ->sum(fn ($detail) => max(0, (int) ($detail['quantity'] ?? 0)));
    }

    /**
     * Mantiene únicamente publicaciones que pueden recuperarse enviando stock:
     * activas o pausadas automáticamente por falta de existencias.
     * Los registros sin estado se conservan temporalmente hasta la siguiente
     * sincronización para no ocultar todo durante la migración.
     */
    private function applyReplenishablePublicationFilter($query): void
    {
        $query->where(function ($statusQuery) {
            $statusQuery
                ->whereNull('publication_status')
                ->orWhere('publication_status', '')
                ->orWhere('publication_status', 'active')
                ->orWhere(function ($pausedQuery) {
                    $pausedQuery
                        ->where('publication_status', 'paused')
                        ->where('publication_sub_status', 'like', '%out_of_stock%');
                });
        });
    }

    /**
     * Detecta filas que no deben tratarse como agotadas reponibles porque la
     * publicación está bloqueada, bajo revisión, cerrada o pausada por causas
     * distintas a la falta de stock.
     */
    private function applyBlockedOrReviewPublicationFilter($query): void
    {
        $query->whereNotNull('publication_status')
            ->where('publication_status', '<>', '')
            ->where(function ($statusQuery) {
                $statusQuery
                    ->whereIn('publication_status', ['under_review', 'inactive', 'closed'])
                    ->orWhere(function ($pausedQuery) {
                        $pausedQuery
                            ->where('publication_status', 'paused')
                            ->where(function ($subStatusQuery) {
                                $subStatusQuery
                                    ->whereNull('publication_sub_status')
                                    ->orWhere('publication_sub_status', 'not like', '%out_of_stock%');
                            });
                    });
            });
    }

    private function isReplenishablePublication(MeliFullStock $stock): bool
    {
        $status = strtolower(trim((string) $stock->publication_status));
        $subStatuses = collect(is_array($stock->publication_sub_status)
            ? $stock->publication_sub_status
            : [])
            ->map(fn ($value) => strtolower(trim((string) $value)));

        if ($status === '') {
            return true;
        }

        if ($status === 'active') {
            return true;
        }

        return $status === 'paused' && $subStatuses->contains('out_of_stock');
    }

    private function publicationStatusLabel(MeliFullStock $stock): string
    {
        $status = strtolower(trim((string) $stock->publication_status));
        $subStatuses = collect(is_array($stock->publication_sub_status)
            ? $stock->publication_sub_status
            : [])
            ->map(fn ($value) => strtolower(trim((string) $value)));

        if ($status === 'active') {
            return 'Activa';
        }

        if ($status === 'paused' && $subStatuses->contains('out_of_stock')) {
            return 'Agotada por stock';
        }

        if ($status === 'under_review') {
            return 'En revisión';
        }

        if ($status === 'inactive') {
            return 'Inactiva por revisión';
        }

        if ($status === 'closed') {
            return 'Cerrada';
        }

        if ($status === 'paused') {
            return 'Pausada / no reponible';
        }

        return 'Estado pendiente';
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function ($inner) use ($like) {
            $inner->where('title', 'like', $like)
                ->orWhere('variation_label', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('mlm', 'like', $like)
                ->orWhere('variation_id', 'like', $like)
                ->orWhere('inventory_id', 'like', $like)
                ->orWhere('user_product_id', 'like', $like);
        });
    }

    private function applyStatusFilter($query, string $filter): void
    {
        match ($filter) {
            'available' => $query->where('full_available_quantity', '>', 0),
            'zero' => $query->where('full_available_quantity', 0),
            'unavailable' => $query->where('full_not_available_quantity', '>', 0),
            'errors' => $query->whereNotNull('last_error'),
            default => null,
        };
    }

    private function applySort($query, string $sort, string $direction, string $physicalKeySql): void
    {
        if ($sort === 'connected') {
            $query
                ->orderByRaw($physicalKeySql.' ASC')
                ->orderBy('mlm')
                ->orderBy('variation_label');

            return;
        }

        $sortColumn = match ($sort) {
            'title' => 'title',
            'total' => 'full_total_quantity',
            'unavailable' => 'full_not_available_quantity',
            'updated' => 'synced_at',
            default => 'full_available_quantity',
        };

        $query
            ->orderBy($sortColumn, $direction)
            ->orderByRaw($physicalKeySql.' ASC')
            ->orderBy('mlm')
            ->orderBy('variation_label');
    }

    private function stockMatchesSearch(MeliFullStock $stock, string $search): bool
    {
        $needle = mb_strtolower($search);
        $haystack = mb_strtolower(implode(' ', [
            $stock->title,
            $stock->variation_label,
            $stock->sku,
            $stock->mlm,
            $stock->variation_id,
            $stock->inventory_id,
            $stock->user_product_id,
        ]));

        return str_contains($haystack, $needle);
    }

    private function groupMatchesStatus(Collection $rows, string $filter): bool
    {
        return match ($filter) {
            'available' => $rows->contains(
                fn (MeliFullStock $stock) => (int) $stock->full_available_quantity > 0
            ),
            'zero' => $rows->contains(
                fn (MeliFullStock $stock) => (int) $stock->full_available_quantity === 0
            ),
            'unavailable' => $rows->contains(
                fn (MeliFullStock $stock) => (int) $stock->full_not_available_quantity > 0
            ),
            'errors' => $rows->contains(
                fn (MeliFullStock $stock) => filled($stock->last_error)
            ),
            default => true,
        };
    }

    private function physicalKeySql(): string
    {
        return "CASE
            WHEN inventory_id IS NOT NULL AND inventory_id <> ''
                THEN CONCAT('inventory:', UPPER(inventory_id))
            WHEN user_product_id IS NOT NULL AND user_product_id <> ''
                THEN CONCAT('user-product:', UPPER(user_product_id))
            ELSE CONCAT('row:', stock_key)
        END";
    }

    private function physicalKey(MeliFullStock $stock): string
    {
        $inventoryId = strtoupper(trim((string) $stock->inventory_id));

        if ($inventoryId !== '') {
            return 'inventory:'.$inventoryId;
        }

        $userProductId = strtoupper(trim((string) $stock->user_product_id));

        if ($userProductId !== '') {
            return 'user-product:'.$userProductId;
        }

        return 'row:'.$stock->stock_key;
    }

    /**
     * @param  array{rows:int, publications:int}  $connection
     * @return array<string, mixed>
     */
    private function stockToArray(
        MeliFullStock $stock,
        string $physicalKey,
        array $connection,
    ): array {
        return [
            'id' => $stock->id,
            'meli_account_id' => $stock->meli_account_id,
            'account_name' => $stock->meliAccount?->nickname ?: 'Cuenta '.$stock->meli_account_id,
            'mlm' => $stock->mlm,
            'variation_id' => $stock->variation_id,
            'sku' => $stock->sku,
            'title' => $stock->title ?: 'Publicación sin título',
            'variation_label' => $stock->variation_label,
            'thumbnail' => $stock->thumbnail,
            'permalink' => $stock->permalink,
            'publication_status' => $stock->publication_status,
            'publication_sub_status' => $stock->publication_sub_status ?? [],
            'publication_tags' => $stock->publication_tags ?? [],
            'publication_status_label' => $this->publicationStatusLabel($stock),
            'is_replenishable_publication' => $this->isReplenishablePublication($stock),
            'inventory_id' => $stock->inventory_id,
            'user_product_id' => $stock->user_product_id,
            'physical_key' => $physicalKey,
            'connected_rows' => (int) $connection['rows'],
            'connected_publications' => (int) $connection['publications'],
            'shares_inventory' => (int) $connection['rows'] > 1,
            'stock_source' => $stock->stock_source,
            'full_available_quantity' => $stock->full_available_quantity,
            'full_not_available_quantity' => $stock->full_not_available_quantity,
            'full_total_quantity' => $stock->full_total_quantity,
            'not_available_detail' => $stock->not_available_detail ?? [],
            'last_error' => $stock->last_error,
            'synced_at' => optional($stock->synced_at)->format('d/m/Y H:i'),
        ];
    }
}
