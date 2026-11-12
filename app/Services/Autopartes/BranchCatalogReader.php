<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotivePart;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Internal read boundary only for the isolated Autopartes catalog. */
final class BranchCatalogReader
{
    public function __construct(
        private BranchCatalogAccess $access,
        private AutomotiveStockService $stock,
    ) {}

    public function parts(User $user, AutomotiveBranch $branch, int $page = 1, bool $onlyActive = false): LengthAwarePaginator
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::CATALOG_VIEW);

        $query = AutomotivePart::query()
            ->select(['id', 'code', 'item_number', 'manufacturer_part_number', 'description', 'identity_status', 'is_active'])
            ->orderBy('id');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return $query->paginate(50, ['*'], 'page', max(1, $page))
            ->through(fn (AutomotivePart $part): array => [
                'id' => (int) $part->getKey(),
                'code' => (string) $part->code,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'description' => $part->description,
                'identity_status' => (string) $part->identity_status,
                'is_active' => (bool) $part->is_active,
            ]);
    }

    public function physicalStock(User $user, AutomotiveBranch $branch, int $automotivePartId): int
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::STOCK_VIEW);
        $part = AutomotivePart::query()->whereKey($automotivePartId)->first();

        if (! $part) {
            throw new AuthorizationException('La autoparte no pertenece al catálogo exclusivo de Autopartes.');
        }

        return $this->stock->branchPhysicalStock($part, $branch);
    }

    public function physicalStockByLocation(User $user, AutomotiveBranch $branch, int $automotivePartId, int $locationId): int
    {
        $this->access->authorize($user, $branch, BranchCatalogAccess::STOCK_VIEW);
        $part = AutomotivePart::query()->whereKey($automotivePartId)->first();

        if (! $part) {
            throw new AuthorizationException('La autoparte no pertenece al catálogo exclusivo de Autopartes.');
        }

        return $this->stock->branchPhysicalStockByLocation($part, $branch, $locationId);
    }
}
