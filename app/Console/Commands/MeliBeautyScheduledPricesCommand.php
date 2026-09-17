<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMeliBeautyScheduledPriceJob;
use App\Models\MeliBeautyScheduledDiscount;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Illuminate\Console\Command;

class MeliBeautyScheduledPricesCommand extends Command
{
    protected $signature = 'meli:beauty-scheduled-prices
        {--dry-run : Simular sin escrituras remotas ni cambios locales}
        {--apply : Encolar cambios reales explícitamente}
        {--account= : ID interno de meli_accounts}
        {--discount= : ID de regla}
        {--item= : MLM específico}
        {--all : Autorizar explícitamente el procesamiento global}';

    protected $description = 'Evalúa descuentos programados Beauty de Meli Price Manager';

    public function handle(MeliBeautyScheduledPriceService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Usa --dry-run o --apply, no ambos.');

            return self::INVALID;
        }
        if ($apply && ! config('meli_price_manager.beauty_scheduled_prices.enabled', false)) {
            $this->error('La automatización está deshabilitada por MELI_BEAUTY_SCHEDULED_PRICES_ENABLED.');

            return self::FAILURE;
        }
        if ($apply && ! config('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false)) {
            $this->error('Las promociones están deshabilitadas por MELI_BEAUTY_PROMOTIONAL_PRICES_ENABLED.');

            return self::FAILURE;
        }
        if ($apply
            && ! $this->option('all')
            && ! $this->option('item')
            && ! $this->option('discount')
            && ! $this->option('account')) {
            $this->error('--apply requiere --item, --discount, --account o la intención global explícita --all.');

            return self::INVALID;
        }

        $rules = MeliBeautyScheduledDiscount::query()->with('meliAccount')
            ->when($this->option('account'), fn ($query, $account) => $query->where('meli_account_id', (int) $account))
            ->when($this->option('discount'), fn ($query, $discount) => $query->whereKey((int) $discount))
            ->get();
        if ($rules->isEmpty()) {
            $this->warn('No se encontraron reglas.');

            return self::SUCCESS;
        }

        foreach ($rules as $rule) {
            if ($apply) {
                ProcessMeliBeautyScheduledPriceJob::dispatch($rule->id, $this->option('item'));
                $this->info("Regla #{$rule->id} encolada en meli.");

                continue;
            }

            $summary = $service->processRule($rule, $this->option('item'), true);
            $this->line(sprintf(
                'Cuenta %d · %s · APPLY:%d NO_CHANGE:%d RESTORE:%d REBASE:%d BLOCKED:%d ERROR:%d',
                $rule->meli_account_id,
                $rule->brandGroup?->name ?? 'Marca',
                $summary['apply'], $summary['no_change'], $summary['restore'], $summary['rebase'], $summary['blocked'], $summary['failed'],
            ));
            if ($this->option('verbose')) {
                foreach ($summary['details'] as $detail) {
                    $this->line(sprintf(
                        '%s brand=%s standard_base=%s promotion_original=%s discount=%s%% target=%s allowed=%s..%s suggested=%s strategy=%s action=%s reason=%s',
                        $detail['meli_item_id'], $detail['brand'],
                        $detail['standard_base'] === null ? 'n/a' : number_format($detail['standard_base'], 2, '.', ''),
                        $detail['promotion_original'] === null ? 'n/a' : number_format($detail['promotion_original'], 2, '.', ''),
                        number_format($detail['discount'], 2, '.', ''),
                        $detail['target'] === null ? 'n/a' : number_format($detail['target'], 2, '.', ''),
                        $detail['allowed_min'] === null ? 'n/a' : number_format($detail['allowed_min'], 2, '.', ''),
                        $detail['allowed_max'] === null ? 'n/a' : number_format($detail['allowed_max'], 2, '.', ''),
                        $detail['suggested'] === null ? 'n/a' : number_format($detail['suggested'], 2, '.', ''),
                        $detail['strategy'],
                        $detail['action'],
                        $detail['reason'] === '' ? 'none' : $detail['reason'],
                    ));
                }
                foreach ($summary['errors'] as $error) {
                    $label = ($error['status'] ?? 'failed') === 'blocked' ? 'BLOCKED' : 'ERROR';
                    $this->warn(($error['meli_item_id'] ?? 'item').' '.$label.' '.$error['message']);
                }
            }
        }

        return self::SUCCESS;
    }
}
