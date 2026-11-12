<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Guardia central para asegurar que la conexión dedicada 'autopartes' nunca apunte
 * a la base operativa 'api_llantas', no sea secuestrada por DB_URL, mantenga separación
 * de usuarios SQL/esquema físico en MariaDB y no ejecute inventario sobre default.
 */
final class AutopartesConnectionGuard
{
    public const CONNECTION_NAME = 'autopartes';

    public const PRODUCTION_DATABASE = 'api_llantas_autopartes';

    public const PILOT_DATABASE = 'api_llantas_autopartes_pilot';

    public const STAGING_DATABASE = 'api_llantas_autopartes_staging';

    public const STAGING_TEST_DATABASE = 'api_llantas_autopartes_staging_test';

    /** @var list<string> */
    private const FORBIDDEN_OPERATIONAL_DATABASES = [
        'api_llantas',
        'api_llantas_prod',
        'api_llantas_production',
        'laravel',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'phpmyadmin',
    ];

    /** @var list<string> */
    private const FORBIDDEN_LEGACY_OPERATIONAL_TABLES = [
        'inventory_products',
        'inventory_locations',
        'inventory_movements',
        'inventory_reservations',
        'inventory_channel_links',
        'pos_sales',
        'users',
    ];

    /** @var list<string> */
    private const ALLOWED_DRIVERS = [
        'mariadb',
        'mysql',
        'sqlite',
    ];

