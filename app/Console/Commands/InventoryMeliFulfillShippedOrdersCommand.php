<?php

namespace App\Console\Commands;

use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitReservation;
use App\Models\InventoryReservation;
use App\Models\MeliOrder;
use App\Services\InventoryMeliOrderReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryMeliFulfillShippedOrdersCommand extends Command
{
    protected $signature = 'inventory:meli-fulfill-shipped
        {--account= : Filtra por ID de cuenta MeLi}
        {--order= : Filtra por ID remoto de orden (ej. 2000018746705782)}
        {--dry-run : Muestra qué reservas se cumplirían sin aplicar los cambios}
        {--force : Fuerza el cumplimiento de la orden especificada aunque MeLi aún no marque shipped}
        {--limit=200 : Límite de órdenes a procesar}';

    protected $description = 'Cumple automáticamente las reservas de órdenes de Mercado Libre cuyos paquetes ya han sido despachados o entregados';

    public function handle(InventoryMeliOrderReservationService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $accountFilter = $this->option('account');
        $orderFilter = $this->option('order');
        $limit = (int) $this->option('limit');

        if ($isDryRun) {
            $this->warn('--- MODO PREVISUALIZACIÓN (DRY-RUN): No se aplicarán cambios ---');
        }

        // Buscar órdenes que tienen paquetes enviados/entregados y que tienen reservas o asignaciones activas
        $shippedStatuses = ['shipped', 'delivered', 'in_transit'];

        // Encontrar órdenes con asignaciones activas
        $activeOrderIds = InventoryChannelOrderAllocation::query()
            ->where('channel', 'mercado_libre')
            ->where('status', 'ACTIVE')
            ->pluck('remote_order_id')
            ->unique()
            ->values()
            ->all();

        // Órdenes vinculadas a reservas simples activas con source_type = 'meli_order'
        $activeSourceOrderIds = InventoryReservation::query()
            ->active()
            ->where('source_type', 'meli_order')
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();

        // Órdenes vinculadas a reservas de KIT activas con source_type = 'meli_order'
        $activeKitSourceOrderIds = InventoryKitReservation::query()
            ->active()
            ->where('source_type', 'meli_order')
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();

        $query = MeliOrder::query()
            ->with('items')
            ->when(! $force, function ($q) use ($shippedStatuses) {
                $q->where(function ($nested) use ($shippedStatuses) {
                    $nested->whereIn('shipping_status', $shippedStatuses);
                    if (DB::connection()->getDriverName() === 'mysql') {
                        $nested->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(shipping_raw, '$.status'))) IN ('shipped', 'delivered', 'in_transit')")
                               ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.shipping.status'))) IN ('shipped', 'delivered', 'in_transit')");
                    }
                });
            })
            ->when(empty($orderFilter), function ($q) use ($activeOrderIds, $activeSourceOrderIds, $activeKitSourceOrderIds) {
                $q->where(function ($nested) use ($activeOrderIds, $activeSourceOrderIds, $activeKitSourceOrderIds) {
                    $hasAny = false;
                    if (! empty($activeOrderIds)) {
                        $nested->whereIn('order_id', $activeOrderIds);
                        $hasAny = true;
                    }
                    if (! empty($activeSourceOrderIds)) {
                        $hasAny ? $nested->orWhereIn('id', $activeSourceOrderIds) : $nested->whereIn('id', $activeSourceOrderIds);
                        $hasAny = true;
                    }
                    if (! empty($activeKitSourceOrderIds)) {
                        $hasAny ? $nested->orWhereIn('id', $activeKitSourceOrderIds) : $nested->whereIn('id', $activeKitSourceOrderIds);
                        $hasAny = true;
                    }
                    if (! $hasAny) {
                        $nested->whereRaw('0 = 1');
                    }
                });
            })
            ->when(filled($accountFilter), fn ($q) => $q->where('meli_account_id', (string) $accountFilter))
            ->when(filled($orderFilter), fn ($q) => $q->where('order_id', trim((string) $orderFilter)))
            ->orderByDesc('id')
            ->limit($limit);

        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->info('No se encontraron órdenes con paquetes despachados y reservas activas pendientes.');

            return self::SUCCESS;
        }

        $this->info("Se encontraron {$orders->count()} orden(es) despachada(s) con reservas activas. Procesando...");

        $tableRows = [];
        $totalFulfilled = 0;

        foreach ($orders as $order) {
            // Asegurar que si shipping_status no estaba en la columna pero sí en el raw, se actualice
            $effectiveShipping = $order->shipping_status;
            if ($force) {
                $effectiveShipping = 'shipped';
                if (! $isDryRun && $order->shipping_status !== 'shipped') {
                    $order->update(['shipping_status' => 'shipped']);
                }
            } elseif (! in_array($effectiveShipping, $shippedStatuses, true)) {
                $rawStatus = data_get($order->shipping_raw, 'status') ?? data_get($order->raw, 'shipping.status');
                if (in_array(strtolower(trim((string) $rawStatus)), $shippedStatuses, true)) {
                    $effectiveShipping = strtolower(trim((string) $rawStatus));
                    if (! $isDryRun) {
                        $order->update(['shipping_status' => $effectiveShipping]);
                    }
                }
            }

            if ($isDryRun) {
                $previews = $service->preview($order);
                foreach ($previews as $prev) {
                    $action = $prev['action'] ?? 'UNKNOWN';
                    $isFulfill = in_array($action, ['FULFILL', InventoryMeliOrderReservationService::FULFILL], true);
                    if ($isFulfill) {
                        $totalFulfilled++;
                    }
                    $tableRows[] = [
                        $order->order_id,
                        $order->meli_account_id,
                        $effectiveShipping ?? 'N/A',
                        $prev['sku'] ?? $prev['mlm'] ?? '—',
                        $prev['quantity'] ?? 1,
                        $isFulfill ? '<fg=yellow>SIMULAR CUMPLIMIENTO</>' : "<fg=gray>{$action}</>",
                    ];
                }
            } else {
                $results = $service->apply($order);
                foreach ($results as $res) {
                    $action = $res['action'] ?? 'UNKNOWN';
                    $applied = ! empty($res['applied']);
                    if ($action === InventoryMeliOrderReservationService::FULFILL && $applied) {
                        $totalFulfilled++;
                    }
                    $tableRows[] = [
                        $order->order_id,
                        $order->meli_account_id,
                        $effectiveShipping ?? 'N/A',
                        $res['sku'] ?? $res['mlm'] ?? '—',
                        $res['quantity'] ?? 1,
                        ($action === InventoryMeliOrderReservationService::FULFILL && $applied)
                            ? '<fg=green>✓ CUMPLIDA</>'
                            : "<fg=gray>{$action}</>",
                    ];
                }
            }
        }

        $this->table(
            ['Orden ML', 'Cuenta', 'Estado Envío', 'SKU / MLM', 'Cant.', 'Resultado'],
            $tableRows
        );

        if ($isDryRun) {
            $this->info("Simulación terminada. {$totalFulfilled} reserva(s) calificarían para cumplirse.");
            $this->comment('Para aplicar los cambios, ejecuta el comando sin la opción --dry-run.');
        } else {
            $this->info("Proceso terminado. Se cumplieron {$totalFulfilled} reserva(s) exitosamente y se registraron sus salidas en Movimientos.");
        }

        return self::SUCCESS;
    }
}
