<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strict allowlist, queue mapping, target database/engine verification, and automation safety gates
 * for R4.2 selective historical job quarantine.
 *
 * Rejects arbitrary job classes, source/target queues, non-database queue drivers, non-InnoDB
 * MariaDB/MySQL tables, and active problematic automations in fail-closed mode.
 */
class QueueQuarantinePolicy
{
    public const ALLOWED_SOURCE_QUEUE = 'meli';

    public const TARGET_QUEUE_RESERVATIONS = 'quarantine_reservations_20261127';

    public const TARGET_QUEUE_BEAUTY = 'quarantine_beauty_20261127';

    /** @var array<string, array{source_queue: string, target_queue: string}> */
    public const MANDATORY_MAPPING = [
        QueuePayloadInspector::JOB_RESERVATIONS => [
            'source_queue' => self::ALLOWED_SOURCE_QUEUE,
            'target_queue' => self::TARGET_QUEUE_RESERVATIONS,
        ],
        QueuePayloadInspector::JOB_BEAUTY_PRICES => [
            'source_queue' => self::ALLOWED_SOURCE_QUEUE,
            'target_queue' => self::TARGET_QUEUE_BEAUTY,
        ],
    ];

    /**
     * Validates an authorized class and its mandatory source -> target queue mapping.
     * No wildcards, partial matches, or arbitrary queue names are permitted.
     *
     * @return array{job_class: string, source_queue: string, target_queue: string}
     */
    public function resolveAuthorizedMapping(
        string $rawJobClass,
        ?string $rawSourceQueue = null,
        ?string $rawTargetQueue = null,
    ): array {
        $jobClass = ltrim(trim($rawJobClass), '\\');
        if ($jobClass === '' || str_contains($jobClass, '*') || str_contains($jobClass, '?') || str_contains($jobClass, '%')) {
            throw new QueueRecoveryException(
                "Clase de job inválida o comodín prohibido en cuarentena: '{$rawJobClass}'.",
                'invalid_quarantine_job_class',
                ['requested_job' => $rawJobClass],
            );
        }

        if (! isset(self::MANDATORY_MAPPING[$jobClass])) {
            throw new QueueRecoveryException(
                "La clase '{$jobClass}' no está autorizada para cuarentena R4.2.",
                'invalid_quarantine_job_class',
                [
                    'requested_job' => $jobClass,
                    'allowed_jobs' => array_keys(self::MANDATORY_MAPPING),
                ],
            );
        }

        $expected = self::MANDATORY_MAPPING[$jobClass];
        $sourceQueue = $rawSourceQueue !== null && trim($rawSourceQueue) !== ''
            ? trim($rawSourceQueue)
            : $expected['source_queue'];
        $targetQueue = $rawTargetQueue !== null && trim($rawTargetQueue) !== ''
            ? trim($rawTargetQueue)
            : $expected['target_queue'];

        if ($sourceQueue !== $expected['source_queue']) {
            throw new QueueRecoveryException(
                "Cola de origen no autorizada para '{$jobClass}': '{$sourceQueue}' (obligatoria: '{$expected['source_queue']}').",
                'invalid_quarantine_queue',
                ['job_class' => $jobClass, 'source_queue' => $sourceQueue, 'expected_source_queue' => $expected['source_queue']],
            );
        }

        if ($targetQueue !== $expected['target_queue']) {
            throw new QueueRecoveryException(
                "Cola de destino no autorizada para '{$jobClass}': '{$targetQueue}' (obligatoria: '{$expected['target_queue']}').",
                'invalid_quarantine_queue',
                ['job_class' => $jobClass, 'target_queue' => $targetQueue, 'expected_target_queue' => $expected['target_queue']],
            );
        }

        return [
            'job_class' => $jobClass,
            'source_queue' => $sourceQueue,
            'target_queue' => $targetQueue,
        ];
    }

