<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotivePart;
use App\Models\AutomotivePriceHistory;
use App\Services\Autopartes\AutomotivePriceService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PriceController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotivePriceService $priceService,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $filters = [
            'q' => (string) $request->query('q', ''),
            'category' => (string) $request->query('category', ''),
            'price_status' => (string) $request->query('price_status', ''),
        ];

        $parts = $this->priceService->paginateCatalogPrices($branch, $filters, $user);

        $categories = AutomotivePart::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $recentHistory = AutomotivePriceHistory::query()
            ->with(['part:id,code,item_number,manufacturer_part_number,description'])
            ->where('branch_id', $branch->getKey())
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $historyUsers = AutopartesUserDirectory::mapByIds($recentHistory->pluck('changed_by')->all());
        $recentHistory->each(function (AutomotivePriceHistory $item) use ($historyUsers): void {
            $item->setAttribute('changed_by_name', $historyUsers->get((int) $item->changed_by)?->name);
        });

        return Inertia::render('Autopartes/Prices', [
            'branch' => $branch->only(['id', 'code', 'name']),
            'capabilities' => $this->access->capabilities($user, $branch),
            'filters' => $filters,
            'parts' => $parts,
            'statusCounts' => $this->priceService->getPriceStatusCounts($branch),
            'categories' => $categories,
            'recentHistory' => $recentHistory,
        ]);
    }

    public function update(
        Request $request,
        AutomotiveBranch $branch,
        AutomotivePart $part,
    ): JsonResponse|RedirectResponse {
        $setting = $this->priceService->configurePrice($branch, $part, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'setting' => $setting,
            ]);
        }

        return back()->with('success', "Precio MXN de [{$part->item_number}] actualizado.");
    }

    public function previewBatch(Request $request, AutomotiveBranch $branch): JsonResponse
    {
        $preview = $this->priceService->previewBatchUpdate(
            $branch,
            is_array($request->input('items')) ? $request->input('items') : [],
            (string) $request->input('reason', ''),
            $request->user(),
        );

        return response()->json([
            'ok' => true,
            'preview' => $preview,
        ]);
    }

    public function applyBatch(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $result = $this->priceService->applyBatchUpdate(
            $branch,
            is_array($request->input('items')) ? $request->input('items') : [],
            (string) $request->input('reason', ''),
            $request->user(),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'result' => $result,
            ]);
        }

        return back()->with('success', "Lote {$result['batch_reference']} aplicado ({$result['updated_count']} autopartes).");
    }

    public function history(Request $request, AutomotiveBranch $branch, AutomotivePart $part): JsonResponse
    {
        $user = $request->user();
        if (
            ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::PRICES_VIEW)
            && ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::PRICES_MANAGE)
        ) {
            $this->access->authorize($user, $branch, BranchCatalogAccess::PRICES_VIEW);
        }

        $history = AutomotivePriceHistory::query()
            ->where('branch_id', $branch->getKey())
            ->where('automotive_part_id', $part->getKey())
            ->orderByDesc('id')
            ->get();

        $users = AutopartesUserDirectory::mapByIds($history->pluck('changed_by')->all());
        $history->each(function (AutomotivePriceHistory $item) use ($users): void {
            $item->setAttribute('changed_by_name', $users->get((int) $item->changed_by)?->name);
        });

        return response()->json([
            'ok' => true,
            'history' => $history,
        ]);
    }

    public function previewImport(Request $request, AutomotiveBranch $branch): JsonResponse
    {
        $preview = $this->priceService->previewBulkImport($branch, $request->all(), $request->user());

        return response()->json([
            'ok' => true,
            'preview' => $preview,
        ]);
    }

    public function applyImport(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $result = $this->priceService->applyBulkImport($branch, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'result' => $result,
            ]);
        }

        return back()->with('success', "Importación masiva {$result['batch_reference']} confirmada ({$result['updated_count']} autopartes actualizadas).");
    }
}
