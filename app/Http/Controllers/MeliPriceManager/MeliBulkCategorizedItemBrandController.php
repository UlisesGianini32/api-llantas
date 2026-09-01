<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\BulkReassignMeliItemBrandRequest;
use App\Services\MercadoLibre\PriceManager\MeliCategorizedItemBrandAssignmentService;
use Illuminate\Http\RedirectResponse;

class MeliBulkCategorizedItemBrandController extends Controller
{
    public function __invoke(
        BulkReassignMeliItemBrandRequest $request,
        MeliCategorizedItemBrandAssignmentService $service,
    ): RedirectResponse {
        $result = $service->bulk(
            $request->integer('meli_account_id'),
            array_map('intval', $request->input('item_ids')),
            $request->integer('brand_group_id'),
            (int) $request->user()->id,
        );

        $changedMessage = $result['changed'] === 1
            ? "1 publicación cambió de marca a {$result['brand']->name}."
            : "{$result['changed']} publicaciones cambiaron de marca a {$result['brand']->name}.";
        $unchangedMessage = match ($result['unchanged']) {
            0 => '',
            1 => ' 1 ya estaba asignada.',
            default => " {$result['unchanged']} ya estaban asignadas.",
        };

        return back()->with($result['changed'] > 0 ? 'success' : 'info', $changedMessage.$unchangedMessage);
    }
}
