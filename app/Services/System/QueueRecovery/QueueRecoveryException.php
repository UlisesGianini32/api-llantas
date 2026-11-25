<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use RuntimeException;
use Throwable;

class QueueRecoveryException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
