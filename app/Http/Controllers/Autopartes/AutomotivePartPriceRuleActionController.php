<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\StoreAutomotivePartPriceRuleRequest;
use App\Http\Requests\Autopartes\UpdateAutomotivePartPriceRuleRequest;
use App\Models\AutomotivePartPriceRule;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AutomotivePartPriceRuleActionController extends Controller
{
    public function store(StoreAutomotivePartPriceRuleRequest $request, AutomotivePartPriceRuleService $service): RedirectResponse { $rule = $service->createDraft($request->validated(), $request->user()); return redirect()->route('autopartes.prices.rules.show', $rule)->with('success', 'Regla borrador creada.'); }
    public function update(UpdateAutomotivePartPriceRuleRequest $request, AutomotivePartPriceRule $rule, AutomotivePartPriceRuleService $service): RedirectResponse { $service->updateDraft($rule, $request->validated(), $request->user()); return back()->with('success', 'Regla borrador actualizada.'); }
    public function activate(Request $request, AutomotivePartPriceRule $rule, AutomotivePartPriceRuleService $service): RedirectResponse { $service->activate($rule, $request->user()); return back()->with('success', 'Regla aprobada y activa; no se modificó Mercado Libre.'); }
    public function deactivate(Request $request, AutomotivePartPriceRule $rule, AutomotivePartPriceRuleService $service): RedirectResponse { $service->deactivate($rule, $request->user()); return back()->with('success', 'Regla desactivada.'); }
    public function replace(Request $request, AutomotivePartPriceRule $rule, AutomotivePartPriceRuleService $service): RedirectResponse { $new = $service->replace($rule, $request->user()); return redirect()->route('autopartes.prices.rules.show', $new)->with('success', 'Nueva versión borrador creada.'); }
}
