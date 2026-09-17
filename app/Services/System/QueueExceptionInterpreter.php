<?php

namespace App\Services\System;

class QueueExceptionInterpreter
{
    /**
     * Analiza una excepción de Laravel Queue y devuelve un diagnóstico
     * entendible para el usuario.
     *
     * @return array{
     *     type: string,
     *     title: string,
     *     cause: string,
     *     recommendation: string,
     *     severity: string,
     *     retry_recommended: bool,
     *     retry_safe: bool,
     *     integration: string|null,
     *     matched_rule: string
     * }
     */
    public function analyze(string $exception, ?string $jobName = null): array
    {
        $exception = trim($exception);
        $searchText = mb_strtolower($jobName.' '.$exception);
        $integration = $this->detectIntegration($searchText);

        /*
         * Disco lleno.
         */
        if ($this->containsAny($searchText, [
            'no space left on device',
            'disk full',
            'the table is full',
            'database or disk is full',
        ])) {
            return $this->diagnosis(
                type: 'DiskFull',
                title: 'El servidor no tiene espacio disponible',
                cause: 'El trabajo no pudo guardar archivos, caché, logs o información en la base de datos porque el disco está lleno.',
                recommendation: 'Libera espacio en el servidor antes de reintentar. Revisa especialmente logs, respaldos, archivos temporales y almacenamiento de Plesk.',
                severity: 'critical',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'disk_full',
            );
        }

        /*
         * Permisos del sistema de archivos.
         */
        if ($this->containsAny($searchText, [
            'permission denied',
            'operation not permitted',
            'failed to open stream: permission denied',
            'is not writable',
            'unable to create the directory',
            'cannot create directory',
        ])) {
            return $this->diagnosis(
                type: 'PermissionError',
                title: 'Permisos incorrectos en el servidor',
                cause: 'Laravel no tiene permisos suficientes para leer, escribir o crear alguno de los archivos necesarios.',
                recommendation: 'Revisa propietario y permisos de storage, bootstrap/cache y las carpetas utilizadas por este proceso. Después limpia la caché y reinicia los workers.',
                severity: 'high',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'permission_error',
            );
        }

        /*
         * Archivos de caché inexistentes o dañados.
         */
        if (
            $this->containsAny($searchText, [
                'storage/framework/cache',
                'storage/framework/views',
                'bootstrap/cache',
            ])
            && $this->containsAny($searchText, [
                'no such file or directory',
                'failed to open stream',
                'file_put_contents',
                'unlink',
            ])
        ) {
            return $this->diagnosis(
                type: 'CacheFileError',
                title: 'La caché de Laravel está dañada o incompleta',
                cause: 'El worker intentó utilizar un archivo temporal o de caché que ya no existe, fue eliminado o no pudo ser creado.',
                recommendation: 'Ejecuta php artisan optimize:clear, verifica las carpetas de storage/framework y después reinicia los workers.',
                severity: 'medium',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'cache_file_error',
            );
        }

        /*
         * Autenticación y tokens.
         */
        if ($this->containsAny($searchText, [
            'unauthenticated',
            'unauthorized',
            'invalid access token',
            'invalid_token',
            'token expired',
            'expired token',
            'access token expired',
            'oauth token',
            'http 401',
            'status code 401',
            'response status code 401',
        ])) {
            return $this->diagnosis(
                type: 'AuthenticationError',
                title: 'Token o sesión de la integración vencida',
                cause: $this->integrationMessage(
                    $integration,
                    'La API rechazó la solicitud porque las credenciales utilizadas ya no son válidas o expiraron.'
                ),
                recommendation: 'Renueva o vuelve a autorizar la cuenta correspondiente. Confirma que el token se guardó correctamente antes de reintentar.',
                severity: 'high',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'authentication_error',
            );
        }

        /*
         * Acceso prohibido.
         */
        if ($this->containsAny($searchText, [
            'forbidden',
            'http 403',
            'status code 403',
            'response status code 403',
        ])) {
            return $this->diagnosis(
                type: 'ForbiddenError',
                title: 'La API rechazó el acceso',
                cause: $this->integrationMessage(
                    $integration,
                    'Las credenciales fueron reconocidas, pero no tienen permiso para ejecutar esta operación.'
                ),
                recommendation: 'Revisa los permisos de la cuenta, las IP autorizadas, el alcance del token y las restricciones configuradas en la API.',
                severity: 'high',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'forbidden_error',
            );
        }

        /*
         * Límite de solicitudes.
         */
        if ($this->containsAny($searchText, [
            'too many requests',
            'rate limit',
            'rate_limit',
            'http 429',
            'status code 429',
            'response status code 429',
        ])) {
            return $this->diagnosis(
                type: 'RateLimitError',
                title: 'La API recibió demasiadas solicitudes',
                cause: $this->integrationMessage(
                    $integration,
                    'La integración alcanzó temporalmente el límite de solicitudes permitido por la API.'
                ),
                recommendation: 'Espera unos minutos y vuelve a intentarlo. Conviene agregar pausas, backoff y límites de concurrencia al proceso.',
                severity: 'medium',
                retryRecommended: true,
                retrySafe: true,
                integration: $integration,
                matchedRule: 'rate_limit',
            );
        }

        /*
         * Timeouts.
         */
        if ($this->containsAny($searchText, [
            'timeoutexceededexception',
            'timed out',
            'timeout',
            'maximum execution time',
            'curl error 28',
            'operation timed out',
            'execution timed out',
        ])) {
            return $this->diagnosis(
                type: 'TimeoutError',
                title: 'La operación tardó demasiado',
                cause: $this->integrationMessage(
                    $integration,
                    'El trabajo superó el tiempo máximo permitido mientras esperaba una respuesta o procesaba demasiada información.'
                ),
                recommendation: 'Revisa la conectividad y el tiempo de respuesta de la API. Si el proceso es grande, divídelo en lotes y ajusta timeout, tries y backoff.',
                severity: 'medium',
                retryRecommended: true,
                retrySafe: true,
                integration: $integration,
                matchedRule: 'timeout',
            );
        }

        /*
         * Problemas de conexión.
         */
        if ($this->containsAny($searchText, [
            'connection refused',
            'could not resolve host',
            'couldn\'t resolve host',
            'name or service not known',
            'network is unreachable',
            'connection reset by peer',
            'connection timed out',
            'curl error 6',
            'curl error 7',
            'curl error 35',
            'curl error 52',
            'curl error 56',
            'temporary failure in name resolution',
        ])) {
            return $this->diagnosis(
                type: 'ConnectionError',
                title: 'No fue posible conectarse al servicio',
                cause: $this->integrationMessage(
                    $integration,
                    'El servidor no pudo establecer o mantener la conexión con el servicio externo.'
                ),
                recommendation: 'Comprueba Internet, DNS, firewall, URL del servicio y disponibilidad de la API. Después vuelve a intentar el trabajo.',
                severity: 'medium',
                retryRecommended: true,
                retrySafe: true,
                integration: $integration,
                matchedRule: 'connection_error',
            );
        }

        /*
         * Deadlocks de base de datos.
         */
        if ($this->containsAny($searchText, [
            'deadlock found',
            'serialization failure',
            'lock wait timeout exceeded',
            'database is locked',
        ])) {
            return $this->diagnosis(
                type: 'DatabaseLockError',
                title: 'La base de datos estaba ocupada',
                cause: 'Dos o más procesos intentaron modificar los mismos registros al mismo tiempo y la base de datos detuvo uno de ellos.',
                recommendation: 'El trabajo normalmente puede reintentarse. Si ocurre con frecuencia, reduce la concurrencia y procesa los registros en lotes más pequeños.',
                severity: 'medium',
                retryRecommended: true,
                retrySafe: true,
                integration: $integration,
                matchedRule: 'database_lock',
            );
        }

        /*
         * Registros duplicados.
         */
        if ($this->containsAny($searchText, [
            'duplicate entry',
            'unique constraint',
            'unique violation',
            'integrity constraint violation: 1062',
        ])) {
            return $this->diagnosis(
                type: 'DuplicateRecordError',
                title: 'Se intentó guardar un registro duplicado',
                cause: 'La operación quiso crear información que ya existe en una columna o combinación marcada como única.',
                recommendation: 'Revisa el SKU, identificador externo o llave única involucrada. Corrige el origen o cambia el proceso para actualizar en lugar de insertar.',
                severity: 'medium',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'duplicate_record',
            );
        }

        /*
         * Otros errores SQL.
         */
        if ($this->containsAny($searchText, [
            'queryexception',
            'sqlstate[',
            'pdoexception',
            'mysqli_sql_exception',
        ])) {
            return $this->diagnosis(
                type: 'DatabaseError',
                title: 'Error al consultar la base de datos',
                cause: 'El trabajo no pudo completar una consulta, actualización o inserción en la base de datos.',
                recommendation: 'Abre los detalles técnicos para identificar la consulta y la tabla afectada. Revisa migraciones, columnas, datos y conexión a la base de datos.',
                severity: 'high',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'database_error',
            );
        }

        /*
         * Errores HTTP del servidor externo.
         */
        if ($this->containsAny($searchText, [
            'http 500',
            'status code 500',
            'response status code 500',
            'http 502',
            'status code 502',
            'http 503',
            'status code 503',
            'http 504',
            'status code 504',
            'bad gateway',
            'service unavailable',
            'gateway timeout',
            'internal server error',
        ])) {
            return $this->diagnosis(
                type: 'ExternalServiceError',
                title: 'El servicio externo presentó un error',
                cause: $this->integrationMessage(
                    $integration,
                    'La API respondió con un error interno o se encontraba temporalmente fuera de servicio.'
                ),
                recommendation: 'Espera unos minutos y vuelve a intentarlo. Si continúa, revisa el estado de la API y guarda la respuesta completa para soporte.',
                severity: 'medium',
                retryRecommended: true,
                retrySafe: true,
                integration: $integration,
                matchedRule: 'external_service_error',
            );
        }

        /*
         * Número máximo de intentos.
         */
        if ($this->containsAny($searchText, [
            'maxattemptsexceededexception',
            'has been attempted too many times',
            'attempted too many times',
            'maximum attempts',
        ])) {
            return $this->diagnosis(
                type: 'MaxAttemptsExceeded',
                title: 'El trabajo agotó todos sus intentos',
                cause: 'Laravel intentó ejecutar este trabajo varias veces, pero el problema original continuó ocurriendo.',
                recommendation: 'No lo reintentes masivamente. Revisa los detalles y el log para encontrar la excepción original antes de enviarlo nuevamente.',
                severity: 'high',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'max_attempts',
            );
        }

        /*
         * Clases inexistentes o errores de programación.
         */
        if ($this->containsAny($searchText, [
            'class not found',
            'target class',
            'undefined method',
            'call to undefined method',
            'undefined variable',
            'syntax error',
            'parse error',
            'typeerror',
            'argumentcounterror',
        ])) {
            return $this->diagnosis(
                type: 'ApplicationError',
                title: 'Error en el código de la aplicación',
                cause: 'El trabajo intentó utilizar una clase, método, variable o tipo que no está disponible o no coincide con lo esperado.',
                recommendation: 'Revisa el código y el despliegue reciente. Ejecuta Composer y compila el frontend si corresponde. No reintentes hasta corregir el error.',
                severity: 'high',
                retryRecommended: false,
                retrySafe: false,
                integration: $integration,
                matchedRule: 'application_error',
            );
        }

        /*
         * Trabajo desconocido.
         */
        return $this->diagnosis(
            type: 'UnknownError',
            title: 'Error todavía no identificado',
            cause: 'El sistema encontró una excepción que todavía no coincide con una regla de diagnóstico conocida.',
            recommendation: 'Abre los detalles técnicos y revisa el log relacionado. No se recomienda un reintento masivo hasta confirmar la causa.',
            severity: 'medium',
            retryRecommended: false,
            retrySafe: false,
            integration: $integration,
            matchedRule: 'unknown',
        );
    }

