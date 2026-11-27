<?php

namespace App\Exceptions;

use RuntimeException;

class WarehouseDispatchReadOnlyException extends RuntimeException
{
    public function __construct(string $message = 'El módulo de Salidas de Almacén se encuentra en modo de solo consulta.')
    {
        parent::__construct($message, 503);
    }
}
