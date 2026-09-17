<?php

namespace App\Console\Commands;

use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliEstimatedReceivableSnapshotService;
use App\Services\MercadoLibre\PriceManager\MeliPriceSimulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

class BackfillMeliEstimatedReceivablesCommand extends Command
{
    protected $signature = 'meli:price-manager-backfill-receivables
        {--account= : ID de cuenta de Mercado Libre}
        {--limit=100 : Máximo de publicaciones por ejecución}
        {--delay-ms=500 : Pausa entre publicaciones para proteger el rate limit}';

    protected $description = 'Calcula snapshots faltantes de RECIBES usando la simulación oficial del Price Manager';

    public function handle(
        MeliPriceSimulationService $simulations,
        MeliEstimatedReceivableSnapshotService $snapshots,
    ): int {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $delayMs = min(10_000, max(0, (int) $this->option('delay-ms')));
        $accountId = $this->option('account');
        $query = MeliPriceManagerItem::query()
            ->focusedCatalog()
            ->with('meliAccount')
            ->when($accountId !== null, fn ($query) => $query->where('meli_account_id', (int) $accountId))
            ->where(function ($query): void {
                $query->whereNull('estimated_receivable')
                    ->orWhereNull('estimated_receivable_price')
                    ->orWhereColumn('current_price', '!=', 'estimated_receivable_price');
            })
            ->orderBy('id')
            ->limit($limit);

        $processed = 0;
        $stored = 0;
        $failed = 0;
        foreach ($query->get() as $item) {
            $processed++;
            try {
                $simulation = $simulations->simulate($item->meliAccount, $item, (float) $item->current_price);
                if ($snapshots->storeForCurrentPrice($item, $simulation) !== null) {
                    $stored++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("{$item->meli_item_id}: no fue posible calcular el snapshot.");
            }

            if ($delayMs > 0 && $processed < $limit) {
                Sleep::for($delayMs)->milliseconds();
            }
        }

        $this->info("Procesadas: {$processed}; guardadas: {$stored}; fallidas o incompletas: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
