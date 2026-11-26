<?php

namespace App\Console\Commands;

use App\Services\InventoryMeliOrderReconciliationPreflight;
use Illuminate\Console\Command;

class OpsInventoryMeliReconciliationsPreflightCommand extends Command
{
    protected $signature = 'ops:inventory-meli-reconciliations-preflight {--json : Emit machine-readable JSON output}';

    protected $description = 'Fail-closed read-only preflight verifying database queue, InnoDB, and temporal bounds for ML order reservations.';

    public function handle(InventoryMeliOrderReconciliationPreflight $preflight): int
    {
        $report = $preflight->check();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['passed'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report['checks'] as $check) {
            $prefix = $check['passed'] ? '[PASS]' : '[FAIL]';
            $line = "{$prefix} {$check['name']}: {$check['message']}";
            if ($check['passed']) {
                $this->info($line);
            } else {
                $this->error($line);
            }
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
