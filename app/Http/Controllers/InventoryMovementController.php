<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryInsufficientStockException;
use App\Http\Requests\StoreInventoryMovementRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Services\InventoryMovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $type = (string) $request->input('type', '');
        $movements = InventoryMovement::query()
            ->with([
                'product:id,name,sku,barcode',
                'location:id,code,name',
                'createdBy:id,name',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($product) use ($search): void {
                            $product->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%");
                        })
                        ->orWhereHas('location', function ($location) use ($search): void {
                            $location->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Movements/Index', [
            'movements' => $movements,
            'filters' => ['search' => $search, 'type' => $type],
            'types' => InventoryMovement::types(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Movements/Form', [
            'products' => InventoryProduct::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
            'locations' => InventoryLocation::query()
                ->where('is_active', true)
                ->orderByRaw('sort_order IS NULL')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'types' => array_values(array_diff(
                InventoryMovement::types(),
                [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT],
            )),
        ]);
    }

    public function store(
        StoreInventoryMovementRequest $request,
        InventoryMovementService $movements,
    ): RedirectResponse {
        try {
            $movements->recordManual($request->validated(), $request->user());
        } catch (InventoryInsufficientStockException $exception) {
            return back()
                ->withInput()
                ->withErrors(['quantity' => $exception->getMessage()]);
        }

        return redirect()->route('inventory.movements.index')
            ->with('success', 'Movimiento registrado correctamente.');
    }

    public function show(InventoryMovement $inventoryMovement): Response
    {
        $inventoryMovement->load([
            'product:id,name,sku,barcode',
            'location:id,code,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('Inventory/Movements/Show', [
            'movement' => $inventoryMovement,
        ]);
    }
}
