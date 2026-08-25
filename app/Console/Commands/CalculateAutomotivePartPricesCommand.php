<?php

namespace App\Console\Commands;

use App\Jobs\CalculateAutomotivePartPriceJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartPriceRule;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingConfiguration;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceCalculator;
use Illuminate\Console\Command;

class CalculateAutomotivePartPricesCommand extends Command
{
    protected $signature = 'autopartes:prices-calculate {--part-id=} {--rule-id=} {--limit=} {--force} {--dry-run}';
    protected $description = 'Previsualiza o encola cálculos internos de precio MXN para Autopartes';

    public function handle(AutomotivePartPriceCalculator $calculator, AutomotivePartMediaPricingConfiguration $configuration): int
    {
        $limit = $this->integer('limit') ?? $configuration->maxBatch(); $partId = $this->integer('part-id'); $ruleId = $this->integer('rule-id');
        if ($limit === false || $partId === false || $ruleId === false || $limit > $configuration->maxBatch()) {
            $this->error('Los IDs deben ser positivos y el límite no puede superar el máximo configurado.'); return self::FAILURE;
        }
        $rule = $ruleId ? AutomotivePartPriceRule::query()->find($ruleId) : null;
        if ($ruleId && $rule === null) { $this->error('No existe la regla solicitada.'); return self::FAILURE; }
        $parts = AutomotivePart::query()->when($partId, fn ($q) => $q->whereKey($partId))->orderBy('id')->limit($limit)->get();
        $previews = $parts->map(fn ($part) => $calculator->preview($part, $rule));
        if ($this->option('dry-run')) {
            $this->info('Dry-run: no se persistió, no se encoló y no se realizaron solicitudes externas.');
            $this->table(['Autoparte', 'Regla', 'Precio previsto', 'Fingerprint', 'Errores'], $previews->map(fn ($p, $i) => [$parts[$i]->id, $p['rule_id'] ?? '—', $p['price_mxn'] ?? '—', $p['fingerprint'] ?? '—', implode(', ', $p['errors'])]));
            return self::SUCCESS;
        }
        if (! $configuration->enabled()) { $this->error('La Fase 6 está deshabilitada.'); return self::FAILURE; }
        foreach ($previews as $index => $preview) {
            if ($preview['fingerprint'] !== null && $preview['errors'] === []) CalculateAutomotivePartPriceJob::dispatch($parts[$index]->id, $preview['rule_id'], $preview['fingerprint'], (bool) $this->option('force'));
        }
        $this->info('Cálculos encolados localmente; solicitudes externas: 0.');
        return self::SUCCESS;
    }

    private function integer(string $name): int|false|null { $value = $this->option($name); if ($value === null || $value === '') return null; return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : false; }
}
