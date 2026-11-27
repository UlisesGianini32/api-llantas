<?php

namespace App\Exceptions;

use RuntimeException;

class InventoryMeliOrderReconciliationSchemaException extends RuntimeException
{
    public function __construct(
        string $message = 'La tabla durable inventory_meli_order_reconciliations no existe; la reconciliación automática se bloquea en modo fail-closed.',
    ) {
        parent::__construct($message);
    }
}
