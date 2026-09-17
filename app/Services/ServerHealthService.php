<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

final class ServerHealthService
{
    private const STATUS_HEALTHY = 'healthy';
    private const STATUS_WARNING = 'warning';
    private const STATUS_CRITICAL = 'critical';
    private const STATUS_UNKNOWN = 'unknown';

    /**
     * Obtiene una fotografía completa del estado actual del servidor.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $startedAt = microtime(true);

        $cpu = $this->cpu();
        $memory = $this->memory();
        $swap = $this->swap();
        $disk = $this->disk();
        $load = $this->loadAverage();
        $system = $this->systemInformation();
        $runtime = $this->runtimeInformation();
        $database = $this->database();
        $workers = $this->queueWorkers();
        $scheduler = $this->scheduler();

        $components = [
            'cpu' => $cpu,
            'memory' => $memory,
            'swap' => $swap,
            'disk' => $disk,
            'load' => $load,
            'database' => $database,
            'workers' => $workers,
            'scheduler' => $scheduler,
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'response_time_ms' => round(
                (microtime(true) - $startedAt) * 1000,
                2
            ),
            'overall' => $this->overallStatus($components),
            'cpu' => $cpu,
            'memory' => $memory,
            'swap' => $swap,
            'disk' => $disk,
            'load' => $load,
            'system' => $system,
            'runtime' => $runtime,
            'database' => $database,
            'workers' => $workers,
            'scheduler' => $scheduler,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cpu(): array
    {
        $logicalCores = $this->logicalCpuCores();
        $model = $this->cpuModel();
        $usage = $this->cpuUsagePercent();

        if ($usage === null) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No fue posible leer el uso actual del CPU.',
                'usage_percent' => null,
                'logical_cores' => $logicalCores,
                'model' => $model,
            ];
        }

        $status = match (true) {
            $usage >= 90 => self::STATUS_CRITICAL,
            $usage >= 75 => self::STATUS_WARNING,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'El uso del CPU es crítico.',
                self::STATUS_WARNING => 'El uso del CPU es elevado.',
                default => 'El uso del CPU está dentro de un rango saludable.',
            },
            'usage_percent' => $usage,
            'logical_cores' => $logicalCores,
            'model' => $model,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memory(): array
    {
        $memInfo = $this->readMemInfo();

        $totalKb = $memInfo['MemTotal'] ?? null;

        $availableKb = $memInfo['MemAvailable']
            ?? (
                ($memInfo['MemFree'] ?? 0)
                + ($memInfo['Buffers'] ?? 0)
                + ($memInfo['Cached'] ?? 0)
            );

        if (!$totalKb || $totalKb <= 0) {
            $fallback = $this->memoryFromFreeCommand();

            if ($fallback !== null) {
                return $fallback;
            }

            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No fue posible leer la memoria RAM.',
                'total_bytes' => null,
                'used_bytes' => null,
                'available_bytes' => null,
                'usage_percent' => null,
            ];
        }

        $usedKb = max(0, $totalKb - $availableKb);
        $usage = round(($usedKb / $totalKb) * 100, 2);

        $status = match (true) {
            $usage >= 92 => self::STATUS_CRITICAL,
            $usage >= 80 => self::STATUS_WARNING,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'La memoria RAM está prácticamente agotada.',
                self::STATUS_WARNING => 'El consumo de memoria RAM es elevado.',
                default => 'La memoria RAM está dentro de un rango saludable.',
            },
            'total_bytes' => $totalKb * 1024,
            'used_bytes' => $usedKb * 1024,
            'available_bytes' => $availableKb * 1024,
            'usage_percent' => $usage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function swap(): array
    {
        $memInfo = $this->readMemInfo();

        $totalKb = $memInfo['SwapTotal'] ?? null;
        $freeKb = $memInfo['SwapFree'] ?? null;

        if ($totalKb === null || $freeKb === null) {
            $fallback = $this->swapFromFreeCommand();

            if ($fallback !== null) {
                return $fallback;
            }

            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No fue posible leer la memoria swap.',
                'enabled' => null,
                'total_bytes' => null,
                'used_bytes' => null,
                'free_bytes' => null,
                'usage_percent' => null,
            ];
        }

        if ($totalKb <= 0) {
            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'El servidor no tiene swap configurada.',
                'enabled' => false,
                'total_bytes' => 0,
                'used_bytes' => 0,
                'free_bytes' => 0,
                'usage_percent' => 0,
            ];
        }

        $usedKb = max(0, $totalKb - $freeKb);
        $usage = round(($usedKb / $totalKb) * 100, 2);

        $status = match (true) {
            $usage >= 85 => self::STATUS_CRITICAL,
            $usage >= 60 => self::STATUS_WARNING,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'El uso de swap es crítico.',
                self::STATUS_WARNING => 'El servidor está utilizando una cantidad considerable de swap.',
                default => 'El uso de swap está dentro de un rango saludable.',
            },
            'enabled' => true,
            'total_bytes' => $totalKb * 1024,
            'used_bytes' => $usedKb * 1024,
            'free_bytes' => $freeKb * 1024,
            'usage_percent' => $usage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function disk(): array
    {
        try {
            $path = base_path();

            $total = @disk_total_space($path);
            $free = @disk_free_space($path);

            if ($total === false || $free === false || $total <= 0) {
                throw new RuntimeException(
                    'No fue posible consultar el espacio del disco.'
                );
            }

            $used = max(0, $total - $free);
            $usage = round(($used / $total) * 100, 2);

            $status = match (true) {
                $usage >= 95 => self::STATUS_CRITICAL,
                $usage >= 85 => self::STATUS_WARNING,
                default => self::STATUS_HEALTHY,
            };

            return [
                'status' => $status,
                'message' => match ($status) {
                    self::STATUS_CRITICAL => 'El disco está prácticamente lleno.',
                    self::STATUS_WARNING => 'El espacio disponible en disco es bajo.',
                    default => 'El almacenamiento está dentro de un rango saludable.',
                },
                'path' => $path,
                'total_bytes' => (int) $total,
                'used_bytes' => (int) $used,
                'free_bytes' => (int) $free,
                'usage_percent' => $usage,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No fue posible consultar el espacio del disco.',
                'error' => $exception->getMessage(),
                'path' => base_path(),
                'total_bytes' => null,
                'used_bytes' => null,
                'free_bytes' => null,
                'usage_percent' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAverage(): array
    {
        $values = function_exists('sys_getloadavg')
            ? @sys_getloadavg()
            : false;

        if ($values === false || count($values) < 3) {
            $values = $this->loadAverageFromCommand();
        }

        if ($values === null || count($values) < 3) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No fue posible obtener el promedio de carga.',
                'one_minute' => null,
                'five_minutes' => null,
                'fifteen_minutes' => null,
                'logical_cores' => $this->logicalCpuCores(),
                'normalized_percent' => null,
            ];
        }

        $cores = max(1, $this->logicalCpuCores() ?? 1);
        $normalized = round(((float) $values[0] / $cores) * 100, 2);

        $status = match (true) {
            $normalized >= 150 => self::STATUS_CRITICAL,
            $normalized >= 100 => self::STATUS_WARNING,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'La carga del servidor supera ampliamente su capacidad de CPU.',
                self::STATUS_WARNING => 'La carga del servidor es elevada.',
                default => 'La carga del servidor está dentro de un rango saludable.',
            },
            'one_minute' => round((float) $values[0], 2),
            'five_minutes' => round((float) $values[1], 2),
            'fifteen_minutes' => round((float) $values[2], 2),
            'logical_cores' => $cores,
            'normalized_percent' => $normalized,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function systemInformation(): array
    {
        $osRelease = $this->readOsRelease();
        $uptimeSeconds = $this->uptimeSeconds();

        return [
            'hostname' => gethostname() ?: null,
            'operating_system' => $osRelease['PRETTY_NAME']
                ?? trim(php_uname('s').' '.php_uname('r')),
            'kernel' => php_uname('r') ?: null,
            'architecture' => php_uname('m') ?: null,
            'uptime_seconds' => $uptimeSeconds,
            'uptime_human' => $this->humanDuration($uptimeSeconds),
            'server_time' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeInformation(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'laravel_version' => app()->version(),
            'environment' => app()->environment(),
            'debug_enabled' => (bool) config('app.debug'),
            'memory_limit' => ini_get('memory_limit') ?: null,
            'max_execution_time_seconds' => (int) ini_get(
                'max_execution_time'
            ),
            'opcache_enabled' => filter_var(
                ini_get('opcache.enable'),
                FILTER_VALIDATE_BOOL
            ),
            'exec_available' => $this->commandExecutionAvailable(),
            'open_basedir' => ini_get('open_basedir') ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        $startedAt = microtime(true);

        try {
            $connection = DB::connection();

            $connection->select('SELECT 1 AS health_check');

            $pdo = $connection->getPdo();
            $driver = $connection->getDriverName();
            $version = null;

            try {
                $versionResult = $connection->selectOne(
                    'SELECT VERSION() AS version'
                );

                $version = $versionResult?->version ?? null;
            } catch (Throwable) {
                // La conexión es válida aunque no se pueda leer la versión.
            }

            try {
                $serverVersion = $version
                    ?: $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            } catch (Throwable) {
                $serverVersion = $version;
            }

            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'La base de datos responde correctamente.',
                'connected' => true,
                'driver' => $driver,
                'database' => $connection->getDatabaseName(),
                'server_version' => $serverVersion,
                'response_time_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => self::STATUS_CRITICAL,
                'message' => 'No fue posible conectar con la base de datos.',
                'connected' => false,
                'driver' => config('database.default'),
                'database' => config(
                    'database.connections.'
                    .config('database.default')
                    .'.database'
                ),
                'server_version' => null,
                'response_time_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queueWorkers(): array
    {
        $processes = $this->processList();

        if ($processes === null) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No fue posible inspeccionar los procesos del servidor.',
                'inspection_available' => false,
                'total' => null,
                'queue_workers' => [],
                'horizon_workers' => [],
                'php_fpm_processes' => null,
            ];
        }

        $queueWorkers = [];
        $horizonWorkers = [];
        $phpFpmCount = 0;

        foreach ($processes as $process) {
            $command = $process['command'];

            if (
                str_contains($command, 'php-fpm')
                || str_contains($command, 'php-fpm:')
            ) {
                $phpFpmCount++;
            }

            if (str_contains($command, 'artisan queue:work')) {
                $queueWorkers[] = $process;
            }

            if (
                str_contains($command, 'artisan horizon')
                || str_contains($command, 'horizon:work')
            ) {
                $horizonWorkers[] = $process;
            }
        }

        $total = count($queueWorkers) + count($horizonWorkers);

        $status = $total > 0
            ? self::STATUS_HEALTHY
            : self::STATUS_WARNING;

        return [
            'status' => $status,
            'message' => $total > 0
                ? 'Se detectaron workers de cola activos.'
                : 'No se detectaron workers de cola activos.',
            'inspection_available' => true,
            'total' => $total,
            'queue_workers' => $queueWorkers,
            'horizon_workers' => $horizonWorkers,
            'php_fpm_processes' => $phpFpmCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $keys = [
            'scheduler:last_run',
            'scheduler_last_run',
            'system:scheduler:last_run',
            'system_scheduler_last_run',
            'schedule:last_run',
        ];

        $value = null;
        $detectedKey = null;

        foreach ($keys as $key) {
            try {
                if (!Cache::has($key)) {
                    continue;
                }

                $value = Cache::get($key);
                $detectedKey = $key;

                break;
            } catch (Throwable) {
                // Continuar con la siguiente llave.
            }
        }

        if ($value === null) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'No existe todavía un heartbeat del scheduler.',
                'heartbeat_configured' => false,
                'cache_key' => null,
                'last_run_at' => null,
                'seconds_since_last_run' => null,
            ];
        }

        try {
            $lastRun = match (true) {
                $value instanceof Carbon => $value,
                $value instanceof \DateTimeInterface => Carbon::instance(
                    $value
                ),
                is_numeric($value) => Carbon::createFromTimestamp(
                    (int) $value
                ),
                default => Carbon::parse((string) $value),
            };

            $seconds = abs(now()->diffInSeconds($lastRun));

            $status = match (true) {
                $seconds > 600 => self::STATUS_CRITICAL,
                $seconds > 180 => self::STATUS_WARNING,
                default => self::STATUS_HEALTHY,
            };

            return [
                'status' => $status,
                'message' => match ($status) {
                    self::STATUS_CRITICAL => 'El scheduler lleva más de 10 minutos sin registrar actividad.',
                    self::STATUS_WARNING => 'El scheduler lleva más de 3 minutos sin registrar actividad.',
                    default => 'El scheduler está registrando actividad correctamente.',
                },
                'heartbeat_configured' => true,
                'cache_key' => $detectedKey,
                'last_run_at' => $lastRun->toIso8601String(),
                'seconds_since_last_run' => $seconds,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'message' => 'El heartbeat del scheduler contiene un valor no reconocido.',
                'heartbeat_configured' => true,
                'cache_key' => $detectedKey,
                'last_run_at' => null,
                'seconds_since_last_run' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $components
     * @return array<string, mixed>
     */
    private function overallStatus(array $components): array
    {
        $statuses = collect($components)
            ->pluck('status')
            ->filter()
            ->values();

        $status = match (true) {
            $statuses->contains(self::STATUS_CRITICAL) => self::STATUS_CRITICAL,
            $statuses->contains(self::STATUS_WARNING) => self::STATUS_WARNING,
            $statuses->every(
                fn (string $value): bool => $value === self::STATUS_UNKNOWN
            ) => self::STATUS_UNKNOWN,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'Se detectaron uno o más problemas críticos.',
                self::STATUS_WARNING => 'El servidor funciona, pero requiere atención.',
                self::STATUS_UNKNOWN => 'No fue posible determinar el estado general.',
                default => 'El servidor funciona correctamente.',
            },
            'healthy_count' => $statuses
                ->filter(
                    fn (string $value): bool =>
                        $value === self::STATUS_HEALTHY
                )
                ->count(),
            'warning_count' => $statuses
                ->filter(
                    fn (string $value): bool =>
                        $value === self::STATUS_WARNING
                )
                ->count(),
            'critical_count' => $statuses
                ->filter(
                    fn (string $value): bool =>
                        $value === self::STATUS_CRITICAL
                )
                ->count(),
            'unknown_count' => $statuses
                ->filter(
                    fn (string $value): bool =>
                        $value === self::STATUS_UNKNOWN
                )
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function readMemInfo(): array
    {
        $contents = $this->safeReadFile('/proc/meminfo');

        if ($contents === null) {
            $contents = $this->runCommand(
                'cat /proc/meminfo 2>/dev/null'
            );
        }

        if ($contents === null || trim($contents) === '') {
            return [];
        }

        $result = [];

        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $line) {
            if (
                !preg_match(
                    '/^([A-Za-z_()]+):\s+(\d+)/',
                    $line,
                    $matches
                )
            ) {
                continue;
            }

            $result[$matches[1]] = (int) $matches[2];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function memoryFromFreeCommand(): ?array
    {
        $output = $this->runCommand(
            "free -b 2>/dev/null | awk '/^Mem:/ {print \$2, \$3, \$7}'"
        );

        if ($output === null || trim($output) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($output));

        if (
            !is_array($parts)
            || count($parts) < 3
            || !is_numeric($parts[0])
            || !is_numeric($parts[1])
            || !is_numeric($parts[2])
        ) {
            return null;
        }

        $total = (int) $parts[0];
        $used = (int) $parts[1];
        $available = (int) $parts[2];

        if ($total <= 0) {
            return null;
        }

        $usage = round(($used / $total) * 100, 2);

        $status = match (true) {
            $usage >= 92 => self::STATUS_CRITICAL,
            $usage >= 80 => self::STATUS_WARNING,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'La memoria RAM está prácticamente agotada.',
                self::STATUS_WARNING => 'El consumo de memoria RAM es elevado.',
                default => 'La memoria RAM está dentro de un rango saludable.',
            },
            'total_bytes' => $total,
            'used_bytes' => $used,
            'available_bytes' => $available,
            'usage_percent' => $usage,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function swapFromFreeCommand(): ?array
    {
        $output = $this->runCommand(
            "free -b 2>/dev/null | awk '/^Swap:/ {print \$2, \$3, \$4}'"
        );

        if ($output === null || trim($output) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($output));

        if (
            !is_array($parts)
            || count($parts) < 3
            || !is_numeric($parts[0])
            || !is_numeric($parts[1])
            || !is_numeric($parts[2])
        ) {
            return null;
        }

        $total = (int) $parts[0];
        $used = (int) $parts[1];
        $free = (int) $parts[2];

        if ($total <= 0) {
            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'El servidor no tiene swap configurada.',
                'enabled' => false,
                'total_bytes' => 0,
                'used_bytes' => 0,
                'free_bytes' => 0,
                'usage_percent' => 0,
            ];
        }

        $usage = round(($used / $total) * 100, 2);

        $status = match (true) {
            $usage >= 85 => self::STATUS_CRITICAL,
            $usage >= 60 => self::STATUS_WARNING,
            default => self::STATUS_HEALTHY,
        };

        return [
            'status' => $status,
            'message' => match ($status) {
                self::STATUS_CRITICAL => 'El uso de swap es crítico.',
                self::STATUS_WARNING => 'El servidor está utilizando una cantidad considerable de swap.',
                default => 'El uso de swap está dentro de un rango saludable.',
            },
            'enabled' => true,
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'usage_percent' => $usage,
        ];
    }

    private function cpuUsagePercent(): ?float
    {
        $first = $this->readCpuTimes();

        if ($first === null) {
            return $this->cpuUsageFromTopCommand();
        }

        usleep(200000);

        $second = $this->readCpuTimes();

        if ($second === null) {
            return $this->cpuUsageFromTopCommand();
        }

        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];

        if ($totalDelta <= 0) {
            return $this->cpuUsageFromTopCommand();
        }

        return round(
            max(
                0,
                min(
                    100,
                    (1 - ($idleDelta / $totalDelta)) * 100
                )
            ),
            2
        );
    }

    /**
     * @return array{total: int, idle: int}|null
     */
    private function readCpuTimes(): ?array
    {
        $contents = $this->safeReadFile('/proc/stat');

        if ($contents === null) {
            $contents = $this->runCommand(
                'head -n 1 /proc/stat 2>/dev/null'
            );
        }

        if ($contents === null) {
            return null;
        }

        $line = strtok($contents, "\n");

        if ($line === false || !str_starts_with(trim($line), 'cpu ')) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($line));

        if (!is_array($parts) || count($parts) < 5) {
            return null;
        }

        array_shift($parts);

        $times = array_map('intval', $parts);

        $idle = ($times[3] ?? 0) + ($times[4] ?? 0);

        return [
            'total' => array_sum($times),
            'idle' => $idle,
        ];
    }

