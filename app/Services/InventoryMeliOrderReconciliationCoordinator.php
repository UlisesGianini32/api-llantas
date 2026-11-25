<?php

namespace App\Services;

use App\Models\InventoryMeliOrderReconciliation;
use App\Models\MeliOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-order reconciliation coordinator (state machine + revision coalescing + bounded lease).
 *
 * Guarantees:
 * - At most 1 queued/active reconciliation job per meli_order_id regardless of how many times
 *   syncs or webhooks fire, even when Supervisor workers are down for days (zero reliance on cache TTL).
 * - Zero lost updates: material changes arriving while PENDING update the target revision/fingerprint
 *   for the queued job; material changes arriving while PROCESSING bump requested_revision so the
 *   running job deterministically schedules exactly 1 follow-up job on completion.
 * - Crash & lock-expiry recovery: a bounded lease (lease_expires_at) lets expired PROCESSING rows
 *   be cleanly reclaimed after worker crashes (SIGKILL / OOM) without deadlocking permanently.
 */
class InventoryMeliOrderReconciliationCoordinator
{
    public const DEFAULT_LEASE_SECONDS = 300;

    public function __construct(
        private readonly InventoryMeliOrderReconciliationFingerprint $fingerprint,
    ) {}

    public function hasStateTable(): bool
    {
        return Schema::hasTable('inventory_meli_order_reconciliations');
    }

    public function fingerprint(MeliOrder $order): string
    {
        return $this->fingerprint->forOrder($order);
    }

