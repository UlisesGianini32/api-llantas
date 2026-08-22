<?php

namespace App\Services\Autopartes\Ai;

use App\Models\AutomotivePartAiRun;
use App\Models\AutomotivePartEnrichmentReview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

class OpenAiAutomotivePartEnrichmentService
{
    public function __construct(
        private AutomotivePartAiConfiguration $configuration,
        private AutomotivePartAiEligibility $eligibility,
        private AutomotivePartAiFingerprint $fingerprint,
        private AutomotivePartAiPromptBuilder $promptBuilder,
        private AutomotivePartAiResponseValidator $validator,
        private AutomotivePartAiErrorSanitizer $sanitizer,
        private OpenAiResponsesClient $client,
    ) {}

    public function execute(AutomotivePartAiRun $run): AutomotivePartAiRun
    {
        $startedAt = microtime(true);
        $run->loadMissing(['automotivePart', 'enrichmentReview.automotivePart']);

        if ($run->status === 'completed') {
            return $run;
        }

        $run->forceFill([
            'status' => 'processing',
            'attempt_count' => $run->attempt_count + 1,
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            $this->configuration->assertReady();
        } catch (AutomotivePartAiException $exception) {
            return $this->finishWithError($run, 'skipped', $exception->errorCode, $exception->getMessage(), $startedAt);
        }

        $review = $run->enrichmentReview;
        if ($review === null || $review->automotivePart === null) {
            return $this->finishWithError($run, 'skipped', 'missing_review', 'La revisión o la autoparte ya no existe.', $startedAt);
        }

        if ($reason = $this->eligibility->reason($review)) {
            return $this->finishWithError($run, 'skipped', 'not_eligible', $reason, $startedAt);
        }

        if (! hash_equals($run->request_fingerprint, $this->currentFingerprint($review, $run))) {
            return $this->finishWithError($run, 'skipped', 'stale_fingerprint', 'La revisión cambió después de crear el job.', $startedAt);
        }

        $request = $this->promptBuilder->requestPayload($run->input_snapshot, $run->model);

        try {
            $response = $this->client->create($request);
        } catch (ConnectionException $exception) {
            $message = $this->sanitizer->sanitize('Timeout o error de conexión con OpenAI: '.$exception->getMessage());
            $this->finishWithError($run, 'failed', 'connection_error', $message, $startedAt);

            throw new AutomotivePartAiException($message, 'connection_error', true, previous: $exception);
        }

        if (! $response->successful()) {
            $exception = $this->httpException($response);
            $this->finishWithError($run, 'failed', $exception->errorCode, $exception->getMessage(), $startedAt);

            if ($exception->transient) {
                throw $exception;
            }

            return $run->fresh();
        }

        $responseData = $response->json();
        if (! is_array($responseData)) {
            return $this->finishWithError($run, 'failed', 'invalid_response', 'OpenAI devolvió una respuesta HTTP sin un objeto JSON válido.', $startedAt);
        }

        $this->storeResponseMetadata($run, $responseData);

        if (($responseData['status'] ?? null) === 'failed') {
            $code = is_string(data_get($responseData, 'error.code')) ? data_get($responseData, 'error.code') : 'response_failed';
            $message = is_string(data_get($responseData, 'error.message'))
                ? data_get($responseData, 'error.message')
                : 'OpenAI marcó la respuesta como fallida.';
            $message = $this->sanitizer->sanitize($message);
            $this->finishWithError($run, 'failed', $code, $message, $startedAt);

            if (in_array($code, ['server_error', 'rate_limit_exceeded'], true)) {
                throw new AutomotivePartAiException($message, $code, true);
            }

            return $run->fresh();
        }

        if (($responseData['status'] ?? null) === 'incomplete') {
            return $this->finishWithError($run, 'failed', 'incomplete_response', 'OpenAI devolvió una respuesta incompleta.', $startedAt);
        }

        if ($refusal = $this->extractRefusal($responseData)) {
            return $this->finishWithError($run, 'refused', 'refusal', $this->sanitizer->sanitize($refusal), $startedAt);
        }

        $outputText = $this->extractOutputText($responseData);
        if ($outputText === null) {
            return $this->finishWithError($run, 'failed', 'missing_output', 'La respuesta no contiene salida estructurada.', $startedAt);
        }

        try {
            $output = json_decode($outputText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->finishWithError($run, 'failed', 'invalid_json', 'La salida estructurada no contiene JSON válido.', $startedAt);
        }

        if (! is_array($output) || array_is_list($output)) {
            return $this->finishWithError($run, 'failed_validation', 'invalid_schema', 'La salida estructurada no es un objeto JSON.', $startedAt, is_array($output) ? $output : null);
        }

        $run->forceFill(['output_payload' => $output])->save();
        $validationErrors = $this->validator->validate($output, $run->input_snapshot);
        if ($validationErrors !== []) {
            return $this->finishWithError(
                $run,
                'failed_validation',
                'failed_validation',
                implode(' ', array_slice($validationErrors, 0, 10)),
                $startedAt,
                $output,
            );
        }

        return $this->applyProposal($run, $output, $startedAt);
    }

    private function applyProposal(AutomotivePartAiRun $run, array $output, float $startedAt): AutomotivePartAiRun
    {
        return DB::transaction(function () use ($run, $output, $startedAt) {
            $lockedRun = AutomotivePartAiRun::query()->lockForUpdate()->findOrFail($run->id);
            $review = AutomotivePartEnrichmentReview::query()
                ->with('automotivePart')
                ->lockForUpdate()
                ->find($lockedRun->automotive_part_enrichment_review_id);

            if ($review === null || $review->automotivePart === null) {
                return $this->finishWithError($lockedRun, 'skipped', 'missing_review', 'La revisión o la autoparte ya no existe.', $startedAt);
            }

            if ($reason = $this->eligibility->reason($review)) {
                return $this->finishWithError($lockedRun, 'skipped', 'not_eligible', $reason, $startedAt, $output);
            }

            if (! hash_equals($lockedRun->request_fingerprint, $this->currentFingerprint($review, $lockedRun))) {
                return $this->finishWithError($lockedRun, 'skipped', 'stale_fingerprint', 'La revisión cambió durante la ejecución.', $startedAt, $output);
            }

            $existingMetadata = $review->metadata ?? [];
            if ($review->enrichment_source === 'rules' && ! isset($existingMetadata['rules_snapshot'])) {
                $existingMetadata['rules_snapshot'] = [
                    'proposed_title' => $review->proposed_title,
                    'proposed_description' => $review->proposed_description,
                    'proposed_brand' => $review->proposed_brand,
                    'proposed_category' => $review->proposed_category,
                    'proposed_compatibility' => $review->proposed_compatibility,
                    'proposed_attributes' => $review->proposed_attributes,
                ];
            }

            $metadata = array_merge($existingMetadata, [
                'last_ai_run_id' => $lockedRun->id,
                'ai_model' => $lockedRun->model,
                'ai_prompt_version' => $lockedRun->prompt_version,
                'ai_warnings' => $output['warnings'],
                'ai_missing_facts' => $output['missing_facts'],
                'ai_source_basis' => $output['source_basis'],
            ]);

            $review->forceFill([
                'status' => 'pending',
                'proposed_title' => $output['title_es'],
                'proposed_description' => $output['description_es'],
                'proposed_brand' => $output['brand_normalized'],
                'proposed_category' => $output['category_suggestion'],
                'proposed_compatibility' => $output['compatibility'],
                'proposed_attributes' => $output['attributes'],
                'confidence_score' => $output['confidence'],
                'enrichment_source' => 'openai',
                'reviewed_by' => null,
                'reviewed_at' => null,
                'metadata' => $metadata,
            ])->save();

            $lockedRun->forceFill([
                'status' => 'completed',
                'output_payload' => $output,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            $this->logRun($lockedRun, $startedAt);

            return $lockedRun->fresh();
        });
    }

    private function finishWithError(
        AutomotivePartAiRun $run,
        string $status,
        string $errorCode,
        string $message,
        float $startedAt,
        ?array $output = null,
    ): AutomotivePartAiRun {
        $run->forceFill([
            'status' => $status,
            'output_payload' => $output ?? $run->output_payload,
            'error_code' => $errorCode,
            'error_message' => $this->sanitizer->sanitize($message),
            'completed_at' => now(),
        ])->save();

        $this->logRun($run, $startedAt);

        return $run->fresh();
    }

    private function storeResponseMetadata(AutomotivePartAiRun $run, array $response): void
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $run->forceFill([
            'response_id' => is_string($response['id'] ?? null) ? $response['id'] : null,
            'input_tokens' => $this->nullableInteger($usage['input_tokens'] ?? null),
            'output_tokens' => $this->nullableInteger($usage['output_tokens'] ?? null),
            'total_tokens' => $this->nullableInteger($usage['total_tokens'] ?? null),
        ])->save();
    }

    private function extractOutputText(array $response): ?string
    {
        if (is_string($response['output_text'] ?? null) && $response['output_text'] !== '') {
            return $response['output_text'];
        }

        foreach ($response['output'] ?? [] as $output) {
            foreach (is_array($output['content'] ?? null) ? $output['content'] : [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    private function extractRefusal(array $response): ?string
    {
        foreach ($response['output'] ?? [] as $output) {
            foreach (is_array($output['content'] ?? null) ? $output['content'] : [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    return is_string($content['refusal'] ?? null) ? $content['refusal'] : 'OpenAI rechazó generar la propuesta.';
                }
            }
        }

        return null;
    }

    private function httpException(Response $response): AutomotivePartAiException
    {
        $status = $response->status();
        $apiCode = $response->json('error.code');
        $apiCode = is_string($apiCode) && $apiCode !== '' ? $apiCode : 'http_'.$status;
        $transientCodes = ['server_error', 'rate_limit_exceeded', 'response_in_progress', 'lock_timeout', 'conflict'];
        $transient = in_array($status, [408, 429, 500, 502, 503, 504], true)
            || ($status === 409 && in_array($apiCode, $transientCodes, true));
        $retryAfter = $this->retryAfterSeconds($response->header('Retry-After'));
        $apiMessage = $response->json('error.message');
        $message = is_string($apiMessage) && $apiMessage !== ''
            ? "OpenAI respondió HTTP {$status}: {$apiMessage}"
            : "OpenAI respondió HTTP {$status}.";

        return new AutomotivePartAiException(
            $this->sanitizer->sanitize($message),
            $apiCode,
            $transient,
            $transient ? $retryAfter : null,
        );
    }

    private function retryAfterSeconds(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (ctype_digit(trim($value))) {
            return max(1, min(3600, (int) trim($value)));
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(1, min(3600, $timestamp - time()));
    }

    private function currentFingerprint(AutomotivePartEnrichmentReview $review, AutomotivePartAiRun $run): string
    {
        return $this->fingerprint->make($review->automotivePart, $review, $run->model, $run->prompt_version);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function logRun(AutomotivePartAiRun $run, float $startedAt): void
    {
        $context = [
            'automotive_part_id' => $run->automotive_part_id,
            'review_id' => $run->automotive_part_enrichment_review_id,
            'ai_run_id' => $run->id,
            'response_id' => $run->response_id,
            'status' => $run->status,
            'model' => $run->model,
            'prompt_version' => $run->prompt_version,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'total_tokens' => $run->total_tokens,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_code' => $run->error_code,
        ];

        if ($run->status === 'completed') {
            Log::info('Automotive part AI enrichment completed.', $context);
        } else {
            Log::warning('Automotive part AI enrichment did not complete.', $context);
        }
    }
}
