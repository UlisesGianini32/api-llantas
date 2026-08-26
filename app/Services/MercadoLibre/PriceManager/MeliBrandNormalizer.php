<?php

namespace App\Services\MercadoLibre\PriceManager;

use Illuminate\Support\Str;

class MeliBrandNormalizer
{
    public function normalize(?string $brand): ?string
    {
        if ($brand === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim(Str::ascii($brand))) ?? '';

        return $normalized === '' ? null : mb_strtoupper($normalized, 'UTF-8');
    }
}
