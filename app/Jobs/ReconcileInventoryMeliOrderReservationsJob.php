<?php

namespace App\Jobs;

use App\Models\MeliOrder;
use App\Services\InventoryMeliOrderReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcileInventoryMeliOrderReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $meliOrderId)
    {
        $this->onQueue((string) config('inventory.meli_order_reservations.queue', 'meli'));
    }

    public function handle(InventoryMeliOrderReservationService $service): void
    {
        $order = MeliOrder::query()->with('items')->find($this->meliOrderId);
        if (! $order) {
            Log::warning('Inventory Meli order reservation skipped: local order missing', [
                'meli_order_id' => $this->meliOrderId,
            ]);

            return;
        }

        $results = $service->apply($order);
        $actions = [];
        foreach ($results as $result) {
            $action = (string) ($result['action'] ?? 'UNKNOWN');
            $actions[$action] = ($actions[$action] ?? 0) + 1;
        }

        Log::info('Inventory Meli order reservation reconciliation completed', [
            'meli_order_id' => $order->id,
            'remote_order_id' => $order->order_id,
            'meli_account_id' => $order->meli_account_id,
            'actions' => $actions,
        ]);
    }
}
