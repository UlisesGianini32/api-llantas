<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;
use App\Models\User;
use App\Services\Autopartes\Drafts\AutomotivePartDraftFingerprint;

class AutomotivePartMeliFinalApprovalService
{
    public function __construct(
        private AutomotivePartMeliPublicationPreflight $preflight,
        private AutomotivePartMeliPublicationRecorder $recorder,
        private AutomotivePartDraftFingerprint $fingerprints,
    ) {}

    public function approve(AutomotivePartMeliPublication $publication, User $user, array $confirmation): AutomotivePartMeliPublication
    {
        if ($publication->status !== 'validated' || $publication->remote_validation_status !== 'passed' ||
            $publication->remote_validation_expires_at === null || $publication->remote_validation_expires_at->isPast()) {
            throw new AutomotivePartMeliPublisherException('La validación remota no está vigente.', 'remote_validation_expired');
        }
        $preview = $this->preflight->assertFresh($publication);
        $note = trim((string) ($confirmation['note'] ?? ''));
        $expected = ['account' => (string) $publication->meli_account_id, 'title' => (string) $preview['item_payload']['title'],
            'price' => number_format((float) $preview['item_payload']['price'], 2, '.', ''),
            'stock' => (string) $preview['item_payload']['available_quantity'], 'category' => (string) $preview['item_payload']['category_id'],
            'fingerprint' => substr($publication->request_fingerprint, -8)];
        $actual = ['account' => trim((string) ($confirmation['confirm_account_id'] ?? '')),
            'title' => trim((string) ($confirmation['confirm_title'] ?? '')),
            'price' => is_numeric($confirmation['confirm_price'] ?? null) ? number_format((float) $confirmation['confirm_price'], 2, '.', '') : '',
            'stock' => trim((string) ($confirmation['confirm_stock'] ?? '')), 'category' => trim((string) ($confirmation['confirm_category_id'] ?? '')),
            'fingerprint' => trim((string) ($confirmation['confirm_fingerprint_suffix'] ?? ''))];
        if ($note === '' || $actual !== $expected) throw new AutomotivePartMeliPublisherException('La nota y todas las confirmaciones deben coincidir exactamente.', 'final_confirmation_mismatch');

        $approval = $this->fingerprints->make(['publication_fingerprint' => $publication->request_fingerprint,
            'validation_response' => $publication->validation_response, 'validation_at' => $publication->remote_validated_at?->toJSON(),
            'confirmed' => $expected, 'approved_by' => $user->id]);
        $publication->forceFill(['final_approved_by' => $user->id, 'final_approved_at' => now(), 'final_approval_fingerprint' => $approval])->save();
        $this->recorder->transition($publication, 'final_approved', 'final_approved', $user, $note, ['confirmed' => $expected]);
        return $publication->fresh();
    }

    public function revoke(AutomotivePartMeliPublication $publication, User $user, string $note): AutomotivePartMeliPublication
    {
        if (! in_array($publication->status, ['final_approved', 'queued'], true) || trim($note) === '') throw new AutomotivePartMeliPublisherException('No puede revocarse la aprobación en el estado actual o sin nota.', 'final_revoke_not_allowed');
        $publication->forceFill(['final_approved_by' => null, 'final_approved_at' => null, 'final_approval_fingerprint' => null])->save();
        $this->recorder->transition($publication, 'validated', 'final_approval_revoked', $user, $note);
        return $publication->fresh();
    }
}
