<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryInsufficientStockException;
use App\Http\Requests\StoreInventoryReservationRequest;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Services\InventoryReservationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class InventoryReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $status = (string) $request->input('status', '');
        $reservations = InventoryReservation::query()
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
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Reservations/Index', [
            'reservations' => $reservations,
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => InventoryReservation::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Reservations/Form', [
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
        ]);
    }

    public function store(
        StoreInventoryReservationRequest $request,
        InventoryReservationService $reservations,
    ): RedirectResponse {
        try {
            $reservations->create($request->validated(), $request->user());
        } catch (InventoryInsufficientStockException|InvalidArgumentException $exception) {
            return back()
                ->withInput()
                ->withErrors(['quantity' => $exception->getMessage()]);
        }

        return redirect()->route('inventory.reservations.index')
            ->with('success', 'Reserva creada correctamente.');
    }

    public function show(InventoryReservation $inventoryReservation): Response
    {
        $inventoryReservation->load([
            'product:id,name,sku,barcode',
            'location:id,code,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('Inventory/Reservations/Show', [
            'reservation' => $inventoryReservation,
        ]);
    }

    public function release(
        Request $request,
        InventoryReservation $inventoryReservation,
        InventoryReservationService $reservations,
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);

        return $this->transition($inventoryReservation, $reservations, 'release');
    }

    public function cancel(
        Request $request,
        InventoryReservation $inventoryReservation,
        InventoryReservationService $reservations,
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);

        return $this->transition($inventoryReservation, $reservations, 'cancel');
    }

    public function expire(
        Request $request,
        InventoryReservation $inventoryReservation,
        InventoryReservationService $reservations,
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);

        return $this->transition($inventoryReservation, $reservations, 'expire');
    }

    public function fulfill(
        Request $request,
        InventoryReservation $inventoryReservation,
        InventoryReservationService $reservations,
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);

        try {
            $reservations->fulfill($inventoryReservation, [
                'inventory_location_id' => $request->input('inventory_location_id'),
                'reference' => $request->input('reference'),
                'notes' => $request->input('notes'),
            ], $request->user());
        } catch (InventoryInsufficientStockException|InvalidArgumentException $exception) {
            return back()->withErrors(['reservation' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reserva cumplida y salida registrada.');
    }

    private function transition(
        InventoryReservation $reservation,
        InventoryReservationService $reservations,
        string $action,
    ): RedirectResponse {
        try {
            $reservations->{$action}($reservation);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['reservation' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reserva actualizada correctamente.');
    }
}
