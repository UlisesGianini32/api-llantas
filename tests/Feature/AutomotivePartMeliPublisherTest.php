<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\PublishAutomotivePartToMeliJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;
use App\Models\AutomotivePartMedia;
use App\Models\AutomotivePartMeliAttributeRequirement;
use App\Models\AutomotivePartMeliCategory;
use App\Models\AutomotivePartMeliCategoryCandidate;
use App\Models\AutomotivePartMeliDraft;
use App\Models\AutomotivePartMeliPictureUpload;
use App\Models\AutomotivePartMeliPublication;
use App\Models\AutomotivePartMeliReadiness;
use App\Models\MeliAccount;
use App\Models\User;
use App\Services\Autopartes\Drafts\AutomotivePartDraftGenerator;
use App\Services\Autopartes\Drafts\AutomotivePartDraftReviewService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliFinalApprovalService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliLivePublisher;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPictureUploadService;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublicationPreflight;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherClient;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherException;
use App\Services\Autopartes\Publisher\AutomotivePartMeliPublisherSanitizer;
use App\Services\Autopartes\Publisher\AutomotivePartMeliRemoteValidationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AutomotivePartMeliPublisherTest extends TestCase
{
    private array $migrations = [];
    private User $user;
    private MeliAccount $account;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite'); config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true); DB::purge('sqlite');
        config()->set('autopartes_drafts.enabled', true); config()->set('autopartes_drafts.usd_mxn_rate', 20);
        config()->set('autopartes_drafts.price_markup_percent', 0); config()->set('autopartes_drafts.meli_fee_percent', 0);
        config()->set('autopartes_drafts.condition', 'new'); config()->set('autopartes_drafts.currency', 'MXN');
        config()->set('autopartes_drafts.images_by_source_key', []); config()->set('autopartes_media_pricing.enabled', true);
        config()->set('autopartes_media_pricing.media_disk', 'local'); config()->set('autopartes_media_pricing.allow_phase5_price_fallback', true);
        config()->set('autopartes_meli_publisher.enabled', true); config()->set('autopartes_meli_publisher.remote_validation_enabled', true);
        config()->set('autopartes_meli_publisher.image_upload_enabled', true); config()->set('autopartes_meli_publisher.live_enabled', true);
        config()->set('autopartes_meli_publisher.listing_type_id', 'gold_special'); config()->set('autopartes_meli_publisher.buying_mode', 'buy_it_now');
        config()->set('autopartes_meli_publisher.channels', []); config()->set('autopartes_meli_publisher.max_daily_items', 1);
        Storage::fake('local'); Http::preventStrayRequests(); Queue::fake(); $this->withoutMiddleware(HandleInertiaRequests::class);
        Schema::create('users', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->timestamps(); });
        Schema::create('meli_accounts', function (Blueprint $table) { $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id'); $table->string('nickname')->nullable(); $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable(); $table->text('refresh_token')->nullable(); $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false); $table->timestamps(); $table->unique(['user_id', 'meli_user_id']); });
        foreach (['2026_08_21_000001_create_automotive_part_tables.php', '2026_08_22_000001_create_automotive_part_enrichment_reviews_table.php',
            '2026_08_22_000003_create_automotive_part_meli_mapping_tables.php', '2026_08_24_000001_create_automotive_part_meli_drafts_tables.php',
            '2026_08_24_000002_create_automotive_part_media_pricing_tables.php', '2026_08_25_000001_create_automotive_part_meli_publication_tables.php'] as $file) {
            $migration = require database_path('migrations/'.$file); $migration->up(); $this->migrations[] = $migration;
        }
        $this->user = User::query()->create(['name' => 'Publisher Reviewer', 'email' => 'publisher@example.test', 'password' => 'password']);
        $this->account = MeliAccount::query()->create(['user_id' => $this->user->id, 'meli_user_id' => '123456789', 'nickname' => 'Cuenta elegida',
            'access_token' => 'APP_USR-secret-test-token', 'expires_at' => now()->addHour(), 'is_default' => false]);
        config()->set('autopartes_meli_publisher.account_id', $this->account->id);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) $migration->down();
        Schema::dropIfExists('meli_accounts'); Schema::dropIfExists('users'); DB::purge('sqlite'); parent::tearDown();
    }

    public function test_disabled_by_default_and_dry_run_never_persists_or_calls_http(): void
    {
        $draft = $this->approvedDraft();
        config()->set('autopartes_meli_publisher.enabled', false);
        $this->expectPublisherError('publisher_disabled', fn () => app(AutomotivePartMeliPublicationPreflight::class)->create($draft, $this->account));
        $this->assertSame(0, Artisan::call('autopartes:meli-publish', ['--draft-id' => $draft->id, '--limit' => 1, '--dry-run' => true]));
        $this->assertDatabaseCount('automotive_part_meli_publications', 0); Http::assertNothingSent(); Queue::assertNothingPushed();
        config()->set('autopartes_meli_publisher.account_id', null);
        $this->assertSame(1, Artisan::call('autopartes:meli-publish', ['--draft-id' => $draft->id, '--dry-run' => true]));
        Http::assertNothingSent();
        $defaults = require base_path('config/autopartes_meli_publisher.php');
        $this->assertFalse((bool) $defaults['live_enabled']);
    }

    public function test_preflight_requires_approved_fresh_complete_sources_and_builds_an_exact_backed_payload(): void
    {
        $draft = $this->approvedDraft(); $preview = app(AutomotivePartMeliPublicationPreflight::class)->preview($draft, $this->account);
        $this->assertTrue($preview['eligible']);
        $this->assertSame(['site_id', 'title', 'category_id', 'price', 'currency_id', 'available_quantity', 'buying_mode', 'listing_type_id', 'pictures', 'attributes'], array_keys($preview['item_payload']));
        $this->assertSame('new', collect($preview['item_payload']['attributes'])->firstWhere('id', 'ITEM_CONDITION')['value_id']);
        $this->assertArrayNotHasKey('exclusive_channel', $preview['item_payload']); $this->assertArrayNotHasKey('gtin', $preview['item_payload']);
        $this->assertArrayNotHasKey('source', $preview['item_payload']['pictures'][0]);
        $draft->forceFill(['status' => 'stale'])->save();
        $stale = app(AutomotivePartMeliPublicationPreflight::class)->preview($draft->fresh(), $this->account);
        $this->assertContains('draft_stale', collect($stale['errors'])->pluck('code'));
        $draft->forceFill(['status' => 'approved', 'stock' => 0])->save();
        $this->assertContains('invalid_stock', collect(app(AutomotivePartMeliPublicationPreflight::class)->preview($draft->fresh(), $this->account)['errors'])->pluck('code'));
        $draft->forceFill(['stock' => 3, 'price_mxn' => null, 'category_id' => null, 'prepared_images' => []])->save();
        $codes = collect(app(AutomotivePartMeliPublicationPreflight::class)->preview($draft->fresh(), $this->account)['errors'])->pluck('code');
        $this->assertContains('invalid_price_mxn', $codes); $this->assertContains('invalid_site', $codes); $this->assertContains('missing_images', $codes);
        Http::assertNothingSent();
    }

    public function test_client_allowlist_blocks_put_delete_and_unknown_routes_before_http(): void
    {
        $client = app(AutomotivePartMeliPublisherClient::class);
        foreach ([['PUT', '/items/MLM123'], ['DELETE', '/items/MLM123'], ['POST', '/items/MLM123/questions'], ['GET', 'https://evil.test/items/MLM123']] as [$method, $path]) {
            $this->expectPublisherError('endpoint_not_allowed', fn () => $client->request($method, $path, $this->account));
        }
        Http::assertNothingSent();
    }

    public function test_changed_source_fingerprint_marks_publication_stale_and_revokes_final_approval(): void
    {
        $publication = $this->publication();
        $publication->forceFill(['status' => 'validated', 'final_approved_by' => $this->user->id,
            'final_approved_at' => now(), 'final_approval_fingerprint' => str_repeat('a', 64)])->save();
        $publication->automotivePart()->update(['quantity' => 9]);
        $this->expectPublisherError('stale_publication', fn () => app(AutomotivePartMeliPublicationPreflight::class)->assertFresh($publication->fresh()));
        $this->assertSame('stale', $publication->fresh()->status); $this->assertNull($publication->fresh()->final_approved_at);
        Http::assertNothingSent();
    }

    public function test_private_picture_upload_is_multipart_and_idempotent_by_account_and_hash(): void
    {
        $first = $this->publication();
        Http::fake(['https://api.mercadolibre.com/pictures/items/upload' => Http::response(['id' => 'PIC-123', 'secure_url' => 'https://http2.mlstatic.com/pic.jpg'], 201)]);
        app(AutomotivePartMeliPictureUploadService::class)->upload($first, $this->user);
        Http::assertSentCount(1);
        [$request] = Http::recorded()->first();
        $this->assertSame('POST', $request->method()); $this->assertSame('https://api.mercadolibre.com/pictures/items/upload', $request->url());
        $this->assertSame('file', data_get($request->data(), '0.name'));
        $this->assertStringStartsWith('automotive-part-', (string) data_get($request->data(), '0.filename'));
        $second = $this->publication();
        app(AutomotivePartMeliPictureUploadService::class)->upload($second, $this->user);
        Http::assertSentCount(1);
        $this->assertSame('PIC-123', $second->fresh()->pictureUploads()->first()->meli_picture_id);
        $this->assertStringNotContainsString('/preview', json_encode($first->fresh()->pictureUploads));
    }

    public function test_remote_validation_never_posts_items_and_final_approval_is_local_and_exact(): void
    {
        $publication = $this->publication(); $this->markPicturesUploaded($publication);
        Http::fake(['https://api.mercadolibre.com/items/validate' => Http::response([], 204)]);
        app(AutomotivePartMeliRemoteValidationService::class)->validate($publication, $this->user);
        Http::assertSentCount(1); Http::assertSent(fn ($request) => $request->url() === 'https://api.mercadolibre.com/items/validate');
        $this->expectPublisherError('final_confirmation_mismatch', fn () => app(AutomotivePartMeliFinalApprovalService::class)->approve($publication->fresh(), $this->user, ['note' => 'Revisado']));
        $approved = $this->approveFinal($publication->fresh());
        $this->assertSame('final_approved', $approved->status); Http::assertSentCount(1);
        $approved->forceFill(['remote_validation_expires_at' => now()->subMinute(), 'status' => 'validated'])->save();
        $this->expectPublisherError('remote_validation_expired', fn () => $this->approveFinal($approved->fresh()));
    }

    public function test_remote_validation_errors_are_persisted_without_publication(): void
    {
        $publication = $this->publication(); $this->markPicturesUploaded($publication);
        Http::fake(['https://api.mercadolibre.com/items/validate' => Http::response(['message' => 'invalid price', 'cause' => [['code' => 'item.price.invalid']]], 400)]);
        $this->expectPublisherError('meli_validation_error', fn () => app(AutomotivePartMeliRemoteValidationService::class)->validate($publication, $this->user));
        $this->assertSame('validation_failed', $publication->fresh()->status);
        $this->assertSame('invalid price', $publication->fresh()->validation_response['message']);
        Http::assertSentCount(1); Http::assertSent(fn ($request) => ! str_ends_with($request->url(), '/items'));
    }

    public function test_http_auth_errors_do_not_retry_and_429_retries_only_safe_validation(): void
    {
        Http::fakeSequence()->push(['message' => 'unauthorized'], 401)->push(['message' => 'forbidden'], 403)
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '0'])->push([], 204)
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '0'])->push(['id' => 'MLM999'], 201);
        $client = app(AutomotivePartMeliPublisherClient::class);
        $this->expectPublisherError('meli_unauthorized', fn () => $client->validateItem($this->account, ['title' => 'x']));
        $this->expectPublisherError('meli_forbidden', fn () => $client->validateItem($this->account, ['title' => 'x']));
        $this->assertSame(204, $client->validateItem($this->account, ['title' => 'x'])['status']);
        $this->expectPublisherError('meli_rate_limited', fn () => $client->createItem($this->account, ['title' => 'x']));
        Http::assertSentCount(5);
    }

    public function test_live_posts_item_once_persists_id_before_plain_text_description_and_leaves_compatibility_pending(): void
    {
        $publication = $this->validatedAndApprovedPublication(true);
        $dailyBlocked = $this->validatedAndApprovedPublication(); Queue::fake();
        $itemCalls = 0; $descriptionSawItem = false;
        Http::fake(function ($request) use (&$itemCalls, &$descriptionSawItem, $publication) {
            if ($request->url() === 'https://api.mercadolibre.com/items') { $itemCalls++; return Http::response(['id' => 'MLM987654321', 'permalink' => 'https://articulo.mercadolibre.com.mx/MLM-987654321', 'status' => 'active'], 201); }
            if (str_ends_with($request->url(), '/description')) {
                $descriptionSawItem = AutomotivePartMeliPublication::query()->find($publication->id)?->meli_item_id === 'MLM987654321';
                $this->assertSame(['plain_text' => $publication->local_payload['description_payload']['plain_text']], $request->data());
                return Http::response(['text' => 'ok'], 201);
            }
            return Http::response([], 500);
        });
        $result = app(AutomotivePartMeliLivePublisher::class)->publish($publication);
        $this->assertSame(1, $itemCalls); $this->assertTrue($descriptionSawItem); $this->assertSame('published_pending_compatibility', $result->status);
        $this->assertSame('pending_no_write_phase_7', $result->metadata['compatibility_task']);
        $this->expectPublisherError('daily_limit_reached', fn () => app(AutomotivePartMeliLivePublisher::class)->publish($dailyBlocked));
        $this->expectPublisherError('final_approval_required', fn () => app(AutomotivePartMeliLivePublisher::class)->publish($result));
        $this->assertSame(1, $itemCalls);
    }

    public function test_description_failure_and_ambiguous_item_creation_never_repeat_post_items(): void
    {
        $publication = $this->validatedAndApprovedPublication();
        $ambiguous = $this->validatedAndApprovedPublication();
        $itemCalls = 0; $ambiguousMode = false;
        Http::fake(function ($request) use (&$itemCalls, &$ambiguousMode) {
            if ($request->url() === 'https://api.mercadolibre.com/items') {
                $itemCalls++;
                if ($ambiguousMode) throw new ConnectionException('connection lost');
                return Http::response(['id' => 'MLM111222333'], 201);
            }
            return Http::response(['message' => 'temporary'], 500);
        });
        $this->expectPublisherError('meli_server_error', fn () => app(AutomotivePartMeliLivePublisher::class)->publish($publication));
        $this->assertSame(1, $itemCalls); $this->assertSame('description_pending', $publication->fresh()->status); $this->assertSame('MLM111222333', $publication->fresh()->meli_item_id);

        $publication->fresh()->forceFill(['published_at' => null])->save();
        $ambiguousMode = true;
        $this->expectPublisherError('connection_error', fn () => app(AutomotivePartMeliLivePublisher::class)->publish($ambiguous));
        $this->assertSame('reconciliation_required', $ambiguous->fresh()->status);
        $this->assertTrue($ambiguous->attempts()->where('operation', 'create_item')->first()->ambiguous_result);
    }

    public function test_sanitizer_daily_limit_single_job_and_authenticated_form_routes(): void
    {
        $sanitized = app(AutomotivePartMeliPublisherSanitizer::class)->array(['Authorization' => 'Bearer token', 'access_token' => 'APP_USR-secret', 'nested' => ['client_secret' => 'secret']]);
        $this->assertSame('[REDACTED]', $sanitized['Authorization']); $this->assertSame('[REDACTED]', $sanitized['access_token']);
        $publication = $this->publication();
        $this->get(route('autopartes.meli.publications.index'))->assertRedirect('/login');
        $this->post(route('autopartes.meli.publications.preflight'))->assertRedirect('/login');
        $this->actingAs($this->user)->post(route('autopartes.meli.publications.preflight'))->assertSessionHasErrors(['draft_id', 'meli_account_id']);
        $this->post(route('autopartes.meli.publications.enqueue', $publication))->assertSessionHasErrors('confirm_live_publication');
        $job = new PublishAutomotivePartToMeliJob($publication->id); $this->assertSame(1, $job->tries); $this->assertSame('autopartes-meli-publisher', $job->queue);
        Http::assertNothingSent();
    }

    private function publication(bool $compatibility = false): AutomotivePartMeliPublication { return app(AutomotivePartMeliPublicationPreflight::class)->create($this->approvedDraft($compatibility), $this->account, $this->user); }

    private function validatedAndApprovedPublication(bool $compatibility = false): AutomotivePartMeliPublication
    {
        $publication = $this->publication($compatibility);
        $this->markPicturesUploaded($publication);
        Http::fake(['https://api.mercadolibre.com/items/validate' => Http::response([], 204)]);
        app(AutomotivePartMeliRemoteValidationService::class)->validate($publication, $this->user);
        return $this->approveFinal($publication->fresh());
    }

    private function approveFinal(AutomotivePartMeliPublication $publication): AutomotivePartMeliPublication
    {
        $item = $publication->local_payload['item_payload'];
        return app(AutomotivePartMeliFinalApprovalService::class)->approve($publication, $this->user, ['note' => 'Revisión final humana',
            'confirm_account_id' => $publication->meli_account_id, 'confirm_title' => $item['title'], 'confirm_price' => $item['price'],
            'confirm_stock' => $item['available_quantity'], 'confirm_category_id' => $item['category_id'],
            'confirm_fingerprint_suffix' => substr($publication->request_fingerprint, -8)]);
    }

    private function markPicturesUploaded(AutomotivePartMeliPublication $publication): void
    {
        foreach ($publication->local_payload['media'] as $image) AutomotivePartMeliPictureUpload::query()->create(['publication_id' => $publication->id,
            'automotive_part_media_id' => $image['media_id'], 'media_sha256' => $image['sha256'], 'status' => 'uploaded',
            'attempt_count' => 1, 'meli_picture_id' => 'PIC-'.$image['media_id'], 'uploaded_at' => now()]);
    }

    private function approvedDraft(bool $compatibility = false): AutomotivePartMeliDraft
    {
        $part = $this->eligiblePart($compatibility); $draft = app(AutomotivePartDraftGenerator::class)->generate($part)['draft'];
        return app(AutomotivePartDraftReviewService::class)->approve($draft, $this->user, 'Aprobación Fase 7');
    }

    private function eligiblePart(bool $compatibility = false): AutomotivePart
    {
        $this->sequence++; $part = AutomotivePart::query()->create(['source_key' => 'publisher-'.$this->sequence, 'item_number' => 'ITEM-'.$this->sequence,
            'manufacturer_part_number' => 'MPN-'.$this->sequence, 'vendor' => 'ACME', 'vendor_normalized' => 'acme', 'category' => 'BRAKES',
            'description_original' => 'Brake part', 'description_normalized' => 'brake part', 'quantity' => 3, 'original_currency' => 'USD',
            'retail_price_original' => 100, 'prevalent_model' => 'Modelo X', 'applicable_models_text' => 'Modelo X', 'data_status' => 'imported']);
        $review = AutomotivePartEnrichmentReview::query()->create(['automotive_part_id' => $part->id, 'status' => 'approved', 'issue_codes' => [],
            'proposed_title' => 'Balata de freno ACME para Modelo X',
            'proposed_description' => 'Balata de freno para Modelo X con materiales y aplicación respaldados por la revisión interna.',
            'proposed_brand' => 'ACME', 'proposed_compatibility' => $compatibility ? [['make' => 'ACME', 'model' => 'Modelo X']] : [], 'proposed_attributes' => [], 'enrichment_source' => 'manual',
            'reviewed_by' => $this->user->id, 'reviewed_at' => now()]);
        $category = AutomotivePartMeliCategory::query()->firstOrCreate(['site_id' => 'MLM', 'category_id' => 'MLM123'], ['name' => 'Balatas',
            'domain_id' => 'MLM-VEHICLE_BRAKE_PADS', 'path_from_root' => [['id' => 'MLM123', 'name' => 'Balatas']],
            'settings' => ['listing_allowed' => true], 'raw_payload' => ['id' => 'MLM123'], 'synced_at' => now(), 'attributes_synced_at' => now()]);
        foreach ([['MPN', 'Número de parte'], ['ITEM_CONDITION', 'Condición']] as [$id, $name]) AutomotivePartMeliAttributeRequirement::query()->firstOrCreate([
            'automotive_part_meli_category_id' => $category->id, 'attribute_id' => $id], ['name' => $name, 'value_type' => 'string',
            'tags' => ['required' => true], 'is_required' => true, 'is_catalog_required' => false, 'is_conditional_required' => false,
            'allowed_values' => $id === 'ITEM_CONDITION' ? [['id' => 'new', 'name' => 'Nuevo']] : null, 'raw_payload' => ['id' => $id]]);
        $candidate = AutomotivePartMeliCategoryCandidate::query()->create(['automotive_part_id' => $part->id, 'automotive_part_enrichment_review_id' => $review->id,
            'status' => 'approved', 'category_id' => 'MLM123', 'category_name' => 'Balatas', 'domain_id' => 'MLM-VEHICLE_BRAKE_PADS',
            'source' => 'manual', 'evidence' => ['validated' => true], 'reviewed_by' => $this->user->id, 'reviewed_at' => now()]);
        AutomotivePartMeliReadiness::query()->create(['automotive_part_id' => $part->id, 'approved_category_candidate_id' => $candidate->id,
            'status' => 'ready', 'proposed_attributes' => [
                ['attribute_id' => 'ITEM_CONDITION', 'value' => 'Nuevo', 'value_id' => 'new', 'source_field' => 'category_attribute', 'confidence' => 1, 'warnings' => []],
                ['attribute_id' => 'MPN', 'value' => $part->manufacturer_part_number, 'value_id' => null, 'source_field' => 'manufacturer_part_number', 'confidence' => 1, 'warnings' => []]],
            'missing_required_attributes' => [], 'missing_conditional_attributes' => [], 'compatibility_requirements' => ['required' => false],
            'warnings' => [], 'evaluation_fingerprint' => hash('sha256', 'ready-'.$part->id), 'reviewed_by' => $this->user->id,
            'reviewed_at' => now(), 'last_evaluated_at' => now()->addSecond()]);
        $fake = UploadedFile::fake()->image('part.png', 20, 20); $contents = file_get_contents($fake->getRealPath()); $path = 'autopartes/media/'.$part->id.'/approved.png'; Storage::disk('local')->put($path, $contents);
        AutomotivePartMedia::query()->create(['automotive_part_id' => $part->id, 'disk' => 'local', 'path' => $path, 'original_name' => 'part.png',
            'stored_name' => 'approved.png', 'detected_mime' => 'image/png', 'extension' => 'png', 'size_bytes' => strlen($contents), 'width' => 20,
            'height' => 20, 'sha256' => hash('sha256', $contents), 'position' => 1, 'is_primary' => true, 'approved_primary_slot' => $part->id,
            'status' => 'approved', 'provenance_type' => 'owned_photo', 'uploaded_by' => $this->user->id, 'approved_by' => $this->user->id,
            'uploaded_at' => now(), 'approved_at' => now(), 'metadata' => ['content_verified' => true]]);
        return $part->fresh();
    }

    private function expectPublisherError(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Se esperaba '.$code); } catch (AutomotivePartMeliPublisherException $exception) { $this->assertSame($code, $exception->errorCode); }
    }
}
