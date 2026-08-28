<?php

namespace App\Jobs;

use App\Models\AutomotivePart;
use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySuggestionService;
use App\Services\Autopartes\Meli\AutomotivePartMeliErrorSanitizer;
use App\Services\Autopartes\Meli\AutomotivePartMeliException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MapAutomotivePartToMeliCategoriesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public function __construct(
        public readonly int $automotivePartId,
        public readonly string $queryFingerprint,
        public readonly bool $refreshMetadata = false,
        public readonly bool $force = false,
    ) {
        $this->timeout = max(1, (int) config('autopartes_meli.timeout', 20)) * 8;
        $this->onQueue('autopartes-meli-mapping');
    }

    public function uniqueId(): string
    {
        return 'autopartes-meli:'.$this->automotivePartId.':'.$this->queryFingerprint;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        return [10 + random_int(0, 5), 30 + random_int(0, 15), 90 + random_int(0, 30)];
    }

    public function handle(AutomotivePartMeliCategorySuggestionService $suggestions): void
    {
        $part = AutomotivePart::query()->with('enrichmentReview')->find($this->automotivePartId);
        if ($part === null) {
            return;
        }

        $preview = $suggestions->preview($part);
        $currentFingerprint = hash('sha256', json_encode([
            'part_id' => $part->id,
            'part_updated_at' => $part->updated_at?->toJSON(),
            'review_updated_at' => $part->enrichmentReview?->updated_at?->toJSON(),
            'query' => $preview['query'],
            'rules_version' => $preview['rules_version'],
        ], JSON_THROW_ON_ERROR));
        if (! hash_equals($this->queryFingerprint, $currentFingerprint)) {
            Log::info('Automotive part Mercado Libre mapping job skipped stale input.', [
                'automotive_part_id' => $this->automotivePartId,
            ]);

            return;
        }

        try {
            $suggestions->suggest($part, $this->refreshMetadata, $this->force);
        } catch (AutomotivePartMeliException $exception) {
            Log::warning('Automotive part Mercado Libre mapping job failed.', [
                'automotive_part_id' => $this->automotivePartId,
                'error_code' => $exception->errorCode,
                'transient' => $exception->transient,
            ]);

            if ($exception->transient) {
                if ($exception->retryAfter !== null && $this->attempts() < $this->tries) {
                    $this->release($exception->retryAfter);

                    return;
                }

                throw $exception;
            }

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $sanitizer = app(AutomotivePartMeliErrorSanitizer::class);
        Log::error('Automotive part Mercado Libre mapping job exhausted retries.', [
            'automotive_part_id' => $this->automotivePartId,
            'error' => $sanitizer->sanitize($exception?->getMessage() ?? 'Error sin mensaje.'),
        ]);
    }
}
