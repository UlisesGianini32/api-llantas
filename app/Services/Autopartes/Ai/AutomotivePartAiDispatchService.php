<?php

namespace App\Services\Autopartes\Ai;

use App\Jobs\GenerateAutomotivePartEnrichmentWithAiJob;
use App\Models\AutomotivePartAiRun;
use App\Models\AutomotivePartEnrichmentReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class AutomotivePartAiDispatchService
{
    public function __construct(
        private AutomotivePartAiConfiguration $configuration,
        private AutomotivePartAiEligibility $eligibility,
        private AutomotivePartAiFingerprint $fingerprint,
        private AutomotivePartAiPromptBuilder $promptBuilder,
    ) {}

    public function candidateQuery(
        ?int $partId = null,
        ?int $reviewId = null,
        ?string $issue = null,
    ): Builder {
        return AutomotivePartEnrichmentReview::query()
            ->with('automotivePart')
            ->where('status', 'pending')
            ->where('enrichment_source', '!=', 'manual')
            ->when($partId !== null, fn (Builder $query) => $query->where('automotive_part_id', $partId))
            ->when($reviewId !== null, fn (Builder $query) => $query->whereKey($reviewId))
            ->when($issue !== null, fn (Builder $query) => $query->whereJsonContains('issue_codes', $issue))
            ->orderBy('id');
    }

    public function preview(
        int $limit,
        ?int $partId = null,
        ?int $reviewId = null,
        ?string $issue = null,
    ): Collection {
        return $this->candidateQuery($partId, $reviewId, $issue)
            ->limit($limit)
            ->get()
            ->map(function (AutomotivePartEnrichmentReview $review) {
                return [
                    'review_id' => $review->id,
                    'automotive_part_id' => $review->automotive_part_id,
                    'item_number' => $review->automotivePart?->item_number,
                    'model' => $this->configuration->model(),
                    'prompt_version' => $this->configuration->promptVersion(),
                    'fingerprint' => $this->fingerprintFor($review),
                    'eligible' => $this->eligibility->reason($review) === null,
                    'reason' => $this->eligibility->reason($review),
                ];
            });
    }

    public function dispatchBatch(
        int $limit,
        ?int $partId = null,
        ?int $reviewId = null,
        ?string $issue = null,
        bool $force = false,
    ): array {
        $this->configuration->assertReady();

        $stats = ['candidates' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
        $reviews = $this->candidateQuery($partId, $reviewId, $issue)->limit($limit)->get();

        foreach ($reviews as $review) {
            $stats['candidates']++;

            if ($this->dailyRemaining() < 1) {
                $stats['skipped']++;
                $stats['details'][] = [
                    'review_id' => $review->id,
                    'status' => 'skipped',
                    'message' => 'Se alcanzó el límite diario de productos.',
                ];

                continue;
            }

            try {
                $result = $this->dispatchReview($review, $force);
                $stats[$result['queued'] ? 'queued' : 'skipped']++;
                $stats['details'][] = $result;
            } catch (\Throwable $exception) {
                $stats['errors']++;
                $stats['details'][] = [
                    'review_id' => $review->id,
                    'status' => 'error',
                    'message' => 'No fue posible preparar la ejecución de IA.',
                ];
            }
        }

        return $stats;
    }

    public function dispatchReview(AutomotivePartEnrichmentReview $review, bool $force = false): array
    {
        $this->configuration->assertReady();
        $review->loadMissing('automotivePart');

        if ($reason = $this->eligibility->reason($review)) {
            return $this->skippedResult($review, $reason);
        }

        if ($review->enrichment_source === 'openai' && ! $force) {
            return $this->skippedResult($review, 'La revisión ya tiene una propuesta de IA; usa regeneración explícita.');
        }

        if ($this->dailyRemaining() < 1) {
            return $this->skippedResult($review, 'Se alcanzó el límite diario de productos.');
        }

        $fingerprint = $this->fingerprintFor($review);
        $run = AutomotivePartAiRun::query()->where('request_fingerprint', $fingerprint)->first();

        if ($run !== null) {
            $retryableStatus = in_array($run->status, ['failed', 'failed_validation', 'refused', 'skipped', 'cancelled'], true);
            if (! $force || ! $retryableStatus) {
                return $this->skippedResult($review, 'Ya existe una ejecución para este fingerprint.', $run);
            }

            $run->forceFill([
                'status' => 'queued',
                'error_code' => null,
                'error_message' => null,
                'completed_at' => null,
            ])->save();
        } else {
            try {
                $run = AutomotivePartAiRun::query()->create([
                    'automotive_part_id' => $review->automotive_part_id,
                    'automotive_part_enrichment_review_id' => $review->id,
                    'status' => 'queued',
                    'model' => $this->configuration->model(),
                    'prompt_version' => $this->configuration->promptVersion(),
                    'request_fingerprint' => $fingerprint,
                    'input_snapshot' => $this->promptBuilder->inputSnapshot($review->automotivePart, $review),
                ]);
            } catch (QueryException $exception) {
                $run = AutomotivePartAiRun::query()->where('request_fingerprint', $fingerprint)->firstOrFail();

                return $this->skippedResult($review, 'Otra ejecución creó el mismo fingerprint.', $run);
            }
        }

        GenerateAutomotivePartEnrichmentWithAiJob::dispatch($run->id, $run->request_fingerprint);

        return [
            'review_id' => $review->id,
            'run_id' => $run->id,
            'status' => 'queued',
            'queued' => true,
            'message' => 'Ejecución encolada.',
        ];
    }

    public function dailyRemaining(): int
    {
        $used = AutomotivePartAiRun::query()
            ->whereDate('created_at', now()->toDateString())
            ->whereNotIn('status', ['skipped', 'cancelled'])
            ->count();

        return max(0, max(1, (int) config('autopartes_ai.max_daily_items', 50)) - $used);
    }

    public function fingerprintFor(AutomotivePartEnrichmentReview $review): string
    {
        $review->loadMissing('automotivePart');

        return $this->fingerprint->make(
            $review->automotivePart,
            $review,
            $this->configuration->model(),
            $this->configuration->promptVersion(),
        );
    }

    private function skippedResult(
        AutomotivePartEnrichmentReview $review,
        string $message,
        ?AutomotivePartAiRun $run = null,
    ): array {
        return [
            'review_id' => $review->id,
            'run_id' => $run?->id,
            'status' => 'skipped',
            'queued' => false,
            'message' => $message,
        ];
    }
}
