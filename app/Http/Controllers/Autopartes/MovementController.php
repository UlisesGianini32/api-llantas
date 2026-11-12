<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveInventoryMovement;
use App\Models\AutomotiveStockAdjustment;
use App\Services\Autopartes\AutomotiveBarcodeLookupService;
use App\Services\Autopartes\AutomotiveStockAdjustmentService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MovementController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotiveStockAdjustmentService $adjustments,
        private readonly AutomotiveBarcodeLookupService $lookup,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $this->access->authorize($user, $branch, BranchCatalogAccess::MOVEMENTS_VIEW);
        $defaultLocation = $branch->ensureDefaultReceptionLocation();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'automotive_part_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'automotive_location_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'adjustment_search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $branchLocations = $branch->locations()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active']);
        $branchLocationIds = $branchLocations->pluck('id')->all();

        $selectedLocationId = isset($filters['automotive_location_id']) && $filters['automotive_location_id'] !== null && $filters['automotive_location_id'] !== ''
            ? (int) $filters['automotive_location_id']
            : null;
        if ($selectedLocationId !== null && ! in_array($selectedLocationId, $branchLocationIds, true)) {
            abort(404);
        }

        $scopedLocationIds = $selectedLocationId !== null ? [$selectedLocationId] : $branchLocationIds;

        $query = AutomotiveInventoryMovement::query()
            ->where('branch_id', $branch->getKey())
            ->whereIn('automotive_location_id', $scopedLocationIds === [] ? [0] : $scopedLocationIds)
            ->with([
                'part',
                'location:id,code,name,branch_id',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('part', function ($partQuery) use ($search): void {
                        $partQuery->where('code', 'like', "%{$search}%")
                            ->orWhere('item_number', 'like', "%{$search}%")
                            ->orWhere('manufacturer_part_number', 'like', "%{$search}%")
                            ->orWhere('verified_barcode', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%");
                    });
            });
        }

        if (filled($filters['automotive_part_id'] ?? null)) {
            $query->where('automotive_part_id', (int) $filters['automotive_part_id']);
        }

        if (filled($filters['user_id'] ?? null)) {
            $query->where('created_by', (int) $filters['user_id']);
        }

        if (filled($filters['type'] ?? null)) {
            $query->where('type', (string) $filters['type']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->whereDate('occurred_at', '>=', (string) $filters['date_from']);
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->whereDate('occurred_at', '<=', (string) $filters['date_to']);
        }

        $paginatedMovements = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $actorIds = AutomotiveInventoryMovement::query()
            ->where('branch_id', $branch->getKey())
            ->whereNotNull('created_by')
            ->distinct()
            ->pluck('created_by')
            ->all();

        $recentAdjustmentModels = AutomotiveStockAdjustment::query()
            ->where('branch_id', $branch->getKey())
            ->with([
                'part',
                'location:id,code,name',
            ])
            ->latest('id')
            ->limit(20)
            ->get();

        $userIds = [
            ...$actorIds,
            ...$paginatedMovements->getCollection()->pluck('created_by')->all(),
            ...$recentAdjustmentModels->pluck('created_by')->all(),
            ...$recentAdjustmentModels->pluck('approved_by')->all(),
        ];
        $users = AutopartesUserDirectory::mapByIds($userIds);

        $movements = $paginatedMovements->through(function (AutomotiveInventoryMovement $movement) use ($branch, $users): array {
            $part = $movement->part;
            $meta = is_array($movement->metadata) ? $movement->metadata : [];
            $actor = $movement->created_by ? $users->get((int) $movement->created_by) : null;

            return [
                'id' => (int) $movement->getKey(),
                'folio' => $movement->reference ?: ('MOV-'.$movement->getKey()),
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
                'user_name' => $actor?->name ?? 'Sistema',
                'user_id' => $movement->created_by ? (int) $movement->created_by : null,
                'automotive_part_id' => (int) $movement->automotive_part_id,
                'automotive_location_id' => (int) $movement->automotive_location_id,
                'code' => (string) ($part?->code ?? '—'),
                'item_number' => $part?->item_number,
                'manufacturer_part_number' => $part?->manufacturer_part_number,
                'verified_barcode' => $part?->verified_barcode,
                'description' => $part?->description,
                'type' => $movement->type,
                'quantity' => (int) $movement->quantity,
                'stock_before' => (int) $movement->stock_before,
                'stock_after' => (int) $movement->stock_after,
                'branch_code' => $branch->code,
                'branch_name' => $branch->name,
                'location_code' => $movement->location?->code ?? '—',
                'location_name' => $movement->location?->name ?? '—',
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id ? (int) $movement->reference_id : null,
                'reason' => $meta['reason'] ?? ($movement->reference_type === 'automotive_receipt' ? 'Recepción confirmada de autopartes' : 'Movimiento de inventario'),
                'notes' => $movement->notes,
            ];
        });

        $actors = collect($actorIds)
            ->map(fn ($id) => $users->get((int) $id))
            ->filter()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->map(fn ($u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all();

        $recentAdjustments = $recentAdjustmentModels
            ->map(function (AutomotiveStockAdjustment $adj) use ($users): array {
                $part = $adj->part;

                return [
                    'id' => (int) $adj->getKey(),
                    'folio' => $adj->folio,
                    'status' => $adj->status,
                    'type' => $adj->type,
                    'quantity' => (int) $adj->quantity,
                    'stock_before' => $adj->stock_before !== null ? (int) $adj->stock_before : null,
                    'stock_after' => $adj->stock_after !== null ? (int) $adj->stock_after : null,
                    'reason' => $adj->reason,
                    'notes' => $adj->notes,
                    'automotive_part_id' => (int) $adj->automotive_part_id,
                    'automotive_location_id' => (int) $adj->automotive_location_id,
                    'code' => $part?->code,
                    'item_number' => $part?->item_number,
                    'manufacturer_part_number' => $part?->manufacturer_part_number,
                    'description' => $part?->description,
                    'location_code' => $adj->location?->code,
                    'created_by_name' => $users->get((int) $adj->created_by)?->name,
                    'approved_by_name' => $users->get((int) $adj->approved_by)?->name,
                    'created_at' => $adj->created_at?->toIso8601String(),
                    'approved_at' => $adj->approved_at?->toIso8601String(),
                    'automotive_movement_id' => $adj->automotive_movement_id ? (int) $adj->automotive_movement_id : null,
                ];
            })
            ->values()
            ->all();

        $adjustmentSearch = trim((string) ($filters['adjustment_search'] ?? ''));
        $adjustmentCandidates = $adjustmentSearch !== ''
            ? $this->lookup->manualSearch($user, $branch, $adjustmentSearch, 20)
            : [];

        return Inertia::render('Autopartes/Movements', [
            'branch' => $branch->only('code', 'name'),
            'defaultLocationId' => $defaultLocation->getKey(),
            'locations' => $branchLocations,
            'actors' => $actors,
            'movements' => $movements,
            'recentAdjustments' => $recentAdjustments,
            'adjustmentSearch' => $adjustmentSearch,
            'adjustmentCandidates' => $adjustmentCandidates,
            'suggestedIdempotencyKey' => 'adj-'.$branch->code.'-'.Str::uuid(),
            'filters' => [
                'search' => $search,
                'automotive_part_id' => $filters['automotive_part_id'] ?? '',
                'user_id' => $filters['user_id'] ?? '',
                'automotive_location_id' => $selectedLocationId ?? '',
                'type' => $filters['type'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
            'capabilities' => $this->access->capabilities($user, $branch),
        ]);
    }

    public function storeAdjustment(Request $request, AutomotiveBranch $branch): RedirectResponse
    {
        $adjustment = $this->adjustments->create($request->user(), $branch, $request->all());

        $message = $adjustment->status === AutomotiveStockAdjustment::STATUS_APPROVED
            ? "Ajuste {$adjustment->folio} aplicado correctamente. Saldo resultante: {$adjustment->stock_after}."
            : "Ajuste {$adjustment->folio} registrado como pendiente de aprobación.";

        return back()->with('success', $message);
    }

    public function approveAdjustment(
        Request $request,
        AutomotiveBranch $branch,
        AutomotiveStockAdjustment $adjustment,
    ): RedirectResponse {
        $approved = $this->adjustments->approve($request->user(), $branch, $adjustment);

        return back()->with('success', "Ajuste {$approved->folio} aprobado y aplicado. Saldo resultante: {$approved->stock_after}.");
    }
}
