<?php

namespace App\Services\Autopartes\Meli;

use App\Models\AutomotivePart;
use App\Models\AutomotivePartMeliCategoryCandidate;
use App\Models\AutomotivePartMeliReadiness;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AutomotivePartMeliReviewService
{
    public function __construct(
        private AutomotivePartMeliCategorySyncService $categories,
        private AutomotivePartMeliReadinessService $readiness,
    ) {}

    public function approve(
        AutomotivePartMeliCategoryCandidate $candidate,
        User $user,
        ?string $notes = null,
        bool $refresh = false,
    ): AutomotivePartMeliReadiness {
        if ($candidate->status !== 'pending') {
            throw new AutomotivePartMeliException('Solo puede aprobarse un candidato pendiente.', 'candidate_not_pending');
        }

        $category = $this->categories->syncAttributes($candidate->category_id, $refresh);

        DB::transaction(function () use ($candidate, $category, $user, $notes) {
            $locked = AutomotivePartMeliCategoryCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($category->category_id !== $locked->category_id) {
                throw new AutomotivePartMeliException('La categoría oficial no coincide con el candidato.', 'category_id_mismatch');
            }

            AutomotivePartMeliCategoryCandidate::query()
                ->where('automotive_part_id', $locked->automotive_part_id)
                ->where('id', '!=', $locked->id)
                ->whereIn('status', ['pending', 'approved'])
                ->update(['status' => 'superseded']);

            $locked->forceFill([
                'status' => 'approved',
                'category_name' => $category->name,
                'domain_id' => $category->domain_id,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();
        });

        return $this->readiness->evaluate($candidate->automotivePart()->firstOrFail(), $refresh);
    }

    public function reject(AutomotivePartMeliCategoryCandidate $candidate, User $user, string $notes): void
    {
        if ($candidate->status !== 'pending') {
            throw new AutomotivePartMeliException('Solo puede rechazarse un candidato pendiente.', 'candidate_not_pending');
        }

        if (trim($notes) === '') {
            throw new AutomotivePartMeliException('Se requiere una nota para rechazar el candidato.', 'missing_rejection_note');
        }

        $candidate->forceFill([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        $this->readiness->evaluate($candidate->automotivePart()->firstOrFail());
    }

    public function createManualCandidate(
        AutomotivePart $part,
        string $categoryId,
        User $user,
        ?string $notes = null,
        bool $refresh = false,
    ): AutomotivePartMeliCategoryCandidate {
        $category = $this->categories->syncCategory($categoryId, $refresh);

        $candidate = AutomotivePartMeliCategoryCandidate::query()->create([
            'automotive_part_id' => $part->id,
            'automotive_part_enrichment_review_id' => $part->enrichmentReview?->id,
            'status' => 'pending',
            'category_id' => $category->category_id,
            'category_name' => $category->name,
            'domain_id' => $category->domain_id,
            'source' => 'manual',
            'query_text' => null,
            'position' => null,
            'score' => null,
            'evidence' => [
                'entered_by' => $user->id,
                'validated_at' => now()->toIso8601String(),
                'site_id' => $category->site_id,
            ],
            'raw_payload' => $category->raw_payload,
            'review_notes' => $notes,
        ]);

        $this->readiness->evaluate($part);

        return $candidate;
    }
}
