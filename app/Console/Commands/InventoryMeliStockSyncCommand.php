<?php

namespace App\Console\Commands;

use App\Services\InventoryMeliStockSyncService;
use Illuminate\Console\Command;

class InventoryMeliStockSyncCommand extends Command
{
    protected $signature = 'inventory:meli-stock-sync
        {--apply : Ejecuta las actualizaciones remotas; por defecto solo muestra el preview}
        {--account= : Filtra por ID de cuenta MeLi}
        {--sku= : Filtra por SKU}
        {--link= : Filtra por ID de vínculo}';

    protected $description = 'Previsualiza o sincroniza stock de Inventory a vínculos Mercado Libre habilitados';

    public function handle(InventoryMeliStockSyncService $sync): int
    {
        $filters = array_filter([
            'account_key' => $this->option('account'),
            'sku' => $this->option('sku'),
            'link' => $this->option('link'),
        ], fn ($value) => $value !== null && $value !== '');
        $preview = $sync->preview($filters);
        $this->table(['SKU', 'MLM', 'Variante', 'Target', 'Estado'], array_map(fn (array $row): array => [
            $row['sku'] ?? '—', $row['external_listing_id'] ?? '—', $row['external_variant_id'] ?? '—', $row['target'], $row['status'],
        ], $preview['rows']));
        $this->line($this->option('apply') ? 'Ejecutando vínculos elegibles...' : 'Dry-run: no se modificó Mercado Libre.');
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $result = $sync->apply($filters);
        $this->info("Sincronizaciones exitosas: {$result['imported']}.");

        return self::SUCCESS;
    }
}
