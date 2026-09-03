<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\UpdateMeliItemPriceRequest;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateException;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateService;
use Illuminate\Http\JsonResponse;

class MeliPriceUpdateController extends Controller
{
    public function __invoke(
        UpdateMeliItemPriceRequest $request,
        int $item,
        MeliPriceUpdateService $service,
    ): JsonResponse {
        $publication = MeliPriceManagerItem::query()
            ->focusedCatalog()
            ->whereKey($item)
            ->firstOrFail();
        $account = $request->user()
            ->meliAccounts()
            ->whereKey($publication->meli_account_id)
            ->firstOrFail();
        $data = $request->validated();

        try {
            $result = $service->update(
                (int) $request->user()->id,
                $account,
                $publication,
                $data['simulation_token'],
                (float) $data['price'],
                (string) $data['listing_type_id'],
            );
        } catch (MeliPriceUpdateException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
            ], $exception->httpStatus());
        }

        return response()->json([
            'message' => ($result['no_op'] ?? false)
                ? 'No hay cambios por aplicar.'
                : 'Cambios actualizados correctamente en Mercado Libre.',
            'data' => $result,
        ]);
    }
}
