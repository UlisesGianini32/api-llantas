<?php

namespace App\Jobs;

use App\Models\MeliOrder;
use App\Services\InventoryMeliAffectedStockDispatcher;
use App\Services\InventoryMeliOrderReservationCutover;
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

        if (! (bool) config('inventory.meli_order_reservations.automatic', false)) {
            Log::info('Inventory Meli order reservation skipped: automation disabled', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
            ]);

            return;
        }

        if (! app(InventoryMeliOrderReservationCutover::class)->allows($order)) {
            Log::info('Inventory Meli order reservation skipped: outside automatic cutover', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
            ]);

            return;
        }

        $results = $service->apply($order);

        /*
         * Re-discover affected products from the persisted allocations rather
         * than trusting only the current action result.
         *
         * This is intentional: a retry can return NO_CHANGE after the
         * reservation was already committed, but it must still have another
         * opportunity to converge Mercado Libre stock if the previous stock
         * dispatch failed.
         */
        $stockSyncJobsDispatched = app(
            InventoryMeliAffectedStockDispatcher::class
        )->dispatchForOrder($order);

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
            'stock_sync_jobs_dispatched' => $stockSyncJobsDispatched,
        ]);
    }
}