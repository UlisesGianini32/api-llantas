<?php

namespace App\Services\Autopartes\MediaPricing;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartPriceCalculation;
use App\Models\AutomotivePartPriceRule;
use App\Services\Autopartes\Drafts\AutomotivePartDraftFingerprint;
use Illuminate\Support\Facades\DB;

class AutomotivePartPriceCalculator
{
    public function __construct(
        private AutomotivePartPriceRuleResolver $resolver,
        private AutomotivePartDraftFingerprint $fingerprints,
        private AutomotivePartMediaPricingConfiguration $configuration,
        private AutomotivePartMediaPricingLocalOnlyGuard $guard,
        private AutomotivePartDraftStalenessService $staleness,
    ) {}

    public function preview(AutomotivePart $part, ?AutomotivePartPriceRule $rule = null, bool $allowDraft = false): array
    {
        $this->guard->assert('preview_price');
        $rule ??= $this->resolver->resolve($part);
        $errors = [];
        if ($rule === null || (($rule->status !== 'active' || $rule->approved_at === null) && ! ($allowDraft && $rule->status === 'draft'))) {
            return $this->failure($part, $rule, ['missing_exchange_rate', 'missing_price_mxn']);
        }
        $sourceCurrency = strtoupper(trim((string) $part->original_currency));
        $sourcePrice = is_numeric($part->retail_price_original) ? (float) $part->retail_price_original : null;
        if ($sourceCurrency !== 'USD' || $rule->source_currency !== 'USD' || $rule->target_currency !== 'MXN') $errors[] = 'unsupported_currency';
        if ($sourcePrice === null || $sourcePrice <= 0) $errors[] = 'missing_price_mxn';
        $rate = (float) $rule->usd_mxn_rate; $markup = (float) $rule->markup_percent;
        $fee = (float) $rule->meli_fee_percent; $fixed = (float) $rule->fixed_cost_mxn;
        $increment = (float) $rule->rounding_increment;
        if ($rate <= 0) $errors[] = 'missing_exchange_rate';
        if ($markup < 0 || $markup > $this->configuration->maxMarkup() || $fee < 0
            || $fee >= (float) config('autopartes_media_pricing.max_meli_fee_percent', 95) || $fixed < 0 || $increment <= 0) $errors[] = 'invalid_price_configuration';
        $minimum = $rule->minimum_price_mxn === null ? null : (float) $rule->minimum_price_mxn;
        $maximum = $rule->maximum_price_mxn === null ? null : (float) $rule->maximum_price_mxn;
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) $errors[] = 'invalid_price_configuration';
        if ($errors !== []) return $this->failure($part, $rule, array_values(array_unique(array_merge($errors, ['missing_price_mxn']))));

        $sourceMxn = $sourcePrice * $rate;
        $subtotal = ($sourceMxn * (1 + $markup / 100)) + $fixed;
        $beforeRounding = $subtotal / (1 - $fee / 100);
        $rounded = match ($rule->rounding_mode) {
            'none' => $beforeRounding,
            'up' => ceil($beforeRounding / $increment) * $increment,
            'down' => floor($beforeRounding / $increment) * $increment,
            default => round($beforeRounding / $increment) * $increment,
        };
        $final = $rounded;
        if ($minimum !== null) $final = max($final, $minimum);
        if ($maximum !== null) $final = min($final, $maximum);
        $final = round($final, 2);
        if ($final <= 0) return $this->failure($part, $rule, ['missing_price_mxn']);
        $breakdown = [
            'price_rule_id' => $rule->id, 'price_rule_key' => $rule->rule_key, 'price_rule_version' => $rule->version,
            'source_price_original' => $sourcePrice, 'source_currency' => $sourceCurrency,
            'usd_mxn_rate' => $rate, 'source_price_mxn' => round($sourceMxn, 6),
            'markup_percent' => $markup, 'fixed_cost_mxn' => $fixed, 'subtotal_mxn' => round($subtotal, 6),
            'meli_fee_percent' => $fee, 'sale_price_before_rounding_mxn' => round($beforeRounding, 6),
            'rounding_mode' => $rule->rounding_mode, 'rounding_increment' => $increment,
            'rounded_price_mxn' => round($rounded, 6), 'minimum_price_mxn' => $minimum,
            'maximum_price_mxn' => $maximum, 'final_price_mxn' => $final,
        ];
        $fingerprint = $this->fingerprints->make([
            'automotive_part_id' => $part->id, 'source_price' => $sourcePrice, 'source_currency' => $sourceCurrency,
            'price_rule_id' => $rule->id, 'rule_key' => $rule->rule_key, 'rule_version' => $rule->version,
            'rule_updated_at' => $rule->updated_at?->toJSON(), 'breakdown' => $breakdown,
        ]);
        return ['price_mxn' => $final, 'source_price' => $sourcePrice, 'source_currency' => $sourceCurrency,
            'rule' => $rule, 'rule_id' => $rule->id, 'rule_version' => $rule->version,
            'breakdown' => $breakdown, 'fingerprint' => $fingerprint, 'errors' => []];
    }

    public function calculate(AutomotivePart $part, ?AutomotivePartPriceRule $rule = null, bool $force = false): array
    {
        $this->guard->assert('calculate_price'); $this->configuration->assertEnabled();
        $preview = $this->preview($part, $rule);
        if ($preview['errors'] !== []) return ['calculation' => null, 'created' => false, 'preview' => $preview];
        return DB::transaction(function () use ($part, $preview, $force) {
            $existing = AutomotivePartPriceCalculation::query()->where('automotive_part_id', $part->id)
                ->where('fingerprint', $preview['fingerprint'])->first();
            if ($existing !== null) return ['calculation' => $existing, 'created' => false, 'preview' => $preview];
            AutomotivePartPriceCalculation::query()->where('automotive_part_id', $part->id)->where('status', 'valid')->update(['status' => 'stale']);
            $calculation = AutomotivePartPriceCalculation::query()->create([
                'automotive_part_id' => $part->id, 'price_rule_id' => $preview['rule_id'],
                'source_price' => $preview['source_price'], 'source_currency' => $preview['source_currency'],
                'exchange_rate' => $preview['breakdown']['usd_mxn_rate'], 'calculated_price_mxn' => $preview['price_mxn'],
                'calculation_breakdown' => $preview['breakdown'], 'fingerprint' => $preview['fingerprint'],
                'status' => 'valid', 'calculated_at' => now(),
            ]);
            $this->staleness->markPart($part, 'price_calculation_changed', ['price_calculation_id' => $calculation->id, 'force' => $force]);
            return ['calculation' => $calculation, 'created' => true, 'preview' => $preview];
        });
    }

    private function failure(AutomotivePart $part, ?AutomotivePartPriceRule $rule, array $errors): array
    {
        return ['price_mxn' => null, 'source_price' => is_numeric($part->retail_price_original) ? (float) $part->retail_price_original : null,
            'source_currency' => strtoupper(trim((string) $part->original_currency)) ?: null, 'rule' => $rule,
            'rule_id' => $rule?->id, 'rule_version' => $rule?->version, 'breakdown' => null, 'fingerprint' => null,
            'errors' => array_values(array_unique($errors))];
    }
}
