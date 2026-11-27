<?php

namespace App\Services;

use App\Models\InventoryMeliOrderReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exhaustive fail-closed runtime preflight verifying EVERY transactional table, required
 * schema column, critical index, InnoDB storage engine, strict atomic queue mode, and
 * temporal bound required for production Mercado Libre order reservations (H11 + H15).
 *
 * Alternative queue drivers (Redis, SQS, Beanstalkd, sync) are intentionally outside the
 * production incident scope because they cannot commit `jobs` atomically with InnoDB state.
 */
class InventoryMeliOrderReconciliationPreflight
{
    /**
     * Full set of tables read/locked/mutated across reserve, release, fulfill, kits & components,
     * physical movements, durable reconciliation state, and secondary stock-sync enqueue.
     *
     * @var list<string>
     */
    public const REQUIRED_TRANSACTIONAL_TABLES = [
        'inventory_meli_order_reconciliations',
        'jobs',
        'meli_orders',
        'meli_order_items',
        'inventory_channel_links',
        'inventory_channel_order_allocations',
        'inventory_products',
        'inventory_locations',
        'inventory_reservations',
        'inventory_kit_components',
        'inventory_kit_reservations',
        'inventory_movements',
        'inventory_channel_stock_syncs',
    ];

    /**
     * Critical columns required to detect schema drift before enabling automatic processing.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_SCHEMA_COLUMNS = [
        'inventory_meli_order_reconciliations' => [
            'meli_order_id',
            'state',
            'requested_revision',
            'processed_revision',
            'last_enqueued_revision',
            'fencing_token',
            'pending_job_id',
            'dispatch_confirmed_at',
            'stock_sync_required',
            'stock_sync_completed_revision',
            'last_state_fingerprint',
            'last_reconciled_fingerprint',
            'lease_owner',
            'lease_expires_at',
            'attempts',
            'last_error',
        ],
        'inventory_channel_order_allocations' => [
            'identity_hash',
            'status',
            'reservation_kind',
            'reservation_id',
            'reservation_version',
            'diagnostic_code',
        ],
        'inventory_channel_links' => [
            'inventory_product_id',
            'channel',
            'account_key',
            'external_listing_id',
            'external_variant_id',
            'remote_user_product_id',
            'is_active',
            'order_reservation_enabled',
            'stock_sync_enabled',
        ],
        'meli_order_items' => [
            'meli_order_id',
            'item_id',
            'variation_id',
            'remote_line_key',
            'quantity',
        ],
        'inventory_products' => [
            'product_type',
            'primary_location_id',
            'is_active',
        ],
        'inventory_reservations' => [
            'inventory_product_id',
            'quantity',
            'status',
            'external_key',
        ],
        'inventory_kit_reservations' => [
            'kit_product_id',
            'quantity',
            'status',
            'external_key',
        ],
        'inventory_movements' => [
            'inventory_product_id',
            'inventory_location_id',
            'type',
            'quantity',
            'external_key',
            'occurred_at',
        ],
    ];

    /**
     * Critical index names required on durable reconciliation and allocation tables.
     *
     * @var array<string, list<string>>
     */
    public const REQUIRED_INDEXES = [
        'inventory_meli_order_reconciliations' => [
            'inv_meli_order_rec_order_uq',
            'inv_meli_order_rec_state_lease_idx',
            'inv_meli_order_rec_outbox_idx',
            'inv_meli_order_rec_pending_job_idx',
        ],
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
        $defaultDbConnection = (string) config('database.default', '');
        $queueDbConnection = (string) (config("queue.connections.{$queueConnectionName}.connection") ?? $defaultDbConnection);
        $stateDbConnection = (string) ((new InventoryMeliOrderReconciliation)->getConnectionName() ?? $defaultDbConnection);
        $jobsTable = (string) config("queue.connections.{$queueConnectionName}.table", 'jobs');
        $retryAfter = (int) config("queue.connections.{$queueConnectionName}.retry_after", 90);
        $leaseSeconds = (int) config('inventory.meli_order_reservations.lease_seconds', InventoryMeliOrderReconciliationCoordinator::DEFAULT_LEASE_SECONDS);
        $jobTimeoutSeconds = (int) config('inventory.meli_order_reservations.job_timeout_seconds', 120);
        $strictAtomicQueue = (bool) config('inventory.meli_order_reservations.strict_atomic_queue', false);

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

        $sameConn = $queueDbConnection !== ''
            && $queueDbConnection === $stateDbConnection
            && $stateDbConnection === $defaultDbConnection;

        $checks[] = [
            'name' => 'same_transactional_db_connection',
            'passed' => $sameConn,
            'message' => $sameConn
                ? "Cola, estado durable y tablas de inventario comparten la conexión transaccional '{$stateDbConnection}'."
                : "Conexiones incompatibles: queue='{$queueDbConnection}', state='{$stateDbConnection}', default='{$defaultDbConnection}'.",
        ];

        $checks[] = [
            'name' => 'strict_atomic_queue_enabled',
            'passed' => $strictAtomicQueue,
            'message' => $strictAtomicQueue
                ? 'INVENTORY_MELI_ORDER_RESERVATIONS_STRICT_ATOMIC_QUEUE=true (enforcement fail-closed activo en runtime).'
                : 'INVENTORY_MELI_ORDER_RESERVATIONS_STRICT_ATOMIC_QUEUE=false; debe activarse en producción para impedir degradación silenciosa.',
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
                ? 'Todas las '.count($tablesToCheck).' tablas transaccionales requeridas existen.'
                : 'Faltan tablas transaccionales requeridas: '.implode(', ', $missingTables).'.',
            'details' => ['checked' => $tablesToCheck, 'missing' => $missingTables],
        ];

        // Schema drift verification across all critical tables.
        $missingColumns = [];
        foreach (self::REQUIRED_SCHEMA_COLUMNS as $table => $columns) {
            if (in_array($table, $missingTables, true)) {
                continue;
            }
            $existingCols = Schema::connection($stateDbConnection)->getColumnListing($table);
            $existingSet = array_flip(array_map('strtolower', $existingCols));
            foreach ($columns as $col) {
                if (! isset($existingSet[strtolower($col)])) {
                    $missingColumns[$table][] = $col;
                }
            }
        }

        $checks[] = [
            'name' => 'required_schema_columns_present',
            'passed' => $missingTables === [] && $missingColumns === [],
            'message' => ($missingTables === [] && $missingColumns === [])
                ? 'Todas las columnas críticas de esquema están presentes (sin schema drift).'
                : 'Schema drift detectado (columnas faltantes): '.json_encode($missingColumns, JSON_UNESCAPED_SLASHES),
            'details' => ['missing_columns' => $missingColumns],
        ];

        // Critical index verification (MariaDB/MySQL SHOW INDEX + SQLite PRAGMA index_list).
        $dbDriver = (string) config("database.connections.{$stateDbConnection}.driver", '');
        $missingIndexes = $this->findMissingCriticalIndexes($stateDbConnection, $dbDriver, $missingTables, $jobsTable);

        $checks[] = [
            'name' => 'critical_indexes_present',
            'passed' => $missingTables === [] && $missingIndexes === [],
            'message' => ($missingTables === [] && $missingIndexes === [])
                ? 'Todos los índices críticos están presentes en las tablas de reconciliación, asignaciones y cola.'
                : 'Faltan índices críticos: '.json_encode($missingIndexes, JSON_UNESCAPED_SLASHES),
            'details' => ['missing_indexes' => $missingIndexes],
        ];

        // Verify InnoDB storage engine for ALL 13 transactional tables on MariaDB / MySQL.
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
                    ? 'Todas las '.count($tablesToCheck).' tablas transaccionales utilizan motor InnoDB.'
                    : 'Tablas fuera de InnoDB (rompen atomicidad transaccional): '.json_encode($nonInnoDb, JSON_UNESCAPED_SLASHES),
                'details' => ['engines' => $enginesByTable, 'non_innodb' => $nonInnoDb],
            ];
        } else {
            $checks[] = [
                'name' => 'transactional_tables_use_innodb',
                'passed' => $dbDriver === 'sqlite' && $missingTables === [],
                'message' => $dbDriver === 'sqlite'
                    ? 'Entorno SQLite (pruebas); en producción MariaDB/MySQL se exige InnoDB en las '.count($tablesToCheck).' tablas.'
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
                'default_db_connection' => $defaultDbConnection,
                'db_driver' => $dbDriver,
                'after_commit' => $afterCommit,
                'jobs_table' => $jobsTable,
                'job_timeout_seconds' => $jobTimeoutSeconds,
                'lease_seconds' => $leaseSeconds,
                'retry_after' => $retryAfter,
                'reservations_automatic' => (bool) config('inventory.meli_order_reservations.automatic', false),
                'reservations_cutover' => config('inventory.meli_order_reservations.automatic_after'),
                'strict_atomic_queue' => $strictAtomicQueue,
                'tables_verified' => count($tablesToCheck),
            ],
        ];
    }

    /**
     * @param  list<string>  $missingTables
     * @return array<string, list<string>>
     */
    private function findMissingCriticalIndexes(
        string $connection,
        string $dbDriver,
        array $missingTables,
        string $jobsTable,
    ): array {
        $missing = [];
        $conn = DB::connection($connection);

        foreach (self::REQUIRED_INDEXES as $table => $expectedIndexes) {
            if (in_array($table, $missingTables, true)) {
                continue;
            }
            $existing = $this->listTableIndexNames($conn, $dbDriver, $table);
            foreach ($expectedIndexes as $idx) {
                if (! in_array($idx, $existing, true)) {
                    $missing[$table][] = $idx;
                }
            }
        }

        // Verify unique index exists on inventory_channel_order_allocations.identity_hash.
        if (! in_array('inventory_channel_order_allocations', $missingTables, true)) {
            if (! $this->hasUniqueIndexOnColumn($conn, $dbDriver, 'inventory_channel_order_allocations', 'identity_hash')) {
                $missing['inventory_channel_order_allocations'][] = 'unique(identity_hash)';
            }
        }

        // Verify queue index exists on jobs.queue.
        if (! in_array($jobsTable, $missingTables, true)) {
            if (! $this->hasAnyIndexOnColumn($conn, $dbDriver, $jobsTable, 'queue')) {
                $missing[$jobsTable][] = 'index(queue)';
            }
        }

        return $missing;
    }

    /** @return list<string> */
    private function listTableIndexNames(\Illuminate\Database\Connection $conn, string $dbDriver, string $table): array
    {
        if (in_array($dbDriver, ['mysql', 'mariadb'], true)) {
            return collect($conn->select("SHOW INDEX FROM `{$table}`"))
                ->pluck('Key_name')
                ->map(static fn ($v): string => (string) $v)
                ->unique()
                ->values()
                ->all();
        }

        if ($dbDriver === 'sqlite') {
            return collect($conn->select("PRAGMA index_list('{$table}')"))
                ->pluck('name')
                ->map(static fn ($v): string => (string) $v)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    private function hasUniqueIndexOnColumn(\Illuminate\Database\Connection $conn, string $dbDriver, string $table, string $column): bool
    {
        if (in_array($dbDriver, ['mysql', 'mariadb'], true)) {
            $rows = $conn->select("SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Non_unique = 0", [$column]);

            return count($rows) > 0;
        }

        if ($dbDriver === 'sqlite') {
            foreach ($conn->select("PRAGMA index_list('{$table}')") as $idx) {
                if ((int) ($idx->unique ?? 0) !== 1) {
                    continue;
                }
                $info = $conn->select("PRAGMA index_info('{$idx->name}')");
                if (count($info) === 1 && (string) ($info[0]->name ?? '') === $column) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasAnyIndexOnColumn(\Illuminate\Database\Connection $conn, string $dbDriver, string $table, string $column): bool
    {
        if (in_array($dbDriver, ['mysql', 'mariadb'], true)) {
            $rows = $conn->select("SHOW INDEX FROM `{$table}` WHERE Column_name = ?", [$column]);

            return count($rows) > 0;
        }

        if ($dbDriver === 'sqlite') {
            foreach ($conn->select("PRAGMA index_list('{$table}')") as $idx) {
                $info = $conn->select("PRAGMA index_info('{$idx->name}')");
                foreach ($info as $col) {
                    if ((string) ($col->name ?? '') === $column) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
