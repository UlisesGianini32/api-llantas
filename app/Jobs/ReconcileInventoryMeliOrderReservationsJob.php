<?php

namespace App\Jobs;

use App\Exceptions\InventoryMeliOrderReconciliationSchemaException;
use App\Exceptions\StaleReconciliationLeaseException;
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

    /**
     * Bounded per-attempt timeout strictly below lease_seconds (300s) and retry_after (7300s).
     */
    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [15, 60, 180, 600];

    /**
     * Intentionally declared as a non-readonly property with a real default (`null`)
     * so pre-R3 serialized payloads (which lacked `targetRevision`) unserialize cleanly in PHP 8.4.
     */
    public ?int $targetRevision = null;

    private ?string $activeLeaseOwner = null;

    private ?int $activeFencingToken = null;

    public function __construct(
        public readonly int $meliOrderId,
        ?int $targetRevision = null,
    ) {
        $this->targetRevision = $targetRevision;
        $this->timeout = max(30, (int) config('inventory.meli_order_reservations.job_timeout_seconds', 120));
        $this->onQueue((string) config('inventory.meli_order_reservations.queue', 'meli'));
    }

    public function handle(InventoryMeliOrderReservationService $service): void
    {
        $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);

        try {
            $coordinator->assertStateTableExists();
            $coordinator->assertStrictAtomicQueueIfEnabled();
        } catch (InventoryMeliOrderReconciliationSchemaException $exception) {
            Log::critical('Inventory Meli order reservation blocked: durable reconciliation table missing', [
                'diagnostic_code' => 'missing_reconciliation_state_table',
                'meli_order_id' => $this->meliOrderId,
                'target_revision' => $this->targetRevision,
                'message' => $coordinator->sanitizeErrorMessage($exception->getMessage()),
            ]);

            return;
        } catch (\RuntimeException $exception) {
            Log::critical('Inventory Meli order reservation blocked: incompatible atomic queue configuration', [
                'diagnostic_code' => 'incompatible_atomic_queue_config',
                'meli_order_id' => $this->meliOrderId,
                'target_revision' => $this->targetRevision,
                'message' => $coordinator->sanitizeErrorMessage($exception->getMessage()),
            ]);

            return;
        }

        $order = MeliOrder::query()->with('items')->find($this->meliOrderId);

        if (! $order) {
            $coordinator->releasePendingIfUnowned($this->meliOrderId, $this->targetRevision);
            Log::warning('Inventory Meli order reservation skipped: local order missing', [
                'meli_order_id' => $this->meliOrderId,
            ]);

            return;
        }

        if (! (bool) config('inventory.meli_order_reservations.automatic', false)) {
            $coordinator->releasePendingIfUnowned($this->meliOrderId, $this->targetRevision);
            Log::info('Inventory Meli order reservation skipped: automation disabled', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
            ]);

            return;
        }

        if (! app(InventoryMeliOrderReservationCutover::class)->allows($order)) {
            $coordinator->releasePendingIfUnowned($this->meliOrderId, $this->targetRevision);
            Log::info('Inventory Meli order reservation skipped: outside automatic cutover', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
            ]);

            return;
        }

        $leaseOwner = (string) Str::uuid();
        $isQueuedExecution = $this->job !== null;
        $claim = $coordinator->claimForExecution(
            $order->id,
            $leaseOwner,
            $this->targetRevision,
            $isQueuedExecution,
        );

        if (! $claim['claimed']) {
            Log::info('Inventory Meli order reservation skipped by coordinator', [
                'meli_order_id' => $order->id,
                'remote_order_id' => $order->order_id,
                'meli_account_id' => $order->meli_account_id,
                'reason' => $claim['reason'],
                'target_revision' => $this->targetRevision,
            ]);

            return;
        }

        $fencingToken = (int) $claim['fencing_token'];
        $this->activeLeaseOwner = $leaseOwner;
        $this->activeFencingToken = $fencingToken;

        try {
            // Re-read the freshest committed order + items AFTER claiming the lease.
            $freshOrder = MeliOrder::query()->with('items')->find($this->meliOrderId);
            if (! $freshOrder) {
                $coordinator->releaseOwnedLease($this->meliOrderId, $leaseOwner, $fencingToken);
                $this->clearActiveLease();

                return;
            }

            if (! (bool) config('inventory.meli_order_reservations.automatic', false)
                || ! app(InventoryMeliOrderReservationCutover::class)->allows($freshOrder)) {
                $coordinator->releaseOwnedLease($this->meliOrderId, $leaseOwner, $fencingToken);
                $this->clearActiveLease();

                return;
            }

            $reconciledFingerprint = $coordinator->fingerprint($freshOrder);

            // Scope the active lease + fencing token so InventoryMeliOrderReservationService verifies
            // ownership inside every line transaction BEFORE mutating allocations/inventory.
            $results = $coordinator->runWithActiveLease(
                $freshOrder->id,
                $leaseOwner,
                $fencingToken,
                static fn (): array => $service->apply($freshOrder),
            );

            /*
             * Re-discover affected products from the persisted allocations rather
             * than trusting only the current action result.
             *
             * When running on same-DB `database` queue with the real stock dispatcher,
             * ALL SyncInventoryMeliStockLinkJob rows are enqueued ATOMICALLY inside
             * completeExecution()'s DB transaction under lease_owner + fencing_token lock
             * (zero partial dispatch on SIGKILL, zero stale-owner stock dispatch).
             * Otherwise (BusFake / QueueFake / mocked dispatcher / non-same-DB), dispatch
             * under fenced renewal right before completeExecution().
             */
            $stockDispatcher = app(InventoryMeliAffectedStockDispatcher::class);
            $queue = (string) config('inventory.meli_order_reservations.queue', 'meli');
            $useAtomicStockHandoff = $coordinator->canAtomicPushToDatabaseQueue()
                && ! ($stockDispatcher instanceof \Mockery\MockInterface);

            if ($useAtomicStockHandoff) {
                $coordinator->markStockSyncRequiredAndRenewLease(
                    $freshOrder->id,
                    $leaseOwner,
                    $fencingToken,
                );
                $linkIds = $stockDispatcher->resolveDispatchableLinkIdsForOrder($freshOrder);

                $completion = $coordinator->completeExecution(
                    $freshOrder->id,
                    $leaseOwner,
                    $fencingToken,
                    (int) $claim['starting_revision'],
                    $reconciledFingerprint,
                    $queue,
                    $linkIds,
                );
                $stockSyncJobsDispatched = (int) $completion['stock_sync_jobs_enqueued_atomically'];
            } else {
                // Verify lease & fencing token BEFORE dispatching remote stock sync, and durably flag
                // stock_sync_required = true so a retry still converges stock if dispatchForOrder() fails.
                $coordinator->markStockSyncRequiredAndRenewLease(
                    $freshOrder->id,
                    $leaseOwner,
                    $fencingToken,
                );

                $stockSyncJobsDispatched = $stockDispatcher->dispatchForOrder($freshOrder);

                $completion = $coordinator->completeExecution(
                    $freshOrder->id,
                    $leaseOwner,
                    $fencingToken,
                    (int) $claim['starting_revision'],
                    $reconciledFingerprint,
                    $queue,
                );
            }

            if ($completion['needs_after_commit_followup']) {
                $orderId = $freshOrder->id;
                $remoteId = $freshOrder->order_id;
                $accountId = $freshOrder->meli_account_id;
                $nextRev = (int) $completion['next_revision'];

                DB::afterCommit(static function () use ($orderId, $remoteId, $accountId, $queue, $nextRev, $coordinator): void {
                    try {
                        $coordinator->invokeAfterCommitOutboxHook($orderId, $nextRev, 'complete_execution_followup');
                        self::dispatch($orderId, $nextRev)->onQueue($queue);
                        $coordinator->confirmOutboxDispatch($orderId, $nextRev);
                    } catch (Throwable $enqueueException) {
                        try {
                            $coordinator->markEnqueueFailedForRevision(
                                $orderId,
                                $nextRev,
                                $enqueueException->getMessage(),
                            );
                        } catch (Throwable) {
                            // Never mask enqueue failure logging.
                        }

                        Log::error('Inventory Meli order reservation followup enqueue failed', [
                            'meli_order_id' => $orderId,
                            'remote_order_id' => $remoteId,
                            'meli_account_id' => $accountId,
                            'revision' => $nextRev,
                            'exception' => $enqueueException::class,
                            'message' => $coordinator->sanitizeErrorMessage($enqueueException->getMessage()),
                        ]);
                    }
                });
            }

            $this->clearActiveLease();

            $actions = [];
            foreach ($results as $result) {
                $action = (string) ($result['action'] ?? 'UNKNOWN');
                $actions[$action] = ($actions[$action] ?? 0) + 1;
            }

            Log::info('Inventory Meli order reservation reconciliation completed', [
                'meli_order_id' => $freshOrder->id,
                'remote_order_id' => $freshOrder->order_id,
                'meli_account_id' => $freshOrder->meli_account_id,
                'fencing_token' => $fencingToken,
                'actions' => $actions,
                'stock_sync_jobs_dispatched' => $stockSyncJobsDispatched,
                'stock_sync_handoff' => $stockSyncJobsDispatched > 0 ? 'enqueued_not_remotely_confirmed' : 'none_required',
                'stock_sync_atomic' => $useAtomicStockHandoff,
                'followup_dispatched' => $completion['needs_followup'],
                'followup_atomic' => $completion['enqueued_atomically'],
            ]);
        } catch (StaleReconciliationLeaseException $stale) {
            $this->clearActiveLease();
            Log::warning('Inventory Meli order reservation aborted: stale lease or fencing token', [
                'meli_order_id' => $this->meliOrderId,
                'lease_owner' => $leaseOwner,
                'fencing_token' => $fencingToken,
                'message' => $coordinator->sanitizeErrorMessage($stale->getMessage()),
            ]);
        } catch (Throwable $exception) {
            $retryJobId = null;
            if ($this->job !== null && $this->attempts() < $this->tries) {
                $rawJobId = $this->job->getJobId();
                if (is_numeric($rawJobId) && (int) $rawJobId > 0) {
                    $retryJobId = (int) $rawJobId;
                }
            }

            try {
                $coordinator->markFailedForOwner(
                    $this->meliOrderId,
                    $leaseOwner,
                    $fencingToken,
                    $exception->getMessage(),
                    $retryJobId,
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
            $coordinator = app(InventoryMeliOrderReconciliationCoordinator::class);
            $error = $exception ? $exception->getMessage() : 'job failed';

            if ($this->activeLeaseOwner !== null && $this->activeFencingToken !== null) {
                $coordinator->markFailedForOwner(
                    $this->meliOrderId,
                    $this->activeLeaseOwner,
                    $this->activeFencingToken,
                    $error,
                    null,
                );
                $this->clearActiveLease();

                return;
            }

            $coordinator->markTerminalFailureIfUnowned(
                $this->meliOrderId,
                $this->targetRevision,
                $error,
            );
        } catch (Throwable) {
            // Best-effort state cleanup on terminal failure.
        }
    }

    private function clearActiveLease(): void
    {
        $this->activeLeaseOwner = null;
        $this->activeFencingToken = null;
    }
}
