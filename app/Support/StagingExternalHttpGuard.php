<?php

namespace App\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class StagingExternalHttpGuard
{
    public static function isIsolatedEnvironment(): bool
    {
        if (function_exists('app') && app()->bound('env')) {
            return app()->environment(['staging', 'pilot']);
        }

        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '')));

        return in_array($env, ['staging', 'pilot'], true);
    }

    public static function isProductionEnvironment(): bool
    {
        if (function_exists('app') && app()->bound('env')) {
            return app()->environment('production');
        }

        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: '')));

        return $env === 'production';
    }

    public static function shouldBlock(): bool
    {
        // Never allow staging/pilot isolation flags to silence legitimate core MeLi/SYSCOM/Telegram production services.
        if (self::isProductionEnvironment()) {
            return false;
        }

        if (function_exists('app') && app()->bound('config')) {
            return (bool) config('autopartes.block_external_channels', false)
                || self::isIsolatedEnvironment();
        }

        return filter_var(getenv('AUTOPARTES_BLOCK_EXTERNAL_CHANNELS') ?: false, FILTER_VALIDATE_BOOLEAN)
            || self::isIsolatedEnvironment();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function client(array $options = []): Client
    {
        return new Client(self::applyToGuzzleOptions($options));
    }

    /**
     * Attach a pre-network Guzzle middleware when staging external-channel blocking is enabled.
     * In production (`AUTOPARTES_BLOCK_EXTERNAL_CHANNELS=false` and `APP_ENV=production`),
     * returns $options unchanged.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function applyToGuzzleOptions(array $options = []): array
    {
        if (! self::shouldBlock()) {
            return $options;
        }

        $stack = isset($options['handler']) && $options['handler'] instanceof HandlerStack
            ? clone $options['handler']
            : HandlerStack::create();

        $stack->unshift(static function (callable $handler): callable {
            return static function (RequestInterface $request, array $reqOptions) {
                return new RejectedPromise(new RuntimeException(
                    'Conexión HTTP externa bloqueada en staging/pruebas: '.$request->getMethod().' '.$request->getUri()
                ));
            };
        }, 'autopartes_staging_block');

        $options['handler'] = $stack;

        return $options;
    }
}
