<?php

namespace App\Services\MercadoLibre\PriceManager;

use App\Models\MeliAccount;
use App\Models\MeliAccountTaxProfile;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use UnexpectedValueException;

class MeliSellerTaxSimulationService
{
    public function __construct(private readonly MeliHistoricalTaxRuleService $historicalRules) {}

    /** @return array<string, mixed> */
    public function simulate(MeliAccount $account, float $price): array
    {
        if (! is_finite($price) || $price <= 0) {
            throw new InvalidArgumentException('El precio debe ser mayor que cero para estimar retenciones.');
        }

        $historicalRule = $this->historicalRules->forAccount($account);
        if (($historicalRule['available'] ?? false) === true
            && ($historicalRule['confidence'] ?? null) === 'high') {
            return $this->calculate(
                $price,
                (float) $historicalRule['vat_included_rate'],
                (float) $historicalRule['vat_withholding_rate'],
                (float) $historicalRule['income_tax_withholding_rate'],
                'historical_account_tax_rule',
                'high',
                'Retenciones estimadas según el historial real de Mercado Libre.',
                null,
                $this->ruleSnapshot($historicalRule),
            );
        }

        if (! Schema::hasTable('meli_account_tax_profiles')) {
            return $this->unavailable(
                null,
                null,
                'No hay un perfil fiscal configurado para esta cuenta. El monto recibido puede ser menor al estimado.',
            );
        }

        $profile = $account->taxProfile()->first();
        if ($profile === null) {
            return $this->unavailable(
                null,
                null,
                'No hay un perfil fiscal configurado para esta cuenta. El monto recibido puede ser menor al estimado.',
            );
        }

        $profileSnapshot = $this->profileSnapshot($profile);
        if (! $profile->enabled) {
            return $this->unavailable(
                'account_tax_profile',
                $profileSnapshot,
                'La estimación fiscal está desactivada para esta cuenta.',
            );
        }

        if ($profile->effective_from?->isFuture()) {
            return $this->unavailable(
                'account_tax_profile',
                $profileSnapshot,
                'El perfil fiscal configurado todavía no entra en vigor.',
            );
        }

        return $this->calculate(
            $price,
            $profile->vat_included_rate,
            $profile->vat_withholding_rate,
            $profile->income_tax_withholding_rate,
            'account_tax_profile',
            'configured',
            'Retenciones fiscales estimadas según el perfil configurado para esta cuenta.',
            $profileSnapshot,
            null,
        );
    }

    /** @return array<string, mixed> */
    private function calculate(
        float $price,
        mixed $vatIncluded,
        mixed $vatWithholding,
        mixed $incomeTaxWithholding,
        string $source,
        string $confidence,
        string $message,
        ?array $profile,
        ?array $rule,
    ): array {
        $vatIncludedRate = $this->validRate($vatIncluded, 'IVA incluido');
        $vatWithholdingRate = $this->validRate($vatWithholding, 'IVA retenido');
        $incomeTaxRate = $this->validRate($incomeTaxWithholding, 'ISR retenido');
        $denominator = 1 + ($vatIncludedRate / 100);
        if ($denominator <= 0) {
            throw new UnexpectedValueException('El perfil fiscal produciría una base fiscal inválida.');
        }

        $rawTaxableBase = $price / $denominator;
        $vatAmount = round($rawTaxableBase * ($vatWithholdingRate / 100), 2);
        $incomeTaxAmount = round($rawTaxableBase * ($incomeTaxRate / 100), 2);
        $total = round($vatAmount + $incomeTaxAmount, 2);

        return [
            'available' => true,
            'source' => $source,
            'confidence' => $confidence,
            'stale' => (bool) ($rule['stale'] ?? false),
            'fallback' => $rule['fallback'] ?? null,
            'sample_count' => $rule['sample_count'] ?? null,
            'first_observed_at' => $rule['first_observed_at'] ?? null,
            'last_observed_at' => $rule['last_observed_at'] ?? null,
            'evidence' => $rule['evidence'] ?? null,
            'message' => $message,
            'taxable_base' => round($rawTaxableBase, 2),
            'vat' => [
                'included_rate' => $vatIncludedRate,
                'withholding_rate' => $vatWithholdingRate,
                'amount' => $vatAmount,
            ],
            'income_tax' => [
                'withholding_rate' => $incomeTaxRate,
                'amount' => $incomeTaxAmount,
            ],
            'iva' => $vatAmount,
            'isr' => $incomeTaxAmount,
            'amount' => $total,
            'profile' => $profile,
            'rule' => $rule,
        ];
    }

    private function validRate(mixed $value, string $label): float
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new UnexpectedValueException("El porcentaje de {$label} del perfil fiscal es inválido.");
        }

        return round((float) $value, 4);
    }

    /** @return array<string, mixed> */
    private function profileSnapshot(MeliAccountTaxProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'meli_account_id' => (int) $profile->meli_account_id,
            'enabled' => (bool) $profile->enabled,
            'vat_included_rate' => $profile->vat_included_rate !== null ? (float) $profile->vat_included_rate : null,
            'vat_withholding_rate' => $profile->vat_withholding_rate !== null ? (float) $profile->vat_withholding_rate : null,
            'income_tax_withholding_rate' => $profile->income_tax_withholding_rate !== null ? (float) $profile->income_tax_withholding_rate : null,
            'effective_from' => $profile->effective_from?->toDateString(),
            'notes' => $profile->notes,
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }

    /** @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function ruleSnapshot(array $rule): array
    {
        return [
            'source' => 'historical_account_tax_rule',
            'confidence' => 'high',
            'stale' => (bool) ($rule['stale'] ?? false),
            'fallback' => $rule['fallback'] ?? null,
            'sample_count' => (int) $rule['sample_count'],
            'vat_included_rate' => (float) $rule['vat_included_rate'],
            'vat_withholding_rate' => (float) $rule['vat_withholding_rate'],
            'income_tax_withholding_rate' => (float) $rule['income_tax_withholding_rate'],
            'first_observed_at' => $rule['first_observed_at'] ?? null,
            'last_observed_at' => $rule['last_observed_at'] ?? null,
            'evidence' => is_array($rule['evidence'] ?? null) ? $rule['evidence'] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(?string $source, ?array $profile, string $message): array
    {
        return [
            'available' => false,
            'source' => $source,
            'confidence' => $source === 'account_tax_profile' ? 'configured' : 'insufficient',
            'stale' => false,
            'fallback' => null,
            'sample_count' => null,
            'first_observed_at' => null,
            'last_observed_at' => null,
            'evidence' => null,
            'message' => $message,
            'taxable_base' => null,
            'vat' => [
                'included_rate' => null,
                'withholding_rate' => null,
                'amount' => null,
            ],
            'income_tax' => [
                'withholding_rate' => null,
                'amount' => null,
            ],
            'iva' => null,
            'isr' => null,
            'withholdings' => null,
            'other' => null,
            'amount' => null,
            'profile' => $profile,
            'rule' => null,
        ];
    }
}
