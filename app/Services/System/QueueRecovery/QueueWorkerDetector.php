<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Carbon\CarbonImmutable;
use JsonException;

/**
 * Fail-closed detector for active queue workers, Horizon, and scheduled dispatchers
 * plus operational maintenance-gate sentinel validation.
 *
 * Never modifies or stops Supervisor automatically.
 */
class QueueWorkerDetector
{
    /**
     * @var (callable(): array{
     *     processes: list<array{pid: int, command: string}>,
     *     unreadable_count: int,
     *     inspection_available: bool
     * })|null
     */
    private $snapshotResolver;

    /**
     * @param  (callable(): (array<string, mixed>|list<array{pid: int, command: string}>))|null  $snapshotResolver
     */
    public function __construct(
        ?callable $snapshotResolver = null,
        private readonly QueueRecoveryPathGuard $pathGuard = new QueueRecoveryPathGuard,
    ) {
        $this->snapshotResolver = $snapshotResolver;
    }

    /**
     * Fails closed if:
     * - Process inspection cannot be performed reliably (permissions/namespaces/unreadable cmdlines), OR
     * - Any active queue worker (Supervisor, manual, Horizon) or conflicting cron/dispatcher is detected.
     *
     * @param  list<string>  $targetQueues
     */
    public function assertNoActiveTargetWorkers(array $targetQueues, bool $strictVisibility = true): void
    {
        $snapshot = $this->collectProcessSnapshot();

        if (! $snapshot['inspection_available']) {
            throw new QueueRecoveryException(
                'Inspección de procesos no disponible en este entorno; abortando en modo fail-closed porque no puede garantizarse ausencia de workers activos.',
                'worker_inspection_unavailable',
            );
        }

        if ($strictVisibility && $snapshot['unreadable_count'] > 0) {
            throw new QueueRecoveryException(
                sprintf(
                    'Visibilidad insuficiente de procesos: %d proceso(s) tienen /proc/<pid>/cmdline ilegible por permisos o aislamiento de namespaces. Ejecute con permisos de inspección adecuados o confirme exclusión operativa.',
                    $snapshot['unreadable_count'],
                ),
                'worker_inspection_insufficient_permissions',
                ['unreadable_count' => $snapshot['unreadable_count']],
            );
        }

        $detected = $this->filterConflictingProcesses($snapshot['processes'], $targetQueues);

        if ($detected !== []) {
            throw new QueueRecoveryException(
                sprintf(
                    'Se detectaron %d proceso(s) activos incompatibles con la ventana de poda (%s). Detenga workers/cron antes de continuar.',
                    count($detected),
                    implode(', ', array_unique(array_column($detected, 'kind'))),
                ),
                'active_worker_detected',
                [
                    'target_queues' => $targetQueues,
                    'detected_pids' => array_column($detected, 'pid'),
                    'detected_kinds' => array_values(array_unique(array_column($detected, 'kind'))),
                ],
            );
        }
    }

