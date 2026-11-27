<?php

namespace App\Http\Controllers\Inventory\Dispatches;

use App\Exceptions\WarehouseDispatchConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\Dispatches\LinkProvisionalItemRequest;
use App\Http\Requests\Inventory\Dispatches\MergeProvisionalItemsRequest;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Services\Inventory\Dispatches\ProvisionalItemLinkService;
use App\Services\Inventory\Dispatches\WarehouseDispatchFeatureGuard;
use App\Services\Inventory\Dispatches\WarehouseDispatchPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class ProvisionalItemController extends Controller
{
    public function __construct(
        private readonly ProvisionalItemLinkService $service,
        private readonly WarehouseDispatchFeatureGuard $guard,
        private readonly WarehouseDispatchPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('viewProvisional', InventoryDispatch::class);

        $search = trim((string) $request->input('search', ''));
        $status = (string) $request->input('status', 'pending');

        $query = InventoryProvisionalItem::query()
            ->with([
                'linkedProduct:id,sku,name,barcode,brand',
                'mergedInto:id,provisional_code,name',
                'canonical:id,provisional_code,name',
            ])
            ->when($status === 'pending', fn ($q) => $q->whereIn('status', [
                InventoryProvisionalItem::STATUS_PENDING_LINK,
                InventoryProvisionalItem::STATUS_AMBIGUOUS_REVIEW,
            ]))
            ->when(in_array($status, InventoryProvisionalItem::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($nested) use ($search): void {
                    $nested->where('provisional_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('known_sku', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('tone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id');

        $paginator = $query->paginate(25)->withQueryString();

        $provisionalIds = collect($paginator->items())->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Aggregate confirmed demand grouped by canonical_provisional_id without mixing unconverted containers with physical units.
        $statsByCanonical = [];
        if ($provisionalIds !== []) {
            $rows = DB::table('inventory_dispatch_items as di')
                ->join('inventory_dispatches as d', 'd.id', '=', 'di.inventory_dispatch_id')
                ->join('inventory_provisional_items as pi', 'pi.id', '=', 'di.provisional_item_id')
                ->where('d.status', InventoryDispatch::STATUS_CONFIRMED)
                ->where(function ($q) use ($provisionalIds): void {
                    $q->whereIn('pi.canonical_provisional_id', $provisionalIds)
                        ->orWhereIn('pi.id', $provisionalIds);
                })
                ->selectRaw('COALESCE(pi.canonical_provisional_id, pi.id) as canonical_id')
                ->selectRaw('COUNT(DISTINCT d.id) as dispatches_count')
                ->selectRaw('COALESCE(SUM(CASE WHEN di.normalized_physical_quantity IS NOT NULL THEN di.normalized_physical_quantity ELSE 0 END), 0) as total_physical_units')
                ->selectRaw('SUM(CASE WHEN di.normalized_physical_quantity IS NULL THEN di.captured_quantity ELSE 0 END) as unconverted_containers_qty')
                ->selectRaw('MAX(d.effective_at) as last_dispatched_at')
                ->groupBy(DB::raw('COALESCE(pi.canonical_provisional_id, pi.id)'))
                ->get();

            foreach ($rows as $row) {
                $statsByCanonical[(int) $row->canonical_id] = [
                    'dispatches_count' => (int) $row->dispatches_count,
                    'total_physical_units' => (int) $row->total_physical_units,
                    'unconverted_containers_qty' => (int) $row->unconverted_containers_qty,
                    'last_dispatched_at' => $row->last_dispatched_at,
                ];
            }
        }

        $paginator->through(function (InventoryProvisionalItem $item) use ($statsByCanonical): array {
            $stats = $statsByCanonical[(int) $item->getKey()] ?? [
                'dispatches_count' => 0,
                'total_physical_units' => 0,
                'unconverted_containers_qty' => 0,
                'last_dispatched_at' => null,
            ];

            return [
                'id' => (int) $item->getKey(),
                'provisional_code' => (string) $item->provisional_code,
                'barcode' => $item->barcode,
                'known_sku' => $item->known_sku,
                'name' => (string) $item->name,
                'brand' => $item->brand,
                'line' => $item->line,
                'tone' => $item->tone,
                'presentation' => $item->presentation,
                'unit_of_measure' => (string) $item->unit_of_measure,
                'default_units_per_container' => $item->default_units_per_container,
                'status' => (string) $item->status,
                'linked_product' => $item->linkedProduct ? [
                    'id' => (int) $item->linkedProduct->id,
                    'sku' => (string) $item->linkedProduct->sku,
                    'name' => (string) $item->linkedProduct->name,
                    'brand' => $item->linkedProduct->brand,
                ] : null,
                'linked_at' => $item->linked_at?->copy()->utc()->toIso8601String(),
                'merged_into' => $item->mergedInto ? [
                    'id' => (int) $item->mergedInto->id,
                    'provisional_code' => (string) $item->mergedInto->provisional_code,
                    'name' => (string) $item->mergedInto->name,
                ] : null,
                'canonical_provisional_id' => $item->resolvedCanonicalId(),
                'dispatches_count' => $stats['dispatches_count'],
                'total_physical_units' => $stats['total_physical_units'],
                'unconverted_containers_qty' => $stats['unconverted_containers_qty'],
                'last_dispatched_at' => $stats['last_dispatched_at'],
            ];
        });

        $mergeTargets = InventoryProvisionalItem::query()
            ->whereIn('status', [
                InventoryProvisionalItem::STATUS_PENDING_LINK,
                InventoryProvisionalItem::STATUS_AMBIGUOUS_REVIEW,
                InventoryProvisionalItem::STATUS_LINKED,
            ])
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'provisional_code', 'name', 'brand', 'tone', 'presentation', 'status'])
            ->map(fn (InventoryProvisionalItem $t): array => [
                'id' => (int) $t->getKey(),
                'provisional_code' => (string) $t->provisional_code,
                'name' => (string) $t->name,
                'brand' => $t->brand,
                'tone' => $t->tone,
                'presentation' => $t->presentation,
                'status' => (string) $t->status,
            ])
            ->values()
            ->all();

        return Inertia::render('Inventory/Dispatches/ProvisionalIndex', [
            'provisionalItems' => $paginator,
            'mergeTargets' => $mergeTargets,
            'filters' => ['search' => $search, 'status' => $status],
            'operatingMode' => $this->guard->operatingMode(),
            'capabilities' => $this->presenter->presentCapabilities($user),
        ]);
    }

    public function link(
        LinkProvisionalItemRequest $request,
        InventoryProvisionalItem $provisionalItem,
    ): RedirectResponse {
        $validated = $request->validated();
        $product = InventoryProduct::query()->findOrFail((int) $validated['inventory_product_id']);

        try {
            $linked = $this->service->linkToMasterProduct(
                $provisionalItem,
                $product,
                $request->user(),
                $validated['link_notes'] ?? null,
            );
        } catch (WarehouseDispatchConflictException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('inventory.dispatches.provisional.index')
            ->with('success', "Ficha {$linked->provisional_code} vinculada a {$product->sku} sin descontar inventario histórico.");
    }

    public function merge(
        MergeProvisionalItemsRequest $request,
        InventoryProvisionalItem $provisionalItem,
    ): RedirectResponse {
        $validated = $request->validated();
        $target = InventoryProvisionalItem::query()->findOrFail((int) $validated['target_provisional_item_id']);

        try {
            $this->service->mergeProvisionalItems(
                $provisionalItem,
                $target,
                $request->user(),
                (string) $validated['merge_reason'],
            );
        } catch (WarehouseDispatchConflictException|InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('inventory.dispatches.provisional.index')
            ->with('success', "Ficha {$provisionalItem->provisional_code} fusionada en {$target->provisional_code} preservando el historial original.");
    }
}
