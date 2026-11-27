<?php

namespace App\Exceptions;

use RuntimeException;

class WarehouseDispatchConflictException extends RuntimeException
{
    public function __construct(string $message = 'Conflicto de estado o idempotencia en la salida de almacén.')
    {
        parent::__construct($message, 409);
    }
}
