<?php

namespace App\Services\Autopartes\MediaPricing;

use App\Models\AutomotivePartPriceRule;
use App\Models\AutomotivePartPriceRuleEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomotivePartPriceRuleService
{
    public function __construct(
        private AutomotivePartMediaPricingConfiguration $configuration,
        private AutomotivePartMediaPricingLocalOnlyGuard $guard,
        private AutomotivePartDraftStalenessService $staleness,
        private AutomotivePartPriceRuleResolver $resolver,
    ) {}

    public function createDraft(array $data, User $user): AutomotivePartPriceRule
    {
        $this->mutating('create_price_rule'); $data = $this->validated($data);
        $rule = AutomotivePartPriceRule::query()->create(array_merge($data, [
            'rule_key' => (string) Str::uuid(), 'version' => 1, 'status' => 'draft', 'created_by' => $user->id,
        ]));
        $this->event($rule, 'created', null, 'draft', $user);
        return $rule;
    }

    public function updateDraft(AutomotivePartPriceRule $rule, array $data, User $user): AutomotivePartPriceRule
    {
        $this->mutating('edit_price_rule'); $this->assertDraft($rule);
        $rule->fill($this->validated(array_merge($rule->only($this->editable()), $data)))->save();
        $this->event($rule, 'edited', 'draft', 'draft', $user);
        return $rule->fresh();
    }

    public function replace(AutomotivePartPriceRule $rule, User $user): AutomotivePartPriceRule
    {
        $this->mutating('replace_price_rule');
        if (! in_array($rule->status, ['active', 'inactive'], true)) $this->fail('Solo una regla aprobada puede versionarse.', 'invalid_rule_transition');
        $replacement = AutomotivePartPriceRule::query()->create(array_merge($rule->only($this->editable()), [
            'rule_key' => $rule->rule_key, 'version' => ((int) AutomotivePartPriceRule::query()->where('rule_key', $rule->rule_key)->max('version')) + 1,
            'status' => 'draft', 'created_by' => $user->id, 'approved_by' => null, 'approved_at' => null,
            'metadata' => array_merge($rule->metadata ?? [], ['replaces_rule_id' => $rule->id]),
        ]));
        $this->event($replacement, 'version_created', null, 'draft', $user, ['previous_rule_id' => $rule->id]);
        $this->staleness->markRuleScope($rule, 'price_rule_replacement_created');
        return $replacement;
    }

    public function activate(AutomotivePartPriceRule $rule, User $user): AutomotivePartPriceRule
    {
        $this->mutating('activate_price_rule'); $this->assertDraft($rule); $this->validated($rule->only($this->editable()));
        DB::transaction(function () use ($rule, $user) {
            $locked = AutomotivePartPriceRule::query()->lockForUpdate()->findOrFail($rule->id);
            $ambiguous = AutomotivePartPriceRule::query()->where('status', 'active')->whereNotNull('approved_at')
                ->where('scope_type', $locked->scope_type)->where('scope_value', $locked->scope_value)
                ->where('rule_key', '!=', $locked->rule_key)
                ->where('effective_from', '<=', $locked->effective_until ?? '9999-12-31 23:59:59')
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $locked->effective_from))->exists();
            if ($ambiguous) $this->fail('Existe otra regla activa para el mismo scope y periodo.', 'ambiguous_active_rule');
            $previous = AutomotivePartPriceRule::query()->where('rule_key', $locked->rule_key)->where('status', 'active')->get();
            foreach ($previous as $old) {
                $old->forceFill(['status' => 'superseded'])->save();
                $old->calculations()->where('status', 'valid')->update(['status' => 'stale']);
                $this->event($old, 'superseded', 'active', 'superseded', $user, ['replacement_rule_id' => $locked->id]);
            }
            $locked->forceFill(['status' => 'active', 'approved_by' => $user->id, 'approved_at' => now()])->save();
            $this->event($locked, 'approved_and_activated', 'draft', 'active', $user);
        });
        $active = $rule->fresh(); $this->staleness->markRuleScope($active, 'price_rule_activated');
        return $active;
    }

    public function deactivate(AutomotivePartPriceRule $rule, User $user): AutomotivePartPriceRule
    {
        $this->mutating('deactivate_price_rule');
        if ($rule->status !== 'active') $this->fail('Solo una regla activa puede desactivarse.', 'invalid_rule_transition');
        $rule->forceFill(['status' => 'inactive'])->save();
        $rule->calculations()->where('status', 'valid')->update(['status' => 'stale']);
        $this->event($rule, 'deactivated', 'active', 'inactive', $user);
        $this->staleness->markRuleScope($rule, 'price_rule_deactivated');
        return $rule->fresh();
    }

    private function validated(array $data): array
    {
        $scope = (string) ($data['scope_type'] ?? '');
        if (trim((string) ($data['name'] ?? '')) === '') $this->fail('El nombre es obligatorio.', 'invalid_price_rule');
        if (! in_array($scope, AutomotivePartPriceRule::SCOPES, true)) $this->fail('Scope no permitido.', 'invalid_price_rule');
        $scopeValue = $scope === 'global' ? null : $this->resolver->normalize($data['scope_value'] ?? null);
        if ($scope !== 'global' && $scopeValue === null) $this->fail('El scope requiere un valor.', 'invalid_price_rule');
        $source = strtoupper(trim((string) ($data['source_currency'] ?? 'USD')));
        $target = strtoupper(trim((string) ($data['target_currency'] ?? 'MXN')));
        $rate = (float) ($data['usd_mxn_rate'] ?? 0); $markup = (float) ($data['markup_percent'] ?? 0);
        $fee = (float) ($data['meli_fee_percent'] ?? 0); $fixed = (float) ($data['fixed_cost_mxn'] ?? 0);
        $increment = (float) ($data['rounding_increment'] ?? 0);
        $mode = (string) ($data['rounding_mode'] ?? 'nearest');
        $min = filled($data['minimum_price_mxn'] ?? null) ? (float) $data['minimum_price_mxn'] : null;
        $max = filled($data['maximum_price_mxn'] ?? null) ? (float) $data['maximum_price_mxn'] : null;
        $from = Carbon::parse($data['effective_from'] ?? now());
        $until = filled($data['effective_until'] ?? null) ? Carbon::parse($data['effective_until']) : null;
        if ($source !== 'USD' || $target !== 'MXN' || $rate <= 0 || $markup < 0 || $markup > $this->configuration->maxMarkup()
            || $fee < 0 || $fee >= (float) config('autopartes_media_pricing.max_meli_fee_percent', 95)
            || $fixed < 0 || $increment <= 0 || ! in_array($mode, AutomotivePartPriceRule::ROUNDING_MODES, true)
            || ($min !== null && $min < 0) || ($max !== null && $max <= 0) || ($min !== null && $max !== null && $min > $max)
            || ($until !== null && $until->lt($from))) $this->fail('La configuración de precio no es válida.', 'invalid_price_rule');
        return array_merge($data, ['name' => trim((string) ($data['name'] ?? '')), 'scope_type' => $scope, 'scope_value' => $scopeValue,
            'source_currency' => $source, 'target_currency' => $target, 'usd_mxn_rate' => $rate,
            'markup_percent' => $markup, 'meli_fee_percent' => $fee, 'fixed_cost_mxn' => $fixed,
            'rounding_mode' => $mode, 'rounding_increment' => $increment, 'minimum_price_mxn' => $min,
            'maximum_price_mxn' => $max, 'effective_from' => $from, 'effective_until' => $until]);
    }

    private function editable(): array { return ['name', 'scope_type', 'scope_value', 'source_currency', 'target_currency', 'usd_mxn_rate', 'markup_percent', 'meli_fee_percent', 'fixed_cost_mxn', 'rounding_mode', 'rounding_increment', 'minimum_price_mxn', 'maximum_price_mxn', 'effective_from', 'effective_until', 'notes', 'metadata']; }
    private function assertDraft(AutomotivePartPriceRule $rule): void { if ($rule->status !== 'draft') $this->fail('Una regla activa aprobada es inmutable; cree una nueva versión.', 'active_rule_immutable'); }
    private function mutating(string $operation): void { $this->guard->assert($operation); $this->configuration->assertEnabled(); }
    private function event(AutomotivePartPriceRule $rule, string $action, ?string $from, ?string $to, User $user, array $metadata = []): void { AutomotivePartPriceRuleEvent::query()->create(['automotive_part_price_rule_id' => $rule->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'user_id' => $user->id, 'metadata' => $metadata ?: null, 'created_at' => now()]); }
    private function fail(string $message, string $code): never { throw new AutomotivePartMediaPricingException($message, $code); }
}
