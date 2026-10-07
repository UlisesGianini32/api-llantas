<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryInsufficientStockException;
use App\Http\Requests\StoreInventoryMovementRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Services\InventoryMovementService;
use App\Support\InventoryActorPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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
                                ->orWhere('barcode', 'like', "%{$search}%")
                                ->orWhere('barcode_secondary', 'like', "%{$search}%");
                        })
                        ->orWhereHas('location', function ($location) use ($search): void {
                            $location->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($type !== '', function ($query) use ($type) {
                if ($type === 'TRANSFER') {
                    $query->whereIn('type', [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT]);
                } else {
                    $query->where('type', $type);
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $movements->through(function (InventoryMovement $movement): InventoryMovement {
            $movement->creator_name = InventoryActorPresenter::labelForMovement($movement);

            return $movement;
        });

        return Inertia::render('Inventory/Movements/Index', [
            'movements' => $movements,
            'filters' => ['search' => $search, 'type' => $type],
            'types' => [
                'RECEIPT',
                'TRANSFER',
                'ADJUSTMENT_IN',
                'ADJUSTMENT_OUT',
                'RETURN',
                'DAMAGE',
                'INITIAL',
                'TRANSFER_IN',
                'TRANSFER_OUT',
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Movements/Form', [
            'products' => InventoryProduct::query()
                ->with('primaryLocation:id,code,name')
                ->where('is_active', true)
                ->where('product_type', InventoryProduct::SIMPLE)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'barcode', 'barcode_secondary', 'brand', 'primary_location_id']),
            'locations' => InventoryLocation::query()
                ->where('is_active', true)
                ->orderByRaw('sort_order IS NULL')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'types' => [
                'RECEIPT',
                'TRANSFER',
                'ADJUSTMENT_IN',
                'ADJUSTMENT_OUT',
                'RETURN',
                'DAMAGE',
                'INITIAL',
            ],
        ]);
    }

    public function store(
        StoreInventoryMovementRequest $request,
        InventoryMovementService $movements,
    ): RedirectResponse {
        $validated = $request->validated();
        $user = $request->user();

        try {
            // Manejo de movimiento tipo TRANSFER (Mover entre ubicaciones)
            if ($validated['type'] === 'TRANSFER') {
                $destinationLocationId = (int) ($validated['destination_location_id'] ?? 0);
                $destLoc = InventoryLocation::findOrFail($destinationLocationId);

                $itemList = ! empty($validated['items']) ? $validated['items'] : [[
                    'inventory_product_id' => $validated['inventory_product_id'],
                    'quantity' => $validated['quantity'],
                    'inventory_location_id' => $validated['inventory_location_id'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]];

                $count = DB::transaction(function () use ($itemList, $validated, $user, $movements, $destLoc) {
                    $recorded = 0;
                    foreach ($itemList as $item) {
                        $originLocationId = ! empty($item['inventory_location_id'])
                            ? (int) $item['inventory_location_id']
                            : (! empty($validated['inventory_location_id']) ? (int) $validated['inventory_location_id'] : null);

                        $product = InventoryProduct::findOrFail($item['inventory_product_id']);
                        if (! $originLocationId) {
                            $originLocationId = $product->primary_location_id;
                        }
                        if (! $originLocationId) {
                            throw new InvalidArgumentException("El producto '{$product->name}' no tiene ubicación de origen.");
                        }
                        if ($originLocationId === $destLoc->id) {
                            throw new InvalidArgumentException("La ubicación de origen y destino no pueden ser la misma para '{$product->name}'.");
                        }

                        $originLoc = InventoryLocation::findOrFail($originLocationId);
                        $qty = (int) $item['quantity'];
                        $ref = ! empty($validated['reference'])
                            ? $validated['reference']
                            : "Mover: {$originLoc->code} → {$destLoc->code}";

                        $itemNotes = trim((string) ($item['notes'] ?? ''));
                        $baseNotes = trim((string) ($validated['notes'] ?? ''));
                        $combinedNotes = $baseNotes !== '' && $itemNotes !== ''
                            ? "{$baseNotes} | {$itemNotes}"
                            : ($itemNotes !== '' ? $itemNotes : ($baseNotes !== '' ? $baseNotes : null));

                        // 1. Salida de origen
                        $movements->recordManual([
                            'inventory_product_id' => $product->id,
                            'inventory_location_id' => $originLoc->id,
                            'type' => InventoryMovement::TRANSFER_OUT,
                            'quantity' => $qty,
                            'reference' => $ref,
                            'notes' => $combinedNotes,
                            'occurred_at' => $validated['occurred_at'] ?? now(),
                        ], $user);

                        // 2. Entrada a destino
                        $movements->recordManual([
                            'inventory_product_id' => $product->id,
                            'inventory_location_id' => $destLoc->id,
                            'type' => InventoryMovement::TRANSFER_IN,
                            'quantity' => $qty,
                            'reference' => $ref,
                            'notes' => $combinedNotes,
                            'occurred_at' => $validated['occurred_at'] ?? now(),
                        ], $user);

                        $recorded++;
                    }

                    return $recorded;
                });

                return redirect()->route('inventory.movements.index')
                    ->with('success', "Se movieron {$count} producto(s) a la ubicación {$destLoc->code} correctamente.");
            }

            if (! empty($validated['items'])) {
                $count = DB::transaction(function () use ($validated, $user, $movements) {
                    $recorded = 0;
                    foreach ($validated['items'] as $item) {
                        $itemNotes = trim((string) ($item['notes'] ?? ''));
                        $baseNotes = trim((string) ($validated['notes'] ?? ''));
                        $combinedNotes = $baseNotes !== '' && $itemNotes !== ''
                            ? "{$baseNotes} | {$itemNotes}"
                            : ($itemNotes !== '' ? $itemNotes : ($baseNotes !== '' ? $baseNotes : null));

                        $locationId = ! empty($item['inventory_location_id'])
                            ? (int) $item['inventory_location_id']
                            : (! empty($validated['inventory_location_id']) ? (int) $validated['inventory_location_id'] : null);

                        if (! $locationId) {
                            $product = InventoryProduct::find($item['inventory_product_id']);
                            $locationId = $product?->primary_location_id;
                        }

                        if (! $locationId) {
                            throw new InvalidArgumentException("El producto ID {$item['inventory_product_id']} no tiene ubicación asignada ni se indicó una general.");
                        }

                        $movements->recordManual([
                            'inventory_product_id' => (int) $item['inventory_product_id'],
                            'inventory_location_id' => $locationId,
                            'type' => (string) $validated['type'],
                            'quantity' => (int) $item['quantity'],
                            'reference_type' => $validated['reference_type'] ?? null,
                            'reference_id' => $validated['reference_id'] ?? null,
                            'reference' => $validated['reference'] ?? null,
                            'notes' => $combinedNotes,
                            'metadata' => $validated['metadata'] ?? null,
                            'occurred_at' => $validated['occurred_at'] ?? now(),
                            'external_key' => $item['external_key'] ?? null,
                        ], $user);
                        $recorded++;
                    }

                    return $recorded;
                });

                return redirect()->route('inventory.movements.index')
                    ->with('success', "Se registraron {$count} movimientos correctamente.");
            }

            $locationId = ! empty($validated['inventory_location_id'])
                ? (int) $validated['inventory_location_id']
                : InventoryProduct::find($validated['inventory_product_id'])?->primary_location_id;

            if (! $locationId) {
                throw new InvalidArgumentException('Debes seleccionar una ubicación de almacén o el producto debe tener una asignada.');
            }

            $validated['inventory_location_id'] = $locationId;
            $movements->recordManual($validated, $user);
        } catch (InventoryInsufficientStockException|InvalidArgumentException $exception) {
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
