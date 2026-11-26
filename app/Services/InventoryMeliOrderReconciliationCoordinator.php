<?php

namespace App\Services;

use App\Exceptions\InventoryMeliOrderReconciliationSchemaException;
use App\Exceptions\StaleReconciliationLeaseException;
use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Jobs\SyncInventoryMeliStockLinkJob;
use App\Models\InventoryMeliOrderReconciliation;
use App\Models\MeliOrder;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Throwable;

/**
 * Durable per-order reconciliation coordinator (state machine + revision coalescing + fencing + outbox).
 *
 * Guarantees (R3.1):
 * - Fail-closed if inventory_meli_order_reconciliations does not exist (H5).
 * - Atomic same-DB transactional enqueue into `jobs` when the default queue uses the `database` driver
 *   on the same DB connection, plus bounded transactional outbox recovery (`dispatch_confirmed_at IS NULL`
 *   or O(1) PK verification of `pending_job_id`) for non-same-DB drivers / SIGKILL recovery (H1).
 * - Monotonic `fencing_token` + lease renewal + transactional ownership verification inside
 *   InventoryMeliOrderReservationService before any inventory mutation, with strict deadlock-free lock
 *   ordering: meli_orders -> inventory_meli_order_reconciliations -> inventory_channel_order_allocations
 *   -> inventory_products -> inventory_reservations (H2).
 * - Safe failure/release semantics: stale workers or stale callbacks can never release or overwrite
 *   another worker's active PROCESSING lease or a newer revision, and all secrets are redacted (H3).
 * - Under-lock live fingerprint calculation, stock_sync_required convergence tracking, O(1) retry job
 *   coalescing, and historical redundant replay rejection (H4).
 */
class InventoryMeliOrderReconciliationCoordinator
{
    public const DEFAULT_LEASE_SECONDS = 300;

    public const DEFAULT_OUTBOX_GRACE_SECONDS = 15;

    /** @var array<int, list<array{lease_owner: string, fencing_token: int}>> */
    private static array $activeLeaseContexts = [];

    private static bool $allowSameDbAtomicPush = true;

    /** @var (callable(int, int, string): void)|null */
    private static $afterCommitOutboxHook = null;

    public function __construct(
        private readonly InventoryMeliOrderReconciliationFingerprint $fingerprint,
    ) {}

    public function hasStateTable(): bool
    {
        return Schema::hasTable('inventory_meli_order_reconciliations');
    }

    public function assertStateTableExists(): void
    {
        if (! $this->hasStateTable()) {
            throw new InventoryMeliOrderReconciliationSchemaException;
        }
    }

    public function fingerprint(MeliOrder $order): string
    {
        return $this->fingerprint->forOrder($order);
    }

    public static function setAllowSameDbAtomicPush(bool $allow): void
    {
        self::$allowSameDbAtomicPush = $allow;
    }

    /** @param (callable(int, int, string): void)|null $hook */
    public static function setAfterCommitOutboxHook(?callable $hook): void
    {
        self::$afterCommitOutboxHook = $hook;
    }

    public static function resetTestingHooks(): void
    {
        self::$allowSameDbAtomicPush = true;
        self::$afterCommitOutboxHook = null;
        self::$activeLeaseContexts = [];
    }

    public function invokeAfterCommitOutboxHook(int $meliOrderId, int $revision, string $source): void
    {
        if (self::$afterCommitOutboxHook !== null) {
            (self::$afterCommitOutboxHook)($meliOrderId, $revision, $source);
        }
    }

    /**
     * Scopes an active lease context for the current order so InventoryMeliOrderReservationService
     * enforces transactional fencing without breaking legacy 1-arg service call signatures.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runWithActiveLease(
        int $meliOrderId,
        string $leaseOwner,
        int $fencingToken,
        callable $callback,
    ): mixed {
        self::$activeLeaseContexts[$meliOrderId][] = [
            'lease_owner' => $leaseOwner,
            'fencing_token' => $fencingToken,
        ];

        try {
            return $callback();
        } finally {
            array_pop(self::$activeLeaseContexts[$meliOrderId]);
            if (self::$activeLeaseContexts[$meliOrderId] === []) {
                unset(self::$activeLeaseContexts[$meliOrderId]);
            }
        }
    }

    /** @return array{lease_owner: string, fencing_token: int}|null */
    public function activeLeaseContext(int $meliOrderId): ?array
    {
        $stack = self::$activeLeaseContexts[$meliOrderId] ?? [];

        return $stack === [] ? null : $stack[count($stack) - 1];
    }

