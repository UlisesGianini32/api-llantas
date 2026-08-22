<?php

namespace App\Jobs;

use App\Models\AutomotivePartAiRun;
use App\Services\Autopartes\Ai\AutomotivePartAiErrorSanitizer;
use App\Services\Autopartes\Ai\AutomotivePartAiException;
use App\Services\Autopartes\Ai\OpenAiAutomotivePartEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateAutomotivePartEnrichmentWithAiJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly int $aiRunId,
        public readonly string $requestFingerprint,
    ) {
        $this->tries = max(1, (int) config('autopartes_ai.max_retries', 3));
        $this->timeout = max(1, (int) config('autopartes_ai.timeout', 60)) + 15;
        $this->onQueue('autopartes-ai');
    }

    public function uniqueId(): string
    {
        return 'autopartes-ai:'.$this->requestFingerprint;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        $delays = [];
        for ($attempt = 0; $attempt < max(1, $this->tries - 1); $attempt++) {
            $base = min(900, 10 * (2 ** $attempt));
            $delays[] = $base + random_int(0, max(1, (int) floor($base / 2)));
        }

        return $delays;
    }

    public function handle(OpenAiAutomotivePartEnrichmentService $service): void
    {
        $run = AutomotivePartAiRun::query()->find($this->aiRunId);
        if ($run === null || ! hash_equals($run->request_fingerprint, $this->requestFingerprint)) {
            return;
        }

        try {
            $service->execute($run);
        } catch (AutomotivePartAiException $exception) {
            if (! $exception->transient) {
                return;
            }

            if ($exception->retryAfter !== null && $this->attempts() < $this->tries) {
                $this->release($exception->retryAfter);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = AutomotivePartAiRun::query()->find($this->aiRunId);
        if ($run === null || $run->status === 'completed') {
            return;
        }

        $sanitizer = app(AutomotivePartAiErrorSanitizer::class);
        $run->forceFill([
            'status' => 'failed',
            'error_code' => $run->error_code ?? 'job_failed',
            'error_message' => $sanitizer->sanitize($exception?->getMessage() ?? 'El job agotó sus reintentos.'),
            'completed_at' => now(),
        ])->save();
    }
}
