<?php

namespace App\Services;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;

class InventoryMeliStockPilotService
{
    public const READY = 'READY';

    public const PREFLIGHT_FAILED = 'PREFLIGHT_FAILED';

    public const SYNC_DISABLED = 'SYNC_DISABLED';

    public const LOCKED = 'LOCKED';

    public const VERIFIED = InventoryChannelStockSync::VERIFIED;

    public const MISMATCH = InventoryChannelStockSync::MISMATCH;

    public const UNVERIFIED = InventoryChannelStockSync::UNVERIFIED;

    public const CONFIRMATION_MISMATCH = InventoryMeliStockSyncService::CONFIRMATION_MISMATCH;

    public function __construct(
        private readonly InventoryMeliStockSyncService $sync,
        private readonly InventoryMeliRemoteStockService $remote,
        private readonly InventoryMeliStockOwnershipService $ownership,
        private readonly InventoryMeliSharedStockGroupService $groups,
    ) {}

    /** @return array<string,mixed> */
    public function preview(InventoryChannelLink|int $link): array
    {
        $link = $link instanceof InventoryChannelLink
            ? $link->fresh(['product'])
            : InventoryChannelLink::query()->with('product')->findOrFail($link);
        $row = collect($this->sync->preview(['link' => $link->getKey()])['rows'])->first()
            ?? ['id' => $link->id, 'status' => InventoryMeliStockSyncService::UNSUPPORTED];
        $ownership = $this->ownership->inspect($link);
        $lastSuccess = $link->stockSyncs()
            ->where('status', InventoryChannelStockSync::SUCCESS)
            ->latest('id')
            ->first();
        $remote = null;
        $eligibility = $row['status'];

        if ($eligibility === InventoryMeliStockSyncService::READY
            && $ownership['status'] !== InventoryMeliStockOwnershipService::BLOCKED_LEGACY_VARIATION_OWNERSHIP) {
            $remote = $this->remote->read($link, $ownership['account']);
            if ($remote['status'] !== InventoryMeliRemoteStockService::OK) {
                $eligibility = self::PREFLIGHT_FAILED;
            }
        } elseif ($eligibility === InventoryMeliStockSyncService::READY) {
            $eligibility = $ownership['status'];
        }

        $link->refresh();
        $grouped = $this->groups->isGrouped($link);
        $siblings = $grouped ? $this->groups->links($link, true) : collect([$link]);
        $representative = $grouped ? ($siblings->first() ?? $link) : $link;
        $conflict = $grouped ? $this->groups->conflict($link) : ['conflict' => false, 'link_ids' => [], 'product_ids' => []];
        if ($conflict['conflict'] && $eligibility === InventoryMeliStockSyncService::READY) {
            $eligibility = InventoryMeliStockSyncService::REMOTE_USER_PRODUCT_CONFLICT;
        }

        $remoteQuantity = $remote['quantity'] ?? null;
        $lastTarget = $lastSuccess?->target_quantity;

        $remoteDrift = $lastTarget !== null && $remoteQuantity !== null && (int) $lastTarget !== (int) $remoteQuantity;
        $explainedDrift = $remoteDrift && $grouped && $this->hasRecentExplainingGroupWrite($link, $lastSuccess, (int) $remoteQuantity);

        return [
            ...$row,
            'remote_user_product_id' => $link->remote_user_product_id,
            'shared_stock_group' => $grouped ? $link->remote_user_product_id : null,
            'sibling_link_ids' => $grouped ? $siblings->pluck('id')->map(fn ($id) => (int) $id)->all() : [],
            'representative_link_id' => $grouped ? (int) ($siblings->first()?->id ?? $link->id) : (int) $link->id,
            'requested_identity' => $this->identity($link),
            'write_identity' => $this->identity($representative),
            'remote_user_product_conflict' => $conflict['conflict'],
            'conflict_link_ids' => $conflict['link_ids'],
            'conflict_product_ids' => $conflict['product_ids'],
            'identity' => $this->identity($link),
            'simple_or_kit' => $row['product_type'] ?? null,
            'physical' => $row['physical'] ?? null,
            'reserved' => $row['reserved'] ?? null,
            'available' => $row['available'] ?? null,
            'target' => $row['target'] ?? null,
            'remote_current_quantity' => $remoteQuantity,
            'delta' => $remoteQuantity === null || ! isset($row['target']) ? null : (int) $row['target'] - (int) $remoteQuantity,
            'stock_sync_enabled' => (bool) ($row['stock_sync_enabled'] ?? false),
            'last_success_target' => $lastTarget,
            'last_remote_before' => $lastSuccess?->previous_known_quantity,
            'verified_quantity' => $lastSuccess?->verified_quantity,
            'verification_status' => $lastSuccess?->verification_status,
            'verified_at' => $lastSuccess?->verified_at?->toISOString(),
            'remote_drift' => $remoteDrift && ! $explainedDrift,
            'remote_drift_explained' => $explainedDrift,
            'legacy_writer_detected' => $ownership['legacy_writer_detected'],
            'legacy_conflict' => $link->is_active && $link->stock_sync_enabled && $ownership['legacy_writer_detected'],
            'legacy_sources' => $ownership['legacy_sources'],
            'legacy_ownership_status' => $ownership['status'],
            'remote_status' => $remote['status'] ?? null,
            'remote_http_status' => $remote['http_status'] ?? null,
            'eligibility' => $eligibility,
            'preflight_error' => $remote['error'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    public function apply(InventoryChannelLink|int $link, ?int $userId = null, ?string $confirmedWriteIdentity = null): array
    {
        $preview = $this->preview($link);
        if ($preview['eligibility'] !== self::READY) {
            $writeStatus = $preview['eligibility'] === InventoryMeliStockSyncService::SKIPPED_SYNC_DISABLED
                ? self::SYNC_DISABLED
                : $preview['eligibility'];

            return [...$preview, 'write_status' => $writeStatus, 'verification_status' => null];
        }

        $link = $link instanceof InventoryChannelLink
            ? $link->fresh(['product'])
            : InventoryChannelLink::query()->with('product')->findOrFail($link);
        $requestedLinkId = (int) $link->id;
        $link->refresh();
        $conflict = $this->groups->conflict($link);
        if ($conflict['conflict']) {
            return [...$preview, 'write_status' => InventoryMeliStockSyncService::REMOTE_USER_PRODUCT_CONFLICT, 'verification_status' => null, ...$conflict];
        }
        $link = $this->groups->representative($link)->fresh(['product']);
        $preview['representative_link_id'] = (int) $link->id;
        $preview['write_identity'] = $this->identity($link);
        $lock = $this->groups->lock($link);
        if (! $lock->get()) {
            return [...$preview, 'eligibility' => self::LOCKED, 'write_status' => self::LOCKED, 'verification_status' => null];
        }

        try {
            $fresh = $this->previewWithoutRemote($link);
            if (($fresh['eligibility'] ?? null) !== self::READY) {
                $writeStatus = ($fresh['eligibility'] ?? null) === InventoryMeliStockSyncService::SKIPPED_SYNC_DISABLED
                    ? self::SYNC_DISABLED
                    : ($fresh['eligibility'] ?? self::PREFLIGHT_FAILED);

                return [...$fresh, 'write_status' => $writeStatus, 'verification_status' => null];
            }

            $result = $this->sync->syncLinkUnderExistingLock(
                $link,
                $userId,
                'pilot',
                $this->groups->isGrouped($link) ? null : (is_numeric($preview['remote_current_quantity'] ?? null) ? (int) $preview['remote_current_quantity'] : null),
                $requestedLinkId,
                $confirmedWriteIdentity,
            );
            if ($result['status'] !== InventoryChannelStockSync::SUCCESS) {
                if ($result['status'] === InventoryMeliStockSyncService::NO_CHANGE) {
                    return [...$preview, ...$result, 'write_status' => InventoryMeliStockSyncService::NO_CHANGE, 'verification_status' => InventoryChannelStockSync::VERIFIED];
                }
                return [...$preview, 'write_status' => $result['status'], 'verification_status' => null, 'reason' => $result['reason'] ?? null];
            }

            $after = $this->remote->read($link);
            $verificationStatus = match (true) {
                $after['status'] !== InventoryMeliRemoteStockService::OK => self::UNVERIFIED,
                (int) $after['quantity'] === (int) $result['target'] => self::VERIFIED,
                default => self::MISMATCH,
            };
            $audit = InventoryChannelStockSync::query()->find($result['audit_id'] ?? null);
            if ($audit) {
                $siblingVerification = [];
                foreach ($this->groups->links($link, true)->where('id', '!=', $link->id) as $sibling) {
                    $siblingRemote = $this->remote->read($sibling);
                    $siblingVerification[] = [
                        'link_id' => (int) $sibling->id,
                        'status' => $siblingRemote['status'] === InventoryMeliRemoteStockService::OK
                            ? ((int) $siblingRemote['quantity'] === (int) $result['target'] ? 'PROPAGATED' : 'PENDING_OR_MISMATCH')
                            : 'UNVERIFIED',
                        'quantity' => $siblingRemote['quantity'],
                    ];
                }
                $audit->forceFill([
                    'verified_quantity' => $after['quantity'],
                    'verification_status' => $verificationStatus,
                    'verified_at' => now(),
                    'metadata' => array_merge((array) $audit->metadata, ['pilot' => true, 'representative' => true, 'sibling_verification' => $siblingVerification]),
                ])->save();
            }

            return [
                ...$preview,
                ...$result,
                'write_status' => InventoryChannelStockSync::SUCCESS,
                'verified_quantity' => $after['quantity'],
                'verification_status' => $verificationStatus,
                'verification_error' => $after['error'] ?? null,
            ];
        } finally {
            $lock->release();
        }
    }

    public function identity(InventoryChannelLink $link): string
    {
        return filled($link->external_variant_id)
            ? $link->external_listing_id.':'.$link->external_variant_id
            : (string) $link->external_listing_id;
    }

    /** @return array<string,mixed> */
    private function previewWithoutRemote(InventoryChannelLink $link): array
    {
        $row = collect($this->sync->preview(['link' => $link->getKey()])['rows'])->first()
            ?? ['id' => $link->id, 'status' => InventoryMeliStockSyncService::UNSUPPORTED];
        $ownership = $this->ownership->inspect($link);
        $eligibility = $row['status'];
        if ($eligibility === InventoryMeliStockSyncService::READY
            && $ownership['status'] === InventoryMeliStockOwnershipService::BLOCKED_LEGACY_VARIATION_OWNERSHIP) {
            $eligibility = $ownership['status'];
        }

        return [
            ...$row,
            'identity' => $this->identity($link),
            'eligibility' => $eligibility,
            'legacy_writer_detected' => $ownership['legacy_writer_detected'],
            'legacy_conflict' => $link->is_active && $link->stock_sync_enabled && $ownership['legacy_writer_detected'],
            'legacy_sources' => $ownership['legacy_sources'],
            'legacy_ownership_status' => $ownership['status'],
        ];
    }

    private function hasRecentExplainingGroupWrite(InventoryChannelLink $link, ?InventoryChannelStockSync $lastSuccess, int $remoteQuantity): bool
    {
        $query = InventoryChannelStockSync::query()
            ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
            ->where('account_key', $link->account_key)
            ->where('status', InventoryChannelStockSync::SUCCESS)
            ->where('target_quantity', $remoteQuantity)
            ->where(function ($query): void {
                $query->whereNull('metadata->put_skipped')->orWhere('metadata->put_skipped', false);
            })
            ->whereHas('link', fn ($links) => $links
                ->where('channel', InventoryChannelLink::MERCADO_LIBRE)
                ->where('account_key', $link->account_key)
                ->where('remote_user_product_id', $link->remote_user_product_id)
                ->whereNull('external_variant_id'));
        if ($lastSuccess?->finished_at) {
            $query->where('finished_at', '>', $lastSuccess->finished_at);
        }

        return $query->where('finished_at', '>=', now()->subDay())->exists();
    }
}