    /**
     * Registers an order sync/update under row locks (`meli_orders` -> `inventory_meli_order_reconciliations`)
     * and atomically decides whether a job must be enqueued (either inside the same DB TX or via outbox).
     *
     * @return array{
     *     should_dispatch: bool,
     *     enqueued_atomically: bool,
     *     should_dispatch_after_commit: bool,
     *     pending_job_id: ?int,
     *     reason: string,
     *     revision: int,
     *     fingerprint: string
     * }
     */
    public function registerOrderState(
        MeliOrder $order,
        ?string $previousFingerprint = null,
        bool $forceReconcile = false,
        ?string $queueName = null,
    ): array {
        $this->assertStateTableExists();

        if ((bool) config('inventory.meli_order_reservations.strict_atomic_queue', false)
            && ! $this->canAtomicPushToDatabaseQueue()) {
            throw new \RuntimeException(
                'Modo estricto activo (strict_atomic_queue=true): la cola debe usar driver database en la misma conexión transaccional con after_commit=false.',
            );
        }

        $localId = (int) $order->getKey();
        if ($localId <= 0) {
            return [
                'should_dispatch' => false,
                'enqueued_atomically' => false,
                'should_dispatch_after_commit' => false,
                'pending_job_id' => null,
                'reason' => 'invalid_order_id',
                'revision' => 0,
                'fingerprint' => $this->fingerprint->forOrder($order),
            ];
        }

        $queue = $queueName ?? (string) config('inventory.meli_order_reservations.queue', 'meli');

        return DB::transaction(function () use ($localId, $forceReconcile, $queue): array {
            $now = now();

            // Lock order FIRST (global lock ordering: meli_orders -> inventory_meli_order_reconciliations -> jobs)
            // and compute the material fingerprint from the committed/locked DB row to prevent out-of-order regressions.
            $freshOrder = MeliOrder::query()
                ->whereKey($localId)
                ->lockForUpdate()
                ->first();

            if (! $freshOrder) {
                return [
                    'should_dispatch' => false,
                    'enqueued_atomically' => false,
                    'should_dispatch_after_commit' => false,
                    'pending_job_id' => null,
                    'reason' => 'order_not_found',
                    'revision' => 0,
                    'fingerprint' => '',
                ];
            }

            $freshOrder->load('items');
            $currentFingerprint = $this->fingerprint->forOrder($freshOrder);

            DB::table('inventory_meli_order_reconciliations')->insertOrIgnore([
                'meli_order_id' => $localId,
                'meli_account_id' => $freshOrder->meli_account_id !== null ? (int) $freshOrder->meli_account_id : null,
                'remote_order_id' => $freshOrder->order_id !== null ? (string) $freshOrder->order_id : null,
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'requested_revision' => 0,
                'processed_revision' => 0,
                'last_enqueued_revision' => 0,
                'fencing_token' => 0,
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'stock_sync_required' => false,
                'stock_sync_completed_revision' => 0,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $localId)
                ->lockForUpdate()
                ->firstOrFail();

            $row->meli_account_id = $freshOrder->meli_account_id !== null ? (int) $freshOrder->meli_account_id : $row->meli_account_id;
            $row->remote_order_id = $freshOrder->order_id !== null ? (string) $freshOrder->order_id : $row->remote_order_id;

            $fingerprintChangedFromRequested = $row->last_state_fingerprint === null
                || ! hash_equals((string) $row->last_state_fingerprint, $currentFingerprint);
            $fingerprintDiffersFromReconciled = $row->last_reconciled_fingerprint === null
                || ! hash_equals((string) $row->last_reconciled_fingerprint, $currentFingerprint);
            $hasUnprocessedRevision = (int) $row->requested_revision > (int) $row->processed_revision;
            $needsStockSync = (bool) $row->stock_sync_required;

            // 1. IDLE and already reconciled at this exact material fingerprint with stock sync complete => NO-OP.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_IDLE
                && ! $forceReconcile
                && ! $fingerprintDiffersFromReconciled
                && ! $hasUnprocessedRevision
                && ! $needsStockSync) {
                $row->save();

                return [
                    'should_dispatch' => false,
                    'enqueued_atomically' => false,
                    'should_dispatch_after_commit' => false,
                    'pending_job_id' => null,
                    'reason' => 'already_reconciled_no_material_change',
                    'revision' => (int) $row->requested_revision,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // 2. PENDING => Verify durability of the pending job (O(1) PK check or outbox confirmation).
            if ($row->state === InventoryMeliOrderReconciliation::STATE_PENDING) {
                $missingPendingJob = $this->isPendingDispatchMissing($row, $now);

                if (! $missingPendingJob) {
                    if ($fingerprintChangedFromRequested || $forceReconcile) {
                        $row->requested_revision = (int) $row->requested_revision + 1;
                        $row->last_state_fingerprint = $currentFingerprint;
                        $row->save();

                        return [
                            'should_dispatch' => false,
                            'enqueued_atomically' => false,
                            'should_dispatch_after_commit' => false,
                            'pending_job_id' => $row->pending_job_id !== null ? (int) $row->pending_job_id : null,
                            'reason' => 'coalesced_into_pending_with_update',
                            'revision' => (int) $row->requested_revision,
                            'fingerprint' => $currentFingerprint,
                        ];
                    }

                    $row->save();

                    return [
                        'should_dispatch' => false,
                        'enqueued_atomically' => false,
                        'should_dispatch_after_commit' => false,
                        'pending_job_id' => $row->pending_job_id !== null ? (int) $row->pending_job_id : null,
                        'reason' => 'already_pending_same_fingerprint',
                        'revision' => (int) $row->requested_revision,
                        'fingerprint' => $currentFingerprint,
                    ];
                }

                // Orphan PENDING detected (unconfirmed outbox after grace window OR pending_job_id missing from `jobs`).
                $nextRevision = ($fingerprintChangedFromRequested || $forceReconcile)
                    ? ((int) $row->requested_revision + 1)
                    : max(1, (int) $row->requested_revision);

                return $this->transitionToPendingAndEnqueue(
                    $row,
                    $localId,
                    $nextRevision,
                    $currentFingerprint,
                    $queue,
                    $now,
                    'recovered_orphan_pending',
                );
            }

            // 3. PROCESSING with ACTIVE (non-expired) lease => Mark dirty so running job schedules 1 follow-up.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING
                && $row->lease_expires_at !== null
                && $row->lease_expires_at->isFuture()) {
                if ($fingerprintChangedFromRequested || $forceReconcile) {
                    $row->requested_revision = (int) $row->requested_revision + 1;
                    $row->last_state_fingerprint = $currentFingerprint;
                    $row->save();

                    return [
                        'should_dispatch' => false,
                        'enqueued_atomically' => false,
                        'should_dispatch_after_commit' => false,
                        'pending_job_id' => null,
                        'reason' => 'marked_dirty_during_processing',
                        'revision' => (int) $row->requested_revision,
                        'fingerprint' => $currentFingerprint,
                    ];
                }

                $row->save();

                return [
                    'should_dispatch' => false,
                    'enqueued_atomically' => false,
                    'should_dispatch_after_commit' => false,
                    'pending_job_id' => null,
                    'reason' => 'already_processing_same_fingerprint',
                    'revision' => (int) $row->requested_revision,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // 4. FAILED while a Laravel backoff retry job STILL exists in `jobs` (O(1) PK lookup) => coalesce into it.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_FAILED
                && $row->pending_job_id !== null
                && $this->databaseJobExists((int) $row->pending_job_id)) {
                $nextRevision = ($fingerprintChangedFromRequested || $forceReconcile || ! $hasUnprocessedRevision)
                    ? ((int) $row->requested_revision + 1)
                    : (int) $row->requested_revision;

                $row->forceFill([
                    'state' => InventoryMeliOrderReconciliation::STATE_PENDING,
                    'requested_revision' => $nextRevision,
                    'last_enqueued_revision' => $nextRevision,
                    'last_state_fingerprint' => $currentFingerprint,
                    'dispatch_confirmed_at' => $now,
                    'queued_at' => $now,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ])->save();

                return [
                    'should_dispatch' => false,
                    'enqueued_atomically' => false,
                    'should_dispatch_after_commit' => false,
                    'pending_job_id' => (int) $row->pending_job_id,
                    'reason' => 'coalesced_into_existing_retry_job',
                    'revision' => $nextRevision,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // 5. IDLE (change / never reconciled / pending stock sync), FAILED (no retry in jobs),
            //    or PROCESSING with EXPIRED lease => transition to PENDING and enqueue exactly 1 job.
            $wasExpiredLease = $row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING;
            $nextRevision = ($fingerprintChangedFromRequested || $forceReconcile || ! $hasUnprocessedRevision)
                ? ((int) $row->requested_revision + 1)
                : (int) $row->requested_revision;

            return $this->transitionToPendingAndEnqueue(
                $row,
                $localId,
                $nextRevision,
                $currentFingerprint,
                $queue,
                $now,
                $wasExpiredLease ? 'reclaimed_expired_lease' : 'enqueued',
            );
        });
    }

    /**
     * Marks an outbox-dispatched PENDING row as durably confirmed in the queue backend.
     */
    public function confirmOutboxDispatch(int $meliOrderId, int $revision, ?int $jobId = null): void
    {
        $this->assertStateTableExists();

        DB::transaction(function () use ($meliOrderId, $revision, $jobId): void {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row
                || $row->state !== InventoryMeliOrderReconciliation::STATE_PENDING
                || (int) $row->last_enqueued_revision !== $revision) {
                return;
            }

            $updates = [
                'dispatch_confirmed_at' => now(),
            ];
            if ($jobId !== null && $jobId > 0) {
                $updates['pending_job_id'] = $jobId;
            }

            $row->forceFill($updates)->save();
        });
    }

    /**
     * Bounded, idempotent transactional outbox recovery for PENDING rows whose enqueue was never confirmed
     * (e.g., process killed with SIGKILL/OOM between COMMIT and afterCommit enqueue on non-same-DB queues).
     * Never scans `jobs` payloads; uses the composite index `(state, dispatch_confirmed_at)`.
     *
     * @return array{scanned: int, recovered: int, skipped: int}
     */
    public function recoverUnconfirmedOutboxDispatches(
        int $limit = 100,
        ?int $graceSeconds = null,
        ?string $queueName = null,
    ): array {
        $this->assertStateTableExists();

        $limit = max(1, min(1000, $limit));
        $grace = max(0, (int) ($graceSeconds ?? config('inventory.meli_order_reservations.outbox_grace_seconds', self::DEFAULT_OUTBOX_GRACE_SECONDS)));
        $cutoff = now()->subSeconds($grace);
        $queue = $queueName ?? (string) config('inventory.meli_order_reservations.queue', 'meli');

        $candidateIds = InventoryMeliOrderReconciliation::query()
            ->where('state', InventoryMeliOrderReconciliation::STATE_PENDING)
            ->whereNull('dispatch_confirmed_at')
            ->where(function ($q) use ($cutoff): void {
                $q->whereNull('queued_at')->orWhere('queued_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('meli_order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $recovered = 0;
        $skipped = 0;

        foreach ($candidateIds as $orderId) {
            $decision = DB::transaction(function () use ($orderId, $cutoff, $queue): array {
                $now = now();

                // Global lock ordering: meli_orders -> inventory_meli_order_reconciliations -> jobs.
                $order = MeliOrder::query()->whereKey($orderId)->lockForUpdate()->first();
                $row = InventoryMeliOrderReconciliation::query()
                    ->where('meli_order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $row
                    || $row->state !== InventoryMeliOrderReconciliation::STATE_PENDING
                    || $row->dispatch_confirmed_at !== null
                    || ($row->queued_at !== null && $row->queued_at->gt($cutoff))) {
                    return ['recovered' => false, 'needs_after_commit' => false, 'revision' => 0];
                }

                if (! $order) {
                    $row->forceFill([
                        'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                        'pending_job_id' => null,
                        'dispatch_confirmed_at' => null,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                    ])->save();

                    return ['recovered' => false, 'needs_after_commit' => false, 'revision' => 0];
                }

                $order->load('items');
                $currentFingerprint = $this->fingerprint->forOrder($order);
                $revision = max(1, (int) $row->requested_revision);

                $res = $this->transitionToPendingAndEnqueue(
                    $row,
                    $orderId,
                    $revision,
                    $currentFingerprint,
                    $queue,
                    $now,
                    'outbox_recovered',
                );

                return [
                    'recovered' => true,
                    'needs_after_commit' => $res['should_dispatch_after_commit'],
                    'revision' => $res['revision'],
                ];
            });

            if (! $decision['recovered']) {
                $skipped++;

                continue;
            }

            if ($decision['needs_after_commit']) {
                $rev = (int) $decision['revision'];
                try {
                    $this->invokeAfterCommitOutboxHook($orderId, $rev, 'outbox_recovery');
                    ReconcileInventoryMeliOrderReservationsJob::dispatch($orderId, $rev)->onQueue($queue);
                    $this->confirmOutboxDispatch($orderId, $rev);
                } catch (Throwable $exception) {
                    $this->markEnqueueFailedForRevision($orderId, $rev, $exception->getMessage());
                    Log::error('Inventory Meli order reservation outbox recovery failed', [
                        'meli_order_id' => $orderId,
                        'revision' => $rev,
                        'exception' => $exception::class,
                        'message' => $this->sanitizeErrorMessage($exception->getMessage()),
                    ]);
                    $skipped++;

                    continue;
                }
            }

            $recovered++;
        }

        return [
            'scanned' => count($candidateIds),
            'recovered' => $recovered,
            'skipped' => $skipped,
        ];
    }

    /**
     * Atomically claims execution lease + monotonic fencing token for a worker processing
     * ReconcileInventoryMeliOrderReservationsJob.
     *
     * Rejects:
     * - Active non-expired lease held by another worker.
     * - Historical/superseded queued replays when already reconciled at the exact live fingerprint
     *   and stock_sync_required is false.
     *
     * @return array{
     *     claimed: bool,
     *     reason: string,
     *     starting_revision: int,
     *     fencing_token: int,
     *     fingerprint: string
     * }
     */
    public function claimForExecution(
        int $meliOrderId,
        string $leaseOwner,
        ?int $targetRevision = null,
        bool $isQueuedExecution = false,
        bool $forceReconcile = false,
        ?int $leaseSeconds = null,
    ): array {
        $this->assertStateTableExists();

        $ttl = max(30, (int) ($leaseSeconds ?? config('inventory.meli_order_reservations.lease_seconds', self::DEFAULT_LEASE_SECONDS)));

        return DB::transaction(function () use (
            $meliOrderId,
            $leaseOwner,
            $targetRevision,
            $isQueuedExecution,
            $forceReconcile,
            $ttl,
        ): array {
            $now = now();

            // Global lock order: meli_orders FIRST, inventory_meli_order_reconciliations SECOND.
            $freshOrder = MeliOrder::query()
                ->whereKey($meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $freshOrder) {
                return [
                    'claimed' => false,
                    'reason' => 'order_not_found',
                    'starting_revision' => 0,
                    'fencing_token' => 0,
                    'fingerprint' => '',
                ];
            }

            $freshOrder->load('items');
            $currentFingerprint = $this->fingerprint->forOrder($freshOrder);

            DB::table('inventory_meli_order_reconciliations')->insertOrIgnore([
                'meli_order_id' => $meliOrderId,
                'meli_account_id' => $freshOrder->meli_account_id !== null ? (int) $freshOrder->meli_account_id : null,
                'remote_order_id' => $freshOrder->order_id !== null ? (string) $freshOrder->order_id : null,
                'state' => InventoryMeliOrderReconciliation::STATE_PENDING,
                'requested_revision' => max(1, (int) ($targetRevision ?? 1)),
                'processed_revision' => 0,
                'last_enqueued_revision' => max(1, (int) ($targetRevision ?? 1)),
                'fencing_token' => 0,
                'pending_job_id' => null,
                'dispatch_confirmed_at' => $now,
                'stock_sync_required' => false,
                'stock_sync_completed_revision' => 0,
                'queued_at' => $now,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING
                && $row->lease_expires_at !== null
                && $row->lease_expires_at->isFuture()
                && $row->lease_owner !== $leaseOwner) {
                return [
                    'claimed' => false,
                    'reason' => 'active_lease_held_by_another_worker',
                    'starting_revision' => (int) $row->requested_revision,
                    'fencing_token' => (int) $row->fencing_token,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // Skip redundant historical or superseded queued replays when already reconciled at the
            // exact live DB fingerprint and stock sync convergence is complete.
            $alreadyReconciledAtCurrentFingerprint = $row->state === InventoryMeliOrderReconciliation::STATE_IDLE
                && ! $forceReconcile
                && ! (bool) $row->stock_sync_required
                && $row->last_reconciled_fingerprint !== null
                && hash_equals((string) $row->last_reconciled_fingerprint, $currentFingerprint);

            if ($alreadyReconciledAtCurrentFingerprint) {
                if ($targetRevision !== null && (int) $row->processed_revision >= $targetRevision) {
                    return [
                        'claimed' => false,
                        'reason' => 'already_reconciled_replay',
                        'starting_revision' => (int) $row->processed_revision,
                        'fencing_token' => (int) $row->fencing_token,
                        'fingerprint' => $currentFingerprint,
                    ];
                }

                if ($targetRevision === null && $isQueuedExecution) {
                    return [
                        'claimed' => false,
                        'reason' => 'already_reconciled_historical_replay',
                        'starting_revision' => (int) $row->processed_revision,
                        'fencing_token' => (int) $row->fencing_token,
                        'fingerprint' => $currentFingerprint,
                    ];
                }
            }

            $startingRevision = max(1, (int) $row->requested_revision, (int) ($targetRevision ?? 0));
            $nextFencingToken = (int) $row->fencing_token + 1;

            $row->forceFill([
                'meli_account_id' => $freshOrder->meli_account_id !== null ? (int) $freshOrder->meli_account_id : $row->meli_account_id,
                'remote_order_id' => $freshOrder->order_id !== null ? (string) $freshOrder->order_id : $row->remote_order_id,
                'state' => InventoryMeliOrderReconciliation::STATE_PROCESSING,
                'requested_revision' => $startingRevision,
                'fencing_token' => $nextFencingToken,
                'last_state_fingerprint' => $currentFingerprint,
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => $now->copy()->addSeconds($ttl),
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'attempts' => (int) $row->attempts + 1,
            ])->save();

            return [
                'claimed' => true,
                'reason' => 'claimed',
                'starting_revision' => $startingRevision,
                'fencing_token' => $nextFencingToken,
                'fingerprint' => $currentFingerprint,
            ];
        });
    }

    /**
     * Must be called inside an open DB transaction AFTER locking `meli_orders` and BEFORE any
     * allocation/inventory mutation. Verifies ownership + fencing token + non-expired lease and
     * renews `lease_expires_at` atomically. Throws StaleReconciliationLeaseException on mismatch.
     */
    public function assertAndRenewLeaseInTransaction(
        int $meliOrderId,
        string $leaseOwner,
        int $fencingToken,
        ?int $leaseSeconds = null,
    ): void {
        $this->assertStateTableExists();

        $ttl = max(30, (int) ($leaseSeconds ?? config('inventory.meli_order_reservations.lease_seconds', self::DEFAULT_LEASE_SECONDS)));
        $now = now();

        $row = InventoryMeliOrderReconciliation::query()
            ->where('meli_order_id', $meliOrderId)
            ->lockForUpdate()
            ->first();

        if (! $row
            || $row->state !== InventoryMeliOrderReconciliation::STATE_PROCESSING
            || $row->lease_owner !== $leaseOwner
            || (int) $row->fencing_token !== $fencingToken
            || $row->lease_expires_at === null
            || $row->lease_expires_at->lte($now)) {
            throw new StaleReconciliationLeaseException(
                $meliOrderId,
                $leaseOwner,
                $fencingToken,
                'La ejecución perdió su lease o fencing token antes de mutar inventario.',
            );
        }

        $row->forceFill([
            'lease_expires_at' => $now->copy()->addSeconds($ttl),
        ])->save();
    }

    /**
     * Marks `stock_sync_required = true` inside an ALREADY-OPEN inventory transaction (after
     * `meli_orders` lock) so a SIGKILL immediately after committing an inventory line never loses
     * the obligation to synchronize affected Mercado Libre listings (H9).
     */
    public function markStockSyncRequiredInTransaction(
        int $meliOrderId,
        string $leaseOwner,
        int $fencingToken,
    ): void {
        $this->assertStateTableExists();

        $now = now();
        $row = InventoryMeliOrderReconciliation::query()
            ->where('meli_order_id', $meliOrderId)
            ->lockForUpdate()
            ->first();

        if (! $row
            || $row->state !== InventoryMeliOrderReconciliation::STATE_PROCESSING
            || $row->lease_owner !== $leaseOwner
            || (int) $row->fencing_token !== $fencingToken
            || $row->lease_expires_at === null
            || $row->lease_expires_at->lte($now)) {
            throw new StaleReconciliationLeaseException(
                $meliOrderId,
                $leaseOwner,
                $fencingToken,
                'La ejecución perdió su lease o fencing token al registrar obligación de stock sync.',
            );
        }

        if (! (bool) $row->stock_sync_required) {
            $row->forceFill(['stock_sync_required' => true])->save();
        }
    }

    /**
     * Marks stock_sync_required = true and renews active lease right after inventory apply() succeeds
     * and immediately before dispatching remote stock-sync jobs. Throws StaleReconciliationLeaseException
     * if this worker no longer owns the active lease + fencing token.
     */
    public function markStockSyncRequiredAndRenewLease(
        int $meliOrderId,
        string $leaseOwner,
        int $fencingToken,
        ?int $leaseSeconds = null,
    ): void {
        $this->assertStateTableExists();

        $ttl = max(30, (int) ($leaseSeconds ?? config('inventory.meli_order_reservations.lease_seconds', self::DEFAULT_LEASE_SECONDS)));

        DB::transaction(function () use ($meliOrderId, $leaseOwner, $fencingToken, $ttl): void {
            $now = now();

            // Follow global lock ordering: meli_orders -> inventory_meli_order_reconciliations.
            MeliOrder::query()->whereKey($meliOrderId)->lockForUpdate()->first();

            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row
                || $row->state !== InventoryMeliOrderReconciliation::STATE_PROCESSING
                || $row->lease_owner !== $leaseOwner
                || (int) $row->fencing_token !== $fencingToken
                || $row->lease_expires_at === null
                || $row->lease_expires_at->lte($now)) {
                throw new StaleReconciliationLeaseException(
                    $meliOrderId,
                    $leaseOwner,
                    $fencingToken,
                    'La ejecución perdió su lease o fencing token antes de despachar stock sync.',
                );
            }

            $row->forceFill([
                'stock_sync_required' => true,
                'lease_expires_at' => $now->copy()->addSeconds($ttl),
            ])->save();
        });
    }

    /**
     * Completes execution under row locks (`meli_orders` -> `inventory_meli_order_reconciliations` -> `jobs`),
     * verifying exact lease_owner + fencing_token.
     *
     * When `$atomicStockSyncLinkIds` is provided on a same-DB `database` queue, ALL `SyncInventoryMeliStockLinkJob`
     * rows are pushed inside this SAME DB transaction (eliminating partial dispatch on SIGKILL and guaranteeing
     * stale owners cannot dispatch stock syncs). Note that clearing `stock_sync_required` marks local queue
     * handoff only — remote Mercado Libre confirmation is audited separately in `inventory_channel_stock_syncs`.
     *
     * @param  list<int>  $atomicStockSyncLinkIds
     * @return array{
     *     needs_followup: bool,
     *     enqueued_atomically: bool,
     *     needs_after_commit_followup: bool,
     *     pending_job_id: ?int,
     *     next_revision: int,
     *     stock_sync_jobs_enqueued_atomically: int
     * }
     */
    public function completeExecution(
        int $meliOrderId,
        string $leaseOwner,
        int $fencingToken,
        int $startingRevision,
        string $reconciledFingerprint,
        ?string $queueName = null,
        array $atomicStockSyncLinkIds = [],
    ): array {
        $this->assertStateTableExists();

        $queue = $queueName ?? (string) config('inventory.meli_order_reservations.queue', 'meli');

        return DB::transaction(function () use (
            $meliOrderId,
            $leaseOwner,
            $fencingToken,
            $startingRevision,
            $reconciledFingerprint,
            $queue,
            $atomicStockSyncLinkIds,
        ): array {
            $now = now();

            // Global lock ordering: meli_orders FIRST, inventory_meli_order_reconciliations SECOND.
            $freshOrder = MeliOrder::query()->whereKey($meliOrderId)->lockForUpdate()->first();
            $liveFingerprint = $reconciledFingerprint;
            if ($freshOrder) {
                $freshOrder->load('items');
                $liveFingerprint = $this->fingerprint->forOrder($freshOrder);
            }

            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row
                || $row->state !== InventoryMeliOrderReconciliation::STATE_PROCESSING
                || $row->lease_owner !== $leaseOwner
                || (int) $row->fencing_token !== $fencingToken) {
                throw new StaleReconciliationLeaseException(
                    $meliOrderId,
                    $leaseOwner,
                    $fencingToken,
                    'La ejecución perdió su lease o fencing token antes de completar la reconciliación.',
                );
            }

            $atomicStockCount = 0;
            if ($atomicStockSyncLinkIds !== [] && $this->canAtomicPushToDatabaseQueue()) {
                $queueConnectionName = (string) config('queue.default', 'database');
                foreach ($atomicStockSyncLinkIds as $linkId) {
                    $stockJob = new SyncInventoryMeliStockLinkJob((int) $linkId);
                    Queue::connection($queueConnectionName)->pushOn($stockJob->queue ?: 'meli', $stockJob);
                    $atomicStockCount++;
                }
            }

            $currentRequested = (int) $row->requested_revision;
            $fingerprintDrifted = $row->last_state_fingerprint !== null
                && ! hash_equals((string) $row->last_state_fingerprint, $reconciledFingerprint);
            $liveFingerprintDrifted = ! hash_equals($liveFingerprint, $reconciledFingerprint);

            if ($currentRequested > $startingRevision || $fingerprintDrifted || $liveFingerprintDrifted) {
                $nextRev = max($currentRequested, $startingRevision + 1);
                $row->processed_revision = max((int) $row->processed_revision, $startingRevision);
                $row->last_reconciled_fingerprint = $reconciledFingerprint;
                $row->last_reconciled_at = $now;
                $row->stock_sync_required = false;
                $row->stock_sync_completed_revision = max((int) $row->stock_sync_completed_revision, $startingRevision);

                $enqueue = $this->transitionToPendingAndEnqueue(
                    $row,
                    $meliOrderId,
                    $nextRev,
                    $liveFingerprint,
                    $queue,
                    $now,
                    'followup_after_dirty_processing',
                );

                return [
                    'needs_followup' => true,
                    'enqueued_atomically' => $enqueue['enqueued_atomically'],
                    'needs_after_commit_followup' => $enqueue['should_dispatch_after_commit'],
                    'pending_job_id' => $enqueue['pending_job_id'],
                    'next_revision' => $nextRev,
                    'stock_sync_jobs_enqueued_atomically' => $atomicStockCount,
                ];
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'processed_revision' => max((int) $row->processed_revision, $startingRevision),
                'last_state_fingerprint' => $reconciledFingerprint,
                'last_reconciled_fingerprint' => $reconciledFingerprint,
                'last_reconciled_at' => $now,
                'stock_sync_required' => false,
                'stock_sync_completed_revision' => max((int) $row->stock_sync_completed_revision, $startingRevision),
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error' => null,
            ])->save();

            return [
                'needs_followup' => false,
                'enqueued_atomically' => false,
                'needs_after_commit_followup' => false,
                'pending_job_id' => null,
                'next_revision' => $currentRequested,
                'stock_sync_jobs_enqueued_atomically' => $atomicStockCount,
            ];
        });
    }

    /**
     * Releases a PENDING row when a job skips BEFORE claiming a lease (e.g. automation disabled,
     * outside cutover, or local order missing). NEVER touches an active PROCESSING lease or a newer revision.
     */
    public function releasePendingIfUnowned(int $meliOrderId, ?int $targetRevision = null): void
    {
        if (! $this->hasStateTable()) {
            return;
        }

        DB::transaction(function () use ($meliOrderId, $targetRevision): void {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->state !== InventoryMeliOrderReconciliation::STATE_PENDING) {
                return;
            }

            if ($targetRevision !== null && (int) $row->requested_revision > $targetRevision) {
                return;
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();
        });
    }

    /**
     * Releases a PROCESSING lease owned by this exact worker + fencing token when skipping cleanly
     * without reconciling (does NOT advance last_reconciled_fingerprint).
     */
    public function releaseOwnedLease(int $meliOrderId, string $leaseOwner, int $fencingToken): void
    {
        if (! $this->hasStateTable()) {
            return;
        }

        DB::transaction(function () use ($meliOrderId, $leaseOwner, $fencingToken): void {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row
                || $row->state !== InventoryMeliOrderReconciliation::STATE_PROCESSING
                || $row->lease_owner !== $leaseOwner
                || (int) $row->fencing_token !== $fencingToken) {
                return;
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();
        });
    }

    /**
     * Marks execution as FAILED ONLY if this exact leaseOwner + fencingToken still owns the row.
     * Preserves `pending_job_id` when Laravel still has queued retry attempts remaining for this job.
     */
    public function markFailedForOwner(
        int $meliOrderId,
        string $leaseOwner,
        int $fencingToken,
        string $errorMessage,
        ?int $retryJobId = null,
    ): bool {
        if (! $this->hasStateTable()) {
            return false;
        }

        $sanitized = $this->sanitizeErrorMessage($errorMessage);

        return DB::transaction(function () use ($meliOrderId, $leaseOwner, $fencingToken, $sanitized, $retryJobId): bool {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row
                || $row->state !== InventoryMeliOrderReconciliation::STATE_PROCESSING
                || $row->lease_owner !== $leaseOwner
                || (int) $row->fencing_token !== $fencingToken) {
                return false;
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'pending_job_id' => $retryJobId !== null && $retryJobId > 0 ? $retryJobId : null,
                'dispatch_confirmed_at' => $retryJobId !== null && $retryJobId > 0 ? now() : null,
                'last_error' => $sanitized,
            ])->save();

            return true;
        });
    }

    /**
     * Terminal `failed()` callback fallback when no live leaseOwner/fencingToken exists on the job
     * instance (e.g., worker killed on timeout/max-attempts and Laravel instantiates a fresh job).
     * NEVER overwrites an active PROCESSING lease, an already-IDLE reconciled order, or a newer PENDING job.
     */
    public function markTerminalFailureIfUnowned(
        int $meliOrderId,
        ?int $targetRevision,
        string $errorMessage,
    ): bool {
        if (! $this->hasStateTable()) {
            return false;
        }

        $sanitized = $this->sanitizeErrorMessage($errorMessage);

        return DB::transaction(function () use ($meliOrderId, $targetRevision, $sanitized): bool {
            $now = now();

            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return false;
            }

            // Never clobber an already reconciled IDLE state.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_IDLE) {
                return false;
            }

            // Never clobber an active non-expired PROCESSING lease owned by another worker.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING
                && $row->lease_expires_at !== null
                && $row->lease_expires_at->isFuture()) {
                return false;
            }

            // Never clobber a newer PENDING revision or a PENDING row whose job is still queued in `jobs`.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_PENDING) {
                if ($targetRevision !== null && (int) $row->requested_revision > $targetRevision) {
                    return false;
                }

                if ($row->pending_job_id !== null && $this->databaseJobExists((int) $row->pending_job_id)) {
                    return false;
                }
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'last_error' => $sanitized,
            ])->save();

            return true;
        });
    }

    /**
     * Marks an outbox after-commit enqueue failure ONLY if the row is still PENDING for the exact
     * failed revision and has not yet been confirmed or superseded by a newer revision/worker.
     */
    public function markEnqueueFailedForRevision(
        int $meliOrderId,
        int $failedRevision,
        string $errorMessage,
    ): bool {
        if (! $this->hasStateTable()) {
            return false;
        }

        $sanitized = $this->sanitizeErrorMessage($errorMessage);

        return DB::transaction(function () use ($meliOrderId, $failedRevision, $sanitized): bool {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row
                || $row->state !== InventoryMeliOrderReconciliation::STATE_PENDING
                || $row->dispatch_confirmed_at !== null
                || (int) $row->requested_revision !== $failedRevision
                || (int) $row->last_enqueued_revision !== $failedRevision) {
                return false;
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'pending_job_id' => null,
                'dispatch_confirmed_at' => null,
                'last_error' => $sanitized,
            ])->save();

            return true;
        });
    }

    public function sanitizeErrorMessage(string $message): string
    {
        $patterns = [
            '/(authorization)\s*[:=]\s*bearer\s+[^\s,;&]+/iu' => '$1: Bearer [redacted]',
            '/\bbearer\s+[A-Za-z0-9\-._~+\/]+=*/iu' => 'Bearer [redacted]',
            '/(access_token|refresh_token|client_secret|api_key|password|secret|token)\s*[:=]\s*(?:bearer\s+)?[^\s,;&"\']+/iu' => '$1=[redacted]',
            '/([?&](?:access_token|refresh_token|token|authorization|secret|password|api_key)=)([^&#\s]+)/iu' => '$1[redacted]',
        ];

        $sanitized = preg_replace(array_keys($patterns), array_values($patterns), $message);
        $clean = trim((string) ($sanitized ?? ''));

        return mb_substr($clean !== '' ? $clean : 'reconciliation failed', 0, 500);
    }

    /**
     * @return array{
     *     should_dispatch: bool,
     *     enqueued_atomically: bool,
     *     should_dispatch_after_commit: bool,
     *     pending_job_id: ?int,
     *     reason: string,
     *     revision: int,
     *     fingerprint: string
     * }
     */
    private function transitionToPendingAndEnqueue(
        InventoryMeliOrderReconciliation $row,
        int $localId,
        int $nextRevision,
        string $currentFingerprint,
        string $queue,
        \Illuminate\Support\Carbon $now,
        string $reason,
    ): array {
        $canAtomicPush = $this->canAtomicPushToDatabaseQueue();
        $pushedJobId = null;

        if ($canAtomicPush) {
            $queueConnectionName = (string) config('queue.default', 'database');
            $job = new ReconcileInventoryMeliOrderReservationsJob($localId, $nextRevision);
            $rawId = Queue::connection($queueConnectionName)->pushOn($queue, $job);
            $pushedJobId = is_numeric($rawId) && (int) $rawId > 0 ? (int) $rawId : null;
        }

        $row->forceFill([
            'state' => InventoryMeliOrderReconciliation::STATE_PENDING,
            'requested_revision' => $nextRevision,
            'last_enqueued_revision' => $nextRevision,
            'last_state_fingerprint' => $currentFingerprint,
            'pending_job_id' => $pushedJobId,
            'dispatch_confirmed_at' => $canAtomicPush ? $now : null,
            'queued_at' => $now,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'last_error' => null,
        ])->save();

        return [
            'should_dispatch' => true,
            'enqueued_atomically' => $canAtomicPush,
            'should_dispatch_after_commit' => ! $canAtomicPush,
            'pending_job_id' => $pushedJobId,
            'reason' => $reason,
            'revision' => $nextRevision,
            'fingerprint' => $currentFingerprint,
        ];
    }

    /**
     * Bounded, idempotent recovery sweeper for ALL stuck reconciliation obligations without requiring
     * any future syncs for the affected orders and without scanning `jobs` payloads (H11):
     * 1. PENDING with unconfirmed outbox (`dispatch_confirmed_at IS NULL` past grace) OR missing `pending_job_id` in `jobs` (O(1) PK check).
     * 2. PROCESSING whose lease has expired (`lease_expires_at <= now()`), e.g. SIGKILL/OOM mid-flight.
     * 3. FAILED whose retry job vanished from `jobs` with `attempts < $maxAutoRecoveryAttempts` and pending obligation.
     *
     * @return array{
     *     scanned: int,
     *     recovered_pending: int,
     *     recovered_expired_processing: int,
     *     recovered_failed: int,
     *     skipped: int
     * }
     */
    public function recoverStuckReconciliations(
        int $limit = 100,
        ?int $graceSeconds = null,
        int $maxAutoRecoveryAttempts = 8,
        ?string $queueName = null,
    ): array {
        $this->assertStateTableExists();

        $limit = max(1, min(1000, $limit));
        $grace = max(0, (int) ($graceSeconds ?? config('inventory.meli_order_reservations.outbox_grace_seconds', self::DEFAULT_OUTBOX_GRACE_SECONDS)));
        $now = now();
        $cutoff = $now->copy()->subSeconds($grace);
        $queue = $queueName ?? (string) config('inventory.meli_order_reservations.queue', 'meli');

        $candidates = InventoryMeliOrderReconciliation::query()
            ->where(function ($q) use ($now, $cutoff, $maxAutoRecoveryAttempts): void {
                // 1. PENDING unconfirmed outbox or PENDING carrying a database pending_job_id to verify in O(1).
                $q->where(function ($p) use ($cutoff): void {
                    $p->where('state', InventoryMeliOrderReconciliation::STATE_PENDING)
                        ->where(function ($p2) use ($cutoff): void {
                            $p2->where(function ($unconfirmed) use ($cutoff): void {
                                $unconfirmed->whereNull('dispatch_confirmed_at')
                                    ->where(function ($q2) use ($cutoff): void {
                                        $q2->whereNull('queued_at')->orWhere('queued_at', '<=', $cutoff);
                                    });
                            })->orWhereNotNull('pending_job_id');
                        });
                })
                    // 2. PROCESSING with expired lease.
                    ->orWhere(function ($proc) use ($now): void {
                        $proc->where('state', InventoryMeliOrderReconciliation::STATE_PROCESSING)
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $now);
                    })
                    // 3. FAILED with bounded retry budget.
                    ->orWhere(function ($fail) use ($maxAutoRecoveryAttempts): void {
                        $fail->where('state', InventoryMeliOrderReconciliation::STATE_FAILED)
                            ->where('attempts', '<', max(1, $maxAutoRecoveryAttempts));
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('meli_order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $recoveredPending = 0;
        $recoveredExpiredProcessing = 0;
        $recoveredFailed = 0;
        $skipped = 0;

        foreach ($candidates as $orderId) {
            $decision = DB::transaction(function () use ($orderId, $maxAutoRecoveryAttempts, $queue): array {
                $txNow = now();

                // Global lock ordering: meli_orders -> inventory_meli_order_reconciliations -> jobs.
                $order = MeliOrder::query()->whereKey($orderId)->lockForUpdate()->first();
                $row = InventoryMeliOrderReconciliation::query()
                    ->where('meli_order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $row || $row->state === InventoryMeliOrderReconciliation::STATE_IDLE) {
                    return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                }

                if (! $order) {
                    $row->forceFill([
                        'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                        'pending_job_id' => null,
                        'dispatch_confirmed_at' => null,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                    ])->save();

                    return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                }

                $order->load('items');
                $liveFingerprint = $this->fingerprint->forOrder($order);

                if ($row->state === InventoryMeliOrderReconciliation::STATE_PENDING) {
                    if (! $this->isPendingDispatchMissing($row, $txNow)) {
                        return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                    }

                    $rev = max(1, (int) $row->requested_revision);
                    $res = $this->transitionToPendingAndEnqueue($row, $orderId, $rev, $liveFingerprint, $queue, $txNow, 'sweeper_recovered_pending');

                    return ['bucket' => 'pending', 'needs_after_commit' => $res['should_dispatch_after_commit'], 'revision' => $rev];
                }

                if ($row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING) {
                    if ($row->lease_expires_at === null || $row->lease_expires_at->isFuture()) {
                        return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                    }

                    $rev = max(1, (int) $row->requested_revision);
                    $res = $this->transitionToPendingAndEnqueue($row, $orderId, $rev, $liveFingerprint, $queue, $txNow, 'sweeper_reclaimed_expired_processing');

                    return ['bucket' => 'processing', 'needs_after_commit' => $res['should_dispatch_after_commit'], 'revision' => $rev];
                }

                if ($row->state === InventoryMeliOrderReconciliation::STATE_FAILED) {
                    if ((int) $row->attempts >= max(1, $maxAutoRecoveryAttempts)) {
                        return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                    }

                    // If Laravel's retry job is still queued in `jobs`, do NOT duplicate it!
                    if ($row->pending_job_id !== null && $this->databaseJobExists((int) $row->pending_job_id)) {
                        return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                    }

                    $hasObligation = (bool) $row->stock_sync_required
                        || (int) $row->requested_revision > (int) $row->processed_revision
                        || $row->last_reconciled_fingerprint === null
                        || ! hash_equals((string) $row->last_reconciled_fingerprint, $liveFingerprint);

                    if (! $hasObligation) {
                        return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
                    }

                    $rev = max(1, (int) $row->requested_revision);
                    $res = $this->transitionToPendingAndEnqueue($row, $orderId, $rev, $liveFingerprint, $queue, $txNow, 'sweeper_recovered_failed');

                    return ['bucket' => 'failed', 'needs_after_commit' => $res['should_dispatch_after_commit'], 'revision' => $rev];
                }

                return ['bucket' => null, 'needs_after_commit' => false, 'revision' => 0];
            });

            if ($decision['bucket'] === null) {
                $skipped++;

                continue;
            }

            if ($decision['needs_after_commit']) {
                $rev = (int) $decision['revision'];
                try {
                    $this->invokeAfterCommitOutboxHook($orderId, $rev, 'stuck_recovery');
                    ReconcileInventoryMeliOrderReservationsJob::dispatch($orderId, $rev)->onQueue($queue);
                    $this->confirmOutboxDispatch($orderId, $rev);
                } catch (Throwable $exception) {
                    $this->markEnqueueFailedForRevision($orderId, $rev, $exception->getMessage());
                    Log::error('Inventory Meli order reservation stuck recovery failed', [
                        'meli_order_id' => $orderId,
                        'revision' => $rev,
                        'exception' => $exception::class,
                        'message' => $this->sanitizeErrorMessage($exception->getMessage()),
                    ]);
                    $skipped++;

                    continue;
                }
            }

            match ($decision['bucket']) {
                'pending' => $recoveredPending++,
                'processing' => $recoveredExpiredProcessing++,
                'failed' => $recoveredFailed++,
                default => null,
            };
        }

        return [
            'scanned' => count($candidates),
            'recovered_pending' => $recoveredPending,
            'recovered_expired_processing' => $recoveredExpiredProcessing,
            'recovered_failed' => $recoveredFailed,
            'skipped' => $skipped,
        ];
    }

    public function canAtomicPushToDatabaseQueue(): bool
    {
        if (! self::$allowSameDbAtomicPush) {
            return false;
        }

        if (app(BusDispatcher::class) instanceof BusFake) {
            return false;
        }

        if (Queue::getFacadeRoot() instanceof QueueFake) {
            return false;
        }

        $queueConnectionName = (string) config('queue.default', 'sync');
        $driver = config("queue.connections.{$queueConnectionName}.driver");
        if ($driver !== 'database') {
            return false;
        }

        if ((bool) config("queue.connections.{$queueConnectionName}.after_commit", false)) {
            return false;
        }

        $queueDbConnection = config("queue.connections.{$queueConnectionName}.connection")
            ?? config('database.default');
        $stateDbConnection = (new InventoryMeliOrderReconciliation)->getConnectionName()
            ?? config('database.default');

        if ($queueDbConnection !== $stateDbConnection) {
            return false;
        }

        $jobsTable = (string) config("queue.connections.{$queueConnectionName}.table", 'jobs');

        return Schema::connection($queueDbConnection)->hasTable($jobsTable);
    }

    private function isPendingDispatchMissing(
        InventoryMeliOrderReconciliation $row,
        \Illuminate\Support\Carbon $now,
    ): bool {
        // O(1) primary-key existence check when pending_job_id points to a database `jobs` row.
        if ($row->pending_job_id !== null && $this->usesDatabaseQueueTable()) {
            if (! $this->databaseJobExists((int) $row->pending_job_id)) {
                return true;
            }
        }

        if ($row->dispatch_confirmed_at === null) {
            $grace = max(0, (int) config('inventory.meli_order_reservations.outbox_grace_seconds', self::DEFAULT_OUTBOX_GRACE_SECONDS));
            if ($row->queued_at === null || $row->queued_at->lte($now->copy()->subSeconds($grace))) {
                return true;
            }
        }

        return false;
    }

    private function usesDatabaseQueueTable(): bool
    {
        $queueConnectionName = (string) config('queue.default', 'sync');
        if (config("queue.connections.{$queueConnectionName}.driver") !== 'database') {
            return false;
        }

        $queueDbConnection = config("queue.connections.{$queueConnectionName}.connection")
            ?? config('database.default');
        $jobsTable = (string) config("queue.connections.{$queueConnectionName}.table", 'jobs');

        return Schema::connection($queueDbConnection)->hasTable($jobsTable);
    }

    private function databaseJobExists(int $jobId): bool
    {
        if ($jobId <= 0 || ! $this->usesDatabaseQueueTable()) {
            return false;
        }

        $queueConnectionName = (string) config('queue.default', 'sync');
        $queueDbConnection = config("queue.connections.{$queueConnectionName}.connection")
            ?? config('database.default');
        $jobsTable = (string) config("queue.connections.{$queueConnectionName}.table", 'jobs');

        return DB::connection($queueDbConnection)
            ->table($jobsTable)
            ->where('id', $jobId)
            ->exists();
    }
}
