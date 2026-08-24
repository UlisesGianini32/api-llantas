<?php

namespace App\Services\Autopartes\Drafts;

use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliDraftEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AutomotivePartDraftReviewService
{
    public function __construct(
        private AutomotivePartDraftConfiguration $configuration,
        private AutomotivePartDraftBuilder $builder,
        private AutomotivePartDraftValidator $validator,
        private AutomotivePartDraftLocalOnlyGuard $localOnly,
    ) {}

    public function approve(AutomotivePartMeliDraft $draft, User $user, ?string $notes = null): AutomotivePartMeliDraft
    {
        $this->localOnly->assertLocalOperation('approve');
        $this->configuration->assertEnabled();
        $this->assertFresh($draft);

        return DB::transaction(function () use ($draft, $user, $notes) {
            $locked = AutomotivePartMeliDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if ($locked->status !== 'pending_review') {
                throw new AutomotivePartDraftException('Solo puede aprobarse un borrador pendiente de revisión.', 'draft_not_pending_review');
            }
            if ($locked->hasBlockingErrors()) {
                throw new AutomotivePartDraftException('El borrador contiene errores bloqueantes.', 'draft_has_blocking_errors');
            }

            $this->transition($locked, 'approved', 'approved', $user, $notes);
            $locked->forceFill(['approved_at' => now()])->save();

            return $locked->fresh(['events.user']);
        });
    }

    public function reject(AutomotivePartMeliDraft $draft, User $user, string $notes): AutomotivePartMeliDraft
    {
        $this->localOnly->assertLocalOperation('reject');
        if (trim($notes) === '') {
            throw new AutomotivePartDraftException('Se requiere una nota para rechazar el borrador.', 'missing_rejection_note');
        }
        $this->assertFresh($draft);

        return DB::transaction(function () use ($draft, $user, $notes) {
            $locked = AutomotivePartMeliDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if (! in_array($locked->status, ['draft', 'incomplete', 'pending_review'], true)) {
                throw new AutomotivePartDraftException('El estado actual no permite rechazar el borrador.', 'invalid_draft_transition');
            }
            $this->transition($locked, 'rejected', 'rejected', $user, $notes);

            return $locked->fresh(['events.user']);
        });
    }

    public function returnToPending(AutomotivePartMeliDraft $draft, User $user, ?string $notes = null): AutomotivePartMeliDraft
    {
        $this->localOnly->assertLocalOperation('return_to_pending');
        $preview = $this->assertFresh($draft);

        return DB::transaction(function () use ($draft, $user, $notes, $preview) {
            $locked = AutomotivePartMeliDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if (! in_array($locked->status, ['approved', 'rejected'], true)) {
                throw new AutomotivePartDraftException('Solo un borrador aprobado o rechazado puede volver a revisión.', 'invalid_draft_transition');
            }
            $target = $preview['blocking_errors'] === [] ? 'pending_review' : 'incomplete';
            $this->transition($locked, $target, 'returned_to_pending', $user, $notes);
            $locked->forceFill(['approved_at' => null])->save();

            return $locked->fresh(['events.user']);
        });
    }

    private function assertFresh(AutomotivePartMeliDraft $draft): array
    {
        $preview = $this->builder->preview($draft->automotivePart()->firstOrFail());
        if (! hash_equals($draft->fingerprint, $preview['fingerprint'])) {
            $from = $draft->status;
            $errors = collect($draft->blocking_errors ?? [])
                ->push($this->validator->staleSourceIssue())
                ->unique(fn ($issue) => $issue['code'].'|'.$issue['field'])
                ->values()
                ->all();
            $draft->forceFill(['status' => 'stale', 'blocking_errors' => $errors])->save();
            AutomotivePartMeliDraftEvent::query()->create([
                'automotive_part_meli_draft_id' => $draft->id,
                'action' => 'stale_source_detected',
                'from_status' => $from,
                'to_status' => 'stale',
                'metadata' => ['current_fingerprint' => $preview['fingerprint']],
                'created_at' => now(),
            ]);

            throw new AutomotivePartDraftException('Los datos fuente cambiaron; regenera el borrador.', 'stale_source_data');
        }

        $draft->forceFill([
            'blocking_errors' => $preview['blocking_errors'],
            'warnings' => $preview['warnings'],
        ])->save();

        return $preview;
    }

    private function transition(
        AutomotivePartMeliDraft $draft,
        string $target,
        string $action,
        User $user,
        ?string $notes,
    ): void {
        $from = $draft->status;
        $draft->forceFill([
            'status' => $target,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();
        AutomotivePartMeliDraftEvent::query()->create([
            'automotive_part_meli_draft_id' => $draft->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $target,
            'user_id' => $user->id,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }
}
