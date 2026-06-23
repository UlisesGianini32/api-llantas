<?php

namespace App\Console\Commands;

use App\Models\MeliOrder;
use App\Models\User;
use App\Services\SyscomOrderFromMeliService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyscomCancelOrdersFromMeliCommand extends Command
{
    protected $signature = 'syscom:cancel-orders-from-ml
                            {--user_id= : Usuario ML (default: primero con access_token)}
                            {--max=50 : Máximo de órdenes a procesar}
                            {--order_id= : Solo esta orden ML (opcional)}';

    protected $description = 'Cancela pedidos SYSCOM cuando la orden ML está cancelada y ya tiene folio';

    public function handle(SyscomOrderFromMeliService $service): int
    {
        $userId = (int) ($this->option('user_id') ?: 0);
        $max = max(1, (int) ($this->option('max') ?: 50));
        $onlyOrderId = trim((string) ($this->option('order_id') ?: ''));

        $user = $userId > 0
            ? User::query()->find($userId)
            : User::query()->whereNotNull('access_token')->first();

        if (! $user || ! $user->access_token) {
            $this->error('No se encontró usuario con access_token.');

            return self::FAILURE;
        }

        $cancelledStatuses = ['cancelled', 'canceled', 'invalid', 'expired'];

        $query = MeliOrder::query()
            ->whereNotNull('syscom_order_folio')
            ->where('syscom_order_folio', '!=', '')
            ->whereNull('syscom_order_cancelled_at')
            ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), $cancelledStatuses)
            ->orderByDesc('updated_at');

        if ($onlyOrderId !== '') {
            $query->where('order_id', $onlyOrderId);
        } else {
            $query->limit($max);
        }

        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->line('No hay órdenes ML canceladas con folio SYSCOM pendientes de cancelar.');

            return self::SUCCESS;
        }

        $ok = 0;
        $err = 0;
        foreach ($orders as $order) {
            $service->cancelIfEligible($user, $order);
            $order->refresh();

            if ($order->syscom_order_cancelled_at) {
                $ok++;
                $this->line("OK  ML {$order->order_id} cancelado en SYSCOM ({$order->syscom_order_folio})");
            } else {
                $err++;
                $this->warn("ERR ML {$order->order_id}: " . (string) ($order->syscom_order_cancel_error ?? 'sin detalle'));
            }
        }

        $this->info("Procesadas: {$orders->count()} | Canceladas OK: {$ok} | Error: {$err}");

        return self::SUCCESS;
    }
}
