<?php

namespace App\Services\Autopartes\Meli;

use Illuminate\Support\Str;

class AutomotivePartMeliErrorSanitizer
{
    public function sanitize(string $message): string
    {
        $sanitized = preg_replace([
            '/\b(password|passwd|pwd|token|secret|authorization|access[_-]?token|refresh[_-]?token)\b(\s*[=:]\s*)([^\s,;]+)/iu',
            '/\b(APP_USR-[A-Za-z0-9_-]{8,})\b/u',
            '/(https?:\/\/)[^@\s\/]+@/iu',
        ], [
            '$1$2[REDACTED]',
            '[REDACTED]',
            '$1[REDACTED]@',
        ], $message) ?? 'Error sin mensaje disponible.';

        return Str::limit($sanitized, 1000, '…');
    }
}
