<?php

namespace App\Services\Autopartes\MediaPricing;

use RuntimeException;

class AutomotivePartMediaPricingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
