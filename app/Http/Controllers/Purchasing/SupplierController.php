<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\BrandRestockConfiguration;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBrand;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Builder;
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

    public function codesIndex(Request $request): Response
    {
        $suppliers = Supplier::query()
            ->orderBy('name')
            ->with('brands')
            ->get();

        $selectedSupplierId = $request->input('supplier_id');
        $selectedSupplier = null;

        if ($selectedSupplierId) {
            $selectedSupplier = $suppliers->firstWhere('id', (int) $selectedSupplierId);
        }

        // Si no se especificó un proveedor y existen proveedores registrados, elegir el primero por defecto
        if (! $selectedSupplier && $suppliers->isNotEmpty() && ! $request->has('supplier_id')) {
            $selectedSupplier = $suppliers->first();
            $selectedSupplierId = $selectedSupplier->id;
        }

        $brandFilter = $request->input('brand');
        $statusFilter = $request->input('status', 'all'); // 'all', 'with_code', 'without_code'
        $search = trim((string) $request->input('search', ''));

        // Conteo de códigos de catálogo asignados por proveedor
        $supplierCodesCounts = SupplierProduct::query()
            ->selectRaw('supplier_name, COUNT(DISTINCT inventory_product_id) as count')
            ->groupBy('supplier_name')
            ->pluck('count', 'supplier_name');

        $suppliersList = $suppliers->map(function (Supplier $sup) use ($supplierCodesCounts) {
            return [
                'id' => $sup->id,
                'name' => $sup->name,
                'brands' => $sup->brands->pluck('brand')->all(),
                'codes_count' => (int) ($supplierCodesCounts[$sup->name] ?? 0),
            ];
        });

        $productsQuery = InventoryProduct::query()->where('is_active', true);

        if ($selectedSupplier) {
            $brandNames = $selectedSupplier->brands->pluck('brand')->all();
            $supplierName = $selectedSupplier->name;
            $supplierId = $selectedSupplier->id;

            $productsQuery->where(function (Builder $q) use ($supplierName, $supplierId, $brandNames) {
                $hasCond = false;
                if (! empty($brandNames)) {
                    $q->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames);
                    $hasCond = true;
                }
                if ($hasCond) {
                    $q->orWhere('supplier', $supplierName)
                      ->orWhereHas('supplierProducts', fn ($sq) => $sq->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName));
                } else {
                    $q->where('supplier', $supplierName)
                      ->orWhereHas('supplierProducts', fn ($sq) => $sq->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName));
                }
            });
        }

        if ($brandFilter) {
            $productsQuery->where(DB::raw('UPPER(TRIM(brand))'), mb_strtoupper(trim($brandFilter)));
        }

        if ($search !== '') {
            $digits = preg_replace('/[^0-9]/', '', $search);
            $unpadded = $digits !== '' ? ltrim($digits, '0') : '';

            $productsQuery->where(function (Builder $q) use ($search, $digits, $unpadded) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('barcode_secondary', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('supplierProducts', function ($sq) use ($search) {
                        $sq->where('supplier_sku', 'like', "%{$search}%");
                    });

                if ($digits !== '' && strlen($digits) >= 6) {
                    $q->orWhere('barcode', $digits)
                      ->orWhere('barcode_secondary', $digits);
                }
                if ($unpadded !== '' && strlen($unpadded) >= 6) {
                    $q->orWhere('barcode', $unpadded)
                      ->orWhere('barcode', str_pad($unpadded, 12, '0', STR_PAD_LEFT))
                      ->orWhere('barcode', str_pad($unpadded, 13, '0', STR_PAD_LEFT));
                }
            });
        }

        if ($statusFilter === 'with_code' && $selectedSupplier) {
            $productsQuery->whereHas('supplierProducts', function ($sq) use ($selectedSupplier) {
                $sq->where('supplier_id', $selectedSupplier->id)
                   ->orWhere('supplier_name', $selectedSupplier->name);
            });
        } elseif ($statusFilter === 'without_code' && $selectedSupplier) {
            $productsQuery->whereDoesntHave('supplierProducts', function ($sq) use ($selectedSupplier) {
                $sq->where('supplier_id', $selectedSupplier->id)
                   ->orWhere('supplier_name', $selectedSupplier->name);
            });
        }

        // Métricas rápidas del proveedor seleccionado
        $totalForSupplier = 0;
        $withCodeCount = 0;
        $withoutCodeCount = 0;
        if ($selectedSupplier) {
            $baseSupplierQuery = InventoryProduct::query()
                ->where('is_active', true)
                ->where(function (Builder $q) use ($selectedSupplier) {
                    $brandNames = $selectedSupplier->brands->pluck('brand')->all();
                    $supplierName = $selectedSupplier->name;
                    $supplierId = $selectedSupplier->id;
                    $hasCond = false;
                    if (! empty($brandNames)) {
                        $q->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames);
                        $hasCond = true;
                    }
                    if ($hasCond) {
                        $q->orWhere('supplier', $supplierName)
                          ->orWhereHas('supplierProducts', fn ($sq) => $sq->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName));
                    } else {
                        $q->where('supplier', $supplierName)
                          ->orWhereHas('supplierProducts', fn ($sq) => $sq->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName));
                    }
                });

            $totalForSupplier = (clone $baseSupplierQuery)->count();
            $withCodeCount = (clone $baseSupplierQuery)->whereHas('supplierProducts', function ($sq) use ($selectedSupplier) {
                $sq->where('supplier_id', $selectedSupplier->id)->orWhere('supplier_name', $selectedSupplier->name);
            })->count();
            $withoutCodeCount = max(0, $totalForSupplier - $withCodeCount);
        }

        $products = $productsQuery
            ->with(['supplierProducts' => function ($sq) use ($selectedSupplier) {
                if ($selectedSupplier) {
                    $sq->where('supplier_id', $selectedSupplier->id)
                       ->orWhere('supplier_name', $selectedSupplier->name);
                }
            }])
            ->orderBy('brand')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $products->through(function (InventoryProduct $p) {
            $supProduct = $p->supplierProducts->first();

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'barcode_secondary' => $p->barcode_secondary,
                'name' => $p->name,
                'brand' => $p->brand,
                'supplier' => $p->supplier,
                'cost' => (float) $p->cost,
                'price_public' => (float) $p->price_public,
                'supplier_sku' => $supProduct?->supplier_sku ?? '',
                'supplier_cost' => $supProduct?->supplier_cost !== null ? (float) $supProduct->supplier_cost : (float) $p->cost,
                'supplier_product_id' => $supProduct?->id,
            ];
        });

        $availableBrands = $selectedSupplier
            ? $selectedSupplier->brands->pluck('brand')->all()
            : InventoryProduct::whereNotNull('brand')->where('brand', '!=', '')->distinct()->pluck('brand')->sort()->values()->all();

        return Inertia::render('Purchasing/Suppliers/Codes', [
            'suppliers' => $suppliersList,
            'selectedSupplier' => $selectedSupplier ? [
                'id' => $selectedSupplier->id,
                'name' => $selectedSupplier->name,
                'rfc' => $selectedSupplier->rfc,
                'brands' => $selectedSupplier->brands->pluck('brand')->all(),
            ] : null,
            'products' => $products,
            'availableBrands' => $availableBrands,
            'filters' => [
                'supplier_id' => $selectedSupplierId ? (int) $selectedSupplierId : null,
                'search' => $search,
                'brand' => $brandFilter ?? '',
                'status' => $statusFilter,
            ],
            'summary' => [
                'total' => $totalForSupplier,
                'with_code' => $withCodeCount,
                'without_code' => $withoutCodeCount,
                'supplier_name' => $selectedSupplier?->name ?? 'General',
            ],
        ]);
    }

    public function show(Supplier $supplier): Response
    {
        $supplier->load(['brands', 'purchaseOrders' => fn ($q) => $q->latest()->limit(15)]);

        $brandNames = $supplier->brands->pluck('brand')->all();
        $supplierName = $supplier->name;
        $supplierId = $supplier->id;

        // Productos del catálogo pertenecientes a este proveedor (por marca, supplier o supplier_products)
        $products = InventoryProduct::query()
            ->where(function (Builder $q) use ($supplierName, $supplierId, $brandNames) {
                $hasCond = false;
                if (! empty($brandNames)) {
                    $q->whereIn(DB::raw('UPPER(TRIM(brand))'), $brandNames);
                    $hasCond = true;
                }
                if ($hasCond) {
                    $q->orWhere('supplier', $supplierName)
                      ->orWhereHas('supplierProducts', fn ($sq) => $sq->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName));
                } else {
                    $q->where('supplier', $supplierName)
                      ->orWhereHas('supplierProducts', fn ($sq) => $sq->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName));
                }
            })
            ->where('is_active', true)
            ->with(['supplierProducts' => fn ($q) => $q->where('supplier_id', $supplierId)->orWhere('supplier_name', $supplierName)])
            ->orderBy('brand')
            ->orderBy('name')
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'supplier', 'cost', 'price_public'])
            ->map(function (InventoryProduct $p) {
                $supProduct = $p->supplierProducts->first();

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'barcode' => $p->barcode,
                    'barcode_secondary' => $p->barcode_secondary,
                    'name' => $p->name,
                    'brand' => $p->brand,
                    'supplier' => $p->supplier,
                    'cost' => (float) $p->cost,
                    'price_public' => (float) $p->price_public,
                    'supplier_sku' => $supProduct?->supplier_sku ?? '',
                    'supplier_cost' => $supProduct?->supplier_cost !== null ? (float) $supProduct->supplier_cost : (float) $p->cost,
                    'supplier_product_id' => $supProduct?->id,
                ];
            });

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

    /**
     * Guarda o actualiza el código de proveedor (supplier_sku) y costo para un producto
     */
    public function saveProductCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_name' => ['required', 'string', 'max:150'],
            'supplier_sku' => ['required', 'string', 'max:100'],
            'supplier_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = InventoryProduct::findOrFail($validated['inventory_product_id']);
        $supplierName = trim($validated['supplier_name']);
        $supplierSku = trim($validated['supplier_sku']);

        $supplier = null;
        if (! empty($validated['supplier_id'])) {
            $supplier = Supplier::find($validated['supplier_id']);
        }
        if (! $supplier) {
            $supplier = Supplier::where('name', $supplierName)->first();
        }

        // Buscar registro existente para este producto y proveedor
        $supProduct = SupplierProduct::where('inventory_product_id', $product->id)
            ->where(function ($q) use ($supplier, $supplierName) {
                if ($supplier) {
                    $q->where('supplier_id', $supplier->id)->orWhere('supplier_name', $supplierName);
                } else {
                    $q->where('supplier_name', $supplierName);
                }
            })
            ->first();

        if ($supProduct) {
            $supProduct->update([
                'supplier_id' => $supplier?->id ?? $supProduct->supplier_id,
                'supplier_name' => $supplierName,
                'supplier_sku' => $supplierSku,
                'supplier_product_name' => $product->name,
                'supplier_cost' => $validated['supplier_cost'] ?? $supProduct->supplier_cost,
                'is_primary' => true,
            ]);
        } else {
            $supProduct = SupplierProduct::create([
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplierName,
                'inventory_product_id' => $product->id,
                'supplier_sku' => $supplierSku,
                'supplier_product_name' => $product->name,
                'supplier_cost' => $validated['supplier_cost'] ?? $product->cost,
                'is_primary' => true,
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => "Código '{$supplierSku}' asignado correctamente a '{$product->name}'.",
            'supplier_product' => [
                'id' => $supProduct->id,
                'inventory_product_id' => $product->id,
                'supplier_sku' => $supProduct->supplier_sku,
                'supplier_cost' => (float) $supProduct->supplier_cost,
            ],
        ]);
    }

    /**
     * Desvincula / elimina el código de proveedor de un producto
     */
    public function deleteProductCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_product_id' => ['required', 'integer', 'exists:inventory_products,id'],
            'supplier_name' => ['required', 'string', 'max:150'],
        ]);

        SupplierProduct::where('inventory_product_id', $validated['inventory_product_id'])
            ->where('supplier_name', trim($validated['supplier_name']))
            ->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Código de proveedor desvinculado exitosamente.',
        ]);
    }

    /**
     * Búsqueda general de productos del catálogo para vincular a un proveedor
     */
    public function searchProductsForSupplier(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['ok' => true, 'products' => []]);
        }

        $digits = preg_replace('/[^0-9]/', '', $term);
        $unpadded = $digits !== '' ? ltrim($digits, '0') : '';

        $products = InventoryProduct::query()
            ->where('is_active', true)
            ->where(function (Builder $q) use ($term, $digits, $unpadded) {
                $q->where('sku', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%")
                    ->orWhere('barcode_secondary', 'like', "%{$term}%");

                if ($digits !== '' && strlen($digits) >= 6) {
                    $q->orWhere('barcode', $digits)
                      ->orWhere('barcode_secondary', $digits);
                }
                if ($unpadded !== '' && strlen($unpadded) >= 6) {
                    $q->orWhere('barcode', $unpadded)
                      ->orWhere('barcode', str_pad($unpadded, 12, '0', STR_PAD_LEFT))
                      ->orWhere('barcode', str_pad($unpadded, 13, '0', STR_PAD_LEFT));
                }
            })
            ->limit(25)
            ->get(['id', 'sku', 'barcode', 'barcode_secondary', 'name', 'brand', 'supplier', 'cost', 'price_public']);

        return response()->json([
            'ok' => true,
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
