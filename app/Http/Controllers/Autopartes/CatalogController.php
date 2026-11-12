<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveCatalogRow;
use App\Models\AutomotiveImportRun;
use App\Services\Autopartes\AutomotiveStockService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutomotiveSourceSchema;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CatalogController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotiveStockService $stock,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch)
    {
        $user = $request->user();
        $this->access->authorize($user, $branch, BranchCatalogAccess::CATALOG_VIEW);
        $filters = $request->validate(['search' => 'nullable|string|max:200', 'category' => 'nullable|string|max:500',
            'vendor' => 'nullable|string|max:500', 'file' => 'nullable|integer', 'page' => 'nullable|integer|min:1']);
        $runs = AutomotiveImportRun::where('branch_id', $branch->id)->where('status', 'completed')->with('file')->latest('id')->get();
        $run = isset($filters['file']) ? $runs->firstWhere('file_id', (int) $filters['file']) : $runs->first();
        abort_if(isset($filters['file']) && ! $run, 404);
        $base = AutomotiveCatalogRow::query()->whereHas('sheet', fn ($q) => $q->where('file_id', $run?->file_id ?? 0));
        $categories = (clone $base)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        $vendors = (clone $base)->whereNotNull('vendor_name')->distinct()->orderBy('vendor_name')->pluck('vendor_name');
        $query = (clone $base)->with(['sheet.file', 'currentDecision.part']);
        if (filled($filters['search'] ?? null)) {
            $query->where('search_text', 'like', '%'.mb_strtolower($filters['search']).'%');
        }
        foreach (['category' => 'category', 'vendor' => 'vendor_name'] as $filter => $field) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($field, $filters[$filter]);
            }
        }
        $canStock = $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::STOCK_VIEW);
        $counts = (clone $base)->selectRaw('item_number, COUNT(*) as total')->groupBy('item_number')->pluck('total', 'item_number');
        $paginated = $query->orderBy('sheet_id')->orderBy('row_number')->paginate(50)->withQueryString();

        $stockByPart = [];
        if ($canStock) {
            $partIds = $paginated->getCollection()
                ->map(fn (AutomotiveCatalogRow $row): ?int => $row->currentDecision?->part?->id)
                ->filter()
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
            $stockByPart = $this->stock->stockSummaryForPartIds($branch, $partIds);
        }

        $rows = $paginated->through(function (AutomotiveCatalogRow $row) use ($canStock, $counts, $stockByPart): array {
            $part = $row->currentDecision?->part;
            $partId = $part ? (int) $part->getKey() : null;

            return ['id' => $row->id, 'automotive_part_id' => $partId, 'code' => $part?->code, 'item' => $row->item_number,
                'part' => $row->manufacturer_part_number, 'description' => $row->description,
                'category' => $row->category, 'vendor' => $row->vendor_name,
                'qty' => $row->cells[6]['display'], 'retail' => $row->cells[7]['display'], 'extended_retail' => $row->cells[8]['display'],
                'ambiguous' => ($counts[$row->item_number] ?? 0) > 1,
                'identity_status' => $part?->identity_status,
                'physical_stock' => $canStock && $partId !== null ? (int) ($stockByPart[$partId]['physical'] ?? 0) : null,
                'row_number' => $row->row_number, 'sheet' => $row->sheet->name];
        });

        return Inertia::render('Autopartes/Index', [
            'branch' => $branch->only('code', 'name'), 'rows' => $rows, 'filters' => $filters,
            'categories' => $categories, 'vendors' => $vendors,
            'files' => $runs->unique('file_id')->map(fn ($r) => ['id' => $r->file_id, 'name' => $r->file->original_name])->values(),
            'selectedFile' => $run?->file_id, 'canImport' => $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::IMPORT),
            'capabilities' => $this->access->capabilities($user, $branch),
        ]);
    }

    public function show(Request $request, AutomotiveBranch $branch, AutomotiveCatalogRow $row)
    {
        $user = $request->user();
        $this->access->authorize($user, $branch, BranchCatalogAccess::CATALOG_VIEW);
        $row->load(['sheet.file', 'currentDecision.part']);
        $run = AutomotiveImportRun::where('branch_id', $branch->id)->where('file_id', $row->sheet->file_id)->where('status', 'completed')->latest('id')->firstOrFail();
        $related = AutomotiveCatalogRow::where('sheet_id', $row->sheet_id)->whereKeyNot($row->id)
            ->where(function ($q) use ($row): void {
                $q->where('identity_key', $row->identity_key);
                if (filled($row->item_number)) {
                    $q->orWhere('item_number', $row->item_number);
                }
                if (filled($row->manufacturer_part_number)) {
                    $q->orWhere('manufacturer_part_number', $row->manufacturer_part_number);
                }
            })->get(['id', 'item_number', 'manufacturer_part_number', 'description', 'row_number']);
        $part = $row->currentDecision?->part;
        $canStock = $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::STOCK_VIEW);

        return Inertia::render('Autopartes/Show', [
            'branch' => $branch->only('code', 'name'), 'headers' => AutomotiveSourceSchema::HEADERS,
            'record' => ['id' => $row->id, 'automotive_part_id' => $part?->id, 'code' => $part?->code, 'identity_status' => $part?->identity_status,
                'cells' => $row->cells, 'row_number' => $row->row_number,
                'sheet' => $row->sheet->name, 'sheet_index' => $row->sheet->sheet_index,
                'file_name' => $row->sheet->file->original_name, 'sha256' => $row->sheet->file->sha256,
                'physical_stock' => $canStock && $part ? $this->stock->branchPhysicalStock($part, $branch) : null],
            'related' => $related, 'decisions' => $row->decisions()->orderBy('id')->get(['id', 'decision', 'reason', 'created_at']),
            'importUrl' => $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::IMPORT)
                ? route('autopartes.imports.show', [$branch->code, $run->id]) : null,
            'capabilities' => $this->access->capabilities($user, $branch),
        ]);
    }
}
