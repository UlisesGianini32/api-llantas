<?php

namespace App\Models;

use App\Support\AutopartesConnectionGuard;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Modelo base abstracto para todas las entidades del módulo independiente Autopartes Hermosillo.
 * Garantiza conexión dedicada ('autopartes') y transacciones ACID aisladas del inventario general.
 */
abstract class AutopartesModel extends Model
{
    protected $connection = AutopartesConnectionGuard::CONNECTION_NAME;

    public function getConnectionName(): string
    {
        return AutopartesConnectionGuard::connectionName();
    }

    public static function db(): ConnectionInterface
    {
        $info = AutopartesConnectionGuard::assertDedicatedConnectionSafe();
        $connection = DB::connection($info['connection']);
        AutopartesConnectionGuard::assertAutopartesTransactionConnection($connection, false);

        return $connection;
    }

    /**
     * Execute an InnoDB transaction strictly on the dedicated 'autopartes' database connection.
     */
    public static function transaction(Closure $callback, ?int $attempts = null): mixed
    {
        $tries = $attempts ?? (function_exists('app') && app()->bound('config')
            ? max(1, (int) config('autopartes.transaction_attempts', 3))
            : 3);

        $connection = static::db();

        return $connection->transaction(function () use ($callback, $connection): mixed {
            AutopartesConnectionGuard::assertAutopartesTransactionConnection($connection, true);

            return $callback($connection);
        }, $tries);
    }
}
