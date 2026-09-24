<?php

namespace App\Exceptions;

use RuntimeException;

class InventoryInsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $available,
        public readonly int $requested,
        public readonly string $locationCode,
    ) {
        parent::__construct(
            "Stock insuficiente en {$this->locationCode}. Disponible: {$this->available}, solicitado: {$this->requested}."
        );
    }
}
