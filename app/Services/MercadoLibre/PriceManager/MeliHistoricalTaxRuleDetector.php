<?php

namespace App\Services\MercadoLibre\PriceManager;

use Carbon\CarbonImmutable;
use Throwable;

class MeliHistoricalTaxRuleDetector
{
    private const MINIMUM_ORDERS = 5;

    private const MINIMUM_DISTINCT_ITEMS = 3;

    private const MONEY_TOLERANCE_CENTS = 1;

    /** @var list<float> */
    private const VAT_INCLUDED_RATE_CANDIDATES = [0.0, 8.0, 16.0];

    /** @var list<float> */
    private const WITHHOLDING_RATE_CANDIDATES = [0.5, 1.0, 1.25, 2.0, 2.5, 4.0, 6.0, 8.0, 10.0, 16.0];

    /** @param list<array<string, mixed>> $observations
     * @return array<string, mixed>
     */
    public function detect(int $accountId, array $observations): array
    {
        $valid = $this->validObservations($accountId, $observations);
        $distinctItems = count(array_unique(array_column($valid, 'item_id')));

        if (count($valid) < self::MINIMUM_ORDERS || $distinctItems < self::MINIMUM_DISTINCT_ITEMS) {
            return $this->insufficient(count($valid), $distinctItems, 'La muestra histórica válida es insuficiente.');
        }

        $matchingRules = [];
        foreach (self::VAT_INCLUDED_RATE_CANDIDATES as $vatIncludedRate) {
            foreach (self::WITHHOLDING_RATE_CANDIDATES as $vatWithholdingRate) {
                foreach (self::WITHHOLDING_RATE_CANDIDATES as $incomeTaxWithholdingRate) {
                    if ($this->matchesAll($valid, $vatIncludedRate, $vatWithholdingRate, $incomeTaxWithholdingRate)) {
                        $matchingRules[] = [$vatIncludedRate, $vatWithholdingRate, $incomeTaxWithholdingRate];
                    }
                }
            }
        }

        if (count($matchingRules) !== 1) {
            return $this->insufficient(
                count($valid),
                $distinctItems,
                $matchingRules === []
                    ? 'El historial contiene resultados contradictorios o ninguna regla candidata consistente.'
                    : 'El historial admite más de una regla candidata y no puede resolverse de forma inequívoca.',
            );
        }

        [$vatIncludedRate, $vatWithholdingRate, $incomeTaxWithholdingRate] = $matchingRules[0];
        $observedTimestamps = array_column($valid, 'observed_timestamp');

        return [
            'available' => true,
            'source' => 'historical_account_tax_rule',
            'confidence' => 'high',
            'sample_count' => count($valid),
            'vat_included_rate' => $vatIncludedRate,
            'vat_withholding_rate' => $vatWithholdingRate,
            'income_tax_withholding_rate' => $incomeTaxWithholdingRate,
            'first_observed_at' => CarbonImmutable::createFromTimestamp(min($observedTimestamps))->toISOString(),
            'last_observed_at' => CarbonImmutable::createFromTimestamp(max($observedTimestamps))->toISOString(),
            'evidence' => [
                'distinct_items' => $distinctItems,
                'max_vat_rate_deviation' => $this->maxRateDeviation($valid, $vatIncludedRate, $vatWithholdingRate, 'vat_amount'),
                'max_isr_rate_deviation' => $this->maxRateDeviation($valid, $vatIncludedRate, $incomeTaxWithholdingRate, 'income_tax_amount'),
                'money_tolerance_cents' => self::MONEY_TOLERANCE_CENTS,
                'attribution_scope' => 'single_item_orders',
                'derived_from' => 'historical_mercadolibre_billing',
            ],
        ];
    }

    /** @param list<array<string, mixed>> $observations
     * @return list<array<string, mixed>>
     */
    private function validObservations(int $accountId, array $observations): array
    {
        $valid = [];
        foreach ($observations as $observation) {
            if (! is_array($observation)
                || (int) ($observation['meli_account_id'] ?? 0) !== $accountId
                || ($observation['attribution_scope'] ?? null) !== 'single_item'
                || ($observation['payment_status'] ?? null) !== 'approved'
                || ($observation['refunded'] ?? null) !== false
                || ! is_numeric($observation['gross_sale_amount'] ?? null)
                || ! is_numeric($observation['vat_amount'] ?? null)
                || ! is_numeric($observation['income_tax_amount'] ?? null)
                || (float) $observation['gross_sale_amount'] <= 0
                || (float) $observation['vat_amount'] <= 0
                || (float) $observation['income_tax_amount'] <= 0
                || ! filled($observation['order_id'] ?? null)
                || ! filled($observation['item_id'] ?? null)
                || ! filled($observation['observed_at'] ?? null)) {
                continue;
            }

            try {
                $observedTimestamp = CarbonImmutable::parse((string) ($observation['observed_at'] ?? ''))->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            $orderId = (string) $observation['order_id'];
            $valid[$orderId] = [
                ...$observation,
                'item_id' => (string) $observation['item_id'],
                'gross_sale_amount' => round((float) $observation['gross_sale_amount'], 2),
                'vat_amount' => round((float) $observation['vat_amount'], 2),
                'income_tax_amount' => round((float) $observation['income_tax_amount'], 2),
                'observed_timestamp' => $observedTimestamp,
            ];
        }

        return array_values($valid);
    }

    /** @param list<array<string, mixed>> $observations */
    private function matchesAll(
        array $observations,
        float $vatIncludedRate,
        float $vatWithholdingRate,
        float $incomeTaxWithholdingRate,
    ): bool {
        foreach ($observations as $observation) {
            $base = (float) $observation['gross_sale_amount'] / (1 + ($vatIncludedRate / 100));
            $predictedVat = $base * ($vatWithholdingRate / 100);
            $predictedIncomeTax = $base * ($incomeTaxWithholdingRate / 100);

            if (! $this->moneyMatches($predictedVat, (float) $observation['vat_amount'])
                || ! $this->moneyMatches($predictedIncomeTax, (float) $observation['income_tax_amount'])) {
                return false;
            }
        }

        return true;
    }

    private function moneyMatches(float $predicted, float $observed): bool
    {
        return abs((int) round($predicted * 100) - (int) round($observed * 100)) <= self::MONEY_TOLERANCE_CENTS;
    }

    /** @param list<array<string, mixed>> $observations */
    private function maxRateDeviation(array $observations, float $vatIncludedRate, float $expectedRate, string $amountKey): float
    {
        $deviations = array_map(static function (array $observation) use ($vatIncludedRate, $expectedRate, $amountKey): float {
            $base = (float) $observation['gross_sale_amount'] / (1 + ($vatIncludedRate / 100));
            $observedRate = ((float) $observation[$amountKey] / $base) * 100;

            return abs($observedRate - $expectedRate);
        }, $observations);

        return round(max($deviations), 6);
    }

    /** @return array<string, mixed> */
    private function insufficient(int $sampleCount, int $distinctItems, string $message): array
    {
        return [
            'available' => false,
            'source' => null,
            'confidence' => 'insufficient',
            'sample_count' => $sampleCount,
            'vat_included_rate' => null,
            'vat_withholding_rate' => null,
            'income_tax_withholding_rate' => null,
            'first_observed_at' => null,
            'last_observed_at' => null,
            'evidence' => [
                'distinct_items' => $distinctItems,
                'money_tolerance_cents' => self::MONEY_TOLERANCE_CENTS,
            ],
            'message' => $message,
        ];
    }
}
