<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateAutomotivePartEnrichmentWithAiJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartAiRun;
use App\Models\AutomotivePartEnrichmentReview;
use App\Models\User;
use App\Services\Autopartes\Ai\AutomotivePartAiDispatchService;
use App\Services\Autopartes\Ai\AutomotivePartAiException;
use App\Services\Autopartes\Ai\AutomotivePartAiFingerprint;
use App\Services\Autopartes\Ai\OpenAiAutomotivePartEnrichmentService;
use App\Services\Autopartes\Ai\OpenAiResponsesClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutomotivePartAiEnrichmentTest extends TestCase
{
    private array $migrations = [];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('autopartes_ai.enabled', true);
        config()->set('autopartes_ai.api_key', str_repeat('x', 32));
        config()->set('autopartes_ai.model', 'gpt-5.6');
        config()->set('autopartes_ai.prompt_version', 'v1');
        config()->set('autopartes_ai.max_batch', 10);
        config()->set('autopartes_ai.max_daily_items', 50);
        config()->set('autopartes_ai.title_max_chars', 60);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        $this->withoutMiddleware(HandleInertiaRequests::class);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        foreach ([
            '2026_08_21_000001_create_automotive_part_tables.php',
            '2026_08_22_000001_create_automotive_part_enrichment_reviews_table.php',
            '2026_08_22_000002_create_automotive_part_ai_runs_table.php',
        ] as $migration) {
            $instance = require database_path('migrations/'.$migration);
            $instance->up();
            $this->migrations[] = $instance;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_disabled_or_missing_configuration_never_calls_openai(): void
    {
        $review = $this->createReview();
        $dispatcher = app(AutomotivePartAiDispatchService::class);

        foreach ([['enabled' => false], ['api_key' => null]] as $override) {
            config()->set('autopartes_ai.enabled', true);
            config()->set('autopartes_ai.api_key', str_repeat('x', 32));
            foreach ($override as $key => $value) {
                config()->set('autopartes_ai.'.$key, $value);
            }

            try {
                $dispatcher->dispatchReview($review);
                $this->fail('Se esperaba un error de configuración.');
            } catch (AutomotivePartAiException $exception) {
                $this->assertFalse($exception->transient);
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('automotive_part_ai_runs', 0);
    }

    public function test_dry_run_does_not_call_openai_enqueue_or_modify_the_review(): void
    {
        Queue::fake();
        config()->set('autopartes_ai.enabled', false);
        config()->set('autopartes_ai.api_key', null);
        $review = $this->createReview();
        $originalTitle = $review->proposed_title;

        $exitCode = Artisan::call('autopartes:ai-enrich', [
            '--review-id' => $review->id,
            '--limit' => 1,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dry-run', Artisan::output());
        $this->assertSame($originalTitle, $review->fresh()->proposed_title);
        $this->assertDatabaseCount('automotive_part_ai_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_valid_response_uses_responses_structured_outputs_and_updates_only_review(): void
    {
        $review = $this->createReview();
        $run = $this->createRun($review);
        $originalPart = $review->automotivePart->getAttributes();
        Http::fake([OpenAiResponsesClient::ENDPOINT => Http::response($this->responsePayload($this->validOutput($review)), 200)]);

        $result = app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);

        $this->assertSame('completed', $result->status);
        $this->assertSame('resp_test', $result->response_id);
        $this->assertSame(120, $result->input_tokens);
        $this->assertSame(80, $result->output_tokens);
        $this->assertSame(200, $result->total_tokens);
        $this->assertSame('openai', $review->fresh()->enrichment_source);
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertSame('Balata de freno ACME MFG-1', $review->fresh()->proposed_title);
        $this->assertSame($originalPart, $review->automotivePart->fresh()->getAttributes());

        Http::assertSent(function (Request $request) {
            $format = $request->data()['text']['format'] ?? [];

            return $request->url() === OpenAiResponsesClient::ENDPOINT
                && $request->method() === 'POST'
                && ($request->data()['model'] ?? null) === 'gpt-5.6'
                && ($format['type'] ?? null) === 'json_schema'
                && ($format['strict'] ?? null) === true
                && data_get($format, 'schema.additionalProperties') === false
                && data_get($format, 'schema.properties.compatibility.items.additionalProperties') === false;
        });
    }

    public function test_manual_and_final_reviews_are_never_overwritten(): void
    {
        foreach ([
            ['status' => 'pending', 'enrichment_source' => 'manual'],
            ['status' => 'approved', 'enrichment_source' => 'rules'],
            ['status' => 'rejected', 'enrichment_source' => 'rules'],
        ] as $state) {
            $review = $this->createReview($state);
            $run = $this->createRun($review);
            $title = $review->proposed_title;

            app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);

            $this->assertSame('skipped', $run->fresh()->status);
            $this->assertSame($title, $review->fresh()->proposed_title);
        }

        Http::assertNothingSent();
    }

    public function test_fingerprint_prevents_duplicates_and_force_requeues_failed_run(): void
    {
        Queue::fake();
        $review = $this->createReview();
        $dispatcher = app(AutomotivePartAiDispatchService::class);

        $first = $dispatcher->dispatchReview($review);
        $second = $dispatcher->dispatchReview($review);
        $this->assertTrue($first['queued']);
        $this->assertFalse($second['queued']);
        $this->assertDatabaseCount('automotive_part_ai_runs', 1);

        $run = AutomotivePartAiRun::query()->firstOrFail();
        $run->update(['status' => 'failed', 'error_code' => 'http_500']);
        $forced = $dispatcher->dispatchReview($review, true);

        $this->assertTrue($forced['queued']);
        $this->assertSame($run->id, $forced['run_id']);
        $this->assertDatabaseCount('automotive_part_ai_runs', 1);
        Queue::assertPushed(GenerateAutomotivePartEnrichmentWithAiJob::class, 1);
    }

    public function test_refusal_invalid_json_and_schema_mismatch_are_recorded_without_application(): void
    {
        $cases = [
            ['response' => ['id' => 'resp_refused', 'output' => [['content' => [['type' => 'refusal', 'refusal' => 'No puedo ayudar.']]]]], 'status' => 'refused'],
            ['response' => ['id' => 'resp_invalid', 'output_text' => '{not-json'], 'status' => 'failed'],
            ['response' => ['id' => 'resp_schema', 'output_text' => json_encode(['language' => 'es-MX'])], 'status' => 'failed_validation'],
        ];

        $sequence = Http::sequence();
        foreach ($cases as $case) {
            $sequence->push($case['response'], 200);
        }
        Http::fake([OpenAiResponsesClient::ENDPOINT => $sequence]);

        foreach ($cases as $case) {
            $review = $this->createReview();
            $run = $this->createRun($review);

            app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);

            $this->assertSame($case['status'], $run->fresh()->status);
            $this->assertSame('rules', $review->fresh()->enrichment_source);
        }
    }

    public function test_hallucinated_compatibility_years_and_part_number_are_rejected(): void
    {
        foreach (['model', 'year', 'part_number'] as $mutation) {
            $review = $this->createReview();
            $run = $this->createRun($review);
            $output = $this->validOutput($review);
            if ($mutation === 'model') {
                $output['compatibility'][0]['model'] = 'Vehículo inventado';
            } elseif ($mutation === 'year') {
                $output['compatibility'][0]['year_from'] = 2010;
            } else {
                $output['manufacturer_part_number'] = 'ALTERADO';
            }
            Http::fake([OpenAiResponsesClient::ENDPOINT => Http::response($this->responsePayload($output), 200)]);

            app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);

            $this->assertSame('failed_validation', $run->fresh()->status);
            $this->assertSame('rules', $review->fresh()->enrichment_source);
        }
    }

    public function test_transient_http_errors_are_distinguished_from_authentication_errors(): void
    {
        $sequence = Http::sequence()
            ->push(['error' => ['code' => 'server_error', 'message' => 'Temporary']], 429)
            ->push(['error' => ['code' => 'server_error', 'message' => 'Temporary']], 500)
            ->push(['error' => ['code' => 'invalid_api_key', 'message' => 'Unauthorized']], 401);
        Http::fake([OpenAiResponsesClient::ENDPOINT => $sequence]);

        foreach ([429, 500] as $status) {
            $review = $this->createReview();
            $run = $this->createRun($review);

            try {
                app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);
                $this->fail("HTTP {$status} debió ser temporal.");
            } catch (AutomotivePartAiException $exception) {
                $this->assertTrue($exception->transient);
            }
        }

        $review = $this->createReview();
        $run = $this->createRun($review);
        $result = app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);

        $this->assertSame('failed', $result->status);
        $this->assertSame('invalid_api_key', $result->error_code);
    }

    public function test_error_messages_and_logs_do_not_expose_credentials(): void
    {
        $secret = str_repeat('s', 32);
        config()->set('autopartes_ai.api_key', $secret);
        $review = $this->createReview();
        $run = $this->createRun($review);
        Log::spy();
        Http::fake([OpenAiResponsesClient::ENDPOINT => Http::response([
            'error' => [
                'code' => 'bad_request',
                'message' => 'authorization='.$secret.' api_key='.$secret,
            ],
        ], 400)]);

        $result = app(OpenAiAutomotivePartEnrichmentService::class)->execute($run);

        $this->assertStringNotContainsString($secret, $result->error_message);
        $this->assertStringContainsString('[REDACTED]', $result->error_message);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($secret) {
            return ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $secret);
        });
    }

    public function test_batch_and_daily_limits_are_enforced(): void
    {
        $review = $this->createReview();
        $this->createReview();
        config()->set('autopartes_ai.max_batch', 1);
        config()->set('autopartes_ai.max_daily_items', 1);
        Queue::fake();
        $dispatcher = app(AutomotivePartAiDispatchService::class);

        $stats = $dispatcher->dispatchBatch(1);
        $this->assertSame(1, $stats['queued']);
        $this->assertSame(0, $dispatcher->dailyRemaining());
        $this->assertFalse($dispatcher->dispatchReview($review->fresh())['queued']);

        $this->assertSame(1, Artisan::call('autopartes:ai-enrich', ['--limit' => 2]));
        $this->assertStringContainsString('no puede superar', Artisan::output());
    }

    public function test_ai_endpoints_require_authentication_and_validate_batch_and_review_state(): void
    {
        $review = $this->createReview();
        $this->post(route('autopartes.enrichment.ai.generate', $review))->assertRedirect('/login');
        $this->get(route('autopartes.enrichment.ai.history', $review))->assertRedirect('/login');

        $user = User::query()->create([
            'name' => 'Reviewer',
            'email' => 'ai-reviewer@example.test',
            'password' => 'password',
        ]);
        $this->actingAs($user)
            ->post(route('autopartes.enrichment.ai.batch'), ['limit' => 11])
            ->assertSessionHasErrors('limit');

        $review->update(['enrichment_source' => 'manual']);
        $this->post(route('autopartes.enrichment.ai.generate', $review))
            ->assertSessionHasErrors('review');
    }

    public function test_human_edit_of_ai_proposal_changes_source_to_manual(): void
    {
        $review = $this->createReview(['enrichment_source' => 'openai']);
        $user = User::query()->create([
            'name' => 'Reviewer',
            'email' => 'manual-reviewer@example.test',
            'password' => 'password',
        ]);

        $this->actingAs($user)->put(route('autopartes.enrichment.update', $review), [
            'proposed_title' => 'Título ajustado por una persona',
            'proposed_description' => 'Descripción revisada manualmente.',
            'proposed_brand' => 'ACME',
            'proposed_category' => 'Frenos',
            'proposed_compatibility' => '[]',
            'proposed_attributes' => '[]',
            'confidence_score' => 0.9,
        ])->assertSessionHasNoErrors();

        $this->assertSame('manual', $review->fresh()->enrichment_source);
        $this->assertSame('in_review', $review->fresh()->status);
    }

    private function createReview(array $attributes = []): AutomotivePartEnrichmentReview
    {
        $this->sequence++;
        $part = AutomotivePart::query()->create([
            'source_key' => 'ai-part-'.$this->sequence,
            'item_number' => 'ITEM-'.$this->sequence,
            'manufacturer_part_number' => 'MFG-'.$this->sequence,
            'vendor' => 'ACME',
            'vendor_normalized' => 'acme',
            'category' => 'BRAKES',
            'description_original' => 'Brake pad for Modelo X',
            'description_normalized' => 'brake pad for modelo x',
            'quantity' => 2,
            'retail_price_original' => 25,
            'min_model_year' => 2020,
            'average_model_year' => 2022,
            'max_model_year' => 2024,
            'prevalent_model' => 'Modelo X',
            'applicable_models_text' => 'Modelo X 2020-2024',
            'length_inches' => 1,
            'width_inches' => 2,
            'height_inches' => 3,
            'weight_pounds' => 4,
            'length_cm' => 2.54,
            'width_cm' => 5.08,
            'height_cm' => 7.62,
            'weight_kg' => 1.8144,
            'data_status' => 'imported',
        ]);

        return AutomotivePartEnrichmentReview::query()->create(array_merge([
            'automotive_part_id' => $part->id,
            'status' => 'pending',
            'issue_codes' => ['needs_spanish_content'],
            'proposed_title' => 'Brake pad ACME',
            'enrichment_source' => 'rules',
        ], $attributes))->load('automotivePart');
    }

    private function createRun(AutomotivePartEnrichmentReview $review): AutomotivePartAiRun
    {
        $review->loadMissing('automotivePart');
        $fingerprint = app(AutomotivePartAiFingerprint::class)->make(
            $review->automotivePart,
            $review,
            'gpt-5.6',
            'v1',
        );

        return AutomotivePartAiRun::query()->create([
            'automotive_part_id' => $review->automotive_part_id,
            'automotive_part_enrichment_review_id' => $review->id,
            'status' => 'queued',
            'model' => 'gpt-5.6',
            'prompt_version' => 'v1',
            'request_fingerprint' => $fingerprint,
            'input_snapshot' => app(\App\Services\Autopartes\Ai\AutomotivePartAiPromptBuilder::class)
                ->inputSnapshot($review->automotivePart, $review),
        ]);
    }

    private function validOutput(AutomotivePartEnrichmentReview $review): array
    {
        return [
            'language' => 'es-MX',
            'title_es' => 'Balata de freno ACME MFG-'.$review->automotive_part_id,
            'description_es' => 'Balata de freno para Modelo X, basada exclusivamente en los datos disponibles del catálogo.',
            'brand_normalized' => 'ACME',
            'manufacturer_part_number' => 'MFG-'.$review->automotive_part_id,
            'category_suggestion' => 'Sistema de frenos',
            'compatibility' => [[
                'make' => null,
                'model' => 'Modelo X',
                'year_from' => 2020,
                'year_to' => 2024,
                'notes' => null,
            ]],
            'attributes' => [[
                'name' => 'Peso',
                'value' => '4',
                'unit' => 'lb',
                'source_field' => 'weight_pounds',
            ]],
            'missing_facts' => [],
            'warnings' => [],
            'source_basis' => ['description_original', 'manufacturer_part_number', 'applicable_models_text'],
            'confidence' => 0.88,
        ];
    }

    private function responsePayload(array $output): array
    {
        return [
            'id' => 'resp_test',
            'output_text' => json_encode($output, JSON_THROW_ON_ERROR),
            'output' => [],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ];
    }
}
