<?php

namespace App\Services;

use App\Exceptions\InventoryMeliOrderReconciliationSchemaException;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Models\MeliOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventoryMeliOrderReservationDispatcher
{
    public function __construct(
        private readonly InventoryMeliOrderReservationCutover $cutover,
        private readonly InventoryMeliOrderReconciliationCoordinator $coordinator,
    ) {}

    public function dispatchAfterCommit(
        MeliOrder $order,
        ?string $previousFingerprint = null,
        bool $forceReconcile = false,
    ): void {
        if (! (bool) config('inventory.meli_order_reservations.automatic', false)) {
            return;
        }

        if (! $this->cutover->allows($order)) {
            return;
        }

        $localId = (int) $order->getKey();
        $remoteId = $order->order_id;
        $accountId = $order->meli_account_id;
        $queue = (string) config('inventory.meli_order_reservations.queue', 'meli');

        try {
            $decision = $this->coordinator->registerOrderState(
                $order,
                $previousFingerprint,
                $forceReconcile,
                $queue,
            );

            // When on same-DB `database` queue, the job row was already inserted atomically inside the
            // same DB transaction as the PENDING state — nothing to dispatch in afterCommit.
            if (! $decision['should_dispatch_after_commit']) {
                return;
            }

            $revision = (int) $decision['revision'];

            DB::afterCommit(function () use ($localId, $remoteId, $accountId, $queue, $revision): void {
                try {
                    $this->coordinator->invokeAfterCommitOutboxHook($localId, $revision, 'dispatcher');

                    // The sync driver executes the job here. Keep the dispatch boundary
                    // best effort so legacy order persistence remains successful.
                    ReconcileInventoryMeliOrderReservationsJob::dispatch($localId, $revision)->onQueue($queue);
                    $this->coordinator->confirmOutboxDispatch($localId, $revision);
                } catch (Throwable $exception) {
                    try {
                        $this->coordinator->markEnqueueFailedForRevision(
                            $localId,
                            $revision,
                            $exception->getMessage(),
                        );
                    } catch (Throwable) {
                        // Never mask enqueue failure logging or fail order persistence.
                    }
                    $this->logEnqueueFailure($localId, $remoteId, $accountId, $exception);
                }
            });
        } catch (InventoryMeliOrderReconciliationSchemaException $exception) {
            Log::critical('Inventory Meli order reservation blocked: durable reconciliation table missing', [
                'diagnostic_code' => 'missing_reconciliation_state_table',
                'meli_order_id' => $localId,
                'remote_order_id' => $remoteId,
                'meli_account_id' => $accountId,
                'message' => $this->coordinator->sanitizeErrorMessage($exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            $this->logEnqueueFailure($localId, $remoteId, $accountId, $exception);
        }
    }

    /**
     * Explicit manual/administrative reconciliation trigger for changes outside the order snapshot
     * (e.g. channel link activated, kit components modified, or manual inventory review resolved).
     */
    public function dispatchForceReconcile(MeliOrder $order): void
    {
        $this->dispatchAfterCommit($order, previousFingerprint: null, forceReconcile: true);
    }

    private function logEnqueueFailure(int $localId, mixed $remoteId, mixed $accountId, Throwable $exception): void
    {
        $message = $this->coordinator->sanitizeErrorMessage($exception->getMessage());

        Log::error('Inventory Meli order reservation enqueue failed', [
            'meli_order_id' => $localId,
            'remote_order_id' => $remoteId,
            'meli_account_id' => $accountId,
            'exception' => $exception::class,
            'message' => $message,
        ]);
    }
}
