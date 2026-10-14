<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryShelfAudit;
use App\Services\Inventory\ShelfAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ShelfAuditController extends Controller
{
    public function __construct(
        protected ShelfAuditService $auditService,
    ) {}

    public function index(Request $request): Response
    {
        $query = InventoryShelfAudit::query()
            ->with(['location:id,code,name', 'user:id,name', 'appliedBy:id,name']);

        if ($request->filled('location_id')) {
            $query->where('inventory_location_id', (int) $request->input('location_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('audit_number', 'like', "%{$search}%")
                    ->orWhereHas('location', function ($locQ) use ($search) {
                        $locQ->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $audits = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $summary = [
            'total_audits' => InventoryShelfAudit::count(),
            'applied_audits' => InventoryShelfAudit::where('status', InventoryShelfAudit::STATUS_APPLIED)->count(),
            'in_progress_audits' => InventoryShelfAudit::where('status', InventoryShelfAudit::STATUS_IN_PROGRESS)->count(),
            'locations_audited_count' => InventoryShelfAudit::distinct('inventory_location_id')->count('inventory_location_id'),
            'net_difference_units' => (int) InventoryShelfAudit::where('status', InventoryShelfAudit::STATUS_APPLIED)->sum('total_difference_units'),
        ];

        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('Inventory/Audits/Index', [
            'audits' => $audits,
            'summary' => $summary,
            'locations' => $locations,
            'filters' => $request->only(['location_id', 'status', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $preselectedLocationId = $request->filled('location_id')
            ? (int) $request->input('location_id')
            : ($locations[0]->id ?? null);

        $initialProducts = [];
        if ($preselectedLocationId) {
            $initialProducts = $this->auditService->getShelfExpectedProducts($preselectedLocationId)->values()->all();
        }

        return Inertia::render('Inventory/Audits/Create', [
            'locations' => $locations,
            'preselectedLocationId' => $preselectedLocationId,
            'initialProducts' => $initialProducts,
        ]);
    }

    public function getShelfProducts(InventoryLocation $inventoryLocation): JsonResponse
    {
        $products = $this->auditService->getShelfExpectedProducts($inventoryLocation->id);

        return response()->json([
            'ok' => true,
            'location' => [
                'id' => $inventoryLocation->id,
                'code' => $inventoryLocation->code,
                'name' => $inventoryLocation->name,
            ],
            'products' => $products->values()->all(),
        ]);
    }

    public function searchCatalog(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $products = \App\Models\InventoryProduct::query()
            ->with('primaryLocation:id,code,name')
            ->select(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'cost', 'primary_location_id', 'is_active'])
            ->where('is_active', true)
            ->where(function ($query) use ($q): void {
                $query->where('sku', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhere('barcode_secondary', 'like', "%{$q}%");
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'apply_immediately' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.system_quantity' => ['required', 'integer'],
            'items.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $applyImmediately = ! empty($validated['apply_immediately']);
            $audit = $this->auditService->createAudit($validated, $request->user(), $applyImmediately);

            $message = $applyImmediately
                ? "¡Auditoría {$audit->audit_number} aplicada con éxito! El inventario del anaquel {$audit->location->code} fue ajustado a lo contado."
                : "Auditoría {$audit->audit_number} guardada como borrador.";

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => $message,
                    'audit' => $audit,
                ]);
            }

            return redirect()->route('inventory.audits.show', $audit)
                ->with('success', $message);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(InventoryShelfAudit $shelfAudit): Response
    {
        $shelfAudit->load([
            'location:id,code,name',
            'user:id,name,email',
            'appliedBy:id,name,email',
            'items.product:id,sku,barcode,barcode_secondary,name,brand,cost,primary_location_id',
            'items.product.primaryLocation:id,code,name',
        ]);

        return Inertia::render('Inventory/Audits/Show', [
            'audit' => $shelfAudit,
        ]);
    }

    public function apply(Request $request, InventoryShelfAudit $shelfAudit): JsonResponse|RedirectResponse
    {
        try {
            $audit = $this->auditService->applyAuditAdjustment($shelfAudit, $request->user(), $request->input('notes'));

            $message = "¡Ajuste aplicado exitosamente! El anaquel {$audit->location->code} ha quedado cuadrado con el conteo físico.";

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => $message,
                    'audit' => $audit,
                ]);
            }

            return back()->with('success', $message);
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }
}