    public static function connectionName(): string
    {
        $configured = function_exists('app') && app()->bound('config')
            ? trim((string) config('autopartes.database_connection', self::CONNECTION_NAME))
            : self::CONNECTION_NAME;

        if ($configured !== self::CONNECTION_NAME) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó el acceso: autopartes.database_connection='{$configured}' debe ser 'autopartes'."
            );
        }

        return self::CONNECTION_NAME;
    }

    /**
     * Validate that the 'autopartes' connection is explicitly isolated from operational databases.
     *
     * @return array{connection: string, driver: string, host: string, port: string, database: string, username: string}
     */
    public static function assertDedicatedConnectionSafe(?string $connectionName = null): array
    {
        $name = trim((string) ($connectionName ?? self::connectionName()));
        if ($name !== self::CONNECTION_NAME) {
            throw new RuntimeException(
                "AutopartesConnectionGuard rechazó la conexión '{$name}': únicamente se permite 'autopartes'."
            );
        }

        $config = config("database.connections.{$name}");
        if (! is_array($config)) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó el acceso: la conexión 'database.connections.{$name}' no está configurada."
            );
        }

        $driver = strtolower(trim((string) ($config['driver'] ?? '')));
        if (! in_array($driver, self::ALLOWED_DRIVERS, true)) {
            throw new RuntimeException(
                "AutopartesConnectionGuard rechazó el driver '{$driver}' para la conexión 'autopartes'."
            );
        }

        $configUrl = trim((string) ($config['url'] ?? ''));
        $rawAutopartesUrl = trim((string) (self::readRawEnv('DB_AUTOPARTES_URL') ?? ''));
        if ($configUrl !== '' || $rawAutopartesUrl !== '') {
            throw new RuntimeException(
                'AutopartesConnectionGuard bloqueó el acceso: no se permite sobrescribir la conexión autopartes mediante url o DB_AUTOPARTES_URL.'
            );
        }

        $database = trim((string) ($config['database'] ?? ''));
        if ($database === '') {
            throw new RuntimeException(
                'AutopartesConnectionGuard bloqueó el acceso: DB_AUTOPARTES_DATABASE no puede estar vacía.'
            );
        }

        foreach (['DB_URL', 'DATABASE_URL'] as $globalUrlKey) {
            $globalUrl = trim((string) (self::readRawEnv($globalUrlKey) ?? ''));
            if ($globalUrl === '') {
                continue;
            }
            $globalUrlDb = self::extractDatabaseFromUrl($globalUrl);
            if (
                $globalUrlDb === ''
                || strcasecmp($globalUrlDb, $database) === 0
                || self::isForbiddenOperationalDatabase($globalUrlDb)
            ) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó el acceso: {$globalUrlKey} no puede anular ni mezclar la configuración de conexiones."
                );
            }
        }

        if (self::isForbiddenOperationalDatabase($database)) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó el acceso: la conexión 'autopartes' no puede apuntar a '{$database}'."
            );
        }

        $host = strtolower(trim((string) ($config['host'] ?? '')));
        $port = trim((string) ($config['port'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));

        // Prohibir explícitamente DB_CONNECTION=autopartes en el entorno operativo
        $rawEnvConnection = self::readRawEnv('DB_CONNECTION');
        if ($rawEnvConnection !== null && strtolower(trim($rawEnvConnection)) === self::CONNECTION_NAME) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó el acceso: DB_CONNECTION no puede ser 'autopartes'."
            );
        }

        // Prohibir database.default='autopartes' salvo cuando el migrador oficial de Laravel esté
        // ejecutando legítimamente migraciones dedicadas de Autopartes con --database=autopartes.
        $defaultName = (string) config('database.default', '');
        $isMigratorForAutopartes = self::isMigratorRunningForAutopartes();
        if ($defaultName === self::CONNECTION_NAME && ! $isMigratorForAutopartes) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó el acceso: database.default no puede ser 'autopartes'."
            );
        }

        $strictEnv = function_exists('app') && app()->bound('env') && app()->environment(['pilot', 'production']);

        if (in_array($driver, ['mariadb', 'mysql'], true) && $strictEnv) {
            if ($username === '') {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó el acceso: DB_AUTOPARTES_USERNAME obligatorio en entorno '".app()->environment()."'."
                );
            }
            if (strtolower($username) === 'root') {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó el acceso: el usuario SQL 'root' está prohibido para 'autopartes'."
                );
            }
        }

        // Validar de forma independiente contra las conexiones operativas para asegurar aislamiento estricto
        $operationalConnections = self::resolveOperationalConnectionsToCheck();
        foreach ($operationalConnections as $opName => $opConfig) {
            if (! is_array($opConfig)) {
                continue;
            }

            $opDriver = strtolower(trim((string) ($opConfig['driver'] ?? '')));
            $opDb = trim((string) ($opConfig['database'] ?? ''));
            $opHost = strtolower(trim((string) ($opConfig['host'] ?? '')));
            $opPort = trim((string) ($opConfig['port'] ?? ''));
            $opUser = trim((string) ($opConfig['username'] ?? ''));

            if (in_array($driver, ['mariadb', 'mysql'], true) && in_array($opDriver, ['mariadb', 'mysql'], true)) {
                $sameServer = ($host === $opHost || ($host === 'localhost' && $opHost === '127.0.0.1') || ($host === '127.0.0.1' && $opHost === 'localhost'))
                    && $port === $opPort;
                if ($sameServer && strcasecmp($database, $opDb) === 0) {
                    throw new RuntimeException(
                        "AutopartesConnectionGuard bloqueó el acceso: 'autopartes' comparte base de datos ('{$database}') con la conexión predeterminada '{$opName}'."
                    );
                }

                // Dos bases independientes pueden residir en el mismo servidor MariaDB si usan nombres distintos y usuarios SQL separados.
                if (
                    $sameServer
                    && $strictEnv
                    && $opUser !== ''
                    && $username !== ''
                    && strcasecmp($username, $opUser) === 0
                ) {
                    throw new RuntimeException(
                        "AutopartesConnectionGuard bloqueó el acceso: 'autopartes' no puede compartir el mismo usuario SQL ('{$username}') con '{$opName}' en el mismo servidor."
                    );
                }
            }

            if ($driver === 'sqlite' && $opDriver === 'sqlite' && $database !== ':memory:' && $opDb !== ':memory:' && strcasecmp($database, $opDb) === 0) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó el acceso: 'autopartes' no puede reutilizar el archivo SQLite operativo ('{$database}')."
                );
            }
        }

        return [
            'connection' => $name,
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
        ];
    }

    /**
     * Enforce connection guard prior to executing dedicated Autopartes schema migrations.
     *
     * @return array{connection: string, driver: string, host: string, port: string, database: string, username: string}
     */
    public static function assertSafeForSchemaChange(?string $connectionName = null): array
    {
        // 1. Bloquear si el migrador oficial está ejecutándose sin --database=autopartes para no alterar ni registrar en la base operativa
        if (self::isMigratorActive() && function_exists('app') && app()->bound('migrator')) {
            try {
                $migrator = app('migrator');
                $migratorConn = method_exists($migrator, 'getConnection')
                    ? (string) $migrator->getConnection()
                    : '';

                if ($migratorConn !== self::CONNECTION_NAME) {
                    $operationalTarget = self::resolveOperationalConnectionName();
                    throw new RuntimeException(
                        "AutopartesConnectionGuard bloqueó la migración: el migrador está operando sobre la conexión '{$operationalTarget}' en lugar de 'autopartes'. Las migraciones de Autopartes deben ejecutarse con --database=autopartes para no alterar el esquema ni registrar migraciones en la base operativa."
                    );
                }
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'AutopartesConnectionGuard')) {
                    throw $e;
                }
            }
        }

        $info = self::assertDedicatedConnectionSafe($connectionName);

        if (function_exists('app') && app()->bound('env')) {
            if (app()->environment('pilot') && $info['database'] !== self::PILOT_DATABASE) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard rechazó migración en piloto: se requiere '".self::PILOT_DATABASE."', recibido '{$info['database']}'."
                );
            }

            if (app()->environment('staging') && ! in_array($info['database'], [self::STAGING_DATABASE, self::STAGING_TEST_DATABASE], true)) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard rechazó migración en staging: base '{$info['database']}' no autorizada."
                );
            }

            if (app()->environment('production') && in_array($info['driver'], ['mariadb', 'mysql'], true) && $info['database'] !== self::PRODUCTION_DATABASE) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard rechazó migración en producción: se requiere '".self::PRODUCTION_DATABASE."', recibido '{$info['database']}'."
                );
            }
        }

        return $info;
    }

    /**
     * Enforce that Autopartes inventory transactions only execute on the dedicated 'autopartes' connection.
     *
     * @return array{connection: string, driver: string, host: string, port: string, database: string, username: string}
     */
    public static function assertAutopartesTransactionConnection(?ConnectionInterface $connection = null, bool $requireActiveTransaction = false): array
    {
        $info = self::assertDedicatedConnectionSafe(self::CONNECTION_NAME);
        $resolved = $connection ?? DB::connection(self::CONNECTION_NAME);
        $resolvedName = method_exists($resolved, 'getName') ? (string) $resolved->getName() : '';
        $operationalName = self::resolveOperationalConnectionName();

        if ($resolvedName !== self::CONNECTION_NAME || ($operationalName !== '' && $resolvedName === $operationalName)) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó transacción de inventario: se ejecutó sobre conexión '{$resolvedName}' en lugar de 'autopartes'."
            );
        }

        $resolvedDb = method_exists($resolved, 'getDatabaseName') ? trim((string) $resolved->getDatabaseName()) : '';
        if ($resolvedDb !== '' && $resolvedDb !== ':memory:' && self::isForbiddenOperationalDatabase($resolvedDb)) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó transacción de inventario: la base activa '{$resolvedDb}' está prohibida."
            );
        }

        if (in_array($info['driver'], ['mariadb', 'mysql'], true) && $resolvedDb !== '' && strcasecmp($resolvedDb, $info['database']) !== 0) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó transacción de inventario: discrepancia entre base activa '{$resolvedDb}' y '{$info['database']}'."
            );
        }

        if ($requireActiveTransaction && method_exists($resolved, 'transactionLevel') && $resolved->transactionLevel() < 1) {
            throw new RuntimeException(
                "AutopartesConnectionGuard bloqueó escritura de inventario: requiere transacción activa explícita sobre 'autopartes'."
            );
        }

        // En entornos reales (fuera de RefreshDatabase en unit tests), prohibir transacciones abiertas simultáneas en la conexión principal durante mutaciones de inventario.
        if (
            $requireActiveTransaction
            && (! function_exists('app') || ! app()->runningUnitTests())
            && $operationalName !== ''
            && $operationalName !== self::CONNECTION_NAME
        ) {
            $operationalConn = DB::connection($operationalName);
            if (method_exists($operationalConn, 'transactionLevel') && $operationalConn->transactionLevel() > 0) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó mutación de inventario: existe una transacción abierta sobre la conexión principal '{$operationalName}'."
                );
            }
        }

        return $info;
    }

    /**
     * Verify physical isolation beyond database names: live SELECT DATABASE(), legacy table absence,
     * and (in pilot or when requested) SQL user privilege restrictions via SHOW GRANTS.
     *
     * @param  (callable(): string)|null  $databaseProbe
     * @param  (callable(): list<string>)|null  $tablesProbe
     * @param  (callable(): list<string>)|null  $grantsProbe
     * @return array{database: string, tables_checked: int, grants_checked: bool}
     */
    public static function assertRuntimeIsolation(
        ?ConnectionInterface $connection = null,
        ?callable $databaseProbe = null,
        ?callable $tablesProbe = null,
        ?callable $grantsProbe = null,
        bool $verifyGrants = false,
    ): array {
        $info = self::assertDedicatedConnectionSafe(self::CONNECTION_NAME);
        $conn = $connection ?? DB::connection(self::CONNECTION_NAME);

        $liveDb = $databaseProbe !== null
            ? trim((string) $databaseProbe())
            : self::probeLiveDatabaseName($conn, $info['driver']);

        if ($liveDb !== '' && $liveDb !== ':memory:') {
            if (self::isForbiddenOperationalDatabase($liveDb)) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard rechazó aislamiento en vivo: la conexión autopartes apunta a '{$liveDb}'."
                );
            }

            if (in_array($info['driver'], ['mariadb', 'mysql'], true) && strcasecmp($liveDb, $info['database']) !== 0) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard rechazó aislamiento en vivo: DATABASE()='{$liveDb}' no coincide con '{$info['database']}'."
                );
            }
        }

        $tables = $tablesProbe !== null
            ? array_map(static fn ($name): string => strtolower(trim((string) $name)), $tablesProbe())
            : self::probeConnectionTables($conn, $info['driver'], $liveDb);

        foreach (self::FORBIDDEN_LEGACY_OPERATIONAL_TABLES as $forbiddenTable) {
            if (in_array($forbiddenTable, $tables, true)) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard detectó contaminación de esquema: la base de autopartes contiene la tabla operativa '{$forbiddenTable}'."
                );
            }
        }

        $shouldCheckGrants = $verifyGrants
            || (function_exists('app') && app()->bound('env') && app()->environment('pilot'));

        $grantsChecked = false;
        if ($shouldCheckGrants && in_array($info['driver'], ['mariadb', 'mysql'], true)) {
            $grants = $grantsProbe !== null
                ? $grantsProbe()
                : self::probeCurrentUserGrants($conn);

            self::assertGrantsIsolatedFromProduction($grants);
            $grantsChecked = true;
        }

        return [
            'database' => $liveDb,
            'tables_checked' => count($tables),
            'grants_checked' => $grantsChecked,
        ];
    }

    /**
     * Validate SHOW GRANTS lines for the autopartes SQL user so it holds zero production or global DB access.
     *
     * @param  list<string>  $grants
     */
    public static function assertGrantsIsolatedFromProduction(array $grants): void
    {
        if ($grants === []) {
            throw new RuntimeException(
                'AutopartesConnectionGuard rechazó privilegios SQL vacíos o no verificables para la conexión autopartes.'
            );
        }

        $operationalName = self::resolveOperationalConnectionName();
        $defaultDb = $operationalName !== self::CONNECTION_NAME
            ? trim((string) config("database.connections.{$operationalName}.database", ''))
            : '';

        foreach ($grants as $grantLine) {
            $line = trim((string) $grantLine);
            if ($line === '') {
                continue;
            }

            $upper = strtoupper($line);

            // MariaDB/MySQL siempre emite "GRANT USAGE ON *.* TO ..." cuando la cuenta no tiene permisos globales.
            if (preg_match('/\bON\s+\*\.\*/i', $line) === 1 && ! str_starts_with($upper, 'GRANT USAGE ON *.*')) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó el usuario SQL de autopartes: tiene privilegios globales prohibidos ({$line})."
                );
            }

            if (preg_match('/\bON\s+`?([a-z0-9_%\\\\-]+)`?\./i', $line, $matches) === 1) {
                $grantDb = strtolower(str_replace(['\_', '\%'], ['_', '%'], $matches[1]));
                $matchesDefault = $defaultDb !== ''
                    && $defaultDb !== ':memory:'
                    && strcasecmp($grantDb, $defaultDb) === 0;

                if (
                    self::isForbiddenOperationalDatabase($grantDb)
                    || in_array($grantDb, ['api_llantas_pilot', 'api_llantas_pilot_core'], true)
                    || str_contains($grantDb, '%')
                    || $matchesDefault
                ) {
                    throw new RuntimeException(
                        "AutopartesConnectionGuard bloqueó el usuario SQL de autopartes: tiene privilegios sobre esquema operativo o prohibido '{$grantDb}' ({$line})."
                    );
                }
            }
        }
    }

    public static function isForbiddenOperationalDatabase(string $database): bool
    {
        $normalized = strtolower(trim($database));
        if ($normalized === '') {
            return true;
        }

        if (in_array($normalized, self::FORBIDDEN_OPERATIONAL_DATABASES, true)) {
            return true;
        }

        if (str_starts_with($normalized, 'api_llantas_prod')) {
            return true;
        }

        if (str_contains(basename($normalized), 'database.sqlite')) {
            return true;
        }

        return false;
    }

    private static function probeLiveDatabaseName(ConnectionInterface $conn, string $driver): string
    {
        if ($driver === 'sqlite') {
            return method_exists($conn, 'getDatabaseName') ? trim((string) $conn->getDatabaseName()) : ':memory:';
        }

        $row = $conn->selectOne('SELECT DATABASE() AS db');

        return trim((string) ($row->db ?? ''));
    }

    /**
     * @return list<string>
     */
    private static function probeConnectionTables(ConnectionInterface $conn, string $driver, string $liveDb): array
    {
        try {
            if ($driver === 'sqlite') {
                $rows = $conn->select("SELECT name FROM sqlite_master WHERE type = 'table'");

                return array_values(array_map(static fn ($r): string => strtolower((string) $r->name), $rows));
            }

            if ($liveDb !== '') {
                $rows = $conn->select(
                    'SELECT table_name AS tbl FROM information_schema.tables WHERE table_schema = ?',
                    [$liveDb]
                );

                return array_values(array_map(static fn ($r): string => strtolower((string) $r->tbl), $rows));
            }
        } catch (Throwable) {
            return array_values(array_map(
                static fn (string $t): string => strtolower($t),
                Schema::connection(self::CONNECTION_NAME)->getTableListing()
            ));
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function probeCurrentUserGrants(ConnectionInterface $conn): array
    {
        $rows = $conn->select('SHOW GRANTS FOR CURRENT_USER()');
        $lines = [];

        foreach ($rows as $row) {
            $values = array_values((array) $row);
            if ($values !== []) {
                $lines[] = (string) $values[0];
            }
        }

        return $lines;
    }

    private static function extractDatabaseFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? ltrim(trim($path), '/') : '';
    }

    public static function isMigratorActive(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? '') === 'Illuminate\Database\Migrations\Migrator') {
                return true;
            }
        }

        return false;
    }

    public static function isMigratorRunningForAutopartes(): bool
    {
        if (! self::isMigratorActive()) {
            return false;
        }

        if (! function_exists('app') || ! app()->bound('migrator')) {
            return false;
        }

        try {
            $migrator = app('migrator');
            $migratorConn = method_exists($migrator, 'getConnection')
                ? (string) $migrator->getConnection()
                : '';

            return $migratorConn === self::CONNECTION_NAME;
        } catch (Throwable) {
            return false;
        }
    }

    public static function resolveOperationalConnectionName(): string
    {
        $rawEnv = self::readRawEnv('DB_CONNECTION');
        if ($rawEnv !== null && trim($rawEnv) !== '') {
            $normalized = strtolower(trim($rawEnv));
            if ($normalized === self::CONNECTION_NAME) {
                throw new RuntimeException(
                    "AutopartesConnectionGuard bloqueó el acceso: DB_CONNECTION no puede ser 'autopartes'."
                );
            }

            return $normalized;
        }

        $defaultName = strtolower(trim((string) config('database.default', '')));
        if ($defaultName !== '' && $defaultName !== self::CONNECTION_NAME) {
            return $defaultName;
        }

        if (self::isMigratorRunningForAutopartes()) {
            foreach (['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv'] as $candidate) {
                $cfg = config("database.connections.{$candidate}");
                if (is_array($cfg) && ! empty($cfg['driver'])) {
                    return $candidate;
                }
            }
        }

        return $defaultName;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function resolveOperationalConnectionsToCheck(): array
    {
        $connections = [];
        $primaryOpName = self::resolveOperationalConnectionName();

        if ($primaryOpName !== '' && $primaryOpName !== self::CONNECTION_NAME) {
            $primaryConfig = config("database.connections.{$primaryOpName}");
            if (is_array($primaryConfig)) {
                $connections[$primaryOpName] = $primaryConfig;
            }
        }

        foreach (['mysql', 'mariadb', 'sqlite'] as $candidate) {
            if ($candidate === $primaryOpName) {
                continue;
            }
            $candidateConfig = config("database.connections.{$candidate}");
            if (is_array($candidateConfig) && ! empty($candidateConfig['driver'])) {
                $connections[$candidate] = $candidateConfig;
            }
        }

        return $connections;
    }

    private static function readRawEnv(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null) {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null) {
            return (string) $_SERVER[$key];
        }

        $value = getenv($key);

        return $value === false ? null : (string) $value;
    }
}
