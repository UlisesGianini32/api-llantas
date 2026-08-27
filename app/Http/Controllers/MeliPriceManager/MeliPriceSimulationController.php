<?php

namespace App\Http\Controllers\MeliPriceManager;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeliPriceManager\SimulateMeliItemPriceRequest;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\MeliApiRequestException;
use App\Services\MercadoLibre\PriceManager\MeliPriceSimulationService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use UnexpectedValueException;

class MeliPriceSimulationController extends Controller
{
    public function __invoke(
        SimulateMeliItemPriceRequest $request,
        int $item,
        MeliPriceSimulationService $service,
    ): JsonResponse {
        $publication = MeliPriceManagerItem::query()
            ->managedCatalog()
            ->whereKey($item)
            ->firstOrFail();
        $account = $request->user()
            ->meliAccounts()
            ->whereKey($publication->meli_account_id)
            ->firstOrFail();

        try {
            $simulation = $service->simulate($account, $publication, (float) $request->validated('price'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (MeliApiRequestException|UnexpectedValueException) {
            return response()->json([
                'message' => 'No fue posible calcular los cargos con Mercado Libre. Intenta nuevamente.',
            ], 502);
        }

        return response()->json(['data' => $simulation]);
    }
}
