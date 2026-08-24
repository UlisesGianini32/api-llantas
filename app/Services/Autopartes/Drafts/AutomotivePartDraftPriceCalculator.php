<?php

namespace App\Services\Autopartes\Drafts;

use App\Models\AutomotivePart;

class AutomotivePartDraftPriceCalculator
{
    public function __construct(private AutomotivePartDraftConfiguration $configuration) {}

    public function calculate(AutomotivePart $part): array
    {
        $rules = $this->configuration->pricingRules();
        $sourceCurrency = strtoupper(trim((string) $part->original_currency));
        $sourcePrice = is_numeric($part->retail_price_original) ? (float) $part->retail_price_original : null;
        $errors = [];
        $priceMxn = null;

        if ($rules['currency'] !== 'MXN') {
            $errors[] = 'unsupported_currency';
        } elseif ($sourceCurrency === 'MXN' && $sourcePrice !== null && $sourcePrice > 0) {
            $priceMxn = $this->applyCommercialRules($sourcePrice, $rules, $errors);
        } elseif ($sourceCurrency === 'USD') {
            if (($rules['usd_mxn_rate'] ?? 0) <= 0) {
                $errors[] = 'missing_exchange_rate';
            } elseif ($sourcePrice !== null && $sourcePrice > 0) {
                $priceMxn = $this->applyCommercialRules($sourcePrice * $rules['usd_mxn_rate'], $rules, $errors);
            }
        } else {
            $errors[] = 'unsupported_currency';
        }

        if ($priceMxn === null || $priceMxn <= 0) {
            $errors[] = 'missing_price_mxn';
            $priceMxn = null;
        }

        return [
            'price_mxn' => $priceMxn,
            'source_price' => $sourcePrice,
            'source_currency' => $sourceCurrency ?: null,
            'rules' => $rules,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function applyCommercialRules(float $baseMxn, array $rules, array &$errors): ?float
    {
        $markup = $rules['price_markup_percent'];
        $fee = $rules['meli_fee_percent'];
        if ($markup === null || $markup < 0 || $fee === null || $fee < 0 || $fee >= 100) {
            $errors[] = 'invalid_price_configuration';

            return null;
        }

        return round(($baseMxn * (1 + ($markup / 100))) / (1 - ($fee / 100)), 2);
    }
}
