<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;

class AutomotivePartMeliPublicationStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['local_invalid', 'local_valid', 'cancelled'],
        'local_invalid' => ['local_invalid', 'local_valid', 'cancelled', 'stale'],
        'local_valid' => ['local_invalid', 'local_valid', 'uploading_pictures', 'validating', 'cancelled', 'stale'],
        'uploading_pictures' => ['local_valid', 'failed', 'stale'],
        'validating' => ['validated', 'validation_failed', 'failed', 'stale'],
        'validation_failed' => ['local_invalid', 'local_valid', 'validating', 'cancelled', 'stale'],
        'validated' => ['local_invalid', 'local_valid', 'final_approved', 'validating', 'cancelled', 'stale'],
        'final_approved' => ['local_invalid', 'local_valid', 'validated', 'queued', 'publishing', 'cancelled', 'stale'],
        'queued' => ['local_invalid', 'local_valid', 'final_approved', 'publishing', 'cancelled', 'stale'],
        'publishing' => ['item_created', 'failed', 'reconciliation_required'],
        'item_created' => ['description_pending', 'published', 'published_pending_compatibility', 'partial_failure'],
        'description_pending' => ['published', 'published_pending_compatibility', 'partial_failure'],
        'partial_failure' => ['description_pending', 'published', 'published_pending_compatibility', 'reconciliation_required'],
        'reconciliation_required' => ['item_created', 'failed', 'cancelled'],
        'failed' => ['local_invalid', 'local_valid', 'validating', 'cancelled', 'stale'],
    ];

    public function assert(AutomotivePartMeliPublication $publication, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$publication->status] ?? [], true)) {
            throw new AutomotivePartMeliPublisherException("Transición no permitida: {$publication->status} → {$to}.", 'invalid_publication_transition');
        }
    }
}
