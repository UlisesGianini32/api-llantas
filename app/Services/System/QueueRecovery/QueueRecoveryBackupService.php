<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use JsonException;
use PDO;

/**
 * Exports full jobs rows (all 7 columns) for a verified manifest to a restrictive-permission (0600)
 * NDJSON backup outside the Git repository, verifies cryptographic hashes, runs an isolated
 * SQLite restore verification test, and supports PK-collision-safe restoration into an isolated DB.
 */
class QueueRecoveryBackupService
{
    public const SCHEMA_VERSION = 2;

    private const EXPORT_CHUNK_SIZE = 500;

    public function __construct(
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {}

    /**
     * Exports complete rows for every job entry in the manifest using PK chunks.
     * Refuses symlinks, unsafe repo paths, and accidental overwrite of existing backups.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     path: string,
     *     row_count: int,
     *     size_bytes: int,
     *     entries_sha256: string,
     *     file_sha256: string
     * }
     */
    public function exportForManifest(
        ConnectionInterface $connection,
        string $table,
        array $manifest,
        string $outputPath,
    ): array {
        $entries = $manifest['entries'] ?? [];
        if (! is_array($entries)) {
            throw new QueueRecoveryException('El manifiesto no contiene entradas válidas para respaldo.', 'manifest_invalid');
        }

        // Reject symlinks, traversal, paths inside Git repo, and existing backup files (no accidental overwrite!).
        $safeOutputPath = $this->pathGuard->assertSafeOutputPath(
            $outputPath,
            disallowOverwrite: true,
            disallowInsideRepo: true,
        );
        $this->pathGuard->ensureRestrictiveDirectory(dirname($safeOutputPath), 0700);

        $tmpPath = $safeOutputPath.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
        $handle = @fopen($tmpPath, 'wb');
        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo abrir archivo temporal de respaldo: '{$tmpPath}'.",
                'backup_write_failed',
                ['path' => $safeOutputPath],
            );
        }
        @chmod($tmpPath, 0600);

        $entriesById = [];
        foreach ($entries as $entry) {
            $entriesById[(int) $entry['id']] = $entry;
        }

