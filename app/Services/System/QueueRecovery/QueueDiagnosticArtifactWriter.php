<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Throwable;

/**
 * Hardened file writer for R2-DIAG diagnostic reports (NDJSON entities, JSON summary, completion marker).
 *
 * Composes `QueueRecoveryPathGuard` without modifying R2.1 prune/backup/restore logic (D3 / PR #17):
 * - Rejects symlinks, traversal, forbidden repo paths, path collisions, and overwriting existing files.
 * - Creates temporary files exclusively (`xb` / O_CREAT|O_EXCL) under umask 0077 (0600 from creation).
 * - Checks partial writes, `fflush()`, `fsync()` (when available), and `fclose()`.
 * - Enforces strictly atomic non-clobbering publication via `link()` + `unlink($tempPath)` on BOTH
 *   Linux/POSIX (`link(2)` -> EEXIST) and Windows NTFS (`CreateHardLinkW` -> ERROR_ALREADY_EXISTS).
 * - NEVER falls back to `rename()` when `disallowOverwrite=true`; if `link()` fails or is unavailable
 *   on the host OS/filesystem, aborts fail-closed, preserves any existing file untouched, and cleans up
 *   the temporary file.
 * - Verifies effective POSIX permissions (`0600`) and post-publish SHA-256 hash on disk.
 * - Cleans up temporary files cleanly on any exception without touching pre-existing files or backups.
 */
class QueueDiagnosticArtifactWriter
{
    /** @var (callable(resource, string): (int|false))|null */
    private $writeCallback;

    /** @var (callable(resource): bool)|null */
    private $fsyncCallback;

    /** @var (callable(string, string): bool)|null */
    private $linkCallback;

    private bool $forceLinkUnavailable = false;

    /** @var (callable(string, string): void)|null */
    private $beforePublishHook = null;

    /**
     * @param  (callable(resource, string): (int|false))|null  $writeCallback
     * @param  (callable(resource): bool)|null  $fsyncCallback
     * @param  (callable(string, string): bool)|null  $linkCallback
     */
    public function __construct(
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
        ?callable $writeCallback = null,
        ?callable $fsyncCallback = null,
        ?callable $linkCallback = null,
    ) {
        $this->writeCallback = $writeCallback;
        $this->fsyncCallback = $fsyncCallback;
        $this->linkCallback = $linkCallback;
    }

    /**
     * Test hook to simulate environments/filesystems where atomic non-clobbering `link()` is unavailable.
     */
    public function setForceLinkUnavailable(bool $unavailable): void
    {
        $this->forceLinkUnavailable = $unavailable;
    }

    /**
     * Test hook invoked strictly AFTER all pre-checks and immediately BEFORE atomic `link()`,
     * allowing deterministic testing of concurrent destination creation.
     *
     * @param  (callable(string, string): void)|null  $hook
     */
    public function setBeforePublishHook(?callable $hook): void
    {
        $this->beforePublishHook = $hook;
    }

    /**
     * Rejects any path collision across manifest, backup, NDJSON output, and JSON summary output.
     *
     * @param  array<string, ?string>  $labeledPaths
     */
    public function assertNoPathCollisions(array $labeledPaths): void
    {
        $seen = [];

        foreach ($labeledPaths as $label => $rawPath) {
            if ($rawPath === null || trim($rawPath) === '') {
                continue;
            }

            $normalized = $this->normalizeComparablePath(trim($rawPath));
            if (isset($seen[$normalized])) {
                throw new QueueRecoveryException(
                    sprintf(
                        "Colisión de rutas rechazada por seguridad: '%s' y '%s' apuntan al mismo archivo ('%s').",
                        $seen[$normalized],
                        $label,
                        $rawPath,
                    ),
                    'artifact_path_collision',
                    ['first_role' => $seen[$normalized], 'second_role' => $label, 'path' => $rawPath],
                );
            }

            $seen[$normalized] = $label;
        }
    }

