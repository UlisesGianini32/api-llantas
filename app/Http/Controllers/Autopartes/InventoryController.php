<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveInventoryBalance;
use App\Models\AutomotivePart;
use App\Models\AutopartesModel;
use App\Services\Autopartes\AutomotiveBarcodeLookupService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private readonly BranchCatalogAccess $access) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $this->access->authorize($user, $branch, BranchCatalogAccess::STOCK_VIEW);
        $branch->ensureDefaultReceptionLocation();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:500'],
            'vendor' => ['nullable', 'string', 'max:500'],
            'automotive_location_id' => ['nullable', 'integer'],
            'stock_filter' => ['nullable', 'string', 'in:all,with_stock,without_stock'],
            'identity_status' => ['nullable', 'string', 'in:all,provisional,ambiguous,verified'],
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

        $balanceSubquery = AutomotiveInventoryBalance::query()
            ->selectRaw('automotive_part_id, COALESCE(SUM(physical_quantity), 0) as branch_stock')
            ->where('branch_id', $branch->getKey())
            ->whereIn('automotive_location_id', $scopedLocationIds === [] ? [0] : $scopedLocationIds)
            ->groupBy('automotive_part_id');

        $totalCatalog = AutomotivePart::query()->count();

        $withStockCount = $branchLocationIds === [] ? 0 : AutopartesModel::db()->query()
            ->fromSub(
                AutomotiveInventoryBalance::query()
                    ->select('automotive_part_id')
                    ->where('branch_id', $branch->getKey())
                    ->whereIn('automotive_location_id', $scopedLocationIds)
                    ->groupBy('automotive_part_id')
                    ->havingRaw('SUM(physical_quantity) > 0'),
                'stocked_parts'
            )
            ->count();

        $totalUnits = $branchLocationIds === [] ? 0 : (int) AutomotiveInventoryBalance::query()
            ->where('branch_id', $branch->getKey())
            ->whereIn('automotive_location_id', $scopedLocationIds)
            ->sum('physical_quantity');

        $ambiguousCount = AutomotivePart::query()
            ->where('identity_status', AutomotivePart::STATUS_AMBIGUOUS)
            ->count();

        $verifiedCount = AutomotivePart::query()
            ->where('identity_status', AutomotivePart::STATUS_VERIFIED)
            ->count();

        $categories = AutomotivePart::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $vendors = AutomotivePart::query()
            ->whereNotNull('vendor_name')
            ->where('vendor_name', '!=', '')
            ->distinct()
            ->orderBy('vendor_name')
            ->pluck('vendor_name');

        $query = AutomotivePart::query()
            ->select('automotive_parts.*')
            ->selectRaw('COALESCE(branch_totals.branch_stock, 0) as branch_stock')
            ->leftJoinSub($balanceSubquery, 'branch_totals', function ($join): void {
                $join->on('branch_totals.automotive_part_id', '=', 'automotive_parts.id');
            })
            ->with([
                'sourceRow.sheet.file',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $lower = mb_strtolower($search);
            $upper = mb_strtoupper($search);
            $query->where(function ($q) use ($search, $lower, $upper): void {
                $q->where('automotive_parts.code', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.item_number', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.manufacturer_part_number', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.verified_barcode', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.description', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.vendor_name', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.category', 'like', "%{$search}%")
                    ->orWhere('automotive_parts.subcategory', 'like', "%{$search}%")
                    ->orWhereHas('sourceRow', fn ($rq) => $rq->where('search_text', 'like', "%{$lower}%"))
                    ->orWhereHas('identifiers', fn ($iq) => $iq->where('is_active', true)->whereRaw('UPPER(identifier_value) LIKE ?', ["%{$upper}%"]));
            });
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('automotive_parts.category', (string) $filters['category']);
        }

        if (filled($filters['vendor'] ?? null)) {
            $query->where('automotive_parts.vendor_name', (string) $filters['vendor']);
        }

        $identityStatus = (string) ($filters['identity_status'] ?? 'all');
        if (in_array($identityStatus, AutomotivePart::STATUSES, true)) {
            $query->where('automotive_parts.identity_status', $identityStatus);
        }

        $stockFilter = (string) ($filters['stock_filter'] ?? 'all');
        if ($stockFilter === 'with_stock') {
            $query->whereRaw('COALESCE(branch_totals.branch_stock, 0) > 0');
        } elseif ($stockFilter === 'without_stock') {
            $query->whereRaw('COALESCE(branch_totals.branch_stock, 0) <= 0');
        }

        $paginated = $query
            ->orderByDesc('branch_stock')
            ->orderBy('automotive_parts.id')
            ->paginate(50)
            ->withQueryString();

        $pagePartIds = $paginated->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $locationStocks = ($pagePartIds === [] || $branchLocationIds === [])
            ? collect()
            : AutomotiveInventoryBalance::query()
                ->where('branch_id', $branch->getKey())
                ->whereIn('automotive_part_id', $pagePartIds)
                ->whereIn('automotive_location_id', $branchLocationIds)
                ->get()
                ->groupBy('automotive_part_id');

        $verifiers = AutopartesUserDirectory::mapByIds(
            $paginated->getCollection()->pluck('verified_by')->all()
        );
        $locationMap = $branchLocations->keyBy('id');

        $products = $paginated->through(function (AutomotivePart $part) use ($locationStocks, $locationMap, $verifiers): array {
            $row = $part->sourceRow;
            $cells = is_array($row?->cells) ? $row->cells : [];
            $excelQty = $cells[6]['display'] ?? ($cells[6]['value'] ?? '0');
            $excelRetail = $cells[7]['display'] ?? ($cells[7]['value'] ?? null);

            $breakdown = ($locationStocks->get($part->getKey()) ?? collect())
                ->map(function (AutomotiveInventoryBalance $bal) use ($locationMap): array {
                    $loc = $locationMap->get($bal->automotive_location_id);

                    return [
                        'automotive_location_id' => (int) $bal->automotive_location_id,
                        'code' => $loc?->code ?? '—',
                        'name' => $loc?->name ?? '—',
                        'quantity' => (int) $bal->physical_quantity,
                    ];
                })
                ->filter(fn (array $loc): bool => $loc['quantity'] !== 0)
                ->values()
                ->all();

            return [
                'id' => (int) $part->getKey(),
                'automotive_part_id' => (int) $part->getKey(),
                'code' => (string) $part->code,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'verified_barcode' => $part->verified_barcode,
                'description' => $part->description,
                'category' => $part->category,
                'subcategory' => $part->subcategory,
                'vendor_name' => $part->vendor_name,
                'identity_status' => $part->identity_status ?? AutomotivePart::STATUS_PROVISIONAL,
                'allow_receipt' => (bool) ($part->allow_receipt ?? true),
                'is_receivable' => $part->isReceivable(),
                'verification_evidence' => $part->verification_evidence,
                'verified_by_name' => $verifiers->get((int) $part->verified_by)?->name,
                'verified_at' => $part->verified_at?->toIso8601String(),
                'excel_qty' => (string) $excelQty,
                'excel_retail' => $excelRetail !== null ? (string) $excelRetail : null,
                'branch_stock' => (int) ($part->branch_stock ?? 0),
                'locations_breakdown' => $breakdown,
                'source_row_id' => $row?->getKey(),
                'source_row_number' => $row?->row_number,
                'source_sheet_name' => $row?->sheet?->name,
                'source_file_name' => $row?->sheet?->file?->original_name,
            ];
        });

        return Inertia::render('Autopartes/Inventory', [
            'branch' => $branch->only('code', 'name'),
            'kpis' => [
                'total_catalog' => $totalCatalog,
                'with_stock' => $withStockCount,
                'without_stock' => max(0, $totalCatalog - $withStockCount),
                'total_units' => $totalUnits,
                'ambiguous_count' => $ambiguousCount,
                'verified_count' => $verifiedCount,
            ],
            'products' => $products,
            'filters' => [
                'search' => $search,
                'category' => $filters['category'] ?? '',
                'vendor' => $filters['vendor'] ?? '',
                'automotive_location_id' => $selectedLocationId ?? '',
                'stock_filter' => $stockFilter,
                'identity_status' => $identityStatus,
            ],
            'categories' => $categories,
            'vendors' => $vendors,
            'locations' => $branchLocations,
            'capabilities' => $this->access->capabilities($user, $branch),
        ]);
    }

    public function resolveIdentity(
        Request $request,
        AutomotiveBranch $branch,
        AutomotivePart $part,
        AutomotiveBarcodeLookupService $lookup,
    ): RedirectResponse {
        $lookup->resolveIdentity($request->user(), $branch, $part, $request->all());

        return back()->with('success', 'Identidad de autoparte verificada y registrada con trazabilidad.');
    }
}
