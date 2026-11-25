<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

/**
 * Enforces safe file paths (no traversal, no symlinks, no unsafe git/web directories,
 * no accidental backup overwrite), restrictive permissions (0600/0700), and verified
 * full-write + flush + fsync semantics.
 */
class QueueRecoveryPathGuard
{
    /** @var (callable(resource): bool)|null */
    private $fsyncCallback;

    /** @var (callable(resource, string): (int|false))|null */
    private $writeCallback;

    /**
     * @param  (callable(resource): bool)|null  $fsyncCallback
     * @param  (callable(resource, string): (int|false))|null  $writeCallback
     */
    public function __construct(?callable $fsyncCallback = null, ?callable $writeCallback = null)
    {
        $this->fsyncCallback = $fsyncCallback;
        $this->writeCallback = $writeCallback;
    }

    /**
     * Validates an output file path for sensitive artifacts (backups, manifests, audit logs, checkpoints).
     */
    public function assertSafeOutputPath(
        string $path,
        bool $disallowOverwrite = false,
        bool $disallowInsideRepo = false,
    ): string {
        $trimmed = trim($path);
        if ($trimmed === '' || str_contains($trimmed, "\0")) {
            throw new QueueRecoveryException('Ruta de archivo inválida o vacía.', 'unsafe_path', ['path' => $path]);
        }

        if (preg_match('#(?:^|[/\\\\])\.\.(?:$|[/\\\\])#', $trimmed) === 1) {
            throw new QueueRecoveryException(
                "Ruta insegura rechazada (path traversal '..'): '{$trimmed}'.",
                'unsafe_path',
                ['path' => $trimmed],
            );
        }

        // Reject symlink at target itself or any existing ancestor component.
        $this->assertNoSymlinkInHierarchy($trimmed);

        if ($disallowOverwrite && file_exists($trimmed)) {
            throw new QueueRecoveryException(
                "El archivo ya existe y no se permite sobrescritura accidental: '{$trimmed}'.",
                'backup_already_exists',
                ['path' => $trimmed],
            );
        }

        $this->assertNotForbiddenRepoLocation($trimmed, $disallowInsideRepo);

        return $trimmed;
    }

