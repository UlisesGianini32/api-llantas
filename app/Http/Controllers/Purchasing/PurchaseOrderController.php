<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $poService
    ) {}

    public function index(Request $request): Response
    {
        $query = PurchaseOrder::query()
            ->with(['location:id,code,name', 'buyer:id,name'])
            ->withCount('items');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('supplier')) {
            $query->where('supplier_name', 'like', '%'.$request->input('supplier').'%');
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }

        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', "%{$s}%")
                    ->orWhere('supplier_name', 'like', "%{$s}%")
                    ->orWhere('supplier_quote_reference', 'like', "%{$s}%");
            });
        }

        $orders = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $summary = [
            'total_orders' => PurchaseOrder::count(),
            'draft_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_DRAFT)->count(),
            'ordered_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_ORDERED)->count(),
            'partial_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_PARTIAL)->count(),
            'received_count' => PurchaseOrder::where('status', PurchaseOrder::STATUS_RECEIVED)->count(),
            'total_invested' => (float) PurchaseOrder::whereNotIn('status', [PurchaseOrder::STATUS_CANCELLED])->sum('total_cost'),
        ];

        $suppliers = PurchaseOrder::distinct()->whereNotNull('supplier_name')->pluck('supplier_name');
        $brands = PurchaseOrder::distinct()->whereNotNull('brand')->where('brand', '!=', '')->pluck('brand');

        return Inertia::render('Purchasing/Index', [
            'orders' => $orders,
            'summary' => $summary,
            'suppliers' => $suppliers,
            'brands' => $brands,
            'filters' => $request->only(['status', 'supplier', 'brand', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->orderBy('brand')
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'brand', 'supplier', 'cost']);

        $registeredSuppliers = Supplier::query()
            ->where('is_active', true)
            ->with('brands')
            ->orderBy('name')
            ->get(['id', 'name', 'contact_name', 'phone', 'email', 'lead_time_days', 'credit_days']);

        return Inertia::render('Purchasing/Create', [
            'locations' => $locations,
            'products' => $products,
            'registeredSuppliers' => $registeredSuppliers,
            'prefillSupplier' => $request->input('supplier', ''),
            'prefillBrand' => $request->input('brand', ''),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $order = $this->poService->createOrder($validated, $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$order->order_number} creada en borrador.",
                    'order' => $order,
                ]);
            }

            return redirect()->route('purchasing.orders.show', $order)
                ->with('success', "Orden de compra {$order->order_number} creada en borrador.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'location:id,code,name',
            'buyer:id,name,email',
            'cancelledBy:id,name',
            'items.product:id,sku,name,brand,cost',
            'receipts.receivedBy:id,name',
            'receipts.location:id,code,name',
            'receipts.items.product:id,sku,name',
        ]);

        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->get(['id', 'code', 'name']);

        return Inertia::render('Purchasing/Show', [
            'order' => $purchaseOrder,
            'locations' => $locations,
        ]);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'expected_delivery_date' => ['nullable', 'date'],
        ]);

        try {
            $updated = $this->poService->markAsOrdered(
                $purchaseOrder,
                $validated['supplier_quote_reference'] ?? null,
                $validated['expected_delivery_date'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$updated->order_number} enviada a proveedor.",
                    'order' => $updated,
                ]);
            }

            return back()->with('success', "Orden de compra {$updated->order_number} marcada como enviada.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $receipt = $this->poService->receiveItems(
                $purchaseOrder,
                $validated['items'],
                $request->user(),
                $validated['inventory_location_id'] ?? null,
                $validated['carrier'] ?? null,
                $validated['tracking_number'] ?? null,
                $validated['notes'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Recepción {$receipt->receipt_number} procesada e inventario ingresado al almacén.",
                    'receipt' => $receipt,
                    'order' => $purchaseOrder->fresh(),
                ]);
            }

            return back()->with('success', "Recepción {$receipt->receipt_number} procesada correctamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $cancelled = $this->poService->cancelOrder($purchaseOrder, $request->user(), $validated['reason']);

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$cancelled->order_number} cancelada exitosamente.",
                    'order' => $cancelled,
                ]);
            }

            return back()->with('success', "Orden de compra {$cancelled->order_number} cancelada.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }
}
