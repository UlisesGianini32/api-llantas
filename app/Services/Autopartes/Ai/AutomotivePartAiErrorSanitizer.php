<?php

namespace App\Services\Autopartes\Ai;

use Illuminate\Support\Str;

class AutomotivePartAiErrorSanitizer
{
    public function sanitize(string $message): string
    {
        $sanitized = preg_replace([
            '/\b(password|passwd|pwd|token|secret|authorization|api[_-]?key)\b(\s*[=:]\s*)([^\s,;]+)/iu',
            '/\b(sk-[A-Za-z0-9_-]{8,})\b/u',
            '/(https?:\/\/)[^@\s\/]+@/iu',
        ], [
            '$1$2[REDACTED]',
            '[REDACTED]',
            '$1[REDACTED]@',
        ], $message) ?? 'Error sin mensaje disponible.';

        return Str::limit($sanitized, 1000, '…');
    }
}
