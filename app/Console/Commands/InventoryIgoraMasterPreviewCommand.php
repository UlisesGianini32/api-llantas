<?php

namespace App\Console\Commands;

use App\Services\Inventory\Igora\IgoraMasterBootstrapPreviewService;
use Illuminate\Console\Command;

/**
 * Comando estrictamente READ-ONLY (PASO 30): previsualiza y valida la propuesta auditada
 * de alta de seis productos maestros SIMPLE de Schwarzkopf Igora Royal (89 unidades físicas).
 *
 * Deliberadamente NO expone opción --apply ni realiza escrituras en base de datos ni llamadas HTTP.
 */
class InventoryIgoraMasterPreviewCommand extends Command
{
    protected $signature = 'inventory:igora-master-preview
        {--json : Imprime la propuesta auditada y validaciones completas en formato JSON}';

    protected $description = 'Vista previa read-only (PASO 30) para validar el alta de seis productos maestros SIMPLE de Schwarzkopf Igora Royal';

    public function handle(IgoraMasterBootstrapPreviewService $service): int
    {
        $report = $service->preview();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $proposal = $report['proposal'];
        $summary = $report['summary'];

        $this->info('=== Vista Previa Read-Only · Alta de Productos Maestros Igora Royal (PASO 30) ===');
        $this->line('Modo: SOLO LECTURA — No crea inventory_products, no inserta movimientos INITIAL, no crea vínculos ML ni llama a APIs externas.');
        $this->line(sprintf(
            'Propuesta: %s | Conteo físico verificado: %s | Referencias: %d | Unidades físicas: %d',
            $proposal['proposal_id'],
            $proposal['physical_count_date'],
            $summary['total_products'],
            $summary['total_physical_units']
        ));
        $this->line(sprintf(
            'Marca/Línea: %s %s | Presentación: %s | Snapshots ML de referencia: %s (antiguos, NO usados como stock físico).',
            $proposal['brand'],
            $proposal['line'],
            $proposal['presentation'],
            $proposal['meli_snapshot_reference_date']
        ));

        $this->newLine();
        $this->info('1) Validación de ubicaciones físicas (4 ubicaciones confirmadas)');
        $this->table(
            ['ID esperado', 'Código esperado', 'ID en BD', 'Código en BD', 'Nombre', 'Activa', 'Estado', 'Detalle'],
            array_map(fn (array $loc): array => [
                $loc['expected_id'],
                $loc['expected_code'],
                $loc['actual_id'] ?? '—',
                $loc['actual_code'] ?? '—',
                $loc['actual_name'] ?: '—',
                $loc['exists'] ? ($loc['is_active'] ? 'Sí' : 'No') : 'No existe',
                $loc['is_valid'] ? 'OK' : 'LOCATION_ERROR',
                $loc['errors'] !== [] ? implode(', ', $loc['errors']) : 'Validada',
            ], $report['locations'])
        );

        $this->newLine();
        $this->info('2) Propuesta de 6 productos maestros SIMPLE (89 unidades físicas contadas)');
        $this->table(
            ['Tono', 'Nombre propuesto', 'Tipo', 'SKU', 'Barcode', 'Presentación', 'Conteo', 'Ubicación', 'ID Loc.', 'MLM ref.', 'Dupl/Id', 'Vínculos', 'INITIAL', 'Resultado', 'Motivos'],
            array_map(function (array $row): array {
                $dups = count($row['exact_code_matches']).'/'.count($row['identity_review_matches']);

                return [
                    $row['tone'],
                    $row['proposed_name'],
                    $row['product_type'],
                    $row['sku'],
                    $row['barcode'],
                    'Tubo 60 ml',
                    $row['physical_count'],
                    $row['location_code'],
                    $row['location_id'],
                    $row['audit_mlm'],
                    $dups,
                    count($row['existing_channel_links']),
                    count($row['existing_initial_movements']),
                    $row['status'],
                    $row['reasons'] !== [] ? implode(', ', $row['reasons']) : '—',
                ];
            }, $report['items'])
        );

        $this->newLine();
        $this->info('3) Resumen de auditoría');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total productos propuestos', $summary['total_products'].' / '.$summary['expected_products']],
                ['Total unidades físicas contadas', $summary['total_physical_units'].' / '.$summary['expected_physical_units']],
                ['Ubicaciones válidas', $summary['locations_valid'].' / '.$summary['locations_expected']],
                ['READY_FOR_MANUAL_APPROVAL', $summary['ready_for_manual_approval']],
                ['DUPLICATE_OR_EXISTING_PRODUCT', $summary['duplicate_or_existing_product']],
                ['LOCATION_ERROR', $summary['location_error']],
                ['MANUAL_REVIEW_REQUIRED', $summary['manual_review_required']],
                ['Apto para aprobación manual (6/6)', $summary['all_ready_for_manual_approval'] ? 'SÍ (requiere PR de escritura separado)' : 'NO (resolver hallazgos)'],
            ]
        );

        $this->newLine();
        $this->warn('Precondiciones obligatorias antes del alta real:');
        foreach ($proposal['mandatory_pre_execution_checks'] as $check) {
            $this->line('  - '.$check);
        }

        return self::SUCCESS;
    }
}
