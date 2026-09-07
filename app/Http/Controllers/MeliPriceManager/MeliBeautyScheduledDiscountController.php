<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\StoreMeliBeautyScheduledDiscountRequest;
use App\Http\Requests\MeliPriceManager\UpdateMeliBeautyScheduledDiscountRequest;
use App\Models\MeliBeautyScheduledDiscount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeliBeautyScheduledDiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = MeliBeautyScheduledDiscount::query()
            ->whereIn('meli_account_id', $request->user()->meliAccounts()->select('id'))
            ->with(['brandGroup:id,name,slug', 'meliAccount:id,nickname'])
            ->orderBy('meli_account_id')
            ->orderBy('brand_group_id')
            ->get();

        return response()->json(['data' => $rules]);
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
}
