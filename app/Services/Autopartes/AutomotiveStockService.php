<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveInventoryBalance;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Support\AutopartesConnectionGuard;
use Illuminate\Support\Collection;

class AutomotiveStockService
{
    public function branchPhysicalStock(AutomotivePart|int $part, AutomotiveBranch|int $branch): int
    {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $partId = $part instanceof AutomotivePart ? (int) $part->getKey() : (int) $part;
        $branchId = $branch instanceof AutomotiveBranch ? (int) $branch->getKey() : (int) $branch;

        return (int) AutomotiveInventoryBalance::query()
            ->where('branch_id', $branchId)
            ->where('automotive_part_id', $partId)
            ->sum('physical_quantity');
    }

    public function branchPhysicalStockByLocation(
        AutomotivePart|int $part,
        AutomotiveBranch|int $branch,
        AutomotiveLocation|int $location,
    ): int {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $partId = $part instanceof AutomotivePart ? (int) $part->getKey() : (int) $part;
        $branchId = $branch instanceof AutomotiveBranch ? (int) $branch->getKey() : (int) $branch;
        $locationId = $location instanceof AutomotiveLocation ? (int) $location->getKey() : (int) $location;

        if (! AutomotiveLocation::query()->whereKey($locationId)->where('branch_id', $branchId)->exists()) {
            return 0;
        }

        return (int) (AutomotiveInventoryBalance::query()
            ->where('branch_id', $branchId)
            ->where('automotive_part_id', $partId)
            ->where('automotive_location_id', $locationId)
            ->value('physical_quantity') ?? 0);
    }

    public function branchReservedStock(AutomotivePart|int $part, AutomotiveBranch|int $branch): int
    {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $partId = $part instanceof AutomotivePart ? (int) $part->getKey() : (int) $part;
        $branchId = $branch instanceof AutomotiveBranch ? (int) $branch->getKey() : (int) $branch;

        return (int) AutomotiveInventoryBalance::query()
            ->where('branch_id', $branchId)
            ->where('automotive_part_id', $partId)
            ->sum('reserved_quantity');
    }

    public function branchReservedStockByLocation(
        AutomotivePart|int $part,
        AutomotiveBranch|int $branch,
        AutomotiveLocation|int $location,
    ): int {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $partId = $part instanceof AutomotivePart ? (int) $part->getKey() : (int) $part;
        $branchId = $branch instanceof AutomotiveBranch ? (int) $branch->getKey() : (int) $branch;
        $locationId = $location instanceof AutomotiveLocation ? (int) $location->getKey() : (int) $location;

        if (! AutomotiveLocation::query()->whereKey($locationId)->where('branch_id', $branchId)->exists()) {
            return 0;
        }

        return (int) (AutomotiveInventoryBalance::query()
            ->where('branch_id', $branchId)
            ->where('automotive_part_id', $partId)
            ->where('automotive_location_id', $locationId)
            ->value('reserved_quantity') ?? 0);
    }

    public function branchAvailableStock(AutomotivePart|int $part, AutomotiveBranch|int $branch): int
    {
        return max(0, $this->branchPhysicalStock($part, $branch) - $this->branchReservedStock($part, $branch));
    }

    public function branchAvailableStockByLocation(
        AutomotivePart|int $part,
        AutomotiveBranch|int $branch,
        AutomotiveLocation|int $location,
    ): int {
        return max(
            0,
            $this->branchPhysicalStockByLocation($part, $branch, $location)
            - $this->branchReservedStockByLocation($part, $branch, $location)
        );
    }

    /**
     * @return Collection<int, AutomotiveInventoryBalance>
     */
    public function branchStockByLocation(AutomotivePart|int $part, AutomotiveBranch|int $branch): Collection
    {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $partId = $part instanceof AutomotivePart ? (int) $part->getKey() : (int) $part;
        $branchId = $branch instanceof AutomotiveBranch ? (int) $branch->getKey() : (int) $branch;

        return AutomotiveInventoryBalance::query()
            ->with('location:id,branch_id,code,name,is_active')
            ->where('branch_id', $branchId)
            ->where('automotive_part_id', $partId)
            ->orderBy('automotive_location_id')
            ->get();
    }

    /**
     * Bulk summary map keyed by automotive_part_id for fast catalog/POS queries without N+1.
     *
     * @param  list<int>  $partIds
     * @return array<int, array{physical: int, reserved: int, available: int}>
     */
    public function stockSummaryForPartIds(AutomotiveBranch|int $branch, array $partIds, ?int $locationId = null): array
    {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $partIds), static fn (int $id): bool => $id > 0)));
        if ($cleanIds === []) {
            return [];
        }

        $branchId = $branch instanceof AutomotiveBranch ? (int) $branch->getKey() : (int) $branch;

        $query = AutomotiveInventoryBalance::query()
            ->selectRaw('automotive_part_id, COALESCE(SUM(physical_quantity), 0) as physical_total, COALESCE(SUM(reserved_quantity), 0) as reserved_total')
            ->where('branch_id', $branchId)
            ->whereIn('automotive_part_id', $cleanIds)
            ->groupBy('automotive_part_id');

        if ($locationId !== null) {
            if (! AutomotiveLocation::query()->whereKey($locationId)->where('branch_id', $branchId)->exists()) {
                return [];
            }
            $query->where('automotive_location_id', $locationId);
        }

        $summary = [];
        foreach ($query->get() as $row) {
            $physical = max(0, (int) $row->physical_total);
            $reserved = max(0, (int) $row->reserved_total);
            $summary[(int) $row->automotive_part_id] = [
                'physical' => $physical,
                'reserved' => $reserved,
                'available' => max(0, $physical - $reserved),
            ];
        }

        return $summary;
    }
}
