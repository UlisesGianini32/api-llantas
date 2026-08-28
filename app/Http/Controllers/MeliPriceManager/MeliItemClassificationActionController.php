<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\AcceptMeliItemSuggestionRequest;
use App\Http\Requests\MeliPriceManager\AssignMeliItemBrandRequest;
use App\Http\Requests\MeliPriceManager\BulkMeliItemClassificationRequest;
use App\Http\Requests\MeliPriceManager\CreateMeliItemAliasRequest;
use App\Http\Requests\MeliPriceManager\CreateMeliItemBrandRequest;
use App\Http\Requests\MeliPriceManager\IgnoreMeliItemRequest;
use App\Http\Requests\MeliPriceManager\RestoreMeliItemRequest;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliItemClassificationActionService;
use Illuminate\Http\RedirectResponse;

class MeliItemClassificationActionController extends Controller
{
    public function accept(
        AcceptMeliItemSuggestionRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $service->acceptSuggestion($item, (int) $request->user()->id);

        return back()->with('success', 'Sugerencia aceptada. La decisión manual quedó protegida.');
    }

    public function assign(
        AssignMeliItemBrandRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $brand = MeliBrandGroup::query()->findOrFail($request->integer('brand_group_id'));
        $service->assignBrand($item, $brand, (int) $request->user()->id);

        return back()->with('success', "Publicación asignada a {$brand->name}.");
    }

    public function alias(
        CreateMeliItemAliasRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $data = $request->validated();
        $brand = MeliBrandGroup::query()->findOrFail($request->integer('brand_group_id'));
        $result = $service->createAliasAndAssign($item, $brand, [
            'alias' => $data['alias'],
            'normalized_alias' => $data['normalized_alias'],
            'match_type' => $data['match_type'],
            'priority' => $data['priority'],
            'active' => $data['active'],
        ], (int) $request->user()->id);

        $message = $result['created']
            ? "Alias creado y publicación asignada a {$brand->name}."
            : "El alias ya existía; se reutilizó y la publicación fue asignada a {$brand->name}.";

        return back()->with('success', $message.' No se ejecutó una reclasificación masiva.');
    }

    public function brand(
        CreateMeliItemBrandRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $result = $service->createBrandAndAssign($item, $request->validated(), (int) $request->user()->id);

        return back()->with('success', "Marca {$result['brand']->name} creada y publicación asignada. No se reclasificaron otros registros.");
    }

    public function ignore(
        IgnoreMeliItemRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $service->ignore($item, (int) $request->user()->id);

        return back()->with('success', 'Publicación ignorada.');
    }

    public function restore(
        RestoreMeliItemRequest $request,
        MeliPriceManagerItem $item,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $service->restore($item, (int) $request->user()->id);

        return back()->with('success', 'Publicación devuelta a pendientes.');
    }

    public function bulk(
        BulkMeliItemClassificationRequest $request,
        MeliItemClassificationActionService $service,
    ): RedirectResponse {
        $brand = $request->input('action') === 'assign'
            ? MeliBrandGroup::query()->findOrFail($request->integer('brand_group_id'))
            : null;
        $result = $service->bulk(
            $request->integer('meli_account_id'),
            array_map('intval', $request->input('item_ids')),
            (string) $request->input('action'),
            (int) $request->user()->id,
            $brand,
        );

        return back()->with('success', $this->bulkMessage($result['action'], $result['processed'], $brand));
    }

    private function bulkMessage(string $action, int $count, ?MeliBrandGroup $brand): string
    {
        return match ($action) {
            'assign' => "{$count} publicaciones asignadas a {$brand->name}.",
            'accept_suggestions' => "{$count} sugerencias aceptadas.",
            'ignore' => "{$count} publicaciones ignoradas.",
            'restore' => "{$count} publicaciones devueltas a pendientes.",
        };
    }
}
