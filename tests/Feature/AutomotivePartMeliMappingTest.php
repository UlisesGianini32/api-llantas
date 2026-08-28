<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\MapAutomotivePartToMeliCategoriesJob;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartEnrichmentReview;
use App\Models\AutomotivePartMeliCategory;
use App\Models\AutomotivePartMeliCategoryCandidate;
use App\Models\MeliAccount;
use App\Models\User;
use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySuggestionService;
use App\Services\Autopartes\Meli\AutomotivePartMeliCategorySyncService;
use App\Services\Autopartes\Meli\AutomotivePartMeliConfiguration;
use App\Services\Autopartes\Meli\AutomotivePartMeliException;
use App\Services\Autopartes\Meli\AutomotivePartMeliReadinessService;
use App\Services\Autopartes\Meli\AutomotivePartMeliReviewService;
use App\Services\Autopartes\Meli\MercadoLibreCatalogMetadataClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutomotivePartMeliMappingTest extends TestCase
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
        config()->set('cache.default', 'array');
        config()->set('autopartes_meli.enabled', true);
        config()->set('autopartes_meli.site_id', 'MLM');
        config()->set('autopartes_meli.base_url', 'https://api.mercadolibre.com');
        config()->set('autopartes_meli.timeout', 2);
        config()->set('autopartes_meli.cache_ttl', 86400);
        config()->set('autopartes_meli.max_batch', 10);
        config()->set('autopartes_meli.max_daily_requests', 100);
        config()->set('autopartes_meli.max_candidates', 5);
        config()->set('autopartes_meli.deterministic_rules', []);
        DB::purge('sqlite');
        Cache::flush();
        Http::preventStrayRequests();
        $this->withoutMiddleware(HandleInertiaRequests::class);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('meli_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        foreach ([
            '2026_08_21_000001_create_automotive_part_tables.php',
            '2026_08_22_000001_create_automotive_part_enrichment_reviews_table.php',
            '2026_08_22_000003_create_automotive_part_meli_mapping_tables.php',
        ] as $migration) {
            $instance = require database_path('migrations/'.$migration);
            $instance->up();
            $this->migrations[] = $instance;
        }

        $this->user = User::query()->create([
            'name' => 'Meli Reviewer',
            'email' => 'meli-reviewer@example.test',
            'password' => 'password',
        ]);
        MeliAccount::query()->create([
            'user_id' => $this->user->id,
            'meli_user_id' => '123456',
            'access_token' => str_repeat('t', 32),
            'expires_at' => now()->addHour(),
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->migrations) as $migration) {
            $migration->down();
        }
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_disabled_integration_and_dry_run_never_call_mercado_libre(): void
    {
        $part = $this->createPart();
        config()->set('autopartes_meli.enabled', false);
        Queue::fake();

        try {
            app(MercadoLibreCatalogMetadataClient::class)->category('MLM123');
            $this->fail('La integración deshabilitada debió impedir la solicitud.');
        } catch (AutomotivePartMeliException $exception) {
            $this->assertSame('integration_disabled', $exception->errorCode);
        }

        $this->assertSame(0, Artisan::call('autopartes:meli-map', [
            '--part-id' => $part->id,
            '--limit' => 1,
            '--dry-run' => true,
        ]));
        $this->assertStringContainsString('Dry-run', Artisan::output());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('automotive_part_meli_category_candidates', 0);
    }

    public function test_metadata_client_blocks_writes_items_and_unapproved_paths(): void
    {
        $client = app(MercadoLibreCatalogMetadataClient::class);

        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            try {
                $client->request($method, '/items/MLM123');
                $this->fail("{$method} /items debió bloquearse.");
            } catch (AutomotivePartMeliException $exception) {
                $this->assertSame('method_not_allowed', $exception->errorCode);
            }
        }

        foreach (['/items/MLM123', '/orders/1', '/messages/1', '/users/1/items/search'] as $path) {
            try {
                $client->request('GET', $path);
                $this->fail("GET {$path} debió bloquearse.");
            } catch (AutomotivePartMeliException $exception) {
                $this->assertSame('path_not_allowed', $exception->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    public function test_category_sync_is_idempotent_cacheable_and_refreshable(): void
    {
        $payload = $this->categoryPayload('MLM123');
        Http::fakeSequence()->push($payload)->push(array_merge($payload, ['name' => 'Balatas actualizadas']));
        $client = app(MercadoLibreCatalogMetadataClient::class);
        $service = new AutomotivePartMeliCategorySyncService(
            $client,
            app(AutomotivePartMeliConfiguration::class),
        );

        $first = $service->syncCategory('MLM123');
        $second = $service->syncCategory('MLM123');
        $refreshed = $service->syncCategory('MLM123', true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $refreshed->id);
        $this->assertSame('Balatas actualizadas', $refreshed->name);
        $this->assertDatabaseCount('automotive_part_meli_categories', 1);
        $this->assertSame(2, $client->stats()['requests']);
        $this->assertSame(1, $client->stats()['cache_hits']);
        Http::assertSentCount(2);
    }

    public function test_transient_429_retries_but_401_and_403_do_not(): void
    {
        $sequence = Http::sequence()
            ->push(['error' => 'too_many_requests', 'message' => 'Wait'], 429)
            ->push(['error' => 'too_many_requests', 'message' => 'Wait'], 429)
            ->push(['error' => 'too_many_requests', 'message' => 'Wait'], 429)
            ->push(['error' => 'unauthorized', 'message' => 'Unauthorized'], 401)
            ->push(['error' => 'forbidden', 'message' => 'Forbidden'], 403);
        Http::fake(['*' => $sequence]);
        $client = app(MercadoLibreCatalogMetadataClient::class);

        try {
            $client->category('MLM123', true);
            $this->fail('429 debió agotar los reintentos.');
        } catch (AutomotivePartMeliException $exception) {
            $this->assertTrue($exception->transient);
            $this->assertSame('too_many_requests', $exception->errorCode);
        }

        try {
            $client->category('MLM124', true);
            $this->fail('401 debió fallar.');
        } catch (AutomotivePartMeliException $exception) {
            $this->assertFalse($exception->transient);
            $this->assertSame('unauthorized', $exception->errorCode);
        }

        try {
            $client->category('MLM125', true);
            $this->fail('403 debió fallar sin reintentos.');
        } catch (AutomotivePartMeliException $exception) {
            $this->assertFalse($exception->transient);
            $this->assertSame('forbidden', $exception->errorCode);
        }

        Http::assertSentCount(5);
    }

    public function test_errors_are_sanitized_and_logs_never_contain_tokens(): void
    {
        $secret = str_repeat('s', 32);
        MeliAccount::query()->update(['access_token' => $secret]);
        Log::spy();
        Http::fake(['*' => Http::response([
            'error' => 'bad_request',
            'message' => 'authorization='.$secret.' access_token='.$secret,
        ], 400)]);

        try {
            app(MercadoLibreCatalogMetadataClient::class)->category('MLM123', true);
            $this->fail('La solicitud debió fallar.');
        } catch (AutomotivePartMeliException $exception) {
            $this->assertStringNotContainsString($secret, $exception->getMessage());
            $this->assertStringContainsString('[REDACTED]', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $secret));
    }

    public function test_deterministic_and_discovery_candidates_remain_pending_without_assumed_score(): void
    {
        config()->set('autopartes_meli.max_candidates', 2);
        config()->set('autopartes_meli.deterministic_rules', [[
            'internal_category' => 'ROTORS',
            'internal_subcategory' => 'BRAKE',
            'category_id' => 'MLM100',
        ]]);
        $part = $this->createPart(['category' => 'ROTORS', 'subcategory' => 'BRAKE']);
        $this->fakeMetadata([
            ['category_id' => 'MLM101', 'category_name' => 'Discos de freno', 'domain_id' => 'MLM-BRAKE_DISCS'],
            ['category_id' => 'MLM102', 'category_name' => 'Tambores', 'domain_id' => 'MLM-BRAKE_DRUMS', 'score' => 0.7],
        ]);

        $result = app(AutomotivePartMeliCategorySuggestionService::class)->suggest($part);

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('automotive_part_meli_category_candidates', [
            'automotive_part_id' => $part->id,
            'category_id' => 'MLM100',
            'source' => 'deterministic',
            'status' => 'pending',
        ]);
        $discovered = AutomotivePartMeliCategoryCandidate::query()->where('category_id', 'MLM101')->firstOrFail();
        $this->assertSame('domain_discovery', $discovered->source);
        $this->assertNull($discovered->score);
        $this->assertDatabaseMissing('automotive_part_meli_category_candidates', ['status' => 'approved']);
        $repeat = app(AutomotivePartMeliCategorySuggestionService::class)->suggest($part);
        $this->assertSame(0, $repeat['created']);
        $this->assertDatabaseCount('automotive_part_meli_category_candidates', 2);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && ! str_contains($request->url(), '/items'));
    }

    public function test_manual_candidate_is_validated_audited_and_not_auto_approved(): void
    {
        $part = $this->createPart();
        $this->fakeMetadata();

        $candidate = app(AutomotivePartMeliReviewService::class)
            ->createManualCandidate($part, 'MLM123', $this->user, 'Selección humana');

        $this->assertSame('manual', $candidate->source);
        $this->assertSame('pending', $candidate->status);
        $this->assertSame($this->user->id, $candidate->evidence['entered_by']);
        $this->assertSame('category_pending', $part->meliReadiness()->firstOrFail()->status);
    }

    public function test_approval_normalizes_requirements_supersedes_others_and_detects_missing_data(): void
    {
        $part = $this->createPart();
        $original = $part->fresh()->getAttributes();
        $approved = $this->candidate($part, 'MLM123');
        $other = $this->candidate($part, 'MLM124');
        $this->fakeMetadata([], [
            $this->attribute('BRAND', 'Marca', ['required' => true], [['id' => '1', 'name' => 'ACME']]),
            $this->attribute('MPN', 'Número de parte', ['catalog_required' => true]),
            $this->attribute('GTIN', 'Código universal', ['required' => true]),
            $this->attribute('COLOR', 'Color', ['conditional_required' => true]),
        ]);

        $readiness = app(AutomotivePartMeliReviewService::class)->approve($approved, $this->user);

        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('superseded', $other->fresh()->status);
        $this->assertSame('incomplete', $readiness->status);
        $this->assertContains('GTIN', collect($readiness->missing_required_attributes)->pluck('attribute_id'));
        $this->assertContains('COLOR', collect($readiness->missing_conditional_attributes)->pluck('attribute_id'));
        $this->assertContains('vendor', collect($readiness->proposed_attributes)->pluck('source_field'));
        $this->assertContains('manufacturer_part_number', collect($readiness->proposed_attributes)->pluck('source_field'));
        $this->assertNotContains('GTIN', collect($readiness->proposed_attributes)->pluck('attribute_id'));
        $this->assertTrue(AutomotivePartMeliCategory::query()->firstOrFail()->attributeRequirements()->where('is_catalog_required', true)->exists());
        $this->assertSame($original, $part->fresh()->getAttributes());
    }

    public function test_complete_attributes_require_final_human_review_before_ready(): void
    {
        $part = $this->createPart();
        $candidate = $this->candidate($part, 'MLM123');
        $this->fakeMetadata([], [
            $this->attribute('BRAND', 'Marca', ['required' => true], [['id' => '1', 'name' => 'ACME']]),
            $this->attribute('MPN', 'Número de parte', ['required' => true]),
        ]);

        $readiness = app(AutomotivePartMeliReviewService::class)->approve($candidate, $this->user);
        $this->assertSame('ready_for_review', $readiness->status);

        $confirmed = app(AutomotivePartMeliReadinessService::class)->confirmReady($part, $this->user, 'Revisión final');
        $this->assertSame('ready', $confirmed->status);
        $this->assertSame($this->user->id, $confirmed->reviewed_by);
        $this->assertNotNull($confirmed->reviewed_at);
    }

    public function test_mapper_never_invents_compatibility_gtin_models_or_years(): void
    {
        $part = $this->createPart([
            'min_model_year' => 2020,
            'max_model_year' => 2024,
            'prevalent_model' => null,
            'applicable_models_text' => null,
        ]);
        $candidate = $this->candidate($part, 'MLM123');
        $this->fakeMetadata([], [
            $this->attribute('GTIN', 'GTIN', ['required' => true]),
            $this->attribute('MODEL', 'Modelo', ['required' => true]),
            $this->attribute('YEAR', 'Año', ['required' => true]),
            $this->attribute('PACKAGE_LENGTH', 'Largo del paquete', ['required' => true]),
        ], true);

        $readiness = app(AutomotivePartMeliReviewService::class)->approve($candidate, $this->user);
        $proposedIds = collect($readiness->proposed_attributes)->pluck('attribute_id');

        $this->assertNotContains('GTIN', $proposedIds);
        $this->assertNotContains('MODEL', $proposedIds);
        $this->assertNotContains('YEAR', $proposedIds);
        $this->assertNotContains('PACKAGE_LENGTH', $proposedIds);
        $this->assertContains('COMPATIBILITY', collect($readiness->missing_required_attributes)->pluck('attribute_id'));
        $this->assertSame('incomplete', $readiness->status);
    }

    public function test_category_pending_and_daily_request_limit_are_enforced(): void
    {
        $part = $this->createPart();
        $this->candidate($part, 'MLM123');
        $this->assertSame('category_pending', app(AutomotivePartMeliReadinessService::class)->evaluate($part)->status);

        config()->set('autopartes_meli.max_daily_requests', 1);
        Http::fake(['*' => Http::response($this->categoryPayload('MLM123'))]);
        $client = app(MercadoLibreCatalogMetadataClient::class);
        $client->category('MLM123', true);

        try {
            $client->category('MLM124', true);
            $this->fail('El límite diario debió impedir otra solicitud.');
        } catch (AutomotivePartMeliException $exception) {
            $this->assertSame('daily_request_limit', $exception->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_endpoints_require_auth_and_form_requests_validate_notes_ids_and_limits(): void
    {
        $part = $this->createPart();
        $candidate = $this->candidate($part, 'MLM123');

        $this->get(route('autopartes.meli.categories.index'))->assertRedirect('/login');
        $this->post(route('autopartes.meli.categories.search', $part))->assertRedirect('/login');
        $this->actingAs($this->user)
            ->post(route('autopartes.meli.categories.reject', $candidate))
            ->assertSessionHasErrors('review_notes');
        $this->post(route('autopartes.meli.categories.manual', $part), ['category_id' => 'MLA123'])
            ->assertSessionHasErrors('category_id');
        $this->post(route('autopartes.meli.categories.batch'), ['limit' => 11])
            ->assertSessionHasErrors('limit');
    }

    public function test_command_honors_limit_and_never_calls_publication_endpoints(): void
    {
        $this->createPart();
        $this->createPart();
        Queue::fake();

        $this->assertSame(0, Artisan::call('autopartes:meli-map', ['--limit' => 1]));
        Queue::assertPushed(MapAutomotivePartToMeliCategoriesJob::class, 1);
        $this->assertSame(1, Artisan::call('autopartes:meli-map', ['--limit' => 11]));
        Http::assertNothingSent();
    }

    private function createPart(array $attributes = []): AutomotivePart
    {
        $this->sequence++;
        $part = AutomotivePart::query()->create(array_merge([
            'source_key' => 'meli-map-'.$this->sequence,
            'item_number' => 'ITEM-'.$this->sequence,
            'manufacturer_part_number' => 'MFG-'.$this->sequence,
            'vendor' => 'ACME',
            'vendor_normalized' => 'acme',
            'category' => 'ROTORS',
            'subcategory' => 'BRAKE',
            'description_original' => 'Brake pad for Modelo X',
            'description_normalized' => 'brake pad for modelo x',
            'quantity' => 2,
            'min_model_year' => 2020,
            'max_model_year' => 2024,
            'prevalent_model' => 'Modelo X',
            'applicable_models_text' => 'Modelo X 2020-2024',
            'length_cm' => 10,
            'width_cm' => 5,
            'height_cm' => 2,
            'weight_kg' => 1.5,
            'data_status' => 'imported',
        ], $attributes));
        AutomotivePartEnrichmentReview::query()->create([
            'automotive_part_id' => $part->id,
            'status' => 'pending',
            'issue_codes' => ['internal_category_requires_mapping'],
            'proposed_title' => 'Balata de freno ACME',
            'proposed_description' => 'Balata de freno para Modelo X con número de parte confirmado.',
            'enrichment_source' => 'rules',
        ]);

        return $part->load('enrichmentReview');
    }

    private function candidate(AutomotivePart $part, string $categoryId): AutomotivePartMeliCategoryCandidate
    {
        return AutomotivePartMeliCategoryCandidate::query()->create([
            'automotive_part_id' => $part->id,
            'automotive_part_enrichment_review_id' => $part->enrichmentReview?->id,
            'status' => 'pending',
            'category_id' => $categoryId,
            'category_name' => 'Balatas',
            'domain_id' => 'MLM-BRAKE_PADS',
            'source' => 'domain_discovery',
            'query_text' => 'balata de freno',
            'position' => 1,
            'score' => null,
            'evidence' => ['query_source' => 'test'],
        ]);
    }

    private function fakeMetadata(array $discoveries = [], array $attributes = [], bool $compatibilityRequired = false): void
    {
        Http::fake(function (Request $request) use ($discoveries, $attributes, $compatibilityRequired) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/domain_discovery/search')) {
                return Http::response($discoveries);
            }
            if (str_ends_with($path, '/attributes')) {
                return Http::response($attributes);
            }
            if (str_starts_with($path, '/catalog_domains/')) {
                return Http::response(['id' => basename($path), 'compatibilities' => ['required' => $compatibilityRequired]]);
            }
            if (preg_match('#/categories/(MLM\d+)$#', $path, $matches)) {
                return Http::response($this->categoryPayload($matches[1]));
            }

            return Http::response(['error' => 'unexpected_path'], 500);
        });
    }

    private function categoryPayload(string $categoryId): array
    {
        return [
            'id' => $categoryId,
            'name' => 'Balatas',
            'catalog_domain' => 'MLM-BRAKE_PADS',
            'path_from_root' => [
                ['id' => 'MLM1747', 'name' => 'Accesorios para Vehículos'],
                ['id' => $categoryId, 'name' => 'Balatas'],
            ],
            'settings' => [
                'listing_allowed' => true,
                'vertical' => 'vehicle_parts_accessories',
            ],
        ];
    }

    private function attribute(string $id, string $name, array $tags = [], array $values = []): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'value_type' => $values === [] ? 'string' : 'list',
            'value_max_length' => 120,
            'tags' => $tags,
            'values' => $values,
            'hierarchy' => 'PARENT_PK',
        ];
    }
}
