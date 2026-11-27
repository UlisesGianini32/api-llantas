<?php

namespace App\Services\Inventory\Dispatches;

use App\Exceptions\WarehouseDispatchesDisabledException;
use App\Exceptions\WarehouseDispatchReadOnlyException;

class WarehouseDispatchFeatureGuard
{
    public function isEnabled(): bool
    {
        return (bool) config('inventory.warehouse_dispatches.enabled', false);
    }

    public function isReadOnly(): bool
    {
        return (bool) config('inventory.warehouse_dispatches.read_only', false);
    }

    public function isApplyLedgerEnabled(): bool
    {
        return (bool) config('inventory.warehouse_dispatches.apply_ledger', false);
    }

    public function retroactiveToleranceHours(): int
    {
        return max(1, (int) config('inventory.warehouse_dispatches.retroactive_tolerance_hours', 24));
    }

    public function allowKits(): bool
    {
        return false;
    }

    public function isMeliSyncEnabled(): bool
    {
        // Strictly disabled throughout Phase 1 (fail-closed).
        return false;
    }

    public function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new WarehouseDispatchesDisabledException;
        }
    }

    public function assertWritable(): void
    {
        $this->assertEnabled();

        if ($this->isReadOnly()) {
            throw new WarehouseDispatchReadOnlyException;
        }
    }

    public function operatingMode(): string
    {
        if (! $this->isEnabled()) {
            return 'DISABLED';
        }

        if ($this->isReadOnly()) {
            return 'READ_ONLY';
        }

        return $this->isApplyLedgerEnabled()
            ? 'APPLY_LEDGER'
            : 'RECORD_ONLY';
    }
}