    /**
     * Fail-closed operational maintenance-window sentinel check.
     *
     * Operators create an external JSON sentinel file before maintenance and remove it afterward.
     * Supervisor/worker wrappers can also refuse to start while this sentinel exists.
     * Re-checked before EVERY micro-batch: if deleted, expired, tampered, or mismatched -> ABORT!
     *
     * @return array{window_id: string, incident_id: string, expires_at: string, approved_by: ?string}
     */
    public function assertMaintenanceGateActive(
        string $gateFilePath,
        string $expectedWindowId,
        string $expectedIncidentId,
    ): array {
        $safePath = $this->pathGuard->assertSafeInputPath($gateFilePath);

        if (! is_file($safePath) || ! is_readable($safePath)) {
            throw new QueueRecoveryException(
                "Barrera de ventana de mantenimiento ausente o ilegible: '{$safePath}'. No se permite ejecutar --apply sin sentinel activo.",
                'maintenance_gate_missing',
                ['path' => $safePath],
            );
        }

        $raw = @file_get_contents($safePath);
        if (! is_string($raw) || trim($raw) === '') {
            throw new QueueRecoveryException(
                "Archivo sentinel de mantenimiento vacío: '{$safePath}'.",
                'maintenance_gate_invalid',
                ['path' => $safePath],
            );
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new QueueRecoveryException(
                "JSON inválido en archivo sentinel de mantenimiento: '{$safePath}'.",
                'maintenance_gate_invalid',
                ['path' => $safePath],
                $e,
            );
        }

        if (! is_array($data)) {
            throw new QueueRecoveryException('El sentinel de mantenimiento debe ser un objeto JSON.', 'maintenance_gate_invalid');
        }

        $windowId = trim((string) ($data['window_id'] ?? ''));
        $incidentId = trim((string) ($data['incident_id'] ?? ''));
        $expiresAtRaw = trim((string) ($data['expires_at'] ?? ''));
        $workersHaltedConfirmed = (bool) ($data['workers_halted_confirmed'] ?? false);

        if ($windowId === '' || $windowId !== trim($expectedWindowId)) {
            throw new QueueRecoveryException(
                "El sentinel de mantenimiento tiene window_id='{$windowId}', no coincide con --maintenance-window='{$expectedWindowId}'.",
                'maintenance_gate_mismatch',
            );
        }

        if ($incidentId === '' || $incidentId !== trim($expectedIncidentId)) {
            throw new QueueRecoveryException(
                "El sentinel de mantenimiento tiene incident_id='{$incidentId}', no coincide con el manifiesto ('{$expectedIncidentId}').",
                'maintenance_gate_mismatch',
            );
        }

        if (! $workersHaltedConfirmed) {
            throw new QueueRecoveryException(
                'El sentinel de mantenimiento no incluye confirmación explícita workers_halted_confirmed=true.',
                'maintenance_gate_invalid',
            );
        }

        if ($expiresAtRaw === '') {
            throw new QueueRecoveryException(
                'El sentinel de mantenimiento debe incluir una fecha de expiración expires_at en formato ISO-8601.',
                'maintenance_gate_invalid',
            );
        }

        try {
            $expiresAt = CarbonImmutable::parse($expiresAtRaw);
        } catch (\Throwable $e) {
            throw new QueueRecoveryException(
                "Fecha expires_at inválida en sentinel de mantenimiento: '{$expiresAtRaw}'.",
                'maintenance_gate_invalid',
                [],
                $e,
            );
        }

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new QueueRecoveryException(
                "La ventana de mantenimiento expiró en {$expiresAt->toIso8601String()}. Abortando en modo fail-closed.",
                'maintenance_gate_expired',
                ['expires_at' => $expiresAt->toIso8601String()],
            );
        }

