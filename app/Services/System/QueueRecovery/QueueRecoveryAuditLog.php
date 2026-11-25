<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Append-only NDJSON audit log for queue recovery runs.
 * - Verifies full writes + fflush + fsync on every record.
 * - NEVER silently ignores corrupt or truncated NDJSON lines (aborts fail-closed).
 * - Stores metadata, IDs, counts, and SHA-256 hashes only — never raw payloads, tokens, or PII.
 */
class QueueRecoveryAuditLog
{
    public function __construct(
        private readonly ?string $path,
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {}

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

        $safePath = $this->pathGuard->assertSafeOutputPath($this->path, false, false);
        $this->pathGuard->ensureRestrictiveDirectory(dirname($safePath), 0700);

        $entry = [
            'timestamp' => now()->utc()->toIso8601String(),
            'event' => $event,
            ...$context,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $handle = @fopen($safePath, 'ab');
        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo abrir el audit log para escritura: '{$safePath}'.",
                'audit_log_write_failed',
                ['path' => $safePath],
            );
        }

        try {
            @chmod($safePath, 0600);
            flock($handle, LOCK_EX);
            $this->pathGuard->writeAllAndSync($handle, $line, $safePath, true);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Parses the entire audit log strictly (aborting on ANY corrupt or truncated NDJSON line)
     * and classifies batch lifecycle state for a manifest.
     *
     * @return array{
     *     last_batch_seq: int,
     *     committed_batches: array<int, array{batch_seq: int, ids: list<int>, min_id: int, max_id: int, checkpoint_saved: bool}>,
     *     unresolved_intents: list<array{batch_seq: int, ids: list<int>, min_id: int, max_id: int}>
     * }
     */
    public function inspectManifestJournal(string $manifestSha256): array
    {
        if ($this->path === null || ! is_file($this->path)) {
            return [
                'last_batch_seq' => 0,
                'committed_batches' => [],
                'unresolved_intents' => [],
            ];
        }

        $safePath = $this->pathGuard->assertSafeInputPath($this->path);
        $handle = @fopen($safePath, 'rb');
        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo leer el audit log: '{$safePath}'.",
                'audit_log_unreadable',
                ['path' => $safePath],
            );
        }

        $intents = [];
        $committed = [];
        $checkpointed = [];
        $aborted = [];
        $lastSeq = 0;
        $lineNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }

                // Detect truncated final line lacking trailing newline or invalid JSON.
                try {
                    $record = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new QueueRecoveryException(
                        "Audit log corrupto o truncado en línea {$lineNumber}: '{$safePath}'. Revise la integridad antes de continuar.",
                        'audit_log_corrupt',
                        ['path' => $safePath, 'line' => $lineNumber],
                        $e,
                    );
                }

                if (! is_array($record) || ! is_string($record['event'] ?? null)) {
                    throw new QueueRecoveryException(
                        "Registro inválido en audit log línea {$lineNumber}: '{$safePath}'.",
                        'audit_log_corrupt',
                        ['path' => $safePath, 'line' => $lineNumber],
                    );
                }

                if ((string) ($record['manifest_sha256'] ?? '') !== $manifestSha256) {
                    continue;
                }

                $event = (string) $record['event'];
                $seq = (int) ($record['batch_seq'] ?? 0);
                if ($seq > $lastSeq) {
                    $lastSeq = $seq;
                }

                if ($seq <= 0) {
                    continue;
                }

                if ($event === 'batch_intent' && is_array($record['ids'] ?? null)) {
                    $intents[$seq] = [
                        'batch_seq' => $seq,
                        'ids' => array_values(array_map('intval', $record['ids'])),
                        'min_id' => (int) ($record['min_id'] ?? 0),
                        'max_id' => (int) ($record['max_id'] ?? 0),
                    ];
                } elseif (in_array($event, ['batch_committed', 'batch_reconciled_operator_confirmed'], true)) {
                    $ids = is_array($record['ids'] ?? null)
                        ? array_values(array_map('intval', $record['ids']))
                        : ($intents[$seq]['ids'] ?? []);
                    $committed[$seq] = [
                        'batch_seq' => $seq,
                        'ids' => $ids,
                        'min_id' => (int) ($record['min_id'] ?? ($ids !== [] ? min($ids) : 0)),
                        'max_id' => (int) ($record['max_id'] ?? ($ids !== [] ? max($ids) : 0)),
                        'checkpoint_saved' => false,
                    ];
                } elseif ($event === 'batch_checkpoint_saved') {
                    $checkpointed[$seq] = true;
                } elseif ($event === 'batch_aborted') {
                    $aborted[$seq] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        foreach ($committed as $seq => &$batch) {
            if (isset($checkpointed[$seq])) {
                $batch['checkpoint_saved'] = true;
            }
        }
        unset($batch);

        $unresolved = [];
        foreach ($intents as $seq => $intent) {
            if (! isset($committed[$seq]) && ! isset($aborted[$seq])) {
                $unresolved[] = $intent;
            }
        }
        usort($unresolved, static fn (array $a, array $b): int => $a['batch_seq'] <=> $b['batch_seq']);
        ksort($committed);

        return [
            'last_batch_seq' => $lastSeq,
            'committed_batches' => $committed,
            'unresolved_intents' => $unresolved,
        ];
    }
}
