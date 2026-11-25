<?php

namespace App\Jobs;

use App\Models\MeliOrder;
use App\Services\InventoryMeliAffectedStockDispatcher;
use App\Services\InventoryMeliOrderReconciliationCoordinator;
use App\Services\InventoryMeliOrderReservationCutover;
use App\Services\InventoryMeliOrderReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ReconcileInventoryMeliOrderReservationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [15, 60, 180, 600];

    public function __construct(public readonly int $meliOrderId)
    {
        $this->onQueue((string) config('inventory.meli_order_reservations.queue', 'meli'));
    }

    public function handle(InventoryMeliOrderReservationService $service): void
    {
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
        $order = MeliOrder::query()->with('items')->find($this->meliOrderId);

        if (! $order) {
            $coordinator->releaseWithoutReconciling($this->meliOrderId);
            Log::warning('Inventory Meli order reservation skipped: local order missing', [
                'meli_order_id' => $this->meliOrderId,
            ]);

            return;
        }

        if (! (bool) config('inventory.meli_order_reservations.automatic', false)) {
            $coordinator->releaseWithoutReconciling($this->meliOrderId);
            Log::info('Inventory Meli order reservation skipped: automation disabled', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
            ]);

            return;
        }

        if (! app(InventoryMeliOrderReservationCutover::class)->allows($order)) {
            $coordinator->releaseWithoutReconciling($this->meliOrderId);
            Log::info('Inventory Meli order reservation skipped: outside automatic cutover', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
            ]);

            return;
        }

        $leaseOwner = (string) Str::uuid();
        $claim = $coordinator->claimForExecution($order->id, $leaseOwner);

        if (! $claim['claimed']) {
            Log::info('Inventory Meli order reservation skipped: active lease held', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
                'reason' => $claim['reason'],
            ]);

            return;
        }

        try {
            // Re-read the freshest committed order + items AFTER claiming the lease.
            $freshOrder = MeliOrder::query()->with('items')->find($this->meliOrderId);
            if (! $freshOrder) {
                $coordinator->releaseWithoutReconciling($this->meliOrderId, $leaseOwner);

                return;
            }

            if (! (bool) config('inventory.meli_order_reservations.automatic', false)
                || ! app(InventoryMeliOrderReservationCutover::class)->allows($freshOrder)) {
                $coordinator->releaseWithoutReconciling($this->meliOrderId, $leaseOwner);

                return;
            }

            $reconciledFingerprint = $coordinator->fingerprint($freshOrder);
            $results = $service->apply($freshOrder);

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
            )->dispatchForOrder($freshOrder);

            $completion = $coordinator->completeExecution(
                $freshOrder->id,
                $leaseOwner,
                $claim['starting_revision'],
                $reconciledFingerprint,
            );

            if ($completion['needs_followup']) {
                $queue = (string) config('inventory.meli_order_reservations.queue', 'meli');
                $orderId = $freshOrder->id;
                DB::afterCommit(static function () use ($orderId, $queue): void {
                    self::dispatch($orderId)->onQueue($queue);
                });
            }

            $actions = [];

            foreach ($results as $result) {
                $action = (string) ($result['action'] ?? 'UNKNOWN');
                $actions[$action] = ($actions[$action] ?? 0) + 1;
            }

            Log::info('Inventory Meli order reservation reconciliation completed', [
                'meli_order_id' => $freshOrder->id,
                'remote_order_id' => $freshOrder->order_id,
                'meli_account_id' => $freshOrder->meli_account_id,
                'actions' => $actions,
                'stock_sync_jobs_dispatched' => $stockSyncJobsDispatched,
                'followup_dispatched' => $completion['needs_followup'],
            ]);
        } catch (Throwable $exception) {
            try {
                $coordinator->markFailed(
                    $this->meliOrderId,
                    $leaseOwner,
                    mb_substr($exception->getMessage(), 0, 500),
                );
            } catch (Throwable) {
                // Never mask the original exception.
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        try {
            app(InventoryMeliOrderReconciliationCoordinator::class)->markFailed(
                $this->meliOrderId,
                null,
                mb_substr($exception ? $exception->getMessage() : 'job failed', 0, 500),
            );
        } catch (Throwable) {
            // Best-effort state cleanup on terminal failure.
        }
    }
}
