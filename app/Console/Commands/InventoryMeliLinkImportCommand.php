<?php

namespace App\Console\Commands;

use App\Services\InventoryMeliLinkImportService;
use Illuminate\Console\Command;

class InventoryMeliLinkImportCommand extends Command
{
    protected $signature = 'inventory:meli-link-import
        {--apply : Importa y crea los vínculos elegibles en inventory_channel_links; por defecto solo muestra preview}
        {--search= : Filtra por término de búsqueda (SKU, MLM o nombre de producto)}
        {--result= : Filtra por resultado (MATCHED, ALREADY_LINKED, PRODUCT_NOT_FOUND, etc.)}
        {--account= : Filtra por ID de cuenta MeLi}
        {--limit=25 : Cantidad máxima de filas a mostrar en la tabla de muestra}';

    protected $description = 'Previsualiza o importa vínculos entre publicaciones de Mercado Libre y productos de Inventario';

    public function handle(InventoryMeliLinkImportService $importer): int
    {
        $filters = [
            'search' => (string) ($this->option('search') ?? ''),
            'result' => (string) ($this->option('result') ?? ''),
            'account_key' => (string) ($this->option('account') ?? ''),
        ];

        $preview = $importer->preview($filters);

        $this->info('=== Resumen de estados ===');
        $summary = [];
        foreach ($preview['counts'] as $status => $count) {
            $summary[] = [$status, $count];
        }
        $this->table(['Estado', 'Cantidad'], $summary);

        $limit = max(1, (int) $this->option('limit'));
        $rowsToShow = array_slice($preview['rows'], 0, $limit);

        if (! empty($rowsToShow)) {
            $this->newLine();
            $this->info("=== Muestra de publicaciones (máximo {$limit} filas) ===");
            $this->table(
                ['Estado', 'MLM', 'Variante', 'SKU MeLi', 'ID Prod', 'SKU Almacén', 'Nombre Almacén'],
                array_map(fn (array $r): array => [
                    $r['status'],
                    $r['mlm'] ?? '—',
                    $r['variation_id'] ?? '—',
                    $r['sku'] ?? '—',
                    $r['inventory_product_id'] ?? '—',
                    $r['inventory_product_sku'] ?? '—',
                    \Illuminate\Support\Str::limit($r['inventory_product_name'] ?? '—', 35),
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
        $this->line('Aplicando importación de vínculos MATCHED...');
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