        try {
            $header = [
                'type' => 'header',
                'schema_version' => self::SCHEMA_VERSION,
                'incident_id' => (string) $manifest['incident_id'],
                'manifest_sha256' => (string) $manifest['manifest_sha256'],
                'connection' => (string) $manifest['connection'],
                'database' => (string) $manifest['database'],
                'table' => $table,
                'authorized_upper_bound' => (int) ($manifest['authorized_upper_bound'] ?? $manifest['end_id'] ?? 0),
                'last_scanned_id' => (int) ($manifest['last_scanned_id'] ?? $manifest['end_id'] ?? 0),
                'expected_count' => count($entriesById),
                'created_at' => now()->utc()->toIso8601String(),
            ];
            $this->writeLine($handle, $header, $tmpPath, false);

            $digestContext = hash_init('sha256');
            $exportedCount = 0;

            foreach (array_chunk(array_keys($entriesById), self::EXPORT_CHUNK_SIZE) as $chunkIds) {
                $rows = $connection->table($table)
                    ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                    ->whereIn('id', $chunkIds)
                    ->orderBy('id')
                    ->get()
                    ->keyBy('id');

                foreach ($chunkIds as $jobId) {
                    $row = $rows->get($jobId);
                    if ($row === null) {
                        throw new QueueRecoveryException(
                            "No se pudo respaldar job #{$jobId}: el registro desapareció antes de exportar el respaldo.",
                            'backup_source_mismatch',
                            ['job_id' => $jobId],
                        );
                    }

                    $expected = $entriesById[$jobId];
                    $normalized = $this->normalizeRow($row);
                    $payloadHash = hash('sha256', $normalized['payload']);
                    $rowSha = $this->rowHash($normalized);

                    if ($normalized['reserved_at'] !== null) {
                        throw new QueueRecoveryException(
                            "No se pudo respaldar job #{$jobId}: el registro pasó a estado reservado.",
                            'reserved_jobs_detected',
                            ['job_id' => $jobId],
                        );
                    }

                    if ($normalized['queue'] !== (string) $expected['queue']
                        || $normalized['attempts'] !== (int) $expected['attempts']
                        || $normalized['available_at'] !== (int) $expected['available_at']
                        || $normalized['created_at'] !== (int) $expected['created_at']
                        || ! hash_equals((string) $expected['payload_hash'], $payloadHash)
                        || ! hash_equals((string) $expected['row_sha256'], $rowSha)) {
                        throw new QueueRecoveryException(
                            "No se pudo respaldar job #{$jobId}: discrepancia de columnas o hash entre BD y manifiesto.",
                            'backup_source_mismatch',
                            ['job_id' => $jobId],
                        );
                    }

                    hash_update($digestContext, $jobId.':'.$rowSha."\n");

                    $this->writeLine($handle, [
                        'type' => 'row',
                        'row' => $normalized,
                        'payload_hash' => $payloadHash,
                        'row_sha256' => $rowSha,
                    ], $tmpPath, false);
                    $exportedCount++;
                }
            }

            $entriesSha = hash_final($digestContext);
            $this->writeLine($handle, [
                'type' => 'footer',
                'row_count' => $exportedCount,
                'entries_sha256' => $entriesSha,
            ], $tmpPath, true);

            if (! @fclose($handle)) {
                $handle = null;
                throw new QueueRecoveryException("Falló fclose() al cerrar respaldo temporal '{$tmpPath}'.", 'backup_write_failed');
            }
            $handle = null;

            // Final check right before atomic rename to prevent race overwrite.
            if (file_exists($safeOutputPath)) {
                @unlink($tmpPath);
                throw new QueueRecoveryException(
                    "El archivo de respaldo ya existe: '{$safeOutputPath}'.",
                    'backup_already_exists',
                    ['path' => $safeOutputPath],
                );
            }

            if (! @rename($tmpPath, $safeOutputPath)) {
                @unlink($tmpPath);
                throw new QueueRecoveryException(
                    "No se pudo mover atómicamente el respaldo a '{$safeOutputPath}'.",
                    'backup_write_failed',
                    ['path' => $safeOutputPath],
                );
            }

            $this->pathGuard->enforceRestrictivePermissions($safeOutputPath, 0600);

            $fileSha = hash_file('sha256', $safeOutputPath);
            if (! is_string($fileSha)) {
                throw new QueueRecoveryException("No se pudo calcular el hash SHA-256 del respaldo '{$safeOutputPath}'.", 'backup_integrity_failed');
            }

            return [
                'path' => $safeOutputPath,
                'row_count' => $exportedCount,
                'size_bytes' => (int) (@filesize($safeOutputPath) ?: 0),
                'entries_sha256' => $entriesSha,
                'file_sha256' => $fileSha,
            ];
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($tmpPath);
            throw $e;
        }
    }

    /**
     * Verifies backup hashes against ALL 7 columns in the manifest AND executes an isolated SQLite
     * restore verification test before allowing any deletion.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     path: string,
     *     row_count: int,
     *     size_bytes: int,
     *     entries_sha256: string,
     *     file_sha256: string,
     *     isolated_restore_verified: bool,
     *     restore_duration_ms: float,
     *     restore_peak_memory_bytes: int
     * }
     */
    public function verifyAndTestRestore(string $backupPath, array $manifest): array
    {
        $safeBackupPath = $this->pathGuard->assertSafeInputPath($backupPath);

        if (! is_file($safeBackupPath) || ! is_readable($safeBackupPath)) {
            throw new QueueRecoveryException(
                "El archivo de respaldo no existe o no es legible: '{$safeBackupPath}'.",
                'backup_missing',
                ['path' => $safeBackupPath],
            );
        }

        $startedAt = microtime(true);
        $fileSha = hash_file('sha256', $safeBackupPath);
        if (! is_string($fileSha)) {
            throw new QueueRecoveryException("No se pudo calcular SHA-256 del respaldo '{$safeBackupPath}'.", 'backup_integrity_failed');
        }

        $expectedById = [];
        foreach ((array) ($manifest['entries'] ?? []) as $entry) {
            $expectedById[(int) $entry['id']] = $entry;
        }

        $handle = @fopen($safeBackupPath, 'rb');
        if ($handle === false) {
            throw new QueueRecoveryException("No se pudo abrir el respaldo '{$safeBackupPath}'.", 'backup_missing');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL,
                reserved_at INTEGER NULL,
                available_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            )
        ');

        $insertStmt = $pdo->prepare('
            INSERT INTO jobs (id, queue, payload, attempts, reserved_at, available_at, created_at)
            VALUES (:id, :queue, :payload, :attempts, :reserved_at, :available_at, :created_at)
        ');

        $header = null;
        $footer = null;
        $seenIds = [];
        $expectedRowHashes = [];
        $digestContext = hash_init('sha256');
        $lineNumber = 0;

        try {
            $pdo->beginTransaction();

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                try {
                    $record = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new QueueRecoveryException(
                        "Respaldo corrupto: JSON inválido en línea {$lineNumber}.",
                        'backup_integrity_failed',
                        ['line' => $lineNumber],
                        $e,
                    );
                }

                if (! is_array($record) || ! is_string($record['type'] ?? null)) {
                    throw new QueueRecoveryException(
                        "Respaldo corrupto: estructura inválida en línea {$lineNumber}.",
                        'backup_integrity_failed',
                        ['line' => $lineNumber],
                    );
                }

                $type = $record['type'];

                if ($type === 'header') {
                    if ($header !== null) {
                        throw new QueueRecoveryException('Respaldo corrupto: encabezado duplicado.', 'backup_integrity_failed');
                    }
                    $header = $record;
                    $this->assertHeaderMatchesManifest($header, $manifest);

                    continue;
                }

                if ($type === 'footer') {
                    if ($footer !== null) {
                        throw new QueueRecoveryException('Respaldo corrupto: pie de archivo duplicado.', 'backup_integrity_failed');
                    }
                    $footer = $record;

                    continue;
                }

                if ($type !== 'row' || $header === null || $footer !== null) {
                    throw new QueueRecoveryException(
                        "Respaldo corrupto: registro fuera de orden en línea {$lineNumber}.",
                        'backup_integrity_failed',
                        ['line' => $lineNumber],
                    );
                }

                $row = $record['row'] ?? null;
                if (! is_array($row)) {
                    throw new QueueRecoveryException("Respaldo corrupto: fila vacía en línea {$lineNumber}.", 'backup_integrity_failed');
                }

                $normalized = $this->normalizeRow($row);
                $jobId = $normalized['id'];

                if (isset($seenIds[$jobId])) {
                    throw new QueueRecoveryException(
                        "Respaldo corrupto: job #{$jobId} duplicado en el archivo de respaldo.",
                        'backup_integrity_failed',
                        ['job_id' => $jobId],
                    );
                }

                $computedPayloadHash = hash('sha256', $normalized['payload']);
                $computedRowHash = $this->rowHash($normalized);

                if (! hash_equals($computedPayloadHash, (string) ($record['payload_hash'] ?? ''))
                    || ! hash_equals($computedRowHash, (string) ($record['row_sha256'] ?? ''))) {
                    throw new QueueRecoveryException(
                        "Respaldo alterado: hash SHA-256 inválido para job #{$jobId}.",
                        'backup_integrity_failed',
                        ['job_id' => $jobId],
                    );
                }

                if (! isset($expectedById[$jobId])) {
                    throw new QueueRecoveryException(
                        "Respaldo inconsistente: job #{$jobId} no pertenece al manifiesto autorizado.",
                        'backup_integrity_failed',
                        ['job_id' => $jobId],
                    );
                }

                $manifestEntry = $expectedById[$jobId];
                if ($normalized['queue'] !== (string) $manifestEntry['queue']
                    || $normalized['attempts'] !== (int) $manifestEntry['attempts']
                    || $normalized['available_at'] !== (int) $manifestEntry['available_at']
                    || $normalized['created_at'] !== (int) $manifestEntry['created_at']
                    || ! hash_equals((string) $manifestEntry['payload_hash'], $computedPayloadHash)
                    || ! hash_equals((string) $manifestEntry['row_sha256'], $computedRowHash)
                    || $normalized['reserved_at'] !== null) {
                    throw new QueueRecoveryException(
                        "Respaldo inconsistente con el manifiesto para job #{$jobId}.",
                        'backup_integrity_failed',
                        ['job_id' => $jobId],
                    );
                }

                $insertStmt->execute([
                    ':id' => $normalized['id'],
                    ':queue' => $normalized['queue'],
                    ':payload' => $normalized['payload'],
                    ':attempts' => $normalized['attempts'],
                    ':reserved_at' => $normalized['reserved_at'],
                    ':available_at' => $normalized['available_at'],
                    ':created_at' => $normalized['created_at'],
                ]);

                $seenIds[$jobId] = true;
                $expectedRowHashes[$jobId] = $computedRowHash;
                hash_update($digestContext, $jobId.':'.$computedRowHash."\n");
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fclose($handle);
            throw $e;
        }

        fclose($handle);

        if ($header === null || $footer === null) {
            throw new QueueRecoveryException('Respaldo incompleto: falta encabezado o pie de archivo.', 'backup_integrity_failed');
        }

        $computedEntriesSha = hash_final($digestContext);
        if ((int) ($footer['row_count'] ?? -1) !== count($seenIds)
            || ! hash_equals($computedEntriesSha, (string) ($footer['entries_sha256'] ?? ''))) {
            throw new QueueRecoveryException(
                'Integridad del respaldo comprometida: el conteo o digest final no coincide.',
                'backup_integrity_failed',
                [
                    'declared_count' => $footer['row_count'] ?? null,
                    'actual_count' => count($seenIds),
                ],
            );
        }

        if (count($seenIds) !== count($expectedById)) {
            $missing = array_values(array_diff(array_keys($expectedById), array_keys($seenIds)));
            throw new QueueRecoveryException(
                sprintf('Respaldo incompleto: faltan %d registro(s) del manifiesto.', count($missing)),
                'backup_integrity_failed',
                ['missing_sample_ids' => array_slice($missing, 0, 10)],
            );
        }

        // Verify restored rows by reading them back from the isolated SQLite database.
        $restoredCount = 0;
        $selectStmt = $pdo->query('SELECT id, queue, payload, attempts, reserved_at, available_at, created_at FROM jobs ORDER BY id ASC');
        while (($restoredRow = $selectStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $normalizedRestored = $this->normalizeRow($restoredRow);
            $restoredId = $normalizedRestored['id'];
            $restoredHash = $this->rowHash($normalizedRestored);

            if (! isset($expectedRowHashes[$restoredId]) || ! hash_equals($expectedRowHashes[$restoredId], $restoredHash)) {
                throw new QueueRecoveryException(
                    "Falló la prueba de restauración aislada para job #{$restoredId}.",
                    'backup_restore_verification_failed',
                    ['job_id' => $restoredId],
                );
            }
            $restoredCount++;
        }

        if ($restoredCount !== count($expectedById)) {
            throw new QueueRecoveryException(
                'Falló la prueba de restauración aislada: el número de filas restauradas no coincide.',
                'backup_restore_verification_failed',
                ['expected' => count($expectedById), 'restored' => $restoredCount],
            );
        }

        return [
            'path' => $safeBackupPath,
            'row_count' => $restoredCount,
            'size_bytes' => (int) (@filesize($safeBackupPath) ?: 0),
            'entries_sha256' => $computedEntriesSha,
            'file_sha256' => $fileSha,
            'isolated_restore_verified' => true,
            'restore_duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'restore_peak_memory_bytes' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Restores a verified backup file into a target isolated Laravel DB connection.
     * - Preserves original PK IDs.
     * - Checks PK collisions BEFORE inserting and aborts cleanly with `restore_pk_collision` (never overwrites).
     * - Never modifies any commercial/business table.
     *
     * @return array{restored: int, verified: int, entries_sha256: string}
     */
    public function restoreToConnection(string $backupPath, string $connectionName, string $table = 'jobs'): array
    {
        $safeBackupPath = $this->pathGuard->assertSafeInputPath($backupPath);

        if (! is_file($safeBackupPath) || ! is_readable($safeBackupPath)) {
            throw new QueueRecoveryException("Archivo de respaldo no legible: '{$safeBackupPath}'.", 'backup_missing');
        }

        $connection = DB::connection($connectionName);

        // Pass 1: Validate full backup integrity AND check ALL PK collisions BEFORE inserting a single row!
        $allIds = [];
        $handleCheck = @fopen($safeBackupPath, 'rb');
        if ($handleCheck === false) {
            throw new QueueRecoveryException("No se pudo abrir el respaldo '{$safeBackupPath}'.", 'backup_missing');
        }

        $footer = null;
        $digestContext = hash_init('sha256');
        $expectedHashes = [];

        try {
            while (($line = fgets($handleCheck)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $record = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($record)) {
                    throw new QueueRecoveryException('Registro inválido en archivo de respaldo.', 'backup_integrity_failed');
                }
                $type = (string) ($record['type'] ?? '');
                if ($type === 'footer') {
                    $footer = $record;

                    continue;
                }
                if ($type !== 'row') {
                    continue;
                }
                $normalized = $this->normalizeRow((array) $record['row']);
                $rowHash = $this->rowHash($normalized);
                if (! hash_equals($rowHash, (string) ($record['row_sha256'] ?? ''))) {
                    throw new QueueRecoveryException(
                        "Hash corrupto durante restauración para job #{$normalized['id']}.",
                        'backup_integrity_failed',
                    );
                }
                $allIds[] = $normalized['id'];
                $expectedHashes[$normalized['id']] = $rowHash;
                hash_update($digestContext, $normalized['id'].':'.$rowHash."\n");
            }
        } finally {
            fclose($handleCheck);
        }

        $computedSha = hash_final($digestContext);
        if ($footer === null || ! hash_equals($computedSha, (string) ($footer['entries_sha256'] ?? ''))) {
            throw new QueueRecoveryException('Digest final del respaldo inválido durante restauración.', 'backup_integrity_failed');
        }

        // Check PK collisions in chunks BEFORE writing anything!
        foreach (array_chunk($allIds, self::EXPORT_CHUNK_SIZE) as $chunkIds) {
            $existingIds = $connection->table($table)
                ->whereIn('id', $chunkIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($existingIds !== []) {
                throw new QueueRecoveryException(
                    sprintf(
                        'Colisión de PK detectada antes de restaurar: %d ID(s) ya existen en %s (p. ej. #%d). Abortando sin sobrescribir.',
                        count($existingIds),
                        $table,
                        $existingIds[0],
                    ),
                    'restore_pk_collision',
                    ['colliding_sample_ids' => array_slice($existingIds, 0, 10)],
                );
            }
        }

        // Pass 2: Stream and insert in micro-transactions of 500 preserving original IDs.
        $handle = @fopen($safeBackupPath, 'rb');
        if ($handle === false) {
            throw new QueueRecoveryException("No se pudo abrir el respaldo '{$safeBackupPath}'.", 'backup_missing');
        }

        $batch = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $record = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($record) || (string) ($record['type'] ?? '') !== 'row') {
                    continue;
                }
                $batch[] = $this->normalizeRow((array) $record['row']);
                if (count($batch) >= self::EXPORT_CHUNK_SIZE) {
                    $this->insertRestoreBatch($connection, $table, $batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->insertRestoreBatch($connection, $table, $batch);
            }
        } finally {
            fclose($handle);
        }

        // Pass 3: Verify all restored rows exist with exact bit-for-bit content in the target isolated DB.
        $verified = 0;
        foreach (array_chunk(array_keys($expectedHashes), self::EXPORT_CHUNK_SIZE) as $ids) {
            $rows = $connection->table($table)
                ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
                ->whereIn('id', $ids)
                ->get();

            foreach ($rows as $row) {
                $normalized = $this->normalizeRow($row);
                $id = $normalized['id'];
                if (! isset($expectedHashes[$id]) || ! hash_equals($expectedHashes[$id], $this->rowHash($normalized))) {
                    throw new QueueRecoveryException(
                        "Verificación post-restauración fallida para job #{$id}.",
                        'backup_restore_verification_failed',
                    );
                }
                $verified++;
            }
        }

        if ($verified !== count($expectedHashes)) {
            throw new QueueRecoveryException(
                'Verificación post-restauración fallida: conteo de filas restauradas incompleto.',
                'backup_restore_verification_failed',
                ['expected' => count($expectedHashes), 'verified' => $verified],
            );
        }

        return [
            'restored' => count($expectedHashes),
            'verified' => $verified,
            'entries_sha256' => $computedSha,
        ];
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $manifest
     */
    private function assertHeaderMatchesManifest(array $header, array $manifest): void
    {
        if ((int) ($header['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new QueueRecoveryException('Versión de esquema de respaldo no soportada.', 'backup_integrity_failed');
        }

        if (! hash_equals((string) $manifest['manifest_sha256'], (string) ($header['manifest_sha256'] ?? ''))) {
            throw new QueueRecoveryException(
                'El archivo de respaldo no corresponde al checksum manifest_sha256 del manifiesto proporcionado.',
                'backup_integrity_failed',
            );
        }

        $manifestUpper = (int) ($manifest['authorized_upper_bound'] ?? $manifest['end_id'] ?? 0);
        if ((string) ($header['incident_id'] ?? '') !== (string) $manifest['incident_id']
            || (int) ($header['authorized_upper_bound'] ?? -1) !== $manifestUpper
            || (string) ($header['table'] ?? '') !== (string) $manifest['table']) {
            throw new QueueRecoveryException(
                'Los metadatos del respaldo (incident_id, authorized_upper_bound o table) no coinciden con el manifiesto.',
                'backup_integrity_failed',
            );
        }
    }

    /**
     * @param  list<array{id: int, queue: string, payload: string, attempts: int, reserved_at: ?int, available_at: int, created_at: int}>  $batch
     */
    private function insertRestoreBatch(ConnectionInterface $connection, string $table, array $batch): void
    {
        $ids = array_column($batch, 'id');
        $connection->transaction(function () use ($connection, $table, $batch, $ids): void {
            $existing = $connection->table($table)->whereIn('id', $ids)->pluck('id')->all();
            if ($existing !== []) {
                throw new QueueRecoveryException(
                    'Colisión de PK concurrente durante restauración en lote.',
                    'restore_pk_collision',
                    ['colliding_sample_ids' => array_slice(array_map('intval', $existing), 0, 10)],
                );
            }
            $connection->table($table)->insert($batch);
        });
    }

    /**
     * @param  object|array<string, mixed>  $row
     * @return array{id: int, queue: string, payload: string, attempts: int, reserved_at: ?int, available_at: int, created_at: int}
     */
    public function normalizeRow(object|array $row): array
    {
        $arr = is_object($row) ? (array) $row : $row;

        return [
            'id' => (int) $arr['id'],
            'queue' => (string) $arr['queue'],
            'payload' => (string) $arr['payload'],
            'attempts' => (int) $arr['attempts'],
            'reserved_at' => $arr['reserved_at'] === null ? null : (int) $arr['reserved_at'],
            'available_at' => (int) $arr['available_at'],
            'created_at' => (int) $arr['created_at'],
        ];
    }

    /** @param array{id: int, queue: string, payload: string, attempts: int, reserved_at: ?int, available_at: int, created_at: int} $row */
    public function rowHash(array $row): string
    {
        return hash(
            'sha256',
            json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $data
     */
    private function writeLine($handle, array $data, string $contextPath, bool $sync): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $this->pathGuard->writeAllAndSync($handle, $json, $contextPath, $sync);
    }
}
