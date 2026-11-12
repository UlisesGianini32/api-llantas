<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
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
        if ($this->isAutopartesJob($connection, $queue)) {
            try {
                $decoded = json_decode($payload, true);
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
            } catch (Throwable) {
                // If the automotive_failed_jobs table is unavailable, fail-safe to default provider if present
                if ($this->defaultProvider) {
                    return $this->defaultProvider->log($connection, $queue, $payload, $exception);
                }
                return null;
            }
        }

        return $this->defaultProvider?->log($connection, $queue, $payload, $exception);
    }

    public function ids($queue = null): array
    {
        $defaultIds = $this->defaultProvider?->ids($queue) ?? [];

        if ($queue !== null && ! str_starts_with((string) $queue, self::QUEUE_NAME)) {
            return $defaultIds;
        }

        try {
            $autoIds = $this->getAutopartesTable()
                ->when(! is_null($queue), fn ($q) => $q->where('queue', $queue))
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
        if ($this->isAutopartesJob($connection, $queue)) {
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

    private function isAutopartesJob(?string $connection, ?string $queue): bool
    {
        if ($connection === self::CONNECTION_NAME) {
            return true;
        }

        if ($queue !== null && str_starts_with($queue, self::QUEUE_NAME)) {
            return true;
        }

        return false;
    }

    private function getAutopartesTable()
    {
        return $this->resolver->connection(self::DB_CONNECTION)->table(self::TABLE);
    }
}
