<?php

namespace App\Services\Autopartes\Drafts;

class AutomotivePartDraftLocalOnlyGuard
{
    private const OPERATIONS = ['generate', 'regenerate', 'approve', 'reject', 'return_to_pending'];

    public function assertLocalOperation(string $operation): void
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new AutomotivePartDraftException(
                'La Fase 5 solo permite operaciones internas; la publicación externa está bloqueada.',
                'external_write_forbidden',
            );
        }
    }
}