        return [
            'window_id' => $windowId,
            'incident_id' => $incidentId,
            'expires_at' => $expiresAt->toIso8601String(),
            'approved_by' => is_string($data['approved_by'] ?? null) ? trim((string) $data['approved_by']) : null,
        ];
    }

    /**
     * @param  list<array{pid: int, command: string}>  $processes
     * @param  list<string>  $targetQueues
     * @return list<array{pid: int, kind: string, command: string}>
     */
    private function filterConflictingProcesses(array $processes, array $targetQueues): array
    {
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

            $kind = $this->classifyConflictingCommand($command, $normalizedTargets);
            if ($kind !== null) {
                $matches[] = [
                    'pid' => $pid,
                    'kind' => $kind,
                    'command' => $this->sanitizeCommand($command),
                ];
            }
        }

        return $matches;
    }

    /** @param list<string> $targetQueues */
    private function classifyConflictingCommand(string $command, array $targetQueues): ?string
    {
        if (preg_match('/\bartisan\s+horizon(?::work)?\b/i', $command) === 1) {
            return 'horizon';
        }

        if (preg_match('/\bartisan\s+(?:queue:work|queue:listen)\b/i', $command) === 1) {
            if ($this->workerCouldConsumeQueues($command, $targetQueues)) {
                return 'queue_worker';
            }

            return null;
        }

        // Detect scheduler / cron processes that dispatch reservations or Beauty jobs.
        if (preg_match('/\bartisan\s+(?:schedule:run|schedule:work|meli:beauty-scheduled-prices)\b/i', $command) === 1) {
            return 'scheduler_or_dispatcher';
        }

        return null;
    }

    /** @param list<string> $targetQueues */
    private function workerCouldConsumeQueues(string $command, array $targetQueues): bool
    {
        if (preg_match('/--queue(?:=|\s+)([^\s]+)/i', $command, $matches) === 1) {
            $rawQueues = trim($matches[1], "\"'");
            $workerQueues = array_values(array_filter(array_map(
                static fn (string $q): string => strtolower(trim($q)),
                explode(',', $rawQueues),
            )));

            return array_intersect($workerQueues, $targetQueues) !== [];
        }

        // Worker without --queue consumes the default queue ('default').
        return in_array('default', $targetQueues, true);
    }

    /**
     * @return array{
     *     processes: list<array{pid: int, command: string}>,
     *     unreadable_count: int,
     *     inspection_available: bool
     * }
     */
    private function collectProcessSnapshot(): array
    {
        if ($this->snapshotResolver !== null) {
            $resolved = ($this->snapshotResolver)();
            if (is_array($resolved) && array_key_exists('processes', $resolved)) {
                return [
                    'processes' => (array) $resolved['processes'],
                    'unreadable_count' => (int) ($resolved['unreadable_count'] ?? 0),
                    'inspection_available' => (bool) ($resolved['inspection_available'] ?? true),
                ];
            }

            return [
                'processes' => is_array($resolved) ? array_values($resolved) : [],
                'unreadable_count' => 0,
                'inspection_available' => true,
            ];
        }

        if (is_dir('/proc') && is_readable('/proc')) {
            return $this->readProcFilesystem();
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->readWindowsProcesses();
        }

        return $this->readPsCommand();
    }

    /**
     * @return array{processes: list<array{pid: int, command: string}>, unreadable_count: int, inspection_available: bool}
     */
    private function readProcFilesystem(): array
    {
        $entries = @scandir('/proc');
        if (! is_array($entries)) {
            return ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => false];
        }

        $result = [];
        $unreadable = 0;

        foreach ($entries as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $cmdlinePath = "/proc/{$entry}/cmdline";
            if (! file_exists($cmdlinePath)) {
                continue;
            }

            if (! is_readable($cmdlinePath)) {
                $unreadable++;

                continue;
            }

            $raw = @file_get_contents($cmdlinePath);
            if ($raw === false) {
                $unreadable++;

                continue;
            }

            $command = trim(str_replace("\0", ' ', $raw));
            if ($command !== '') {
                $result[] = ['pid' => (int) $entry, 'command' => $command];
            }
        }

        return [
            'processes' => $result,
            'unreadable_count' => $unreadable,
            'inspection_available' => true,
        ];
    }

    /**
     * @return array{processes: list<array{pid: int, command: string}>, unreadable_count: int, inspection_available: bool}
     */
    private function readPsCommand(): array
    {
        if (! function_exists('exec')) {
            return ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => false];
        }

        $lines = [];
        $code = 1;
        @exec('ps -eo pid=,args= 2>/dev/null', $lines, $code);

        if ($code !== 0) {
            return ['processes' => [], 'unreadable_count' => 0, 'inspection_available' => false];
        }

        $result = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if (preg_match('/^(\d+)\s+(.+)$/', $trimmed, $m) === 1) {
                $result[] = ['pid' => (int) $m[1], 'command' => $m[2]];
            }
        }

        return [
            'processes' => $result,
            'unreadable_count' => 0,
            'inspection_available' => true,
        ];
    }

    /**
     * @return array{processes: list<array{pid: int, command: string}>, unreadable_count: int, inspection_available: bool}
     */
    private function readWindowsProcesses(): array
    {
        return [
            'processes' => [],
            'unreadable_count' => 0,
            'inspection_available' => false,
        ];
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
