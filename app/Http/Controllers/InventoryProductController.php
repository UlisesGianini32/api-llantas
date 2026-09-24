<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryProductRequest;
use App\Http\Requests\UpdateInventoryProductRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Services\InventoryStockService;
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
            ->withSum('movements as physical_stock', 'quantity')
            ->withSum([
                'reservations as reserved_stock' => fn ($query) => $query
                    ->where('status', InventoryReservation::ACTIVE),
            ], 'quantity')
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

    public function show(
        InventoryProduct $inventoryProduct,
        InventoryStockService $stock,
    ): Response {
        $inventoryProduct->load('primaryLocation:id,code,name,is_active');
        $movements = $inventoryProduct->movements()
            ->with([
                'location:id,code,name',
                'createdBy:id,name',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
        $physicalByLocation = $stock->productStockByLocation($inventoryProduct)
            ->keyBy('inventory_location_id');
        $reservedByLocation = InventoryReservation::query()
            ->active()
            ->where('inventory_product_id', $inventoryProduct->getKey())
            ->whereNotNull('inventory_location_id')
            ->select('inventory_location_id')
            ->selectRaw('SUM(quantity) as quantity')
            ->groupBy('inventory_location_id')
            ->get()
            ->keyBy('inventory_location_id');
        $locationIds = $physicalByLocation->keys()
            ->merge($reservedByLocation->keys())
            ->unique()
            ->values();
        $locations = InventoryLocation::query()
            ->whereIn('id', $locationIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');
        $stockByLocation = $locationIds->map(function ($locationId) use (
            $physicalByLocation,
            $reservedByLocation,
            $locations,
        ): array {
            $physical = (int) ($physicalByLocation[$locationId]->quantity ?? 0);
            $reserved = (int) ($reservedByLocation[$locationId]->quantity ?? 0);

            return [
                'inventory_location_id' => (int) $locationId,
                'location' => $locations[$locationId] ?? null,
                'physical_stock' => $physical,
                'reserved_stock' => $reserved,
                'available_stock' => $physical - $reserved,
            ];
        })->values();
        $reservations = $inventoryProduct->reservations()
            ->active()
            ->with('location:id,code,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return Inertia::render('Inventory/Products/Show', [
            'product' => $inventoryProduct,
            'physicalStock' => $stock->productStock($inventoryProduct),
            'reservedStock' => $stock->reservedStock($inventoryProduct),
            'availableStock' => $stock->availableStock($inventoryProduct),
            'stockByLocation' => $stockByLocation,
            'movements' => $movements,
            'reservations' => $reservations,
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