    private function cpuUsageFromTopCommand(): ?float
    {
        $output = $this->runCommand(
            "top -bn1 2>/dev/null | awk '/Cpu\\(s\\)|%Cpu/ {for(i=1;i<=NF;i++) if(\$i ~ /id/) {gsub(/,/, \"\", \$(i-1)); print 100-\$(i-1); exit}}'"
        );

        if ($output === null || !is_numeric(trim($output))) {
            return null;
        }

        return round(
            max(0, min(100, (float) trim($output))),
            2
        );
    }

    private function logicalCpuCores(): ?int
    {
        $contents = $this->safeReadFile('/proc/cpuinfo');

        if ($contents !== null) {
            preg_match_all(
                '/^processor\s*:/m',
                $contents,
                $matches
            );

            $count = count($matches[0]);

            if ($count > 0) {
                return $count;
            }
        }

        $output = $this->runCommand('nproc 2>/dev/null');

        if ($output !== null && is_numeric(trim($output))) {
            return max(1, (int) trim($output));
        }

        $output = $this->runCommand(
            'getconf _NPROCESSORS_ONLN 2>/dev/null'
        );

        if ($output !== null && is_numeric(trim($output))) {
            return max(1, (int) trim($output));
        }

        return null;
    }

    private function cpuModel(): ?string
    {
        $contents = $this->safeReadFile('/proc/cpuinfo');

        if ($contents !== null) {
            if (
                preg_match(
                    '/^model name\s*:\s*(.+)$/m',
                    $contents,
                    $matches
                )
            ) {
                return trim($matches[1]);
            }

            if (
                preg_match(
                    '/^Hardware\s*:\s*(.+)$/m',
                    $contents,
                    $matches
                )
            ) {
                return trim($matches[1]);
            }
        }

        $output = $this->runCommand(
            "lscpu 2>/dev/null | awk -F: '/Model name/ {gsub(/^[ \\t]+/, \"\", \$2); print \$2; exit}'"
        );

        if ($output !== null && trim($output) !== '') {
            return trim($output);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readOsRelease(): array
    {
        $contents = $this->safeReadFile('/etc/os-release');

        if ($contents === null) {
            $contents = $this->runCommand(
                'cat /etc/os-release 2>/dev/null'
            );
        }

        if ($contents === null || trim($contents) === '') {
            return [];
        }

        $result = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $line) {
            if (
                str_starts_with(trim($line), '#')
                || !str_contains($line, '=')
            ) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $result[trim($key)] = trim(
                trim($value),
                "\"'"
            );
        }

        return $result;
    }

    private function uptimeSeconds(): ?int
    {
        $contents = $this->safeReadFile('/proc/uptime');

        if ($contents === null) {
            $contents = $this->runCommand(
                'cat /proc/uptime 2>/dev/null'
            );
        }

        if ($contents !== null) {
            $parts = preg_split('/\s+/', trim($contents));

            if (
                isset($parts[0])
                && is_numeric($parts[0])
            ) {
                return (int) floor((float) $parts[0]);
            }
        }

        $output = $this->runCommand(
            'cut -d. -f1 /proc/uptime 2>/dev/null'
        );

        if ($output !== null && is_numeric(trim($output))) {
            return (int) trim($output);
        }

        return null;
    }

    /**
     * @return array<int, float>|null
     */
    private function loadAverageFromCommand(): ?array
    {
        $output = $this->runCommand(
            "uptime 2>/dev/null | sed 's/.*load average[s]*: //'"
        );

        if ($output === null || trim($output) === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim($output));
        $parts = preg_split('/\s+/', $normalized);

        if (!is_array($parts) || count($parts) < 3) {
            return null;
        }

        $values = [];

        foreach (array_slice($parts, 0, 3) as $part) {
            $part = trim($part, " \t\n\r\0\x0B,");

            if (!is_numeric($part)) {
                return null;
            }

            $values[] = (float) $part;
        }

        return $values;
    }

    private function humanDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;

        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;

        $minutes = intdiv($seconds, 60);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days.' día'.($days === 1 ? '' : 's');
        }

