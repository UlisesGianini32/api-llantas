<?php

namespace App\Http\Controllers;

use App\Models\InventoryKitReservation;
use App\Services\InventoryKitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class InventoryKitReservationController extends Controller
{
    public function show(InventoryKitReservation $inventoryKitReservation): Response
    {
        $inventoryKitReservation->load([
            'kit:id,name,sku',
            'createdBy:id,name',
            'componentReservations.product:id,name,sku',
            'componentReservations.location:id,code,name',
        ]);

        return Inertia::render('Inventory/Kits/ReservationShow', ['reservation' => $inventoryKitReservation]);
    }

    public function release(Request $request, InventoryKitReservation $reservation, InventoryKitService $kits): RedirectResponse
    {
        return $this->transition($request, $reservation, $kits, 'release');
    }

    public function cancel(Request $request, InventoryKitReservation $reservation, InventoryKitService $kits): RedirectResponse
    {
        return $this->transition($request, $reservation, $kits, 'cancel');
    }

    public function expire(Request $request, InventoryKitReservation $reservation, InventoryKitService $kits): RedirectResponse
    {
        return $this->transition($request, $reservation, $kits, 'expire');
    }

    public function fulfill(Request $request, InventoryKitReservation $reservation, InventoryKitService $kits): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        try {
            $kits->fulfill($reservation, [
                'reference' => $request->input('reference'),
                'notes' => $request->input('notes'),
            ], $request->user());
        } catch (InvalidArgumentException|\App\Exceptions\InventoryInsufficientStockException $exception) {
            return back()->withErrors(['reservation' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reserva del kit cumplida y componentes descontados.');
    }

    private function transition(Request $request, InventoryKitReservation $reservation, InventoryKitService $kits, string $action): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        try {
            $kits->{$action}($reservation);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['reservation' => $exception->getMessage()]);
        }

        return back()->with('success', 'Reserva del kit actualizada correctamente.');
    }
}
