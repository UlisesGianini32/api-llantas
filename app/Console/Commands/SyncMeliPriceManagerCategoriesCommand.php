<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\PriceManager\MeliCategorySyncService;
use Illuminate\Console\Command;

class SyncMeliPriceManagerCategoriesCommand extends Command
{
    protected $signature = 'meli-price-manager:sync-categories {--account= : Meli account ID}';

    protected $description = 'Resuelve y persiste categorías usadas por Meli Price Manager';

    public function handle(MeliCategorySyncService $service): int
    {
        $query = MeliAccount::query();
        if ($this->option('account') !== null) {
            $query->whereKey((int) $this->option('account'));
        }

        $total = 0;
        foreach ($query->cursor() as $account) {
            $total += $service->sync($account);
        }

        $this->info("Categorías resueltas: {$total}");

        return self::SUCCESS;
    }
}
