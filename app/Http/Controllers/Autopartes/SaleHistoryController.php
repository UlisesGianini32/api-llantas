<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotivePosSale;
use App\Models\AutomotiveSeller;
use App\Services\Autopartes\AutomotivePosSaleService;
use App\Services\Autopartes\BranchCatalogAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SaleHistoryController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotivePosSaleService $saleService,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $filters = [
            'folio' => (string) $request->query('folio', ''),
            'seller_id' => (string) $request->query('seller_id', ''),
            'payment_method' => (string) $request->query('payment_method', ''),
            'status' => (string) $request->query('status', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
        ];

        $sales = $this->saleService->paginateSalesHistory($branch, $filters, $user);
        $sellers = AutomotiveSeller::query()
            ->where('branch_id', $branch->getKey())
            ->orderBy('full_name')
            ->get(['id', 'code', 'full_name', 'is_active']);

        return Inertia::render('Autopartes/Sales', [
            'branch' => $branch->only(['id', 'code', 'name']),
            'capabilities' => $this->access->capabilities($user, $branch),
            'filters' => $filters,
            'sales' => $sales,
            'sellers' => $sellers,
        ]);
    }

    public function cancel(
        Request $request,
        AutomotiveBranch $branch,
        AutomotivePosSale $sale,
    ): JsonResponse|RedirectResponse {
        $returnRecord = $this->saleService->cancelSale($branch, $sale, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'return' => $returnRecord,
                'sale' => $sale->fresh(['items', 'returns.items']),
            ]);
        }

        return back()->with('success', "Venta {$sale->folio} cancelada ({$returnRecord->folio}).");
    }

    public function returnItems(
        Request $request,
        AutomotiveBranch $branch,
        AutomotivePosSale $sale,
    ): JsonResponse|RedirectResponse {
        $returnRecord = $this->saleService->returnSaleItems($branch, $sale, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'return' => $returnRecord,
                'sale' => $sale->fresh(['items', 'returns.items']),
            ]);
        }

        return back()->with('success', "Devolución {$returnRecord->folio} procesada correctamente.");
    }
}
