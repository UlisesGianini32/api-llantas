<?php

namespace App\Exceptions;

use RuntimeException;

class StaleReconciliationLeaseException extends RuntimeException
{
    public function __construct(
        public readonly int $meliOrderId,
        public readonly string $attemptedOwner,
        public readonly int $attemptedFencingToken,
        string $message = 'Lease o fencing token obsoleto para reconciliación de pedido Mercado Libre.',
    ) {
        parent::__construct($message);
    }
}
