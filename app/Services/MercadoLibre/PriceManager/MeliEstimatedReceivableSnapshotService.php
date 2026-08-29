<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliPriceManagerItem;

class MeliEstimatedReceivableSnapshotService
{
    /** @param array<string, mixed> $simulation
     * @return array{amount: float, price: float, calculated_at: string}|null
     */
    public function storeForCurrentPrice(MeliPriceManagerItem $item, array $simulation): ?array
    {
        $price = $simulation['proposed_price'] ?? null;
        $receivable = $simulation['estimated_receivable'] ?? null;
        $shippingAvailable = data_get($simulation, 'charges.shipping.available') === true;

        if (! is_numeric($price) || ! is_numeric($receivable) || ! $shippingAvailable
            || ! $this->samePrice((float) $item->current_price, (float) $price)) {
            return null;
        }

        $calculatedAt = now();
        $item->forceFill([
            'estimated_receivable' => round((float) $receivable, 2),
            'estimated_receivable_price' => round((float) $price, 2),
            'estimated_receivable_calculated_at' => $calculatedAt,
        ])->save();

        return [
            'amount' => round((float) $receivable, 2),
            'price' => round((float) $price, 2),
            'calculated_at' => $calculatedAt->toISOString(),
        ];
    }

    public function currentAmount(MeliPriceManagerItem $item): ?float
    {
        if (! is_numeric($item->estimated_receivable) || ! is_numeric($item->estimated_receivable_price)
            || ! $this->samePrice((float) $item->current_price, (float) $item->estimated_receivable_price)) {
            return null;
        }

        return round((float) $item->estimated_receivable, 2);
    }

    private function samePrice(float $first, float $second): bool
    {
        return (int) round($first * 100) === (int) round($second * 100);
    }
}
