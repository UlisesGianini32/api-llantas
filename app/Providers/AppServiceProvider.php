<?php

namespace App\Providers;

use App\Services\TelegramAlertService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::failing(function (JobFailed $event): void {
            /** @var TelegramAlertService $alerts */
            $alerts = app(TelegramAlertService::class);

            $ex = $event->exception;
            $alerts->notifyQueueFailure(
                connection: (string) $event->connectionName,
                queue: (string) ($event->job->getQueue() ?: 'default'),
                jobName: (string) $event->job->resolveName(),
                exceptionMessage: $ex?->getMessage(),
                exceptionClass: $ex !== null ? $ex::class : null
            );
        });
    }
}
