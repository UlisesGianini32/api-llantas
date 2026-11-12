<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Guardia de aislamiento obligatorio para el piloto interactivo de Autopartes.
 * Valida entorno, driver, host/puerto, credenciales externas y candados comerciales
 * antes de abrir conexión y luego confirma la identidad real de la BD en MariaDB.
 */
final class PilotDatabaseGuard
{
    public const PILOT_ENV = 'pilot';

    public const PILOT_CORE_DATABASE = 'api_llantas_pilot';

    public const PILOT_DATABASE = 'api_llantas_autopartes_pilot';

    public const PILOT_HOST = '127.0.0.1';

    public const PILOT_PORT = 3307;

    /** @var list<int> */
    private const ALLOWED_PILOT_PORTS = [3306, 3307];

    /** @var list<string> */
    private const ALLOWED_CORE_DATABASES = [
        'api_llantas_pilot',
        'api_llantas_pilot_core',
    ];

    public const PILOT_DRIVER = 'mariadb';

    private const PLACEHOLDER_APP_KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    /**
     * Config paths and env keys for external integrations that must remain blank in pilot.
     *
     * @var array<string, list<string>>
     */
    private const FORBIDDEN_EXTERNAL_CREDENTIALS = [
        'services.meli.app_id' => ['MELI_APP_ID'],
        'services.meli.client_id' => ['MELI_CLIENT_ID'],
        'services.meli.client_secret' => ['MELI_CLIENT_SECRET'],
        'services.amazon.lwa_client_id' => ['AMAZON_SP_API_CLIENT_ID'],
        'services.amazon.lwa_client_secret' => ['AMAZON_SP_API_CLIENT_SECRET'],
        'services.amazon.lwa_refresh_token' => ['AMAZON_SP_API_REFRESH_TOKEN'],
        'services.amazon.aws_access_key_id' => ['AMAZON_AWS_ACCESS_KEY_ID'],
        'services.amazon.aws_secret_access_key' => ['AMAZON_AWS_SECRET_ACCESS_KEY'],
        'services.shopify.store_domain' => ['SHOPIFY_STORE_DOMAIN'],
        'services.shopify.client_id' => ['SHOPIFY_CLIENT_ID'],
        'services.shopify.client_secret' => ['SHOPIFY_CLIENT_SECRET'],
        'services.syscom.client_id' => ['SYSCOM_CLIENT_ID'],
        'services.syscom.client_secret' => ['SYSCOM_CLIENT_SECRET'],
        'services.syscom.access_token' => ['SYSCOM_ACCESS_TOKEN'],
        'syscom.portal_cookies' => ['SYSCOM_PORTAL_COOKIES'],
        'services.slack.notifications.bot_user_oauth_token' => ['SLACK_BOT_USER_OAUTH_TOKEN'],
    ];

    /**
     * Direct env secrets read outside config() in legacy jobs/alerts.
     *
     * @var list<string>
     */
    private const FORBIDDEN_ENV_SECRETS = [
        'TELEGRAM_BOT_TOKEN',
        'LOG_SLACK_WEBHOOK_URL',
        'POSTMARK_API_KEY',
        'RESEND_API_KEY',
        'OPENAI_API_KEY',
    ];

    /**
     * Determine whether runtime appears configured or launched for the visual pilot.
     */
    public static function shouldEnforce(): bool
    {
        if (function_exists('app') && app()->bound('env') && app()->environment(self::PILOT_ENV)) {
            return true;
        }

        $rawEnv = strtolower(trim((string) (self::readRawEnv('APP_ENV') ?? '')));
        if ($rawEnv === self::PILOT_ENV) {
            return true;
        }

        if (function_exists('app') && app()->bound('config')) {
            $defaultConnection = (string) config('database.default', '');
            $database = trim((string) config("database.connections.{$defaultConnection}.database", ''));
            $autopartesDb = trim((string) config('database.connections.autopartes.database', ''));
            if (
                $database === self::PILOT_DATABASE
                || in_array($database, self::ALLOWED_CORE_DATABASES, true)
                || $autopartesDb === self::PILOT_DATABASE
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enforce pilot safeguards during application bootstrap when running in pilot mode.
     */
    public static function enforceIfConfigured(): void
    {
        if (! self::shouldEnforce()) {
            return;
        }

        if (function_exists('app') && app()->runningInConsole()) {
            $command = (string) ($_SERVER['argv'][1] ?? '');
            if (in_array($command, ['config:clear', 'optimize:clear', 'clear-compiled'], true)) {
                return;
            }
        }

        self::assertPilotReady();
    }

    /**
     * Validate dual-connection configuration before connecting, then verify live MariaDB targets,
     * table isolation, and least-privilege SQL grants for the Autopartes pilot user.
     *
     * @param  (callable(): object)|null  $databaseProbe
     * @param  (callable(): void)|null  $commercialPolicyChecker
     * @param  (callable(): object)|null  $autopartesProbe
     * @param  (callable(): list<string>)|null  $autopartesGrantsProbe
     * @param  (callable(): list<string>)|null  $autopartesTablesProbe
     * @return array{env: string, driver: string, host: string, port: int, database: string, autopartes_database: string, autopartes_port: int}
     */
    public static function assertPilotReady(
        ?callable $databaseProbe = null,
        ?callable $commercialPolicyChecker = null,
        ?callable $autopartesProbe = null,
        ?callable $autopartesGrantsProbe = null,
        ?callable $autopartesTablesProbe = null,
    ): array {
        if (app()->configurationIsCached()) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: bootstrap/cache/config.php está activo en piloto. Ejecuta php artisan config:clear.'
            );
        }

        $runtimeEnv = (string) app()->environment();
        if ($runtimeEnv !== self::PILOT_ENV) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: APP_ENV={$runtimeEnv} inválido para piloto (se requiere 'pilot')."
            );
        }