    /**
     * Validates an existing input file path (rejects traversal and symlinks).
     */
    public function assertSafeInputPath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || str_contains($trimmed, "\0")) {
            throw new QueueRecoveryException('Ruta de entrada inválida o vacía.', 'unsafe_path', ['path' => $path]);
        }

        if (preg_match('#(?:^|[/\\\\])\.\.(?:$|[/\\\\])#', $trimmed) === 1) {
            throw new QueueRecoveryException(
                "Ruta insegura rechazada (path traversal '..'): '{$trimmed}'.",
                'unsafe_path',
                ['path' => $trimmed],
            );
        }

        $this->assertNoSymlinkInHierarchy($trimmed);

        return $trimmed;
    }

    public function ensureRestrictiveDirectory(string $dir, int $mode = 0700): void
    {
        $this->assertNoSymlinkInHierarchy($dir);

        if (! is_dir($dir)) {
            if (! @mkdir($dir, $mode, true) && ! is_dir($dir)) {
                throw new QueueRecoveryException(
                    "No se pudo crear el directorio seguro: '{$dir}'.",
                    'io_write_failed',
                    ['dir' => $dir],
                );
            }
            @chmod($dir, $mode);
        }
    }

    /** @param resource $handle */
    public function writeAllAndSync($handle, string $bytes, string $contextPath, bool $sync = true): void
    {
        $expectedLength = strlen($bytes);
        $written = $this->writeCallback !== null
            ? ($this->writeCallback)($handle, $bytes)
            : @fwrite($handle, $bytes);

        if ($written === false || $written !== $expectedLength) {
            throw new QueueRecoveryException(
                "Escritura incompleta o fallo de disco al escribir en '{$contextPath}' (esperados {$expectedLength} bytes).",
                'io_write_failed',
                ['path' => $contextPath, 'expected_bytes' => $expectedLength, 'written_bytes' => $written],
            );
        }

        if (! @fflush($handle)) {
            throw new QueueRecoveryException(
                "Falló fflush() al persistir '{$contextPath}'.",
                'io_sync_failed',
                ['path' => $contextPath],
            );
        }

        if ($sync) {
            $synced = $this->fsyncCallback !== null
                ? ($this->fsyncCallback)($handle)
                : (function_exists('fsync') ? @fsync($handle) : true);

            if (! $synced) {
                throw new QueueRecoveryException(
                    "Falló fsync() al sincronizar '{$contextPath}' en disco.",
                    'io_sync_failed',
                    ['path' => $contextPath],
                );
            }
        }
    }

    public function writeAtomicFile(
        string $targetPath,
        string $content,
        int $fileMode = 0600,
        bool $disallowOverwrite = false,
        bool $disallowInsideRepo = false,
    ): void {
        $safePath = $this->assertSafeOutputPath($targetPath, $disallowOverwrite, $disallowInsideRepo);
        $this->ensureRestrictiveDirectory(dirname($safePath), 0700);

        $tmp = $safePath.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo abrir archivo temporal '{$tmp}'.",
                'io_write_failed',
                ['path' => $safePath],
            );
        }

        try {
            @chmod($tmp, $fileMode);
            $this->writeAllAndSync($handle, $content, $tmp, true);
        } catch (\Throwable $e) {
            @fclose($handle);
            @unlink($tmp);
            throw $e;
        }

        if (! @fclose($handle)) {
            @unlink($tmp);
            throw new QueueRecoveryException("Falló fclose() en '{$tmp}'.", 'io_write_failed', ['path' => $safePath]);
        }

        if (! @rename($tmp, $safePath)) {
            @unlink($tmp);
            throw new QueueRecoveryException(
                "No se pudo renombrar atómicamente '{$tmp}' a '{$safePath}'.",
                'io_write_failed',
                ['path' => $safePath],
            );
        }

        @chmod($safePath, $fileMode);
    }

    public function enforceRestrictivePermissions(string $path, int $mode = 0600): void
    {
        @chmod($path, $mode);
    }

    private function assertNoSymlinkInHierarchy(string $path): void
    {
        $cursor = $path;
        for ($i = 0; $i < 32; $i++) {
            if (is_link($cursor)) {
                throw new QueueRecoveryException(
                    "Ruta rechazada por seguridad: contiene enlace simbólico en '{$cursor}'.",
                    'unsafe_symlink_path',
                    ['path' => $path, 'symlink' => $cursor],
                );
            }

            $parent = dirname($cursor);
            if ($parent === $cursor || $parent === '' || $parent === '.') {
                break;
            }
            $cursor = $parent;
        }
    }

    private function assertNotForbiddenRepoLocation(string $path, bool $disallowInsideRepo): void
    {
        $base = realpath(base_path()) ?: base_path();
        $normalizedBase = $this->normalizeSlashes(rtrim($base, '/\\'));
        $resolvedParent = $this->resolveExistingParentRealpath($path);

        if ($resolvedParent === null) {
            return;
        }

        $normalizedTarget = $this->normalizeSlashes($resolvedParent.'/'.basename($path));

        if ($disallowInsideRepo && ($normalizedTarget === $normalizedBase || str_starts_with($normalizedTarget, $normalizedBase.'/'))) {
            throw new QueueRecoveryException(
                "Ruta de respaldo insegura: no se permite escribir respaldos sensibles dentro del repositorio Git ('{$path}'). Use almacenamiento externo o un directorio seguro fuera del repositorio.",
                'unsafe_repo_path',
                ['path' => $path],
            );
        }

        $forbiddenSubdirs = ['.git', 'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests', 'vendor'];
        foreach ($forbiddenSubdirs as $sub) {
            $prefix = $normalizedBase.'/'.$sub;
            if ($normalizedTarget === $prefix || str_starts_with($normalizedTarget, $prefix.'/')) {
                throw new QueueRecoveryException(
                    "Ruta prohibida dentro de directorios versionados o públicos del repositorio: '{$path}'.",
                    'unsafe_repo_path',
                    ['path' => $path, 'forbidden_prefix' => $sub],
                );
            }
        }
    }

    private function resolveExistingParentRealpath(string $path): ?string
    {
        $cursor = dirname($path);
        for ($i = 0; $i < 16; $i++) {
            if (is_dir($cursor)) {
                $real = realpath($cursor);

                return $real !== false ? $real : $cursor;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor || $parent === '') {
                break;
            }
            $cursor = $parent;
        }

        return null;
    }

    private function normalizeSlashes(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
