<?php

namespace App\Services\Autopartes\Publisher;

use Illuminate\Support\Str;

class AutomotivePartMeliPublisherSanitizer
{
    private const SECRET_KEYS = ['authorization', 'access_token', 'refresh_token', 'token', 'password', 'secret', 'client_secret'];

    public function array(array $value): array
    {
        $sanitized = $this->walk($value);
        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $max = max(1024, (int) config('autopartes_meli_publisher.max_persisted_response_bytes', 65535));
        if (is_string($json) && strlen($json) > $max) return ['truncated' => true, 'sha256' => hash('sha256', $json), 'original_bytes' => strlen($json)];
        return $sanitized;
    }

    public function message(?string $message): ?string
    {
        if ($message === null) return null;
        $value = preg_replace([
            '/Bearer\s+[A-Za-z0-9._~-]+/i',
            '/\b(access_token|refresh_token|client_secret|authorization)\b\s*[=:]\s*[^\s,;]+/i',
            '/\bAPP_USR-[A-Za-z0-9_-]+\b/',
        ], ['Bearer [REDACTED]', '$1=[REDACTED]', '[REDACTED]'], $message) ?? 'Error sanitizado';
        return Str::limit($value, 1000, '…');
    }

    private function walk(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::SECRET_KEYS, true)) return '[REDACTED]';
        if (is_string($value)) return $this->message($value);
        if (! is_array($value)) return $value;
        foreach ($value as $childKey => $child) $value[$childKey] = $this->walk($child, is_string($childKey) ? $childKey : null);
        return $value;
    }
}
