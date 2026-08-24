<?php

namespace App\Jobs;

use App\Models\AutomotivePart;
use App\Services\Autopartes\Drafts\AutomotivePartDraftBuilder;
use App\Services\Autopartes\Drafts\AutomotivePartDraftException;
use App\Services\Autopartes\Drafts\AutomotivePartDraftGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAutomotivePartMeliDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $automotivePartId,
        public readonly string $expectedFingerprint,
        public readonly bool $force = false,
    ) {
        $this->onQueue('autopartes-meli-drafts');
    }

    public function uniqueId(): string
    {
        return 'autopartes-meli-draft:'.$this->automotivePartId.':'.$this->expectedFingerprint;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(AutomotivePartDraftBuilder $builder, AutomotivePartDraftGenerator $generator): void
    {
        $part = AutomotivePart::query()->find($this->automotivePartId);
        if ($part === null) {
            return;
        }

        $preview = $builder->preview($part);
        if (! hash_equals($this->expectedFingerprint, $preview['fingerprint'])) {
            Log::info('Automotive part draft job skipped stale source data.', [
                'automotive_part_id' => $part->id,
            ]);

            return;
        }

        try {
            $result = $generator->generate($part, $this->force);
            Log::info('Automotive part internal draft generation completed.', [
                'automotive_part_id' => $part->id,
                'draft_id' => $result['draft']->id,
                'created' => $result['created'],
                'status' => $result['draft']->status,
            ]);
        } catch (AutomotivePartDraftException $exception) {
            Log::warning('Automotive part internal draft generation failed.', [
                'automotive_part_id' => $part->id,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }
    }
}