    /**
     * Resolves and strictly validates the database queue target, table columns, and InnoDB engine (MySQL/MariaDB).
     *
     * @return array{
     *     queue_connection: string,
     *     db_connection: string,
     *     driver: string,
     *     database: string,
     *     table: string,
     *     engine: ?string
     * }
     */
    public function resolveAndValidateQueueTarget(?string $queueConnectionName = null, bool $requireInnoDb = false): array
    {
        $queueConn = $queueConnectionName !== null && trim($queueConnectionName) !== ''
            ? trim($queueConnectionName)
            : (string) config('queue.default', 'database');

        $connConfig = config("queue.connections.{$queueConn}");
        if (! is_array($connConfig) || ($connConfig['driver'] ?? null) !== 'database') {
            throw new QueueRecoveryException(
                "La conexión de cola '{$queueConn}' no utiliza driver 'database'.",
                'database_target_mismatch',
                ['queue_connection' => $queueConn, 'driver' => is_array($connConfig) ? ($connConfig['driver'] ?? null) : null],
            );
        }

        $dbConnName = is_string($connConfig['connection'] ?? null) && trim($connConfig['connection']) !== ''
            ? trim($connConfig['connection'])
            : (string) config('database.default');
        $table = is_string($connConfig['table'] ?? null) && trim($connConfig['table']) !== ''
            ? trim($connConfig['table'])
            : 'jobs';

        if ($table !== 'jobs') {
            throw new QueueRecoveryException(
                "Tabla de colas no autorizada para cuarentena R4.2: '{$table}' (solo se permite 'jobs').",
                'database_target_mismatch',
                ['table' => $table],
            );
        }

        $connection = DB::connection($dbConnName);
        $database = (string) $connection->getDatabaseName();
        $driver = (string) $connection->getDriverName();

        $schema = Schema::connection($dbConnName);
        if (! $schema->hasTable($table)) {
            throw new QueueRecoveryException(
                "La tabla de colas '{$table}' no existe en la conexión '{$dbConnName}'.",
                'database_target_mismatch',
                ['db_connection' => $dbConnName, 'table' => $table],
            );
        }

        foreach (['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'] as $column) {
            if (! $schema->hasColumn($table, $column)) {
                throw new QueueRecoveryException(
                    "La tabla '{$table}' carece de la columna requerida '{$column}'.",
                    'database_target_mismatch',
                    ['table' => $table, 'missing_column' => $column],
                );
            }
        }

        $engine = null;
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $engine = $this->detectInnoDbEngine($connection, $database, $table);
            if ($requireInnoDb && strtoupper((string) $engine) !== 'INNODB') {
                throw new QueueRecoveryException(
                    "La tabla '{$database}.{$table}' utiliza motor '".($engine ?? 'UNKNOWN')."'; se requiere InnoDB para SELECT ... FOR UPDATE seguro.",
                    'non_innodb_table',
                    ['database' => $database, 'table' => $table, 'engine' => $engine],
                );
            }
        }

        return [
            'queue_connection' => $queueConn,
            'db_connection' => $dbConnName,
            'driver' => $driver,
            'database' => $database,
            'table' => $table,
            'engine' => $engine,
        ];
    }

    /**
     * Fails closed if either problematic automation (automatic ML reservations or Beauty scheduler) is enabled.
     */
    public function assertProblematicAutomationsDisabled(): void
    {
        $reservationsAuto = filter_var(
            config('inventory.meli_order_reservations.automatic', false),
            FILTER_VALIDATE_BOOLEAN,
        );
        $beautyScheduler = filter_var(
            config('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($reservationsAuto || $beautyScheduler) {
            throw new QueueRecoveryException(
                sprintf(
                    'Automatizaciones problemáticas aún habilitadas (INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC=%s, MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED=%s). Deshabilítelas antes de ejecutar --apply o --restore.',
                    $reservationsAuto ? 'true' : 'false',
                    $beautyScheduler ? 'true' : 'false',
                ),
                'automation_still_enabled',
                [
                    'reservations_automatic' => $reservationsAuto,
                    'beauty_scheduler_enabled' => $beautyScheduler,
                ],
            );
        }
    }

    private function detectInnoDbEngine(ConnectionInterface $connection, string $database, string $table): ?string
    {
        $row = $connection->selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        );

        return isset($row->ENGINE) ? (string) $row->ENGINE : null;
    }
}
