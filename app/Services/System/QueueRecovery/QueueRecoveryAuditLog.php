<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Append-only NDJSON audit log for queue recovery runs.
 * Stores metadata, IDs, counts, and SHA-256 hashes only — never raw payloads, tokens, or PII.
 */
class QueueRecoveryAuditLog
{
    public function __construct(private readonly ?string $path) {}

    public function path(): ?string
    {
        return $this->path;
    }

    /** @param array<string, mixed> $context */
    public function record(string $event, array $context = []): void
    {
        if ($this->path === null || trim($this->path) === '') {
            return;
        }

        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new QueueRecoveryException(
                "No se pudo crear el directorio para el audit log: '{$dir}'.",
                'audit_log_write_failed',
                ['path' => $this->path],
            );
        }

        $entry = [
            'timestamp' => now()->utc()->toIso8601String(),
            'event' => $event,
            ...$context,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo abrir el audit log para escritura: '{$this->path}'.",
                'audit_log_write_failed',
                ['path' => $this->path],
            );
        }

        try {
            flock($handle, LOCK_EX);
            if (fwrite($handle, $line) === false) {
                throw new QueueRecoveryException(
                    "No se pudo escribir en el audit log: '{$this->path}'.",
                    'audit_log_write_failed',
                    ['path' => $this->path],
                );
            }
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Finds batch_intent records for a manifest that were never followed by batch_committed
     * or batch_reconciled_after_crash (e.g., crash between DB commit and checkpoint write).
     *
     * @return list<array{batch_seq: int, ids: list<int>, min_id: int, max_id: int}>
     */
    public function findUnresolvedIntents(string $manifestSha256): array
    {
        if ($this->path === null || ! is_file($this->path) || ! is_readable($this->path)) {
            return [];
        }

        $intents = [];
        $resolved = [];

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                try {
                    $record = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }

                if (! is_array($record) || (string) ($record['manifest_sha256'] ?? '') !== $manifestSha256) {
                    continue;
                }

                $event = (string) ($record['event'] ?? '');
                $batchSeq = (int) ($record['batch_seq'] ?? 0);

                if ($batchSeq <= 0) {
                    continue;
                }

                if ($event === 'batch_intent' && is_array($record['ids'] ?? null)) {
                    $intents[$batchSeq] = [
                        'batch_seq' => $batchSeq,
                        'ids' => array_values(array_map('intval', $record['ids'])),
                        'min_id' => (int) ($record['min_id'] ?? 0),
                        'max_id' => (int) ($record['max_id'] ?? 0),
                    ];
                } elseif (in_array($event, ['batch_committed', 'batch_reconciled_after_crash', 'batch_aborted'], true)) {
                    $resolved[$batchSeq] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        $unresolved = [];
        foreach ($intents as $seq => $intent) {
            if (! isset($resolved[$seq])) {
                $unresolved[] = $intent;
            }
        }

        usort($unresolved, static fn (array $a, array $b): int => $a['batch_seq'] <=> $b['batch_seq']);

        return $unresolved;
    }

    /**
     * Counts all batch_seq numbers already used for this manifest in the audit log.
     */
    public function lastBatchSeq(string $manifestSha256): int
    {
        if ($this->path === null || ! is_file($this->path) || ! is_readable($this->path)) {
            return 0;
        }

        $maxSeq = 0;
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            return 0;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                try {
                    $record = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    continue;
                }
                if (is_array($record) && (string) ($record['manifest_sha256'] ?? '') === $manifestSha256) {
                    $seq = (int) ($record['batch_seq'] ?? 0);
                    if ($seq > $maxSeq) {
                        $maxSeq = $seq;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $maxSeq;
    }
}
