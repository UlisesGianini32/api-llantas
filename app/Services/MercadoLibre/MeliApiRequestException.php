<?php

namespace App\Services\MercadoLibre;

use RuntimeException;
use Throwable;

class MeliApiRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
