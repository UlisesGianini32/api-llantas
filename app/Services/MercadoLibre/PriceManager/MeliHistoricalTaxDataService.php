<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\MeliAccountApiClient;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class MeliHistoricalTaxDataService
{
    private const MAX_ORDER_IDS = 60;

    private const CACHE_SECONDS = 21600;

    public function __construct(
        private readonly MeliAccountApiClient $api,
        private readonly MeliTaxDetailsNormalizer $normalizer,
    ) {}

    /** @param list<int|string> $orderIds
     * @return array<string, mixed>
     */
    public function forOrders(MeliAccount $account, array $orderIds): array
    {
        $orderIds = $this->orderIds($orderIds);
        if ($orderIds === []) {
            return $this->normalizer->normalize(['results' => []]);
        }

        if (count($orderIds) > self::MAX_ORDER_IDS) {
            throw new InvalidArgumentException('Mercado Libre permite consultar como máximo 60 orders por solicitud de billing.');
        }

        $cacheKey = 'meli-price-manager:historical-taxes:'.$account->getKey().':'.hash('sha256', implode(',', $orderIds));

        return Cache::remember($cacheKey, self::CACHE_SECONDS, function () use ($account, $orderIds): array {
            $response = $this->api->getReadOnly(
                $account,
                '/billing/integration/group/ML/order/details',
                ['order_ids' => implode(',', $orderIds)],
            );

            return $this->normalizer->normalize($response->json());
        });
    }

    /** @param list<int|string> $orderIds
     * @return list<string>
     */
    private function orderIds(array $orderIds): array
    {
        $normalized = [];
        foreach ($orderIds as $orderId) {
            $orderId = trim((string) $orderId);
            if ($orderId === '' || ! ctype_digit($orderId)) {
                throw new InvalidArgumentException('Los identificadores de order deben ser enteros positivos.');
            }

            $orderId = ltrim($orderId, '0');
            if ($orderId === '') {
                throw new InvalidArgumentException('Los identificadores de order deben ser mayores que cero.');
            }

            $normalized[$orderId] = $orderId;
        }

        return array_values($normalized);
    }
}
