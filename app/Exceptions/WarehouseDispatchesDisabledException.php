<?php

namespace App\Exceptions;

use RuntimeException;

class WarehouseDispatchesDisabledException extends RuntimeException
{
    public function __construct(string $message = 'El módulo de Salidas de Almacén se encuentra deshabilitado.')
    {
        parent::__construct($message, 404);
    }
}
