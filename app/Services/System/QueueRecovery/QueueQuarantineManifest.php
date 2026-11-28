<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Generator;
use JsonException;
use Throwable;

/**
 * Streaming NDJSON manifest writer and single-FD verifier for R4.2 job quarantine.
 *
 * Designed for 100k–2.76M historical jobs under strict memory budgets (< 128 MiB):
 * - Writes header + candidate lines + completion footer incrementally via `QueueDiagnosticArtifactWriter`
 *   (exclusive `xb` 0600 temp file, `fflush`, `fsync`, atomic non-clobbering `link()` + `unlink()`,
 *   and post-publish disk SHA-256 verification).
 * - Never holds all candidates in memory during dry-run, verification, apply, or restore.
 * - Single-FD verification checks fstat/lstat identity, rejects symlinks, rejects incomplete manifests
 *   lacking a valid `footer` with `status="complete"`, validates ascending IDs & bounds, verifies
 *   `candidates_sha256`, `manifest_sha256`, operator `--expected-manifest-sha256`, and optional HMAC-SHA256.
 */
class QueueQuarantineManifest
{
    public const FORMAT_VERSION = 'quarantine-ndjson-1.0';

    public function __construct(
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
        private readonly QueueDiagnosticArtifactWriter $artifactWriter = new QueueDiagnosticArtifactWriter,
        private readonly QueueQuarantinePolicy $policy = new QueueQuarantinePolicy,
    ) {}

    /**
     * Opens an exclusive 0600 NDJSON manifest stream and writes the canonical header record.
     *
     * @param  array<string, mixed>  $headerFields
     * @return array<string, mixed>
     */
    public function openWriter(string $targetPath, array $headerFields): array
    {
        $header = [
            'record_type' => 'header',
            'format_version' => self::FORMAT_VERSION,
            ...$headerFields,
        ];
        unset($header['manifest_sha256'], $header['manifest_hmac_sha256']);

        $stream = $this->artifactWriter->openExclusiveStream($targetPath, disallowOverwrite: true, disallowInsideRepo: false);
        try {
            $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->artifactWriter->writeStreamChunk($stream, $headerJson."\n", countRecord: false);
        } catch (Throwable $e) {
            $this->artifactWriter->abortStream($stream);
            throw $e;
        }

        return [
            'stream' => $stream,
            'header' => $header,
            'candidates_hash_ctx' => hash_init('sha256'),
            'candidates_written' => 0,
            'last_candidate_id' => 0,
        ];
    }

    /**
     * Appends one candidate record to the open manifest stream (O(1) memory).
     *
     * @param  array<string, mixed>  $writerState
     * @param  array<string, mixed>  $candidate
     */
    public function appendCandidate(array &$writerState, array $candidate): void
    {
        $jobId = (int) ($candidate['id'] ?? 0);
        if ($jobId <= 0 || $jobId <= (int) $writerState['last_candidate_id']) {
            throw new QueueRecoveryException(
                "Candidato de cuarentena inválido o fuera de orden al escribir manifiesto: job #{$jobId}.",
                'manifest_write_failed',
                ['job_id' => $jobId],
            );
        }

        $record = [
            'record_type' => 'candidate',
            ...$candidate,
        ];

        try {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $this->artifactWriter->writeStreamChunk($writerState['stream'], $line, countRecord: true);
            hash_update($writerState['candidates_hash_ctx'], $line);
            $writerState['candidates_written']++;
            $writerState['last_candidate_id'] = $jobId;
        } catch (Throwable $e) {
            $this->abortWriter($writerState);
            throw $e;
        }
    }

