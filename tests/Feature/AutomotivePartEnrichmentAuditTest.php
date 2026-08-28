<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;
use App\Models\User;
use App\Services\Autopartes\AutomotivePartEnrichmentAuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AutomotivePartEnrichmentAuditTest extends TestCase
{
    private Migration $phaseOneMigration;

    private Migration $phaseTwoMigration;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        $this->withoutMiddleware(HandleInertiaRequests::class);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $this->phaseOneMigration = require database_path('migrations/2026_08_21_000001_create_automotive_part_tables.php');
        $this->phaseOneMigration->up();
        $this->phaseTwoMigration = require database_path('migrations/2026_08_22_000001_create_automotive_part_enrichment_reviews_table.php');
        $this->phaseTwoMigration->up();
    }

    protected function tearDown(): void
    {
        $this->phaseTwoMigration->down();
        $this->phaseOneMigration->down();
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_audit_is_idempotent_and_detects_compatibility_and_year_issues(): void
    {
        $part = $this->createPart([
            'applicable_models_text' => null,
            'min_model_year' => 2026,
            'average_model_year' => 2024,
            'max_model_year' => 2020,
        ]);

        $service = app(AutomotivePartEnrichmentAuditService::class);
        $firstStats = $service->audit(partId: $part->id);
        $secondStats = $service->audit(partId: $part->id);

        $this->assertSame(1, $firstStats['created']);
        $this->assertSame(0, $secondStats['created']);
        $this->assertSame(1, $secondStats['updated']);
        $this->assertSame(1, AutomotivePartEnrichmentReview::query()->count());

        $issues = $part->enrichmentReview()->firstOrFail()->issue_codes;
        $this->assertContains('missing_compatibility', $issues);
        $this->assertContains('invalid_model_year_range', $issues);
    }

    public function test_audit_preserves_manual_proposals_and_skips_approved_reviews_by_default(): void
    {
        $part = $this->createPart(['applicable_models_text' => null]);
        $service = app(AutomotivePartEnrichmentAuditService::class);
        $service->audit(partId: $part->id);

        $review = $part->enrichmentReview()->firstOrFail();
        $review->update([
            'status' => 'in_review',
            'proposed_title' => 'Título manual confirmado',
            'reviewer_notes' => 'Conservar esta nota',
            'enrichment_source' => 'manual',
        ]);

        $service->audit(partId: $part->id);
        $this->assertSame('Título manual confirmado', $review->fresh()->proposed_title);
        $this->assertSame('Conservar esta nota', $review->fresh()->reviewer_notes);

        $review->update(['status' => 'approved']);
        $part->update(['applicable_models_text' => 'Modelo X 2020-2024']);

        $skippedStats = $service->audit(partId: $part->id);
        $this->assertSame(1, $skippedStats['approved_skipped']);
        $this->assertContains('missing_compatibility', $review->fresh()->issue_codes);

        $service->audit(partId: $part->id, refreshApproved: true);
        $review->refresh();

        $this->assertSame('approved', $review->status);
        $this->assertSame('Título manual confirmado', $review->proposed_title);
        $this->assertSame('Conservar esta nota', $review->reviewer_notes);
        $this->assertNotContains('missing_compatibility', $review->issue_codes);
    }

    public function test_english_description_not_covered_by_the_previous_heuristic_requires_spanish_content(): void
    {
        $part = $this->createPart([
            'description_original' => 'Suspension stabilizer link',
            'description_normalized' => 'Suspension stabilizer link',
        ]);

        app(AutomotivePartEnrichmentAuditService::class)->audit(partId: $part->id);

        $this->assertContains('needs_spanish_content', $part->enrichmentReview()->firstOrFail()->issue_codes);
    }

    public function test_pending_review_without_complete_manual_proposals_keeps_spanish_content_issue(): void
    {
        $part = $this->createPart();
        $service = app(AutomotivePartEnrichmentAuditService::class);
        $service->audit(partId: $part->id);

        $review = $part->enrichmentReview()->firstOrFail();
        $review->update([
            'status' => 'pending',
            'enrichment_source' => 'manual',
            'proposed_title' => 'Título manual',
            'proposed_description' => null,
        ]);

        $service->audit(partId: $part->id);

        $this->assertContains('needs_spanish_content', $review->fresh()->issue_codes);
    }

    public function test_complete_manual_spanish_title_and_description_clear_spanish_content_issue(): void
    {
        $part = $this->createPart();
        $service = app(AutomotivePartEnrichmentAuditService::class);
        $service->audit(partId: $part->id);

        $review = $part->enrichmentReview()->firstOrFail();
        $review->update([
            'status' => 'pending',
            'enrichment_source' => 'manual',
            'proposed_title' => 'Enlace estabilizador de suspensión',
            'proposed_description' => 'Repuesto para el sistema de suspensión del vehículo, revisado manualmente en español.',
        ]);

        $service->audit(partId: $part->id);
        $review->refresh();

        $this->assertNotContains('needs_spanish_content', $review->issue_codes);
        $this->assertSame('Enlace estabilizador de suspensión', $review->proposed_title);
        $this->assertSame('Repuesto para el sistema de suspensión del vehículo, revisado manualmente en español.', $review->proposed_description);
    }

    public function test_approve_and_reject_actions_validate_review_state_and_notes(): void
    {
        $user = User::query()->create([
            'name' => 'Reviewer',
            'email' => 'reviewer@example.test',
            'password' => 'secret-password',
        ]);
        $part = $this->createPart(['applicable_models_text' => null]);
        app(AutomotivePartEnrichmentAuditService::class)->audit(partId: $part->id);
        $review = $part->enrichmentReview()->firstOrFail();
        $review->update(['proposed_title' => null]);

        $this->actingAs($user)
            ->post(route('autopartes.enrichment.approve', $review))
            ->assertSessionHasErrors('proposed_title');

        $review->update(['proposed_title' => 'Propuesta lista']);
        $this->actingAs($user)
            ->post(route('autopartes.enrichment.approve', $review), ['reviewer_notes' => 'Aprobada'])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertSame($user->id, $review->fresh()->reviewed_by);

        $this->post(route('autopartes.enrichment.pending', $review))->assertSessionHasNoErrors();
        $this->post(route('autopartes.enrichment.reject', $review))->assertSessionHasErrors('reviewer_notes');
        $this->post(route('autopartes.enrichment.reject', $review), ['reviewer_notes' => 'Falta validar marca'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', $review->fresh()->status);
    }

    public function test_command_honors_limit_and_part_id(): void
    {
        $first = $this->createPart(['source_key' => 'part-one', 'applicable_models_text' => null]);
        $this->createPart(['source_key' => 'part-two', 'applicable_models_text' => null]);
        $third = $this->createPart(['source_key' => 'part-three', 'applicable_models_text' => null]);

        $this->assertSame(0, Artisan::call('autopartes:audit-enrichment', ['--limit' => 1]));
        $this->assertSame(1, AutomotivePartEnrichmentReview::query()->count());
        $this->assertSame($first->id, AutomotivePartEnrichmentReview::query()->first()->automotive_part_id);

        AutomotivePartEnrichmentReview::query()->delete();

        $this->assertSame(0, Artisan::call('autopartes:audit-enrichment', ['--part-id' => $third->id]));
        $this->assertSame(1, AutomotivePartEnrichmentReview::query()->count());
        $this->assertSame($third->id, AutomotivePartEnrichmentReview::query()->first()->automotive_part_id);
        $this->assertStringContainsString('Productos revisados', Artisan::output());
    }

    public function test_audit_reports_logs_and_displays_sanitized_error_details(): void
    {
        $parts = collect(range(1, 11))
            ->map(fn () => $this->createPart(['applicable_models_text' => null]));
        $part = $parts->first();
        Log::spy();

        $service = new class extends AutomotivePartEnrichmentAuditService
        {
            public function detectIssues(AutomotivePart $part, bool $hasDuplicateImportRow = false): array
            {
                throw new RuntimeException('Database failed token=super-secret-value');
            }
        };

        $stats = $service->audit();

        $this->assertSame(11, $stats['errors']);
        $this->assertCount(10, $stats['error_details']);
        $this->assertSame($part->id, $stats['error_details'][0]['automotive_part_id']);
        $this->assertSame(RuntimeException::class, $stats['error_details'][0]['exception_class']);
        $this->assertStringContainsString('token=[REDACTED]', $stats['error_details'][0]['message']);
        $this->assertStringNotContainsString('super-secret-value', $stats['error_details'][0]['message']);

        Log::shouldHaveReceived('error')->times(11)->withArgs(
            fn (string $message, array $context) => $message === 'Automotive part enrichment audit failed.'
                && $parts->pluck('id')->contains($context['automotive_part_id'])
                && $context['exception'] instanceof RuntimeException,
        );

        $commandService = new class($stats) extends AutomotivePartEnrichmentAuditService
        {
            public function __construct(private array $stats) {}

            public function audit(?int $limit = null, ?int $partId = null, bool $refreshApproved = false): array
            {
                return $this->stats;
            }
        };
        $this->app->instance(AutomotivePartEnrichmentAuditService::class, $commandService);

        $this->assertSame(1, Artisan::call('autopartes:audit-enrichment', ['--limit' => 1]));
        $output = Artisan::output();
        $this->assertStringContainsString((string) $part->id, $output);
        $this->assertStringContainsString(RuntimeException::class, $output);
        $this->assertStringContainsString('token=[REDACTED]', $output);
        $this->assertStringNotContainsString('super-secret-value', $output);
    }

    private function createPart(array $attributes = []): AutomotivePart
    {
        static $sequence = 0;
        $sequence++;

        return AutomotivePart::query()->create(array_merge([
            'source_key' => 'audit-part-'.$sequence,
            'item_number' => 'ITEM-'.$sequence,
            'manufacturer_part_number' => 'MFG-'.$sequence,
            'vendor' => 'ACME',
            'vendor_normalized' => 'acme',
            'category' => 'BRAKES',
            'description_original' => 'Balata de freno',
            'description_normalized' => 'Balata de freno',
            'quantity' => 1,
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
            'data_status' => 'imported',
        ], $attributes));
    }
}
