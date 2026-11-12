<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveDiscountApproval;
use App\Models\AutomotivePart;
use App\Models\AutomotiveWholesaleDiscountRule;
use App\Services\Autopartes\AutomotiveDiscountService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesUserDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DiscountController extends Controller
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotiveDiscountService $discountService,
    ) {}

    public function index(Request $request, AutomotiveBranch $branch): Response
    {
        $user = $request->user();
        $rules = $this->discountService->listRules($branch, $user);

        $categories = AutomotivePart::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $approvals = $this->access->allows($user, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_APPROVE)
            ? AutomotiveDiscountApproval::query()
                ->where('branch_id', $branch->getKey())
                ->orderByDesc('id')
                ->limit(30)
                ->get()
            : collect();

        $policy = $this->discountService->getCommercialPolicy($branch);

        $userIds = [$policy->updated_by];
        foreach ($approvals as $approval) {
            $userIds[] = $approval->requested_by;
            $userIds[] = $approval->approved_by;
        }
        $users = AutopartesUserDirectory::mapByIds($userIds);

        $approvals->each(function (AutomotiveDiscountApproval $approval) use ($users): void {
            $approval->setAttribute('requested_by_name', $users->get((int) $approval->requested_by)?->name);
            $approval->setAttribute('approved_by_name', $users->get((int) $approval->approved_by)?->name);
        });
        $policy->setAttribute('updated_by_name', $users->get((int) $policy->updated_by)?->name);

        return Inertia::render('Autopartes/Discounts', [
            'branch' => $branch->only(['id', 'code', 'name']),
            'capabilities' => $this->access->capabilities($user, $branch),
            'rules' => $rules,
            'categories' => $categories,
            'approvals' => $approvals,
            'commercialPolicy' => $policy,
            'manualDiscountPolicy' => [
                'manual_discounts_enabled' => (bool) $policy->manual_discounts_enabled || (bool) config('autopartes.pos.manual_discounts_enabled_by_default', false),
                'cashier_max_percent' => (float) ($policy->cashier_max_discount_percent > 0 ? $policy->cashier_max_discount_percent : config('autopartes.pos.cashier_max_manual_discount_percent', 0.0)),
                'cashier_max_amount' => (float) ($policy->cashier_max_discount_amount > 0 ? $policy->cashier_max_discount_amount : config('autopartes.pos.cashier_max_manual_discount_amount', 0.0)),
            ],
        ]);
    }

    public function updatePolicy(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $policy = $this->discountService->updateCommercialPolicy($branch, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'policy' => $policy,
            ]);
        }

        return back()->with('success', 'Política comercial y de descuentos de la sucursal actualizada.');
    }

    public function store(Request $request, AutomotiveBranch $branch): JsonResponse|RedirectResponse
    {
        $rule = $this->discountService->createRule($branch, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'rule' => $rule,
            ]);
        }

        return back()->with('success', "Regla de descuento [{$rule->name}] creada.");
    }

    public function update(
        Request $request,
        AutomotiveBranch $branch,
        AutomotiveWholesaleDiscountRule $rule,
    ): JsonResponse|RedirectResponse {
        $updated = $this->discountService->updateRule($branch, $rule, $request->all(), $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'rule' => $updated,
            ]);
        }

        return back()->with('success', "Regla de descuento [{$updated->name}] actualizada.");
    }
}
