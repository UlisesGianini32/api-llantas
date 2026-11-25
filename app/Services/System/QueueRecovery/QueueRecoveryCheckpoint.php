<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Atomic persistent cursor/checkpoint for resumable batch pruning with full fsync & 0600 permissions.
 */
class QueueRecoveryCheckpoint
{
    public function __construct(
        private readonly string $path,
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed>|null */
    public function load(string $expectedManifestSha256): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $safePath = $this->pathGuard->assertSafeInputPath($this->path);
        $raw = @file_get_contents($safePath);
        if (! is_string($raw) || trim($raw) === '') {
            throw new QueueRecoveryException(
                "Archivo de checkpoint corrupto o vacío: '{$safePath}'.",
                'checkpoint_corrupt',
                ['path' => $safePath],
            );
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                "JSON inválido en archivo de checkpoint: '{$safePath}'.",
                'checkpoint_corrupt',
                ['path' => $safePath],
                $e,
            );
        }

        if (! is_array($data)) {
            throw new QueueRecoveryException("Estructura inválida en checkpoint: '{$safePath}'.", 'checkpoint_corrupt');
        }

        if (! hash_equals($expectedManifestSha256, (string) ($data['manifest_sha256'] ?? ''))) {
            throw new QueueRecoveryException(
                'El checkpoint no corresponde al manifiesto actual (manifest_sha256 distinto).',
                'checkpoint_manifest_mismatch',
                ['path' => $safePath],
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $state */
    public function saveAtomic(array $state): void
    {
        $state['updated_at'] = now()->utc()->toIso8601String();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $this->pathGuard->writeAtomicFile($this->path, $json, 0600, false, false);
    }
}
