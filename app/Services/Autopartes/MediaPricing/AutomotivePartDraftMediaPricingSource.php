<?php

namespace App\Services\Autopartes\MediaPricing;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMedia;
use App\Models\AutomotivePartPriceCalculation;
use App\Services\Autopartes\Drafts\AutomotivePartDraftConfiguration;
use App\Services\Autopartes\Drafts\AutomotivePartDraftPriceCalculator;
use Illuminate\Support\Facades\Schema;

class AutomotivePartDraftMediaPricingSource
{
    public function __construct(
        private AutomotivePartMediaPricingConfiguration $configuration,
        private AutomotivePartPriceRuleResolver $rules,
        private AutomotivePartPriceCalculator $calculator,
        private AutomotivePartDraftConfiguration $legacyConfiguration,
        private AutomotivePartDraftPriceCalculator $legacyPrices,
    ) {}

    public function images(AutomotivePart $part): array
    {
        if ($this->configuration->enabled() && Schema::hasTable('automotive_part_media')) {
            $allCount = AutomotivePartMedia::query()->where('automotive_part_id', $part->id)->count();
            $approved = AutomotivePartMedia::query()->where('automotive_part_id', $part->id)
                ->where('status', 'approved')->orderByDesc('is_primary')->orderBy('position')->orderBy('id')->get();
            if ($approved->isNotEmpty() || $allCount > 0) {
                return $approved->map(fn (AutomotivePartMedia $media) => [
                    'media_id' => $media->id,
                    'url' => '/autopartes/medios/archivos/'.$media->id.'/preview',
                    'source' => 'approved_database_media',
                    'sha256' => $media->sha256,
                    'position' => $media->position,
                    'is_primary' => $media->is_primary,
                    'provenance_type' => $media->provenance_type,
                ])->all();
            }
        }
        if (! (bool) config('autopartes_media_pricing.allow_phase5_image_fallback', true)) return [];
        return collect($this->legacyConfiguration->imagesFor($part))->sort()->values()
            ->map(fn (string $url) => ['url' => $url, 'source' => 'phase5_explicit_config_fallback'])->all();
    }

    public function price(AutomotivePart $part): array
    {
        if ($this->configuration->enabled() && Schema::hasTable('automotive_part_price_rules') && Schema::hasTable('automotive_part_price_calculations')) {
            $rule = $this->rules->resolve($part);
            if ($rule !== null) {
                $preview = $this->calculator->preview($part, $rule);
                $calculation = $preview['fingerprint'] === null ? null : AutomotivePartPriceCalculation::query()
                    ->where('automotive_part_id', $part->id)->where('price_rule_id', $rule->id)
                    ->where('fingerprint', $preview['fingerprint'])->where('status', 'valid')->latest('calculated_at')->first();
                if ($calculation === null) {
                    return ['price_mxn' => null, 'source_price' => $preview['source_price'],
                        'source_currency' => $preview['source_currency'], 'rules' => ['currency' => 'MXN'],
                        'rule_id' => $rule->id, 'rule_version' => $rule->version, 'calculation_id' => null,
                        'calculation_fingerprint' => $preview['fingerprint'], 'source' => 'database_missing_valid_calculation',
                        'breakdown' => $preview['breakdown'], 'errors' => array_values(array_unique(array_merge($preview['errors'], ['missing_exchange_rate', 'missing_price_mxn'])))];
                }
                return ['price_mxn' => (float) $calculation->calculated_price_mxn,
                    'source_price' => (float) $calculation->source_price, 'source_currency' => $calculation->source_currency,
                    'rules' => ['currency' => 'MXN', 'usd_mxn_rate' => (float) $rule->usd_mxn_rate,
                        'price_markup_percent' => (float) $rule->markup_percent, 'meli_fee_percent' => (float) $rule->meli_fee_percent],
                    'rule_id' => $rule->id, 'rule_key' => $rule->rule_key, 'rule_version' => $rule->version,
                    'calculation_id' => $calculation->id, 'calculation_fingerprint' => $calculation->fingerprint,
                    'source' => 'database_calculation', 'breakdown' => $calculation->calculation_breakdown, 'errors' => []];
            }
            if ($this->rules->hasAnyCandidate($part)) {
                return ['price_mxn' => null, 'source_price' => is_numeric($part->retail_price_original) ? (float) $part->retail_price_original : null,
                    'source_currency' => $part->original_currency, 'rules' => ['currency' => 'MXN'],
                    'source' => 'database_no_active_rule', 'errors' => ['missing_exchange_rate', 'missing_price_mxn']];
            }
        }
        if (! (bool) config('autopartes_media_pricing.allow_phase5_price_fallback', true)) {
            return ['price_mxn' => null, 'source_price' => is_numeric($part->retail_price_original) ? (float) $part->retail_price_original : null,
                'source_currency' => $part->original_currency, 'rules' => ['currency' => 'MXN'],
                'source' => 'database_no_rule', 'errors' => ['missing_exchange_rate', 'missing_price_mxn']];
        }
        return array_merge($this->legacyPrices->calculate($part), ['source' => 'phase5_explicit_config_fallback']);
    }
}
