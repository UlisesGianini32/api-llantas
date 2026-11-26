<?php

namespace App\Services;

use App\Models\InventoryMeliOrderReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-closed runtime preflight verifying every database/queue invariant required for
 * atomic same-DB reservation reconciliation in production (H11).
 *
 * Alternative queue drivers (Redis, SQS, Beanstalkd, sync) are intentionally outside the
 * production incident scope because they cannot commit `jobs` atomically with InnoDB state.
 */
class InventoryMeliOrderReconciliationPreflight
{
    /** @var list<string> */
    public const REQUIRED_TRANSACTIONAL_TABLES = [
        'inventory_meli_order_reconciliations',
        'jobs',
        'meli_orders',
        'meli_order_items',
        'inventory_channel_order_allocations',
        'inventory_reservations',
    ];

    /**
     * @return array{
     *     passed: bool,
     *     checks: list<array{name: string, passed: bool, message: string, details?: array<string, mixed>}>,
     *     runtime: array<string, mixed>
     * }
     */
    public function check(): array
    {
        $checks = [];

        $queueConnectionName = (string) config('queue.default', 'sync');
        $queueDriver = (string) config("queue.connections.{$queueConnectionName}.driver", '');
        $afterCommit = (bool) config("queue.connections.{$queueConnectionName}.after_commit", false);
        $queueDbConnection = (string) (config("queue.connections.{$queueConnectionName}.connection") ?? config('database.default', ''));
        $stateDbConnection = (string) ((new InventoryMeliOrderReconciliation)->getConnectionName() ?? config('database.default', ''));
        $jobsTable = (string) config("queue.connections.{$queueConnectionName}.table", 'jobs');
        $retryAfter = (int) config("queue.connections.{$queueConnectionName}.retry_after", 90);
        $leaseSeconds = (int) config('inventory.meli_order_reservations.lease_seconds', InventoryMeliOrderReconciliationCoordinator::DEFAULT_LEASE_SECONDS);
        $jobTimeoutSeconds = (int) config('inventory.meli_order_reservations.job_timeout_seconds', 120);

        $checks[] = [
            'name' => 'queue_driver_is_database',
            'passed' => $queueDriver === 'database',
            'message' => $queueDriver === 'database'
                ? "queue.default='{$queueConnectionName}' usa driver 'database'."
                : "queue.default='{$queueConnectionName}' usa driver '{$queueDriver}'; solo 'database' está soportado con garantía atómica en producción.",
        ];

        $checks[] = [
            'name' => 'queue_after_commit_is_false',
            'passed' => ! $afterCommit,
            'message' => ! $afterCommit
                ? "queue.connections.{$queueConnectionName}.after_commit=false (permite inserción atómica dentro de TX)."
                : "queue.connections.{$queueConnectionName}.after_commit=true rompe la inserción atómica dentro de DB::transaction().",
        ];

        $checks[] = [
            'name' => 'same_transactional_db_connection',
            'passed' => $queueDbConnection !== '' && $queueDbConnection === $stateDbConnection,
            'message' => ($queueDbConnection !== '' && $queueDbConnection === $stateDbConnection)
                ? "La cola y inventory_meli_order_reconciliations comparten la conexión '{$stateDbConnection}'."
                : "Conexión de cola ('{$queueDbConnection}') difiere de la conexión de estado ('{$stateDbConnection}').",
        ];

        $tablesToCheck = array_values(array_unique(array_map(
            static fn (string $t): string => $t === 'jobs' ? $jobsTable : $t,
            self::REQUIRED_TRANSACTIONAL_TABLES,
        )));

        $missingTables = [];
        foreach ($tablesToCheck as $table) {
            if (! Schema::connection($stateDbConnection)->hasTable($table)) {
                $missingTables[] = $table;
            }
        }

        $checks[] = [
            'name' => 'required_tables_exist',
            'passed' => $missingTables === [],
            'message' => $missingTables === []
                ? 'Todas las tablas transaccionales requeridas existen.'
                : 'Faltan tablas requeridas: '.implode(', ', $missingTables).'.',
            'details' => ['missing' => $missingTables],
        ];

        // Verify InnoDB storage engine on MariaDB / MySQL connections.
        $dbDriver = (string) config("database.connections.{$stateDbConnection}.driver", '');
        if (in_array($dbDriver, ['mysql', 'mariadb'], true) && $missingTables === []) {
            $nonInnoDb = [];
            $dbName = (string) config("database.connections.{$stateDbConnection}.database", '');
            $rows = DB::connection($stateDbConnection)->select(
                'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.implode(',', array_fill(0, count($tablesToCheck), '?')).')',
                [$dbName, ...$tablesToCheck],
            );

            $enginesByTable = [];
            foreach ($rows as $row) {
                $enginesByTable[(string) $row->TABLE_NAME] = (string) $row->ENGINE;
            }

            foreach ($tablesToCheck as $table) {
                $engine = $enginesByTable[$table] ?? 'UNKNOWN';
                if (strcasecmp($engine, 'InnoDB') !== 0) {
                    $nonInnoDb[$table] = $engine;
                }
            }

            $checks[] = [
                'name' => 'transactional_tables_use_innodb',
                'passed' => $nonInnoDb === [],
                'message' => $nonInnoDb === []
                    ? 'Todas las tablas requeridas utilizan motor InnoDB.'
                    : 'Tablas fuera de InnoDB (rompen atomicidad transaccional): '.json_encode($nonInnoDb, JSON_UNESCAPED_SLASHES),
                'details' => ['engines' => $enginesByTable, 'non_innodb' => $nonInnoDb],
            ];
        } else {
            $checks[] = [
                'name' => 'transactional_tables_use_innodb',
                'passed' => $dbDriver === 'sqlite' && $missingTables === [],
                'message' => $dbDriver === 'sqlite'
                    ? 'Entorno SQLite (pruebas); en producción MariaDB/MySQL se exige InnoDB.'
                    : "No se pudo verificar motor InnoDB (driver='{$dbDriver}', tablas faltantes=".count($missingTables).').',
            ];
        }

