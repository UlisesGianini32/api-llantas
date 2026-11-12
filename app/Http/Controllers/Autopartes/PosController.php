<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveDiscountApproval;
use App\Models\AutomotivePosSale;
use App\Models\AutomotivePosShift;
use App\Services\Autopartes\AutomotiveDiscountService;
use App\Services\Autopartes\AutomotivePosReceiptService;
use App\Services\Autopartes\AutomotivePosSaleService;
use App\Services\Autopartes\AutomotiveSellerService;
use App\Services\Autopartes\AutomotiveShiftService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotivePosSaleService $saleService,
        private readonly AutomotiveShiftService $shiftService,
        private readonly AutomotiveSellerService $sellerService,
        private readonly AutomotiveDiscountService $discountService,
        private readonly AutomotivePosReceiptService $receiptService,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        if (
            ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            && ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
        ) {
            $this->access->authorize($user, $branch, BranchCatalogAccess::POS_VIEW);
        }

        $registers = $this->shiftService->listRegisters($branch);
        $activeShift = $this->shiftService->getActiveShiftForBranch($branch, $user)
            ?? $this->shiftService->getActiveShiftForBranch($branch);
        $shiftSummary = $activeShift ? $this->shiftService->calculateShiftSummary($activeShift) : null;
        $sellers = $this->sellerService->listActiveForPos($branch);
        $initialProducts = $this->saleService->searchProductsForPos(
            $branch,
            '',
            $activeShift?->automotive_location_id,
            $user,
            24,
        );

        $pendingApprovals = $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_APPROVE)
            ? AutomotiveDiscountApproval::query()
                ->where('branch_id', $branch->getKey())
                ->where('status', AutomotiveDiscountApproval::STATUS_PENDING)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
            : collect();

        if ($pendingApprovals->isNotEmpty()) {
            $requesters = AutopartesUserDirectory::mapByIds($pendingApprovals->pluck('requested_by')->all());
            $pendingApprovals->each(function (AutomotiveDiscountApproval $approval) use ($requesters): void {
                $approval->setAttribute('requested_by_name', $requesters->get((int) $approval->requested_by)?->name);
            });
        }

        $policy = $branch->ensureDefaultCommercialPolicy();

        return Inertia::render('Autopartes/Pos', [
            'branch' => $branch->only(['id', 'code', 'name']),
            'capabilities' => $this->access->capabilities($user, $branch),
            'cashier' => $user->only(['id', 'name', 'email']),
            'registers' => $registers,
            'activeShift' => $activeShift,
            'shiftSummary' => $shiftSummary,
            'shiftHistory' => $this->shiftService->listShiftHistory($branch, $user, 15),
            'sellers' => $sellers,
            'initialProducts' => $initialProducts,
            'pendingApprovals' => $pendingApprovals,
            'commercialPolicy' => $policy,
            'posConfig' => [
                'pos_sales_enabled' => (bool) $policy->pos_sales_enabled || (bool) config('autopartes.pos.sales_enabled_by_default', false),
                'fiscal_policy_validated' => (bool) $policy->fiscal_policy_validated || (bool) config('autopartes.pos.fiscal_policy_validated_by_default', false),
                'manual_discounts_enabled' => (bool) $policy->manual_discounts_enabled || (bool) config('autopartes.pos.manual_discounts_enabled_by_default', false),
                'tax_enabled' => (bool) $policy->tax_enabled || (bool) config('autopartes.pos.tax_enabled', false),
                'tax_rate' => (float) ($policy->tax_rate > 0 ? $policy->tax_rate : config('autopartes.pos.tax_rate', 0.0)),
                'cashier_max_manual_discount_percent' => (float) ($policy->cashier_max_discount_percent > 0 ? $policy->cashier_max_discount_percent : config('autopartes.pos.cashier_max_manual_discount_percent', 0.0)),
                'cashier_max_manual_discount_amount' => (float) ($policy->cashier_max_discount_amount > 0 ? $policy->cashier_max_discount_amount : config('autopartes.pos.cashier_max_manual_discount_amount', 0.0)),
                'default_thermal_width' => (string) ($policy->default_thermal_width ?: '80mm'),
            ],
        ]);
    }

    public function searchProducts(Request $request, AutomotiveBranch $branch): JsonResponse
    {
        $query = (string) $request->input('q', '');
        $rawLocationId = $request->input('automotive_location_id');
        $locationId = $rawLocationId !== null && $rawLocationId !== '' ? (int) $rawLocationId : null;

        $products = $this->saleService->searchProductsForPos($branch, $query, $locationId, $request->user());

        return response()->json([
            'ok' => true,
            'products' => $products,
        ]);
    }

    public function lookupCode(Request $request, AutomotiveBranch $branch): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:191'],
            'automotive_location_id' => ['nullable', 'integer'],
        ]);

        $rawLocationId = $validated['automotive_location_id'] ?? null;
        $locationId = $rawLocationId !== null ? (int) $rawLocationId : null;

        $result = $this->saleService->lookupCodeForPos(
            $branch,
            (string) $validated['code'],
            $locationId,
            $request->user(),
        );

        return response()->json($result);
    }

    public function calculateCart(Request $request, AutomotiveBranch $branch): JsonResponse
    {
        $calc = $this->saleService->calculateCart($branch, $request->all(), $request->user(), false);

        return response()->json([
            'ok' => true,
            'calculation' => $calc,
        ]);
    }

    public function requestDiscountApproval(Request $request, AutomotiveBranch $branch): JsonResponse
    {
        $approval = $this->saleService->requestCartManualDiscountApproval(
            $branch,
            $request->all(),
            $request->user(),
        );

        return response()->json([
            'ok' => true,
            'approval' => $approval,
        ]);
    }

    public function approveDiscountRequest(
        Request $request,
        AutomotiveBranch $branch,
        AutomotiveDiscountApproval $approval,
    ): JsonResponse|RedirectResponse {
        $approved = $this->discountService->approveRequest($branch, $approval, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'approval' => $approved,
            ]);
        }

        return back()->with('success', "Descuento manual autorizado (Token: {$approved->token}).");
    }

    public function storeSale(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $sale = $this->saleService->confirmSale($branch, $request->all(), $request->user());
        $paperType = (string) $request->input('paper_type', '80mm');
        $receiptFormat = (string) $request->input('receipt_format', 'both');
        $receipt = $this->receiptService->formatSaleReceipt($sale, $paperType, true, false, $receiptFormat);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'sale' => $sale,
                'receipt' => $receipt,
                'letter_receipt' => $receipt['letter'],
            ]);
        }

        return back()
            ->with('success', "Venta {$sale->folio} confirmada correctamente.")
            ->with('last_sale', $sale)
            ->with('last_receipt', $receipt);
    }

    public function openShift(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $shift = $this->shiftService->openShift($branch, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'shift' => $shift,
                'summary' => $this->shiftService->calculateShiftSummary($shift),
            ]);
        }

        return back()->with('success', "Turno {$shift->folio} abierto correctamente.");
    }

    public function addCashMovement(
        Request $request,
        AutomotiveBranch $branch,
        AutomotivePosShift $shift,
    ): JsonResponse|RedirectResponse {
        $movement = $this->shiftService->addCashMovement($branch, $shift, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'movement' => $movement,
                'summary' => $this->shiftService->calculateShiftSummary($shift->fresh()),
            ]);
        }

        return back()->with('success', 'Movimiento de efectivo registrado.');
    }

    public function closeShift(
        Request $request,
        AutomotiveBranch $branch,
        AutomotivePosShift $shift,
    ): JsonResponse|RedirectResponse {
        $closed = $this->shiftService->closeShift($branch, $shift, $request->all(), $request->user());
        $cutReceipt = $this->receiptService->formatShiftCutReceipt($closed, (string) $request->input('paper_type', '80mm'));

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'shift' => $closed,
                'receipt' => $cutReceipt,
            ]);
        }

        return back()->with('success', "Turno {$closed->folio} cerrado correctamente.");
    }

    public function storeRegister(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $register = $this->shiftService->createRegister($branch, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'register' => $register,
            ]);
        }

        return back()->with('success', "Caja {$register->code} creada.");
    }

    public function receipt(Request $request, AutomotiveBranch $branch, AutomotivePosSale $sale): JsonResponse
    {
        $user = $request->user();
        if (
            ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            && ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
            && ! $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::SALES_VIEW)
        ) {
            $this->access->authorize($user, $branch, BranchCatalogAccess::SALES_VIEW);
        }

        if ((int) $sale->branch_id !== (int) $branch->getKey()) {
            abort(404);
        }

        $paperType = (string) $request->input('paper_type', '80mm');
        $format = (string) $request->input('format', 'both');
        $receipt = $this->receiptService->formatSaleReceipt($sale, $paperType, false, false, $format);

        return response()->json([
            'ok' => true,
            'receipt' => $receipt,
        ]);
    }

    public function reprintReceipt(Request $request, AutomotiveBranch $branch, AutomotivePosSale $sale): JsonResponse
    {
        $paperType = (string) $request->input('paper_type', '80mm');
        $format = (string) $request->input('format', 'both');
        $receipt = $this->receiptService->reprintSaleReceipt($branch, $sale, $request->user(), $paperType, $format);

        return response()->json([
            'ok' => true,
            'receipt' => $receipt,
            'sale' => $sale->fresh(),
        ]);
    }
}
