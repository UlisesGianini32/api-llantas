<?php

namespace App\Console\Commands;

use App\Services\Shopify\InventoryShopifyStockSyncService;
use Illuminate\Console\Command;

class InventoryShopifyStockSyncCommand extends Command
{
    protected $signature = 'inventory:shopify-stock-sync
        {--apply : Ejecuta las actualizaciones remotas; por defecto solo muestra el preview}
        {--sku= : Filtra por SKU}
        {--link= : Filtra por ID de vínculo}
        {--location= : ID de ubicación en Shopify}';

    protected $description = 'Previsualiza o sincroniza stock de Inventory a vínculos de Shopify habilitados';

    public function handle(InventoryShopifyStockSyncService $sync): int
    {
        $filters = array_filter([
            'sku' => $this->option('sku'),
            'link' => $this->option('link'),
        ], fn ($value) => $value !== null && $value !== '');

        $preview = $sync->preview($filters);

        $this->table(
            ['SKU', 'Variante ID', 'Item ID', 'Físico', 'Reservado', 'Disponible', 'Target', 'Sync Habilitado', 'Estado'],
            array_map(fn (array $row): array => [
                $row['sku'] ?? '—',
                $row['external_variant_id'] ?? '—',
                $row['external_product_id'] ?? '—',
                $row['physical'],
                $row['reserved'],
                $row['available'],
                $row['target'],
                $row['stock_sync_enabled'] ? 'SÍ' : 'NO',
                $row['status'].($row['reason'] ? " ({$row['reason']})" : ''),
            ], $preview['rows'])
        );

        $this->line($this->option('apply') ? 'Ejecutando sincronización de stock con Shopify...' : 'Dry-run: no se modificó Shopify.');

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $result = $sync->apply($filters, null, $this->option('location'));
        $this->info("Sincronizaciones exitosas en Shopify: {$result['imported']}.");

        return self::SUCCESS;
    }
}
