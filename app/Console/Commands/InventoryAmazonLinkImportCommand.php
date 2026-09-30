<?php

namespace App\Console\Commands;

use App\Services\Amazon\InventoryAmazonLinkImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InventoryAmazonLinkImportCommand extends Command
{
    protected $signature = 'inventory:amazon-link-import
        {--apply : Importa y crea los vínculos elegibles en inventory_channel_links; por defecto solo muestra preview}
        {--search= : Filtra por término de búsqueda (SKU, ASIN o nombre de producto)}
        {--result= : Filtra por resultado (MATCHED, ALREADY_LINKED, PRODUCT_NOT_FOUND, etc.)}
        {--limit=25 : Cantidad máxima de filas a mostrar en la tabla de muestra}';

    protected $description = 'Previsualiza o importa vínculos entre publicaciones de Amazon y productos de Inventario';

    public function handle(InventoryAmazonLinkImportService $importer): int
    {
        $filters = [
            'search' => (string) ($this->option('search') ?? ''),
            'result' => (string) ($this->option('result') ?? ''),
        ];

        $preview = $importer->preview($filters);

        $this->info('=== Resumen de estados Amazon ===');
        $summary = [];
        foreach ($preview['counts'] as $status => $count) {
            $summary[] = [$status, $count];
        }
        $this->table(['Estado', 'Cantidad'], $summary);

        $limit = max(1, (int) $this->option('limit'));
        $rowsToShow = array_slice($preview['rows'], 0, $limit);

        if (! empty($rowsToShow)) {
            $this->newLine();
            $this->info("=== Muestra de publicaciones Amazon (máximo {$limit} filas) ===");
            $this->table(
                ['Estado', 'Seller SKU', 'ASIN', 'Barcode', 'ID Prod', 'SKU Almacén', 'Título'],
                array_map(fn (array $r): array => [
                    $r['status'],
                    $r['seller_sku'] ?? '—',
                    $r['asin'] ?? '—',
                    $r['barcode'] ?? '—',
                    $r['inventory_product_id'] ?? '—',
                    $r['inventory_product_sku'] ?? '—',
                    Str::limit($r['amazon_title'] ?? '—', 35),
                ], $rowsToShow)
            );
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('<comment>Modo preview (dry-run): no se crearon vínculos.</comment>');
            $this->line('Para importar las coincidencias válidas (MATCHED), ejecuta con <info>--apply</info>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Aplicando importación de vínculos MATCHED para Amazon...');
        $result = $importer->apply($filters);

        $this->info("Importación completada: {$result['imported']} vínculo(s) creado(s).");

        if (! empty($result['errors'])) {
            $this->error('Se encontraron errores en los siguientes vínculos:');
            foreach ($result['errors'] as $err) {
                $this->warn(" - {$err}");
            }
        }

        return self::SUCCESS;
    }
}
