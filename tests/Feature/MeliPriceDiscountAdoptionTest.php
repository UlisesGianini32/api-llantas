<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateException;
use App\Services\MercadoLibre\PriceManager\MeliPriceUpdateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeliPriceDiscountAdoptionTest extends TestCase
{
    private MeliAccount $account;

    private MeliPriceManagerItem $item;

    private MeliBeautyScheduledDiscount $rule;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', true);
        DB::purge('sqlite');

        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('meli_user_id');
            $table->text('access_token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('category_id')->unique();
            $table->string('root_category_id')->nullable();
            $table->json('path_from_root')->nullable();
        });
        foreach ([
            '2026_08_26_000001_create_meli_price_manager_tables.php',
            '2026_08_29_000001_add_linked_publication_fields_to_meli_price_manager_items.php',
            '2026_09_07_000001_create_meli_beauty_scheduled_discounts_table.php',
            '2026_09_07_000002_create_meli_scheduled_price_states_table.php',
            '2026_09_07_000003_add_scheduled_source_to_meli_price_changes.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->account = MeliAccount::query()->create(['meli_user_id' => '123', 'access_token' => 'test-token']);
        $brand = MeliBrandGroup::query()->create(['name' => 'Beauty', 'slug' => 'beauty', 'active' => true]);
        $this->item = MeliPriceManagerItem::query()->create([
            'meli_account_id' => $this->account->id, 'brand_group_id' => $brand->id,
            'meli_item_id' => 'MLM4733828880', 'title' => 'Beauty promotion adoption',
            'category_id' => 'MLM-BEAUTY-CATEGORY', 'classification_status' => 'categorized',
            'current_price' => 299.0, 'status' => 'active',
        ]);
        DB::table('meli_categories')->insert([
            'category_id' => $this->item->category_id, 'root_category_id' => 'MLM1246',
        ]);
        $this->rule = MeliBeautyScheduledDiscount::query()->create([
            'meli_account_id' => $this->account->id, 'brand_group_id' => $brand->id,
            'discount_percentage' => 10, 'starts_at' => '17:00', 'ends_at' => '17:30',
            'timezone' => 'America/Hermosillo', 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    #[DataProvider('matchingStatuses')]
    public function test_beauty_dry_run_allows_adoption_and_apply_persists_confirmed_state_without_post(string $status): void
    {
        $this->fakeRemotePrices(['status' => $status]);
        $before = $this->item->fresh()->getAttributes();
        $this->assertNull($this->item->scheduledPriceState);

        DB::enableQueryLog();
        $dryRun = $this->processBeauty(dryRun: true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, $dryRun['apply']);
        $this->assertSame(0, $dryRun['blocked']);
        $this->assertSame(0, $dryRun['failed']);
        $this->assertSame('apply', $dryRun['details'][0]['action']);
        $this->assertSame([], $dryRun['details'][0]['reasons']);
        $this->assertNull($dryRun['details'][0]['allowed_min']);
        $this->assertNull($dryRun['details'][0]['allowed_max']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete|replace)\b/i', $query['query']);
        }
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        $this->assertDatabaseCount('meli_price_changes', 0);
        $this->assertDatabaseCount('meli_price_change_batches', 0);
        $this->assertSame($before, $this->item->fresh()->getAttributes());
        $this->assertNoRemoteWrites();

        $applied = $this->processBeauty();

        $this->assertSame(1, $applied['apply']);
        $this->assertSame(1, $applied['success']);
        $this->assertSame(0, $applied['blocked']);
        $this->assertSame(0, $applied['failed']);
        $state = $this->item->fresh()->scheduledPriceState;
        $this->assertNotNull($state);
        $this->assertSame('active', $state->status);
        $this->assertSame('299.00', $state->base_price);
        $this->assertSame('269.10', $state->promotional_price);
        $this->assertSame('269.10', $state->last_confirmed_remote_price);
        $this->assertSame('269.10', $state->last_observed_remote_price);
        $this->assertSame($this->rule->id, $state->meli_beauty_scheduled_discount_id);
        $this->assertNotNull($state->applied_at);
        $this->assertNull($state->failure_message);
        $this->assertSame('299.00', $this->item->fresh()->current_price);
        $this->assertSuccessfulAudit();
        $this->assertNoRemoteWrites();

        $repeated = $this->processBeauty();
        $this->assertSame(1, $repeated['no_change']);
        $this->assertSame(0, $repeated['blocked']);
        $this->assertDatabaseCount('meli_scheduled_price_states', 1);
        $this->assertDatabaseCount('meli_price_changes', 1);
        $this->assertNoRemoteWrites();
    }

    #[DataProvider('matchingStatuses')]
    public function test_price_update_adopts_existing_promotion_without_post(string $status): void
    {
        $this->fakeRemotePrices(['status' => $status]);

        $result = $this->updatePromotion();

        $this->assertSame('success', $result['result']);
        $this->assertSame(269.1, $result['new_price']);
        $this->assertNotNull($result['change_id']);
        $this->assertNotNull($result['batch_id']);
        $this->assertSame('299.00', $this->item->fresh()->current_price);
        $this->assertSuccessfulAudit();
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        $this->assertNoRemoteWrites();
    }

    #[DataProvider('nonMatchingPromotions')]
    public function test_beauty_and_price_update_block_nonmatching_promotions(array $prices, string $reason): void
    {
        $this->fakeRemotePrices($prices);

        foreach ([true, false] as $dryRun) {
            $result = $this->processBeauty($dryRun);
            $this->assertSame(0, $result['apply']);
            $this->assertSame(1, $result['blocked']);
            $this->assertContains($reason, $result['details'][0]['reasons']);
        }

        try {
            $this->updatePromotion();
            $this->fail('A nonmatching promotion must not be adopted.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame($reason, $exception->errorCode());
        }

        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        $this->assertDatabaseCount('meli_price_changes', 0);
        $this->assertNoRemoteWrites();
    }

    public function test_standard_base_change_blocks_adoption(): void
    {
        $this->fakeRemotePrices(['standard' => 300.0]);

        $dryRun = $this->processBeauty(dryRun: true);
        $this->assertSame(1, $dryRun['blocked']);
        $this->assertContains('price_discount_already_active', $dryRun['details'][0]['reasons']);

        try {
            $this->updatePromotion();
            $this->fail('A changed standard base must not be adopted.');
        } catch (MeliPriceUpdateException $exception) {
            $this->assertSame('concurrent_standard_price_change', $exception->errorCode());
        }
        $this->assertDatabaseCount('meli_scheduled_price_states', 0);
        $this->assertNoRemoteWrites();
    }

    public function test_new_promotion_with_valid_range_still_posts_and_persists_state(): void
    {
        $this->fakeRemotePrices([
            'status' => 'candidate', 'promotion' => null, 'sale' => 299.0, 'regular' => null,
            'min' => 250.0, 'max' => 280.0,
        ], []);

        $result = $this->processBeauty();

        $this->assertSame(1, $result['success']);
        $this->assertSame('active', $this->item->fresh()->scheduledPriceState?->status);
        $this->assertSuccessfulAudit();
        $posts = Http::recorded(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertSame([
            'deal_price' => 269.1,
            'start_date' => '2026-09-07T00:00:00',
            'finish_date' => '2026-09-07T00:00:00',
            'promotion_type' => 'PRICE_DISCOUNT',
        ], $posts->first()[0]->data());
    }

    public static function matchingStatuses(): array
    {
        return [['started'], ['candidate'], ['active']];
    }

    public static function nonMatchingPromotions(): array
    {
        $cases = [];
        foreach (['started', 'active'] as $status) {
            foreach ([
                'different target' => ['promotion' => 260.0, 'sale' => 260.0],
                'different regular amount' => ['regular' => 300.0],
                'missing winning price' => ['promotion' => null],
                'different sale amount' => ['sale' => 260.0],
            ] as $name => $prices) {
                $cases[$status.' '.$name] = [['status' => $status, ...$prices], 'price_discount_already_active'];
            }
        }
        $newPromotion = ['status' => 'candidate', 'promotion' => null, 'sale' => 299.0, 'regular' => null];
        $cases['new promotion without range'] = [$newPromotion, 'promotion_range_unavailable'];
        $cases['new promotion outside range'] = [[...$newPromotion, 'min' => 275.0, 'max' => 290.0], 'target_outside_promotion_range'];
        $cases['new promotion with wrong original'] = [[...$newPromotion, 'min' => 250.0, 'max' => 280.0, 'original' => 300.0], 'promotion_base_mismatch'];
        $cases['candidate without winning price'] = [['status' => 'candidate', 'promotion' => null], 'promotion_range_unavailable'];

        return $cases;
    }

    private function processBeauty(bool $dryRun = false): array
    {
        return CarbonImmutable::withTestNow(CarbonImmutable::parse('2026-09-07 17:15', 'America/Hermosillo'),
            fn (): array => app(MeliBeautyScheduledPriceService::class)->processRule($this->rule, $this->item->meli_item_id, $dryRun));
    }

    private function updatePromotion(): array
    {
        return app(MeliPriceUpdateService::class)->updateScheduledPromotion(
            $this->account, $this->item, $this->rule, 299.0, 269.1, 'apply',
        );
    }

    private function assertSuccessfulAudit(): void
    {
        $this->assertDatabaseHas('meli_price_changes', [
            'price_manager_item_id' => $this->item->id, 'source' => 'scheduled_beauty',
            'scheduled_action' => 'apply', 'status' => 'success', 'new_price' => 269.1,
        ]);
        $this->assertDatabaseHas('meli_price_change_batches', [
            'meli_beauty_scheduled_discount_id' => $this->rule->id,
            'status' => 'completed', 'successful_items' => 1,
        ]);
    }

    private function assertNoRemoteWrites(): void
    {
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/seller-promotions/'));
        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT', 'DELETE'], true));
    }

    private function fakeRemotePrices(array $before = [], ?array $afterPost = null): void
    {
        $posted = false;
        Http::fake(function (Request $request) use ($before, $afterPost, &$posted): mixed {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                $this->assertNotNull($afterPost, 'Adoption must not POST a duplicate promotion.');
                $this->assertSame('/seller-promotions/items/MLM4733828880', $path);
                $posted = true;

                return Http::response([], 201);
            }
            $this->assertSame('GET', $request->method());
            $prices = array_replace([
                'standard' => 299.0, 'promotion' => 269.1, 'sale' => 269.1, 'regular' => 299.0,
                'original' => 299.0, 'status' => 'started', 'min' => null, 'max' => null,
            ], $posted ? $afterPost : $before);

            return match ($path) {
                '/pricing-automation/items/MLM4733828880/automation' => Http::response([], 404),
                '/items/MLM4733828880/prices' => Http::response(['prices' => array_values(array_filter([
                    ['type' => 'standard', 'amount' => $prices['standard']],
                    $prices['promotion'] === null ? null : [
                        'type' => 'promotion', 'amount' => $prices['promotion'], 'regular_amount' => $prices['regular'],
                        'conditions' => ['context_restrictions' => ['channel_marketplace']],
                    ],
                ]))]),
                '/items/MLM4733828880/sale_price' => Http::response([
                    'amount' => $prices['sale'], 'regular_amount' => $prices['regular'],
                ]),
                '/seller-promotions/items/MLM4733828880' => Http::response([[
                    'type' => 'PRICE_DISCOUNT', 'status' => $prices['status'], 'original_price' => $prices['original'],
                    'min_discounted_price' => $prices['min'], 'max_discounted_price' => $prices['max'],
                    'suggested_discounted_price' => null,
                ]]),
                default => $this->fail('Unexpected HTTP request: '.$request->url()),
            };
        });
    }
}
