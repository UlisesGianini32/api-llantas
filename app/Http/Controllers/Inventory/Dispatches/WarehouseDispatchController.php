<?php

namespace App\Http\Controllers\Inventory\Dispatches;

use App\Exceptions\WarehouseDispatchConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\Dispatches\CancelConfirmedWarehouseDispatchRequest;
use App\Http\Requests\Inventory\Dispatches\ConfirmWarehouseDispatchRequest;
use App\Http\Requests\Inventory\Dispatches\StoreWarehouseDispatchRequest;
use App\Http\Requests\Inventory\Dispatches\UpdateWarehouseDispatchRequest;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchDestination;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryLocation;
use App\Models\InventoryProvisionalItem;
use App\Services\Inventory\Dispatches\WarehouseDispatchFeatureGuard;
use App\Services\Inventory\Dispatches\WarehouseDispatchPresenter;
use App\Services\Inventory\Dispatches\WarehouseDispatchSearchService;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Support\InventoryDateTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseDispatchController extends Controller
{
    public function __construct(
        private readonly WarehouseDispatchService $service,
        private readonly WarehouseDispatchSearchService $searchService,
        private readonly WarehouseDispatchFeatureGuard $guard,
        private readonly WarehouseDispatchPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', InventoryDispatch::class);

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'destination_id' => $request->input('destination_id') ? (int) $request->input('destination_id') : null,
            'operation_type' => trim((string) $request->input('operation_type', '')),
            'status' => trim((string) $request->input('status', '')),
            'apply_status' => trim((string) $request->input('apply_status', '')),
            'brand' => trim((string) $request->input('brand', '')),
            'product_search' => trim((string) $request->input('product_search', '')),
            'date_from' => trim((string) $request->input('date_from', '')),
            'date_to' => trim((string) $request->input('date_to', '')),
            'only_provisional' => $request->boolean('only_provisional'),
            'only_discrepancies' => $request->boolean('only_discrepancies'),
        ];

        $query = InventoryDispatch::query()
            ->with(['destination:id,code,name,destination_type', 'deliveredBy:id,name'])
            ->withCount([
                'items as provisional_items_count' => fn ($q) => $q->whereIn('identification_status', [
                    InventoryDispatchItem::ID_PROVISIONAL,
                    InventoryDispatchItem::ID_AMBIGUOUS_PENDING,
                ]),
                'items as discrepancy_items_count' => fn ($q) => $q->whereIn('inventory_apply_status', [
                    InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK,
                    InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION,
                ]),
                'items as pending_apply_items_count' => fn ($q) => $q->where('inventory_apply_status', InventoryDispatchItem::APPLY_SKIPPED_LEDGER_DISABLED),
            ]);

        $this->applyListFilters($query, $filters);

        $dispatches = $query
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $dispatches->through(fn (InventoryDispatch $dispatch): array => $this->presenter->presentDispatchListItem($dispatch, $user));

        $destinations = InventoryDispatchDestination::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'destination_type', 'is_active'])
            ->values()
            ->all();

        $summary = $this->buildInformativeSummary($filters);

        return Inertia::render('Inventory/Dispatches/Index', [
            'dispatches' => $dispatches,
            'summary' => $summary,
            'destinations' => $destinations,
            'filters' => $filters,
            'operationTypes' => InventoryDispatch::OPERATION_TYPES,
            'statuses' => InventoryDispatch::STATUSES,
            'applyStatuses' => InventoryDispatchItem::APPLY_STATUSES,
            'operatingMode' => $this->guard->operatingMode(),
            'capabilities' => $this->presenter->presentCapabilities($user),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', InventoryDispatch::class);

        $filters = [
            'destination_id' => $request->input('destination_id') ? (int) $request->input('destination_id') : null,
            'operation_type' => trim((string) $request->input('operation_type', '')),
            'brand' => trim((string) $request->input('brand', '')),
            'product_search' => trim((string) $request->input('product_search', '')),
            'date_from' => trim((string) $request->input('date_from', '')),
            'date_to' => trim((string) $request->input('date_to', '')),
            'only_provisional' => $request->boolean('only_provisional'),
        ];

        return response()->json([
            'informative_only' => true,
            'rows' => $this->buildInformativeSummary($filters),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('exportSummary', InventoryDispatch::class);

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'destination_id' => $request->input('destination_id') ? (int) $request->input('destination_id') : null,
            'operation_type' => trim((string) $request->input('operation_type', '')),
            'status' => trim((string) $request->input('status', '')),
            'apply_status' => trim((string) $request->input('apply_status', '')),
            'brand' => trim((string) $request->input('brand', '')),
            'product_search' => trim((string) $request->input('product_search', '')),
            'date_from' => trim((string) $request->input('date_from', '')),
            'date_to' => trim((string) $request->input('date_to', '')),
            'only_provisional' => $request->boolean('only_provisional'),
            'only_discrepancies' => $request->boolean('only_discrepancies'),
        ];

        $canViewCosts = Gate::forUser($user)->allows('viewCosts', InventoryDispatch::class);
        $canViewStylist = Gate::forUser($user)->allows('viewStylistPrices', InventoryDispatch::class);

        $query = InventoryDispatchItem::query()
            ->join('inventory_dispatches as d', 'd.id', '=', 'inventory_dispatch_items.inventory_dispatch_id')
            ->leftJoin('inventory_provisional_items as pi', 'pi.id', '=', 'inventory_dispatch_items.provisional_item_id')
            ->select([
                'd.dispatch_number',
                'd.status as dispatch_status',
                'd.operation_type',
                'd.effective_at',
                'd.destination_code_snapshot',
                'd.destination_name_snapshot',
                'd.delivered_by_name_snapshot',
                'd.received_by_name',
                'inventory_dispatch_items.identification_status',
                'inventory_dispatch_items.inventory_apply_status',
                'inventory_dispatch_items.captured_sku',
                'inventory_dispatch_items.captured_barcode',
                'inventory_dispatch_items.captured_name',
                'inventory_dispatch_items.captured_brand',
                'inventory_dispatch_items.captured_tone',
                'inventory_dispatch_items.captured_presentation',
                'inventory_dispatch_items.location_code_snapshot',
                'inventory_dispatch_items.captured_quantity',
                'inventory_dispatch_items.captured_unit_of_measure',
                'inventory_dispatch_items.unit_conversion_factor',
                'inventory_dispatch_items.normalized_physical_quantity',
                'inventory_dispatch_items.unit_cost_snapshot',
                'inventory_dispatch_items.unit_price_stylist_snapshot',
                'pi.provisional_code',
            ]);

        if ($filters['destination_id']) {
            $query->where('d.destination_id', $filters['destination_id']);
        }
        if ($filters['operation_type'] !== '') {
            $query->where('d.operation_type', $filters['operation_type']);
        }
        if ($filters['status'] !== '') {
            $query->where('d.status', $filters['status']);
        }
        if ($filters['apply_status'] !== '') {
            $query->where('inventory_dispatch_items.inventory_apply_status', $filters['apply_status']);
        }
        if ($filters['brand'] !== '') {
            $query->where('inventory_dispatch_items.captured_brand', 'like', "%{$filters['brand']}%");
        }
        if ($filters['date_from'] !== '') {
            $fromUtc = InventoryDateTime::toUtc($filters['date_from'].' 00:00:00');
            if ($fromUtc) {
                $query->where('d.effective_at', '>=', $fromUtc);
            }
        }
        if ($filters['date_to'] !== '') {
            $toUtc = InventoryDateTime::toUtc($filters['date_to'].' 23:59:59');
            if ($toUtc) {
                $query->where('d.effective_at', '<=', $toUtc);
            }
        }

        $rows = $query->orderByDesc('d.effective_at')->orderByDesc('d.id')->orderBy('inventory_dispatch_items.line_order')->get();
        $filename = 'salidas-almacen-'.now(InventoryDateTime::OPERATING_TIMEZONE)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $canViewCosts, $canViewStylist): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");

            $headers = [
                'Folio',
                'Estado',
                'Tipo Operacion',
                'Fecha Efectiva (Hermosillo)',
                'Destino Codigo',
                'Destino Nombre',
                'Entrego',
                'Recibio',
                'Identificacion',
                'Estado Inventario',
                'Codigo Provisional',
                'SKU',
                'Codigo Barras',
                'Producto',
                'Marca',
                'Tono',
                'Presentacion',
                'Ubicacion',
                'Cantidad Capturada',
                'Unidad Capturada',
                'Factor Conversion',
                'Piezas Fisicas Normalizadas',
            ];
            if ($canViewCosts) {
                $headers[] = 'Costo Unitario Referencial';
            }
            if ($canViewStylist) {
                $headers[] = 'Precio Estilista Referencial';
            }

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                $effLocal = $row->effective_at
                    ? \Carbon\Carbon::parse($row->effective_at, 'UTC')->setTimezone(InventoryDateTime::OPERATING_TIMEZONE)->format('Y-m-d H:i')
                    : '';

                $line = [
                    $this->sanitizeCsvCell($row->dispatch_number),
                    $this->sanitizeCsvCell($row->dispatch_status),
                    $this->sanitizeCsvCell($row->operation_type),
                    $effLocal,
                    $this->sanitizeCsvCell($row->destination_code_snapshot),
                    $this->sanitizeCsvCell($row->destination_name_snapshot),
                    $this->sanitizeCsvCell($row->delivered_by_name_snapshot),
                    $this->sanitizeCsvCell($row->received_by_name),
                    $this->sanitizeCsvCell($row->identification_status),
                    $this->sanitizeCsvCell($row->inventory_apply_status),
                    $this->sanitizeCsvCell($row->provisional_code),
                    $this->sanitizeCsvCell($row->captured_sku),
                    $this->sanitizeCsvCell($row->captured_barcode),
                    $this->sanitizeCsvCell($row->captured_name),
                    $this->sanitizeCsvCell($row->captured_brand),
                    $this->sanitizeCsvCell($row->captured_tone),
                    $this->sanitizeCsvCell($row->captured_presentation),
                    $this->sanitizeCsvCell($row->location_code_snapshot),
                    (int) $row->captured_quantity,
                    $this->sanitizeCsvCell($row->captured_unit_of_measure),
                    $row->unit_conversion_factor !== null ? (int) $row->unit_conversion_factor : '',
                    $row->normalized_physical_quantity !== null ? (int) $row->normalized_physical_quantity : '',
                ];
                if ($canViewCosts) {
                    $line[] = $row->unit_cost_snapshot !== null ? number_format((float) $row->unit_cost_snapshot, 2, '.', '') : '';
                }
                if ($canViewStylist) {
                    $line[] = ($row->operation_type === InventoryDispatch::OP_SALON_SALE && $row->unit_price_stylist_snapshot !== null)
                        ? number_format((float) $row->unit_price_stylist_snapshot, 2, '.', '')
                        : '';
                }

                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('create', InventoryDispatch::class);

        return Inertia::render('Inventory/Dispatches/Form', [
            'dispatch' => null,
            'destinations' => InventoryDispatchDestination::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'destination_type', 'default_operation_type', 'contact_name'])
                ->values()
                ->all(),
            'locations' => InventoryLocation::query()
                ->where('is_active', true)
                ->orderByRaw('sort_order IS NULL')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->values()
                ->all(),
            'operationTypes' => InventoryDispatch::OPERATION_TYPES,
            'unitsOfMeasure' => InventoryProvisionalItem::UNITS_OF_MEASURE,
            'individualUnits' => InventoryProvisionalItem::INDIVIDUAL_UNITS,
            'operatingMode' => $this->guard->operatingMode(),
            'retroactiveToleranceHours' => $this->guard->retroactiveToleranceHours(),
            'capabilities' => $this->presenter->presentCapabilities($user),
        ]);
    }

    public function store(StoreWarehouseDispatchRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $dispatch = $this->service->createDraft($request->validated(), $request->user());
        } catch (WarehouseDispatchConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->withErrors(['items' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'dispatch' => $this->presenter->presentDispatchDetail($dispatch, $request->user()),
            ], 201);
        }

        return redirect()
            ->route('inventory.dispatches.show', $dispatch)
            ->with('success', "Borrador {$dispatch->dispatch_number} guardado correctamente.");
    }

    public function searchProducts(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', InventoryDispatch::class);

        $result = $this->searchService->search(
            query: (string) $request->input('q', ''),
            mode: (string) $request->input('mode', 'search'),
            user: $request->user(),
            brand: $request->input('brand'),
            tone: $request->input('tone'),
            preferredLocationId: $request->input('location_id') ? (int) $request->input('location_id') : null,
        );

        return response()->json($result);
    }

    public function show(Request $request, InventoryDispatch $inventoryDispatch): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $inventoryDispatch);

        $inventoryDispatch->load([
            'destination:id,code,name,destination_type,contact_name',
            'sourceLocation:id,code,name',
            'deliveredBy:id,name',
            'confirmedBy:id,name',
            'cancelledBy:id,name',
            'items.product:id,sku,name,barcode,brand,is_active',
            'items.provisionalItem:id,provisional_code,name,status,canonical_provisional_id',
            'items.location:id,code,name',
            'events.actor:id,name',
        ]);

        return Inertia::render('Inventory/Dispatches/Show', [
            'dispatch' => $this->presenter->presentDispatchDetail($inventoryDispatch, $user),
            'operatingMode' => $this->guard->operatingMode(),
            'capabilities' => $this->presenter->presentCapabilities($user, $inventoryDispatch),
        ]);
    }

    public function edit(Request $request, InventoryDispatch $inventoryDispatch): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $inventoryDispatch->isDraft()) {
            return redirect()
                ->route('inventory.dispatches.show', $inventoryDispatch)
                ->with('warning', 'Solo los borradores pueden editarse.');
        }

        Gate::forUser($user)->authorize('update', $inventoryDispatch);

        $inventoryDispatch->load([
            'destination:id,code,name,destination_type',
            'sourceLocation:id,code,name',
            'items.product:id,sku,name,barcode,brand,is_active',
            'items.provisionalItem:id,provisional_code,name,status',
            'items.location:id,code,name',
        ]);

        return Inertia::render('Inventory/Dispatches/Form', [
            'dispatch' => $this->presenter->presentDispatchDetail($inventoryDispatch, $user),
            'destinations' => InventoryDispatchDestination::query()
                ->where(function ($q) use ($inventoryDispatch): void {
                    $q->where('is_active', true)->orWhere('id', $inventoryDispatch->destination_id);
                })
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'destination_type', 'default_operation_type', 'contact_name', 'is_active'])
                ->values()
                ->all(),
            'locations' => InventoryLocation::query()
                ->where('is_active', true)
                ->orderByRaw('sort_order IS NULL')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->values()
                ->all(),
            'operationTypes' => InventoryDispatch::OPERATION_TYPES,
            'unitsOfMeasure' => InventoryProvisionalItem::UNITS_OF_MEASURE,
            'individualUnits' => InventoryProvisionalItem::INDIVIDUAL_UNITS,
            'operatingMode' => $this->guard->operatingMode(),
            'retroactiveToleranceHours' => $this->guard->retroactiveToleranceHours(),
            'capabilities' => $this->presenter->presentCapabilities($user, $inventoryDispatch),
        ]);
    }

    public function update(
        UpdateWarehouseDispatchRequest $request,
        InventoryDispatch $inventoryDispatch,
    ): RedirectResponse|JsonResponse {
        try {
            $updated = $this->service->updateDraft($inventoryDispatch, $request->validated(), $request->user());
        } catch (WarehouseDispatchConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withInput()->withErrors(['items' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'dispatch' => $this->presenter->presentDispatchDetail($updated, $request->user()),
            ]);
        }

        return redirect()
            ->route('inventory.dispatches.show', $updated)
            ->with('success', "Borrador {$updated->dispatch_number} actualizado.");
    }

    public function confirm(
        ConfirmWarehouseDispatchRequest $request,
        InventoryDispatch $inventoryDispatch,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();

        try {
            $confirmed = $this->service->confirm(
                $inventoryDispatch,
                $request->user(),
                (string) $validated['confirm_idempotency_key'],
                isset($validated['expected_draft_version']) ? (int) $validated['expected_draft_version'] : null,
            );
        } catch (WarehouseDispatchConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return redirect()
                ->route('inventory.dispatches.show', $inventoryDispatch)
                ->with('error', $exception->getMessage());
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return redirect()
                ->route('inventory.dispatches.show', $inventoryDispatch)
                ->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'dispatch' => $this->presenter->presentDispatchDetail($confirmed, $request->user()),
            ]);
        }

        return redirect()
            ->route('inventory.dispatches.show', $confirmed)
            ->with('success', "Salida {$confirmed->dispatch_number} confirmada correctamente.");
    }

    public function cancelDraft(Request $request, InventoryDispatch $inventoryDispatch): RedirectResponse|JsonResponse
    {
        Gate::forUser($request->user())->authorize('cancelDraft', $inventoryDispatch);

        try {
            $cancelled = $this->service->cancelDraft(
                $inventoryDispatch,
                $request->user(),
                $request->input('cancel_reason'),
            );
        } catch (WarehouseDispatchConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return back()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'dispatch' => $this->presenter->presentDispatchDetail($cancelled, $request->user()),
            ]);
        }

        return redirect()
            ->route('inventory.dispatches.show', $cancelled)
            ->with('success', "Borrador {$cancelled->dispatch_number} cancelado.");
    }

    public function cancelConfirmed(
        CancelConfirmedWarehouseDispatchRequest $request,
        InventoryDispatch $inventoryDispatch,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();

        try {
            $cancelled = $this->service->cancelConfirmedWithoutLedger(
                $inventoryDispatch,
                $request->user(),
                (string) $validated['cancel_reason'],
                (string) $validated['physical_situation'],
            );
        } catch (WarehouseDispatchConflictException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return redirect()
                ->route('inventory.dispatches.show', $inventoryDispatch)
                ->with('error', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->withErrors(['cancel_reason' => $exception->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'dispatch' => $this->presenter->presentDispatchDetail($cancelled, $request->user()),
            ]);
        }

        return redirect()
            ->route('inventory.dispatches.show', $cancelled)
            ->with('success', "Salida {$cancelled->dispatch_number} anulada (sin movimientos de inventario).");
    }

    public function pdf(Request $request, InventoryDispatch $inventoryDispatch): HttpResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewPdf', $inventoryDispatch);

        $includePricesRequested = $request->boolean('include_prices');
        if ($includePricesRequested) {
            Gate::forUser($user)->authorize('showPricesInPdf', $inventoryDispatch);
        }

        $inventoryDispatch->load([
            'destination:id,code,name,destination_type,contact_name,phone,address',
            'deliveredBy:id,name',
            'confirmedBy:id,name',
            'items.provisionalItem:id,provisional_code',
        ]);

        // Use presenter so financial fields are strictly stripped unless authorized.
        $presented = $this->presenter->presentDispatchDetail($inventoryDispatch, $user);

        // Costs are NEVER shown on the printable delivery receipt (H06).
        unset($presented['reference_subtotal_cost'], $presented['reference_cost_complete']);
        foreach ($presented['items'] as &$item) {
            unset($item['unit_cost_snapshot']);
            if (! $includePricesRequested) {
                unset($item['unit_price_stylist_snapshot']);
            }
        }
        unset($item);

        if (! $includePricesRequested) {
            unset($presented['reference_subtotal_stylist'], $presented['reference_stylist_complete']);
        }

        $effectiveLocal = $inventoryDispatch->effective_at
            ? $inventoryDispatch->effective_at->copy()->setTimezone(InventoryDateTime::OPERATING_TIMEZONE)->format('d/m/Y H:i')
            : '—';

        return response()
            ->view('inventory.dispatches.receipt-pdf', [
                'dispatch' => $presented,
                'effectiveLocal' => $effectiveLocal,
                'includePrices' => $includePricesRequested,
                'canTogglePrices' => Gate::forUser($user)->allows('showPricesInPdf', $inventoryDispatch),
            ])
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyListFilters($query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s): void {
                $q->where('dispatch_number', 'like', "%{$s}%")
                    ->orWhere('destination_code_snapshot', 'like', "%{$s}%")
                    ->orWhere('destination_name_snapshot', 'like', "%{$s}%")
                    ->orWhere('received_by_name', 'like', "%{$s}%")
                    ->orWhere('notes', 'like', "%{$s}%");
            });
        }

        if (! empty($filters['destination_id'])) {
            $query->where('destination_id', (int) $filters['destination_id']);
        }

        if (! empty($filters['operation_type'])) {
            $query->where('operation_type', $filters['operation_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $fromUtc = InventoryDateTime::toUtc($filters['date_from'].' 00:00:00');
            if ($fromUtc) {
                $query->where('effective_at', '>=', $fromUtc);
            }
        }

        if (! empty($filters['date_to'])) {
            $toUtc = InventoryDateTime::toUtc($filters['date_to'].' 23:59:59');
            if ($toUtc) {
                $query->where('effective_at', '<=', $toUtc);
            }
        }

        if (! empty($filters['apply_status'])) {
            $applyStatus = $filters['apply_status'];
            $query->whereHas('items', fn ($q) => $q->where('inventory_apply_status', $applyStatus));
        }

        if (! empty($filters['brand'])) {
            $brand = $filters['brand'];
            $query->whereHas('items', fn ($q) => $q->where('captured_brand', 'like', "%{$brand}%"));
        }

        if (! empty($filters['product_search'])) {
            $ps = $filters['product_search'];
            $query->whereHas('items', function ($q) use ($ps): void {
                $q->where('captured_name', 'like', "%{$ps}%")
                    ->orWhere('captured_sku', 'like', "%{$ps}%")
                    ->orWhere('captured_barcode', 'like', "%{$ps}%")
                    ->orWhere('captured_tone', 'like', "%{$ps}%");
            });
        }

        if (! empty($filters['only_provisional'])) {
            $query->whereHas('items', fn ($q) => $q->whereIn('identification_status', [
                InventoryDispatchItem::ID_PROVISIONAL,
                InventoryDispatchItem::ID_AMBIGUOUS_PENDING,
            ]));
        }

        if (! empty($filters['only_discrepancies'])) {
            $query->whereHas('items', fn ($q) => $q->whereIn('inventory_apply_status', [
                InventoryDispatchItem::APPLY_DISCREPANCY_INSUFFICIENT_STOCK,
                InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION,
            ]));
        }
    }

    /**
     * Informative-only summary grouped by registered product or canonical provisional identity & operation_type.
     * Does NOT feed InventoryDemandForecastingService (Fase 3) and NEVER sums unconverted containers with physical pieces.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function buildInformativeSummary(array $filters): array
    {
        $query = DB::table('inventory_dispatch_items as di')
            ->join('inventory_dispatches as d', 'd.id', '=', 'di.inventory_dispatch_id')
            ->leftJoin('inventory_products as p', 'p.id', '=', 'di.inventory_product_id')
            ->leftJoin('inventory_provisional_items as pi', 'pi.id', '=', 'di.provisional_item_id')
            ->leftJoin('inventory_provisional_items as canon', 'canon.id', '=', 'pi.canonical_provisional_id')
            ->where('d.status', InventoryDispatch::STATUS_CONFIRMED);

        if (! empty($filters['destination_id'])) {
            $query->where('d.destination_id', (int) $filters['destination_id']);
        }
        if (! empty($filters['operation_type'])) {
            $query->where('d.operation_type', $filters['operation_type']);
        }
        if (! empty($filters['brand'])) {
            $query->where('di.captured_brand', 'like', "%{$filters['brand']}%");
        }
        if (! empty($filters['product_search'])) {
            $ps = $filters['product_search'];
            $query->where(function ($q) use ($ps): void {
                $q->where('di.captured_name', 'like', "%{$ps}%")
                    ->orWhere('di.captured_sku', 'like', "%{$ps}%")
                    ->orWhere('di.captured_barcode', 'like', "%{$ps}%")
                    ->orWhere('di.captured_tone', 'like', "%{$ps}%");
            });
        }
        if (! empty($filters['date_from'])) {
            $fromUtc = InventoryDateTime::toUtc($filters['date_from'].' 00:00:00');
            if ($fromUtc) {
                $query->where('d.effective_at', '>=', $fromUtc);
            }
        }
        if (! empty($filters['date_to'])) {
            $toUtc = InventoryDateTime::toUtc($filters['date_to'].' 23:59:59');
            if ($toUtc) {
                $query->where('d.effective_at', '<=', $toUtc);
            }
        }
        if (! empty($filters['only_provisional'])) {
            $query->whereNull('di.inventory_product_id');
        }

        $rows = $query
            ->selectRaw("CASE WHEN di.inventory_product_id IS NOT NULL THEN 'REGISTERED' ELSE 'PROVISIONAL' END as item_kind")
            ->selectRaw('di.inventory_product_id')
            ->selectRaw('COALESCE(pi.canonical_provisional_id, di.provisional_item_id) as canonical_provisional_id')
            ->selectRaw('d.operation_type')
            ->selectRaw('MAX(COALESCE(p.sku, canon.provisional_code, pi.provisional_code, di.captured_sku)) as display_code')
            ->selectRaw('MAX(COALESCE(p.name, canon.name, pi.name, di.captured_name)) as display_name')
            ->selectRaw('MAX(COALESCE(p.brand, canon.brand, pi.brand, di.captured_brand)) as display_brand')
            ->selectRaw('MAX(COALESCE(canon.tone, pi.tone, di.captured_tone)) as display_tone')
            ->selectRaw('COUNT(DISTINCT d.id) as dispatches_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN di.normalized_physical_quantity IS NOT NULL THEN di.normalized_physical_quantity ELSE 0 END), 0) as total_physical_units')
            ->selectRaw('COALESCE(SUM(CASE WHEN di.normalized_physical_quantity IS NULL THEN di.captured_quantity ELSE 0 END), 0) as unconverted_containers_qty')
            ->selectRaw("SUM(CASE WHEN di.inventory_apply_status = 'SKIPPED_LEDGER_DISABLED' THEN 1 ELSE 0 END) as pending_ledger_lines")
            ->groupBy(
                DB::raw("CASE WHEN di.inventory_product_id IS NOT NULL THEN 'REGISTERED' ELSE 'PROVISIONAL' END"),
                'di.inventory_product_id',
                DB::raw('COALESCE(pi.canonical_provisional_id, di.provisional_item_id)'),
                'd.operation_type',
            )
            ->orderByDesc('total_physical_units')
            ->orderByDesc('unconverted_containers_qty')
            ->limit(100)
            ->get();

        return $rows->map(fn ($row): array => [
            'item_kind' => (string) $row->item_kind,
            'inventory_product_id' => $row->inventory_product_id ? (int) $row->inventory_product_id : null,
            'canonical_provisional_id' => $row->canonical_provisional_id ? (int) $row->canonical_provisional_id : null,
            'operation_type' => (string) $row->operation_type,
            'display_code' => $row->display_code,
            'display_name' => (string) $row->display_name,
            'display_brand' => $row->display_brand,
            'display_tone' => $row->display_tone,
            'dispatches_count' => (int) $row->dispatches_count,
            'total_physical_units' => (int) $row->total_physical_units,
            'unconverted_containers_qty' => (int) $row->unconverted_containers_qty,
            'pending_ledger_lines' => (int) $row->pending_ledger_lines,
        ])->values()->all();
    }

    private function sanitizeCsvCell(mixed $value): string
    {
        $str = (string) ($value ?? '');
        if ($str !== '' && preg_match('/^[=+\-@\t\r]/', $str) === 1) {
            return "'".$str;
        }

        return $str;
    }
}
