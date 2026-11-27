<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use HashContext;
use JsonException;

/**
 * Memory-bounded, single-FD streaming reader for R2/R2.1 JSON manifests and NDJSON manifests.
 *
 * Security & Integrity Guarantees (R2-DIAG.1: D1, D2, D4):
 * - Single open file descriptor across ALL stages (format detection, Pass 1, Pass 2, fstat/lstat
 *   identity checks, and Pass 1 vs Pass 2 `entries` byte-hash comparison) — eliminates TOCTOU
 *   replacement, concurrent modification, truncation, and symlink swap attacks (D4).
 * - Preserves ALL R2.1 `QueueRecoveryManifest::loadAndVerify()` structural, whitelist, upper-bound,
 *   ascending-ID, required-column, non-reserved, canonical SHA-256, and HMAC guarantees for
 *   `schema_version=2` JSON manifests, plus rejects duplicate JSON keys at top-level and entry level (D4).
 * - Exact duplicate job ID detection up to `$maxExactSeenJobTracking` (default 250,000), transitioning
 *   fail-closed to strict ascending order (`id > max_seen_job_id`) beyond the budget so NO duplicate
 *   or out-of-order ID can ever be silently accepted (D1).
 * - Accounts for EVERY identifiable `meli_order` in diagnostic formats — including orders whose ONLY
 *   manifest jobs are reserved — while keeping unreserved, reserved, invalid, conflicting-identity,
 *   other-class, and duplicate job counts strictly disjoint (zero double counting) (D2).
 */
class QueueManifestStreamReader
{
    public const MAX_JOB_ID_SAMPLE_PER_ORDER = 25;

    public const MAX_ANOMALY_SAMPLE = 200;

    public const MAX_EXACT_SEEN_JOB_TRACKING = 250000;

    private const READ_BUFFER_BYTES = 65536;

    /** @var (callable(string): void)|null */
    private $betweenPassesHook = null;

    public function __construct(
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
        private readonly int $maxExactSeenJobTracking = self::MAX_EXACT_SEEN_JOB_TRACKING,
    ) {}

    /**
     * Test hook invoked strictly between Pass 1 and Pass 2 (or before final stat check in NDJSON)
     * to simulate concurrent file replacement, in-place tampering, truncation, or symlink swap (D4).
     *
     * @param  (callable(string): void)|null  $hook
     */
    public function setBetweenPassesHook(?callable $hook): void
    {
        $this->betweenPassesHook = $hook;
    }

