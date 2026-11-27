<?php

namespace App\Console\Commands;

use App\Services\InventoryMeliOrderAdministrativeReevaluationService;
use Illuminate\Console\Command;
use Throwable;

class OpsInventoryMeliOrdersReevaluateCommand extends Command
{
    protected $signature = 'ops:inventory-meli-orders-reevaluate
        {--dry-run : Diagnostic preview only (default)}
        {--apply : Enqueue bounded force-reconcile jobs only for safe open paid candidates}
        {--link-id= : Scope to orders referencing a specific InventoryChannelLink ID}
        {--product-id= : Scope to orders referencing a specific InventoryProduct ID (includes dependent kits)}
        {--kit-id= : Scope to orders referencing a specific Kit product ID}
        {--order-id= : Scope to a single local meli_orders.id}
        {--since= : Lower UTC/ISO date bound (defaults to automatic_after cutover)}
        {--limit=100 : Maximum orders to evaluate (1..500)}
        {--json : Emit machine-readable JSON output}';

    protected $description = 'Bounded, diagnostic-first administrative re-evaluation of open post-cutover ML orders (never auto-reconstructs ambiguous orders).';

    public function handle(InventoryMeliOrderAdministrativeReevaluationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun && $apply) {
            $this->error('Las opciones --dry-run y --apply son mutuamente excluyentes.');

            return self::FAILURE;
        }

        try {
            $report = $service->evaluate([
                'link_id' => $this->option('link-id') !== null ? (int) $this->option('link-id') : null,
                'product_id' => $this->option('product-id') !== null ? (int) $this->option('product-id') : null,
                'kit_id' => $this->option('kit-id') !== null ? (int) $this->option('kit-id') : null,
                'order_id' => $this->option('order-id') !== null ? (int) $this->option('order-id') : null,
                'since' => $this->option('since') !== null ? (string) $this->option('since') : null,
                'limit' => (int) $this->option('limit'),
            ], apply: $apply);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Modo: {$report['mode']} | Escaneadas: {$report['scanned_orders']} | Elegibles seguras: ".count($report['eligible_safe_candidates']).' | Ambiguas omitidas: '.count($report['skipped_ambiguous_requires_human_review'])." | Despachadas: {$report['dispatched_count']}");

        return self::SUCCESS;
    }
}
