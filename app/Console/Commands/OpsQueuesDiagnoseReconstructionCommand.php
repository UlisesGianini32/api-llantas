<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use Illuminate\Console\Command;

class OpsQueuesDiagnoseReconstructionCommand extends Command
{
    protected $signature = 'ops:queues-diagnose-reconstruction
        {--manifest= : Ruta opcional de un manifiesto dry-run para acotar el diagnóstico}
        {--account= : Filtrar por ID interno de meli_accounts}
        {--cutover= : Fecha ISO-8601 de corte (default INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER)}
        {--chunk=500 : Tamaño de lote de lectura por cursor PK}
        {--connection= : Conexión de base de datos (default config database.default)}
        {--output= : Ruta opcional para guardar el reporte JSON completo}';

    protected $description = 'Diagnóstico exclusivamente de lectura (R2): inventario de pedidos y promociones Beauty afectados antes de descartar jobs';

    public function handle(QueueReconstructionDiagnosticsService $service): int
    {
        try {
            $report = $service->diagnose([
                'manifest_path' => $this->option('manifest') ? (string) $this->option('manifest') : null,
                'account_id' => $this->option('account') !== null && $this->option('account') !== '' ? (int) $this->option('account') : null,
                'cutover' => $this->option('cutover') ? (string) $this->option('cutover') : null,
                'chunk' => (int) ($this->option('chunk') ?? 500),
                'connection' => $this->option('connection') ? (string) $this->option('connection') : null,
            ]);

            $this->info('DIAGNÓSTICO DE RECONSTRUCCIÓN COMPLETADO (100% SOLO LECTURA — sin escrituras ni llamadas a Mercado Libre).');
            $this->table(['Categoría', 'Cantidad', 'Requiere verificación manual/remota'], [
                ['Pedidos cancelados con reservas activas', (string) $report['counts']['cancelled_orders_with_active_reservations'], 'Revisar'],
                ['Pedidos enviados con reservas activas', (string) $report['counts']['shipped_orders_with_active_reservations'], 'Revisar'],
                ['Pedidos pagados pendientes de reserva', (string) $report['counts']['paid_orders_pending_reservation'], 'Revisar'],
                ['Pedidos enviados en caída sin reserva previa', (string) $report['counts']['shipped_during_outage_never_reserved'], 'SÍ (no re-simular)'],
                ['Pedidos con movimientos manuales', (string) $report['counts']['orders_with_manual_movements'], 'SÍ'],
                ['Pedidos multilínea', (string) $report['counts']['multiline_orders'], 'SÍ'],
                ['Kits y componentes', (string) $report['counts']['kits_and_components'], 'SÍ'],
                ['Promociones Beauty activas', (string) $report['counts']['active_beauty_promotions'], 'Revisar'],
                ['Promociones Beauty pendientes de restauración', (string) $report['counts']['beauty_promotions_pending_restore'], 'Prioridad alta'],
                ['Promociones Beauty vencidas', (string) $report['counts']['expired_beauty_promotions'], 'Revisar'],
                ['Estados remotos que requieren verificación', (string) $report['counts']['remote_states_requiring_verification'], 'SÍ (GET remoto)'],
            ]);

            $outputPath = $this->option('output') ? trim((string) $this->option('output')) : '';
            if ($outputPath !== '') {
                $dir = dirname($outputPath);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                file_put_contents($outputPath, $json."\n", LOCK_EX);
                $this->line('Reporte JSON guardado en: '.$outputPath);
            }

            return self::SUCCESS;
        } catch (QueueRecoveryException $e) {
            $this->error("[{$e->reasonCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
