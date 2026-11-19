<?php

namespace App\Console\Commands;

use App\Services\Inventory\Igora\IgoraGroupingAssistantService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InventoryIgoraAuditCommand extends Command
{
    protected $signature = 'inventory:igora-audit
        {--account= : Filtra por ID de cuenta Mercado Libre}
        {--line= : Filtra por línea (IGORA_ROYAL, IGORA_VIBRANCE, etc.)}
        {--tone= : Filtra por tono normalizado (ej. 0-22, 7-77, 9.5-1)}
        {--presentation= : Filtra por presentación (SINGLE_DYE, DYE_WITH_PEROXIDE, MULTIPACK, STANDALONE_PEROXIDE, UNKNOWN_PRESENTATION)}
        {--status= : Filtra por estado remoto en Mercado Libre (active, paused, under_review, etc.)}
        {--search= : Búsqueda por SKU, MLM, variación o título}
        {--ambiguous : Muestra únicamente filas ambiguas, con conflictos o revisión humana}
        {--attributes-summary : Muestra el resumen read-only de combinaciones de atributos estructurados (IS_KIT, INCLUDES_ACTIVATOR, KIT_CONTENT, OXIDANT_VOLUMES)}
        {--limit=100 : Máximo de filas a imprimir en tabla}
        {--json : Imprime la salida completa en JSON}';

    protected $description = 'Auditoría read-only y vista previa del agrupador automático Schwarzkopf Igora Royal sobre Inventory*';

    public function handle(IgoraGroupingAssistantService $assistant): int
    {
        $filters = [
            'account_key' => (string) ($this->option('account') ?? ''),
            'line' => (string) ($this->option('line') ?? ''),
            'tone' => (string) ($this->option('tone') ?? ''),
            'presentation' => (string) ($this->option('presentation') ?? ''),
            'status' => (string) ($this->option('status') ?? ''),
            'search' => (string) ($this->option('search') ?? ''),
            'ambiguous_only' => (bool) $this->option('ambiguous'),
        ];

        $preview = $assistant->preview($filters);

        if ($this->option('json')) {
            $this->line(json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $counts = $preview['counts'];
        $this->info('=== Auditoría Read-Only · Agrupador Igora Royal (Vista Previa) ===');
        $this->line('Modo: SOLO LECTURA (no modifica existencias, no crea vínculos, no llama a Mercado Libre).');
        $this->table(
            ['Métrica', 'Total'],
            [
                ['Filas / Variaciones descubiertas', $counts['total_rows']],
                ['Grupos propuestos', $counts['total_groups']],
                ['Igora Royal', $counts['royal_rows']],
                ['Otras líneas / Ambiguas', $counts['other_line_rows']],
                ['Tinte individual', $counts['single_dye_rows']],
                ['Tinte con peróxido', $counts['dye_with_peroxide_rows']],
                ['Multipack', $counts['multipack_rows']],
                ['Peróxido independiente', $counts['standalone_peroxide_rows']],
                ['Presentación desconocida', $counts['unknown_presentation_rows']],
                ['Ambiguos / Conflictos / Revisión', $counts['ambiguous_or_conflicted_rows']],
                ['FULL (controlados por ML)', $counts['full_rows']],
                ['Pausadas / Moderadas', $counts['paused_rows'].' / '.$counts['moderated_rows']],
            ]
        );

        if ($this->option('attributes-summary')) {
            $combos = $preview['attribute_combinations'] ?? [];
            $this->newLine();
            $this->info('=== Combinaciones de Atributos Estructurados (Read-Only) ===');

            if ($combos === []) {
                $this->warn('Sin combinaciones de atributos para los filtros aplicados.');
            } else {
                $this->table(
                    ['IS_KIT', 'INCLUDES_ACTIVATOR', 'KIT_CONTENT', 'OXIDANT_VOLUMES', 'UNITS_PER_PACK', 'Presentación', 'Filas', 'Conflictos', 'Ejemplos MLM'],
                    array_map(fn (array $combo): array => [
                        $combo['is_kit'],
                        $combo['includes_activator'],
                        Str::limit((string) $combo['kit_content'], 32),
                        (string) $combo['oxidant_volumes'],
                        (string) $combo['units_per_pack'],
                        (string) $combo['presentation_type'],
                        (int) $combo['count'],
                        implode(',', $combo['conflicts']) ?: '—',
                        implode(',', $combo['sample_mlms']) ?: '—',
                    ], $combos)
                );
            }
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $rows = array_slice($preview['rows'], 0, $limit);

        if ($rows === []) {
            $this->warn('No se encontraron publicaciones Igora con los filtros aplicados.');

            return self::SUCCESS;
        }

        $this->table(
            ['Línea', 'Tono', 'SKU', 'Presentación', 'Cuenta', 'MLM / Var', 'User Product', 'Local (F/D)', 'ML (Pub/PM)', 'Estado', 'Confianza', 'Conflictos', 'Acción'],
            array_map(function (array $row): array {
                $mlmVar = $row['mlm'].($row['variation_id'] ? ':'.$row['variation_id'] : '');
                $local = $row['local_physical_stock'] !== null
                    ? $row['local_physical_stock'].'/'.$row['local_available_stock']
                    : 'N/A';
                $remote = ($row['remote_publication_stock'] ?? '—').'/'.($row['remote_price_manager_stock'] ?? '—');

                return [
                    $row['line_label'],
                    $row['normalized_tone'] ?? '—',
                    $row['sku'] !== '' ? Str::limit($row['sku'], 18) : '—',
                    $row['presentation_label'],
                    $row['account_name'],
                    $mlmVar,
                    $row['user_product_id'] ?? '—',
                    $local,
                    $remote,
                    $row['status'],
                    $row['confidence'],
                    implode(',', $row['conflicts']) ?: '—',
                    $row['recommended_action'],
                ];
            }, $rows)
        );

        if (count($preview['rows']) > $limit) {
            $this->comment(sprintf('Mostrando %d de %d filas. Usa --limit=%d para ver más.', $limit, count($preview['rows']), count($preview['rows'])));
        }

        return self::SUCCESS;
    }
}
