<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;
use App\Models\User;

class AutomotivePartMeliRemoteValidationService
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartMeliPublicationPreflight $preflight,
        private AutomotivePartMeliPublisherClient $client,
        private AutomotivePartMeliPublicationRecorder $recorder,
        private AutomotivePartMeliPublisherSanitizer $sanitizer,
    ) {}

    public function validate(AutomotivePartMeliPublication $publication, ?User $user = null): AutomotivePartMeliPublication
    {
        $this->configuration->assertRemoteValidation();
        if (! in_array($publication->status, ['local_valid', 'validation_failed', 'validated'], true)) {
            throw new AutomotivePartMeliPublisherException('El estado actual no permite validar remotamente.', 'remote_validation_not_allowed');
        }
        $payload = $this->preflight->remotePayload($publication);
        $this->recorder->transition($publication, 'validating', 'remote_validation_started', $user);
        $attempt = $this->recorder->startAttempt($publication, 'remote_validate', $publication->request_fingerprint, $payload);
        try {
            $result = $this->client->validateItem($publication->account()->firstOrFail(), $payload);
            $this->recorder->finishAttempt($attempt, $result);
            $publication->forceFill(['validation_payload' => $payload, 'validation_response' => $this->sanitizer->array($result['json']),
                'remote_validation_status' => 'passed', 'remote_validated_at' => now(),
                'remote_validation_expires_at' => now()->addMinutes($this->configuration->validationTtlMinutes()),
                'final_approved_by' => null, 'final_approved_at' => null, 'final_approval_fingerprint' => null,
                'error_code' => null, 'error_message' => null])->save();
            $this->recorder->transition($publication, 'validated', 'remote_validation_passed', $user, null, ['http_status' => $result['status']]);
        } catch (AutomotivePartMeliPublisherException $exception) {
            $this->recorder->finishAttempt($attempt, [], $exception);
            $publication->forceFill(['validation_payload' => $payload, 'validation_response' => $this->sanitizer->array($exception->response),
                'remote_validation_status' => 'failed', 'remote_validated_at' => now(), 'remote_validation_expires_at' => null,
                'final_approved_by' => null, 'final_approved_at' => null, 'final_approval_fingerprint' => null,
                'error_code' => $exception->errorCode, 'error_message' => $this->sanitizer->message($exception->getMessage())])->save();
            $target = $exception->httpStatus === 400 ? 'validation_failed' : 'failed';
            $this->recorder->transition($publication, $target, $target === 'validation_failed' ? 'remote_validation_failed' : 'failed', $user);
            throw $exception;
        }
        return $publication->fresh();
    }
}
