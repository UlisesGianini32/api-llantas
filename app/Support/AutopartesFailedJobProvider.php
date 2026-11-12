<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AutopartesFailedJobProvider implements FailedJobProviderInterface, PrunableFailedJobProvider, CountableFailedJobProvider
{
    public const CONNECTION_NAME = 'autopartes_database';
    public const QUEUE_NAME = 'autopartes';
    public const TABLE = 'automotive_failed_jobs';
    public const DB_CONNECTION = 'autopartes';

    public function __construct(
        private readonly ?FailedJobProviderInterface $defaultProvider,
        private readonly ConnectionResolverInterface $resolver,
    ) {}

    public function log($connection, $queue, $payload, $exception): ?string
    {
        if (self::isAutopartesJob($connection, $queue)) {
            try {
                $decoded = json_decode((string) $payload, true);
                $uuid = is_array($decoded) && isset($decoded['uuid'])
                    ? (string) $decoded['uuid']
                    : (string) Str::uuid();

                $this->getAutopartesTable()->insert([
                    'uuid' => $uuid,
                    'connection' => (string) $connection,
                    'queue' => (string) $queue,
                    'payload' => (string) $payload,
                    'exception' => (string) mb_convert_encoding($exception, 'UTF-8'),
                    'failed_at' => Date::now(),
                ]);

                return $uuid;
            } catch (Throwable $e) {
                // PROHIBIDO el fallback a api_llantas.failed_jobs.
                // Fallar de forma explícita y verificable para garantizar aislamiento físico.
                throw new RuntimeException(
                    "AutopartesFailedJobProvider bloqueó el registro de fallo: no se pudo escribir en '"
                    .self::TABLE."' de la conexión dedicada '"
                    .self::DB_CONNECTION."'. Prohibido registrar fallos de Autopartes en la base operativa 'api_llantas'. Error: "
                    .$e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $this->defaultProvider?->log($connection, $queue, $payload, $exception);
    }

    public function ids($queue = null): array
    {
        if ($queue !== null && self::isAutopartesJob(null, $queue)) {
            try {
                return $this->getAutopartesTable()
                    ->where('queue', $queue)
                    ->orderBy('id', 'desc')
                    ->pluck('uuid')
                    ->all();
            } catch (Throwable) {
                return [];
            }
        }

        $defaultIds = $this->defaultProvider?->ids($queue) ?? [];

        if ($queue !== null) {
            return $defaultIds;
        }

        try {
            $autoIds = $this->getAutopartesTable()
                ->orderBy('id', 'desc')
                ->pluck('uuid')
                ->all();

            return array_values(array_unique(array_merge($defaultIds, $autoIds)));
        } catch (Throwable) {
            return $defaultIds;
        }
    }

    public function all(): array
    {
        $defaultJobs = $this->defaultProvider?->all() ?? [];

        try {
            $autoJobs = $this->getAutopartesTable()
                ->orderBy('id', 'desc')
                ->get()
                ->map(function ($record) {
                    $record->id = $record->uuid;
                    unset($record->uuid);
                    return $record;
                })
                ->all();

            return array_merge($defaultJobs, $autoJobs);
        } catch (Throwable) {
            return $defaultJobs;
        }
    }

    public function find($id): ?object
    {
        $job = $this->defaultProvider?->find($id);
        if ($job !== null) {
            return $job;
        }

        try {
            if ($record = $this->getAutopartesTable()->where('uuid', $id)->first()) {
                $record->id = $record->uuid;
                unset($record->uuid);
                return (object) $record;
            }
        } catch (Throwable) {
            // Ignore
        }

        return null;
    }

    public function forget($id): bool
    {
        $deleted = $this->defaultProvider?->forget($id) ?? false;
        if ($deleted) {
            return true;
        }

        try {
            return $this->getAutopartesTable()->where('uuid', $id)->delete() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function flush($hours = null): void
    {
        $this->defaultProvider?->flush($hours);

        try {
            $this->getAutopartesTable()
                ->when($hours, fn ($query) => $query->where('failed_at', '<=', Date::now()->subHours($hours)))
                ->delete();
        } catch (Throwable) {
            // Ignore
        }
    }

    public function prune(DateTimeInterface $before): int
    {
        $defaultPruned = 0;
        if ($this->defaultProvider instanceof PrunableFailedJobProvider) {
            $defaultPruned = $this->defaultProvider->prune($before);
        }

        $autoPruned = 0;
        try {
            $query = $this->getAutopartesTable()->where('failed_at', '<', $before);
            do {
                $deleted = $query->limit(1000)->delete();
                $autoPruned += $deleted;
            } while ($deleted !== 0);
        } catch (Throwable) {
            // Ignore
        }

        return $defaultPruned + $autoPruned;
    }

    public function count($connection = null, $queue = null): int
    {
        if (self::isAutopartesJob($connection, $queue)) {
            try {
                return $this->getAutopartesTable()
                    ->when($connection, fn ($b) => $b->where('connection', $connection))
                    ->when($queue, fn ($b) => $b->where('queue', $queue))
                    ->count();
            } catch (Throwable) {
                return 0;
            }
        }

        $defaultCount = 0;
        if ($this->defaultProvider instanceof CountableFailedJobProvider) {
            $defaultCount = $this->defaultProvider->count($connection, $queue);
        }

        if ($connection === null && $queue === null) {
            try {
                $defaultCount += $this->getAutopartesTable()->count();
            } catch (Throwable) {
                // Ignore
            }
        }

        return $defaultCount;
    }

    public static function isAutopartesJob(?string $connection, ?string $queue): bool
    {
        if ($connection !== null && $connection === self::CONNECTION_NAME) {
            return true;
        }

        $configuredQueue = (string) config('autopartes.queue', self::QUEUE_NAME);
        if ($queue !== null && ($queue === self::QUEUE_NAME || $queue === $configuredQueue)) {
            return true;
        }

        return false;
    }

    private function getAutopartesTable()
    {
        return $this->resolver->connection(self::DB_CONNECTION)->table(self::TABLE);
    }
}
