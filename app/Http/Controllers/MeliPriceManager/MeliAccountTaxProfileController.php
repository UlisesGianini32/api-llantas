<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\UpdateMeliAccountTaxProfileRequest;
use Illuminate\Http\RedirectResponse;

class MeliAccountTaxProfileController extends Controller
{
    public function update(UpdateMeliAccountTaxProfileRequest $request): RedirectResponse
    {
        $account = $request->user()->meliAccounts()
            ->whereKey($request->integer('meli_account_id'))
            ->firstOrFail();
        $validated = $request->validated();

        $account->taxProfile()->updateOrCreate([], [
            'enabled' => $request->boolean('enabled'),
            'vat_included_rate' => $validated['vat_included_rate'] ?? null,
            'vat_withholding_rate' => $validated['vat_withholding_rate'] ?? null,
            'income_tax_withholding_rate' => $validated['income_tax_withholding_rate'] ?? null,
            'effective_from' => $validated['effective_from'] ?? null,
            'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
        ]);

        return back()->with('success', 'Perfil fiscal de la cuenta actualizado.');
    }
}
