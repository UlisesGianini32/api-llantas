<?php

namespace App\Console\Commands;

use App\Services\Llantas\LlantaMergeService;
use Illuminate\Console\Command;

class LlantasMergeKnownSkuChanges extends Command
{
    protected $signature = 'llantas:merge-known-skus {--execute : Ejecuta las fusiones; sin esta opción solo muestra el plan}';
    protected $description = 'Fusiona los cambios de SKU conocidos configurados en config/llantas.php';

    public function handle(LlantaMergeService $service): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute && !$this->confirm('Se modificarán llantas, compuestos y publicaciones. ¿Continuar?')) {
            return self::SUCCESS;
        }

        foreach (config('llantas.known_changes', []) as $oldSku => $newSku) {
            try {
                $result = $service->mergeBySku($oldSku, $newSku, $execute);
                $this->info(($execute ? 'FUSIONADO' : 'PLAN') . ": {$oldSku} -> {$newSku}");
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $this->warn("OMITIDO {$oldSku} -> {$newSku}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
