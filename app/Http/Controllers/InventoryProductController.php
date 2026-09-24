<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryProductRequest;
use App\Http\Requests\UpdateInventoryProductRequest;
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
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
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
        ]);
    }

    public function store(StoreInventoryProductRequest $request): RedirectResponse
    {
        InventoryProduct::create($request->validated());

        return redirect()->route('inventory.products.index')->with('success', 'Producto creado correctamente.');
    }

    public function show(InventoryProduct $inventoryProduct): Response
    {
        return Inertia::render('Inventory/Products/Show', [
            'product' => $inventoryProduct,
        ]);
    }

    public function edit(InventoryProduct $inventoryProduct): Response
    {
        return Inertia::render('Inventory/Products/Form', [
            'mode' => 'edit',
            'product' => $inventoryProduct,
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
}
