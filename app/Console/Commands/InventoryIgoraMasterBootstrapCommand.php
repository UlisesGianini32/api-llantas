<?php

namespace App\Console\Commands;

use App\Services\Inventory\Igora\IgoraMasterBootstrapApplyService;
use App\Services\Inventory\Igora\IgoraMasterBootstrapPreviewService;
use Illuminate\Console\Command;

/**
 * Comando controlado (PASO 33) para el alta transaccional e idempotente de los seis productos
 * maestros SIMPLE de Schwarzkopf Igora Royal y sus movimientos INITIAL (89 unidades físicas).
 *
 * - Separado del comando estrictamente read-only `inventory:igora-master-preview`.
 * - Por defecto corre en DRY-RUN sin escrituras.
 * - Con `--apply` exige `--proposal=<PROPOSAL_ID>`, `--reconfirmed-counts-at=<YYYY-MM-DD[ HH:MM:SS]>`
 *   y confirmación explícita (`--confirm` o confirmación interactiva).
 * - Nunca crea vínculos de Mercado Libre, BOM, reservas automáticas ni dispara sincronización.
 */
class InventoryIgoraMasterBootstrapCommand extends Command
{
    protected $signature = 'inventory:igora-master-bootstrap
        {--apply : Ejecuta el alta transaccional (predeterminado: dry-run sin escrituras)}
        {--proposal= : Identificador exacto de la propuesta auditada (obligatorio con --apply)}
        {--confirm : Confirma explícitamente la escritura en modo no interactivo}
        {--reconfirmed-counts-at= : Fecha/hora real (>= 2026-11-19 y no futura) de reconfirmación de conteos físicos frente a pedidos, ventas, apartados, devoluciones y movimientos posteriores}
        {--user-id= : ID opcional de usuario responsable para trazabilidad en created_by}
        {--json : Imprime el reporte completo en formato JSON}';

    protected $description = 'Alta transaccional e idempotente (dry-run por defecto) de 6 productos maestros SIMPLE Igora Royal y sus movimientos INITIAL (PASO 33)';

    public function handle(IgoraMasterBootstrapApplyService $service): int
    {
        $apply = (bool) $this->option('apply');
        $proposalId = $this->option('proposal');
        $reconfirmedCountsAt = $this->option('reconfirmed-counts-at');
        $actorUserId = $this->option('user-id');
        $confirmed = (bool) $this->option('confirm');

        $report = $service->execute([
            'apply' => $apply,
            'proposal_id' => $proposalId !== null ? (string) $proposalId : null,
            'confirmed' => $confirmed,
            'reconfirmed_counts_at' => $reconfirmedCountsAt !== null ? (string) $reconfirmedCountsAt : null,
            'actor_user_id' => $actorUserId !== null && $actorUserId !== '' ? (int) $actorUserId : null,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $this->exitCodeForOutcome((string) $report['outcome']);
        }

        $this->renderHumanReport($report);

        return $this->exitCodeForOutcome((string) $report['outcome']);
    }

    private function exitCodeForOutcome(string $outcome): int
    {
        return in_array($outcome, [
            IgoraMasterBootstrapApplyService::OUTCOME_DRY_RUN_READY,
            IgoraMasterBootstrapApplyService::OUTCOME_DRY_RUN_ALREADY_APPLIED,
            IgoraMasterBootstrapApplyService::OUTCOME_APPLIED,
            IgoraMasterBootstrapApplyService::OUTCOME_ALREADY_APPLIED,
        ], true) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderHumanReport(array $report): void
    {
        $this->info('=== Alta Controlada de Productos Maestros Igora Royal (PASO 33) ===');
        $this->line(sprintf(
            'Modo: %s | Resultado: %s | Propuesta: %s | Fecha de conteo: %s',
            $report['mode'],
            $report['outcome'],
            $report['proposal_id'],
            $report['physical_count_date']
        ));
        $this->line('Política comercial inicial: is_active = false (sin habilitación automática en POS, Compras, Kits, Reservas ni ML Stock Sync).');
        $this->line('Fecha efectiva INITIAL (occurred_at): timestamp real de ejecución (sin inventar hora histórica; conteo físico 2026-11-19 en metadata).');
        $this->line('Rollback auditable: exclusivamente mediante operaciones compensatorias ADJUSTMENT_OUT (append-only, sin DELETE de ledger).');
        $this->line('Restricción de canales: 0 vínculos ML, 0 BOM, 0 reservas automáticas, 0 llamadas HTTP.');

        if (! empty($report['blocking_reasons'])) {
            $this->newLine();
            $this->error('Bloqueos detectados (0 escrituras ejecutadas):');
            foreach ($report['blocking_reasons'] as $reason) {
                $this->line('  - '.$reason);
            }
        }

        if (! empty($report['created_records'])) {
            $this->newLine();
            $this->info(sprintf(
                'Registros creados en transacción: %d productos SIMPLE, %d movimientos INITIAL (%d unidades totales)',
                $report['products_created'],
                $report['movements_created'],
                $report['total_units_written']
            ));
            $this->table(
                ['Tono', 'SKU', 'Product ID', 'is_active', 'Movement ID', 'Cantidad', 'Ubicación', 'External Key'],
                array_map(fn (array $rec): array => [
                    $rec['tone'],
                    $rec['sku'],
                    $rec['inventory_product_id'],
                    $rec['is_active'] ? 'true' : 'false',
                    $rec['inventory_movement_id'],
                    $rec['quantity'],
                    sprintf('%s (#%d)', $rec['location_code'], $rec['inventory_location_id']),
                    $rec['external_key'],
                ], $report['created_records'])
            );
        } elseif (($report['idempotency']['state'] ?? '') === 'COMPLETE') {
            $this->newLine();
            $this->info(sprintf(
                'Idempotencia verificada: los 6 movimientos INITIAL (%d unidades) ya existen con sus external_key únicos. No se realizaron escrituras adicionales.',
                (int) ($report['idempotency']['total_units_recorded'] ?? 0)
            ));
        }

        $gate = $report['apply_gate'] ?? [];
        if (($report['mode'] ?? '') === 'DRY_RUN') {
            $this->newLine();
            $this->comment('Simulación DRY-RUN completada sin escrituras en base de datos.');
            if (! ($gate['gate_passed'] ?? false)) {
                $this->line('Requisitos pendientes para ejecutar con --apply:');
                foreach ($gate['gate_errors'] ?? [] as $err) {
                    $this->line('  - '.$err);
                }
            }
        }
    }
}
