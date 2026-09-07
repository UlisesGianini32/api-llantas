<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\StoreMeliBeautyScheduledDiscountRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBeautyScheduledDiscountRequest;
use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliBrandGroup;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MeliBeautyScheduledDiscountController extends Controller
{
    public function index(Request $request, MeliBeautyScheduledPriceService $service): Response
    {
        $accounts = $request->user()->meliAccounts()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get(['id', 'meli_user_id', 'nickname', 'is_default']);
        $selectedAccount = $this->selectedAccount($request, $accounts);
        $accountId = $selectedAccount?->id;

        $rules = $accountId === null
            ? collect()
            : MeliBeautyScheduledDiscount::query()
                ->where('meli_account_id', $accountId)
                ->with(['brandGroup:id,name,slug', 'meliAccount:id,nickname'])
                ->orderBy('brand_group_id')
                ->get()
                ->map(function (MeliBeautyScheduledDiscount $rule) use ($service): array {
                    $rule->setAttribute('eligible_items_count', $service->eligibleItemsQuery($rule)->count());
                    $rule->setAttribute('window_active', $service->isRuleActiveAt($rule));

                    return $rule->toArray();
                });

        $brandOptions = $accountId === null
            ? collect()
            : MeliBrandGroup::query()
                ->where('active', true)
                ->orderByRaw('LOWER(name)')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'slug'])
                ->map(function (MeliBrandGroup $brand) use ($accountId, $service): ?array {
                    $candidate = new MeliBeautyScheduledDiscount([
                        'meli_account_id' => $accountId,
                        'brand_group_id' => $brand->id,
                    ]);
                    $count = $service->eligibleItemsQuery($candidate)->count();
                    if ($count === 0) {
                        return null;
                    }

                    return [
                        'id' => (int) $brand->id,
                        'name' => (string) $brand->name,
                        'slug' => (string) $brand->slug,
                        'eligible_items_count' => $count,
                    ];
                })
                ->filter()
                ->values();

        return Inertia::render('MeliPriceManager/ScheduledDiscounts', [
            'accounts' => $accounts,
            'selectedAccountId' => $accountId,
            'rules' => $rules,
            'brandOptions' => $brandOptions,
            'defaultTimezone' => config('meli_price_manager.beauty.default_timezone'),
        ]);
    }

    public function store(StoreMeliBeautyScheduledDiscountRequest $request): RedirectResponse
    {
        $rule = MeliBeautyScheduledDiscount::query()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Descuento programado #{$rule->id} creado.");
    }

    public function update(
        UpdateMeliBeautyScheduledDiscountRequest $request,
        MeliBeautyScheduledDiscount $discount,
    ): RedirectResponse {
        $this->assertOwned($request, $discount);
        $discount->forceFill($request->validated())->save();

        return back()->with('success', 'Descuento programado actualizado.');
    }

    public function status(Request $request, MeliBeautyScheduledDiscount $discount): RedirectResponse
    {
        $this->assertOwned($request, $discount);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $discount->forceFill(['active' => $data['active']])->save();

        return back()->with('success', $data['active'] ? 'Descuento activado.' : 'Descuento desactivado de forma segura.');
    }

    private function assertOwned(Request $request, MeliBeautyScheduledDiscount $discount): void
    {
        abort_unless(
            $request->user()->meliAccounts()->whereKey($discount->meli_account_id)->exists(),
            404,
            'La cuenta no pertenece al usuario autenticado.',
        );
    }

    private function selectedAccount(Request $request, $accounts): ?MeliAccount
    {
        if ($request->filled('account')) {
            $account = $accounts->firstWhere('id', $request->integer('account'));
            abort_if($account === null, 404, 'La cuenta de Mercado Libre no pertenece al usuario autenticado.');

            return $account;
        }

        return $accounts->firstWhere('is_default', true) ?? $accounts->first();
    }
}
