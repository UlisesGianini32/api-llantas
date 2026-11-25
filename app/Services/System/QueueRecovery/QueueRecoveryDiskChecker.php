<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

class QueueRecoveryDiskChecker
{
    /** @var (callable(string): (float|int|false))|null */
    private $freeSpaceResolver;

    /** @param (callable(string): (float|int|false))|null $freeSpaceResolver */
    public function __construct(?callable $freeSpaceResolver = null)
    {
        $this->freeSpaceResolver = $freeSpaceResolver;
    }

    /**
     * Verifies every relevant filesystem path has at least $minFreeMb MiB available.
     *
     * @param  list<string>  $paths
     * @return array<string, float> Map of checked path => free MiB
     */
    public function assertMinimumFreeSpace(array $paths, int $minFreeMb): array
    {
        if ($minFreeMb < 1) {
            throw new QueueRecoveryException(
                'El parámetro --min-free-disk-mb debe ser mayor a cero.',
                'invalid_disk_threshold',
                ['min_free_disk_mb' => $minFreeMb],
            );
        }

        $checked = [];
        $uniquePaths = $this->normalizePaths($paths);

        foreach ($uniquePaths as $path) {
            $freeBytes = $this->freeBytesFor($path);

            if ($freeBytes === false || $freeBytes < 0) {
                throw new QueueRecoveryException(
                    "No fue posible determinar el espacio libre en disco para '{$path}'.",
                    'disk_space_unreadable',
                    ['path' => $path],
                );
            }

            $freeMb = round($freeBytes / (1024 * 1024), 2);
            $checked[$path] = $freeMb;

            if ($freeMb < $minFreeMb) {
                throw new QueueRecoveryException(
                    sprintf(
                        'Espacio libre insuficiente en %s: %.2f MiB disponibles, mínimo requerido %d MiB.',
                        $path,
                        $freeMb,
                        $minFreeMb,
                    ),
                    'insufficient_disk_space',
                    [
                        'path' => $path,
                        'free_mb' => $freeMb,
                        'required_mb' => $minFreeMb,
                    ],
                );
            }
        }

        return $checked;
    }

    private function freeBytesFor(string $path): float|int|false
    {
        if ($this->freeSpaceResolver !== null) {
            return ($this->freeSpaceResolver)($path);
        }

        return @disk_free_space($path);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $resolved = [];

        foreach ($paths as $raw) {
            $candidate = trim($raw);
            if ($candidate === '') {
                continue;
            }

            $existing = $this->nearestExistingDirectory($candidate);
            if ($existing === null) {
                throw new QueueRecoveryException(
                    "No se encontró un directorio existente para verificar espacio en disco: '{$candidate}'.",
                    'disk_space_unreadable',
                    ['path' => $candidate],
                );
            }

            $real = realpath($existing) ?: $existing;
            $resolved[$real] = true;
        }

        return array_keys($resolved);
    }

    private function nearestExistingDirectory(string $path): ?string
    {
        $cursor = $path;

        for ($depth = 0; $depth < 16; $depth++) {
            if (is_dir($cursor)) {
                return $cursor;
            }

            $parent = dirname($cursor);
            if ($parent === $cursor || $parent === '') {
                break;
            }

            $cursor = $parent;
        }

        return null;
    }
}
