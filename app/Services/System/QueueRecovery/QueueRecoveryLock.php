<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use Illuminate\Database\ConnectionInterface;

class QueueRecoveryLock
{
    /** @var resource|null */
    private $fileHandle = null;

    private ?string $dbLockName = null;

    private ?ConnectionInterface $dbConnection = null;

    private ?string $lockFilePath = null;

    public function acquire(ConnectionInterface $connection, string $scopeKey, ?string $lockDirectory = null): void
    {
        $hash = hash('sha256', $scopeKey);
        $dir = $lockDirectory ?? storage_path('framework/cache');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $lockPath = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'ops-queue-recovery-'.substr($hash, 0, 16).'.lock';
        $handle = @fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new QueueRecoveryException(
                "No se pudo abrir el archivo de lock exclusivo: '{$lockPath}'.",
                'lock_unavailable',
                ['lock_path' => $lockPath],
            );
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new QueueRecoveryException(
                'Ya existe otra instancia de recuperación de colas en ejecución (lock local ocupado).',
                'already_running',
                ['lock_path' => $lockPath],
            );
        }

        $this->fileHandle = $handle;
        $this->lockFilePath = $lockPath;

        $driver = $connection->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $dbLockName = 'ops_qrec_'.substr($hash, 0, 48);
            $row = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$dbLockName]);
            $acquired = (int) ($row->acquired ?? 0);

            if ($acquired !== 1) {
                $this->release();
                throw new QueueRecoveryException(
                    'Ya existe otra instancia de recuperación de colas en ejecución en la base de datos (GET_LOCK ocupado).',
                    'already_running',
                    ['db_lock_name' => $dbLockName],
                );
            }

            $this->dbLockName = $dbLockName;
            $this->dbConnection = $connection;
        }
    }

    public function release(): void
    {
        if ($this->dbConnection !== null && $this->dbLockName !== null) {
            try {
                $this->dbConnection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->dbLockName]);
            } catch (\Throwable) {
                // Best effort release; connection close also releases MySQL/MariaDB advisory lock.
            }
        }

        $this->dbConnection = null;
        $this->dbLockName = null;

        if ($this->fileHandle !== null) {
            @flock($this->fileHandle, LOCK_UN);
            @fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        $this->lockFilePath = null;
    }

    public function lockFilePath(): ?string
    {
        return $this->lockFilePath;
    }
}
