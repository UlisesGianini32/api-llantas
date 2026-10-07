<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\BrandRestockConfiguration;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Supplier::query()->with('brands');

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('brand')) {
            $brandFilter = mb_strtoupper(trim((string) $request->input('brand')));
            $query->whereHas('brands', function ($q) use ($brandFilter) {
                $q->where('brand', $brandFilter);
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $suppliers = $query->orderBy('name')->get();

        // Conteo de productos del catálogo por marca
        $productCountsByBrand = InventoryProduct::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->selectRaw('UPPER(TRIM(brand)) as clean_brand, COUNT(*) as count')
            ->groupBy(DB::raw('UPPER(TRIM(brand))'))
            ->pluck('count', 'clean_brand');

        // Mapear métricas rápidas a cada proveedor
        $suppliers->transform(function (Supplier $sup) use ($productCountsByBrand) {
            $totalProducts = 0;
            $brandsDetailed = $sup->brands->map(function (SupplierBrand $sb) use ($productCountsByBrand, &$totalProducts) {
                $cnt = (int) ($productCountsByBrand[$sb->brand] ?? 0);
                $totalProducts += $cnt;

                return [
                    'id' => $sb->id,
                    'brand' => $sb->brand,
                    'is_primary' => $sb->is_primary,
                    'lead_time_override' => $sb->lead_time_override,
                    'notes' => $sb->notes,
                    'catalog_products_count' => $cnt,
                ];
            });

            return [
                'id' => $sup->id,
                'name' => $sup->name,
                'rfc' => $sup->rfc,
                'contact_name' => $sup->contact_name,
                'email' => $sup->email,
                'phone' => $sup->phone,
                'address' => $sup->address,
                'lead_time_days' => $sup->lead_time_days,
                'credit_days' => $sup->credit_days,
                'credit_limit' => (float) $sup->credit_limit,
                'payment_method_preferred' => $sup->payment_method_preferred,
                'website' => $sup->website,
                'notes' => $sup->notes,
                'is_active' => $sup->is_active,
                'brands' => $brandsDetailed,
                'brands_count' => $sup->brands->count(),
                'catalog_products_count' => $totalProducts,
                'created_at' => $sup->created_at?->format('Y-m-d'),
            ];
        });

        // Marcas disponibles en el sistema (productos, configuraciones y proveedores)
        $catalogBrands = InventoryProduct::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand');

        $configBrands = BrandRestockConfiguration::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->pluck('brand');

        $supplierBrands = SupplierBrand::query()
            ->distinct()
            ->pluck('brand');

        $availableBrands = $catalogBrands
            ->concat($configBrands)
            ->concat($supplierBrands)
            ->map(fn ($b) => mb_strtoupper(trim((string) $b)))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        // Resumen
        $summary = [
            'total_suppliers' => Supplier::count(),
            'active_suppliers' => Supplier::where('is_active', true)->count(),
            'credit_suppliers' => Supplier::where('credit_days', '>', 0)->count(),
            'total_mapped_brands' => SupplierBrand::distinct('brand')->count('brand'),
        ];

        return Inertia::render('Purchasing/Suppliers/Index', [
            'suppliers' => $suppliers,
            'availableBrands' => $availableBrands,
            'summary' => $summary,
            'filters' => [
                'search' => $request->input('search', ''),
                'brand' => $request->input('brand', ''),
                'status' => $request->input('status', 'active'),
            ],
        ]);
    }

    public function show(Supplier $supplier): Response
    {
        $supplier->load(['brands', 'purchaseOrders' => fn ($q) => $q->latest()->limit(15)]);

        $brandNames = $supplier->brands->pluck('brand')->all();

        // Productos del catálogo pertenecientes a las marcas de este proveedor
        $products = [];
        if (! empty($brandNames)) {
            $products = InventoryProduct::query()
                ->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames)
                ->where('is_active', true)
                ->orderBy('brand')
                ->orderBy('name')
                ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'supplier', 'cost', 'price_public']);
        }

        // Conteo de productos por marca
        $productCountsByBrand = InventoryProduct::query()
            ->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames)
            ->selectRaw('UPPER(TRIM(brand)) as clean_brand, COUNT(*) as count')
            ->groupBy(DB::raw('UPPER(TRIM(brand))'))
            ->pluck('count', 'clean_brand');

        $brandsDetailed = $supplier->brands->map(function (SupplierBrand $sb) use ($productCountsByBrand) {
            return [
                'id' => $sb->id,
                'brand' => $sb->brand,
                'is_primary' => $sb->is_primary,
                'lead_time_override' => $sb->lead_time_override,
                'notes' => $sb->notes,
                'catalog_products_count' => (int) ($productCountsByBrand[$sb->brand] ?? 0),
            ];
        });

        return Inertia::render('Purchasing/Suppliers/Show', [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'rfc' => $supplier->rfc,
                'contact_name' => $supplier->contact_name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'address' => $supplier->address,
                'lead_time_days' => $supplier->lead_time_days,
                'credit_days' => $supplier->credit_days,
                'credit_limit' => (float) $supplier->credit_limit,
                'payment_method_preferred' => $supplier->payment_method_preferred,
                'website' => $supplier->website,
                'notes' => $supplier->notes,
                'is_active' => $supplier->is_active,
                'brands' => $brandsDetailed,
                'purchase_orders' => $supplier->purchaseOrders,
            ],
            'products' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:suppliers,name'],
            'rfc' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_method_preferred' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'brands' => ['nullable', 'array'],
            'update_catalog_products' => ['nullable', 'boolean'],
        ]);

        $supplier = Supplier::create([
            'name' => trim($validated['name']),
            'rfc' => ! empty($validated['rfc']) ? mb_strtoupper(trim($validated['rfc'])) : null,
            'contact_name' => $validated['contact_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'lead_time_days' => $validated['lead_time_days'] ?? 7,
            'credit_days' => $validated['credit_days'] ?? 0,
            'credit_limit' => $validated['credit_limit'] ?? 0,
            'payment_method_preferred' => $validated['payment_method_preferred'] ?? null,
            'website' => $validated['website'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()?->id,
        ]);

        if (! empty($validated['brands'])) {
            $supplier->syncBrands($validated['brands']);

            $brandNames = $supplier->brands()->pluck('brand')->all();

            // Si se marcó actualizar catálogo, se asigna el proveedor a productos de estas marcas
            if (! empty($validated['update_catalog_products'])) {
                InventoryProduct::query()
                    ->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames)
                    ->update(['supplier' => $supplier->name]);
            }

            // Actualizar en configuraciones de reabastecimiento de esas marcas si estaban vacías
            BrandRestockConfiguration::query()
                ->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames)
                ->where(function ($q) {
                    $q->whereNull('supplier')->orWhere('supplier', '');
                })
                ->update(['supplier' => $supplier->name]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Proveedor '{$supplier->name}' registrado exitosamente.",
                'supplier' => $supplier->load('brands'),
            ], 201);
        }

        return redirect()->route('purchasing.suppliers.index')
            ->with('success', "Proveedor '{$supplier->name}' registrado exitosamente.");
    }

    public function update(Request $request, Supplier $supplier): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:suppliers,name,'.$supplier->id],
            'rfc' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_method_preferred' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'brands' => ['nullable', 'array'],
            'update_catalog_products' => ['nullable', 'boolean'],
        ]);

        $oldName = $supplier->name;

        $supplier->update([
            'name' => trim($validated['name']),
            'rfc' => ! empty($validated['rfc']) ? mb_strtoupper(trim($validated['rfc'])) : null,
            'contact_name' => $validated['contact_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'lead_time_days' => $validated['lead_time_days'] ?? 7,
            'credit_days' => $validated['credit_days'] ?? 0,
            'credit_limit' => $validated['credit_limit'] ?? 0,
            'payment_method_preferred' => $validated['payment_method_preferred'] ?? null,
            'website' => $validated['website'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $validated['is_active'] ?? $supplier->is_active,
        ]);

        $supplier->syncBrands($validated['brands'] ?? []);

        $brandNames = $supplier->brands()->pluck('brand')->all();

        // Actualizar en catálogo si se solicitó o si cambió de nombre
        if (! empty($validated['update_catalog_products'])) {
            InventoryProduct::query()
                ->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames)
                ->update(['supplier' => $supplier->name]);
        } elseif ($oldName !== $supplier->name) {
            InventoryProduct::query()
                ->where('supplier', $oldName)
                ->update(['supplier' => $supplier->name]);

            PurchaseOrder::query()
                ->where('supplier_name', $oldName)
                ->update(['supplier_name' => $supplier->name]);

            BrandRestockConfiguration::query()
                ->where('supplier', $oldName)
                ->update(['supplier' => $supplier->name]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Proveedor '{$supplier->name}' actualizado correctamente.",
                'supplier' => $supplier->load('brands'),
            ]);
        }

        return redirect()->back()
            ->with('success', "Proveedor '{$supplier->name}' actualizado correctamente.");
    }

    public function toggle(Supplier $supplier): JsonResponse|RedirectResponse
    {
        $supplier->update(['is_active' => ! $supplier->is_active]);

        $statusText = $supplier->is_active ? 'activado' : 'desactivado';

        if (request()->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => "Proveedor '{$supplier->name}' {$statusText}.",
                'is_active' => $supplier->is_active,
            ]);
        }

        return redirect()->back()->with('success', "Proveedor '{$supplier->name}' {$statusText}.");
    }

    public function destroy(Supplier $supplier): JsonResponse|RedirectResponse
    {
        $hasOrders = PurchaseOrder::where('supplier_name', $supplier->name)->exists();

        if ($hasOrders) {
            $supplier->update(['is_active' => false]);
            $msg = "El proveedor '{$supplier->name}' tiene órdenes de compra asociadas. Se ha desactivado en vez de eliminar.";
        } else {
            $name = $supplier->name;
            $supplier->delete();
            $msg = "Proveedor '{$name}' eliminado exitosamente.";
        }

        if (request()->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $msg,
            ]);
        }

        return redirect()->route('purchasing.suppliers.index')->with('success', $msg);
    }

    /**
     * Búsqueda rápida JSON de proveedores y marcas para autocompletado
     */
    public function search(Request $request): JsonResponse
    {
        $query = Supplier::query()->where('is_active', true)->with('brands');

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        if ($request->filled('brand')) {
            $brand = mb_strtoupper(trim((string) $request->input('brand')));
            $query->whereHas('brands', function ($q) use ($brand) {
                $q->where('brand', $brand);
            });
        }

        $suppliers = $query->orderBy('name')->limit(30)->get();

        return response()->json([
            'ok' => true,
            'suppliers' => $suppliers->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'contact_name' => $s->contact_name,
                'phone' => $s->phone,
                'email' => $s->email,
                'lead_time_days' => $s->lead_time_days,
                'credit_days' => $s->credit_days,
                'brands' => $s->brands->pluck('brand'),
            ]),
        ]);
    }
}
