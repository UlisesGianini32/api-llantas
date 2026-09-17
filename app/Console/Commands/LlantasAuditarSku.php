<?php

namespace App\Console\Commands;

use App\Models\LlantaSkuCandidate;
use Illuminate\Console\Command;

class LlantasAuditarSku extends Command
{
    protected $signature = 'llantas:auditar-skus {--min=86}';
    protected $description = 'Muestra posibles cambios de SKU detectados durante las importaciones';

    public function handle(): int
    {
        $rows = LlantaSkuCandidate::with('llanta')
            ->where('status', 'pending')
            ->where('score', '>=', (float) $this->option('min'))
            ->orderByDesc('score')
            ->get();

        $this->table(
            ['ID', 'SKU actual', 'SKU nuevo', 'Descripción nueva', 'Score'],
            $rows->map(fn ($row) => [
                $row->id,
                $row->llanta?->sku,
                $row->sku_new,
                mb_strimwidth((string) $row->description_new, 0, 70, '...'),
                number_format($row->score, 2),
            ])->all()
        );

        return self::SUCCESS;
    }
}
