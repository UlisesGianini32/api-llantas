<?php

namespace App\Services\Autopartes\Publisher;

use RuntimeException;

class AutomotivePartMeliPublisherException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly bool $transient = false,
        public readonly bool $ambiguousResult = false,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        public readonly array $response = [],
        ?\Throwable $previous = null,
    ) { parent::__construct($message, 0, $previous); }
}
