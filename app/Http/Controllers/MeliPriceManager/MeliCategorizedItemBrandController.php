<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\ReassignMeliItemBrandRequest;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliItemClassificationActionService;
use Illuminate\Http\RedirectResponse;

class MeliCategorizedItemBrandController extends Controller
{
    public function __invoke(
        ReassignMeliItemBrandRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $brand = MeliBrandGroup::query()->findOrFail($request->integer('brand_group_id'));
        if ((int) $item->brand_group_id === (int) $brand->id) {
            return back()->with('info', 'La publicación ya pertenece a la marca seleccionada.');
        }

        $previousBrand = $item->brandGroup?->name ?? 'Sin marca';
        $service->assignBrand($item, $brand, (int) $request->user()->id);

        return back()->with('success', "Marca actualizada: {$previousBrand} → {$brand->name}.");
    }
}
