<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMedia;
use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliPublication;
use App\Models\MeliAccount;
use App\Models\User;
use App\Services\Autopartes\Drafts\AutomotivePartDraftBuilder;
use App\Services\Autopartes\Drafts\AutomotivePartDraftFingerprint;
use Illuminate\Support\Facades\DB;

class AutomotivePartMeliPublicationPreflight
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartDraftBuilder $builder,
        private AutomotivePartDraftFingerprint $fingerprints,
        private AutomotivePartMeliMediaVerifier $mediaVerifier,
        private AutomotivePartMeliPublicationRecorder $recorder,
    ) {}

    public function preview(AutomotivePartMeliDraft $draft, MeliAccount $account): array
    {
        $errors = [];
        $add = static function (string $code, string $message, string $field) use (&$errors): void { $errors[] = compact('code', 'message', 'field'); };
        if ($draft->status !== 'approved' || $draft->approved_at === null) $add('draft_not_approved', 'El borrador debe estar aprobado.', 'draft.status');
        if ($draft->status === 'stale') $add('draft_stale', 'El borrador está obsoleto.', 'draft.status');
        if (($draft->blocking_errors ?? []) !== []) $add('draft_has_blocking_errors', 'El borrador conserva errores bloqueantes.', 'draft.blocking_errors');

        $current = $this->builder->preview($draft->automotivePart()->firstOrFail());
        if (! hash_equals((string) $draft->fingerprint, (string) $current['fingerprint'])) $add('fingerprint_changed', 'Los datos fuente cambiaron.', 'draft.fingerprint');
        $snapshot = (array) $draft->source_snapshot;
        if (($snapshot['enrichment_review']['status'] ?? null) !== 'approved') $add('missing_approved_enrichment', 'Falta enriquecimiento aprobado.', 'enrichment');
        if (($snapshot['approved_category_candidate']['status'] ?? null) !== 'approved') $add('missing_approved_category', 'Falta categoría aprobada.', 'category');
        if (($snapshot['readiness']['status'] ?? null) !== 'ready') $add('readiness_not_ready', 'Readiness no está listo.', 'readiness');
        if (strtoupper((string) ($snapshot['category_snapshot']['site_id'] ?? '')) !== 'MLM' || ! str_starts_with((string) $draft->category_id, 'MLM')) $add('invalid_site', 'La categoría debe pertenecer a MLM.', 'category_id');
        if (! is_numeric($draft->price_mxn) || (float) $draft->price_mxn <= 0 || $draft->currency !== 'MXN') $add('invalid_price_mxn', 'El precio MXN debe ser positivo.', 'price');
        if (! is_numeric($draft->stock) || (int) $draft->stock <= 0 || (float) $draft->stock !== (float) (int) $draft->stock) $add('invalid_stock', 'El stock debe ser un entero positivo.', 'stock');
        if ($this->configuration->listingTypeId() === null) $add('missing_listing_type', 'Selecciona explícitamente el listing type.', 'listing_type_id');
        if ($this->configuration->buyingMode() === null) $add('missing_buying_mode', 'Selecciona explícitamente el buying mode.', 'buying_mode');
        if (! filled($account->meli_user_id)) $add('invalid_meli_account', 'La cuenta seleccionada no tiene seller ID.', 'meli_account_id');

        $attributes = [];
        $hasCondition = false;
        foreach ((array) $draft->prepared_attributes as $attribute) {
            if (! is_array($attribute) || ! filled($attribute['attribute_id'] ?? null) || ! filled($attribute['value'] ?? null)) continue;
            $id = (string) $attribute['attribute_id'];
            $mapped = ['id' => $id];
            if (filled($attribute['value_id'] ?? null)) $mapped['value_id'] = (string) $attribute['value_id'];
            else $mapped['value_name'] = (string) $attribute['value'];
            $attributes[] = $mapped;
            if (strtoupper($id) === 'ITEM_CONDITION') $hasCondition = true;
        }
        if (! $hasCondition) $add('missing_item_condition_attribute', 'La condición debe venir del atributo ITEM_CONDITION respaldado por la categoría.', 'attributes.ITEM_CONDITION');

        $images = [];
        foreach ((array) $draft->prepared_images as $position => $image) {
            if (! is_array($image) || ! isset($image['media_id'], $image['sha256'])) { $add('invalid_media_source', 'Todas las imágenes deben proceder de medios privados aprobados.', 'pictures'); continue; }
            $media = AutomotivePartMedia::query()->find($image['media_id']);
            if ($media === null) { $add('media_missing', 'No existe una imagen preparada.', 'pictures'); continue; }
            try { $this->mediaVerifier->verify($media, $draft->automotive_part_id, (string) $image['sha256']); }
            catch (AutomotivePartMeliPublisherException $e) { $add($e->errorCode, $e->getMessage(), 'pictures'); continue; }
            $images[] = ['media_id' => $media->id, 'sha256' => $media->sha256, 'position' => (int) ($image['position'] ?? $position + 1), 'is_primary' => (bool) $media->is_primary];
        }
        usort($images, fn ($a, $b) => ($b['is_primary'] <=> $a['is_primary'])
            ?: ($a['position'] <=> $b['position']) ?: ($a['media_id'] <=> $b['media_id']));
        if ($images === []) $add('missing_images', 'Se requiere al menos una imagen aprobada.', 'pictures');
        if (! collect($images)->contains('is_primary', true)) $add('missing_primary_image', 'Se requiere una imagen principal aprobada.', 'pictures');

        $description = (string) $draft->description;
        if (trim($description) === '' || $description !== strip_tags($description) || preg_match('/https?:\/\/|www\.|\[[^\]]+\]\([^)]+\)|\b[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}\b|(?:\+?52\s*)?(?:\d[\s.-]*){10}/iu', $description)) {
            $add('unsafe_description', 'La descripción debe ser texto plano sin Markdown, enlaces ni contacto.', 'description');
        }

        $item = ['site_id' => 'MLM', 'title' => (string) $draft->title, 'category_id' => (string) $draft->category_id,
            'price' => (float) $draft->price_mxn, 'currency_id' => 'MXN', 'available_quantity' => (int) $draft->stock,
            'buying_mode' => $this->configuration->buyingMode(), 'listing_type_id' => $this->configuration->listingTypeId(),
            'pictures' => $images, 'attributes' => $attributes];
        if ($this->configuration->channels() !== []) $item['channels'] = $this->configuration->channels();
        $compatibilities = array_values((array) $draft->prepared_compatibilities);
        $data = ['item_payload' => $item, 'description_payload' => ['plain_text' => $description], 'media' => $images,
            'compatibility_snapshot' => $compatibilities, 'compatibility_pending' => $compatibilities !== [],
            'account' => ['id' => $account->id, 'seller_id' => (string) $account->meli_user_id],
            'approved_draft_fingerprint' => (string) $draft->fingerprint, 'rules_version' => $this->configuration->rulesVersion()];
        return $data + ['fingerprint' => $this->fingerprints->make($data), 'errors' => $errors,
            'warnings' => array_values((array) $draft->warnings), 'eligible' => $errors === []];
    }

    public function create(AutomotivePartMeliDraft $draft, MeliAccount $account, ?User $user = null): AutomotivePartMeliPublication
    {
        $this->configuration->assertPublisher();
        $preview = $this->preview($draft, $account);
        return DB::transaction(function () use ($draft, $account, $user, $preview) {
            $publication = AutomotivePartMeliPublication::query()->where('meli_account_id', $account->id)
                ->where('automotive_part_meli_draft_id', $draft->id)->lockForUpdate()->first();
            if ($publication !== null && ! in_array($publication->status, ['draft', 'local_invalid', 'local_valid', 'validation_failed', 'validated', 'final_approved', 'queued', 'failed'], true)) return $publication;
            if ($publication !== null && ! hash_equals((string) $publication->request_fingerprint, $preview['fingerprint']) && in_array($publication->status, ['validated', 'final_approved', 'queued'], true)) {
                $from = $publication->status;
                $publication->forceFill(['status' => 'stale', 'final_approved_by' => null, 'final_approved_at' => null, 'final_approval_fingerprint' => null])->save();
                $this->recorder->event($publication, 'final_approval_revoked', $from, 'stale', $user, 'El preflight cambió.');
                return $publication->fresh();
            }
            $status = $preview['eligible'] ? 'local_valid' : 'local_invalid';
            $values = ['automotive_part_id' => $draft->automotive_part_id, 'status' => $status, 'site_id' => 'MLM',
                'seller_id' => (string) $account->meli_user_id, 'category_id' => (string) $draft->category_id,
                'listing_type_id' => (string) $this->configuration->listingTypeId(), 'request_fingerprint' => $preview['fingerprint'],
                'approved_draft_fingerprint' => (string) $draft->fingerprint, 'local_payload' => $preview,
                'remote_validation_status' => 'not_requested', 'validation_payload' => null, 'validation_response' => null,
                'remote_validated_at' => null, 'remote_validation_expires_at' => null, 'final_approved_by' => null,
                'final_approved_at' => null, 'final_approval_fingerprint' => null,
                'error_code' => null, 'error_message' => null,
                'metadata' => ['rules_version' => $this->configuration->rulesVersion()]];
            if ($publication === null) {
                $publication = AutomotivePartMeliPublication::query()->create($values + ['automotive_part_meli_draft_id' => $draft->id, 'meli_account_id' => $account->id]);
                $this->recorder->event($publication, 'local_preflight_created', null, $status, $user, null, ['errors' => $preview['errors']]);
            } else {
                $from = $publication->status; $hadFinalApproval = $publication->final_approved_at !== null;
                $publication->forceFill($values)->save();
                if ($hadFinalApproval) $this->recorder->event($publication, 'final_approval_revoked', $from, $status, $user, 'El preflight fue regenerado.');
                $this->recorder->event($publication, 'local_preflight_created', $from, $status, $user, 'Preflight regenerado.', ['errors' => $preview['errors']]);
            }
            return $publication->fresh();
        });
    }

    public function assertFresh(AutomotivePartMeliPublication $publication): array
    {
        $preview = $this->preview($publication->draft()->firstOrFail(), $publication->account()->firstOrFail());
        if (! $preview['eligible'] || ! hash_equals((string) $publication->request_fingerprint, $preview['fingerprint'])) {
            $from = $publication->status;
            if (! in_array($from, ['published', 'published_pending_compatibility'], true)) {
                $mediaCodes = ['media_hash_changed', 'media_mime_changed', 'media_missing', 'media_not_approved'];
                if (collect($preview['errors'])->pluck('code')->intersect($mediaCodes)->isNotEmpty()) {
                    $publication->pictureUploads()->where('status', 'uploaded')->update([
                        'status' => 'invalidated', 'error_code' => 'media_changed',
                        'error_message' => 'El medio cambió después del upload.',
                    ]);
                }
                $publication->forceFill(['status' => 'stale', 'final_approved_by' => null, 'final_approved_at' => null, 'final_approval_fingerprint' => null])->save();
                $this->recorder->event($publication, 'final_approval_revoked', $from, 'stale', null, 'El contenido aprobado cambió.');
            }
            throw new AutomotivePartMeliPublisherException('El preflight ya no coincide con sus fuentes.', 'stale_publication');
        }
        return $preview;
    }

    public function remotePayload(AutomotivePartMeliPublication $publication): array
    {
        $preview = $this->assertFresh($publication);
        $uploads = $publication->pictureUploads()->where('status', 'uploaded')->get()->keyBy('automotive_part_media_id');
        $pictures = [];
        foreach ($preview['media'] as $image) {
            $upload = $uploads->get($image['media_id']);
            if ($upload === null || ! filled($upload->meli_picture_id) || ! hash_equals($image['sha256'], $upload->media_sha256)) {
                throw new AutomotivePartMeliPublisherException('Todas las imágenes deben cargarse antes de validar.', 'pictures_not_uploaded');
            }
            $pictures[] = ['id' => $upload->meli_picture_id];
        }
        $payload = $preview['item_payload']; $payload['pictures'] = $pictures;
        return $payload;
    }
}
