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

        $normalized = mb_strtoupper(Str::ascii(trim($brand)), 'UTF-8');
        $normalized = preg_replace('/[^A-Z0-9]+/u', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? '';

        return $normalized === '' ? null : $normalized;
    }
}
