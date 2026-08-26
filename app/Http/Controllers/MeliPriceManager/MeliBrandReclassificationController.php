<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\ApplyMeliBrandReclassificationRequest;
use App\Http\Requests\MeliPriceManager\PreviewMeliBrandReclassificationRequest;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Services\MercadoLibre\PriceManager\MeliBrandClassificationService;
use Illuminate\Http\RedirectResponse;

class MeliBrandReclassificationController extends Controller
{
    public function preview(
        PreviewMeliBrandReclassificationRequest $request,
        MeliBrandClassificationService $service,
    ): RedirectResponse {
        return $this->runPreview($request, $service);
    }

    public function previewBrand(
        PreviewMeliBrandReclassificationRequest $request,
        MeliBrandGroup $brand,
        MeliBrandClassificationService $service,
    ): RedirectResponse {
        return $this->runPreview($request, $service, $brand);
    }

    public function apply(
        ApplyMeliBrandReclassificationRequest $request,
        MeliBrandClassificationService $service,
    ): RedirectResponse {
        $account = MeliAccount::query()->findOrFail($request->integer('meli_account_id'));
        $summary = $service->classifyAccount(
            $account,
            reclassifyAll: $request->boolean('reclassify_all'),
            dryRun: false,
        );

        $request->session()->forget('meli_price_manager_reclassification_preview');

        return back()->with('success', sprintf(
            'Reclasificación aplicada: %d categorizadas, %d sugeridas y %d sin categoría. Manuales e ignoradas se preservaron.',
            $summary['categorized'],
            $summary['suggested'],
            $summary['uncategorized'],
        ));
    }

    private function runPreview(
        PreviewMeliBrandReclassificationRequest $request,
        MeliBrandClassificationService $service,
        ?MeliBrandGroup $brand = null,
    ): RedirectResponse {
        $account = MeliAccount::query()->findOrFail($request->integer('meli_account_id'));
        $reclassifyAll = $request->boolean('reclassify_all');
        $summary = $service->classifyAccount($account, reclassifyAll: $reclassifyAll, dryRun: true);

        $request->session()->put('meli_price_manager_reclassification_preview', [
            'meli_account_id' => (int) $account->id,
            'brand_group_id' => $brand?->id,
            'brand_name' => $brand?->name,
            'reclassify_all' => $reclassifyAll,
            'summary' => $summary,
            'generated_at' => now()->toDateTimeString(),
        ]);

        return back()->with('success', 'Vista previa calculada sin modificar la base de datos.');
    }
}
