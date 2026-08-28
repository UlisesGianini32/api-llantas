<?php

namespace App\Services\Autopartes\Drafts;

use RuntimeException;

class AutomotivePartDraftException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