    /**
     * Reads, verifies, and aggregates a manifest into compact per-order maps over a single open FD.
     *
     * @return array<string, mixed>
     */
    public function readAndVerify(
        string $path,
        QueuePayloadInspector $inspector,
        ?string $expectedManifestSha256 = null,
        ?string $hmacKey = null,
    ): array {
        $safePath = $this->pathGuard->assertSafeInputPath($path);

        if (! is_file($safePath) || ! is_readable($safePath)) {
            throw new QueueRecoveryException(
                "El archivo de manifiesto no existe o no es legible: '{$safePath}'.",
                'manifest_missing',
                ['path' => $safePath],
            );
        }

        // Open ONCE and keep this single FD through format detection, Pass 1, Pass 2, and stat verification (D4).
        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            $initialStat = $this->captureVerifiedStat($fh, $safePath);
            if ((int) ($initialStat['size'] ?? 0) === 0) {
                throw new QueueRecoveryException(
                    "El manifiesto está vacío o no pudo leerse: '{$safePath}'.",
                    'manifest_invalid',
                    ['path' => $safePath],
                );
            }

            $isNdjson = $this->detectNdjsonFormatFromHandle($fh, $safePath);
            if (rewind($fh) === false) {
                throw new QueueRecoveryException("No se pudo rebobinar el descriptor del manifiesto: '{$safePath}'.", 'manifest_invalid');
            }

            if ($isNdjson) {
                return $this->readNdjsonManifestFromHandle(
                    $fh,
                    $safePath,
                    $initialStat,
                    $inspector,
                    $expectedManifestSha256,
                    $hmacKey,
                );
            }

            return $this->readJsonManifestStreamingFromHandle(
                $fh,
                $safePath,
                $initialStat,
                $inspector,
                $expectedManifestSha256,
                $hmacKey,
            );
        } finally {
            fclose($fh);
        }
    }

    /**
     * Captures fstat() on the open descriptor and confirms lstat($path) refers to the exact same
     * regular non-symlink file (device + inode + size).
     *
     * @param  resource  $fh
     * @return array<string, mixed>
     */
    private function captureVerifiedStat($fh, string $safePath): array
    {
        clearstatcache(true, $safePath);
        $this->pathGuard->assertSafeInputPath($safePath);

        $fstat = fstat($fh);
        $lstat = @lstat($safePath);
        if (! is_array($fstat) || ! is_array($lstat)) {
            throw new QueueRecoveryException(
                "No se pudo verificar la identidad del archivo de manifiesto: '{$safePath}'.",
                'manifest_concurrent_modification',
                ['path' => $safePath],
            );
        }

        if (is_link($safePath)) {
            throw new QueueRecoveryException(
                "El manifiesto fue sustituido por un enlace simbólico: '{$safePath}'.",
                'unsafe_symlink_path',
                ['path' => $safePath],
            );
        }

        if ((int) $fstat['ino'] !== (int) $lstat['ino']
            || (int) $fstat['dev'] !== (int) $lstat['dev']
            || (int) $fstat['size'] !== (int) $lstat['size']) {
            throw new QueueRecoveryException(
                "El archivo de manifiesto cambió de identidad o tamaño durante la lectura: '{$safePath}'.",
                'manifest_concurrent_modification',
                ['path' => $safePath],
            );
        }

        return $fstat;
    }

    /**
     * Verifies the open FD and filesystem path still point to the exact same unchanged file.
     *
     * @param  resource  $fh
     * @param  array<string, mixed>  $initialStat
     */
    private function assertUnchangedSinceOpen($fh, string $safePath, array $initialStat): void
    {
        clearstatcache(true, $safePath);
        $this->pathGuard->assertSafeInputPath($safePath);

        $currentFstat = fstat($fh);
        $currentLstat = @lstat($safePath);

        if (! is_array($currentFstat) || ! is_array($currentLstat)) {
            throw new QueueRecoveryException(
                "El manifiesto dejó de estar accesible o fue eliminado durante la lectura: '{$safePath}'.",
                'manifest_concurrent_modification',
                ['path' => $safePath],
            );
        }

        if (is_link($safePath)) {
            throw new QueueRecoveryException(
                "El manifiesto fue reemplazado por un enlace simbólico durante la lectura: '{$safePath}'.",
                'unsafe_symlink_path',
                ['path' => $safePath],
            );
        }

        $changed = (int) $currentFstat['size'] !== (int) $initialStat['size']
            || (int) $currentFstat['ino'] !== (int) $initialStat['ino']
            || (int) $currentFstat['dev'] !== (int) $initialStat['dev']
            || (int) $currentLstat['size'] !== (int) $initialStat['size']
            || (int) $currentLstat['ino'] !== (int) $initialStat['ino']
            || (int) $currentLstat['dev'] !== (int) $initialStat['dev']
            || (int) $currentFstat['mtime'] !== (int) $initialStat['mtime']
            || (int) $currentLstat['mtime'] !== (int) $initialStat['mtime'];

        if ($changed) {
            throw new QueueRecoveryException(
                "Modificación concurrente o sustitución detectada en el manifiesto durante lectura streaming: '{$safePath}'.",
                'manifest_concurrent_modification',
                ['path' => $safePath],
            );
        }
    }

    /**
     * Detects NDJSON format from the already-open FD using a strictly bounded 8 KiB read.
     *
     * @param  resource  $fh
     */
    private function detectNdjsonFormatFromHandle($fh, string $safePath): bool
    {
        if (str_ends_with(strtolower($safePath), '.ndjson')) {
            return true;
        }

        $firstLine = null;
        $secondLine = null;

        while (($line = fgets($fh, 8192)) !== false) {
            $hadNewline = str_ends_with($line, "\n") || str_ends_with($line, "\r");
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if ($firstLine === null) {
                $firstLine = $trimmed;
                if (! $hadNewline && ! feof($fh)) {
                    return false;
                }
            } else {
                $secondLine = $trimmed;
                break;
            }
        }

        if ($firstLine === null) {
            throw new QueueRecoveryException("El manifiesto está vacío: '{$safePath}'.", 'manifest_invalid');
        }

        if (str_starts_with($firstLine, '{') && str_ends_with($firstLine, '}')) {
            if ($secondLine !== null && str_starts_with($secondLine, '{')) {
                return true;
            }
            $decoded = json_decode($firstLine, true);
            if (is_array($decoded) && isset($decoded['record_type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Streams a standard R2.1 (v2) or legacy R2 (v1) JSON manifest over the single open `$fh`,
     * proving byte-for-byte identity between Pass 1 and Pass 2 while preserving ALL R2.1 guarantees.
     *
     * @param  resource  $fh
     * @param  array<string, mixed>  $initialStat
     * @return array<string, mixed>
     */
    private function readJsonManifestStreamingFromHandle(
        $fh,
        string $safePath,
        array $initialStat,
        QueuePayloadInspector $inspector,
        ?string $expectedManifestSha256,
        ?string $hmacKey,
    ): array {
        $pass1RawHashCtx = hash_init('sha256');
        $pass1EntriesHashCtx = hash_init('sha256');

        // Pass 1: Scan top-level JSON object, reject duplicate keys & trailing garbage, hash full file
        // AND hash the exact raw byte span of `entries`.
        $pass1 = $this->scanTopLevelJsonObject($fh, $safePath, $pass1RawHashCtx, $pass1EntriesHashCtx);
        $fileSha256 = hash_final($pass1RawHashCtx);
        $pass1EntriesSha256 = hash_final($pass1EntriesHashCtx);

        $topLevel = $pass1['fields'];
        $entriesStartOffset = $pass1['entries_start'];
        $entriesEndOffset = $pass1['entries_end'];

        $schemaVersion = (int) ($topLevel['schema_version'] ?? 1);
        if (! in_array($schemaVersion, [1, QueueRecoveryManifest::SCHEMA_VERSION], true)) {
            throw new QueueRecoveryException(
                "Versión de esquema de manifiesto no soportada: schema_version={$schemaVersion} (soportadas: 1, 2).",
                'manifest_invalid',
                ['schema_version' => $schemaVersion],
            );
        }

        $strictR2V2 = $schemaVersion === QueueRecoveryManifest::SCHEMA_VERSION;

        if ($strictR2V2) {
            if (! is_string($topLevel['incident_id'] ?? null) || trim((string) $topLevel['incident_id']) === '') {
                throw new QueueRecoveryException('El manifiesto debe incluir un incident_id válido.', 'manifest_invalid');
            }
            $incidentId = trim((string) $topLevel['incident_id']);
            $allowedJobs = $inspector->validateWhitelist(is_array($topLevel['allowed_jobs'] ?? null) ? $topLevel['allowed_jobs'] : []);
            $authorizedUpperBound = (int) ($topLevel['authorized_upper_bound'] ?? 0);
            $lastScannedId = (int) ($topLevel['last_scanned_id'] ?? 0);
            $nextStartId = (int) ($topLevel['next_start_id'] ?? 0);

            if ($authorizedUpperBound < 0 || $lastScannedId < 0 || $nextStartId < 0) {
                throw new QueueRecoveryException('El manifiesto contiene límites de PK o cursores negativos.', 'manifest_invalid');
            }
            if ($authorizedUpperBound > 0 && $lastScannedId > $authorizedUpperBound) {
                throw new QueueRecoveryException(
                    "Inconsistencia de cursor en manifiesto: last_scanned_id ({$lastScannedId}) > authorized_upper_bound ({$authorizedUpperBound}).",
                    'upper_bound_violation',
                );
            }
        } else {
            $incidentId = is_string($topLevel['incident_id'] ?? null) && trim((string) $topLevel['incident_id']) !== ''
                ? trim((string) $topLevel['incident_id'])
                : 'LEGACY-MANIFEST-'.substr($fileSha256, 0, 12);
            $rawAllowedJobs = is_array($topLevel['allowed_jobs'] ?? null) ? $topLevel['allowed_jobs'] : [];
            $allowedJobs = $rawAllowedJobs !== []
                ? $inspector->validateWhitelist($rawAllowedJobs)
                : QueuePayloadInspector::ALLOWED_JOB_CLASSES;
            $authorizedUpperBound = (int) ($topLevel['authorized_upper_bound'] ?? ($topLevel['end_id'] ?? 0));
            $lastScannedId = (int) ($topLevel['last_scanned_id'] ?? ($topLevel['end_id'] ?? 0));
            if ($authorizedUpperBound < 0 || $lastScannedId < 0) {
                throw new QueueRecoveryException('El manifiesto contiene límites de PK o cursores negativos.', 'manifest_invalid');
            }
            if ($authorizedUpperBound > 0 && $lastScannedId > $authorizedUpperBound) {
                throw new QueueRecoveryException(
                    "Inconsistencia de cursor en manifiesto: last_scanned_id ({$lastScannedId}) > authorized_upper_bound ({$authorizedUpperBound}).",
                    'upper_bound_violation',
                );
            }
        }

        if ($entriesStartOffset === null || $entriesEndOffset === null) {
            throw new QueueRecoveryException('El manifiesto debe contener el arreglo entries.', 'manifest_invalid', ['path' => $safePath]);
        }

        $declaredHash = $topLevel['manifest_sha256'] ?? null;
        if ($strictR2V2) {
            if (! is_string($declaredHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $declaredHash)) {
                throw new QueueRecoveryException(
                    'El manifiesto carece de un checksum SHA-256 válido (manifest_sha256).',
                    'manifest_tampered',
                    ['path' => $safePath],
                );
            }
        } elseif ($declaredHash !== null && (! is_string($declaredHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $declaredHash))) {
            throw new QueueRecoveryException(
                'El manifiesto legacy contiene un manifest_sha256 mal formado.',
                'manifest_tampered',
                ['path' => $safePath],
            );
        }

        $declaredHmac = $topLevel['manifest_hmac_sha256'] ?? null;

        // Test hook to deterministically simulate tampering/replacement between Pass 1 and Pass 2 (D4).
        if ($this->betweenPassesHook !== null) {
            ($this->betweenPassesHook)($safePath);
        }

        // Verify file identity did NOT change before starting Pass 2.
        $this->assertUnchangedSinceOpen($fh, $safePath, $initialStat);

        // Pass 2: Stream `entries` over the EXACT SAME FD, hashing raw `entries` bytes AND canonical JSON.
        $bodyFieldsForHash = $topLevel;
        unset($bodyFieldsForHash['manifest_sha256'], $bodyFieldsForHash['manifest_hmac_sha256']);
        $sortedKeys = array_keys($bodyFieldsForHash);
        $sortedKeys[] = 'entries';
        $sortedKeys = array_values(array_unique($sortedKeys));
        sort($sortedKeys, SORT_STRING);

        $canonicalHashCtx = hash_init('sha256');
        hash_update($canonicalHashCtx, '{');

        $pass2EntriesHashCtx = hash_init('sha256');
        $state = $this->initAccumulator($topLevel);

        $firstKey = true;
        foreach ($sortedKeys as $key) {
            if (! $firstKey) {
                hash_update($canonicalHashCtx, ',');
            }
            $firstKey = false;
            hash_update($canonicalHashCtx, json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).':');

            if ($key === 'entries') {
                hash_update($canonicalHashCtx, '[');
                $this->streamJsonEntriesArray(
                    $fh,
                    $entriesStartOffset,
                    $entriesEndOffset,
                    $canonicalHashCtx,
                    $pass2EntriesHashCtx,
                    $state,
                    $strictR2V2,
                    $allowedJobs,
                    $authorizedUpperBound,
                    $lastScannedId,
                );
                hash_update($canonicalHashCtx, ']');
            } else {
                $canonicalVal = $this->canonicalize($bodyFieldsForHash[$key]);
                hash_update(
                    $canonicalHashCtx,
                    json_encode($canonicalVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                );
                unset($bodyFieldsForHash[$key], $topLevel[$key]);
            }
        }
        hash_update($canonicalHashCtx, '}');

        // Prove Pass 1 and Pass 2 read the EXACT same `entries` bytes and file stat never changed!
        $pass2EntriesSha256 = hash_final($pass2EntriesHashCtx);
        if (! hash_equals($pass1EntriesSha256, $pass2EntriesSha256)) {
            throw new QueueRecoveryException(
                "Contenido de 'entries' modificado concurrentemente entre Pasada 1 y Pasada 2: '{$safePath}'.",
                'manifest_concurrent_modification',
                ['path' => $safePath],
            );
        }
        $this->assertUnchangedSinceOpen($fh, $safePath, $initialStat);

        $computedHash = hash_final($canonicalHashCtx);

        if (is_string($declaredHash)) {
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
        }

        $effectiveHash = is_string($declaredHash) ? $computedHash : $fileSha256;
        $authMeta = $this->verifyExpectedHashAndHmac(
            $effectiveHash,
            $fileSha256,
            $expectedManifestSha256,
            $hmacKey,
            $declaredHmac,
        );

        return $this->finalizeAccumulatorResult(
            format: $strictR2V2 ? 'json_r2_v2' : 'json_legacy_v1',
            schemaVersion: $schemaVersion,
            incidentId: $incidentId,
            manifestSha256: $effectiveHash,
            fileSha256: $fileSha256,
            expectedHashVerified: $authMeta['expected_hash_verified'],
            hmacVerified: $authMeta['hmac_authenticated'],
            allowedJobs: $allowedJobs,
            state: $state,
        );
    }

    /**
     * Streams an NDJSON manifest in a SINGLE pass over the open FD while computing its exact SHA-256.
     *
     * @param  resource  $fh
     * @param  array<string, mixed>  $initialStat
     * @return array<string, mixed>
     */
    private function readNdjsonManifestFromHandle(
        $fh,
        string $safePath,
        array $initialStat,
        QueuePayloadInspector $inspector,
        ?string $expectedManifestSha256,
        ?string $hmacKey,
    ): array {
        $fileHashCtx = hash_init('sha256');
        $header = [];
        $declaredHash = null;
        $declaredHmac = null;
        $state = $this->initAccumulator([]);
        $lineNumber = 0;
        $allowedJobs = QueuePayloadInspector::ALLOWED_JOB_CLASSES;

        while (($line = fgets($fh)) !== false) {
            hash_update($fileHashCtx, $line);
            $lineNumber++;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $this->assertNoDuplicateObjectKeys($trimmed);

            try {
                $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new QueueRecoveryException(
                    "Línea #{$lineNumber} del manifiesto NDJSON no es JSON válido.",
                    'manifest_invalid',
                    ['path' => $safePath, 'line' => $lineNumber],
                    $e,
                );
            }

            if (! is_array($decoded)) {
                throw new QueueRecoveryException(
                    "Línea #{$lineNumber} del manifiesto NDJSON debe ser un objeto JSON.",
                    'manifest_invalid',
                    ['path' => $safePath, 'line' => $lineNumber],
                );
            }

            $recordType = $decoded['record_type'] ?? null;
            if ($recordType === 'manifest_header') {
                $header = $decoded;
                $state['totals_declared'] = $this->normalizeDeclaredTotals($decoded['totals'] ?? []);
                $state['anomalies_declared'] = is_array($decoded['anomalies'] ?? null) ? $decoded['anomalies'] : [];
                $rawAllowed = is_array($decoded['allowed_jobs'] ?? null) ? $decoded['allowed_jobs'] : [];
                if ($rawAllowed !== []) {
                    $allowedJobs = $inspector->validateWhitelist($rawAllowed);
                }
                if (is_string($decoded['manifest_sha256'] ?? null)) {
                    $declaredHash = $decoded['manifest_sha256'];
                }
                if (is_string($decoded['manifest_hmac_sha256'] ?? null)) {
                    $declaredHmac = $decoded['manifest_hmac_sha256'];
                }

                continue;
            }

            if ($recordType === 'manifest_footer') {
                if (is_string($decoded['manifest_sha256'] ?? null)) {
                    $declaredHash = $decoded['manifest_sha256'];
                }
                if (is_string($decoded['manifest_hmac_sha256'] ?? null)) {
                    $declaredHmac = $decoded['manifest_hmac_sha256'];
                }

                continue;
            }

            $entry = ($recordType === 'entry' && is_array($decoded['entry'] ?? null))
                ? $decoded['entry']
                : $decoded;

            $this->ingestManifestEntry(
                $entry,
                $state,
                strictR2V2: false,
                allowedJobs: $allowedJobs,
                authorizedUpperBound: (int) ($header['authorized_upper_bound'] ?? 0),
                lastScannedId: (int) ($header['last_scanned_id'] ?? 0),
            );
        }

        if ($this->betweenPassesHook !== null) {
            ($this->betweenPassesHook)($safePath);
        }
        $this->assertUnchangedSinceOpen($fh, $safePath, $initialStat);

        $fileSha256 = hash_final($fileHashCtx);
        $schemaVersion = (int) ($header['schema_version'] ?? QueueRecoveryManifest::SCHEMA_VERSION);
        $incidentId = is_string($header['incident_id'] ?? null) && trim((string) $header['incident_id']) !== ''
            ? trim((string) $header['incident_id'])
            : 'NDJSON-MANIFEST-'.substr($fileSha256, 0, 12);

        $effectiveHash = is_string($declaredHash) && preg_match('/\A[a-f0-9]{64}\z/', $declaredHash)
            ? $declaredHash
            : $fileSha256;

        $authMeta = $this->verifyExpectedHashAndHmac(
            $effectiveHash,
            $fileSha256,
            $expectedManifestSha256,
            $hmacKey,
            $declaredHmac,
        );

        return $this->finalizeAccumulatorResult(
            format: 'ndjson_streaming_v2',
            schemaVersion: $schemaVersion,
            incidentId: $incidentId,
            manifestSha256: $effectiveHash,
            fileSha256: $fileSha256,
            expectedHashVerified: $authMeta['expected_hash_verified'],
            hmacVerified: $authMeta['hmac_authenticated'],
            allowedJobs: $allowedJobs,
            state: $state,
        );
    }

    /**
     * Scans a top-level JSON object over `$fh`, rejecting duplicate keys & trailing garbage while
     * hashing 100% of raw bytes AND hashing the exact interior byte span of `entries`.
     *
     * @param  resource  $fh
     * @return array{fields: array<string, mixed>, entries_start: ?int, entries_end: ?int}
     */
    private function scanTopLevelJsonObject(
        $fh,
        string $safePath,
        HashContext $rawFileHashCtx,
        HashContext $entriesInteriorHashCtx,
    ): array {
        $reader = new JsonByteCursor($fh, self::READ_BUFFER_BYTES, 0, null, $rawFileHashCtx);
        $reader->skipWhitespace();

        if ($reader->peekByte() !== '{') {
            throw new QueueRecoveryException('El manifiesto debe ser un objeto JSON.', 'manifest_invalid', ['path' => $safePath]);
        }
        $reader->consumeByte();

        $fields = [];
        $seenTopKeys = [];
        $entriesStart = null;
        $entriesEnd = null;
        $expectCommaOrEnd = false;

        while (true) {
            $reader->skipWhitespace();
            $ch = $reader->peekByte();
            if ($ch === null) {
                throw new QueueRecoveryException("Fin inesperado del manifiesto JSON: '{$safePath}'.", 'manifest_invalid');
            }
            if ($ch === '}') {
                $reader->consumeByte();
                break;
            }
            if ($expectCommaOrEnd) {
                if ($ch !== ',') {
                    throw new QueueRecoveryException("Se esperaba ',' o '}' en manifiesto JSON: '{$safePath}'.", 'manifest_invalid');
                }
                $reader->consumeByte();
                $reader->skipWhitespace();
            }

            $keyRaw = $reader->readJsonStringLiteral();
            try {
                $key = json_decode($keyRaw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new QueueRecoveryException("Clave JSON inválida en manifiesto: '{$safePath}'.", 'manifest_invalid', [], $e);
            }
            if (! is_string($key)) {
                throw new QueueRecoveryException("Clave JSON no escalar en manifiesto: '{$safePath}'.", 'manifest_invalid');
            }

            if (isset($seenTopKeys[$key])) {
                throw new QueueRecoveryException(
                    "Campo JSON duplicado detectado en manifiesto: '{$key}'.",
                    'manifest_duplicate_json_key',
                    ['key' => $key, 'path' => $safePath],
                );
            }
            $seenTopKeys[$key] = true;

            $reader->skipWhitespace();
            if ($reader->consumeByte() !== ':') {
                throw new QueueRecoveryException("Se esperaba ':' tras la clave '{$key}' en manifiesto.", 'manifest_invalid');
            }
            $reader->skipWhitespace();

            if ($key === 'entries') {
                if ($reader->peekByte() !== '[') {
                    throw new QueueRecoveryException('El campo entries del manifiesto debe ser un arreglo JSON.', 'manifest_invalid');
                }
                $reader->consumeByte();
                $entriesStart = $reader->position();
                $reader->skipRestOfArray($entriesInteriorHashCtx);
                $entriesEnd = $reader->position() - 1;
            } else {
                $rawValue = $reader->readJsonValueRaw();
                if (str_starts_with($rawValue, '{')) {
                    $this->assertNoDuplicateObjectKeys($rawValue);
                }
                try {
                    $fields[$key] = json_decode($rawValue, true, 128, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new QueueRecoveryException(
                        "Valor JSON inválido para la clave '{$key}' en manifiesto.",
                        'manifest_invalid',
                        ['key' => $key],
                        $e,
                    );
                }
            }

            $expectCommaOrEnd = true;
        }

        $reader->assertOnlyWhitespaceUntilEof();

        return [
            'fields' => $fields,
            'entries_start' => $entriesStart,
            'entries_end' => $entriesEnd,
        ];
    }

    /**
     * Streams JSON objects inside `entries: [ ... ]` from byte offset `$start` to `$end` over `$fh`,
     * updating BOTH the canonical SHA-256 hash AND `$pass2EntriesHashCtx` for byte-identity proof.
     *
     * @param  resource  $fh
     * @param  array<string, mixed>  $state
     * @param  list<string>  $allowedJobs
     */
    private function streamJsonEntriesArray(
        $fh,
        int $start,
        int $end,
        HashContext $canonicalHashCtx,
        HashContext $pass2EntriesHashCtx,
        array &$state,
        bool $strictR2V2,
        array $allowedJobs,
        int $authorizedUpperBound,
        int $lastScannedId,
    ): void {
        $reader = new JsonByteCursor($fh, self::READ_BUFFER_BYTES, $start, $end, $pass2EntriesHashCtx);
        $isFirst = true;

        while (true) {
            $reader->skipWhitespace();
            $ch = $reader->peekByte();
            if ($ch === null || $ch === ']') {
                break;
            }
            if (! $isFirst) {
                if ($ch !== ',') {
                    throw new QueueRecoveryException("Se esperaba ',' entre entradas de 'entries' en manifiesto JSON.", 'manifest_invalid');
                }
                $reader->consumeByte();
                $reader->skipWhitespace();
                $ch = $reader->peekByte();
                if ($ch === null || $ch === ']') {
                    throw new QueueRecoveryException("Coma final inválida dentro de 'entries' en manifiesto JSON.", 'manifest_invalid');
                }
            }

            $rawItem = $reader->readJsonValueRaw();
            if (str_starts_with($rawItem, '{')) {
                $this->assertNoDuplicateObjectKeys($rawItem);
            }

            try {
                $entry = json_decode($rawItem, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new QueueRecoveryException('Entrada JSON corrupta dentro de entries del manifiesto.', 'manifest_invalid', [], $e);
            }

            if (! $isFirst) {
                hash_update($canonicalHashCtx, ',');
            }
            $isFirst = false;

            $canonicalEntry = $this->canonicalize($entry);
            hash_update(
                $canonicalHashCtx,
                json_encode($canonicalEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );

            if (! is_array($entry)) {
                if ($strictR2V2) {
                    throw new QueueRecoveryException('Entrada no-objeto dentro de entries en manifiesto R2.1.', 'manifest_invalid');
                }
                $state['invalid_entries_count']++;
                $state['total_manifest_entries']++;

                continue;
            }

            $this->ingestManifestEntry(
                $entry,
                $state,
                $strictR2V2,
                $allowedJobs,
                $authorizedUpperBound,
                $lastScannedId,
            );
        }

        // Consume any trailing whitespace before `$end` so `$pass2EntriesHashCtx` hashes every byte.
        $reader->skipWhitespace();
    }

    /**
     * Rejects duplicate keys at the top level of a JSON object literal (`{"id":1,"id":2}`).
     */
    private function assertNoDuplicateObjectKeys(string $rawObject): void
    {
        $len = strlen($rawObject);
        $i = 0;
        while ($i < $len && ($rawObject[$i] === ' ' || $rawObject[$i] === "\t" || $rawObject[$i] === "\r" || $rawObject[$i] === "\n")) {
            $i++;
        }
        if ($i >= $len || $rawObject[$i] !== '{') {
            return;
        }
        $i++;

        $seenKeys = [];
        $depth = 1;

        while ($i < $len && $depth === 1) {
            while ($i < $len && ($rawObject[$i] === ' ' || $rawObject[$i] === "\t" || $rawObject[$i] === "\r" || $rawObject[$i] === "\n" || $rawObject[$i] === ',')) {
                $i++;
            }
            if ($i >= $len || $rawObject[$i] === '}') {
                break;
            }
            if ($rawObject[$i] !== '"') {
                return;
            }

            $keyStart = $i;
            $i++;
            $escaped = false;
            while ($i < $len) {
                $c = $rawObject[$i++];
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    break;
                }
            }
            $keyLiteral = substr($rawObject, $keyStart, $i - $keyStart);
            $key = json_decode($keyLiteral, true);
            if (is_string($key)) {
                if (isset($seenKeys[$key])) {
                    throw new QueueRecoveryException(
                        "Campo JSON duplicado detectado dentro de objeto del manifiesto: '{$key}'.",
                        'manifest_duplicate_json_key',
                        ['key' => $key],
                    );
                }
                $seenKeys[$key] = true;
            }

            // Skip ':' and value at depth 1
            while ($i < $len && $rawObject[$i] !== ':') {
                $i++;
            }
            $i++; // skip ':'

            $inStr = false;
            $esc = false;
            $valDepth = 0;
            while ($i < $len) {
                $c = $rawObject[$i];
                if ($inStr) {
                    if ($esc) {
                        $esc = false;
                    } elseif ($c === '\\') {
                        $esc = true;
                    } elseif ($c === '"') {
                        $inStr = false;
                    }
                    $i++;

                    continue;
                }
                if ($c === '"') {
                    $inStr = true;
                    $i++;
                } elseif ($c === '{' || $c === '[') {
                    $valDepth++;
                    $i++;
                } elseif ($c === '}' || $c === ']') {
                    if ($valDepth === 0) {
                        break;
                    }
                    $valDepth--;
                    $i++;
                } elseif ($c === ',' && $valDepth === 0) {
                    $i++;
                    break;
                } else {
                    $i++;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $topLevel
     * @return array<string, mixed>
     */
    private function initAccumulator(array $topLevel): array
    {
        return [
            'totals_declared' => $this->normalizeDeclaredTotals($topLevel['totals'] ?? []),
            'anomalies_declared' => is_array($topLevel['anomalies'] ?? null) ? $topLevel['anomalies'] : [],
            'order_job_counts' => [],
            'order_unreserved_counts' => [],
            'order_reserved_counts' => [],
            'order_job_sample_csv' => [],
            'order_unreserved_job_sample_csv' => [],
            'order_reserved_job_sample_csv' => [],
            'order_manifest_account_hints' => [],
            'order_manifest_remote_order_hints' => [],
            'order_manifest_metadata_conflicts' => [],
            'discount_ids_map' => [],
            'total_manifest_entries' => 0,
            'reservation_job_entries' => 0,
            'reservation_unreserved_job_entries' => 0,
            'reservation_reserved_job_entries' => 0,
            'non_order_reserved_job_entries' => 0,
            'beauty_job_entries' => 0,
            'other_class_job_entries' => 0,
            'other_class_job_samples' => [],
            'reserved_entries_count' => 0,
            'reserved_entry_job_ids_sample' => [],
            'invalid_entries_count' => 0,
            'conflicting_identity_job_entries' => 0,
            'invalid_entry_samples' => [],
            'duplicate_manifest_job_id_entries' => 0,
            'duplicate_manifest_job_ids_sample' => [],
            'upper_bound_exceeded_entries' => 0,
            'seen_job_ids' => [],
            'seen_tracking_exact' => true,
            'unique_seen_count' => 0,
            'max_seen_job_id' => 0,
            'previous_job_id' => 0,
            'strict_ascending_mode_activated' => false,
        ];
    }

    /**
     * Ingests a single manifest entry with:
     * - Full R2.1 parity checks when `$strictR2V2 === true` (D4).
     * - Mathematically sound duplicate detection across unlimited entries: exact map up to
     *   `$maxExactSeenJobTracking`, then fail-closed strict ascending (`id > max_seen_job_id`) (D1).
     * - Complete accounting of reserved-only & mixed orders without double counting (D2).
     * - `job_class` vs `entity_type` coherence and metadata conflict tracking (D2).
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $state
     * @param  list<string>  $allowedJobs
     */
    private function ingestManifestEntry(
        array $entry,
        array &$state,
        bool $strictR2V2,
        array $allowedJobs,
        int $authorizedUpperBound,
        int $lastScannedId,
    ): void {
        $state['total_manifest_entries']++;

        $rawJobId = $entry['id'] ?? null;
        $jobId = (is_int($rawJobId) || (is_string($rawJobId) && ctype_digit($rawJobId))) ? (int) $rawJobId : 0;

        if ($jobId <= 0) {
            if ($strictR2V2) {
                throw new QueueRecoveryException(
                    "ID de job inválido en el manifiesto: {$jobId}.",
                    'manifest_invalid',
                    ['job_id' => $jobId],
                );
            }
            $state['invalid_entries_count']++;
            if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                $state['invalid_entry_samples'][] = ['job_id' => $rawJobId, 'reason' => 'invalid_or_missing_job_id'];
            }

            return;
        }

        // Strict R2.1 v2 parity: exact same entry checks as QueueRecoveryManifest::loadAndVerify() (D4).
        if ($strictR2V2) {
            if ($jobId <= $state['previous_job_id']) {
                throw new QueueRecoveryException(
                    "ID de job inválido, desordenado o duplicado en el manifiesto: {$jobId}.",
                    'manifest_invalid',
                    ['job_id' => $jobId],
                );
            }
            if ($authorizedUpperBound > 0 && $jobId > $authorizedUpperBound) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene job #{$jobId} superior al límite autorizado authorized_upper_bound={$authorizedUpperBound}.",
                    'upper_bound_violation',
                    ['job_id' => $jobId, 'authorized_upper_bound' => $authorizedUpperBound],
                );
            }
            if ($lastScannedId > 0 && $jobId > $lastScannedId) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene job #{$jobId} superior a last_scanned_id={$lastScannedId}.",
                    'upper_bound_violation',
                    ['job_id' => $jobId, 'last_scanned_id' => $lastScannedId],
                );
            }

            $v2JobClass = ltrim(trim((string) ($entry['job_class'] ?? '')), '\\');
            if (! in_array($v2JobClass, $allowedJobs, true)) {
                throw new QueueRecoveryException(
                    "El manifiesto incluye una clase no autorizada: '{$v2JobClass}'.",
                    'invalid_job_whitelist',
                    ['job_id' => $jobId, 'job_class' => $v2JobClass],
                );
            }

            $queue = (string) ($entry['queue'] ?? '');
            $payloadHash = (string) ($entry['payload_hash'] ?? '');
            $rowSha256 = (string) ($entry['row_sha256'] ?? '');
            if ($queue === ''
                || ! preg_match('/\A[a-f0-9]{64}\z/', $payloadHash)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $rowSha256)
                || ! array_key_exists('attempts', $entry)
                || ! array_key_exists('available_at', $entry)
                || ! array_key_exists('created_at', $entry)) {
                throw new QueueRecoveryException(
                    "Entrada incompleta en manifiesto para job #{$jobId} (se requieren todas las columnas y hashes).",
                    'manifest_invalid',
                    ['job_id' => $jobId],
                );
            }

            if (($entry['reserved_at'] ?? null) !== null) {
                throw new QueueRecoveryException(
                    "El manifiesto contiene un trabajo reservado (job #{$jobId}); abortando por seguridad.",
                    'reserved_jobs_detected',
                    ['job_id' => $jobId],
                );
            }

            $state['previous_job_id'] = $jobId;
            if ($jobId > $state['max_seen_job_id']) {
                $state['max_seen_job_id'] = $jobId;
            }
            $state['unique_seen_count']++;
        } else {
            // D1: Exact duplicate detection up to `$maxExactSeenJobTracking`, then fail-closed strict ascending!
            if ($state['seen_tracking_exact']) {
                if (isset($state['seen_job_ids'][$jobId])) {
                    $state['duplicate_manifest_job_id_entries']++;
                    if (count($state['duplicate_manifest_job_ids_sample']) < self::MAX_ANOMALY_SAMPLE) {
                        $state['duplicate_manifest_job_ids_sample'][] = $jobId;
                    }

                    return;
                }

                $state['unique_seen_count']++;
                if ($jobId > $state['max_seen_job_id']) {
                    $state['max_seen_job_id'] = $jobId;
                }
                $state['previous_job_id'] = $jobId;

                if ($state['unique_seen_count'] > $this->maxExactSeenJobTracking) {
                    // Release hash map to bound memory AND enforce strict ascending (`id > max_seen_job_id`) fail-closed!
                    $state['seen_job_ids'] = [];
                    $state['seen_tracking_exact'] = false;
                    $state['strict_ascending_mode_activated'] = true;
                } else {
                    $state['seen_job_ids'][$jobId] = true;
                }
            } else {
                // Beyond exact tracking budget: EVERY incoming ID MUST be strictly greater than max_seen_job_id.
                // Rejects BOTH non-consecutive smaller duplicates AND out-of-order IDs fail-closed!
                if ($jobId <= $state['max_seen_job_id']) {
                    throw new QueueRecoveryException(
                        sprintf(
                            'ID de job #%d fuera de orden o duplicado tras superar el límite de seguimiento exacto (%d entradas; máximo observado=%d). Requerido orden estrictamente ascendente para garantizar unicidad con memoria acotada.',
                            $jobId,
                            $this->maxExactSeenJobTracking,
                            $state['max_seen_job_id'],
                        ),
                        'manifest_unordered_or_duplicate_beyond_tracking_limit',
                        [
                            'job_id' => $jobId,
                            'max_seen_job_id' => $state['max_seen_job_id'],
                            'exact_tracking_limit' => $this->maxExactSeenJobTracking,
                        ],
                    );
                }

                $state['max_seen_job_id'] = $jobId;
                $state['previous_job_id'] = $jobId;
                $state['unique_seen_count']++;
            }

            if ($authorizedUpperBound > 0 && $jobId > $authorizedUpperBound) {
                $state['upper_bound_exceeded_entries']++;
            }
        }

        $reservedAt = $entry['reserved_at'] ?? null;
        $isReserved = $reservedAt !== null && $reservedAt !== '';

        $classification = is_string($entry['classification'] ?? null) ? trim((string) $entry['classification']) : null;
        if ($classification !== null && in_array($classification, [
            QueuePayloadInspector::CLASSIFICATION_INVALID_JSON,
            QueuePayloadInspector::CLASSIFICATION_INCONSISTENT,
            'invalid_payload',
            'unverifiable_payload',
        ], true)) {
            $state['invalid_entries_count']++;
            if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                $state['invalid_entry_samples'][] = ['job_id' => $jobId, 'reason' => $entry['reason'] ?? $classification];
            }

            return;
        }

        $declaredEntityType = is_string($entry['entity_type'] ?? null) ? trim((string) $entry['entity_type']) : '';
        $jobClass = is_string($entry['job_class'] ?? null) ? ltrim(trim((string) $entry['job_class']), '\\') : '';
        $rawEntityId = $entry['entity_id'] ?? null;
        $entityId = (is_int($rawEntityId) || (is_string($rawEntityId) && ctype_digit($rawEntityId))) ? (int) $rawEntityId : 0;

        // D2: Verify coherence between `job_class` and `entity_type`, and separate non-allowed job classes!
        if ($jobClass !== '' && ! in_array($jobClass, QueuePayloadInspector::ALLOWED_JOB_CLASSES, true)) {
            $state['other_class_job_entries']++;
            if (count($state['other_class_job_samples']) < self::MAX_ANOMALY_SAMPLE) {
                $state['other_class_job_samples'][] = ['job_id' => $jobId, 'job_class' => $jobClass, 'reserved' => $isReserved];
            }

            return;
        }

        if ($jobClass === QueuePayloadInspector::JOB_RESERVATIONS && $declaredEntityType !== '' && $declaredEntityType !== 'meli_order') {
            $state['invalid_entries_count']++;
            $state['conflicting_identity_job_entries']++;
            if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                $state['invalid_entry_samples'][] = [
                    'job_id' => $jobId,
                    'reason' => 'job_class_entity_type_mismatch',
                    'job_class' => $jobClass,
                    'entity_type' => $declaredEntityType,
                ];
            }

            return;
        }

        if ($jobClass === QueuePayloadInspector::JOB_BEAUTY_PRICES && $declaredEntityType !== '' && $declaredEntityType !== 'beauty_discount') {
            $state['invalid_entries_count']++;
            $state['conflicting_identity_job_entries']++;
            if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                $state['invalid_entry_samples'][] = [
                    'job_id' => $jobId,
                    'reason' => 'job_class_entity_type_mismatch',
                    'job_class' => $jobClass,
                    'entity_type' => $declaredEntityType,
                ];
            }

            return;
        }

        $entityType = $declaredEntityType;
        if ($entityType === '' && $jobClass === QueuePayloadInspector::JOB_RESERVATIONS) {
            $entityType = 'meli_order';
        } elseif ($entityType === '' && $jobClass === QueuePayloadInspector::JOB_BEAUTY_PRICES) {
            $entityType = 'beauty_discount';
        }

        if ($entityType === 'meli_order') {
            if ($entityId <= 0) {
                if ($isReserved) {
                    $state['reserved_entries_count']++;
                    $state['non_order_reserved_job_entries']++;
                    if (count($state['reserved_entry_job_ids_sample']) < self::MAX_ANOMALY_SAMPLE) {
                        $state['reserved_entry_job_ids_sample'][] = $jobId;
                    }
                } else {
                    $state['invalid_entries_count']++;
                    if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                        $state['invalid_entry_samples'][] = ['job_id' => $jobId, 'reason' => 'missing_or_invalid_order_entity_id'];
                    }
                }

                return;
            }

            // Capture optional metadata hints (meli_account_id / remote_order_id) to detect cross-entry conflicts (D2).
            $this->recordOrderMetadataHints($entry, $entityId, $state);

            // Ensure this order is ALWAYS registered in `order_job_counts` (even if ONLY reserved jobs exist — D2!).
            $prevTotal = $state['order_job_counts'][$entityId] ?? 0;
            $state['order_job_counts'][$entityId] = $prevTotal + 1;

            if ($prevTotal === 0) {
                $state['order_job_sample_csv'][$entityId] = (string) $jobId;
            } elseif ($prevTotal < self::MAX_JOB_ID_SAMPLE_PER_ORDER) {
                $state['order_job_sample_csv'][$entityId] .= ','.$jobId;
            }

            if ($isReserved) {
                $state['reserved_entries_count']++;
                $state['reservation_reserved_job_entries']++;
                if (count($state['reserved_entry_job_ids_sample']) < self::MAX_ANOMALY_SAMPLE) {
                    $state['reserved_entry_job_ids_sample'][] = $jobId;
                }

                $prevRes = $state['order_reserved_counts'][$entityId] ?? 0;
                $state['order_reserved_counts'][$entityId] = $prevRes + 1;
                if ($prevRes === 0) {
                    $state['order_reserved_job_sample_csv'][$entityId] = (string) $jobId;
                } elseif ($prevRes < self::MAX_JOB_ID_SAMPLE_PER_ORDER) {
                    $state['order_reserved_job_sample_csv'][$entityId] .= ','.$jobId;
                }
            } else {
                $state['reservation_job_entries']++;
                $state['reservation_unreserved_job_entries']++;

                $prevUnres = $state['order_unreserved_counts'][$entityId] ?? 0;
                $state['order_unreserved_counts'][$entityId] = $prevUnres + 1;
                if ($prevUnres === 0) {
                    $state['order_unreserved_job_sample_csv'][$entityId] = (string) $jobId;
                } elseif ($prevUnres < self::MAX_JOB_ID_SAMPLE_PER_ORDER) {
                    $state['order_unreserved_job_sample_csv'][$entityId] .= ','.$jobId;
                }
            }

            return;
        }

        if ($isReserved) {
            $state['reserved_entries_count']++;
            $state['non_order_reserved_job_entries']++;
            if (count($state['reserved_entry_job_ids_sample']) < self::MAX_ANOMALY_SAMPLE) {
                $state['reserved_entry_job_ids_sample'][] = $jobId;
            }

            return;
        }

        if ($entityType === 'beauty_discount') {
            if ($entityId <= 0) {
                $state['invalid_entries_count']++;
                if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                    $state['invalid_entry_samples'][] = ['job_id' => $jobId, 'reason' => 'missing_or_invalid_discount_entity_id'];
                }

                return;
            }

            $state['beauty_job_entries']++;
            $state['discount_ids_map'][$entityId] = true;

            return;
        }

        $state['invalid_entries_count']++;
        if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
            $state['invalid_entry_samples'][] = [
                'job_id' => $jobId,
                'reason' => $entityType !== '' ? "unsupported_entity_type:{$entityType}" : 'unverifiable_entity_identity',
            ];
        }
    }

    /**
     * Records optional account/remote_order metadata hints from manifest entries and flags conflicting values.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $state
     */
    private function recordOrderMetadataHints(array $entry, int $orderId, array &$state): void
    {
        if (array_key_exists('meli_account_id', $entry) && $entry['meli_account_id'] !== null && $entry['meli_account_id'] !== '') {
            $hintAcc = (int) $entry['meli_account_id'];
            if (isset($state['order_manifest_account_hints'][$orderId]) && $state['order_manifest_account_hints'][$orderId] !== $hintAcc) {
                $state['order_manifest_metadata_conflicts'][$orderId]['account_conflict'] = true;
            } else {
                $state['order_manifest_account_hints'][$orderId] = $hintAcc;
            }
        }

        $remoteRaw = $entry['remote_order_id'] ?? ($entry['order_id'] ?? null);
        if (is_scalar($remoteRaw) && trim((string) $remoteRaw) !== '') {
            $hintRemote = trim((string) $remoteRaw);
            if (isset($state['order_manifest_remote_order_hints'][$orderId]) && $state['order_manifest_remote_order_hints'][$orderId] !== $hintRemote) {
                $state['order_manifest_metadata_conflicts'][$orderId]['remote_order_conflict'] = true;
            } else {
                $state['order_manifest_remote_order_hints'][$orderId] = $hintRemote;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $allowedJobs
     * @return array<string, mixed>
     */
    private function finalizeAccumulatorResult(
        string $format,
        int $schemaVersion,
        string $incidentId,
        string $manifestSha256,
        string $fileSha256,
        bool $expectedHashVerified,
        bool $hmacVerified,
        array $allowedJobs,
        array $state,
    ): array {
        ksort($state['order_job_counts'], SORT_NUMERIC);
        $discountIds = array_keys($state['discount_ids_map']);
        sort($discountIds, SORT_NUMERIC);

        $declaredTotals = $state['totals_declared'];
        $declaredAnomalies = $state['anomalies_declared'];

        $singleJobOrders = 0;
        $multiJobOrders = 0;
        $reservedOnlyOrders = 0;
        $mixedReservedAndUnreservedOrders = 0;
        $jobsPerOrderDistribution = [];

        foreach ($state['order_job_counts'] as $oid => $totalCnt) {
            if ($totalCnt === 1) {
                $singleJobOrders++;
            } else {
                $multiJobOrders++;
            }
            $bucket = (string) $totalCnt;
            $jobsPerOrderDistribution[$bucket] = ($jobsPerOrderDistribution[$bucket] ?? 0) + 1;

            $unresCnt = $state['order_unreserved_counts'][$oid] ?? 0;
            $resCnt = $state['order_reserved_counts'][$oid] ?? 0;
            if ($resCnt > 0 && $unresCnt === 0) {
                $reservedOnlyOrders++;
            } elseif ($resCnt > 0 && $unresCnt > 0) {
                $mixedReservedAndUnreservedOrders++;
            }
        }
        uksort($jobsPerOrderDistribution, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        $distinctOrders = count($state['order_job_counts']);
        $totalOrderJobEntries = $state['reservation_unreserved_job_entries'] + $state['reservation_reserved_job_entries'];
        $extraDuplicateJobsSameOrder = max(0, $totalOrderJobEntries - $distinctOrders);

        $declaredReserved = (int) ($declaredTotals['preserved_reserved'] ?? 0);
        $declaredInvalidJson = (int) ($declaredTotals['preserved_invalid_json'] ?? 0);
        $declaredInconsistent = (int) ($declaredTotals['preserved_inconsistent'] ?? 0);
        $declaredOtherModules = (int) ($declaredTotals['preserved_other_modules'] ?? 0);

        $jobAccounting = [
            'total_manifest_entries' => $state['total_manifest_entries'],
            'reservation_job_entries' => $state['reservation_job_entries'],
            'reservation_unreserved_job_entries' => $state['reservation_unreserved_job_entries'],
            'reservation_reserved_job_entries' => $state['reservation_reserved_job_entries'],
            'total_order_job_entries' => $totalOrderJobEntries,
            'non_order_reserved_job_entries' => $state['non_order_reserved_job_entries'],
            'beauty_job_entries' => $state['beauty_job_entries'],
            'other_class_job_entries' => $state['other_class_job_entries'],
            'other_class_job_samples' => $state['other_class_job_samples'],
            'reserved_job_entries' => $state['reserved_entries_count'],
            'reserved_job_entries_sample' => $state['reserved_entry_job_ids_sample'],
            'invalid_or_unverifiable_job_entries' => $state['invalid_entries_count'],
            'conflicting_identity_job_entries' => $state['conflicting_identity_job_entries'],
            'invalid_or_unverifiable_job_samples' => $state['invalid_entry_samples'],
            'duplicate_manifest_job_id_entries' => $state['duplicate_manifest_job_id_entries'],
            'duplicate_manifest_job_ids_sample' => $state['duplicate_manifest_job_ids_sample'],
            'upper_bound_exceeded_entries' => $state['upper_bound_exceeded_entries'],
            'distinct_orders_in_manifest' => $distinctOrders,
            'reserved_only_orders_in_manifest' => $reservedOnlyOrders,
            'mixed_reserved_and_unreserved_orders_in_manifest' => $mixedReservedAndUnreservedOrders,
            'distinct_discounts_in_manifest' => count($discountIds),
            'orders_with_single_job' => $singleJobOrders,
            'orders_with_multiple_jobs' => $multiJobOrders,
            'extra_duplicate_jobs_for_same_order' => $extraDuplicateJobsSameOrder,
            'jobs_per_order_distribution' => $jobsPerOrderDistribution,
            'exact_seen_job_tracking_limit' => $this->maxExactSeenJobTracking,
            'strict_ascending_mode_activated' => $state['strict_ascending_mode_activated'],
            'manifest_declared_preserved_reserved' => $declaredReserved,
            'manifest_declared_preserved_invalid_json' => $declaredInvalidJson,
            'manifest_declared_preserved_inconsistent' => $declaredInconsistent,
            'manifest_declared_preserved_other_modules' => $declaredOtherModules,
            'manifest_declared_reserved_job_ids_sample' => is_array($declaredAnomalies['reserved_job_ids'] ?? null)
                ? array_slice(array_values($declaredAnomalies['reserved_job_ids']), 0, self::MAX_ANOMALY_SAMPLE)
                : [],
            'manifest_declared_invalid_payload_job_ids_sample' => is_array($declaredAnomalies['invalid_payload_job_ids'] ?? null)
                ? array_slice(array_values($declaredAnomalies['invalid_payload_job_ids']), 0, self::MAX_ANOMALY_SAMPLE)
                : [],
            'manifest_declared_inconsistent_payload_job_ids_sample' => is_array($declaredAnomalies['inconsistent_payload_job_ids'] ?? null)
                ? array_slice(array_values($declaredAnomalies['inconsistent_payload_job_ids']), 0, self::MAX_ANOMALY_SAMPLE)
                : [],
        ];

        return [
            'format' => $format,
            'schema_version' => $schemaVersion,
            'incident_id' => $incidentId,
            'manifest_sha256' => $manifestSha256,
            'file_sha256' => $fileSha256,
            'expected_hash_verified' => $expectedHashVerified,
            'hmac_verified' => $hmacVerified,
            'checksum_is_not_authentication' => true,
            'allowed_jobs' => $allowedJobs,
            'totals_declared' => $declaredTotals,
            'anomalies_declared' => $declaredAnomalies,
            'order_job_counts' => $state['order_job_counts'],
            'order_unreserved_counts' => $state['order_unreserved_counts'],
            'order_reserved_counts' => $state['order_reserved_counts'],
            'order_job_sample_csv' => $state['order_job_sample_csv'],
            'order_unreserved_job_sample_csv' => $state['order_unreserved_job_sample_csv'],
            'order_reserved_job_sample_csv' => $state['order_reserved_job_sample_csv'],
            'order_manifest_account_hints' => $state['order_manifest_account_hints'],
            'order_manifest_remote_order_hints' => $state['order_manifest_remote_order_hints'],
            'order_manifest_metadata_conflicts' => $state['order_manifest_metadata_conflicts'],
            'discount_ids' => $discountIds,
            'job_accounting' => $jobAccounting,
        ];
    }

    /** @return array{expected_hash_verified: bool, hmac_authenticated: bool} */
    private function verifyExpectedHashAndHmac(
        string $computedManifestHash,
        string $fileSha256,
        ?string $expectedManifestSha256,
        ?string $hmacKey,
        mixed $declaredHmac,
    ): array {
        $expectedHashVerified = false;
        $normalizedExpected = $expectedManifestSha256 !== null ? strtolower(trim($expectedManifestSha256)) : '';
        if ($normalizedExpected !== '') {
            $matches = preg_match('/\A[a-f0-9]{64}\z/', $normalizedExpected) === 1
                && (hash_equals($normalizedExpected, $computedManifestHash) || hash_equals($normalizedExpected, $fileSha256));
            if (! $matches) {
                throw new QueueRecoveryException(
                    'El manifiesto en disco no coincide con --expected-manifest-sha256 aprobado por el operador.',
                    'manifest_approval_mismatch',
                    [
                        'expected_sha256' => $normalizedExpected,
                        'actual_sha256' => $computedManifestHash,
                        'file_sha256' => $fileSha256,
                    ],
                );
            }
            $expectedHashVerified = true;
        }

        $hmacAuthenticated = false;
        $trimmedKey = $hmacKey !== null ? trim($hmacKey) : '';
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

            $expectedHmac = hash_hmac('sha256', $computedManifestHash, $trimmedKey);
            if (! hash_equals($expectedHmac, $declaredHmac)) {
                throw new QueueRecoveryException(
                    'Autenticidad del manifiesto comprometida: manifest_hmac_sha256 inválido.',
                    'manifest_hmac_invalid',
                );
            }

            $hmacAuthenticated = true;
        }

        return [
            'expected_hash_verified' => $expectedHashVerified,
            'hmac_authenticated' => $hmacAuthenticated,
        ];
    }

    /** @return array<string, int> */
    private function normalizeDeclaredTotals(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $out[$k] = (int) $v;
            }
        }

        return $out;
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
