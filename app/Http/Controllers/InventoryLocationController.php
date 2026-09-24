<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryLocationRequest;
use App\Http\Requests\UpdateInventoryLocationRequest;
use App\Models\InventoryLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryLocationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $locations = InventoryLocation::query()
            ->withCount('products')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->orderByRaw('sort_order IS NULL')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Locations/Index', [
            'locations' => $locations,
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Locations/Form', [
            'mode' => 'create',
            'location' => null,
        ]);
    }

    public function store(StoreInventoryLocationRequest $request): RedirectResponse
    {
        InventoryLocation::create($request->validated());

        return redirect()->route('inventory.locations.index')->with('success', 'Ubicación creada correctamente.');
    }

    public function show(InventoryLocation $inventoryLocation): Response
    {
        $inventoryLocation->load([
            'products' => fn ($query) => $query
                ->select(['id', 'primary_location_id', 'name', 'sku', 'barcode', 'is_active'])
                ->orderBy('name'),
        ]);

        return Inertia::render('Inventory/Locations/Show', [
            'location' => $inventoryLocation,
        ]);
    }

    public function edit(InventoryLocation $inventoryLocation): Response
    {
        return Inertia::render('Inventory/Locations/Form', [
            'mode' => 'edit',
            'location' => $inventoryLocation,
        ]);
    }

    public function update(UpdateInventoryLocationRequest $request, InventoryLocation $inventoryLocation): RedirectResponse
    {
        $inventoryLocation->update($request->validated());

        return redirect()->route('inventory.locations.index')->with('success', 'Ubicación actualizada correctamente.');
    }

    public function toggle(InventoryLocation $inventoryLocation): RedirectResponse
    {
        $inventoryLocation->update(['is_active' => ! $inventoryLocation->is_active]);

        return back()->with('success', $inventoryLocation->is_active ? 'Ubicación activada.' : 'Ubicación desactivada.');
    }
}
