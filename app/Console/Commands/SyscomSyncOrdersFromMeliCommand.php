<?php

namespace App\Console\Commands;

use App\Models\MeliOrder;
use App\Models\User;
use App\Services\SyscomOrderFromMeliService;
use Illuminate\Console\Command;

class SyscomSyncOrdersFromMeliCommand extends Command
{
    protected $signature = 'syscom:sync-orders-from-ml
                            {--user_id= : Usuario ML a usar (default: primero con access_token)}
                            {--max=100 : Máximo de órdenes a procesar}';

    protected $description = 'Crea pedidos SYSCOM para órdenes ML paid pendientes de sincronizar';

    public function handle(SyscomOrderFromMeliService $service): int
    {
        $userId = (int) ($this->option('user_id') ?: 0);
        $max = max(1, (int) ($this->option('max') ?: 100));

        $user = $userId > 0
            ? User::query()->find($userId)
            : User::query()->whereNotNull('access_token')->first();

        if (! $user || ! $user->access_token) {
            $this->error('No se encontró usuario con access_token para consultar ML.');

            return self::FAILURE;
        }

        $orders = MeliOrder::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'paid'")
            ->whereNull('syscom_order_synced_at')
            ->orderBy('id')
            ->limit($max)
            ->get();

        if ($orders->isEmpty()) {
            $this->line('No hay órdenes paid pendientes de enviar a SYSCOM.');

            return self::SUCCESS;
        }

        $ok = 0;
        $err = 0;
        $skip = 0;
        foreach ($orders as $order) {
            $order->load('items');
            $service->syncIfEligible($user, $order);
            $order->refresh();

            if ($order->syscom_order_synced_at) {
                $ok++;
                $this->line("OK  ML {$order->order_id} -> SYSCOM {$order->syscom_order_folio}");
            } elseif (str_starts_with((string) $order->syscom_order_error, 'SKIP_NO_SYSCOM_ITEMS:')) {
                $skip++;
                $this->line("SKIP ML {$order->order_id}: no corresponde a publicación SYSCOM");
            } else {
                $err++;
                $this->warn("ERR ML {$order->order_id}: " . (string) $order->syscom_order_error);
            }
        }

        $this->info("Procesadas: {$orders->count()} | OK: {$ok} | SKIP: {$skip} | Error: {$err}");

        return self::SUCCESS;
    }
}

