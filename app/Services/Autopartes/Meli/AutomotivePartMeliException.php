<?php

namespace App\Services\Autopartes\Meli;

use RuntimeException;
use Throwable;

class AutomotivePartMeliException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $transient = false,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