        $rawProcessEnv = trim((string) (self::readRawEnv('APP_ENV') ?? ''));
        if ($rawProcessEnv !== '' && strtolower($rawProcessEnv) !== self::PILOT_ENV) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: variable de entorno APP_ENV={$rawProcessEnv} incompatible con piloto."
            );
        }

        if (filter_var(self::readRawEnv('AUTOPARTES_MYSQL_STAGING') ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: AUTOPARTES_MYSQL_STAGING=true está prohibido en entorno piloto.'
            );
        }

        $stagingDbOverride = trim((string) (self::readRawEnv('AUTOPARTES_MYSQL_DATABASE') ?? ''));
        if ($stagingDbOverride !== '') {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: AUTOPARTES_MYSQL_DATABASE={$stagingDbOverride} no debe definirse en piloto."
            );
        }

        if (
            trim((string) (self::readRawEnv('DB_URL') ?? '')) !== ''
            || trim((string) (self::readRawEnv('DATABASE_URL') ?? '')) !== ''
            || trim((string) (self::readRawEnv('DB_AUTOPARTES_URL') ?? '')) !== ''
        ) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: DB_URL, DATABASE_URL y DB_AUTOPARTES_URL deben estar vacías en piloto.'
            );
        }

        $defaultConnection = (string) config('database.default', '');
        $connectionConfig = config("database.connections.{$defaultConnection}");

        if (
            $defaultConnection !== self::PILOT_DRIVER
            || ! is_array($connectionConfig)
            || ($connectionConfig['driver'] ?? null) !== self::PILOT_DRIVER
        ) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: DB_CONNECTION='{$defaultConnection}' inválido (se requiere 'mariadb')."
            );
        }

        if (trim((string) ($connectionConfig['url'] ?? '')) !== '') {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: database.connections.mariadb.url debe estar vacío en piloto.'
            );
        }

        if (trim((string) ($connectionConfig['unix_socket'] ?? '')) !== '') {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: unix_socket no permitido; usa host loopback 127.0.0.1.'
            );
        }

        $host = trim((string) ($connectionConfig['host'] ?? ''));
        $port = (int) ($connectionConfig['port'] ?? 0);
        $database = trim((string) ($connectionConfig['database'] ?? ''));

        if ($host !== self::PILOT_HOST || ! in_array($port, self::ALLOWED_PILOT_PORTS, true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: host/puerto principal no autorizado ({$host}:{$port})."
            );
        }

        if (! in_array($database, self::ALLOWED_CORE_DATABASES, true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: base principal '{$database}' no autorizada en piloto (use 'api_llantas_pilot')."
            );
        }

        // Validación estricta de la conexión dedicada 'autopartes'
        AutopartesConnectionGuard::assertDedicatedConnectionSafe(AutopartesConnectionGuard::CONNECTION_NAME);

        $autopartesConfig = config('database.connections.'.AutopartesConnectionGuard::CONNECTION_NAME);
        if (! is_array($autopartesConfig) || ($autopartesConfig['driver'] ?? null) !== self::PILOT_DRIVER) {
            $apDriver = is_array($autopartesConfig) ? (string) ($autopartesConfig['driver'] ?? '') : 'missing';
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: DB_AUTOPARTES_CONNECTION='{$apDriver}' inválido (se requiere 'mariadb')."
            );
        }

        if (trim((string) ($autopartesConfig['url'] ?? '')) !== '' || trim((string) ($autopartesConfig['unix_socket'] ?? '')) !== '') {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: url/unix_socket no permitidos en conexión autopartes de piloto.'
            );
        }

        $apHost = trim((string) ($autopartesConfig['host'] ?? ''));
        $apPort = (int) ($autopartesConfig['port'] ?? 0);
        $apDatabase = trim((string) ($autopartesConfig['database'] ?? ''));

        if ($apHost !== self::PILOT_HOST || ! in_array($apPort, self::ALLOWED_PILOT_PORTS, true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: host/puerto autopartes no autorizado ({$apHost}:{$apPort})."
            );
        }

        if ($apDatabase !== self::PILOT_DATABASE) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: base autopartes '{$apDatabase}' no autorizada (solo se permite 'api_llantas_autopartes_pilot')."
            );
        }

        self::assertAuxiliaryConnectionsIsolated();
        self::assertAppKeyValid();
        self::assertExternalIntegrationsBlocked();
        self::assertCommercialLocksClosedInConfig();

        try {
            $row = $databaseProbe !== null
                ? $databaseProbe()
                : DB::connection($defaultConnection)->selectOne('SELECT DATABASE() AS db, @@port AS port, @@hostname AS host, @@version AS version');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'PilotDatabaseGuard no pudo confirmar la base principal en MariaDB: '.$e->getMessage(),
                0,
                $e
            );
        }

        $effectiveDb = trim((string) ($row->db ?? ''));
        $effectivePort = (int) ($row->port ?? 0);

        if ($effectiveDb !== $database || ! in_array($effectivePort, self::ALLOWED_PILOT_PORTS, true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard rechazó conexión principal efectiva: DATABASE()='{$effectiveDb}', port={$effectivePort}."
            );
        }

        try {
            $apRow = $autopartesProbe !== null
                ? $autopartesProbe()
                : DB::connection(AutopartesConnectionGuard::CONNECTION_NAME)->selectOne('SELECT DATABASE() AS db, @@port AS port, @@hostname AS host, @@version AS version');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'PilotDatabaseGuard no pudo confirmar la base efectiva de Autopartes en MariaDB: '.$e->getMessage(),
                0,
                $e
            );
        }

        $effectiveAutopartesDb = trim((string) ($apRow->db ?? ''));
        $effectiveAutopartesPort = (int) ($apRow->port ?? 0);

        if ($effectiveAutopartesDb !== self::PILOT_DATABASE || ! in_array($effectiveAutopartesPort, self::ALLOWED_PILOT_PORTS, true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard rechazó conexión autopartes efectiva: DATABASE()='{$effectiveAutopartesDb}', port={$effectiveAutopartesPort}."
            );
        }

        if ($autopartesProbe === null || $autopartesGrantsProbe !== null || $autopartesTablesProbe !== null) {
            AutopartesConnectionGuard::assertRuntimeIsolation(
                DB::connection(AutopartesConnectionGuard::CONNECTION_NAME),
                static fn (): string => $effectiveAutopartesDb,
                $autopartesTablesProbe,
                $autopartesGrantsProbe,
                true
            );
        }

        if ($commercialPolicyChecker !== null) {
            $commercialPolicyChecker();
        } else {
            self::assertCommercialPoliciesClosedInDatabase(AutopartesConnectionGuard::CONNECTION_NAME);
        }

        return [
            'env' => $runtimeEnv,
            'driver' => $defaultConnection,
            'host' => $host,
            'port' => $effectivePort,
            'database' => $effectiveDb,
            'autopartes_database' => $effectiveAutopartesDb,
            'autopartes_port' => $effectiveAutopartesPort,
        ];
    }

    private static function assertAuxiliaryConnectionsIsolated(): void
    {
        $auxiliaryConnections = [
            'session.connection' => config('session.connection'),
            'queue.connections.database.connection' => config('queue.connections.database.connection'),
            'queue.failed.database' => config('queue.failed.database'),
            'cache.stores.database.connection' => config('cache.stores.database.connection'),
            'cache.stores.database.lock_connection' => config('cache.stores.database.lock_connection'),
        ];

        foreach ($auxiliaryConnections as $key => $conn) {
            $normalized = is_string($conn) ? trim($conn) : '';
            if ($normalized !== '' && $normalized !== self::PILOT_DRIVER) {
                throw new RuntimeException(
                    "PilotDatabaseGuard bloqueó el arranque: {$key}='{$normalized}' apunta fuera de mariadb piloto."
                );
            }
        }

        $autopartesQueue = (string) config('autopartes.queue_connection', '');
        if (! in_array($autopartesQueue, ['database', 'sync'], true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: autopartes.queue_connection='{$autopartesQueue}' no aislado en piloto."
            );
        }

        $mailer = strtolower(trim((string) config('mail.default', '')));
        if (! in_array($mailer, ['log', 'array'], true)) {
            throw new RuntimeException(
                "PilotDatabaseGuard bloqueó el arranque: mail.default='{$mailer}' debe ser 'log' o 'array' en piloto."
            );
        }
    }

    private static function assertAppKeyValid(): void
    {
        $appKey = trim((string) config('app.key', ''));
        if (
            $appKey === ''
            || $appKey === self::PLACEHOLDER_APP_KEY
            || str_contains(strtolower($appKey), 'changeme')
            || str_contains(strtolower($appKey), 'placeholder')
        ) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: APP_KEY vacío o de plantilla; genera una llave exclusiva para .env.pilot.'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded === false || strlen($decoded) < 32) {
                throw new RuntimeException(
                    'PilotDatabaseGuard bloqueó el arranque: APP_KEY base64 inválido o menor a 32 bytes.'
                );
            }

            return;
        }

        if (strlen($appKey) < 32) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: APP_KEY demasiado corto para piloto.'
            );
        }
    }

    private static function assertExternalIntegrationsBlocked(): void
    {
        if (config('autopartes.block_external_channels', false) !== true) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: AUTOPARTES_BLOCK_EXTERNAL_CHANNELS debe ser true en piloto.'
            );
        }

        foreach (self::FORBIDDEN_EXTERNAL_CREDENTIALS as $configKey => $envKeys) {
            $configValue = trim((string) config($configKey, ''));
            if ($configValue !== '') {
                throw new RuntimeException(
                    "PilotDatabaseGuard bloqueó el arranque: credencial externa configurada en '{$configKey}'."
                );
            }

            foreach ($envKeys as $envKey) {
                $rawValue = trim((string) (self::readRawEnv($envKey) ?? ''));
                if ($rawValue !== '') {
                    throw new RuntimeException(
                        "PilotDatabaseGuard bloqueó el arranque: variable externa '{$envKey}' presente en entorno piloto."
                    );
                }
            }
        }

        foreach (self::FORBIDDEN_ENV_SECRETS as $envKey) {
            $rawValue = trim((string) (self::readRawEnv($envKey) ?? ''));
            if ($rawValue !== '') {
                throw new RuntimeException(
                    "PilotDatabaseGuard bloqueó el arranque: secreto externo '{$envKey}' presente en entorno piloto."
                );
            }
        }

        if (
            (bool) config('services.meli.webhook_dispatch_orders_v2', false)
            || (bool) config('inventory.meli_order_reservations.automatic', false)
            || (bool) config('syscom.orders_from_meli.enabled', false)
            || (bool) config('syscom.orders_from_meli.cancel_enabled', false)
            || (bool) config('syscom.portal_scrape_enabled', false)
            || MeliBeautyScheduledPriceSchedule::enabled()
            || filter_var(self::readRawEnv('MELI_WEBHOOK_PROCESS_ORDERS') ?? false, FILTER_VALIDATE_BOOLEAN)
            || (int) config('services.meli.sync_orders_user_id', 4) > 0
        ) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: existen banderas automáticas de MeLi/Syscom encendidas en piloto.'
            );
        }
    }

    private static function assertCommercialLocksClosedInConfig(): void
    {
        if (
            config('autopartes.pos.sales_enabled_by_default', true) !== false
            || config('autopartes.pos.fiscal_policy_validated_by_default', true) !== false
            || config('autopartes.pos.manual_discounts_enabled_by_default', true) !== false
            || config('autopartes.pos.tax_enabled', true) !== false
            || abs((float) config('autopartes.pos.tax_rate', 0.0)) > 0.000001
        ) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: candados comerciales o fiscales del POS están abiertos en configuración.'
            );
        }
    }

    private static function assertCommercialPoliciesClosedInDatabase(string $connection): void
    {
        if (! Schema::connection($connection)->hasTable('automotive_branch_commercial_policies')) {
            return;
        }

        $unlocked = DB::connection($connection)
            ->table('automotive_branch_commercial_policies')
            ->where(function ($query): void {
                $query->where('pos_sales_enabled', true)
                    ->orWhere('fiscal_policy_validated', true)
                    ->orWhere('manual_discounts_enabled', true);
            })
            ->exists();

        if ($unlocked) {
            throw new RuntimeException(
                'PilotDatabaseGuard bloqueó el arranque: una sucursal tiene pos_sales_enabled, fiscal_policy_validated o manual_discounts_enabled activo en BD.'
            );
        }
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
