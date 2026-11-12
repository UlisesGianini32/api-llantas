<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveCatalogRow;
use App\Models\AutomotiveSourceProductDecision;
use App\Models\AutopartesModel;
use App\Models\User;

final class AutomotiveSourceDecisionService
{
    public function __construct(private BranchCatalogAccess $access) {}

    public function record(
        User $actor,
        AutomotiveBranch $branch,
        int $rowId,
        string $decision,
        ?int $automotivePartId,
        string $reason,
    ): AutomotiveSourceProductDecision {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::SOURCE_REVIEW);

        return AutopartesModel::transaction(function () use ($actor, $rowId, $decision, $automotivePartId, $reason): AutomotiveSourceProductDecision {
            $row = AutomotiveCatalogRow::query()->lockForUpdate()->findOrFail($rowId);
            $previous = $row->decisions()->orderByDesc('id')->first();

            return $row->decisions()->create([
                'automotive_part_id' => $automotivePartId,
                'supersedes_id' => $previous?->id,
                'decision' => $decision,
                'reason' => $reason,
                'decided_by' => $actor->getKey(),
            ]);
        });
    }
}