    /**
     * Validates an output path and opens an exclusive (`xb`) 0600 temporary file for incremental streaming.
     *
     * @return array{
     *     target_path: string,
     *     temp_path: string,
     *     handle: resource,
     *     hash_ctx: \HashContext,
     *     bytes_written: int,
     *     records_written: int,
     *     closed: bool,
     *     disallow_overwrite: bool
     * }
     */
    public function openExclusiveStream(
        string $targetPath,
        bool $disallowOverwrite = true,
        bool $disallowInsideRepo = false,
    ): array {
        $safePath = $this->pathGuard->assertSafeOutputPath($targetPath, false, $disallowInsideRepo);

        if ($disallowOverwrite) {
            clearstatcache(true, $safePath);
            if (file_exists($safePath) || is_link($safePath)) {
                throw new QueueRecoveryException(
                    "El archivo destino ya existe y no se permite sobrescritura: '{$safePath}'.",
                    'artifact_already_exists',
                    ['path' => $safePath],
                );
            }
        }

        $this->pathGuard->ensureRestrictiveDirectory(dirname($safePath), 0700);

        $tempPath = $safePath.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(8));
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($tempPath, 'xb');
        } finally {
            umask($previousUmask);
        }

        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo crear archivo temporal exclusivo (xb) en '{$tempPath}'.",
                'io_write_failed',
                ['path' => $safePath, 'temp_path' => $tempPath],
            );
        }

        try {
            @chmod($tempPath, 0600);
            $this->assertEffectiveRestrictivePermissions($tempPath);
        } catch (Throwable $e) {
            @fclose($handle);
            @unlink($tempPath);
            throw $e;
        }

        return [
            'target_path' => $safePath,
            'temp_path' => $tempPath,
            'handle' => $handle,
            'hash_ctx' => hash_init('sha256'),
            'bytes_written' => 0,
            'records_written' => 0,
            'closed' => false,
            'disallow_overwrite' => $disallowOverwrite,
        ];
    }

    /**
     * Writes bytes with partial-write verification and updates incremental SHA-256 state.
     *
     * @param  array<string, mixed>  $stream
     */
    public function writeStreamChunk(array &$stream, string $bytes, bool $countRecord = true): void
    {
        $expectedLength = strlen($bytes);
        $handle = $stream['handle'];
        $tempPath = (string) $stream['temp_path'];

        $written = $this->writeCallback !== null
            ? ($this->writeCallback)($handle, $bytes)
            : @fwrite($handle, $bytes);

        if ($written === false || $written !== $expectedLength) {
            throw new QueueRecoveryException(
                "Escritura incompleta o falta de espacio al escribir en '{$tempPath}' (esperados {$expectedLength} bytes).",
                'io_write_failed',
                ['path' => $stream['target_path'], 'expected_bytes' => $expectedLength, 'written_bytes' => $written],
            );
        }

        hash_update($stream['hash_ctx'], $bytes);
        $stream['bytes_written'] += $written;
        if ($countRecord) {
            $stream['records_written']++;
        }
    }

    /**
     * Flushes, fsyncs, closes, non-clobberingly publishes, and verifies permissions & final disk SHA-256.
     *
     * @param  array<string, mixed>  $stream
     * @return array{
     *     path: string,
     *     sha256: string,
     *     size_bytes: int,
     *     records: int,
     *     disk_sha256_verified: bool,
     *     permissions_verified: bool
     * }
     */
    public function publishStream(array &$stream): array
    {
        $handle = $stream['handle'];
        $tempPath = (string) $stream['temp_path'];
        $targetPath = (string) $stream['target_path'];
        $disallowOverwrite = (bool) $stream['disallow_overwrite'];

        if (! @fflush($handle)) {
            $this->abortStream($stream);
            throw new QueueRecoveryException(
                "Falló fflush() al persistir '{$tempPath}'.",
                'io_sync_failed',
                ['path' => $targetPath],
            );
        }

        $synced = $this->fsyncCallback !== null
            ? ($this->fsyncCallback)($handle)
            : (function_exists('fsync') ? @fsync($handle) : true);

        if (! $synced) {
            $this->abortStream($stream);
            throw new QueueRecoveryException(
                "Falló fsync() al sincronizar '{$tempPath}' en disco.",
                'io_sync_failed',
                ['path' => $targetPath],
            );
        }

        if (! @fclose($handle)) {
            $stream['closed'] = true;
            $this->abortStream($stream);
            throw new QueueRecoveryException(
                "Falló fclose() en '{$tempPath}'.",
                'io_write_failed',
                ['path' => $targetPath],
            );
        }
        $stream['closed'] = true;

        $expectedSha256 = hash_final($stream['hash_ctx']);

        // Validate path hierarchy safety before atomic publish.
        try {
            $this->pathGuard->assertSafeOutputPath($targetPath, false, false);
        } catch (Throwable $e) {
            $this->abortStream($stream);
            throw $e;
        }

        // Test hook fires AFTER all pre-checks and immediately BEFORE the single atomic `link()` syscall.
        if ($this->beforePublishHook !== null) {
            ($this->beforePublishHook)($tempPath, $targetPath);
        }

        if ($disallowOverwrite) {
            $linkAvailable = ! $this->forceLinkUnavailable && ($this->linkCallback !== null || function_exists('link'));
            if (! $linkAvailable) {
                $this->abortStream($stream);
                throw new QueueRecoveryException(
                    "Operación atómica sin sobrescritura (link) no disponible para publicar '{$targetPath}'; abortando en modo fail-closed sin usar rename().",
                    'atomic_publish_unavailable',
                    ['path' => $targetPath, 'os_family' => PHP_OS_FAMILY],
                );
            }

            // Strictly atomic non-clobbering publication:
            // - Linux/POSIX: link(2) fails atomically with EEXIST if $targetPath exists (file or symlink).
            // - Windows NTFS: CreateHardLinkW() fails atomically with ERROR_ALREADY_EXISTS if $targetPath exists.
            // - NEVER falls back to rename()!
            $linked = $this->linkCallback !== null
                ? ($this->linkCallback)($tempPath, $targetPath)
                : @link($tempPath, $targetPath);

            if (! $linked) {
                $lastError = error_get_last();
                $this->abortStream($stream);

                clearstatcache(true, $targetPath);
                if (file_exists($targetPath) || is_link($targetPath)) {
                    throw new QueueRecoveryException(
                        "Creación concurrente detectada en '{$targetPath}'; publicación atómica rechazada sin sobrescribir el destino.",
                        'artifact_already_exists',
                        ['path' => $targetPath],
                    );
                }

                throw new QueueRecoveryException(
                    "Falló la publicación atómica sin sobrescritura (link) hacia '{$targetPath}'; abortando en modo fail-closed sin fallback a rename().",
                    'atomic_publish_failed',
                    [
                        'path' => $targetPath,
                        'os_family' => PHP_OS_FAMILY,
                        'os_error' => is_array($lastError) ? (string) ($lastError['message'] ?? '') : null,
                    ],
                );
            }

            // Remove temporary directory entry now that $targetPath atomically references the inode.
            @unlink($tempPath);
        } else {
            if (! @rename($tempPath, $targetPath)) {
                $this->abortStream($stream);
                throw new QueueRecoveryException(
                    "No se pudo publicar '{$tempPath}' en '{$targetPath}'.",
                    'io_write_failed',
                    ['path' => $targetPath],
                );
            }
        }

        if (is_link($targetPath)) {
            throw new QueueRecoveryException(
                "El archivo publicado resultó ser un enlace simbólico: '{$targetPath}'.",
                'unsafe_symlink_path',
                ['path' => $targetPath],
            );
        }

        @chmod($targetPath, 0600);
        $this->assertEffectiveRestrictivePermissions($targetPath);

        $diskSha256 = @hash_file('sha256', $targetPath);
        if (! is_string($diskSha256) || ! hash_equals($expectedSha256, $diskSha256)) {
            throw new QueueRecoveryException(
                "Verificación post-escritura fallida: el SHA-256 en disco no coincide con los bytes emitidos para '{$targetPath}'.",
                'artifact_integrity_mismatch',
                ['path' => $targetPath, 'expected_sha256' => $expectedSha256, 'actual_sha256' => $diskSha256],
            );
        }

        return [
            'path' => $targetPath,
            'sha256' => $expectedSha256,
            'size_bytes' => (int) $stream['bytes_written'],
            'records' => (int) $stream['records_written'],
            'disk_sha256_verified' => true,
            'permissions_verified' => true,
        ];
    }

    /**
     * Writes and publishes a complete string payload (e.g. JSON summary) using identical D3 guarantees.
     *
     * @return array{
     *     path: string,
     *     sha256: string,
     *     size_bytes: int,
     *     records: int,
     *     disk_sha256_verified: bool,
     *     permissions_verified: bool
     * }
     */
    public function writeAndPublishString(
        string $targetPath,
        string $content,
        bool $disallowOverwrite = true,
        bool $disallowInsideRepo = false,
    ): array {
        $stream = $this->openExclusiveStream($targetPath, $disallowOverwrite, $disallowInsideRepo);

        try {
            $this->writeStreamChunk($stream, $content, countRecord: false);

            return $this->publishStream($stream);
        } catch (Throwable $e) {
            $this->abortStream($stream);
            throw $e;
        }
    }

    /**
     * Closes open temp handle and removes temp file cleanly without touching pre-existing files.
     *
     * @param  array<string, mixed>|null  $stream
     */
    public function abortStream(?array &$stream): void
    {
        if (! is_array($stream)) {
            return;
        }

        if (empty($stream['closed']) && isset($stream['handle']) && is_resource($stream['handle'])) {
            @fclose($stream['handle']);
            $stream['closed'] = true;
        }

        $tempPath = isset($stream['temp_path']) ? (string) $stream['temp_path'] : '';
        if ($tempPath !== '' && is_file($tempPath)) {
            @unlink($tempPath);
        }
    }

    private function assertEffectiveRestrictivePermissions(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if ($perms === false) {
            throw new QueueRecoveryException(
                "No se pudieron verificar los permisos efectivos de '{$path}'.",
                'unsafe_file_permissions',
                ['path' => $path],
            );
        }

        $mode = $perms & 0777;
        if (($mode & 0077) !== 0) {
            throw new QueueRecoveryException(
                sprintf("Permisos inseguros detectados en '%s' (modo=%04o; requerido 0600).", $path, $mode),
                'unsafe_file_permissions',
                ['path' => $path, 'mode' => sprintf('%04o', $mode)],
            );
        }
    }

    private function normalizeComparablePath(string $path): string
    {
        $real = @realpath($path);
        if ($real !== false) {
            $normalized = str_replace('\\', '/', $real);

            return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
        }

        $parent = dirname($path);
        $realParent = @realpath($parent);
        $base = basename($path);
        $joined = $realParent !== false ? rtrim($realParent, '/\\').'/'.$base : $path;
        $normalized = str_replace('\\', '/', $joined);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
