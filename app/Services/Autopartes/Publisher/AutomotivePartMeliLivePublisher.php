<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AutomotivePartMeliLivePublisher
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartMeliPublicationPreflight $preflight,
        private AutomotivePartMeliPublisherClient $client,
        private AutomotivePartMeliPublicationRecorder $recorder,
        private AutomotivePartMeliDescriptionService $descriptions,
        private AutomotivePartMeliPublisherSanitizer $sanitizer,
    ) {}

    public function publish(AutomotivePartMeliPublication $publication): AutomotivePartMeliPublication
    {
        $this->configuration->assertLive();
        $lock = Cache::lock('autopartes:meli-publish:'.$publication->meli_account_id.':'.now()->toDateString(), 180);
        if (! $lock->get()) throw new AutomotivePartMeliPublisherException('Ya existe una publicación en curso para esta cuenta.', 'publication_locked');
        try {
            $publication->refresh();
            $this->assertCanPublish($publication);
            $payload = $this->preflight->remotePayload($publication);
            if (AutomotivePartMeliPublication::query()->where('meli_account_id', $publication->meli_account_id)
                ->whereDate('published_at', now()->toDateString())->whereKeyNot($publication->id)->count() >= $this->configuration->maxDailyItems()) {
                throw new AutomotivePartMeliPublisherException('Se alcanzó el límite diario de publicaciones.', 'daily_limit_reached');
            }

            $attempt = DB::transaction(function () use ($publication, $payload) {
                $locked = AutomotivePartMeliPublication::query()->lockForUpdate()->findOrFail($publication->id);
                $this->assertCanPublish($locked);
                if ($locked->attempts()->where('operation', 'create_item')->where('ambiguous_result', true)->exists()) {
                    throw new AutomotivePartMeliPublisherException('Existe un intento ambiguo que requiere reconciliación manual.', 'ambiguous_attempt_exists');
                }
                $this->recorder->transition($locked, 'publishing', 'publishing_started');
                return $this->recorder->startAttempt($locked, 'create_item', $locked->request_fingerprint, $payload);
            });

            try {
                $result = $this->client->createItem($publication->account()->firstOrFail(), $payload);
            } catch (AutomotivePartMeliPublisherException $exception) {
                $this->recorder->finishAttempt($attempt, [], $exception);
                $publication->refresh()->forceFill(['error_code' => $exception->errorCode,
                    'error_message' => $this->sanitizer->message($exception->getMessage())])->save();
                $target = $exception->ambiguousResult ? 'reconciliation_required' : 'failed';
                $this->recorder->transition($publication, $target, $exception->ambiguousResult ? 'reconciliation_required' : 'failed');
                throw $exception;
            }

            $itemId = $result['json']['id'] ?? null;
            if (! is_string($itemId) || preg_match('/^MLM[0-9]+$/', $itemId) !== 1) {
                $exception = new AutomotivePartMeliPublisherException('La respuesta de creación no contiene un item_id MLM válido.', 'missing_item_id', false, true,
                    $result['status'], $result['request_id'], null, $result['json']);
                $this->recorder->finishAttempt($attempt, $result, $exception);
                $publication->refresh()->forceFill(['error_code' => $exception->errorCode, 'error_message' => $exception->getMessage()])->save();
                $this->recorder->transition($publication, 'reconciliation_required', 'reconciliation_required');
                throw $exception;
            }

            DB::transaction(function () use ($publication, $attempt, $result, $itemId) {
                $locked = AutomotivePartMeliPublication::query()->lockForUpdate()->findOrFail($publication->id);
                if (filled($locked->meli_item_id)) throw new AutomotivePartMeliPublisherException('El item_id ya fue persistido; no se repetirá POST /items.', 'item_already_created');
                $this->recorder->finishAttempt($attempt, $result);
                $locked->forceFill(['meli_item_id' => $itemId, 'permalink' => $result['json']['permalink'] ?? null,
                    'item_status' => $result['json']['status'] ?? null, 'publication_response' => $this->sanitizer->array($result['json']),
                    'published_at' => now(), 'description_status' => 'not_started', 'error_code' => null, 'error_message' => null])->save();
                $this->recorder->transition($locked, 'item_created', 'item_created', null, null, ['meli_item_id' => $itemId]);
            });

            $publication->refresh();
            return $this->descriptions->create($publication);
        } finally {
            $lock->release();
        }
    }

    private function assertCanPublish(AutomotivePartMeliPublication $publication): void
    {
        if (! in_array($publication->status, ['final_approved', 'queued'], true)) throw new AutomotivePartMeliPublisherException('La publicación no tiene aprobación humana final.', 'final_approval_required');
        if (filled($publication->meli_item_id)) throw new AutomotivePartMeliPublisherException('Ya existe item_id; POST /items está bloqueado.', 'item_already_created');
        if (! filled($publication->final_approval_fingerprint) || $publication->final_approved_at === null) throw new AutomotivePartMeliPublisherException('Falta aprobación humana final.', 'final_approval_required');
        if ($publication->remote_validation_status !== 'passed' || $publication->remote_validation_expires_at === null || $publication->remote_validation_expires_at->isPast()) {
            throw new AutomotivePartMeliPublisherException('La validación remota expiró.', 'remote_validation_expired');
        }
        $this->preflight->assertFresh($publication);
    }
}
