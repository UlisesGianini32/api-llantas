<?php

namespace App\Http\Controllers;

use App\Services\System\QueueExceptionInterpreter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SystemQueueController extends Controller
{
    public function __construct(
        private readonly QueueExceptionInterpreter $interpreter,
    ) {
    }

    public function index(): Response
    {
        $hasJobsTable = Schema::hasTable('jobs');
        $hasFailedJobsTable = Schema::hasTable('failed_jobs');

        $pending = $hasJobsTable
            ? DB::table('jobs')
                ->select([
                    'id',
                    'queue',
                    'attempts',
                    'reserved_at',
                    'available_at',
                    'created_at',
                ])
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'attempts' => $job->attempts,
                    'reserved_at' => $this->timestamp($job->reserved_at),
                    'available_at' => $this->timestamp($job->available_at),
                    'created_at' => $this->timestamp($job->created_at),
                ])
                ->values()
                ->all()
            : [];

        $failed = $hasFailedJobsTable
            ? DB::table('failed_jobs')
                ->select([
                    'id',
                    'uuid',
                    'connection',
                    'queue',
                    'payload',
                    'exception',
                    'failed_at',
                ])
                ->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(function ($job) {
                    $name = $this->jobName($job->payload);
                    $exception = (string) $job->exception;

                    return [
                        'id' => $job->id,
                        'uuid' => $job->uuid,
                        'connection' => $job->connection,
                        'queue' => $job->queue,
                        'name' => $name,
                        'exception' => mb_substr($exception, 0, 20000),
                        'exception_preview' => $this->exceptionPreview($exception),
                        'failed_at' => $job->failed_at,
                        'diagnosis' => $this->interpreter->analyze(
                            exception: $exception,
                            jobName: $name,
                        ),
                    ];
                })
                ->values()
                ->all()
            : [];

        $blockedVisible = collect($failed)
            ->filter(
                fn (array $job) => ! ($job['diagnosis']['retry_safe'] ?? false)
            )
            ->count();

        return Inertia::render('System/Queues', [
            'stats' => [
                'pending' => $hasJobsTable
                    ? DB::table('jobs')->count()
                    : 0,

                'failed' => $hasFailedJobsTable
                    ? DB::table('failed_jobs')->count()
                    : 0,

                'blocked_visible' => $blockedVisible,
                'connection' => config('queue.default'),
            ],

            'pendingJobs' => $pending,
            'failedJobs' => $failed,
        ]);
    }

    public function retry(string $uuid): RedirectResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return back()->with(
                'error',
                'La tabla de trabajos fallidos no está disponible.'
            );
        }

        $job = DB::table('failed_jobs')
            ->where('uuid', $uuid)
            ->first();

        if (! $job) {
            return back()->with(
                'error',
                'El trabajo fallido ya no existe.'
            );
        }

        $name = $this->jobName($job->payload);
        $diagnosis = $this->interpreter->analyze(
            exception: (string) $job->exception,
            jobName: $name,
        );

        if (! $diagnosis['retry_safe']) {
            return back()->with(
                'error',
                'El reintento fue bloqueado porque primero debe corregirse la causa del error.'
            );
        }

        try {
            Artisan::call('queue:retry', [
                'id' => [$uuid],
            ]);

            return back()->with(
                'success',
                'Trabajo enviado nuevamente a la cola.'
            );
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'No fue posible reintentar el trabajo.'
            );
        }
    }

    public function retryAll(): RedirectResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return back()->with(
                'error',
                'La tabla de trabajos fallidos no está disponible.'
            );
        }

        $failedJobs = DB::table('failed_jobs')
            ->select([
                'payload',
                'exception',
            ])
            ->get();

        if ($failedJobs->isEmpty()) {
            return back()->with(
                'success',
                'No hay trabajos fallidos para reintentar.'
            );
        }

        $unsafeJobs = $failedJobs->filter(function ($job) {
            $name = $this->jobName($job->payload);

            $diagnosis = $this->interpreter->analyze(
                exception: (string) $job->exception,
                jobName: $name,
            );

            return ! $diagnosis['retry_safe'];
        });

        if ($unsafeJobs->isNotEmpty()) {
            return back()->with(
                'error',
                sprintf(
                    'Reintento masivo bloqueado: %d trabajo(s) requieren revisión antes de volver a ejecutarse.',
                    $unsafeJobs->count()
                )
            );
        }

        try {
            Artisan::call('queue:retry', [
                'id' => ['all'],
            ]);

            return back()->with(
                'success',
                'Todos los trabajos seguros fueron reenviados.'
            );
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'No fue posible reintentar los trabajos.'
            );
        }
    }

    public function destroy(string $uuid): RedirectResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return back()->with(
                'error',
                'La tabla de trabajos fallidos no está disponible.'
            );
        }

        $deleted = DB::table('failed_jobs')
            ->where('uuid', $uuid)
            ->delete();

        if ($deleted === 0) {
            return back()->with(
                'error',
                'El trabajo fallido ya no existe.'
            );
        }

        return back()->with(
            'success',
            'Trabajo fallido eliminado.'
        );
    }

    public function flush(): RedirectResponse
    {
        try {
            Artisan::call('queue:flush');

            return back()->with(
                'success',
                'La lista de trabajos fallidos fue vaciada.'
            );
        } catch (Throwable $e) {
            report($e);

            return back()->with(
                'error',
                'No fue posible vaciar los trabajos fallidos.'
            );
        }
    }

    private function jobName(?string $payload): string
    {
        if (! $payload) {
            return 'Trabajo desconocido';
        }

        $decoded = json_decode($payload, true);
        $displayName = $decoded['displayName']
            ?? $decoded['job']
            ?? null;

        if (! is_string($displayName) || $displayName === '') {
            return 'Trabajo desconocido';
        }

        return class_basename($displayName);
    }

    private function timestamp(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return now()
            ->setTimestamp((int) $value)
            ->toIso8601String();
    }

    private function exceptionPreview(string $exception): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($exception));

        if (! is_array($lines)) {
            return mb_substr(trim($exception), 0, 300);
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '') {
                return mb_substr($line, 0, 300);
            }
        }

        return 'No se encontró un mensaje técnico.';
    }
}