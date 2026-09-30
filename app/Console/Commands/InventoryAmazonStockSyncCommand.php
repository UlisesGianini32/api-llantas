<?php

namespace App\Console\Commands;

use App\Services\Amazon\InventoryAmazonStockSyncService;
use Illuminate\Console\Command;

class InventoryAmazonStockSyncCommand extends Command
{
    protected $signature = 'inventory:amazon-stock-sync
        {--apply : Ejecuta las actualizaciones remotas en Amazon; por defecto solo muestra preview}
        {--sku= : Filtra por SKU}
        {--link= : Filtra por ID de vínculo}';

    protected $description = 'Previsualiza o sincroniza stock de Inventory a publicaciones Amazon (FBM)';

    public function handle(InventoryAmazonStockSyncService $sync): int
    {
        $filters = array_filter([
            'sku' => $this->option('sku'),
            'link' => $this->option('link'),
        ], fn ($value) => $value !== null && $value !== '');

        $preview = $sync->preview($filters);

        $this->table(
            ['SKU', 'Seller SKU', 'ASIN', 'Físico', 'Reservado', 'Disponible', 'Target', 'Sync Habilitado', 'Estado'],
            array_map(fn (array $row): array => [
                $row['sku'] ?? '—',
                $row['seller_sku'] ?? '—',
                $row['external_product_id'] ?? '—',
                $row['physical'],
                $row['reserved'],
                $row['available'],
                $row['target'],
                $row['stock_sync_enabled'] ? 'SÍ' : 'NO',
                $row['status'].($row['reason'] ? " ({$row['reason']})" : ''),
            ], $preview['rows'])
        );

        $this->line($this->option('apply') ? 'Ejecutando sincronización de stock con Amazon...' : 'Dry-run: no se modificó Amazon.');

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $result = $sync->apply($filters);
        $this->info("Sincronizaciones exitosas en Amazon: {$result['imported']}.");

        return self::SUCCESS;
    }
}
