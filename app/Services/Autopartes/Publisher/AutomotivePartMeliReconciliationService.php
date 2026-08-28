<?php

namespace App\Services\Autopartes\Publisher;

use App\Models\AutomotivePartMeliPublication;
use App\Models\User;

class AutomotivePartMeliReconciliationService
{
    public function __construct(
        private AutomotivePartMeliPublisherConfiguration $configuration,
        private AutomotivePartMeliPublisherClient $client,
        private AutomotivePartMeliPublicationRecorder $recorder,
        private AutomotivePartMeliDescriptionService $descriptions,
        private AutomotivePartMeliPublisherSanitizer $sanitizer,
    ) {}

    public function resolve(AutomotivePartMeliPublication $publication, User $user, string $resolution, ?string $itemId, string $note): AutomotivePartMeliPublication
    {
        $this->configuration->assertPublisher();
        if ($publication->status !== 'reconciliation_required' || trim($note) === '') throw new AutomotivePartMeliPublisherException('La reconciliación requiere el estado correcto y una nota.', 'reconciliation_not_allowed');
        if ($resolution === 'not_created') {
            $publication->forceFill(['error_code' => 'reconciled_not_created', 'error_message' => $this->sanitizer->message($note)])->save();
            $this->recorder->transition($publication, 'failed', 'reconciliation_resolved', $user, $note, ['resolution' => $resolution]);
            return $publication->fresh();
        }
        if ($resolution !== 'item_found' || ! is_string($itemId) || preg_match('/^MLM[0-9]+$/', $itemId) !== 1) throw new AutomotivePartMeliPublisherException('Indica un item_id MLM válido.', 'invalid_reconciliation_item');
        $result = $this->client->getItem($publication->account()->firstOrFail(), $itemId);
        if (($result['json']['id'] ?? null) !== $itemId) throw new AutomotivePartMeliPublisherException('Mercado Libre no confirmó el item indicado.', 'reconciliation_item_mismatch');
        $publication->forceFill(['meli_item_id' => $itemId, 'permalink' => $result['json']['permalink'] ?? null,
            'item_status' => $result['json']['status'] ?? null, 'publication_response' => $this->sanitizer->array($result['json']),
            'published_at' => $publication->published_at ?? now(), 'error_code' => null, 'error_message' => null])->save();
        $this->recorder->transition($publication, 'item_created', 'reconciliation_resolved', $user, $note, ['resolution' => $resolution, 'item_id' => $itemId]);
        return $this->descriptions->create($publication->fresh(), true, $user);
    }
}
