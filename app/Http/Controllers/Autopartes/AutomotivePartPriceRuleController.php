<?php

namespace App\Http\Controllers\Autopartes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Autopartes\PreviewAutomotivePartPriceRequest;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartPriceRule;
use App\Models\AutomotivePartMeliDraft;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingConfiguration;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceCalculator;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceRuleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomotivePartPriceRuleController extends Controller
{
    public function index(Request $request, AutomotivePartMediaPricingConfiguration $configuration): Response
    {
        return Inertia::render('Autopartes/Precios', ['rules' => AutomotivePartPriceRule::query()->with(['creator:id,name', 'approver:id,name'])->latest('id')->paginate(25),
            'settings' => ['enabled' => $configuration->enabled(), 'scopes' => AutomotivePartPriceRule::SCOPES, 'rounding_modes' => AutomotivePartPriceRule::ROUNDING_MODES]]);
    }
    public function show(AutomotivePartPriceRule $rule): Response
    {
        $rule->load(['creator:id,name', 'approver:id,name', 'events.user:id,name', 'calculations' => fn ($q) => $q->latest('calculated_at')->limit(50)]);
        $affected = AutomotivePartMeliDraft::query()->where('status', '!=', 'stale')
            ->when($rule->scope_type === 'automotive_part', fn ($q) => $q->where('automotive_part_id', (int) $rule->scope_value))
            ->when($rule->scope_type === 'vendor', fn ($q) => $q->whereHas('automotivePart', fn ($part) => $part->whereRaw('LOWER(TRIM(COALESCE(vendor_normalized, vendor))) = ?', [strtolower(trim((string) $rule->scope_value))])))
            ->when($rule->scope_type === 'category', fn ($q) => $q->whereHas('automotivePart', fn ($part) => $part->whereRaw('LOWER(TRIM(category)) = ?', [strtolower(trim((string) $rule->scope_value))])))
            ->count();
        return Inertia::render('Autopartes/PrecioReglaDetalle', ['rule' => $rule, 'affectedDraftsCount' => $affected]);
    }
    public function preview(PreviewAutomotivePartPriceRequest $request, AutomotivePartPriceCalculator $calculator, AutomotivePartPriceRuleResolver $resolver): JsonResponse
    {
        $part = AutomotivePart::query()->findOrFail($request->integer('automotive_part_id'));
        $rule = $request->route('rule'); $rule = $rule instanceof AutomotivePartPriceRule ? $rule : $resolver->resolve($part);
        $preview = $calculator->preview($part, $rule, true); unset($preview['rule']);
        return response()->json($preview);
    }
}
