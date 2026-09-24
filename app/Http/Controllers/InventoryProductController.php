<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryProductRequest;
use App\Http\Requests\UpdateInventoryProductRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $products = InventoryProduct::query()
            ->with('primaryLocation:id,code,name,is_active')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhereHas('primaryLocation', function ($location) use ($search): void {
                            $location->where('code', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'mode' => 'create',
            'product' => null,
            'locations' => $this->locationOptions(),
        ]);
    }

    public function store(StoreInventoryProductRequest $request): RedirectResponse
    {
        InventoryProduct::create($request->validated());

        return redirect()->route('inventory.products.index')->with('success', 'Producto creado correctamente.');
    }

    public function show(InventoryProduct $inventoryProduct): Response
    {
        $inventoryProduct->load('primaryLocation:id,code,name,is_active');

        return Inertia::render('Inventory/Products/Show', [
            'product' => $inventoryProduct,
        ]);
    }

    public function edit(InventoryProduct $inventoryProduct): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'mode' => 'edit',
            'product' => $inventoryProduct,
            'locations' => $this->locationOptions($inventoryProduct),
        ]);
    }

    public function update(UpdateInventoryProductRequest $request, InventoryProduct $inventoryProduct): RedirectResponse
    {
        $inventoryProduct->update($request->validated());

        return redirect()->route('inventory.products.index')->with('success', 'Producto actualizado correctamente.');
    }

    public function toggle(InventoryProduct $inventoryProduct): RedirectResponse
    {
        $inventoryProduct->update(['is_active' => ! $inventoryProduct->is_active]);

        return back()->with('success', $inventoryProduct->is_active ? 'Producto activado.' : 'Producto desactivado.');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, InventoryLocation> */
    private function locationOptions(?InventoryProduct $product = null)
    {
        $currentId = $product?->primary_location_id;

        return InventoryLocation::query()
            ->where(function ($query) use ($currentId): void {
                $query->where('is_active', true);
                if ($currentId) {
                    $query->orWhere('id', $currentId);
                }
            })
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active']);
    }
}
