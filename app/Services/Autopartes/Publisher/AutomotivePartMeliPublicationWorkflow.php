<?php

namespace App\Services\Autopartes\Publisher;

use App\Jobs\PublishAutomotivePartToMeliJob;
use App\Models\AutomotivePartMeliPublication;
use App\Models\User;

class AutomotivePartMeliPublicationWorkflow
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartMeliPublicationPreflight $preflight,
        private AutomotivePartMeliPublicationRecorder $recorder,
    ) {}

    public function enqueue(AutomotivePartMeliPublication $publication, ?User $user = null): AutomotivePartMeliPublication
    {
        $this->configuration->assertLive();
        if ($publication->status !== 'final_approved' || ! filled($publication->final_approval_fingerprint)) throw new AutomotivePartMeliPublisherException('Se requiere aprobación final antes de encolar.', 'final_approval_required');
        if ($publication->remote_validation_expires_at === null || $publication->remote_validation_expires_at->isPast()) throw new AutomotivePartMeliPublisherException('La validación remota expiró.', 'remote_validation_expired');
        $this->preflight->assertFresh($publication);
        $this->recorder->transition($publication, 'queued', 'queued', $user);
        PublishAutomotivePartToMeliJob::dispatch($publication->id);
        return $publication->fresh();
    }

    public function cancel(AutomotivePartMeliPublication $publication, User $user, string $note): AutomotivePartMeliPublication
    {
        if (filled($publication->meli_item_id) || ! in_array($publication->status, ['draft', 'local_invalid', 'local_valid', 'validation_failed', 'validated', 'final_approved', 'queued'], true) || trim($note) === '') {
            throw new AutomotivePartMeliPublisherException('Solo puede cancelarse antes de crear el artículo y con nota.', 'cancel_not_allowed');
        }
        $this->recorder->transition($publication, 'cancelled', 'cancelled', $user, $note);
        return $publication->fresh();
    }
}
