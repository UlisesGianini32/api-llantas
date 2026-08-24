<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateAutomotivePartMeliDraftJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;
use App\Models\AutomotivePartMeliAttributeRequirement;
use App\Models\AutomotivePartMeliCategory;
use App\Models\AutomotivePartMeliCategoryCandidate;
use App\Models\AutomotivePartMeliReadiness;
use App\Models\User;
use App\Services\Autopartes\Drafts\AutomotivePartDraftBuilder;
use App\Services\Autopartes\Drafts\AutomotivePartDraftException;
use App\Services\Autopartes\Drafts\AutomotivePartDraftGenerator;
use App\Services\Autopartes\Drafts\AutomotivePartDraftLocalOnlyGuard;
use App\Services\Autopartes\Drafts\AutomotivePartDraftReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutomotivePartMeliDraftTest extends TestCase
{
    private array $migrations = [];

    private int $sequence = 0;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('autopartes_drafts.enabled', true);
        config()->set('autopartes_drafts.usd_mxn_rate', 20);
        config()->set('autopartes_drafts.price_markup_percent', 10);
        config()->set('autopartes_drafts.meli_fee_percent', 10);
        config()->set('autopartes_drafts.max_batch', 2);
        config()->set('autopartes_drafts.condition', 'new');
        config()->set('autopartes_drafts.currency', 'MXN');
        config()->set('autopartes_drafts.images_by_source_key', []);
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
            '2026_08_22_000003_create_automotive_part_meli_mapping_tables.php',
            '2026_08_24_000001_create_automotive_part_meli_drafts_tables.php',
        ] as $migration) {
            $instance = require database_path('migrations/'.$migration);
            $instance->up();
            $this->migrations[] = $instance;
        }
        $this->user = User::query()->create([
            'name' => 'Draft Reviewer',
            'email' => 'draft-reviewer@example.test',
            'password' => 'password',
        ]);
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

    public function test_disabled_integration_blocks_persistence_but_dry_run_is_local(): void
    {
        $part = $this->eligiblePart();
        config()->set('autopartes_drafts.enabled', false);
        Queue::fake();

        try {
            app(AutomotivePartDraftGenerator::class)->generate($part);
            $this->fail('La integración deshabilitada debió bloquear la generación.');
        } catch (AutomotivePartDraftException $exception) {
            $this->assertSame('drafts_disabled', $exception->errorCode);
        }

        $this->assertSame(0, Artisan::call('autopartes:drafts-generate', [
            '--part-id' => $part->id,
            '--limit' => 1,
            '--dry-run' => true,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringContainsString('Fingerprint', $output);
        $this->assertDatabaseCount('automotive_part_meli_drafts', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_fingerprint_and_generation_are_deterministic_and_idempotent(): void
    {
        $part = $this->eligiblePart();
        $builder = app(AutomotivePartDraftBuilder::class);
        $firstPreview = $builder->preview($part);
        $secondPreview = $builder->preview($part->fresh());
        $generator = app(AutomotivePartDraftGenerator::class);
        $first = $generator->generate($part);
        $second = $generator->generate($part->fresh(), true);

        $this->assertSame($firstPreview['fingerprint'], $secondPreview['fingerprint']);
        $this->assertSame($first['draft']->id, $second['draft']->id);
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertDatabaseCount('automotive_part_meli_drafts', 1);

        app(AutomotivePartDraftReviewService::class)->reject($first['draft'], $this->user, 'No usar todavía');
        $again = $generator->generate($part->fresh());
        $this->assertSame('rejected', $again['draft']->status);
        $this->assertSame('No usar todavía', $again['draft']->review_notes);
        Http::assertNothingSent();
    }

    public function test_missing_rate_and_images_are_blocking_errors(): void
    {
        $part = $this->eligiblePart();
        config()->set('autopartes_drafts.usd_mxn_rate', null);
        config()->set('autopartes_drafts.images_by_source_key', []);

        $preview = app(AutomotivePartDraftBuilder::class)->preview($part);
        $codes = collect($preview['blocking_errors'])->pluck('code');

        $this->assertContains('missing_exchange_rate', $codes);
        $this->assertContains('missing_price_mxn', $codes);
        $this->assertContains('missing_images', $codes);
        $this->assertFalse($preview['eligible']);

        config()->set('autopartes_drafts.usd_mxn_rate', 20);
        config()->set('autopartes_drafts.currency', 'USD');
        config()->set('autopartes_drafts.condition', null);
        $configurationCodes = collect(app(AutomotivePartDraftBuilder::class)->preview($part)['blocking_errors'])->pluck('code');
        $this->assertContains('unsupported_currency', $configurationCodes);
        $this->assertContains('unsupported_condition', $configurationCodes);
    }

    public function test_explicit_pricing_rules_calculate_mxn_and_are_snapshotted(): void
    {
        $part = $this->eligiblePart(['retail_price_original' => 100]);
        $preview = app(AutomotivePartDraftBuilder::class)->preview($part);

        $this->assertSame(2444.44, $preview['payload']['price_mxn']);
        $this->assertSame('MXN', $preview['payload']['currency']);
        $this->assertSame(20.0, data_get($preview, 'source_snapshot.price.rules.usd_mxn_rate'));
        $this->assertSame(10.0, data_get($preview, 'source_snapshot.price.rules.price_markup_percent'));
        $this->assertTrue($preview['eligible']);
    }

    public function test_validator_detects_unapproved_sources_stock_attributes_and_compatibility(): void
    {
        $part = $this->eligiblePart();
        $part->enrichmentReview()->update(['status' => 'pending']);
        $part->meliCategoryCandidates()->update(['status' => 'pending']);
        $part->update(['quantity' => -1]);
        $part->meliReadiness()->update([
            'status' => 'incomplete',
            'proposed_attributes' => [],
            'compatibility_requirements' => ['required' => true],
        ]);

        $builder = app(AutomotivePartDraftBuilder::class);
        $codes = collect($builder->preview($part->fresh())['blocking_errors'])->pluck('code');

        $this->assertContains('missing_approved_enrichment', $codes);
        $this->assertContains('missing_approved_category', $codes);
        $this->assertContains('invalid_stock', $codes);
        $this->assertContains('readiness_not_ready', $codes);

        $part->enrichmentReview()->update(['status' => 'approved']);
        $candidate = $part->meliCategoryCandidates()->firstOrFail();
        $candidate->update(['status' => 'approved']);
        $codesWithCategory = collect($builder->preview($part->fresh())['blocking_errors'])->pluck('code');
        $this->assertContains('missing_required_attribute', $codesWithCategory);
        $this->assertContains('missing_compatibility', $codesWithCategory);
    }

    public function test_stale_or_invalid_category_and_content_are_detected(): void
    {
        $part = $this->eligiblePart();
        $part->enrichmentReview()->update(['proposed_title' => 'Corto', 'proposed_description' => 'Breve']);
        AutomotivePartMeliCategory::query()->update([
            'settings' => ['listing_allowed' => false],
            'attributes_synced_at' => null,
        ]);

        $preview = app(AutomotivePartDraftBuilder::class)->preview($part->fresh());
        $codes = collect($preview['blocking_errors'])->pluck('code');

        $this->assertContains('stale_category_mapping', $codes);
        $this->assertContains('invalid_title', $codes);
        $this->assertContains('invalid_description', $codes);
    }

    public function test_approval_is_blocked_by_errors_and_valid_approval_makes_no_http_calls(): void
    {
        $part = $this->eligiblePart();
        $generator = app(AutomotivePartDraftGenerator::class);
        $reviews = app(AutomotivePartDraftReviewService::class);
        $valid = $generator->generate($part)['draft'];

        $approved = $reviews->approve($valid, $this->user, 'Validación humana completa');
        $this->assertSame('approved', $approved->status);
        $this->assertSame($this->user->id, $approved->reviewed_by);
        $this->assertNotNull($approved->approved_at);
        Http::assertNothingSent();

        $brokenPart = $this->eligiblePart();
        config()->set('autopartes_drafts.images_by_source_key.'.$brokenPart->source_key, []);
        $broken = $generator->generate($brokenPart)['draft'];
        $broken->forceFill(['status' => 'pending_review'])->save();
        try {
            $reviews->approve($broken->fresh(), $this->user);
            $this->fail('Los errores bloqueantes debieron impedir la aprobación.');
        } catch (AutomotivePartDraftException $exception) {
            $this->assertSame('draft_has_blocking_errors', $exception->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_rejection_requires_note_and_history_survives_return_to_pending(): void
    {
        $draft = app(AutomotivePartDraftGenerator::class)->generate($this->eligiblePart())['draft'];
        $reviews = app(AutomotivePartDraftReviewService::class);

        try {
            $reviews->reject($draft, $this->user, '');
            $this->fail('El rechazo sin nota debió fallar.');
        } catch (AutomotivePartDraftException $exception) {
            $this->assertSame('missing_rejection_note', $exception->errorCode);
        }
        $rejected = $reviews->reject($draft, $this->user, 'Datos comerciales pendientes');
        $this->assertSame('rejected', $rejected->status);
        $pending = $reviews->returnToPending($rejected, $this->user, 'Corregir y revisar de nuevo');
        $this->assertSame('pending_review', $pending->status);
        $this->assertDatabaseHas('automotive_part_meli_draft_events', ['action' => 'rejected']);
        $this->assertDatabaseHas('automotive_part_meli_draft_events', ['action' => 'returned_to_pending']);
    }

    public function test_source_change_marks_previous_approved_draft_stale_and_creates_version(): void
    {
        $part = $this->eligiblePart();
        $generator = app(AutomotivePartDraftGenerator::class);
        $first = $generator->generate($part)['draft'];
        $approved = app(AutomotivePartDraftReviewService::class)->approve($first, $this->user, 'Aprobación v1');
        $part->update(['quantity' => 9]);
        $second = $generator->generate($part->fresh())['draft'];

        $this->assertSame(2, $second->version);
        $this->assertSame('stale', $approved->fresh()->status);
        $this->assertSame($this->user->id, $approved->fresh()->reviewed_by);
        $this->assertSame('Aprobación v1', $approved->fresh()->review_notes);
        $this->assertNotNull($approved->fresh()->approved_at);
        $this->assertDatabaseCount('automotive_part_meli_drafts', 2);
    }

    public function test_approval_detects_stale_source_without_generating_a_replacement(): void
    {
        $part = $this->eligiblePart();
        $draft = app(AutomotivePartDraftGenerator::class)->generate($part)['draft'];
        $part->update(['quantity' => 7]);
        $part->meliReadiness()->update(['last_evaluated_at' => now()->subMinute()]);
        $this->assertContains(
            'stale_source_data',
            collect(app(AutomotivePartDraftBuilder::class)->preview($part->fresh())['blocking_errors'])->pluck('code'),
        );

        try {
            app(AutomotivePartDraftReviewService::class)->approve($draft, $this->user);
            $this->fail('El fingerprint obsoleto debió impedir la aprobación.');
        } catch (AutomotivePartDraftException $exception) {
            $this->assertSame('stale_source_data', $exception->errorCode);
        }

        $this->assertSame('stale', $draft->fresh()->status);
        $this->assertContains('stale_source_data', collect($draft->fresh()->blocking_errors)->pluck('code'));
    }

    public function test_local_only_guard_explicitly_blocks_publication_operations(): void
    {
        $guard = app(AutomotivePartDraftLocalOnlyGuard::class);
        $guard->assertLocalOperation('approve');
        $this->addToAssertionCount(1);

        try {
            $guard->assertLocalOperation('publish');
            $this->fail('El guard debió bloquear una operación de publicación.');
        } catch (AutomotivePartDraftException $exception) {
            $this->assertSame('external_write_forbidden', $exception->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_command_enforces_batch_limit_and_only_enqueues_local_jobs(): void
    {
        $this->eligiblePart();
        $this->eligiblePart();
        $this->eligiblePart();
        Queue::fake();

        $this->assertSame(0, Artisan::call('autopartes:drafts-generate', ['--limit' => 2]));
        Queue::assertPushed(GenerateAutomotivePartMeliDraftJob::class, 2);
        $this->assertSame(1, Artisan::call('autopartes:drafts-generate', ['--limit' => 3]));
        Http::assertNothingSent();
    }

    public function test_routes_require_authentication_and_form_requests_validate_actions(): void
    {
        $part = $this->eligiblePart();
        $draft = app(AutomotivePartDraftGenerator::class)->generate($part)['draft'];

        $this->get(route('autopartes.meli.drafts.index'))->assertRedirect('/login');
        $this->post(route('autopartes.meli.drafts.generate', $part))->assertRedirect('/login');
        $this->get(route('autopartes.meli.drafts.history', $draft))->assertRedirect('/login');

        $this->actingAs($this->user)
            ->post(route('autopartes.meli.drafts.reject', $draft))
            ->assertSessionHasErrors('review_notes');
        $this->post(route('autopartes.meli.drafts.generate', $part), ['force' => 'invalid'])
            ->assertSessionHasErrors('force');
        $draft->forceFill(['status' => 'incomplete'])->save();
        $this->post(route('autopartes.meli.drafts.approve', $draft))
            ->assertSessionHasErrors('draft');
        Http::assertNothingSent();
    }

    private function eligiblePart(array $attributes = []): AutomotivePart
    {
        $this->sequence++;
        $part = AutomotivePart::query()->create(array_merge([
            'source_key' => 'draft-source-'.$this->sequence,
            'item_number' => 'ITEM-'.$this->sequence,
            'manufacturer_part_number' => 'MFG-'.$this->sequence,
            'vendor' => 'ACME',
            'vendor_normalized' => 'acme',
            'category' => 'ROTORS',
            'subcategory' => 'BRAKE',
            'description_original' => 'Brake pad for Modelo X',
            'description_normalized' => 'brake pad for modelo x',
            'quantity' => 3,
            'original_currency' => 'USD',
            'retail_price_original' => 100,
            'min_model_year' => 2020,
            'max_model_year' => 2024,
            'prevalent_model' => 'Modelo X',
            'applicable_models_text' => 'Modelo X 2020-2024',
            'data_status' => 'imported',
        ], $attributes));
        config()->set('autopartes_drafts.images_by_source_key', array_merge(
            config('autopartes_drafts.images_by_source_key', []),
            [$part->source_key => ['https://images.example.test/'.$part->source_key.'.jpg']],
        ));
        $review = AutomotivePartEnrichmentReview::query()->create([
            'automotive_part_id' => $part->id,
            'status' => 'approved',
            'issue_codes' => [],
            'proposed_title' => 'Balata de freno ACME para Modelo X',
            'proposed_description' => 'Balata de freno para Modelo X con número de parte respaldado por el catálogo.',
            'proposed_brand' => 'ACME',
            'proposed_compatibility' => [],
            'proposed_attributes' => [],
            'enrichment_source' => 'manual',
            'reviewed_by' => $this->user->id,
            'reviewed_at' => now(),
        ]);
        $category = AutomotivePartMeliCategory::query()->firstOrCreate(
            ['site_id' => 'MLM', 'category_id' => 'MLM123'],
            [
                'name' => 'Balatas',
                'domain_id' => 'MLM-VEHICLE_BRAKE_PADS',
                'path_from_root' => [['id' => 'MLM123', 'name' => 'Balatas']],
                'settings' => ['listing_allowed' => true, 'vertical' => 'vehicle_parts_accessories'],
                'raw_payload' => ['id' => 'MLM123'],
                'synced_at' => now(),
                'attributes_synced_at' => now(),
            ],
        );
        AutomotivePartMeliAttributeRequirement::query()->firstOrCreate(
            ['automotive_part_meli_category_id' => $category->id, 'attribute_id' => 'MPN'],
            [
                'name' => 'Número de parte',
                'value_type' => 'string',
                'tags' => ['required' => true],
                'is_required' => true,
                'is_catalog_required' => false,
                'is_conditional_required' => false,
                'raw_payload' => ['id' => 'MPN'],
            ],
        );
        $candidate = AutomotivePartMeliCategoryCandidate::query()->create([
            'automotive_part_id' => $part->id,
            'automotive_part_enrichment_review_id' => $review->id,
            'status' => 'approved',
            'category_id' => 'MLM123',
            'category_name' => 'Balatas',
            'domain_id' => 'MLM-VEHICLE_BRAKE_PADS',
            'source' => 'manual',
            'evidence' => ['validated' => true],
            'reviewed_by' => $this->user->id,
            'reviewed_at' => now(),
        ]);
        AutomotivePartMeliReadiness::query()->create([
            'automotive_part_id' => $part->id,
            'approved_category_candidate_id' => $candidate->id,
            'status' => 'ready',
            'proposed_attributes' => [[
                'attribute_id' => 'MPN',
                'value' => $part->manufacturer_part_number,
                'value_id' => null,
                'source_field' => 'manufacturer_part_number',
                'transformation' => 'identity',
                'confidence' => 1.0,
                'warnings' => [],
            ]],
            'missing_required_attributes' => [],
            'missing_conditional_attributes' => [],
            'compatibility_requirements' => ['required' => false, 'source_present' => true],
            'warnings' => [],
            'evaluation_fingerprint' => hash('sha256', 'ready-'.$part->id),
            'reviewed_by' => $this->user->id,
            'reviewed_at' => now(),
            'last_evaluated_at' => now(),
        ]);

        return $part->fresh();
    }
}
