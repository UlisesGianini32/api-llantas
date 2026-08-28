<?php

namespace App\Services\Autopartes\MediaPricing;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartPriceRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class AutomotivePartPriceRuleResolver
{
    public function hasAnyCandidate(AutomotivePart $part): bool
    {
        if (! Schema::hasTable('automotive_part_price_rules')) return false;
        $vendor = $this->normalize($part->vendor_normalized ?: $part->vendor);
        $category = $this->normalize($part->category);
        return AutomotivePartPriceRule::query()->where(function ($query) use ($part, $vendor, $category) {
            $query->where(fn ($scope) => $scope->where('scope_type', 'global')->whereNull('scope_value'))
                ->orWhere(fn ($scope) => $scope->where('scope_type', 'automotive_part')->where('scope_value', (string) $part->id));
            if ($vendor !== null) $query->orWhere(fn ($scope) => $scope->where('scope_type', 'vendor')->where('scope_value', $vendor));
            if ($category !== null) $query->orWhere(fn ($scope) => $scope->where('scope_type', 'category')->where('scope_value', $category));
        })->exists();
    }

    public function resolve(AutomotivePart $part, ?CarbonInterface $at = null): ?AutomotivePartPriceRule
    {
        if (! Schema::hasTable('automotive_part_price_rules')) return null;
        $at ??= now();
        $rules = AutomotivePartPriceRule::query()->where('status', 'active')->whereNotNull('approved_at')
            ->where('source_currency', 'USD')->where('target_currency', 'MXN')
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at))
            ->orderByDesc('version')->orderByDesc('id')->get();
        $category = $this->normalize($part->category);
        $vendor = $this->normalize($part->vendor_normalized ?: $part->vendor);
        $matches = [
            'automotive_part' => fn ($rule) => (string) $rule->scope_value === (string) $part->id,
            'vendor' => fn ($rule) => $this->normalize($rule->scope_value) === $vendor && $vendor !== null,
            'category' => fn ($rule) => $this->normalize($rule->scope_value) === $category && $category !== null,
            'global' => fn ($rule) => blank($rule->scope_value),
        ];
        foreach ($matches as $scope => $matchesRule) {
            $applicable = $rules->filter(fn ($rule) => $rule->scope_type === $scope && $matchesRule($rule))->values();
            if ($applicable->count() > 1) {
                throw new AutomotivePartMediaPricingException('Existen reglas activas ambiguas para el mismo scope y periodo.', 'ambiguous_active_rule');
            }
            if ($applicable->isNotEmpty()) return $applicable->first();
        }
        return null;
    }

    public function normalize(mixed $value): ?string
    {
        $value = trim(mb_strtolower((string) $value));
        return $value === '' ? null : $value;
    }
}
