<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartMedia;
use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartPriceCalculation;
use App\Models\AutomotivePartPriceRule;
use App\Models\User;
use App\Services\Autopartes\MediaPricing\AutomotivePartDraftMediaPricingSource;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingException;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaPricingLocalOnlyGuard;
use App\Services\Autopartes\MediaPricing\AutomotivePartMediaService;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceCalculator;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceRuleResolver;
use App\Services\Autopartes\MediaPricing\AutomotivePartPriceRuleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutomotivePartMediaPricingTest extends TestCase
{
    private array $migrations = [];
    private User $user;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('autopartes_media_pricing.enabled', true);
        config()->set('autopartes_media_pricing.media_disk', 'local');
        config()->set('autopartes_media_pricing.media_max_file_kb', 100);
        config()->set('autopartes_media_pricing.media_max_width', 100);
        config()->set('autopartes_media_pricing.media_max_height', 100);
        config()->set('autopartes_media_pricing.media_max_images_per_part', 3);
        config()->set('autopartes_media_pricing.price_max_batch', 3);
        DB::purge('sqlite'); Storage::fake('local'); Http::preventStrayRequests(); Queue::fake();
        $this->withoutMiddleware(HandleInertiaRequests::class);

        Schema::create('users', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->timestamps(); });
        foreach (['2026_08_21_000001_create_automotive_part_tables.php', '2026_08_24_000002_create_automotive_part_media_pricing_tables.php'] as $migration) {
            $instance = require database_path('migrations/'.$migration); $instance->up(); $this->migrations[] = $instance;
        }
        Schema::create('automotive_part_meli_drafts', function (Blueprint $table) {
            $table->id(); $table->foreignId('automotive_part_id'); $table->string('status'); $table->timestamp('approved_at')->nullable(); $table->timestamps();
        });
        Schema::create('automotive_part_meli_draft_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('automotive_part_meli_draft_id'); $table->string('action'); $table->string('from_status')->nullable(); $table->string('to_status')->nullable(); $table->foreignId('user_id')->nullable(); $table->text('notes')->nullable(); $table->json('metadata')->nullable(); $table->timestamp('created_at');
        });
        $this->user = User::query()->create(['name' => 'Reviewer', 'email' => 'phase6@example.test', 'password' => 'password']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('automotive_part_meli_draft_events'); Schema::dropIfExists('automotive_part_meli_drafts');
        foreach (array_reverse($this->migrations) as $migration) $migration->down();
        Schema::dropIfExists('users'); DB::purge('sqlite'); parent::tearDown();
    }

    public function test_disabled_integration_and_external_operation_are_blocked_without_http(): void
    {
        config()->set('autopartes_media_pricing.enabled', false);
        $this->expectMediaError('media_pricing_disabled', fn () => $this->media()->upload($this->part(), UploadedFile::fake()->image('part.jpg', 10, 10), 'owned_photo', null, null, $this->user));
        $this->expectMediaError('external_operation_forbidden', fn () => app(AutomotivePartMediaPricingLocalOnlyGuard::class)->assert('publish'));
        Http::assertNothingSent();
    }

    public function test_valid_jpeg_png_and_webp_are_detected_stored_with_safe_names_and_hashed(): void
    {
        $part = $this->part();
        $files = [UploadedFile::fake()->image('part.jpg', 10, 10), UploadedFile::fake()->image('part.png', 11, 10), $this->webp()];
        foreach ($files as $index => $file) {
            $media = $this->media()->upload($part, $file, 'owned_photo', 'studio-'.$index, null, $this->user);
            $this->assertContains($media->detected_mime, ['image/jpeg', 'image/png', 'image/webp']);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.(jpg|png|webp)$/', $media->stored_name);
            $this->assertSame(64, strlen($media->sha256)); Storage::disk('local')->assertExists($media->path);
        }
        Http::assertNothingSent();
    }

    public function test_unsafe_content_traversal_size_dimensions_duplicates_and_limits_are_rejected(): void
    {
        $part = $this->part();
        $this->expectMediaError('invalid_image_content', fn () => $this->media()->upload($part, UploadedFile::fake()->createWithContent('bad.svg', '<svg/>'), 'user_upload', null, null, $this->user));
        $this->expectMediaError('invalid_image_content', fn () => $this->media()->upload($part, UploadedFile::fake()->createWithContent('fake.jpg', 'MZ executable'), 'user_upload', null, null, $this->user));
        $validTraversalImage = UploadedFile::fake()->image('valid.png', 10, 10);
        $validTraversalContent = file_get_contents($validTraversalImage->getRealPath());
        $this->expectMediaError('path_traversal', fn () => $this->media()->upload($part, UploadedFile::fake()->createWithContent('../escape.png', $validTraversalContent), 'user_upload', null, null, $this->user));
        $this->expectMediaError('path_traversal', fn () => $this->media()->upload($part, UploadedFile::fake()->createWithContent('folder\\escape.png', $validTraversalContent), 'user_upload', null, null, $this->user));
        $nullByteImage = new class($validTraversalImage->getRealPath()) extends UploadedFile
        {
            public function __construct(string $path)
            {
                parent::__construct($path, 'valid.png', 'image/png', UPLOAD_ERR_OK, true);
            }

            public function getClientOriginalPath(): string
            {
                return "unsafe\0.png";
            }
        };
        $this->expectMediaError('path_traversal', fn () => $this->media()->upload($part, $nullByteImage, 'user_upload', null, null, $this->user));
        config()->set('autopartes_media_pricing.media_max_file_kb', 1);
        $this->expectMediaError('invalid_file_size', fn () => $this->media()->upload($part, UploadedFile::fake()->createWithContent('large.jpg', str_repeat('x', 2048)), 'user_upload', null, null, $this->user));
        config()->set('autopartes_media_pricing.media_max_file_kb', 100); config()->set('autopartes_media_pricing.media_max_width', 5);
        $this->expectMediaError('invalid_dimensions', fn () => $this->media()->upload($part, UploadedFile::fake()->image('wide.png', 6, 2), 'user_upload', null, null, $this->user));
        config()->set('autopartes_media_pricing.media_max_width', 100);
        $first = UploadedFile::fake()->image('same.png', 13, 13); $content = file_get_contents($first->getRealPath());
        $this->media()->upload($part, $first, 'user_upload', null, null, $this->user);
        $this->expectMediaError('duplicate_media', fn () => $this->media()->upload($part, UploadedFile::fake()->createWithContent('copy.png', $content), 'user_upload', null, null, $this->user));
        config()->set('autopartes_media_pricing.media_max_images_per_part', 1);
        $this->expectMediaError('media_limit_reached', fn () => $this->media()->upload($part, UploadedFile::fake()->image('other.png', 14, 13), 'user_upload', null, null, $this->user));
    }

    public function test_approval_primary_rejection_and_archive_are_traced_without_deleting_files(): void
    {
        $part = $this->part();
        $first = $this->media()->upload($part, UploadedFile::fake()->image('a.jpg', 10, 10), 'supplier_file', 'supplier-a', null, $this->user);
        $second = $this->media()->upload($part, UploadedFile::fake()->image('b.jpg', 11, 10), 'manufacturer_file', 'maker-b', null, $this->user);
        $this->media()->approve($first, $this->user); $this->media()->approve($second, $this->user);
        $this->assertSame(1, AutomotivePartMedia::query()->where('automotive_part_id', $part->id)->where('status', 'approved')->where('is_primary', true)->count());
        $this->media()->setPrimary($second->fresh(), $this->user);
        $this->assertTrue($second->fresh()->is_primary); $this->assertFalse($first->fresh()->is_primary);
        $path = $first->path; $this->media()->reject($first->fresh(), $this->user, 'Licencia no confirmada');
        Storage::disk('local')->assertExists($path); $this->assertSame($this->user->id, $first->fresh()->rejected_by);
        $this->media()->archive($second->fresh(), $this->user, 'Reemplazo'); Storage::disk('local')->assertExists($second->path);
        $this->assertGreaterThanOrEqual(6, DB::table('automotive_part_media_events')->count()); Http::assertNothingSent();
    }

    public function test_price_scope_precedence_formula_rounding_bounds_fingerprint_and_idempotence(): void
    {
        $part = $this->part(['retail_price_original' => 100, 'vendor_normalized' => 'acme', 'category' => 'brakes']);
        $global = $this->activeRule(['name' => 'Global', 'scope_type' => 'global', 'scope_value' => null, 'usd_mxn_rate' => 18]);
        $category = $this->activeRule(['name' => 'Category', 'scope_type' => 'category', 'scope_value' => 'BRAKES', 'usd_mxn_rate' => 19]);
        $vendor = $this->activeRule(['name' => 'Vendor', 'scope_type' => 'vendor', 'scope_value' => 'ACME', 'usd_mxn_rate' => 20]);
        $specific = $this->activeRule(['name' => 'Part', 'scope_type' => 'automotive_part', 'scope_value' => (string) $part->id,
            'usd_mxn_rate' => 20, 'markup_percent' => 10, 'meli_fee_percent' => 10, 'fixed_cost_mxn' => 100,
            'rounding_mode' => 'up', 'rounding_increment' => 10, 'minimum_price_mxn' => 2500, 'maximum_price_mxn' => 2600]);
        $this->assertSame($specific->id, app(AutomotivePartPriceRuleResolver::class)->resolve($part)->id);
        $firstPreview = app(AutomotivePartPriceCalculator::class)->preview($part); $secondPreview = app(AutomotivePartPriceCalculator::class)->preview($part->fresh());
        $this->assertSame(2560.0, $firstPreview['price_mxn']); $this->assertSame(2555.555556, $firstPreview['breakdown']['sale_price_before_rounding_mxn']);
        $this->assertSame($firstPreview['fingerprint'], $secondPreview['fingerprint']);
        $first = app(AutomotivePartPriceCalculator::class)->calculate($part); $second = app(AutomotivePartPriceCalculator::class)->calculate($part->fresh());
        $this->assertTrue($first['created']); $this->assertFalse($second['created']); $this->assertSame($first['calculation']->id, $second['calculation']->id);
        $this->assertDatabaseCount('automotive_part_price_calculations', 1); Http::assertNothingSent();

        $minimumPart = $this->part(['retail_price_original' => 1]);
        $this->activeRule(['name' => 'Minimum', 'scope_type' => 'automotive_part', 'scope_value' => (string) $minimumPart->id, 'usd_mxn_rate' => 1, 'minimum_price_mxn' => 50]);
        $this->assertSame(50.0, app(AutomotivePartPriceCalculator::class)->preview($minimumPart)['price_mxn']);
        $maximumPart = $this->part(['retail_price_original' => 100]);
        $this->activeRule(['name' => 'Maximum', 'scope_type' => 'automotive_part', 'scope_value' => (string) $maximumPart->id, 'usd_mxn_rate' => 20, 'maximum_price_mxn' => 1000]);
        $this->assertSame(1000.0, app(AutomotivePartPriceCalculator::class)->preview($maximumPart)['price_mxn']);
    }

    public function test_invalid_rules_ambiguity_immutability_and_replacement_version_are_enforced(): void
    {
        $service = app(AutomotivePartPriceRuleService::class);
        $this->expectMediaError('invalid_price_rule', fn () => $service->createDraft($this->ruleData(['meli_fee_percent' => 99.99]), $this->user));
        $this->expectMediaError('invalid_price_rule', fn () => $service->createDraft($this->ruleData(['usd_mxn_rate' => null]), $this->user));
        $active = $this->activeRule(['name' => 'Active global']);
        $this->expectMediaError('active_rule_immutable', fn () => $service->updateDraft($active, $this->ruleData(['name' => 'Mutated']), $this->user));
        $other = $service->createDraft($this->ruleData(['name' => 'Ambiguous global']), $this->user);
        $this->expectMediaError('ambiguous_active_rule', fn () => $service->activate($other, $this->user));
        $replacement = $service->replace($active, $this->user);
        $this->assertSame('draft', $replacement->status); $this->assertSame(2, $replacement->version); $this->assertSame($active->rule_key, $replacement->rule_key);
        Http::assertNothingSent();
    }

    public function test_database_media_and_price_feed_drafts_and_source_changes_mark_approved_snapshot_stale(): void
    {
        $part = $this->part();
        $draft = AutomotivePartMeliDraft::query()->create(['automotive_part_id' => $part->id, 'status' => 'approved', 'approved_at' => now()]);
        $media = $this->media()->upload($part, UploadedFile::fake()->image('approved.png', 10, 10), 'owned_photo', null, null, $this->user);
        config()->set('autopartes_drafts.images_by_source_key', [$part->source_key => ['https://legacy.example.test/image.jpg']]);
        $source = app(AutomotivePartDraftMediaPricingSource::class);
        $this->assertSame([], $source->images($part));
        $rule = $this->activeRule(['scope_type' => 'automotive_part', 'scope_value' => (string) $part->id]);
        $this->assertContains('missing_exchange_rate', $source->price($part)['errors']);
        $this->assertContains('missing_price_mxn', $source->price($part)['errors']);
        $this->media()->approve($media, $this->user);
        $this->assertSame('stale', $draft->fresh()->status); $this->assertNotNull($draft->fresh()->approved_at);
        $calculation = app(AutomotivePartPriceCalculator::class)->calculate($part)['calculation'];
        $this->assertSame($media->id, $source->images($part)[0]['media_id']);
        $this->assertSame($calculation->id, $source->price($part)['calculation_id']);
        $this->assertSame($rule->version, $source->price($part)['rule_version']); Http::assertNothingSent();
    }

    public function test_private_routes_form_requests_and_dry_run_are_safe_and_non_persistent(): void
    {
        $part = $this->part(); $rule = $this->activeRule(['scope_type' => 'automotive_part', 'scope_value' => (string) $part->id]);
        $media = $this->media()->upload($part, UploadedFile::fake()->image('route.png', 10, 10), 'owned_photo', null, null, $this->user);
        $this->get(route('autopartes.media.preview', $media))->assertRedirect('/login');
        $this->get(route('autopartes.media.index'))->assertRedirect('/login');
        $this->get(route('autopartes.prices.index'))->assertRedirect('/login');
        $this->actingAs($this->user)->get(route('autopartes.media.preview', $media))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $media->forceFill(['path' => '.env'])->save();
        $this->get(route('autopartes.media.preview', $media->fresh()))->assertNotFound();
        $media->forceFill(['path' => 'autopartes/media/'.$part->id.'/'.$media->stored_name])->save();
        $this->post(route('autopartes.media.store', $part), ['provenance_type' => 'unknown'])->assertSessionHasErrors(['image', 'provenance_type']);
        $this->post(route('autopartes.media.reject', $media))->assertSessionHasErrors('notes');
        $this->put(route('autopartes.prices.rules.update', $rule), $this->ruleData())->assertSessionHasErrors('rule');
        Queue::fake(); $before = AutomotivePartPriceCalculation::query()->count(); $events = DB::table('automotive_part_media_events')->count();
        $this->assertSame(0, Artisan::call('autopartes:media-audit', ['--part-id' => $part->id, '--limit' => 1, '--dry-run' => true]));
        $this->assertSame($events, DB::table('automotive_part_media_events')->count()); Storage::disk('local')->assertExists($media->path);
        $this->assertSame(0, Artisan::call('autopartes:prices-calculate', ['--part-id' => $part->id, '--rule-id' => $rule->id, '--limit' => 1, '--dry-run' => true]));
        $this->assertStringContainsString('Dry-run', Artisan::output()); $this->assertSame($before, AutomotivePartPriceCalculation::query()->count()); Queue::assertNothingPushed(); Http::assertNothingSent();
    }

    private function media(): AutomotivePartMediaService { return app(AutomotivePartMediaService::class); }
    private function part(array $attributes = []): AutomotivePart { $this->sequence++; return AutomotivePart::query()->create(array_merge(['source_key' => 'phase6-'.$this->sequence, 'item_number' => 'ITEM-'.$this->sequence, 'vendor' => 'ACME', 'vendor_normalized' => 'acme', 'category' => 'brakes', 'description_original' => 'Brake component', 'quantity' => 2, 'original_currency' => 'USD', 'retail_price_original' => 100], $attributes)); }
    private function activeRule(array $attributes = []): AutomotivePartPriceRule { $service = app(AutomotivePartPriceRuleService::class); return $service->activate($service->createDraft($this->ruleData($attributes), $this->user), $this->user); }
    private function ruleData(array $attributes = []): array { return array_merge(['name' => 'Rule '.$this->sequence, 'scope_type' => 'global', 'scope_value' => null, 'source_currency' => 'USD', 'target_currency' => 'MXN', 'usd_mxn_rate' => 20, 'markup_percent' => 0, 'meli_fee_percent' => 0, 'fixed_cost_mxn' => 0, 'rounding_mode' => 'nearest', 'rounding_increment' => 1, 'minimum_price_mxn' => null, 'maximum_price_mxn' => null, 'effective_from' => now()->subDay(), 'effective_until' => null, 'notes' => null, 'metadata' => []], $attributes); }
    private function webp(): UploadedFile { return UploadedFile::fake()->image('part.webp', 12, 10); }
    private function expectMediaError(string $code, callable $callback): void { try { $callback(); $this->fail('Se esperaba '.$code); } catch (AutomotivePartMediaPricingException $exception) { $this->assertSame($code, $exception->errorCode); } }
}
