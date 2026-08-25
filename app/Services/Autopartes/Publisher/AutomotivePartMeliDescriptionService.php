<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;
use App\Models\User;

class AutomotivePartMeliDescriptionService
{
    public function __construct(
        private AutomotivePartMeliPublisherClient $client,
        private AutomotivePartMeliPublicationRecorder $recorder,
        private AutomotivePartMeliPublisherSanitizer $sanitizer,
    ) {}

    public function create(AutomotivePartMeliPublication $publication, bool $checkExisting = false, ?User $user = null): AutomotivePartMeliPublication
    {
        if (! filled($publication->meli_item_id)) throw new AutomotivePartMeliPublisherException('No existe item_id para crear la descripción.', 'missing_meli_item_id');
        if (! in_array($publication->status, ['item_created', 'description_pending', 'partial_failure'], true)) {
            throw new AutomotivePartMeliPublisherException('El estado no permite crear o recuperar la descripción.', 'description_not_allowed');
        }
        $payload = (array) data_get($publication->local_payload, 'description_payload', []);
        if (array_keys($payload) !== ['plain_text'] || ! is_string($payload['plain_text']) || trim($payload['plain_text']) === '') {
            throw new AutomotivePartMeliPublisherException('El payload de descripción no es plain_text válido.', 'invalid_description_payload');
        }

        if ($checkExisting && $this->descriptionAlreadyExists($publication)) return $this->complete($publication, $user, ['reconciled' => true]);
        if ($publication->status !== 'description_pending') {
            $publication->forceFill(['description_status' => 'pending'])->save();
            $this->recorder->transition($publication, 'description_pending', 'description_pending', $user);
        }
        $attempt = $this->recorder->startAttempt($publication, 'create_description', $publication->request_fingerprint, $payload);
        try {
            $result = $this->client->createDescription($publication->account()->firstOrFail(), $publication->meli_item_id, $payload);
            $this->recorder->finishAttempt($attempt, $result);
            return $this->complete($publication, $user);
        } catch (AutomotivePartMeliPublisherException $exception) {
            $this->recorder->finishAttempt($attempt, [], $exception);
            $publication->forceFill(['description_status' => 'failed', 'error_code' => $exception->errorCode,
                'error_message' => $this->sanitizer->message($exception->getMessage())])->save();
            $this->recorder->event($publication, 'partial_failure', $publication->status, $publication->status, $user,
                'El artículo existe, pero la descripción quedó pendiente.', ['operation' => 'create_description']);
            throw $exception;
        }
    }

    private function descriptionAlreadyExists(AutomotivePartMeliPublication $publication): bool
    {
        try {
            $attempt = $this->recorder->startAttempt($publication, 'get_description', $publication->request_fingerprint, ['item_id' => $publication->meli_item_id]);
            $result = $this->client->getDescription($publication->account()->firstOrFail(), $publication->meli_item_id);
            $this->recorder->finishAttempt($attempt, $result);
            return filled($result['json']['plain_text'] ?? null) || filled($result['json']['text'] ?? null);
        } catch (AutomotivePartMeliPublisherException $exception) {
            $this->recorder->finishAttempt($attempt, [], $exception);
            if ($exception->httpStatus === 404) return false;
            throw $exception;
        }
    }

    private function complete(AutomotivePartMeliPublication $publication, ?User $user, array $metadata = []): AutomotivePartMeliPublication
    {
        $pendingCompatibility = (bool) data_get($publication->local_payload, 'compatibility_pending', false);
        $target = $pendingCompatibility ? 'published_pending_compatibility' : 'published';
        $publication->forceFill(['description_status' => 'created', 'completed_at' => now(), 'error_code' => null, 'error_message' => null,
            'metadata' => array_merge((array) $publication->metadata, $pendingCompatibility ? ['compatibility_task' => 'pending_no_write_phase_7'] : [])])->save();
        $this->recorder->event($publication, 'description_created', $publication->status, $publication->status, $user, null, $metadata);
        $this->recorder->transition($publication, $target, 'completed', $user,
            $pendingCompatibility ? 'Publicación creada; compatibilidad pendiente sin escritura remota.' : null);
        return $publication->fresh();
    }
}
