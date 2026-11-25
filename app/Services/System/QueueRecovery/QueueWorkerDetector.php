<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

class QueueWorkerDetector
{
    /** @var (callable(): list<array{pid: int, command: string}>)|null */
    private $processListResolver;

    /** @param (callable(): list<array{pid: int, command: string}>)|null $processListResolver */
    public function __construct(?callable $processListResolver = null)
    {
        $this->processListResolver = $processListResolver;
    }

    /**
     * Ensures no active queue worker can consume jobs from the target queues.
     *
     * @param  list<string>  $targetQueues
     */
    public function assertNoActiveTargetWorkers(array $targetQueues): void
    {
        $detected = $this->detectTargetWorkers($targetQueues);

        if ($detected !== []) {
            throw new QueueRecoveryException(
                sprintf(
                    'Se detectaron %d worker(s) activos que podrían consumir las colas objetivo (%s). Detenga los workers antes de ejecutar --apply.',
                    count($detected),
                    implode(', ', $targetQueues),
                ),
                'active_worker_detected',
                [
                    'target_queues' => $targetQueues,
                    'detected_pids' => array_column($detected, 'pid'),
                ],
            );
        }
    }

    /**
     * @param  list<string>  $targetQueues
     * @return list<array{pid: int, command: string}>
     */
    public function detectTargetWorkers(array $targetQueues): array
    {
        $processes = $this->processList();
        $selfPid = function_exists('getmypid') ? (int) getmypid() : -1;
        $normalizedTargets = array_values(array_unique(array_map(
            static fn (string $q): string => strtolower(trim($q)),
            $targetQueues,
        )));

        $matches = [];

        foreach ($processes as $process) {
            $pid = (int) ($process['pid'] ?? 0);
            $command = (string) ($process['command'] ?? '');

            if ($pid === $selfPid || $command === '') {
                continue;
            }

            if (! $this->isQueueWorkerCommand($command)) {
                continue;
            }

            if ($this->workerCouldConsumeQueues($command, $normalizedTargets)) {
                $matches[] = [
                    'pid' => $pid,
                    'command' => $this->sanitizeCommand($command),
                ];
            }
        }

        return $matches;
    }

    private function isQueueWorkerCommand(string $command): bool
    {
        return (bool) preg_match('/\bartisan\s+(?:queue:work|queue:listen|horizon(?::work)?)\b/i', $command);
    }

    /** @param list<string> $targetQueues */
    private function workerCouldConsumeQueues(string $command, array $targetQueues): bool
    {
        if (preg_match('/\bartisan\s+horizon\b/i', $command) === 1) {
            return true;
        }

        // Extract --queue=... or --queue ...
        if (preg_match('/--queue(?:=|\s+)([^\s]+)/i', $command, $matches) === 1) {
            $rawQueues = trim($matches[1], "\"'");
            $workerQueues = array_values(array_filter(array_map(
                static fn (string $q): string => strtolower(trim($q)),
                explode(',', $rawQueues),
            )));

            return array_intersect($workerQueues, $targetQueues) !== [];
        }

        // A worker without --queue consumes the default queue ('default').
        return in_array('default', $targetQueues, true);
    }

    /** @return list<array{pid: int, command: string}> */
    private function processList(): array
    {
        if ($this->processListResolver !== null) {
            return ($this->processListResolver)();
        }

        if (is_dir('/proc') && is_readable('/proc')) {
            return $this->readProcFilesystem();
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }

        return $this->readPsCommand();
    }

    /** @return list<array{pid: int, command: string}> */
    private function readProcFilesystem(): array
    {
        $entries = @scandir('/proc');
        if (! is_array($entries)) {
            return [];
        }

        $result = [];
        foreach ($entries as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $cmdlinePath = "/proc/{$entry}/cmdline";
            if (! is_readable($cmdlinePath)) {
                continue;
            }

            $raw = @file_get_contents($cmdlinePath);
            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $command = trim(str_replace("\0", ' ', $raw));
            if ($command !== '') {
                $result[] = ['pid' => (int) $entry, 'command' => $command];
            }
        }

        return $result;
    }

    /** @return list<array{pid: int, command: string}> */
    private function readPsCommand(): array
    {
        if (! function_exists('exec')) {
            return [];
        }

        $lines = [];
        $code = 1;
        @exec('ps -eo pid=,args= 2>/dev/null', $lines, $code);

        if ($code !== 0) {
            return [];
        }

        $result = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if (preg_match('/^(\d+)\s+(.+)$/', $trimmed, $m) === 1) {
                $result[] = ['pid' => (int) $m[1], 'command' => $m[2]];
            }
        }

        return $result;
    }

    private function sanitizeCommand(string $command): string
    {
        $sanitized = preg_replace(
            '/\b(password|pass|token|secret|key)\s*[:=]\s*[^\s]+/i',
            '$1=[REDACTED]',
            $command,
        );

        return mb_substr((string) ($sanitized ?? $command), 0, 240);
    }
}
