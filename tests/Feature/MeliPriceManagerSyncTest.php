<?php

namespace Tests\Feature;

use App\Jobs\SyncMeliPriceManagerItemsJob;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliPriceManagerSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MeliPriceManagerSyncTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('meli_id')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
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
            $table->unique(['user_id', 'meli_user_id']);
        });

        $this->migration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->migration->up();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        $this->migration->down();
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_new_publication_is_created_with_remote_fields_and_raw_data(): void
    {
        $account = $this->account();
        $item = $this->item('MLM123', [
            'category_id' => 'MLM1000',
            'listing_type_id' => 'gold_special',
            'catalog_product_id' => 'MLM-CATALOG-1',
            'original_price' => 650,
            'available_quantity' => 8,
            'sold_quantity' => 4,
            'permalink' => 'https://articulo.mercadolibre.com.mx/MLM-123',
            'thumbnail' => 'https://http2.mlstatic.com/item.jpg',
        ]);
        $this->fakeCatalog([$item]);

        $summary = $this->service()->syncAccount($account);
        $saved = MeliPriceManagerItem::query()->firstOrFail();

        $this->assertSame(['total_found' => 1, 'processed' => 1, 'created' => 1, 'updated' => 0, 'failed' => 0], array_intersect_key($summary, array_flip([
            'total_found', 'processed', 'created', 'updated', 'failed',
        ])));
        $this->assertSame('MLM123', $saved->meli_item_id);
        $this->assertSame('Producto MLM123', $saved->title);
        $this->assertSame('500.00', $saved->current_price);
        $this->assertSame('uncategorized', $saved->classification_status);
        $this->assertSame('MLM1000', $saved->category_id);
        $this->assertSame('MLM123', $saved->raw_item['id']);
        $this->assertNotNull($saved->last_synced_at);
    }

    public function test_existing_publication_is_updated_without_creating_a_duplicate(): void
    {
        $account = $this->account();
        $detailCalls = 0;
        Http::fake(function (Request $request) use (&$detailCalls) {
            if (str_contains($request->url(), '/items/search')) {
                return Http::response(['results' => ['MLM123']]);
            }

            $detailCalls++;

            return Http::response([[
                'code' => 200,
                'body' => $this->item('MLM123', ['price' => $detailCalls === 1 ? 500 : 550]),
            ]]);
        });
        $this->service()->syncAccount($account);

        $summary = $this->service()->syncAccount($account);

        $this->assertSame(1, MeliPriceManagerItem::query()->count());
        $this->assertSame('550.00', MeliPriceManagerItem::query()->firstOrFail()->current_price);
        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, $summary['updated']);
    }

    public function test_same_item_id_is_stored_independently_for_two_accounts(): void
    {
        $firstAccount = $this->account();
        $secondAccount = $this->account();
        $this->fakeCatalog([$this->item('MLM123')]);

        $this->service()->syncAccount($firstAccount);
        $this->service()->syncAccount($secondAccount);

        $this->assertSame(2, MeliPriceManagerItem::query()->where('meli_item_id', 'MLM123')->count());
        $this->assertSame(1, $firstAccount->priceManagerItems()->count());
        $this->assertSame(1, $secondAccount->priceManagerItems()->count());
    }

    public function test_brand_is_extracted_and_normalized_without_classifying(): void
    {
        $account = $this->account();
        $this->fakeCatalog([$this->item('MLM123', [
            'attributes' => [['id' => 'BRAND', 'value_name' => '  Álfaparf   Miláno  ']],
        ])]);

        $this->service()->syncAccount($account);
        $saved = MeliPriceManagerItem::query()->firstOrFail();

        $this->assertSame('Álfaparf   Miláno', $saved->meli_brand);
        $this->assertSame('ALFAPARF MILANO', $saved->normalized_brand);
        $this->assertNull($saved->brand_group_id);
        $this->assertSame('uncategorized', $saved->classification_status);
    }

    public function test_missing_brand_is_stored_as_null_without_failing(): void
    {
        $account = $this->account();
        $this->fakeCatalog([$this->item('MLM123', ['attributes' => []])]);

        $summary = $this->service()->syncAccount($account);
        $saved = MeliPriceManagerItem::query()->firstOrFail();

        $this->assertNull($saved->meli_brand);
        $this->assertNull($saved->normalized_brand);
        $this->assertSame(0, $summary['failed']);
    }

    public function test_seller_sku_attribute_is_extracted_with_priority_and_missing_sku_stays_null(): void
    {
        $account = $this->account();
        $withSku = $this->item('MLM123', [
            'seller_custom_field' => 'LEGACY-SKU',
            'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'ATTRIBUTE-SKU']],
        ]);
        $withoutSku = $this->item('MLM124', ['attributes' => []]);
        $this->fakeCatalog([$withSku, $withoutSku]);

        $this->service()->syncAccount($account);

        $this->assertSame('ATTRIBUTE-SKU', MeliPriceManagerItem::query()->where('meli_item_id', 'MLM123')->value('sku'));
        $this->assertNull(MeliPriceManagerItem::query()->where('meli_item_id', 'MLM124')->value('sku'));
    }

    public function test_scan_pagination_processes_all_120_ids_and_multiget_batches(): void
    {
        $account = $this->account();
        $items = [];
        for ($index = 1; $index <= 120; $index++) {
            $id = 'MLM'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $items[$id] = $this->item($id);
        }

        Http::fake(function (Request $request) use ($items) {
            if (str_contains($request->url(), '/items/search')) {
                $query = $this->query($request);

                return match ($query['scroll_id'] ?? null) {
                    'page-2' => Http::response(['results' => array_slice(array_keys($items), 50, 50), 'scroll_id' => 'page-3']),
                    'page-3' => Http::response(['results' => array_slice(array_keys($items), 100, 20)]),
                    default => Http::response(['results' => array_slice(array_keys($items), 0, 50), 'scroll_id' => 'page-2']),
                };
            }

            if (str_contains($request->url(), '/items?')) {
                $requestedIds = explode(',', (string) ($this->query($request)['ids'] ?? ''));

                return Http::response(array_map(static fn (string $id): array => [
                    'code' => 200,
                    'body' => $items[$id],
                ], $requestedIds));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $summary = $this->service()->syncAccount($account);

        $this->assertSame(120, $summary['total_found']);
        $this->assertSame(120, $summary['processed']);
        $this->assertSame(120, $summary['created']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(120, MeliPriceManagerItem::query()->count());
        Http::assertSentCount(9);
    }

    public function test_one_failed_item_does_not_stop_the_other_items(): void
    {
        $account = $this->account();
        $items = [];
        for ($index = 1; $index <= 10; $index++) {
            $items[] = $this->item('MLM'.str_pad((string) $index, 3, '0', STR_PAD_LEFT));
        }
        $this->fakeCatalog($items, ['MLM010' => 500]);

        $summary = $this->service()->syncAccount($account);

        $this->assertSame(10, $summary['processed']);
        $this->assertSame(9, $summary['created']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame('MLM010', $summary['error_details'][0]['meli_item_id']);
        $this->assertSame(500, $summary['error_details'][0]['http_status']);
        $this->assertSame(9, MeliPriceManagerItem::query()->count());
    }

    public function test_existing_manual_classification_is_preserved_during_sync(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $existing = MeliPriceManagerItem::factory()->for($account, 'meliAccount')->for($group, 'brandGroup')->create([
            'meli_item_id' => 'MLM123',
            'classification_status' => 'categorized',
            'classification_source' => 'manual',
            'classification_confidence' => '1.0000',
            'current_price' => 400,
        ]);
        $this->fakeCatalog([$this->item('MLM123', ['price' => 550])]);

        $this->service()->syncAccount($account);
        $existing->refresh();

        $this->assertTrue($existing->brandGroup->is($group));
        $this->assertSame('categorized', $existing->classification_status);
        $this->assertSame('manual', $existing->classification_source);
        $this->assertSame('1.0000', $existing->classification_confidence);
        $this->assertSame('550.00', $existing->current_price);
    }

    public function test_identical_sync_is_idempotent_and_counts_the_second_run_as_updated(): void
    {
        $account = $this->account();
        $this->fakeCatalog([$this->item('MLM123')]);

        $first = $this->service()->syncAccount($account);
        $second = $this->service()->syncAccount($account);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, MeliPriceManagerItem::query()->count());
    }

    public function test_unauthorized_response_refreshes_the_existing_account_token_and_retries_once(): void
    {
        config()->set('services.meli.client_id', 'client-id');
        config()->set('services.meli.client_secret', 'client-secret');
        $account = $this->account(['refresh_token' => 'old-refresh']);
        $searchCalls = 0;

        Http::fake(function (Request $request) use (&$searchCalls) {
            if ($request->url() === 'https://api.mercadolibre.com/oauth/token') {
                return Http::response([
                    'access_token' => 'new-access',
                    'refresh_token' => 'new-refresh',
                    'expires_in' => 21600,
                ]);
            }

            if (str_contains($request->url(), '/items/search')) {
                $searchCalls++;

                return $searchCalls === 1
                    ? Http::response(['message' => 'unauthorized'], 401)
                    : Http::response(['results' => ['MLM123']]);
            }

            return Http::response([['code' => 200, 'body' => $this->item('MLM123')]]);
        });

        $summary = $this->service()->syncAccount($account);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(2, $searchCalls);
        $this->assertSame('new-access', $account->fresh()->access_token);
        $this->assertSame('new-refresh', $account->fresh()->refresh_token);
    }

    public function test_rate_limit_retry_after_and_server_error_use_limited_retries(): void
    {
        $account = $this->account();
        $searchCalls = 0;

        Http::fake(function (Request $request) use (&$searchCalls) {
            if (str_contains($request->url(), '/items/search')) {
                $searchCalls++;

                return match ($searchCalls) {
                    1 => Http::response(['message' => 'rate limited'], 429, ['Retry-After' => '2']),
                    2 => Http::response(['message' => 'temporary'], 503),
                    default => Http::response(['results' => ['MLM123']]),
                };
            }

            return Http::response([['code' => 200, 'body' => $this->item('MLM123')]]);
        });

        $summary = $this->service()->syncAccount($account);

        $this->assertSame(1, $summary['created']);
        $this->assertSame(3, $searchCalls);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_command_dispatches_the_unique_job_to_the_existing_meli_queue(): void
    {
        Bus::fake();
        $account = $this->account();

        $this->artisan('meli:price-manager-sync', ['--account' => $account->id])
            ->expectsOutput("Sincronización encolada para la cuenta #{$account->id} en la cola meli.")
            ->assertSuccessful();

        Bus::assertDispatched(SyncMeliPriceManagerItemsJob::class, static fn (SyncMeliPriceManagerItemsJob $job): bool => $job->meliAccountId === $account->id && $job->queue === 'meli'
        );
    }

    private function service(): MeliPriceManagerSyncService
    {
        return app(MeliPriceManagerSyncService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->create([
            'access_token' => 'test-access-token',
            'expires_at' => now()->addHour(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function item(string $id, array $overrides = []): array
    {
        return [
            'id' => $id,
            'title' => 'Producto '.$id,
            'category_id' => 'MLM1000',
            'listing_type_id' => 'gold_special',
            'catalog_product_id' => null,
            'price' => 500,
            'original_price' => null,
            'available_quantity' => 10,
            'sold_quantity' => 2,
            'currency_id' => 'MXN',
            'status' => 'active',
            'permalink' => 'https://example.test/'.$id,
            'thumbnail' => 'https://example.test/'.$id.'.jpg',
            'attributes' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $itemErrors
     */
    private function fakeCatalog(array $items, array $itemErrors = []): void
    {
        $itemsById = collect($items)->keyBy('id')->all();

        Http::fake(function (Request $request) use ($itemsById, $itemErrors) {
            if (str_contains($request->url(), '/items/search')) {
                return Http::response(['results' => array_keys($itemsById)]);
            }

            if (str_contains($request->url(), '/items?')) {
                $requestedIds = explode(',', (string) ($this->query($request)['ids'] ?? ''));

                return Http::response(array_map(static function (string $id) use ($itemsById, $itemErrors): array {
                    if (isset($itemErrors[$id])) {
                        return ['code' => $itemErrors[$id], 'body' => ['id' => $id, 'message' => 'temporary item failure']];
                    }

                    return ['code' => 200, 'body' => $itemsById[$id]];
                }, $requestedIds));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });
    }

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
