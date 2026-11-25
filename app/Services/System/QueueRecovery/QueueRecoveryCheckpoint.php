<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Atomic persistent cursor/checkpoint for resumable batch pruning.
 */
class QueueRecoveryCheckpoint
{
    public function __construct(private readonly string $path) {}

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

        $raw = @file_get_contents($this->path);
        if (! is_string($raw) || trim($raw) === '') {
            throw new QueueRecoveryException(
                "Archivo de checkpoint corrupto o vacío: '{$this->path}'.",
                'checkpoint_corrupt',
                ['path' => $this->path],
            );
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                "JSON inválido en archivo de checkpoint: '{$this->path}'.",
                'checkpoint_corrupt',
                ['path' => $this->path],
                $e,
            );
        }

        if (! is_array($data)) {
            throw new QueueRecoveryException("Estructura inválida en checkpoint: '{$this->path}'.", 'checkpoint_corrupt');
        }

        if (! hash_equals($expectedManifestSha256, (string) ($data['manifest_sha256'] ?? ''))) {
            throw new QueueRecoveryException(
                'El checkpoint no corresponde al manifiesto actual (manifest_sha256 distinto).',
                'checkpoint_manifest_mismatch',
                ['path' => $this->path],
            );
        }

        return $data;
    }

    /** @param array<string, mixed> $state */
    public function saveAtomic(array $state): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new QueueRecoveryException(
                "No se pudo crear el directorio para el checkpoint: '{$dir}'.",
                'checkpoint_write_failed',
                ['path' => $this->path],
            );
        }

        $state['updated_at'] = now()->utc()->toIso8601String();
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $tmp = $this->path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            throw new QueueRecoveryException(
                "No se pudo escribir archivo temporal de checkpoint: '{$tmp}'.",
                'checkpoint_write_failed',
                ['path' => $this->path],
            );
        }

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new QueueRecoveryException(
                "No se pudo actualizar atómicamente el checkpoint: '{$this->path}'.",
                'checkpoint_write_failed',
                ['path' => $this->path],
            );
        }
    }
}
