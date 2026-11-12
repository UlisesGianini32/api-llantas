<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveReceipt;
use App\Models\AutomotiveReceiptItem;
use App\Services\Autopartes\AutomotiveBarcodeLookupService;
use App\Services\Autopartes\AutomotiveReceiptService;
use App\Services\Autopartes\AutomotiveStockService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotiveReceiptService $receipts,
        private readonly AutomotiveBarcodeLookupService $lookup,
        private readonly AutomotiveStockService $stock,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $this->authorizeView($request, $branch);
        $defaultLocation = $branch->ensureDefaultReceptionLocation();

        $locations = $branch->locations()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $paginated = AutomotiveReceipt::query()
            ->where('branch_id', $branch->getKey())
            ->with([
                'location:id,code,name',
            ])
            ->withCount('items')
            ->withSum('items as total_units', 'quantity')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $userIds = [];
        foreach ($paginated->getCollection() as $receipt) {
            $userIds[] = $receipt->created_by;
            $userIds[] = $receipt->confirmed_by;
        }
        $users = AutopartesUserDirectory::mapByIds($userIds);

        $receipts = $paginated->through(fn (AutomotiveReceipt $receipt): array => [
            'id' => (int) $receipt->getKey(),
            'folio' => $receipt->folio,
            'status' => $receipt->status,
            'location' => $receipt->location ? [
                'id' => $receipt->location->id,
                'code' => $receipt->location->code,
                'name' => $receipt->location->name,
            ] : null,
            'items_count' => (int) ($receipt->items_count ?? 0),
            'total_units' => (int) ($receipt->total_units ?? 0),
            'notes' => $receipt->notes,
            'created_by_name' => $users->get((int) $receipt->created_by)?->name,
            'confirmed_by_name' => $users->get((int) $receipt->confirmed_by)?->name,
            'created_at' => $receipt->created_at?->toIso8601String(),
            'confirmed_at' => $receipt->confirmed_at?->toIso8601String(),
        ]);

        return Inertia::render('Autopartes/Receipts', [
            'branch' => $branch->only('code', 'name'),
            'defaultLocationId' => $defaultLocation->getKey(),
            'locations' => $locations,
            'receipts' => $receipts,
            'capabilities' => $this->access->capabilities($request->user(), $branch),
        ]);
    }

    public function store(Request $request, AutomotiveBranch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'automotive_location_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $receipt = $this->receipts->createDraft($request->user(), $branch, $validated);

        return to_route('autopartes.receipts.show', [$branch->code, $receipt->getKey()])
            ->with('success', "Borrador de recepción {$receipt->folio} creado. Puede iniciar el escaneo de etiquetas.");
    }

    public function show(Request $request, AutomotiveBranch $branch, AutomotiveReceipt $receipt): Response
    {
        $this->authorizeView($request, $branch);
        abort_unless((int) $receipt->branch_id === (int) $branch->getKey(), 404);

        $receipt->load([
            'location:id,code,name,branch_id,is_active',
            'items' => fn ($q) => $q->orderByDesc('id'),
            'items.part.sourceRow.sheet.file',
            'items.part.identifiers' => fn ($q) => $q->where('is_active', true),
            'items.movement',
        ]);

        $users = AutopartesUserDirectory::mapByIds([$receipt->created_by, $receipt->confirmed_by]);

        $manualQuery = trim((string) $request->input('manual_search', ''));
        $manualResults = $manualQuery !== ''
            ? $this->lookup->manualSearch($request->user(), $branch, $manualQuery, 25)
            : [];

        $draftPartIds = $receipt->isConfirmed()
            ? []
            : $receipt->items->pluck('automotive_part_id')->filter()->map(static fn ($id): int => (int) $id)->values()->all();
        $stockByPart = $draftPartIds === []
            ? []
            : $this->stock->stockSummaryForPartIds($branch, $draftPartIds, (int) $receipt->automotive_location_id);

        $items = $receipt->items->map(function (AutomotiveReceiptItem $item) use ($receipt, $stockByPart): array {
            $part = $item->part;
            $row = $part?->sourceRow;
            $cells = is_array($row?->cells) ? $row->cells : [];
            $excelQty = $cells[6]['display'] ?? ($cells[6]['value'] ?? '0');

            $stockBefore = $receipt->isConfirmed() && $item->stock_before !== null
                ? (int) $item->stock_before
                : (int) ($stockByPart[(int) $item->automotive_part_id]['physical'] ?? 0);

            $stockAfter = $receipt->isConfirmed() && $item->stock_after !== null
                ? (int) $item->stock_after
                : $stockBefore + (int) $item->quantity;

            return [
                'id' => (int) $item->getKey(),
                'automotive_part_id' => (int) $item->automotive_part_id,
                'code' => (string) ($part?->code ?? '—'),
                'item_number' => $part?->item_number,
                'manufacturer_part_number' => $part?->manufacturer_part_number,
                'verified_barcode' => $part?->verified_barcode,
                'description' => $part?->description,
                'vendor_name' => $part?->vendor_name,
                'category' => $part?->category,
                'subcategory' => $part?->subcategory,
                'identity_status' => $part?->identity_status ?? $item->identity_status_snapshot,
                'is_receivable' => $part ? $part->isReceivable() : false,
                'scanned_code' => $item->scanned_code,
                'entry_mode' => $item->entry_mode,
                'quantity' => (int) $item->quantity,
                'scan_events_count' => (int) $item->scan_events_count,
                'count_detail' => is_array($item->count_detail) ? $item->count_detail : [],
                'excel_qty' => (string) $excelQty,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'automotive_movement_id' => $item->automotive_movement_id ? (int) $item->automotive_movement_id : null,
                'notes' => $item->notes,
                'source_row_id' => $row?->getKey(),
                'source_row_number' => $row?->row_number,
            ];
        })->values()->all();

        return Inertia::render('Autopartes/ReceiptShow', [
            'branch' => $branch->only('code', 'name'),
            'receipt' => [
                'id' => (int) $receipt->getKey(),
                'folio' => $receipt->folio,
                'status' => $receipt->status,
                'notes' => $receipt->notes,
                'idempotency_key' => $receipt->idempotency_key,
                'suggested_idempotency_key' => $receipt->idempotency_key ?: ('confirm-receipt-'.$receipt->getKey().'-'.Str::uuid()),
                'location' => $receipt->location ? [
                    'id' => $receipt->location->id,
                    'code' => $receipt->location->code,
                    'name' => $receipt->location->name,
                ] : null,
                'created_by_name' => $users->get((int) $receipt->created_by)?->name,
                'confirmed_by_name' => $users->get((int) $receipt->confirmed_by)?->name,
                'created_at' => $receipt->created_at?->toIso8601String(),
                'confirmed_at' => $receipt->confirmed_at?->toIso8601String(),
                'total_items' => count($items),
                'total_units' => array_sum(array_column($items, 'quantity')),
            ],
            'items' => $items,
            'manualSearch' => $manualQuery,
            'manualResults' => $manualResults,
            'lookupDisambiguation' => $request->session()->get('lookup_disambiguation'),
            'lookupWarning' => $request->session()->get('lookup_warning'),
            'capabilities' => $this->access->capabilities($request->user(), $branch),
        ]);
    }

    public function lookup(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $code = $request->input('code');
        $result = $this->lookup->lookup($request->user(), $branch, $code);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back()->with('lookup_disambiguation', $result);
    }

    public function addItem(Request $request, AutomotiveBranch $branch, AutomotiveReceipt $receipt): RedirectResponse
    {
        $result = $this->receipts->addItem($request->user(), $branch, $receipt, $request->all());

        if (! $result['added']) {
            return back()
                ->with('lookup_disambiguation', $result['lookup'])
                ->with('warning', $result['message']);
        }

        return back()
            ->with('success', $result['message'])
            ->with('lookup_warning', $result['lookup']['warning'] ?? null);
    }

    public function updateItem(
        Request $request,
        AutomotiveBranch $branch,
        AutomotiveReceipt $receipt,
        AutomotiveReceiptItem $item,
    ): RedirectResponse {
        $this->receipts->updateItem(
            $request->user(),
            $branch,
            $receipt,
            $item,
            $request->input('quantity'),
            $request->input('notes'),
        );

        return back()->with('success', 'Cantidad de la partida actualizada en el borrador.');
    }

    public function removeItem(
        Request $request,
        AutomotiveBranch $branch,
        AutomotiveReceipt $receipt,
        AutomotiveReceiptItem $item,
    ): RedirectResponse {
        $this->receipts->removeItem($request->user(), $branch, $receipt, $item);

        return back()->with('success', 'Partida eliminada del borrador de recepción.');
    }

    public function confirm(Request $request, AutomotiveBranch $branch, AutomotiveReceipt $receipt): RedirectResponse
    {
        $validated = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'confirmed.accepted' => 'Debe confirmar explícitamente la recepción para generar las entradas físicas.',
        ]);

        $confirmedReceipt = $this->receipts->confirm(
            $request->user(),
            $branch,
            $receipt,
            $validated['idempotency_key'],
            $validated['notes'] ?? null,
        );

        return to_route('autopartes.receipts.show', [$branch->code, $confirmedReceipt->getKey()])
            ->with('success', "Recepción {$confirmedReceipt->folio} confirmada. Se generaron las entradas físicas en {$branch->name}.");
    }

    private function authorizeView(Request $request, AutomotiveBranch $branch): void
    {
        $user = $request->user();
        $allowed = $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::RECEIPT_DRAFT)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::RECEIPT_CONFIRM)
            || $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::STOCK_VIEW);

        if (! $allowed) {
            throw new AuthorizationException('No cuenta con permisos para consultar recepciones en esta sucursal.');
        }
    }
}
