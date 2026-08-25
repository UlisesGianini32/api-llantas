<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;
use App\Models\AutomotivePartMeliPublicationAttempt;
use App\Models\AutomotivePartMeliPublicationEvent;
use App\Models\User;

class AutomotivePartMeliPublicationRecorder
{
    public function __construct(private AutomotivePartMeliPublicationStateMachine $states, private AutomotivePartMeliPublisherSanitizer $sanitizer) {}

    public function transition(AutomotivePartMeliPublication $publication, string $to, string $action, ?User $user = null, ?string $notes = null, array $metadata = []): void
    {
        $this->states->assert($publication, $to); $from = $publication->status;
        $publication->forceFill(['status' => $to])->save();
        $this->event($publication, $action, $from, $to, $user, $notes, $metadata);
    }

    public function event(AutomotivePartMeliPublication $publication, string $action, ?string $from, ?string $to, ?User $user = null, ?string $notes = null, array $metadata = []): void
    {
        AutomotivePartMeliPublicationEvent::query()->create(['publication_id' => $publication->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'user_id' => $user?->id, 'notes' => $this->sanitizer->message($notes),
            'metadata' => $metadata === [] ? null : $this->sanitizer->array($metadata), 'created_at' => now()]);
    }

    public function startAttempt(AutomotivePartMeliPublication $publication, string $operation, string $fingerprint, array $request): AutomotivePartMeliPublicationAttempt
    {
        return AutomotivePartMeliPublicationAttempt::query()->create(['publication_id' => $publication->id, 'operation' => $operation,
            'attempt_number' => ((int) AutomotivePartMeliPublicationAttempt::query()->where('publication_id', $publication->id)->where('operation', $operation)->max('attempt_number')) + 1,
            'request_fingerprint' => $fingerprint, 'sanitized_request' => $this->sanitizer->array($request), 'started_at' => now()]);
    }

    public function finishAttempt(AutomotivePartMeliPublicationAttempt $attempt, array $result = [], ?AutomotivePartMeliPublisherException $error = null): void
    {
        $attempt->forceFill(['http_status' => $result['status'] ?? $error?->httpStatus, 'meli_request_id' => $result['request_id'] ?? $error?->requestId,
            'sanitized_response' => $this->sanitizer->array((array) ($result['json'] ?? $error?->response ?? [])), 'error_code' => $error?->errorCode,
            'error_message' => $this->sanitizer->message($error?->getMessage()), 'transient' => $error?->transient ?? false,
            'ambiguous_result' => $error?->ambiguousResult ?? false, 'completed_at' => now()])->save();
    }
}
