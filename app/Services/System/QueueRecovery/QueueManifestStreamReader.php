<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Memory-bounded streaming reader for R2/R2.1 JSON manifests and NDJSON manifests.
 *
 * Guarantees:
 * - Never loads raw `jobs.payload` strings into memory.
 * - Streams `entries` from large JSON manifests (schema_version 1 or 2) one object at a time
 *   while computing the exact canonical `QueueRecoveryManifest::computeHash()` SHA-256 checksum
 *   (and verifying optional `--expected-manifest-sha256` and external HMAC-SHA256).
 * - Supports line-delimited NDJSON manifests in O(1) parser memory.
 * - Reports invalid, unverifiable, reserved, Beauty, other-module, and duplicate manifest job IDs
 *   explicitly without silently dropping any entry.
 */
class QueueManifestStreamReader
{
    public const MAX_JOB_ID_SAMPLE_PER_ORDER = 25;

    public const MAX_ANOMALY_SAMPLE = 200;

    private const READ_BUFFER_BYTES = 65536;

    private const MAX_EXACT_SEEN_JOB_TRACKING = 250000;

    public function __construct(
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {}

    /**
     * Reads, verifies, and aggregates a manifest into compact per-order maps suitable for
     * 100k–2.76M job diagnostics under strict memory budgets (< 160 MiB).
     *
     * @return array{
     *     format: string,
     *     schema_version: int,
     *     incident_id: string,
     *     manifest_sha256: string,
     *     file_sha256: string,
     *     hmac_verified: bool,
     *     allowed_jobs: list<string>,
     *     totals_declared: array<string, int>,
     *     anomalies_declared: array<string, mixed>,
     *     order_job_counts: array<int, int>,
     *     order_job_sample_csv: array<int, string>,
     *     order_reserved_counts: array<int, int>,
     *     discount_ids: list<int>,
     *     job_accounting: array<string, mixed>
     * }
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

        $fileSize = @filesize($safePath);
        if ($fileSize === false || $fileSize === 0) {
            throw new QueueRecoveryException(
                "El manifiesto está vacío o no pudo leerse: '{$safePath}'.",
                'manifest_invalid',
                ['path' => $safePath],
            );
        }

        $fileSha256 = (string) hash_file('sha256', $safePath);
        $isNdjson = $this->detectNdjsonFormat($safePath);

        if ($isNdjson) {
            return $this->readNdjsonManifest($safePath, $fileSha256, $inspector, $expectedManifestSha256, $hmacKey);
        }

        return $this->readJsonManifestStreaming($safePath, $fileSha256, $inspector, $expectedManifestSha256, $hmacKey);
    }

    /**
     * Detects NDJSON format (multiple JSON objects separated by newlines) vs single JSON document
     * using a strictly bounded read (never loads large single-line JSON files into RAM).
     */
    private function detectNdjsonFormat(string $safePath): bool
    {
        if (str_ends_with(strtolower($safePath), '.ndjson')) {
            return true;
        }

        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            $firstLine = null;
            $secondLine = null;
            $firstLineTruncated = false;

            while (($line = fgets($fh, 8192)) !== false) {
                $hadNewline = str_ends_with($line, "\n") || str_ends_with($line, "\r");
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                if ($firstLine === null) {
                    $firstLine = $trimmed;
                    $firstLineTruncated = ! $hadNewline && ! feof($fh);
                    if ($firstLineTruncated) {
                        // A single line exceeding 8 KiB is a minified JSON manifest, not NDJSON.
                        return false;
                    }
                } else {
                    $secondLine = $trimmed;
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        if ($firstLine === null) {
            throw new QueueRecoveryException("El manifiesto está vacío: '{$safePath}'.", 'manifest_invalid');
        }

        // If line 1 is a bounded complete JSON object AND line 2 starts a new JSON object, it's NDJSON.
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
     * Streams a standard R2 (v1) or R2.1 (v2) JSON manifest without keeping `entries` in memory,
     * computing the exact canonical SHA-256 hash when `manifest_sha256` is present.
     *
     * @return array<string, mixed>
     */
    private function readJsonManifestStreaming(
        string $safePath,
        string $fileSha256,
        QueuePayloadInspector $inspector,
        ?string $expectedManifestSha256,
        ?string $hmacKey,
    ): array {
        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            // Pass 1: Read top-level JSON structure, capturing all non-`entries` keys and the exact
            // byte span of the `entries` array so we never decode `entries` all at once in memory.
            $pass1 = $this->scanTopLevelJsonObject($fh, $safePath);
        } finally {
            fclose($fh);
        }

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

        if ($entriesStartOffset === null || $entriesEndOffset === null) {
            throw new QueueRecoveryException('El manifiesto debe contener el arreglo entries.', 'manifest_invalid', ['path' => $safePath]);
        }

        $declaredHash = $topLevel['manifest_sha256'] ?? null;
        if ($schemaVersion >= QueueRecoveryManifest::SCHEMA_VERSION) {
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

        // Pass 2: Stream `entries` one JSON object at a time, computing the exact canonical SHA-256
        // hash identically to QueueRecoveryManifest::computeHash() AND accumulating compact stats.
        $bodyFieldsForHash = $topLevel;
        unset($bodyFieldsForHash['manifest_sha256'], $bodyFieldsForHash['manifest_hmac_sha256']);
        $sortedKeys = array_keys($bodyFieldsForHash);
        $sortedKeys[] = 'entries';
        $sortedKeys = array_values(array_unique($sortedKeys));
        sort($sortedKeys, SORT_STRING);

        $hashCtx = hash_init('sha256');
        hash_update($hashCtx, '{');

        $state = $this->initAccumulator($topLevel);

        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo reabrir el manifiesto para streaming: '{$safePath}'.", 'manifest_invalid');
        }

        try {
            $firstKey = true;
            foreach ($sortedKeys as $key) {
                if (! $firstKey) {
                    hash_update($hashCtx, ',');
                }
                $firstKey = false;
                hash_update($hashCtx, json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).':');

                if ($key === 'entries') {
                    hash_update($hashCtx, '[');
                    $this->streamJsonEntriesArray(
                        $fh,
                        $entriesStartOffset,
                        $entriesEndOffset,
                        $hashCtx,
                        $state,
                        $authorizedUpperBound,
                    );
                    hash_update($hashCtx, ']');
                } else {
                    $canonicalVal = $this->canonicalize($bodyFieldsForHash[$key]);
                    hash_update(
                        $hashCtx,
                        json_encode($canonicalVal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    );
                    // Free heavy top-level maps like `counts_by_entity` immediately after hashing!
                    unset($bodyFieldsForHash[$key], $topLevel[$key]);
                }
            }
            hash_update($hashCtx, '}');
        } finally {
            fclose($fh);
        }

        $computedHash = hash_final($hashCtx);

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
        $hmacVerified = $this->verifyExpectedHashAndHmac(
            $effectiveHash,
            $fileSha256,
            $expectedManifestSha256,
            $hmacKey,
            $declaredHmac,
        );

        return $this->finalizeAccumulatorResult(
            format: $schemaVersion === 1 ? 'json_legacy_v1' : 'json_r2_v2',
            schemaVersion: $schemaVersion,
            incidentId: $incidentId,
            manifestSha256: $effectiveHash,
            fileSha256: $fileSha256,
            hmacVerified: $hmacVerified,
            allowedJobs: $allowedJobs,
            state: $state,
        );
    }

    /**
     * Streams an NDJSON manifest line-by-line in O(1) parser memory.
     *
     * @return array<string, mixed>
     */
    private function readNdjsonManifest(
        string $safePath,
        string $fileSha256,
        QueuePayloadInspector $inspector,
        ?string $expectedManifestSha256,
        ?string $hmacKey,
    ): array {
        $fh = @fopen($safePath, 'rb');
        if ($fh === false) {
            throw new QueueRecoveryException("No se pudo abrir el manifiesto NDJSON: '{$safePath}'.", 'manifest_invalid');
        }

        $header = [];
        $declaredHash = null;
        $declaredHmac = null;
        $state = $this->initAccumulator([]);
        $lineNumber = 0;

        try {
            while (($line = fgets($fh)) !== false) {
                $lineNumber++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

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

                $this->ingestManifestEntry($entry, $state, (int) ($header['authorized_upper_bound'] ?? 0));
            }
        } finally {
            fclose($fh);
        }

        $schemaVersion = (int) ($header['schema_version'] ?? QueueRecoveryManifest::SCHEMA_VERSION);
        $incidentId = is_string($header['incident_id'] ?? null) && trim((string) $header['incident_id']) !== ''
            ? trim((string) $header['incident_id'])
            : 'NDJSON-MANIFEST-'.substr($fileSha256, 0, 12);

        $rawAllowedJobs = is_array($header['allowed_jobs'] ?? null) ? $header['allowed_jobs'] : [];
        $allowedJobs = $rawAllowedJobs !== []
            ? $inspector->validateWhitelist($rawAllowedJobs)
            : QueuePayloadInspector::ALLOWED_JOB_CLASSES;

        $effectiveHash = is_string($declaredHash) && preg_match('/\A[a-f0-9]{64}\z/', $declaredHash)
            ? $declaredHash
            : $fileSha256;

        $hmacVerified = $this->verifyExpectedHashAndHmac(
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
            hmacVerified: $hmacVerified,
            allowedJobs: $allowedJobs,
            state: $state,
        );
    }

    /**
     * Scans a top-level JSON object stream, decoding all keys EXCEPT `entries`, whose raw byte range
     * (`entries_start` after `[`, `entries_end` at `]`) is recorded for streaming in Pass 2.
     *
     * @param  resource  $fh
     * @return array{fields: array<string, mixed>, entries_start: ?int, entries_end: ?int}
     */
    private function scanTopLevelJsonObject($fh, string $safePath): array
    {
        $reader = new JsonByteCursor($fh, self::READ_BUFFER_BYTES);
        $reader->skipWhitespace();

        if ($reader->peekByte() !== '{') {
            throw new QueueRecoveryException('El manifiesto debe ser un objeto JSON.', 'manifest_invalid', ['path' => $safePath]);
        }
        $reader->consumeByte();

        $fields = [];
        $entriesStart = null;
        $entriesEnd = null;

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
            if ($ch === ',') {
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

            $reader->skipWhitespace();
            if ($reader->consumeByte() !== ':') {
                throw new QueueRecoveryException("Se esperaba ':' tras la clave '{$key}' en manifiesto.", 'manifest_invalid');
            }
            $reader->skipWhitespace();

            if ($key === 'entries') {
                if ($reader->peekByte() !== '[') {
                    throw new QueueRecoveryException('El campo entries del manifiesto debe ser un arreglo JSON.', 'manifest_invalid');
                }
                $reader->consumeByte(); // consume '['
                $entriesStart = $reader->position();
                $reader->skipRestOfArray();
                $entriesEnd = $reader->position() - 1; // offset of ']'
            } else {
                $rawValue = $reader->readJsonValueRaw();
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
        }

        return [
            'fields' => $fields,
            'entries_start' => $entriesStart,
            'entries_end' => $entriesEnd,
        ];
    }

    /**
     * Streams JSON objects inside `entries: [ ... ]` from byte offset `$start` to `$end`,
     * updating the canonical SHA-256 hash and ingesting each entry without keeping entries in RAM.
     *
     * @param  resource  $fh
     * @param  array<string, mixed>  $state
     */
    private function streamJsonEntriesArray(
        $fh,
        int $start,
        int $end,
        \HashContext $hashCtx,
        array &$state,
        int $authorizedUpperBound,
    ): void {
        $reader = new JsonByteCursor($fh, self::READ_BUFFER_BYTES, $start, $end);
        $isFirst = true;

        while (true) {
            $reader->skipWhitespace();
            $ch = $reader->peekByte();
            if ($ch === null || $ch === ']') {
                break;
            }
            if ($ch === ',') {
                $reader->consumeByte();
                $reader->skipWhitespace();
                $ch = $reader->peekByte();
                if ($ch === null || $ch === ']') {
                    break;
                }
            }

            $rawItem = $reader->readJsonValueRaw();
            try {
                $entry = json_decode($rawItem, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new QueueRecoveryException('Entrada JSON corrupta dentro de entries del manifiesto.', 'manifest_invalid', [], $e);
            }

            if (! $isFirst) {
                hash_update($hashCtx, ',');
            }
            $isFirst = false;

            $canonicalEntry = $this->canonicalize($entry);
            hash_update(
                $hashCtx,
                json_encode($canonicalEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );

            if (! is_array($entry)) {
                $state['invalid_entries_count']++;
                $state['total_manifest_entries']++;

                continue;
            }

            $this->ingestManifestEntry($entry, $state, $authorizedUpperBound);
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
            'order_job_sample_csv' => [],
            'order_reserved_counts' => [],
            'discount_ids_map' => [],
            'total_manifest_entries' => 0,
            'reservation_job_entries' => 0,
            'beauty_job_entries' => 0,
            'reserved_entries_count' => 0,
            'reserved_entry_job_ids_sample' => [],
            'invalid_entries_count' => 0,
            'invalid_entry_samples' => [],
            'duplicate_manifest_job_id_entries' => 0,
            'duplicate_manifest_job_ids_sample' => [],
            'upper_bound_exceeded_entries' => 0,
            'seen_job_ids' => [],
            'seen_tracking_exact' => true,
            'last_seen_job_id' => 0,
        ];
    }

    /**
     * Ingests a single manifest entry into compact per-order maps, separating reserved, invalid,
     * duplicate manifest job IDs, Beauty jobs, and valid reservation order jobs cleanly.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $state
     */
    private function ingestManifestEntry(array $entry, array &$state, int $authorizedUpperBound): void
    {
        $state['total_manifest_entries']++;

        $rawJobId = $entry['id'] ?? null;
        $jobId = (is_int($rawJobId) || (is_string($rawJobId) && ctype_digit($rawJobId))) ? (int) $rawJobId : 0;

        if ($jobId <= 0) {
            $state['invalid_entries_count']++;
            if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                $state['invalid_entry_samples'][] = ['job_id' => $rawJobId, 'reason' => 'invalid_or_missing_job_id'];
            }

            return;
        }

        // Duplicate job ID detection (exact map up to 250k entries; strictly-monotonic O(1) guard beyond).
        $isDuplicateJobId = false;
        if ($state['seen_tracking_exact']) {
            if (isset($state['seen_job_ids'][$jobId])) {
                $isDuplicateJobId = true;
            } else {
                $state['seen_job_ids'][$jobId] = true;
                if (count($state['seen_job_ids']) > self::MAX_EXACT_SEEN_JOB_TRACKING) {
                    $state['seen_job_ids'] = [];
                    $state['seen_tracking_exact'] = false;
                }
            }
        } elseif ($jobId === $state['last_seen_job_id']) {
            $isDuplicateJobId = true;
        }
        if ($jobId > $state['last_seen_job_id']) {
            $state['last_seen_job_id'] = $jobId;
        }

        if ($isDuplicateJobId) {
            $state['duplicate_manifest_job_id_entries']++;
            if (count($state['duplicate_manifest_job_ids_sample']) < self::MAX_ANOMALY_SAMPLE) {
                $state['duplicate_manifest_job_ids_sample'][] = $jobId;
            }

            return;
        }

        if ($authorizedUpperBound > 0 && $jobId > $authorizedUpperBound) {
            $state['upper_bound_exceeded_entries']++;
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

        $entityType = is_string($entry['entity_type'] ?? null) ? trim((string) $entry['entity_type']) : '';
        $jobClass = is_string($entry['job_class'] ?? null) ? ltrim(trim((string) $entry['job_class']), '\\') : '';
        $rawEntityId = $entry['entity_id'] ?? null;
        $entityId = (is_int($rawEntityId) || (is_string($rawEntityId) && ctype_digit($rawEntityId))) ? (int) $rawEntityId : 0;

        if ($entityType === '' && $jobClass === QueuePayloadInspector::JOB_RESERVATIONS) {
            $entityType = 'meli_order';
        } elseif ($entityType === '' && $jobClass === QueuePayloadInspector::JOB_BEAUTY_PRICES) {
            $entityType = 'beauty_discount';
        }

        if ($isReserved) {
            $state['reserved_entries_count']++;
            if (count($state['reserved_entry_job_ids_sample']) < self::MAX_ANOMALY_SAMPLE) {
                $state['reserved_entry_job_ids_sample'][] = $jobId;
            }
            // If reserved entry also references an order, note reserved count on that order if already present,
            // while keeping reserved job accounting strictly separated!
            if ($entityType === 'meli_order' && $entityId > 0) {
                $state['order_reserved_counts'][$entityId] = ($state['order_reserved_counts'][$entityId] ?? 0) + 1;
            }

            return;
        }

        if ($entityType === 'meli_order') {
            if ($entityId <= 0) {
                $state['invalid_entries_count']++;
                if (count($state['invalid_entry_samples']) < self::MAX_ANOMALY_SAMPLE) {
                    $state['invalid_entry_samples'][] = ['job_id' => $jobId, 'reason' => 'missing_or_invalid_order_entity_id'];
                }

                return;
            }

            $state['reservation_job_entries']++;
            $prevCount = $state['order_job_counts'][$entityId] ?? 0;
            $state['order_job_counts'][$entityId] = $prevCount + 1;

            if ($prevCount === 0) {
                $state['order_job_sample_csv'][$entityId] = (string) $jobId;
            } elseif ($prevCount < self::MAX_JOB_ID_SAMPLE_PER_ORDER) {
                $state['order_job_sample_csv'][$entityId] .= ','.$jobId;
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
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function finalizeAccumulatorResult(
        string $format,
        int $schemaVersion,
        string $incidentId,
        string $manifestSha256,
        string $fileSha256,
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
        $jobsPerOrderDistribution = [];
        foreach ($state['order_job_counts'] as $cnt) {
            if ($cnt === 1) {
                $singleJobOrders++;
            } else {
                $multiJobOrders++;
            }
            $bucket = (string) $cnt;
            $jobsPerOrderDistribution[$bucket] = ($jobsPerOrderDistribution[$bucket] ?? 0) + 1;
        }
        uksort($jobsPerOrderDistribution, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        $distinctOrders = count($state['order_job_counts']);
        $extraDuplicateJobsSameOrder = max(0, $state['reservation_job_entries'] - $distinctOrders);

        $declaredReserved = (int) ($declaredTotals['preserved_reserved'] ?? 0);
        $declaredInvalidJson = (int) ($declaredTotals['preserved_invalid_json'] ?? 0);
        $declaredInconsistent = (int) ($declaredTotals['preserved_inconsistent'] ?? 0);
        $declaredOtherModules = (int) ($declaredTotals['preserved_other_modules'] ?? 0);

        $jobAccounting = [
            'total_manifest_entries' => $state['total_manifest_entries'],
            'reservation_job_entries' => $state['reservation_job_entries'],
            'beauty_job_entries' => $state['beauty_job_entries'],
            'reserved_job_entries' => $state['reserved_entries_count'],
            'reserved_job_entries_sample' => $state['reserved_entry_job_ids_sample'],
            'invalid_or_unverifiable_job_entries' => $state['invalid_entries_count'],
            'invalid_or_unverifiable_job_samples' => $state['invalid_entry_samples'],
            'duplicate_manifest_job_id_entries' => $state['duplicate_manifest_job_id_entries'],
            'duplicate_manifest_job_ids_sample' => $state['duplicate_manifest_job_ids_sample'],
            'upper_bound_exceeded_entries' => $state['upper_bound_exceeded_entries'],
            'distinct_orders_in_manifest' => $distinctOrders,
            'distinct_discounts_in_manifest' => count($discountIds),
            'orders_with_single_job' => $singleJobOrders,
            'orders_with_multiple_jobs' => $multiJobOrders,
            'extra_duplicate_jobs_for_same_order' => $extraDuplicateJobsSameOrder,
            'jobs_per_order_distribution' => $jobsPerOrderDistribution,
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
            'hmac_verified' => $hmacVerified,
            'allowed_jobs' => $allowedJobs,
            'totals_declared' => $declaredTotals,
            'anomalies_declared' => $declaredAnomalies,
            'order_job_counts' => $state['order_job_counts'],
            'order_job_sample_csv' => $state['order_job_sample_csv'],
            'order_reserved_counts' => $state['order_reserved_counts'],
            'discount_ids' => $discountIds,
            'job_accounting' => $jobAccounting,
        ];
    }

    private function verifyExpectedHashAndHmac(
        string $computedManifestHash,
        string $fileSha256,
        ?string $expectedManifestSha256,
        ?string $hmacKey,
        mixed $declaredHmac,
    ): bool {
        $normalizedExpected = $expectedManifestSha256 !== null ? strtolower(trim($expectedManifestSha256)) : '';
        if ($normalizedExpected !== '') {
            $matchesCanonical = preg_match('/\A[a-f0-9]{64}\z/', $normalizedExpected) === 1
                && (hash_equals($normalizedExpected, $computedManifestHash) || hash_equals($normalizedExpected, $fileSha256));
            if (! $matchesCanonical) {
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
        }

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

            return true;
        }

        return false;
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
