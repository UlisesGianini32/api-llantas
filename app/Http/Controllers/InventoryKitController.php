<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryKitReservationRequest;
use App\Http\Requests\UpdateInventoryKitComponentsRequest;
use App\Models\InventoryProduct;
use App\Services\InventoryKitService;
use App\Services\InventoryKitStockService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class InventoryKitController extends Controller
{
    public function index(InventoryKitStockService $stock): Response
    {
        $kits = InventoryProduct::query()
            ->where('product_type', InventoryProduct::KIT)
            ->with('kitComponents.component:id,name,sku')
            ->orderBy('name')
            ->paginate(25);

        $kitStocks = $stock->stocksForKits($kits->getCollection());
        $kits->getCollection()->each(function (InventoryProduct $kit) use ($kitStocks): void {
            $values = $kitStocks->get($kit->getKey(), ['physical_stock' => 0, 'available_stock' => 0]);
            $kit->setAttribute('physical_stock', $values['physical_stock']);
            $kit->setAttribute('available_stock', $values['available_stock']);
            $kit->setAttribute('reserved_stock', max(0, $values['physical_stock'] - $values['available_stock']));
        });

        return Inertia::render('Inventory/Kits/Index', ['kits' => $kits]);
    }

    public function show(InventoryProduct $inventoryKit, InventoryKitStockService $stock): Response
    {
        abort_unless($inventoryKit->isKit(), 404);
        $inventoryKit->load('kitComponents.component:id,name,sku,product_type');

        return Inertia::render('Inventory/Kits/Show', [
            'kit' => $inventoryKit,
            'components' => $stock->componentSummary($inventoryKit),
            'physicalStock' => $stock->physicalStock($inventoryKit),
            'availableStock' => $stock->availableStock($inventoryKit),
            'reservations' => $inventoryKit->kitReservations()->with('createdBy:id,name')->latest()->limit(20)->get(),
        ]);
    }

    public function edit(InventoryProduct $inventoryKit): Response
    {
        abort_unless($inventoryKit->isKit(), 404);
        $inventoryKit->load('kitComponents.component:id,name,sku,product_type');

        return Inertia::render('Inventory/Kits/Form', [
            'kit' => $inventoryKit,
            'products' => InventoryProduct::query()
                ->where('is_active', true)
                ->where('product_type', InventoryProduct::SIMPLE)
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
        ]);
    }

    public function updateComponents(
        UpdateInventoryKitComponentsRequest $request,
        InventoryProduct $inventoryKit,
        InventoryKitService $kits,
    ): RedirectResponse {
        abort_unless($inventoryKit->isKit(), 404);
        try {
            $kits->replaceComponents($inventoryKit, $request->validated('components', []));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['components' => $exception->getMessage()]);
        }

        return redirect()->route('inventory.kits.show', $inventoryKit)->with('success', 'Componentes del kit actualizados.');
    }

    public function reserve(
        StoreInventoryKitReservationRequest $request,
        InventoryProduct $inventoryKit,
        InventoryKitService $kits,
    ): RedirectResponse {
        abort_unless($inventoryKit->isKit(), 404);
        try {
            $kits->reserve([
                ...$request->validated(),
                'kit_product_id' => $inventoryKit->getKey(),
            ], $request->user());
        } catch (\Throwable $exception) {
            if (! $exception instanceof InvalidArgumentException && ! $exception instanceof \App\Exceptions\InventoryInsufficientStockException) {
                throw $exception;
            }

            return back()->withInput()->withErrors(['quantity' => $exception->getMessage()]);
        }

        return redirect()->route('inventory.kits.show', $inventoryKit)->with('success', 'Kit reservado correctamente.');
    }
}
