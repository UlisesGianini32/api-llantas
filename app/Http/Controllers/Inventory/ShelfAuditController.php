<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
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

        if ($request->filled('shelf_code')) {
            $query->where('shelf_code', (string) $request->input('shelf_code'));
        } elseif ($request->filled('location_id')) {
            $query->where('inventory_location_id', (int) $request->input('location_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('audit_number', 'like', "%{$search}%")
                    ->orWhere('shelf_code', 'like', "%{$search}%")
                    ->orWhereHas('location', function ($locQ) use ($search) {
                        $locQ->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $audits = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $shelvesCount = InventoryShelfAudit::distinct('shelf_code')->count('shelf_code');
        if ($shelvesCount === 0) {
            $shelvesCount = InventoryShelfAudit::distinct('inventory_location_id')->count('inventory_location_id');
        }

        $summary = [
            'total_audits' => InventoryShelfAudit::count(),
            'applied_audits' => InventoryShelfAudit::where('status', InventoryShelfAudit::STATUS_APPLIED)->count(),
            'in_progress_audits' => InventoryShelfAudit::where('status', InventoryShelfAudit::STATUS_IN_PROGRESS)->count(),
            'shelves_audited_count' => $shelvesCount,
            'net_difference_units' => (int) InventoryShelfAudit::where('status', InventoryShelfAudit::STATUS_APPLIED)->sum('total_difference_units'),
        ];

        $shelves = $this->auditService->getShelves();

        return Inertia::render('Inventory/Audits/Index', [
            'audits' => $audits,
            'summary' => $summary,
            'shelves' => $shelves,
            'filters' => $request->only(['shelf_code', 'location_id', 'status', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $shelves = $this->auditService->getShelves();
        $brands = $this->auditService->getBrands();

        $auditType = $request->input('audit_type', 'shelf') === 'brand' ? 'brand' : 'shelf';
        $preselectedShelfCode = $request->filled('shelf_code')
            ? (string) $request->input('shelf_code')
            : ($shelves[0]['shelf_code'] ?? null);
        $preselectedBrand = $request->filled('brand_name')
            ? (string) $request->input('brand_name')
            : ($brands[0]['brand'] ?? null);

        $initialProducts = [];
        if ($auditType === 'brand' && $preselectedBrand) {
            $initialProducts = $this->auditService->getBrandExpectedProducts($preselectedBrand)->values()->all();
        } elseif ($preselectedShelfCode) {
            $initialProducts = $this->auditService->getShelfExpectedProducts($preselectedShelfCode)->values()->all();
        }

        return Inertia::render('Inventory/Audits/Create', [
            'shelves' => $shelves,
            'brands' => $brands,
            'initialAuditType' => $auditType,
            'preselectedShelfCode' => $preselectedShelfCode,
            'preselectedBrand' => $preselectedBrand,
            'initialProducts' => $initialProducts,
        ]);
    }

    public function getShelfProducts(Request $request, string $shelfIdentifier): JsonResponse
    {
        $locations = $this->auditService->getLocationsForShelf($shelfIdentifier);
        $primaryLoc = $locations->first();
        $shelfCode = ShelfAuditService::extractShelfCode($primaryLoc ? $primaryLoc->code : $shelfIdentifier);

        $products = $this->auditService->getShelfExpectedProducts($shelfIdentifier);

        return response()->json([
            'ok' => true,
            'shelf' => [
                'shelf_code' => $shelfCode,
                'name' => $primaryLoc?->name,
                'sub_locations_count' => $locations->count(),
                'sub_locations' => $locations->pluck('code')->all(),
            ],
            'products' => $products->values()->all(),
        ]);
    }

    public function getBrandProducts(Request $request, string $brandName): JsonResponse
    {
        $cleanBrand = urldecode(trim($brandName));
        $products = $this->auditService->getBrandExpectedProducts($cleanBrand);

        return response()->json([
            'ok' => true,
            'brand' => [
                'name' => $cleanBrand,
                'products_count' => $products->count(),
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

        $products = InventoryProduct::query()
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
            'audit_type' => ['nullable', 'string', 'in:shelf,brand'],
            'shelf_code' => ['nullable', 'string', 'max:50'],
            'brand_name' => ['nullable', 'string', 'max:150'],
            'inventory_location_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'apply_immediately' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.system_quantity' => ['required', 'integer'],
            'items.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'items.*.location_breakdown' => ['nullable', 'string', 'max:500'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $auditType = $validated['audit_type'] ?? 'shelf';

        if ($auditType === 'brand' && empty($validated['brand_name'])) {
            return back()->with('error', 'Debes seleccionar una marca a auditar.');
        }

        if ($auditType === 'shelf' && empty($validated['shelf_code']) && empty($validated['inventory_location_id'])) {
            return back()->with('error', 'Debes seleccionar un anaquel.');
        }

        try {
            $applyImmediately = ! empty($validated['apply_immediately']);
            $audit = $this->auditService->createAudit($validated, $request->user(), $applyImmediately);

            $targetLabel = $audit->audit_type === 'brand'
                ? "la Marca {$audit->brand_name}"
                : "el Anaquel ".($audit->shelf_code ?: ($audit->location?->code ?? 'General'));

            $message = $applyImmediately
                ? "¡Auditoría {$audit->audit_number} aplicada con éxito! El inventario de {$targetLabel} fue ajustado a lo contado."
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

        $locations = collect([]);
        if ($shelfAudit->audit_type !== 'brand') {
            $shelfIdentifier = $shelfAudit->shelf_code ?: $shelfAudit->inventory_location_id;
            $locations = $this->auditService->getLocationsForShelf($shelfIdentifier);
        }

        return Inertia::render('Inventory/Audits/Show', [
            'audit' => $shelfAudit,
            'shelf' => [
                'shelf_code' => $shelfAudit->shelf_code ?: ShelfAuditService::extractShelfCode($shelfAudit->location?->code ?? ''),
                'brand_name' => $shelfAudit->brand_name,
                'audit_type' => $shelfAudit->audit_type ?: 'shelf',
                'name' => $shelfAudit->location?->name,
                'sub_locations_count' => $locations->count(),
                'sub_locations' => $locations->pluck('code')->all(),
            ],
        ]);
    }

    public function apply(Request $request, InventoryShelfAudit $shelfAudit): JsonResponse|RedirectResponse
    {
        try {
            $audit = $this->auditService->applyAuditAdjustment($shelfAudit, $request->user(), $request->input('notes'));
            $targetLabel = $audit->audit_type === 'brand'
                ? "la Marca {$audit->brand_name}"
                : "el Anaquel ".($audit->shelf_code ?: ($audit->location?->code ?? 'General'));

            $message = "¡Ajuste aplicado exitosamente! {$targetLabel} ha quedado cuadrado con el conteo físico.";

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
