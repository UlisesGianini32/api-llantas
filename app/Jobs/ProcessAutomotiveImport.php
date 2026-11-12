<?php

namespace App\Jobs;

use App\Services\Autopartes\AutomotiveImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAutomotiveImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId)
    {
        $this->onConnection(config('autopartes.queue_connection'));
        $this->onQueue(config('autopartes.queue'));
    }

    public function handle(AutomotiveImportProcessor $processor): void
    {
        if ($processor->processBatch($this->runId)) {
            self::dispatch($this->runId);
        }
    }
}
