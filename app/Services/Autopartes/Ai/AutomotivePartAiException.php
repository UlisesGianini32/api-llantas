<?php

namespace App\Services\Autopartes\Ai;

use RuntimeException;
use Throwable;

class AutomotivePartAiException extends RuntimeException
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
