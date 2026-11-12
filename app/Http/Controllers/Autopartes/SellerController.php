<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveSeller;
use App\Services\Autopartes\AutomotiveSellerService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotiveSellerService $sellerService,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $sellers = $this->sellerService->paginateSellers($branch, $request->only(['q', 'active']), $user);
        $availableUsers = AutopartesUserDirectory::listSelectable();

        return Inertia::render('Autopartes/Sellers', [
            'branch' => $branch->only(['id', 'code', 'name']),
            'capabilities' => $this->access->capabilities($user, $branch),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'active' => (string) $request->query('active', ''),
            ],
            'sellers' => $sellers,
            'availableUsers' => $availableUsers,
        ]);
    }

    public function store(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $seller = $this->sellerService->createSeller($branch, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'seller' => $seller,
            ]);
        }

        return back()->with('success', "Vendedor {$seller->code} — {$seller->full_name} registrado.");
    }

    public function update(
        Request $request,
        AutomotiveBranch $branch,
        AutomotiveSeller $seller,
    ): JsonResponse|RedirectResponse {
        $updated = $this->sellerService->updateSeller($branch, $seller, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'seller' => $updated,
            ]);
        }

        return back()->with('success', "Vendedor {$updated->code} actualizado.");
    }
}