        $temporalOrderOk = $jobTimeoutSeconds > 0
            && $leaseSeconds > $jobTimeoutSeconds
            && $retryAfter > $leaseSeconds;

        $checks[] = [
            'name' => 'temporal_bounds_timeout_lt_lease_lt_retry_after',
            'passed' => $temporalOrderOk,
            'message' => $temporalOrderOk
                ? "Orden temporal seguro verificado: timeout({$jobTimeoutSeconds}s) < lease({$leaseSeconds}s) < retry_after({$retryAfter}s)."
                : "Orden temporal inseguro: se requiere timeout({$jobTimeoutSeconds}s) < lease({$leaseSeconds}s) < retry_after({$retryAfter}s).",
        ];

        $passed = true;
        foreach ($checks as $check) {
            if (! $check['passed']) {
                $passed = false;
                break;
            }
        }

        return [
            'passed' => $passed,
            'checks' => $checks,
            'runtime' => [
                'queue_connection' => $queueConnectionName,
                'queue_driver' => $queueDriver,
                'queue_db_connection' => $queueDbConnection,
                'state_db_connection' => $stateDbConnection,
                'db_driver' => $dbDriver,
                'after_commit' => $afterCommit,
                'jobs_table' => $jobsTable,
                'job_timeout_seconds' => $jobTimeoutSeconds,
                'lease_seconds' => $leaseSeconds,
                'retry_after' => $retryAfter,
                'reservations_automatic' => (bool) config('inventory.meli_order_reservations.automatic', false),
                'reservations_cutover' => config('inventory.meli_order_reservations.automatic_after'),
                'strict_atomic_queue' => (bool) config('inventory.meli_order_reservations.strict_atomic_queue', false),
            ],
        ];
    }
}
