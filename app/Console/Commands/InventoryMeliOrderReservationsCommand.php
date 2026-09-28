<?php

namespace App\Console\Commands;

use App\Models\MeliOrder;
use App\Services\InventoryMeliOrderReservationService;
use Illuminate\Console\Command;

class InventoryMeliOrderReservationsCommand extends Command
{
    protected $signature = 'inventory:meli-order-reservations
        {--account= : ID local de la cuenta Mercado Libre}
        {--order= : ID remoto de un pedido concreto}
        {--status= : Estado local exacto del pedido}
        {--limit=50 : Máximo de pedidos en preview}
        {--apply : Aplica reservas/release solo para --order}';

    protected $description = 'Previsualiza o aplica reservas Inventory para pedidos Mercado Libre guardados localmente';

    public function handle(InventoryMeliOrderReservationService $service): int
    {
        if ($this->option('apply') && blank($this->option('order'))) {
            $this->error('--apply requiere --order=<REMOTE_ORDER_ID>.');
            return self::FAILURE;
        }
        if (blank($this->option('apply')) && blank($this->option('order')) && ! is_numeric($this->option('limit'))) {
            $this->error('--limit debe ser numérico.');
            return self::FAILURE;
        }
        $query = MeliOrder::query()->with('items')->orderBy('id');
        if (filled($this->option('account'))) $query->where('meli_account_id', (int) $this->option('account'));
        if (filled($this->option('order'))) $query->where('order_id', (string) $this->option('order'));
        if (filled($this->option('status'))) $query->where('status', (string) $this->option('status'));
        if (! $this->option('apply')) $query->limit(max(1, min(500, (int) $this->option('limit'))));
        $orders = $query->get();
        if ($orders->isEmpty()) {
            $this->warn('No se encontraron pedidos locales con esos filtros.');
            return $this->option('apply') ? self::FAILURE : self::SUCCESS;
        }
        if ($this->option('apply') && $orders->count() !== 1) {
            if (! filled($this->option('account'))) {
                $this->error('El order_id coincide con más de una cuenta; especifica --account=<ID>. No se aplicaron cambios.');
            } else {
                $this->error('Los filtros todavía coinciden con más de un pedido local. No se aplicaron cambios.');
            }
            return self::FAILURE;
        }
        $rows = [];
        foreach ($orders as $order) {
            $lines = $this->option('apply') ? $service->apply($order) : $service->preview($order);
            foreach ($lines as $line) {
                $rows[] = [
                    $line['account'] ?? $order->meli_account_id,
                    $line['order_id'] ?? $order->order_id,
                    $line['mlm'] ?? '—', $line['variation'] ?? '—',
                    $line['sku'] ?? '—', $line['quantity'] ?? 0,
                    $line['order_status'] ?? $order->status, $line['action'] ?? 'NO_CHANGE',
                    $line['reservation_id'] ?? '—', $line['allocation_id'] ?? '—',
                    $line['available'] ?? '—',
                ];
            }
        }
        $this->table(['Cuenta', 'Pedido', 'MLM', 'Variación', 'SKU', 'Qty', 'Estado', 'Acción', 'Reserva', 'Allocation', 'Disponible'], $rows);
        $this->line($this->option('apply') ? 'Aplicación terminada; sin llamadas HTTP a Mercado Libre.' : 'Preview solamente; no se modificaron reservas ni allocations.');
        return self::SUCCESS;
    }
}
