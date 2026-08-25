<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMedia;
use App\Models\AutomotivePartMeliPictureUpload;
use App\Models\AutomotivePartMeliPublication;
use App\Models\User;

class AutomotivePartMeliPictureUploadService
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartMeliPublicationPreflight $preflight,
        private AutomotivePartMeliMediaVerifier $verifier,
        private AutomotivePartMeliPublisherClient $client,
        private AutomotivePartMeliPublisherSanitizer $sanitizer,
        private AutomotivePartMeliPublicationRecorder $recorder,
    ) {}

    public function upload(AutomotivePartMeliPublication $publication, ?User $user = null): AutomotivePartMeliPublication
    {
        $this->configuration->assertImageUpload();
        if ($publication->status !== 'local_valid') throw new AutomotivePartMeliPublisherException('La carga requiere un preflight local válido.', 'preflight_not_valid');
        $preview = $this->preflight->assertFresh($publication);
        $this->recorder->transition($publication, 'uploading_pictures', 'picture_upload_started', $user);

        try {
            foreach ($preview['media'] as $image) $this->uploadOne($publication, $image, $user);
            $this->recorder->transition($publication, 'local_valid', 'picture_upload_completed', $user);
        } catch (AutomotivePartMeliPublisherException $exception) {
            $target = in_array($exception->errorCode, ['media_hash_changed', 'media_mime_changed', 'media_missing'], true) ? 'stale' : 'failed';
            $publication->forceFill(['error_code' => $exception->errorCode, 'error_message' => $this->sanitizer->message($exception->getMessage())])->save();
            $this->recorder->transition($publication, $target, $target === 'stale' ? 'final_approval_revoked' : 'failed', $user);
            throw $exception;
        }
        return $publication->fresh(['pictureUploads']);
    }

    private function uploadOne(AutomotivePartMeliPublication $publication, array $image, ?User $user): void
    {
        $media = AutomotivePartMedia::query()->findOrFail($image['media_id']);
        $verified = $this->verifier->verify($media, $publication->automotive_part_id, $image['sha256']);
        $upload = AutomotivePartMeliPictureUpload::query()->firstOrNew([
            'publication_id' => $publication->id, 'automotive_part_media_id' => $media->id,
        ]);
        if ($upload->exists && $upload->status === 'uploaded' && hash_equals($verified['sha256'], (string) $upload->media_sha256) && filled($upload->meli_picture_id)) return;

        $reusable = AutomotivePartMeliPictureUpload::query()->where('media_sha256', $verified['sha256'])
            ->where('status', 'uploaded')->whereNotNull('meli_picture_id')
            ->whereHas('publication', fn ($query) => $query->where('meli_account_id', $publication->meli_account_id))->oldest('id')->first();
        if ($reusable !== null) {
            $upload->forceFill(['media_sha256' => $verified['sha256'], 'status' => 'uploaded', 'attempt_count' => (int) $upload->attempt_count,
                'meli_picture_id' => $reusable->meli_picture_id, 'secure_url' => $reusable->secure_url,
                'sanitized_response' => ['reused_upload_id' => $reusable->id], 'error_code' => null, 'error_message' => null, 'uploaded_at' => now()])->save();
            $this->recorder->event($publication, 'picture_uploaded', 'uploading_pictures', 'uploading_pictures', $user, null,
                ['media_id' => $media->id, 'reused' => true]);
            return;
        }

        $upload->forceFill(['media_sha256' => $verified['sha256'], 'status' => 'uploading',
            'attempt_count' => ((int) $upload->attempt_count) + 1, 'error_code' => null, 'error_message' => null])->save();
        try {
            $result = $this->client->uploadPicture($publication->account()->firstOrFail(), $verified['contents'], $verified['filename'], $verified['mime']);
            $pictureId = $result['json']['id'] ?? null;
            if (! is_string($pictureId) || $pictureId === '') throw new AutomotivePartMeliPublisherException('La carga no devolvió un picture ID.', 'missing_picture_id');
            $upload->forceFill(['status' => 'uploaded', 'meli_picture_id' => $pictureId,
                'secure_url' => $result['json']['secure_url'] ?? null, 'sanitized_response' => $this->sanitizer->array($result['json']),
                'error_code' => null, 'error_message' => null, 'uploaded_at' => now()])->save();
            $this->recorder->event($publication, 'picture_uploaded', 'uploading_pictures', 'uploading_pictures', $user, null,
                ['media_id' => $media->id, 'picture_id' => $pictureId, 'reused' => false]);
        } catch (AutomotivePartMeliPublisherException $exception) {
            $upload->forceFill(['status' => 'failed', 'sanitized_response' => $this->sanitizer->array($exception->response),
                'error_code' => $exception->errorCode, 'error_message' => $this->sanitizer->message($exception->getMessage())])->save();
            throw $exception;
        }
    }
}