    /**
     * Writes the completion footer (with canonical `manifest_sha256` & optional external HMAC-SHA256)
     * and atomically publishes the manifest via `link()` + `unlink()` + disk SHA-256 verification.
     *
     * @param  array<string, mixed>  $writerState
     * @param  array<string, mixed>  $footerFields
     * @return array{
     *     path: string,
     *     manifest_sha256: string,
     *     candidates_sha256: string,
     *     file_sha256: string,
     *     hmac_present: bool,
     *     size_bytes: int,
     *     candidates_count: int,
     *     footer: array<string, mixed>
     * }
     */
    public function finalizeAndPublish(array &$writerState, array $footerFields, ?string $hmacKey = null): array
    {
        try {
            $candidatesSha = hash_final($writerState['candidates_hash_ctx']);
            $footerBody = [
                'record_type' => 'footer',
                'status' => 'complete',
                ...$footerFields,
                'candidates_sha256' => $candidatesSha,
            ];
            unset($footerBody['manifest_sha256'], $footerBody['manifest_hmac_sha256']);

            $manifestSha = $this->computeManifestSha256($writerState['header'], $footerBody);
            $footer = [
                ...$footerBody,
                'manifest_sha256' => $manifestSha,
            ];

            $trimmedKey = $hmacKey !== null ? trim($hmacKey) : '';
            if ($trimmedKey !== '') {
                $footer['manifest_hmac_sha256'] = hash_hmac('sha256', $manifestSha, $trimmedKey);
            }

            $footerLine = json_encode($footer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $this->artifactWriter->writeStreamChunk($writerState['stream'], $footerLine, countRecord: false);

            $published = $this->artifactWriter->publishStream($writerState['stream']);

            return [
                'path' => $published['path'],
                'manifest_sha256' => $manifestSha,
                'candidates_sha256' => $candidatesSha,
                'file_sha256' => $published['sha256'],
                'hmac_present' => isset($footer['manifest_hmac_sha256']),
                'size_bytes' => $published['size_bytes'],
                'candidates_count' => (int) $writerState['candidates_written'],
                'footer' => $footer,
            ];
        } catch (Throwable $e) {
            $this->abortWriter($writerState);
            throw $e;
        }
    }

    /** @param array<string, mixed>|null $writerState */
    public function abortWriter(?array &$writerState): void
    {
        if (! is_array($writerState)) {
            return;
        }
        if (isset($writerState['stream']) && is_array($writerState['stream'])) {
            $stream = $writerState['stream'];
            $this->artifactWriter->abortStream($stream);
            $writerState['stream'] = $stream;
        }
    }

    /**
     * Verifies a streaming NDJSON quarantine manifest in O(1) memory over a single open FD:
     * - Rejects symlinks, missing files, truncated/incomplete manifests without `footer.status="complete"`,
     *   out-of-order/duplicate IDs, out-of-bounds IDs, unauthorized classes/queues, tampered SHA-256,
     *   mismatched `--expected-manifest-sha256`, invalid HMAC, and concurrent file modification.
     *
     * @return array{
     *     path: string,
     *     header: array<string, mixed>,
     *     footer: array<string, mixed>,
     *     manifest_sha256: string,
     *     candidates_sha256: string,
     *     file_sha256: string,
     *     hmac_authenticated: bool,
     *     candidates_count: int,
     *     stat: array<string, mixed>
     * }
     */
    public function verify(
        string $path,
        ?string $expectedManifestSha256 = null,
        ?string $hmacKey = null,
        bool $requireExpectedSha256 = true,
    ): array {
        $safePath = $this->pathGuard->assertSafeInputPath($path);
        if (! is_file($safePath) || ! is_readable($safePath)) {
            throw new QueueRecoveryException(
                "El manifiesto de cuarentena no existe o no es legible: '{$safePath}'.",
                'manifest_missing',
                ['path' => $safePath],
            );
        }

        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto de cuarentena: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            $initialStat = $this->captureVerifiedStat($fh, $safePath);
            if ((int) ($initialStat['size'] ?? 0) === 0) {
                throw new QueueRecoveryException("El manifiesto de cuarentena está vacío: '{$safePath}'.", 'manifest_incomplete');
            }

            $fileHashCtx = hash_init('sha256');
            $candidatesHashCtx = hash_init('sha256');
            $header = null;
            $footer = null;
            $lineNumber = 0;
            $previousId = 0;
            $candidatesCount = 0;
            $authorizedEndId = 0;
            $expectedJobClass = '';
            $expectedSourceQueue = '';

            while (($line = fgets($fh)) !== false) {
                hash_update($fileHashCtx, $line);
                $lineNumber++;

                if (trim($line) === '') {
                    continue;
                }

                if (! str_ends_with($line, "\n")) {
                    throw new QueueRecoveryException(
                        "Manifiesto de cuarentena truncado (línea #{$lineNumber} sin salto de línea final): '{$safePath}'.",
                        'manifest_incomplete',
                        ['path' => $safePath, 'line' => $lineNumber],
                    );
                }

                try {
                    $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new QueueRecoveryException(
                        "JSON inválido en manifiesto de cuarentena línea #{$lineNumber}: '{$safePath}'.",
                        'manifest_invalid',
                        ['path' => $safePath, 'line' => $lineNumber],
                        $e,
                    );
                }

                if (! is_array($record) || ! is_string($record['record_type'] ?? null)) {
                    throw new QueueRecoveryException(
                        "Registro inválido sin record_type en manifiesto línea #{$lineNumber}.",
                        'manifest_invalid',
                        ['line' => $lineNumber],
                    );
                }

                $type = (string) $record['record_type'];

                if ($footer !== null) {
                    throw new QueueRecoveryException(
                        "Datos inesperados después del footer en manifiesto línea #{$lineNumber}.",
                        'manifest_tampered',
                        ['line' => $lineNumber],
                    );
                }

                if ($type === 'header') {
                    if ($header !== null) {
                        throw new QueueRecoveryException('El manifiesto contiene encabezados duplicados.', 'manifest_invalid');
                    }
                    if (($record['format_version'] ?? null) !== self::FORMAT_VERSION) {
                        throw new QueueRecoveryException(
                            'Versión de manifiesto de cuarentena no soportada (requerida: '.self::FORMAT_VERSION.').',
                            'manifest_invalid',
                            ['format_version' => $record['format_version'] ?? null],
                        );
                    }
                    if (! is_string($record['incident_id'] ?? null) || trim((string) $record['incident_id']) === '') {
                        throw new QueueRecoveryException('El manifiesto carece de incident_id válido.', 'manifest_invalid');
                    }

                    $mapping = $this->policy->resolveAuthorizedMapping(
                        (string) ($record['job_class'] ?? ''),
                        is_string($record['source_queue'] ?? null) ? (string) $record['source_queue'] : null,
                        is_string($record['target_queue'] ?? null) ? (string) $record['target_queue'] : null,
                    );

                    $expectedJobClass = $mapping['job_class'];
                    $expectedSourceQueue = $mapping['source_queue'];
                    $authorizedEndId = (int) ($record['authorized_end_id'] ?? 0);
                    $startId = (int) ($record['start_id'] ?? 0);
                    if ($authorizedEndId < 0 || $startId < 0 || $startId > $authorizedEndId) {
                        throw new QueueRecoveryException('Rango de IDs inválido en encabezado del manifiesto.', 'manifest_invalid');
                    }

                    $header = $record;

                    continue;
                }

                if ($header === null) {
                    throw new QueueRecoveryException('El manifiesto debe iniciar con un registro header.', 'manifest_invalid');
                }

                if ($type === 'candidate') {
                    $id = (int) ($record['id'] ?? 0);
                    if ($id <= 0 || $id <= $previousId) {
                        throw new QueueRecoveryException(
                            "ID de job inválido, desordenado o duplicado en manifiesto: #{$id}.",
                            'manifest_invalid',
                            ['job_id' => $id],
                        );
                    }
                    if ($id > $authorizedEndId) {
                        throw new QueueRecoveryException(
                            "El manifiesto contiene job #{$id} superior a authorized_end_id={$authorizedEndId}.",
                            'upper_bound_violation',
                            ['job_id' => $id, 'authorized_end_id' => $authorizedEndId],
                        );
                    }
                    if ((string) ($record['job_class'] ?? '') !== $expectedJobClass) {
                        throw new QueueRecoveryException(
                            "Candidato #{$id} con clase incoherente respecto al encabezado del manifiesto.",
                            'manifest_tampered',
                            ['job_id' => $id],
                        );
                    }
                    if ((string) ($record['queue'] ?? '') !== $expectedSourceQueue) {
                        throw new QueueRecoveryException(
                            "Candidato #{$id} con cola incoherente respecto al encabezado del manifiesto.",
                            'manifest_tampered',
                            ['job_id' => $id],
                        );
                    }
                    if (($record['reserved_at'] ?? null) !== null) {
                        throw new QueueRecoveryException(
                            "El manifiesto contiene un job reservado (#{$id}); rechazado por seguridad.",
                            'reserved_jobs_detected',
                            ['job_id' => $id],
                        );
                    }
                    if (! preg_match('/\A[a-f0-9]{64}\z/', (string) ($record['payload_hash'] ?? ''))
                        || ! preg_match('/\A[a-f0-9]{64}\z/', (string) ($record['row_sha256'] ?? ''))
                        || ! array_key_exists('attempts', $record)
                        || ! array_key_exists('available_at', $record)
                        || ! array_key_exists('created_at', $record)) {
                        throw new QueueRecoveryException(
                            "Candidato incompleto en manifiesto para job #{$id}.",
                            'manifest_invalid',
                            ['job_id' => $id],
                        );
                    }

                    hash_update($candidatesHashCtx, $line);
                    $previousId = $id;
                    $candidatesCount++;

                    continue;
                }

                if ($type === 'footer') {
                    $footer = $record;

                    continue;
                }

                throw new QueueRecoveryException("record_type desconocido '{$type}' en manifiesto línea #{$lineNumber}.", 'manifest_invalid');
            }

            $this->assertUnchangedSinceOpen($fh, $safePath, $initialStat);

            if ($header === null || $footer === null || ($footer['status'] ?? null) !== 'complete') {
                throw new QueueRecoveryException(
                    "Manifiesto de cuarentena incompleto (falta footer con status='complete'): '{$safePath}'.",
                    'manifest_incomplete',
                    ['path' => $safePath],
                );
            }

            $declaredCandidatesCount = (int) ($footer['totals']['candidates'] ?? -1);
            if ($declaredCandidatesCount !== $candidatesCount) {
                throw new QueueRecoveryException(
                    "Discrepancia de conteo en manifiesto: footer declara {$declaredCandidatesCount} candidatos, pero se leyeron {$candidatesCount}.",
                    'manifest_tampered',
                );
            }

            $computedCandidatesSha = hash_final($candidatesHashCtx);
            $declaredCandidatesSha = (string) ($footer['candidates_sha256'] ?? '');
            if (! preg_match('/\A[a-f0-9]{64}\z/', $declaredCandidatesSha) || ! hash_equals($computedCandidatesSha, $declaredCandidatesSha)) {
                throw new QueueRecoveryException(
                    'Integridad comprometida: candidates_sha256 no coincide con las líneas del manifiesto.',
                    'manifest_tampered',
                );
            }

            $declaredManifestSha = (string) ($footer['manifest_sha256'] ?? '');
            if (! preg_match('/\A[a-f0-9]{64}\z/', $declaredManifestSha)) {
                throw new QueueRecoveryException('El manifiesto carece de manifest_sha256 válido.', 'manifest_tampered');
            }

            $footerBody = $footer;
            unset($footerBody['manifest_sha256'], $footerBody['manifest_hmac_sha256']);
            $computedManifestSha = $this->computeManifestSha256($header, $footerBody);

            if (! hash_equals($computedManifestSha, $declaredManifestSha)) {
                throw new QueueRecoveryException(
                    'Integridad del manifiesto comprometida: manifest_sha256 no coincide con encabezado/resumen.',
                    'manifest_tampered',
                    ['declared_sha256' => $declaredManifestSha, 'computed_sha256' => $computedManifestSha],
                );
            }

            $fileSha256 = hash_final($fileHashCtx);

            // Mandatory independent operator approval via --expected-manifest-sha256
            $normalizedExpected = $expectedManifestSha256 !== null ? strtolower(trim($expectedManifestSha256)) : '';
            if ($requireExpectedSha256 && $normalizedExpected === '') {
                throw new QueueRecoveryException(
                    'Debe especificar --expected-manifest-sha256=<hash> aprobado por el operador.',
                    'manifest_approval_required',
                );
            }
            if ($normalizedExpected !== '') {
                $matches = preg_match('/\A[a-f0-9]{64}\z/', $normalizedExpected) === 1
                    && (hash_equals($normalizedExpected, $computedManifestSha) || hash_equals($normalizedExpected, $fileSha256));
                if (! $matches) {
                    throw new QueueRecoveryException(
                        'El manifiesto en disco no coincide con --expected-manifest-sha256 aprobado.',
                        'manifest_approval_mismatch',
                        ['expected_sha256' => $normalizedExpected, 'actual_sha256' => $computedManifestSha],
                    );
                }
            }

            // Optional external HMAC-SHA256 authentication
            $hmacAuthenticated = false;
            $trimmedKey = $hmacKey !== null ? trim($hmacKey) : '';
            $declaredHmac = $footer['manifest_hmac_sha256'] ?? null;
            if ($trimmedKey !== '' || $declaredHmac !== null) {
                if ($trimmedKey === '') {
                    throw new QueueRecoveryException(
                        'El manifiesto está firmado con HMAC-SHA256 pero falta la clave externa configurada.',
                        'manifest_hmac_missing_key',
                    );
                }
                if (! is_string($declaredHmac) || ! preg_match('/\A[a-f0-9]{64}\z/', $declaredHmac)) {
                    throw new QueueRecoveryException('El manifiesto carece de manifest_hmac_sha256 válido.', 'manifest_hmac_invalid');
                }
                $expectedHmac = hash_hmac('sha256', $computedManifestSha, $trimmedKey);
                if (! hash_equals($expectedHmac, $declaredHmac)) {
                    throw new QueueRecoveryException('Firma manifest_hmac_sha256 inválida.', 'manifest_hmac_invalid');
                }
                $hmacAuthenticated = true;
            }

            return [
                'path' => $safePath,
                'header' => $header,
                'footer' => $footer,
                'manifest_sha256' => $computedManifestSha,
                'candidates_sha256' => $computedCandidatesSha,
                'file_sha256' => $fileSha256,
                'hmac_authenticated' => $hmacAuthenticated,
                'candidates_count' => $candidatesCount,
                'stat' => $initialStat,
            ];
        } finally {
            fclose($fh);
        }
    }

