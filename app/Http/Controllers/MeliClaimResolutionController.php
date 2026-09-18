<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\MeliClaim;
use App\Services\MercadoLibre\Claims\MeliClaimActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeliClaimResolutionController extends Controller
{
    public function refund(Request $request, MeliClaim $claim, MeliClaimActionService $actions): RedirectResponse
    {
        $this->accountFor($request, $claim);
        $request->validate(['confirmed' => ['required', 'accepted']]);

        return $this->resolve($request, $claim, 'refund', $actions);
    }

    public function allowReturn(Request $request, MeliClaim $claim, MeliClaimActionService $actions): RedirectResponse
    {
        $this->accountFor($request, $claim);
        $request->validate(['confirmed' => ['required', 'accepted']]);

        return $this->resolve($request, $claim, 'allow_return', $actions);
    }

    public function partialOffers(Request $request, MeliClaim $claim, MeliClaimActionService $actions): JsonResponse
    {
        $this->accountFor($request, $claim);
        $prepared = $actions->prepare($request->user(), $claim, 'partial_refund');
        abort_unless($prepared['ok'], 409, $prepared['message']);

        return response()->json(['offers' => $prepared['offers']]);
    }

    public function partialRefund(Request $request, MeliClaim $claim, MeliClaimActionService $actions): RedirectResponse
    {
        $this->accountFor($request, $claim);
        $validated = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'percentage' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'amount' => ['prohibited'],
            'currency_id' => ['prohibited'],
        ]);

        return $this->resolve($request, $claim, 'partial_refund', $actions, (float) $validated['percentage']);
    }

    private function resolve(Request $request, MeliClaim $claim, string $action, MeliClaimActionService $actions, ?float $percentage = null): RedirectResponse
    {
        $result = $actions->execute($request->user(), $claim, $action, $percentage);
        if (! $result['ok']) {
            return back()->with('err', $result['message']);
        }
        if ($result['refresh_failed']) {
            return redirect()->route('meli.claims.show', $claim)->with('err', $result['message']);
        }

        return redirect()->route('meli.claims.show', $claim)->with('ok', $result['message']);
    }

    private function accountFor(Request $request, MeliClaim $claim): MeliAccount
    {
        return $request->user()->meliAccounts()->findOrFail($claim->meli_account_id);
    }
}
