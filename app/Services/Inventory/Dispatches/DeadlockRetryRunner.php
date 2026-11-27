<?php

namespace App\Services\Inventory\Dispatches;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

class DeadlockRetryRunner
{
    public const MAX_ATTEMPTS = 3;

    /** @var list<int> */
    private const BACKOFF_MS = [25, 75, 150];

    /**
     * Execute an idempotent transactional unit of work with bounded retries on InnoDB/SQL
     * deadlocks (SQLSTATE 40001 / errno 1213) and lock wait timeouts (errno 1205).
     * Never retries validation, authorization, conflict, or unexpected SQL errors.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback, int $maxAttempts = self::MAX_ATTEMPTS): mixed
    {
        $maxAttempts = max(1, min($maxAttempts, 5));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $callback();
            } catch (Throwable $exception) {
                if ($attempt >= $maxAttempts || ! $this->isRetryableLockException($exception)) {
                    throw $exception;
                }

                $baseMs = self::BACKOFF_MS[$attempt - 1] ?? 150;
                $jitterMs = random_int(0, 20);
                usleep(($baseMs + $jitterMs) * 1000);
            }
        }
    }

    public function isRetryableLockException(Throwable $exception): bool
    {
        if (! ($exception instanceof QueryException || $exception instanceof PDOException)) {
            $previous = $exception->getPrevious();
            if ($previous instanceof Throwable && $previous !== $exception) {
                return $this->isRetryableLockException($previous);
            }

            return false;
        }

        $sqlState = '';
        $driverCode = null;

        if ($exception instanceof QueryException && is_array($exception->errorInfo)) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : null;
        } elseif ($exception instanceof PDOException && is_array($exception->errorInfo)) {
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : null;
        }

        if ($sqlState === '40001') {
            return true;
        }

        if (in_array($driverCode, [1213, 1205], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'deadlock found')
            || str_contains($message, 'lock wait timeout exceeded');
    }
}
