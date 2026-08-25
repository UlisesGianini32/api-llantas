<?php

namespace App\Services\Autopartes\MediaPricing;

class AutomotivePartMediaPricingLocalOnlyGuard
{
    private const ALLOWED = [
        'upload_media', 'approve_media', 'reject_media', 'archive_media', 'set_primary_media',
        'reorder_media', 'audit_media', 'create_price_rule', 'edit_price_rule', 'activate_price_rule',
        'deactivate_price_rule', 'replace_price_rule', 'preview_price', 'calculate_price',
    ];

    public function assert(string $operation): void
    {
        if (! in_array($operation, self::ALLOWED, true)) {
            throw new AutomotivePartMediaPricingException(
                'La Fase 6 solo admite operaciones internas y nunca publica ni modifica Mercado Libre.',
                'external_operation_forbidden',
            );
        }
    }
}
