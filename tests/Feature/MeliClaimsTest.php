<?php

namespace Tests\Feature;

use App\Jobs\SyncMeliClaimJob;
use App\Models\MeliAccount;
use App\Models\MeliClaim;
use App\Models\User;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeliClaimsTest extends TestCase
{
    private object $migration;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Sleep::fake();
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password');
            $table->timestamp('email_verified_at')->nullable(); $table->rememberToken();
            $table->text('two_factor_secret')->nullable(); $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable(); $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id'); $table->string('nickname')->nullable(); $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable(); $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable(); $table->boolean('is_default')->default(false); $table->timestamps();
        });
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id(); $table->foreignId('meli_account_id')->nullable(); $table->string('order_id')->unique();
            $table->string('status')->nullable(); $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('meli_order_id'); $table->string('item_id')->nullable();
            $table->string('sku')->nullable(); $table->string('title')->nullable(); $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 14, 2)->nullable(); $table->timestamps();
        });
        $this->migration = require database_path('migrations/2026_08_29_000001_create_meli_claim_tables.php');
        $this->migration->up();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        foreach (['meli_order_items', 'meli_orders', 'meli_accounts', 'users'] as $table) Schema::dropIfExists($table);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_sync_is_idempotent_and_persists_read_only_claim_resources(): void
    {
        $account = $this->account();
        $this->fakeClaimApi('open');
        $service = app(MeliClaimsService::class);

        $service->syncAccount($account);
        $service->syncAccount($account);

        $this->assertSame(1, MeliClaim::query()->count());
        $claim = MeliClaim::query()->sole();
        $this->assertSame('open', $claim->status);
        $this->assertSame('respondent', $claim->action_responsible);
        $this->assertTrue($claim->affects_reputation);
        $this->assertSame('Revisión requerida', $claim->detail_title);
        $this->assertSame('No corresponde', $claim->reason->name);
        $this->assertCount(1, $claim->status_history['data']);
        $this->assertCount(1, $claim->actions_history['data']);
        $this->assertNotEmpty($claim->expected_resolutions);
        $this->assertNotEmpty($claim->available_actions);
        $this->assertArrayNotHasKey('user_id', $claim->raw_claim['players'][0]);
        $requests = collect(Http::recorded())->pluck(0);
        $this->assertTrue($requests->every(fn (Request $request): bool => $request->method() === 'GET'));
        $this->assertTrue($requests->contains(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/expected-resolutions')));
        $this->assertFalse($requests->contains(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/expected_resolutions')));
        $reasonRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/reasons/'))
            ->count();
        $this->assertSame(1, $reasonRequests);
    }

    public function test_closed_claim_updates_and_api_error_does_not_delete_local_record(): void
    {
        $account = $this->account();
        $this->fakeClaimApi('open');
        app(MeliClaimsService::class)->syncClaim($account, '123');
        $this->fakeClaimApi('closed');
        app(MeliClaimsService::class)->syncClaim($account, '123');
        $this->assertSame('closed', MeliClaim::query()->sole()->status);

        Http::fake(fn () => Http::response(['message' => 'temporary'], 500));
        try { app(MeliClaimsService::class)->syncClaim($account, '123'); } catch (\Throwable) {}
        $this->assertDatabaseHas('meli_claims', ['claim_id' => '123', 'status' => 'closed']);
    }

    public function test_same_claim_id_is_isolated_by_account(): void
    {
        $first = $this->account();
        $second = $this->account(['meli_user_id' => 'SELLER-2']);
        $this->fakeClaimApi('open');
        app(MeliClaimsService::class)->syncClaim($first, '123');
        app(MeliClaimsService::class)->syncClaim($second, '123');
        $this->assertSame(2, MeliClaim::query()->where('claim_id', '123')->count());
    }

    public function test_inbox_filters_counts_searches_and_blocks_foreign_claim(): void
    {
        $account = $this->account();
        $otherUser = User::factory()->create();
        $foreign = $this->account(['user_id' => $otherUser->id, 'meli_user_id' => 'FOREIGN']);
        $orderId = DB::table('meli_orders')->insertGetId(['meli_account_id' => $account->id, 'order_id' => 'ORDER-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('meli_order_items')->insert(['meli_order_id' => $orderId, 'item_id' => 'MLM123', 'sku' => 'SKU-CLAIM', 'title' => 'Producto reclamado', 'quantity' => 1, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()]);
        $own = $this->claim($account, ['meli_order_id' => $orderId, 'status' => 'open', 'stage' => 'claim', 'affects_reputation' => true, 'order_id' => 'ORDER-1']);
        $foreignClaim = $this->claim($foreign, ['claim_id' => '999']);

        $this->get(route('meli.claims.index', ['account' => $account->id, 'status' => 'open', 'stage' => 'claim', 'reputation' => 'yes', 'search' => 'SKU-CLAIM']))
            ->assertInertia(fn (Assert $page) => $page->has('claims.data', 1)->where('claims.data.0.id', $own->id)->where('stats.open', 1));
        $this->get(route('meli.claims.show', $foreignClaim))->assertNotFound();
    }

    public function test_post_purchase_claims_dispatches_without_leading_resource_slash(): void
    {
        Bus::fake();
        $account = $this->account(['meli_user_id' => '12345']);
        $this->postJson('/api/meli/webhook', ['topic' => 'post_purchase', 'actions' => ['claims'], 'resource' => 'post-purchase/v1/claims/777', 'user_id' => '12345'])->assertOk();
        Bus::assertDispatched(SyncMeliClaimJob::class, fn ($job) => $job->meliAccountId === $account->id && $job->claimId === '777');
    }

    public function test_post_purchase_claim_actions_dispatches_with_leading_resource_slash(): void
    {
        Bus::fake();
        $account = $this->account(['meli_user_id' => '12345']);
        $this->postJson('/api/meli/webhook', ['topic' => 'post_purchase', 'actions' => ['claims_actions'], 'resource' => '/post-purchase/v1/claims/778', 'user_id' => '12345'])->assertOk();
        Bus::assertDispatched(SyncMeliClaimJob::class, fn ($job) => $job->meliAccountId === $account->id && $job->claimId === '778');
    }

    public function test_unrelated_post_purchase_action_is_ignored_and_existing_topic_still_works(): void
    {
        Bus::fake();
        $this->account(['meli_user_id' => '12345']);
        $this->postJson('/api/meli/webhook', ['topic' => 'post_purchase', 'actions' => ['returns'], 'resource' => '/post-purchase/v1/claims/779', 'user_id' => '12345'])
            ->assertOk()->assertJsonPath('reason', 'unsupported_post_purchase_action');
        Bus::assertNotDispatched(SyncMeliClaimJob::class);
        $this->postJson('/api/meli/webhook', ['topic' => 'items', 'resource' => '/items/MLM123'])->assertOk()->assertJsonPath('reason', 'items_job_disabled');
    }

    private function fakeClaimApi(string $status): void
    {
        Http::fake(function (Request $request) use ($status) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/search')) return Http::response(['data' => [['id' => 123]], 'paging' => ['total' => 1]]);
            if (str_ends_with($path, '/detail')) return Http::response(['due_date' => now()->addHours(4)->toISOString(), 'action_responsible' => 'respondent', 'title' => 'Revisión requerida', 'description' => 'Detalle', 'problem' => 'Producto diferente']);
            if (str_contains($path, '/reasons/')) return Http::response(['id' => 'PDD', 'name' => 'No corresponde', 'detail' => 'Producto diferente', 'flow' => 'mediations']);
            if (str_ends_with($path, '/affects-reputation')) return Http::response(['affects_reputation' => 'affected', 'has_incentive' => false]);
            if (str_ends_with($path, '/status-history')) return Http::response(['data' => [['status' => $status, 'date' => now()->toISOString()]]]);
            if (str_ends_with($path, '/actions-history')) return Http::response(['data' => [['action' => 'claim_opened']]]);
            if (str_ends_with($path, '/expected-resolutions')) return Http::response(['data' => [['type' => 'return']]]);
            return Http::response(['id' => 123, 'resource' => 'order', 'resource_id' => 'ORDER-1', 'status' => $status, 'stage' => 'claim', 'type' => 'mediations', 'reason_id' => 'PDD', 'players' => [['role' => 'respondent', 'type' => 'seller', 'available_actions' => [['action' => 'allow_return', 'due_date' => now()->addHours(4)->toISOString()]]]], 'date_created' => now()->subDay()->toISOString(), 'last_updated' => now()->toISOString()]);
        });
    }

    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->create(['user_id' => $this->user->id, 'access_token' => 'token', 'expires_at' => now()->addHour(), ...$overrides]);
    }

    private function claim(MeliAccount $account, array $overrides = []): MeliClaim
    {
        return MeliClaim::query()->create(['meli_account_id' => $account->id, 'claim_id' => '123', 'date_created' => now(), 'last_synced_at' => now(), ...$overrides]);
    }
}
