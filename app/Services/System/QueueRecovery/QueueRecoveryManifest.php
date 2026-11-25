<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

class QueueRecoveryManifest
{
    public const SCHEMA_VERSION = 1;

    /**
     * Builds a signed, tamper-evident manifest array from dry-run data.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sign(array $body): array
    {
        unset($body['manifest_sha256']);
        $body['schema_version'] = self::SCHEMA_VERSION;
        $hash = $this->computeHash($body);

        return [
            ...$body,
            'manifest_sha256' => $hash,
        ];
    }

    /** @param array<string, mixed> $manifest */
    public function writeAtomic(string $path, array $manifest): void
    {
        $signed = isset($manifest['manifest_sha256']) ? $manifest : $this->sign($manifest);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new QueueRecoveryException(
                "No se pudo crear el directorio para el manifiesto: '{$dir}'.",
                'manifest_write_failed',
                ['path' => $path],
            );
        }

        try {
            $json = json_encode($signed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                'No se pudo codificar el manifiesto en JSON.',
                'manifest_write_failed',
                ['path' => $path],
                $e,
            );
        }

        $tmp = $path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new QueueRecoveryException(
                "No se pudo escribir el archivo temporal del manifiesto: '{$tmp}'.",
                'manifest_write_failed',
                ['path' => $path],
            );
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new QueueRecoveryException(
                "No se pudo mover atómicamente el manifiesto a '{$path}'.",
                'manifest_write_failed',
                ['path' => $path],
            );
        }
    }

    /**
     * Loads and cryptographically verifies a manifest file.
     *
     * @return array<string, mixed>
     */
    public function loadAndVerify(string $path, QueuePayloadInspector $inspector): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new QueueRecoveryException(
                "El archivo de manifiesto no existe o no es legible: '{$path}'.",
                'manifest_missing',
                ['path' => $path],
            );
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            throw new QueueRecoveryException(
                "El manifiesto está vacío o no pudo leerse: '{$path}'.",
                'manifest_invalid',
                ['path' => $path],
            );
        }

        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                "El manifiesto no es un JSON válido: '{$path}'.",
                'manifest_invalid',
                ['path' => $path],
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new QueueRecoveryException('El manifiesto debe ser un objeto JSON.', 'manifest_invalid', ['path' => $path]);
        }

        $declaredHash = $decoded['manifest_sha256'] ?? null;
        if (! is_string($declaredHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $declaredHash)) {
            throw new QueueRecoveryException(
                'El manifiesto carece de una firma SHA-256 válida (manifest_sha256).',
                'manifest_tampered',
                ['path' => $path],
            );
        }

        $body = $decoded;
        unset($body['manifest_sha256']);
        $computedHash = $this->computeHash($body);

        if (! hash_equals($computedHash, $declaredHash)) {
            throw new QueueRecoveryException(
                'Integridad del manifiesto comprometida: el hash SHA-256 no coincide con el contenido.',
                'manifest_tampered',
                [
                    'path' => $path,
                    'declared_sha256' => $declaredHash,
                    'computed_sha256' => $computedHash,
                ],
            );
        }

        if ((int) ($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new QueueRecoveryException(
                'Versión de esquema de manifiesto no soportada.',
                'manifest_invalid',
                ['schema_version' => $decoded['schema_version'] ?? null],
            );
        }

        if (! is_string($decoded['incident_id'] ?? null) || trim((string) $decoded['incident_id']) === '') {
            throw new QueueRecoveryException('El manifiesto debe incluir un incident_id válido.', 'manifest_invalid');
        }

        $allowedJobs = $inspector->validateWhitelist(is_array($decoded['allowed_jobs'] ?? null) ? $decoded['allowed_jobs'] : []);
        $endId = (int) ($decoded['end_id'] ?? 0);
        if ($endId < 0) {
            throw new QueueRecoveryException('El manifiesto contiene un límite superior de PK inválido.', 'manifest_invalid');
        }

        $entries = $decoded['entries'] ?? null;
        if (! is_array($entries)) {
            throw new QueueRecoveryException('El manifiesto debe contener el arreglo entries.', 'manifest_invalid');
        }

        $seenIds = [];
        $previousId = 0;

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new QueueRecoveryException("Entrada #{$index} del manifiesto inválida.", 'manifest_invalid');
            }

            $id = (int) ($entry['id'] ?? 0);
            $jobClass = (string) ($entry['job_class'] ?? '');
            $queue = (string) ($entry['queue'] ?? '');
            $payloadHash = (string) ($entry['payload_hash'] ?? '');
            $reservedAt = $entry['reserved_at'] ?? null;

            if ($id <= 0 || $id <= $previousId || isset($seenIds[$id])) {
                throw new QueueRecoveryException(
                    "ID de job inválido, desordenado o duplicado en el manifiesto: {$id}.",
                    'manifest_invalid',
                    ['job_id' => $id],
                );
            }

            if ($endId > 0 && $id > $endId) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene job #{$id} superior al límite autorizado end_id={$endId}.",
                    'upper_bound_violation',
                    ['job_id' => $id, 'end_id' => $endId],
                );
            }

            if (! in_array($jobClass, $allowedJobs, true)) {
                throw new QueueRecoveryException(
                    "El manifiesto incluye una clase no autorizada: '{$jobClass}'.",
                    'invalid_job_whitelist',
                    ['job_id' => $id, 'job_class' => $jobClass],
                );
            }

            if ($queue === '' || ! preg_match('/\A[a-f0-9]{64}\z/', $payloadHash)) {
                throw new QueueRecoveryException(
                    "Entrada inválida en manifiesto para job #{$id} (cola o payload_hash).",
                    'manifest_invalid',
                    ['job_id' => $id],
                );
            }

            if ($reservedAt !== null) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene un trabajo reservado (job #{$id}); abortando por seguridad.",
                    'reserved_jobs_detected',
                    ['job_id' => $id],
                );
            }

            $seenIds[$id] = true;
            $previousId = $id;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $body */
    public function computeHash(array $body): string
    {
        $canonical = $this->canonicalize($body);

        return hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