    /**
     * Registers an order sync/update and atomically decides whether a new queue job must be dispatched.
     *
     * @return array{
     *     should_dispatch: bool,
     *     reason: string,
     *     revision: int,
     *     fingerprint: string
     * }
     */
    public function registerOrderState(
        MeliOrder $order,
        ?string $previousFingerprint = null,
        bool $forceReconcile = false,
    ): array {
        $currentFingerprint = $this->fingerprint->forOrder($order);
        $localId = (int) $order->getKey();

        if ($localId <= 0) {
            return [
                'should_dispatch' => false,
                'reason' => 'invalid_order_id',
                'revision' => 0,
                'fingerprint' => $currentFingerprint,
            ];
        }

        // Graceful fallback when running in legacy minimal-schema tests without the R3 migration table.
        if (! $this->hasStateTable()) {
            if (! $forceReconcile && $previousFingerprint !== null && hash_equals($previousFingerprint, $currentFingerprint)) {
                return [
                    'should_dispatch' => false,
                    'reason' => 'no_material_change_fallback',
                    'revision' => 0,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            return [
                'should_dispatch' => true,
                'reason' => 'fallback_no_state_table',
                'revision' => 1,
                'fingerprint' => $currentFingerprint,
            ];
        }

        return DB::transaction(function () use ($order, $localId, $currentFingerprint, $forceReconcile): array {
            $now = now();

            DB::table('inventory_meli_order_reconciliations')->insertOrIgnore([
                'meli_order_id' => $localId,
                'meli_account_id' => $order->meli_account_id !== null ? (int) $order->meli_account_id : null,
                'remote_order_id' => $order->order_id !== null ? (string) $order->order_id : null,
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'requested_revision' => 0,
                'processed_revision' => 0,
                'last_enqueued_revision' => 0,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $localId)
                ->lockForUpdate()
                ->firstOrFail();

            $row->meli_account_id = $order->meli_account_id !== null ? (int) $order->meli_account_id : $row->meli_account_id;
            $row->remote_order_id = $order->order_id !== null ? (string) $order->order_id : $row->remote_order_id;

            $fingerprintChangedFromRequested = $row->last_state_fingerprint === null
                || ! hash_equals((string) $row->last_state_fingerprint, $currentFingerprint);
            $fingerprintDiffersFromReconciled = $row->last_reconciled_fingerprint === null
                || ! hash_equals((string) $row->last_reconciled_fingerprint, $currentFingerprint);
            $hasUnprocessedRevision = (int) $row->requested_revision > (int) $row->processed_revision;

            // 1. IDLE and already reconciled at this exact material fingerprint => NO-OP (0 jobs!).
            if ($row->state === InventoryMeliOrderReconciliation::STATE_IDLE
                && ! $forceReconcile
                && ! $fingerprintDiffersFromReconciled
                && ! $hasUnprocessedRevision) {
                $row->save();

                return [
                    'should_dispatch' => false,
                    'reason' => 'already_reconciled_no_material_change',
                    'revision' => (int) $row->requested_revision,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // 2. PENDING => Coalesce into the already-queued job (0 duplicate jobs even across days of downtime!).
            if ($row->state === InventoryMeliOrderReconciliation::STATE_PENDING) {
                if ($fingerprintChangedFromRequested || $forceReconcile) {
                    $row->requested_revision = (int) $row->requested_revision + 1;
                    $row->last_state_fingerprint = $currentFingerprint;
                    $row->save();

                    return [
                        'should_dispatch' => false,
                        'reason' => 'coalesced_into_pending_with_update',
                        'revision' => (int) $row->requested_revision,
                        'fingerprint' => $currentFingerprint,
                    ];
                }

                $row->save();

                return [
                    'should_dispatch' => false,
                    'reason' => 'already_pending_same_fingerprint',
                    'revision' => (int) $row->requested_revision,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // 3. PROCESSING with ACTIVE (non-expired) lease => Mark dirty so running job enqueues 1 follow-up on completion.
            if ($row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING
                && $row->lease_expires_at !== null
                && $row->lease_expires_at->isFuture()) {
                if ($fingerprintChangedFromRequested || $forceReconcile) {
                    $row->requested_revision = (int) $row->requested_revision + 1;
                    $row->last_state_fingerprint = $currentFingerprint;
                    $row->save();

                    return [
                        'should_dispatch' => false,
                        'reason' => 'marked_dirty_during_processing',
                        'revision' => (int) $row->requested_revision,
                        'fingerprint' => $currentFingerprint,
                    ];
                }

                $row->save();

                return [
                    'should_dispatch' => false,
                    'reason' => 'already_processing_same_fingerprint',
                    'revision' => (int) $row->requested_revision,
                    'fingerprint' => $currentFingerprint,
                ];
            }

            // 4. IDLE (material change / never reconciled), FAILED, or PROCESSING with EXPIRED lease => Enqueue exactly 1 job.
            $wasExpiredLease = $row->state === InventoryMeliOrderReconciliation::STATE_PROCESSING;
            $nextRevision = ($fingerprintChangedFromRequested || $forceReconcile || ! $hasUnprocessedRevision)
                ? ((int) $row->requested_revision + 1)
                : (int) $row->requested_revision;

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_PENDING,
                'requested_revision' => $nextRevision,
                'last_enqueued_revision' => $nextRevision,
                'last_state_fingerprint' => $currentFingerprint,
                'queued_at' => $now,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error' => null,
            ])->save();

            return [
                'should_dispatch' => true,
                'reason' => $wasExpiredLease ? 'reclaimed_expired_lease' : 'enqueued',
                'revision' => $nextRevision,
                'fingerprint' => $currentFingerprint,
            ];
        });
    }

    /**
     * Atomically claims execution lease for a worker processing ReconcileInventoryMeliOrderReservationsJob.
     *
     * @return array{claimed: bool, reason: string, starting_revision: int}
     */
    public function claimForExecution(int $meliOrderId, string $leaseOwner, ?int $leaseSeconds = null): array
    {
        if (! $this->hasStateTable()) {
            return [
                'claimed' => true,
                'reason' => 'fallback_no_state_table',
                'starting_revision' => 0,
            ];
        }

        $ttl = max(30, (int) ($leaseSeconds ?? config('inventory.meli_order_reservations.lease_seconds', self::DEFAULT_LEASE_SECONDS)));

        return DB::transaction(function () use ($meliOrderId, $leaseOwner, $ttl): array {
            $now = now();

            DB::table('inventory_meli_order_reconciliations')->insertOrIgnore([
                'meli_order_id' => $meliOrderId,
                'state' => InventoryMeliOrderReconciliation::STATE_PENDING,
                'requested_revision' => 1,
                'processed_revision' => 0,
                'last_enqueued_revision' => 1,
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
                ];
            }

            $startingRevision = max(1, (int) $row->requested_revision);

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_PROCESSING,
                'requested_revision' => $startingRevision,
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => $now->copy()->addSeconds($ttl),
                'attempts' => (int) $row->attempts + 1,
            ])->save();

            return [
                'claimed' => true,
                'reason' => 'claimed',
                'starting_revision' => $startingRevision,
            ];
        });
    }

    /**
     * Completes execution under row lock; if a newer material update arrived during execution,
     * transitions immediately to PENDING and signals caller to enqueue exactly 1 follow-up job.
     *
     * @return array{needs_followup: bool, next_revision: int}
     */
    public function completeExecution(
        int $meliOrderId,
        string $leaseOwner,
        int $startingRevision,
        string $reconciledFingerprint,
    ): array {
        if (! $this->hasStateTable()) {
            return ['needs_followup' => false, 'next_revision' => $startingRevision];
        }

        return DB::transaction(function () use ($meliOrderId, $leaseOwner, $startingRevision, $reconciledFingerprint): array {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->lease_owner !== $leaseOwner) {
                return ['needs_followup' => false, 'next_revision' => $startingRevision];
            }

            $now = now();
            $currentRequested = (int) $row->requested_revision;
            $fingerprintDrifted = $row->last_state_fingerprint !== null
                && ! hash_equals((string) $row->last_state_fingerprint, $reconciledFingerprint);

            if ($currentRequested > $startingRevision || $fingerprintDrifted) {
                $nextRev = max($currentRequested, $startingRevision + 1);
                $row->forceFill([
                    'state' => InventoryMeliOrderReconciliation::STATE_PENDING,
                    'requested_revision' => $nextRev,
                    'processed_revision' => max((int) $row->processed_revision, $startingRevision),
                    'last_enqueued_revision' => $nextRev,
                    'last_reconciled_fingerprint' => $reconciledFingerprint,
                    'last_reconciled_at' => $now,
                    'queued_at' => $now,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'last_error' => null,
                ])->save();

                return ['needs_followup' => true, 'next_revision' => $nextRev];
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'processed_revision' => max((int) $row->processed_revision, $startingRevision),
                'last_state_fingerprint' => $reconciledFingerprint,
                'last_reconciled_fingerprint' => $reconciledFingerprint,
                'last_reconciled_at' => $now,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error' => null,
            ])->save();

            return ['needs_followup' => false, 'next_revision' => $currentRequested];
        });
    }

    /**
     * Releases lease cleanly when a job is skipped (e.g. automation disabled, outside cutover, missing order).
     * Intentionally does NOT update last_reconciled_fingerprint so future re-enabling can reconcile cleanly.
     */
    public function releaseWithoutReconciling(int $meliOrderId, ?string $leaseOwner = null): void
    {
        if (! $this->hasStateTable()) {
            return;
        }

        DB::transaction(function () use ($meliOrderId, $leaseOwner): void {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return;
            }

            if ($leaseOwner !== null && $row->lease_owner !== null && $row->lease_owner !== $leaseOwner) {
                return;
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_IDLE,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();
        });
    }

    /**
     * Marks reconciliation state as FAILED while preserving unprocessed revision so retries/syncs can recover.
     */
    public function markFailed(int $meliOrderId, ?string $leaseOwner, string $sanitizedError): void
    {
        if (! $this->hasStateTable()) {
            return;
        }

        DB::transaction(function () use ($meliOrderId, $leaseOwner, $sanitizedError): void {
            $row = InventoryMeliOrderReconciliation::query()
                ->where('meli_order_id', $meliOrderId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return;
            }

            if ($leaseOwner !== null && $row->lease_owner !== null && $row->lease_owner !== $leaseOwner) {
                return;
            }

            $row->forceFill([
                'state' => InventoryMeliOrderReconciliation::STATE_FAILED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'last_error' => mb_substr($sanitizedError, 0, 500),
            ])->save();
        });
    }
}
