<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceipt;
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
            'partial_count' => PurchaseOrder::whereIn('status', [PurchaseOrder::STATUS_PARTIAL, PurchaseOrder::STATUS_PARTIAL_CLOSED])->count(),
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
            ->with([
                'primaryLocation:id,code,name',
                'supplierProducts:id,inventory_product_id,supplier_id,supplier_name,supplier_sku,supplier_cost',
            ])
            ->where('is_active', true)
            ->where('product_type', InventoryProduct::SIMPLE)
            ->orderBy('brand')
            ->orderBy('name')
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'supplier', 'cost', 'primary_location_id']);

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
            'inventory_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:100'],
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

    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'items.*.supplier_sku' => ['nullable', 'string', 'max:100'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $updated = $this->poService->updateOrder($purchaseOrder, $validated, $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden de compra {$updated->order_number} actualizada exitosamente.",
                    'order' => $updated,
                ]);
            }

            return redirect()->route('purchasing.orders.show', $updated)
                ->with('success', "Orden de compra {$updated->order_number} actualizada exitosamente.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroyItem(Request $request, PurchaseOrder $purchaseOrder, \App\Models\PurchaseOrderItem $item): JsonResponse|RedirectResponse
    {
        try {
            if ($purchaseOrder->receipts()->exists()) {
                throw new \InvalidArgumentException('No se puede eliminar partidas de una orden que ya tiene recibos.');
            }

            if ($purchaseOrder->items()->count() <= 1) {
                throw new \InvalidArgumentException('No se puede eliminar la única partida de la orden.');
            }

            $deletedQty = $item->quantity_ordered;
            $deletedSubtotal = $item->subtotal;
            $item->delete();

            $purchaseOrder->update([
                'total_items_count' => $purchaseOrder->items()->count(),
                'total_units_ordered' => max(0, $purchaseOrder->total_units_ordered - $deletedQty),
                'subtotal' => max(0, round($purchaseOrder->subtotal - $deletedSubtotal, 2)),
                'total_cost' => max(0, round($purchaseOrder->total_cost - $deletedSubtotal, 2)),
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Partida eliminada de la orden.',
                ]);
            }

            return back()->with('success', 'Partida eliminada de la orden.');
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'location:id,code,name',
            'buyer:id,name,email',
            'cancelledBy:id,name',
            'items.product:id,sku,barcode,barcode_secondary,name,brand,cost,primary_location_id',
            'items.product.primaryLocation:id,code,name',
            'receipts.receivedBy:id,name',
            'receipts.location:id,code,name',
            'receipts.items.product:id,sku,name,primary_location_id',
            'receipts.items.product.primaryLocation:id,code,name',
        ]);

        $locations = InventoryLocation::query()
            ->where('is_active', true)
            ->get(['id', 'code', 'name']);

        $supplier = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers')) {
            $supplier = Supplier::query()
                ->where('name', $purchaseOrder->supplier_name)
                ->first(['id', 'name', 'phone', 'contact_name', 'email']);
        }

        return Inertia::render('Purchasing/Show', [
            'order' => $purchaseOrder,
            'locations' => $locations,
            'supplier' => $supplier,
        ]);
    }

    public function pdf(PurchaseOrder $purchaseOrder): \Illuminate\View\View
    {
        $purchaseOrder->load([
            'location:id,code,name',
            'buyer:id,name,email',
            'items.product:id,sku,barcode,barcode_secondary,name,brand,primary_location_id',
            'items.product.primaryLocation:id,code,name',
        ]);

        $supplier = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('suppliers')) {
            $supplier = Supplier::query()
                ->where('name', $purchaseOrder->supplier_name)
                ->first(['id', 'name', 'phone', 'contact_name', 'email']);
        }

        return view('purchasing.order-pdf', [
            'order' => $purchaseOrder,
            'supplier' => $supplier,
        ]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $strippedQ = ltrim($q, '0');
        $cleanQ = preg_replace('/[^0-9A-Za-z]/', '', $q);

        $products = InventoryProduct::query()
            ->with([
                'primaryLocation:id,code,name',
                'supplierProducts:id,inventory_product_id,supplier_id,supplier_name,supplier_sku,supplier_cost',
            ])
            ->where('is_active', true)
            ->purchasableFromSupplier()
            ->where(function ($query) use ($q, $strippedQ, $cleanQ) {
                $query->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhere('barcode_secondary', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('brand', 'like', "%{$q}%")
                    ->orWhereHas('supplierProducts', function ($spq) use ($q) {
                        $spq->where('supplier_sku', 'like', "%{$q}%");
                    });

                if ($strippedQ !== '' && $strippedQ !== $q) {
                    $query->orWhere('sku', 'like', "%{$strippedQ}%")
                          ->orWhere('barcode', 'like', "%{$strippedQ}%");
                }

                if ($cleanQ !== '' && $cleanQ !== $q && $cleanQ !== $strippedQ) {
                    $query->orWhere('sku', 'like', "%{$cleanQ}%")
                          ->orWhere('barcode', 'like', "%{$cleanQ}%");
                }
            })
            ->limit(30)
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'cost', 'primary_location_id']);

        return response()->json(['data' => $products]);
    }

    public function quickCreateProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:150'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'primary_location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
        ]);

        $sku = trim($validated['sku']);
        $barcode = ! empty($validated['barcode']) ? trim($validated['barcode']) : (is_numeric($sku) ? $sku : null);

        // Buscar si ya existe por SKU o código de barras
        $product = InventoryProduct::query()
            ->where('sku', $sku)
            ->when($barcode, fn ($q) => $q->orWhere('barcode', $barcode))
            ->first();

        if (! $product) {
            $locationId = $validated['primary_location_id'] ?? null;
            if (! $locationId) {
                $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
                $locationId = $location?->id;
            }

            $product = InventoryProduct::create([
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => trim($validated['name']),
                'brand' => ! empty($validated['brand']) ? trim($validated['brand']) : 'General',
                'supplier' => ! empty($validated['supplier_name']) ? trim($validated['supplier_name']) : 'Compras USA',
                'cost' => isset($validated['cost']) ? (float) $validated['cost'] : 0.00,
                'product_type' => InventoryProduct::SIMPLE,
                'is_active' => true,
                'primary_location_id' => $locationId,
            ]);
        }

        // Asociar supplier_sku si se especificó
        if (! empty($validated['supplier_sku']) && ! empty($validated['supplier_name'])) {
            $supplier = Supplier::where('name', $validated['supplier_name'])->first();
            \App\Models\SupplierProduct::updateOrCreate(
                [
                    'supplier_name' => trim($validated['supplier_name']),
                    'inventory_product_id' => $product->id,
                    'supplier_sku' => trim($validated['supplier_sku']),
                ],
                [
                    'supplier_id' => $supplier?->id,
                    'supplier_product_name' => $product->name,
                    'supplier_cost' => isset($validated['cost']) ? (float) $validated['cost'] : null,
                    'is_primary' => true,
                ]
            );
        }

        $product->load(['primaryLocation:id,code,name', 'supplierProducts']);

        return response()->json([
            'ok' => true,
            'message' => 'Producto registrado y listo para la orden.',
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'barcode_secondary' => $product->barcode_secondary,
                'name' => $product->name,
                'brand' => $product->brand,
                'cost' => (float) ($product->cost ?? 0),
                'primary_location_id' => $product->primary_location_id,
                'primary_location' => $product->primaryLocation ? [
                    'id' => $product->primaryLocation->id,
                    'code' => $product->primaryLocation->code,
                    'name' => $product->primaryLocation->name,
                ] : null,
                'supplier_products' => $product->supplierProducts ?? [],
            ],
        ]);
    }

    public function saveSupplierSku(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_sku' => ['required', 'string', 'max:100'],
            'supplier_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = InventoryProduct::findOrFail($validated['inventory_product_id']);
        $supplier = Supplier::where('name', $validated['supplier_name'])->first();

        $supProduct = \App\Models\SupplierProduct::updateOrCreate(
            [
                'supplier_name' => trim($validated['supplier_name']),
                'inventory_product_id' => $product->id,
                'supplier_sku' => trim($validated['supplier_sku']),
            ],
            [
                'supplier_id' => $supplier?->id,
                'supplier_product_name' => $product->name,
                'supplier_cost' => $validated['supplier_cost'] ?? null,
                'is_primary' => true,
            ]
        );

        return response()->json([
            'ok' => true,
            'message' => 'Código de proveedor guardado correctamente.',
            'supplier_product' => $supProduct,
        ]);
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'supplier_quote_reference' => ['nullable', 'string', 'max:100'],
            'ordered_at' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
        ]);

        try {
            $updated = $this->poService->markAsOrdered(
                $purchaseOrder,
                $validated['supplier_quote_reference'] ?? null,
                $validated['ordered_at'] ?? null,
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
            'close_order' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
            'items.*.allow_over_receipt' => ['nullable', 'boolean'],
        ]);

        try {
            $receipt = $this->poService->receiveItems(
                $purchaseOrder,
                $validated['items'],
                $request->user(),
                $validated['inventory_location_id'] ?? null,
                $validated['carrier'] ?? null,
                $validated['tracking_number'] ?? null,
                $validated['notes'] ?? null,
                (bool) ($validated['close_order'] ?? false)
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

    public function closePartial(Request $request, PurchaseOrder $purchaseOrder): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->poService->closePartialOrder(
                $purchaseOrder,
                $request->user(),
                $validated['reason'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Orden {$updated->order_number} cerrada definitivamente con recepción parcial.",
                    'order' => $updated,
                ]);
            }

            return back()->with('success', "Orden {$updated->order_number} cerrada definitivamente con recepción parcial.");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function voidReceipt(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderReceipt $receipt): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            if ($receipt->purchase_order_id !== $purchaseOrder->id) {
                abort(404, 'El comprobante de recepción no pertenece a esta orden.');
            }

            $updated = $this->poService->voidReceipt(
                $receipt,
                $request->user(),
                $validated['reason'] ?? null
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => "Comprobante {$receipt->receipt_number} anulado e inventario revertido correctamente.",
                    'order' => $updated,
                ]);
            }

            return back()->with('success', "Comprobante {$receipt->receipt_number} anulado e inventario revertido correctamente.");
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