        if ($hours > 0) {
            $parts[] = $hours.' hora'.($hours === 1 ? '' : 's');
        }

        if ($minutes > 0 || $parts === []) {
            $parts[] = $minutes.' minuto'.($minutes === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }

    /**
     * @return array<int, array{pid: int, command: string}>|null
     */
    private function processList(): ?array
    {
        $output = $this->runCommand(
            'ps -eo pid=,args= 2>/dev/null'
        );

        if ($output === null) {
            return null;
        }

        $result = [];
        $lines = preg_split('/\R/', $output) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (
                !preg_match(
                    '/^(\d+)\s+(.+)$/',
                    $line,
                    $matches
                )
            ) {
                continue;
            }

            $result[] = [
                'pid' => (int) $matches[1],
                'command' => trim($matches[2]),
            ];
        }

        return $result;
    }

    /**
     * Lee archivos del sistema sin permitir que open_basedir
     * provoque una excepción que detenga todo el endpoint.
     */
    private function safeReadFile(string $path): ?string
    {
        try {
            $contents = @file_get_contents($path);

            return $contents === false
                ? null
                : $contents;
        } catch (Throwable) {
            return null;
        }
    }

    private function runCommand(string $command): ?string
    {
        if (!$this->commandExecutionAvailable()) {
            return null;
        }

        try {
            $output = [];
            $exitCode = 1;

            @exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                return null;
            }

            return implode("\n", $output);
        } catch (Throwable) {
            return null;
        }
    }

    private function commandExecutionAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabledFunctions = array_filter(
            array_map(
                'trim',
                explode(
                    ',',
                    (string) ini_get('disable_functions')
                )
            )
        );

        return !in_array(
            'exec',
            $disabledFunctions,
            true
        );
    }
}