    /**
     * Streams candidate batches of size `$batchSize` directly from a verified NDJSON manifest in O(batchSize) RAM,
     * checking file identity (`stat`) and optional ID bounds (`$afterId`, `$minId`, `$maxId`) or `$onlyIds` set.
     *
     * @param  array<string, mixed>  $verifiedManifest
     * @param  array<int, bool>|null  $onlyIds
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function streamCandidateBatches(
        array $verifiedManifest,
        int $batchSize,
        int $afterId = 0,
        ?int $minId = null,
        ?int $maxId = null,
        ?array $onlyIds = null,
    ): Generator {
        $safePath = (string) $verifiedManifest['path'];
        $expectedStat = (array) $verifiedManifest['stat'];
        $expectedCandidatesSha = (string) $verifiedManifest['candidates_sha256'];
        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto para streaming: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            $this->assertUnchangedSinceOpen($fh, $safePath, $expectedStat);
            $candidatesHashCtx = hash_init('sha256');
            $batch = [];

            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($record) || ($record['record_type'] ?? null) !== 'candidate') {
                    continue;
                }

                hash_update($candidatesHashCtx, $line);

                $id = (int) $record['id'];
                if ($id <= $afterId) {
                    continue;
                }
                if ($minId !== null && $id < $minId) {
                    continue;
                }
                if ($maxId !== null && $id > $maxId) {
                    continue;
                }
                if ($onlyIds !== null && ! isset($onlyIds[$id])) {
                    continue;
                }

                $batch[] = $record;
                if (count($batch) >= $batchSize) {
                    yield $batch;
                    $batch = [];
                }
            }

            if ($batch !== []) {
                yield $batch;
            }

            // Only assert full hash when generator ran to EOF without early caller break.
            $this->assertUnchangedSinceOpen($fh, $safePath, $expectedStat);
            if (! hash_equals($expectedCandidatesSha, hash_final($candidatesHashCtx))) {
                throw new QueueRecoveryException(
                    "Modificación concurrente detectada en candidatos del manifiesto durante ejecución: '{$safePath}'.",
                    'manifest_concurrent_modification',
                );
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Looks up specific candidate records by ID (used during crash recovery of unresolved batch intents).
     *
     * @param  array<string, mixed>  $verifiedManifest
     * @param  list<int>  $targetIds
     * @return array<int, array<string, mixed>>
     */
    public function findCandidatesByIds(array $verifiedManifest, array $targetIds): array
    {
        if ($targetIds === []) {
            return [];
        }

        $lookup = [];
        foreach ($targetIds as $tid) {
            $lookup[(int) $tid] = true;
        }
        $maxTarget = max(array_keys($lookup));

        $found = [];
        $safePath = (string) $verifiedManifest['path'];
        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto para inspección de recuperación: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($record) || ($record['record_type'] ?? null) !== 'candidate') {
                    continue;
                }
                $id = (int) $record['id'];
                if (isset($lookup[$id])) {
                    $found[$id] = $record;
                    if (count($found) === count($lookup)) {
                        break;
                    }
                }
                if ($id > $maxTarget) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $footerBody
     */
    private function computeManifestSha256(array $header, array $footerBody): string
    {
        $canonical = $this->canonicalize([
            'header' => $header,
            'footer' => $footerBody,
        ]);

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
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonicalize($v);
        }