    private function diagnosis(
        string $type,
        string $title,
        string $cause,
        string $recommendation,
        string $severity,
        bool $retryRecommended,
        bool $retrySafe,
        ?string $integration,
        string $matchedRule,
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'cause' => $cause,
            'recommendation' => $recommendation,
            'severity' => $severity,
            'retry_recommended' => $retryRecommended,
            'retry_safe' => $retrySafe,
            'integration' => $integration,
            'matched_rule' => $matchedRule,
        ];
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function detectIntegration(string $text): ?string
    {
        if ($this->containsAny($text, [
            'mercado libre',
            'mercadolibre',
            'meli',
            'mlm',
        ])) {
            return 'Mercado Libre';
        }

        if ($this->containsAny($text, [
            'syscom',
        ])) {
            return 'SYSCOM';
        }

        if ($this->containsAny($text, [
            'amazon',
            'amazon selling partner',
            'selling partner api',
            'sp-api',
            'ams',
        ])) {
            return 'Amazon';
        }

        if ($this->containsAny($text, [
            'telegram',
        ])) {
            return 'Telegram';
        }

        if ($this->containsAny($text, [
            'odoo',
        ])) {
            return 'Odoo';
        }

        return null;
    }

    private function integrationMessage(?string $integration, string $message): string
    {
        if (! $integration) {
            return $message;
        }

        return $integration.': '.$message;
    }
}