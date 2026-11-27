<?php

namespace App\Http\Controllers\Inventory\Dispatches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\Dispatches\StoreWarehouseDispatchDestinationRequest;
use App\Http\Requests\Inventory\Dispatches\UpdateWarehouseDispatchDestinationRequest;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchDestination;
use App\Services\Inventory\Dispatches\WarehouseDispatchDestinationService;
use App\Services\Inventory\Dispatches\WarehouseDispatchFeatureGuard;
use App\Services\Inventory\Dispatches\WarehouseDispatchPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseDispatchDestinationController extends Controller
{
    public function __construct(
        private readonly WarehouseDispatchDestinationService $service,
        private readonly WarehouseDispatchFeatureGuard $guard,
        private readonly WarehouseDispatchPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewAny', InventoryDispatchDestination::class);

        $search = trim((string) $request->input('search', ''));
        $status = (string) $request->input('status', 'all');

        $destinations = InventoryDispatchDestination::query()
            ->withCount([
                'dispatches as confirmed_dispatches_count' => fn ($q) => $q->where('status', InventoryDispatch::STATUS_CONFIRMED),
            ])
            ->withSum([
                'dispatches as confirmed_physical_units_sum' => fn ($q) => $q->where('status', InventoryDispatch::STATUS_CONFIRMED),
            ], 'total_physical_units')
            ->withMax([
                'dispatches as last_dispatch_at' => fn ($q) => $q->where('status', InventoryDispatch::STATUS_CONFIRMED),
            ], 'effective_at')
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%");
                });
            })
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryDispatchDestination $dest): array => [
                'id' => (int) $dest->getKey(),
                'code' => (string) $dest->code,
                'name' => (string) $dest->name,
                'destination_type' => (string) $dest->destination_type,
                'contact_name' => $dest->contact_name,
                'phone' => $dest->phone,
                'address' => $dest->address,
                'default_operation_type' => $dest->default_operation_type,
                'is_active' => (bool) $dest->is_active,
                'notes' => $dest->notes,
                'confirmed_dispatches_count' => (int) ($dest->confirmed_dispatches_count ?? 0),
                'confirmed_physical_units_sum' => (int) ($dest->confirmed_physical_units_sum ?? 0),
                'last_dispatch_at' => $dest->last_dispatch_at,
            ])
            ->values()
            ->all();

        return Inertia::render('Inventory/Dispatches/Destinations', [
            'destinations' => $destinations,
            'filters' => ['search' => $search, 'status' => $status],
            'destinationTypes' => InventoryDispatchDestination::TYPES,
            'operationTypes' => InventoryDispatch::OPERATION_TYPES,
            'operatingMode' => $this->guard->operatingMode(),
            'capabilities' => $this->presenter->presentCapabilities($user),
        ]);
    }

    public function store(StoreWarehouseDispatchDestinationRequest $request): RedirectResponse
    {
        $dest = $this->service->create($request->validated(), $request->user());

        return redirect()
            ->route('inventory.dispatches.destinations.index')
            ->with('success', "Destino {$dest->code} ({$dest->name}) creado correctamente.");
    }

    public function update(
        UpdateWarehouseDispatchDestinationRequest $request,
        InventoryDispatchDestination $destination,
    ): RedirectResponse {
        $updated = $this->service->update($destination, $request->validated());

        return redirect()
            ->route('inventory.dispatches.destinations.index')
            ->with('success', "Destino {$updated->code} actualizado.");
    }

    public function toggle(Request $request, InventoryDispatchDestination $destination): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('toggle', $destination);
        $updated = $this->service->toggle($destination);
        $label = $updated->is_active ? 'activado' : 'desactivado';

        return redirect()
            ->route('inventory.dispatches.destinations.index')
            ->with('success', "Destino {$updated->code} {$label}.");
    }
}