        return $value;
    }

    /**
     * @param  resource  $fh
     * @return array<string, mixed>
     */
    private function captureVerifiedStat($fh, string $safePath): array
    {
        clearstatcache(true, $safePath);
        $this->pathGuard->assertSafeInputPath($safePath);
        $fstat = fstat($fh);
        $lstat = @lstat($safePath);
        if (! is_array($fstat) || ! is_array($lstat) || is_link($safePath)) {
            throw new QueueRecoveryException("Identidad de archivo de manifiesto inválida: '{$safePath}'.", 'manifest_concurrent_modification');
        }
        if ((int) $fstat['ino'] !== (int) $lstat['ino']
            || (int) $fstat['dev'] !== (int) $lstat['dev']
            || (int) $fstat['size'] !== (int) $lstat['size']) {
            throw new QueueRecoveryException("El manifiesto cambió durante la apertura: '{$safePath}'.", 'manifest_concurrent_modification');
        }

        return $fstat;
    }

    /**
     * @param  resource  $fh
     * @param  array<string, mixed>  $initialStat
     */
    private function assertUnchangedSinceOpen($fh, string $safePath, array $initialStat): void
    {
        clearstatcache(true, $safePath);
        $this->pathGuard->assertSafeInputPath($safePath);
        $fstat = fstat($fh);
        $lstat = @lstat($safePath);
        if (! is_array($fstat) || ! is_array($lstat) || is_link($safePath)) {
            throw new QueueRecoveryException("El manifiesto cambió o fue sustituido por symlink: '{$safePath}'.", 'manifest_concurrent_modification');
        }
        if ((int) $fstat['size'] !== (int) $initialStat['size']
            || (int) $fstat['ino'] !== (int) $initialStat['ino']
            || (int) $fstat['dev'] !== (int) $initialStat['dev']
            || (int) $lstat['size'] !== (int) $initialStat['size']
            || (int) $lstat['ino'] !== (int) $initialStat['ino']
            || (int) $lstat['dev'] !== (int) $initialStat['dev']
            || (int) $fstat['mtime'] !== (int) $initialStat['mtime']
            || (int) $lstat['mtime'] !== (int) $initialStat['mtime']) {
            throw new QueueRecoveryException("Modificación concurrente detectada en el manifiesto: '{$safePath}'.", 'manifest_concurrent_modification');
        }
    }
}
