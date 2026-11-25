<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Builds, persists, and verifies R2 dry-run manifests.
 *
 * IMPORTANT SECURITY MODEL:
 * - `manifest_sha256` is an integrity checksum (detects accidental corruption), NOT an authenticated signature.
 * - Authenticity / operator approval for `--apply` is enforced independently via:
 *   1. Mandatory `--expected-manifest-sha256` supplied explicitly by the operator, AND
 *   2. Optional HMAC-SHA256 (`manifest_hmac_sha256`) using an external secret key (`QUEUE_RECOVERY_MANIFEST_HMAC_KEY`).
 */
class QueueRecoveryManifest
{
    public const SCHEMA_VERSION = 2;

    public function __construct(
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {}

    /**
     * Finalizes a manifest with its canonical SHA-256 checksum and optional external HMAC-SHA256.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function finalize(array $body, ?string $hmacKey = null): array
    {
        unset($body['manifest_sha256'], $body['manifest_hmac_sha256']);
        $body['schema_version'] = self::SCHEMA_VERSION;
        $checksum = $this->computeHash($body);

        $final = [
            ...$body,
            'manifest_sha256' => $checksum,
        ];

        $trimmedKey = $hmacKey !== null ? trim($hmacKey) : '';
        if ($trimmedKey !== '') {
            $final['manifest_hmac_sha256'] = hash_hmac('sha256', $checksum, $trimmedKey);
        }

        return $final;
    }

    /**
     * Backwards-compatible alias for finalize().
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function sign(array $body, ?string $hmacKey = null): array
    {
        return $this->finalize($body, $hmacKey);
    }

    /** @param array<string, mixed> $manifest */
    public function writeAtomic(string $path, array $manifest, ?string $hmacKey = null): void
    {
        $final = isset($manifest['manifest_sha256']) ? $manifest : $this->finalize($manifest, $hmacKey);

        try {
            $json = json_encode($final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                'No se pudo codificar el manifiesto en JSON.',
                'manifest_write_failed',
                ['path' => $path],
                $e,
            );
        }

        $this->pathGuard->writeAtomicFile($path, $json."\n", 0600, false, false);
    }

    /**
     * Loads and verifies structural + checksum + independent authenticity (expected hash and/or HMAC).
     *
     * @return array<string, mixed>
     */
    public function loadAndVerify(
        string $path,
        QueuePayloadInspector $inspector,
        ?string $expectedManifestSha256 = null,
        ?string $hmacKey = null,
        bool $requireOperatorApproval = false,
    ): array {
        $safePath = $this->pathGuard->assertSafeInputPath($path);

        if (! is_file($safePath) || ! is_readable($safePath)) {
            throw new QueueRecoveryException(
                "El archivo de manifiesto no existe o no es legible: '{$safePath}'.",
                'manifest_missing',
                ['path' => $safePath],
            );
        }

        $raw = @file_get_contents($safePath);
        if (! is_string($raw) || trim($raw) === '') {
            throw new QueueRecoveryException(
                "El manifiesto está vacío o no pudo leerse: '{$safePath}'.",
                'manifest_invalid',
                ['path' => $safePath],
            );
        }

        try {
            $decoded = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                "El manifiesto no es un JSON válido: '{$safePath}'.",
                'manifest_invalid',
                ['path' => $safePath],
                $e,
            );
        }

        if (! is_array($decoded)) {
            throw new QueueRecoveryException('El manifiesto debe ser un objeto JSON.', 'manifest_invalid', ['path' => $safePath]);
        }

        $declaredHash = $decoded['manifest_sha256'] ?? null;
        if (! is_string($declaredHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $declaredHash)) {
            throw new QueueRecoveryException(
                'El manifiesto carece de un checksum SHA-256 válido (manifest_sha256).',
                'manifest_tampered',
                ['path' => $safePath],
            );
        }

        $body = $decoded;
        unset($body['manifest_sha256'], $body['manifest_hmac_sha256']);
        $computedHash = $this->computeHash($body);

        if (! hash_equals($computedHash, $declaredHash)) {
            throw new QueueRecoveryException(
                'Integridad del manifiesto comprometida: el checksum manifest_sha256 no coincide con el contenido.',
                'manifest_tampered',
                [
                    'path' => $safePath,
                    'declared_sha256' => $declaredHash,
                    'computed_sha256' => $computedHash,
                ],
            );
        }

        // Independent authenticity check #1: Operator-approved expected SHA-256 hash.
        $normalizedExpected = $expectedManifestSha256 !== null ? strtolower(trim($expectedManifestSha256)) : '';
        if ($requireOperatorApproval && $normalizedExpected === '') {
            throw new QueueRecoveryException(
                'Debe proporcionar explícitamente --expected-manifest-sha256=<hash> con el hash del manifiesto aprobado tras revisar el dry-run.',
                'manifest_approval_required',
                ['path' => $safePath],
            );
        }

        if ($normalizedExpected !== '') {
            if (! preg_match('/\A[a-f0-9]{64}\z/', $normalizedExpected) || ! hash_equals($normalizedExpected, $computedHash)) {
                throw new QueueRecoveryException(
                    'El manifiesto en disco no coincide con --expected-manifest-sha256 aprobado por el operador (posible sustitución o alteración con checksum recalculado).',
                    'manifest_approval_mismatch',
                    [
                        'expected_sha256' => $normalizedExpected,
                        'actual_sha256' => $computedHash,
                    ],
                );
            }
        }

        // Independent authenticity check #2: External HMAC-SHA256 secret key.
        $trimmedKey = $hmacKey !== null ? trim($hmacKey) : '';
        $declaredHmac = $decoded['manifest_hmac_sha256'] ?? null;

        if ($trimmedKey !== '' || $declaredHmac !== null) {
            if ($trimmedKey === '') {
                throw new QueueRecoveryException(
                    'El manifiesto está protegido con HMAC-SHA256 pero falta la clave externa de verificación.',
                    'manifest_hmac_missing_key',
                );
            }

            if (! is_string($declaredHmac) || ! preg_match('/\A[a-f0-9]{64}\z/', $declaredHmac)) {
                throw new QueueRecoveryException(
                    'La política HMAC está activa pero el manifiesto carece de manifest_hmac_sha256 válido.',
                    'manifest_hmac_invalid',
                );
            }

            $expectedHmac = hash_hmac('sha256', $computedHash, $trimmedKey);
            if (! hash_equals($expectedHmac, $declaredHmac)) {
                throw new QueueRecoveryException(
                    'Autenticidad del manifiesto comprometida: manifest_hmac_sha256 inválido.',
                    'manifest_hmac_invalid',
                );
            }
        }

        if ((int) ($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new QueueRecoveryException(
                'Versión de esquema de manifiesto no soportada (se requiere schema_version='.self::SCHEMA_VERSION.').',
                'manifest_invalid',
                ['schema_version' => $decoded['schema_version'] ?? null],
            );
        }

        if (! is_string($decoded['incident_id'] ?? null) || trim((string) $decoded['incident_id']) === '') {
            throw new QueueRecoveryException('El manifiesto debe incluir un incident_id válido.', 'manifest_invalid');
        }

        $allowedJobs = $inspector->validateWhitelist(is_array($decoded['allowed_jobs'] ?? null) ? $decoded['allowed_jobs'] : []);
        $authorizedUpperBound = (int) ($decoded['authorized_upper_bound'] ?? 0);
        $lastScannedId = (int) ($decoded['last_scanned_id'] ?? 0);
        $nextStartId = (int) ($decoded['next_start_id'] ?? 0);

        if ($authorizedUpperBound < 0 || $lastScannedId < 0 || $nextStartId < 0) {
            throw new QueueRecoveryException('El manifiesto contiene límites de PK o cursores negativos.', 'manifest_invalid');
        }

        if ($authorizedUpperBound > 0 && $lastScannedId > $authorizedUpperBound) {
            throw new QueueRecoveryException(
                "Inconsistencia de cursor en manifiesto: last_scanned_id ({$lastScannedId}) > authorized_upper_bound ({$authorizedUpperBound}).",
                'upper_bound_violation',
            );
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
            $rowSha256 = (string) ($entry['row_sha256'] ?? '');
            $reservedAt = $entry['reserved_at'] ?? null;

            if ($id <= 0 || $id <= $previousId || isset($seenIds[$id])) {
                throw new QueueRecoveryException(
                    "ID de job inválido, desordenado o duplicado en el manifiesto: {$id}.",
                    'manifest_invalid',
                    ['job_id' => $id],
                );
            }

            if ($authorizedUpperBound > 0 && $id > $authorizedUpperBound) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene job #{$id} superior al límite autorizado authorized_upper_bound={$authorizedUpperBound}.",
                    'upper_bound_violation',
                    ['job_id' => $id, 'authorized_upper_bound' => $authorizedUpperBound],
                );
            }

            if ($lastScannedId > 0 && $id > $lastScannedId) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene job #{$id} superior a last_scanned_id={$lastScannedId}.",
                    'upper_bound_violation',
                    ['job_id' => $id, 'last_scanned_id' => $lastScannedId],
                );
            }

            if (! in_array($jobClass, $allowedJobs, true)) {
                throw new QueueRecoveryException(
                    "El manifiesto incluye una clase no autorizada: '{$jobClass}'.",
                    'invalid_job_whitelist',
                    ['job_id' => $id, 'job_class' => $jobClass],
                );
            }

            if ($queue === ''
                || ! preg_match('/\A[a-f0-9]{64}\z/', $payloadHash)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $rowSha256)
                || ! array_key_exists('attempts', $entry)
                || ! array_key_exists('available_at', $entry)
                || ! array_key_exists('created_at', $entry)) {
                throw new QueueRecoveryException(
                    "Entrada incompleta en manifiesto para job #{$id} (se requieren todas las columnas y hashes).",
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
