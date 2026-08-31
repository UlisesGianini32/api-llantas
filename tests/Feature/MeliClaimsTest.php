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
    private object $detailMigration;
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
            $table->string('status')->nullable(); $table->string('display_id')->nullable(); $table->json('raw')->nullable(); $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id(); $table->foreignId('meli_order_id'); $table->string('item_id')->nullable();
            $table->string('sku')->nullable(); $table->string('title')->nullable(); $table->unsignedInteger('quantity')->default(1);
            $table->string('variation_text')->nullable(); $table->decimal('unit_price', 14, 2)->nullable(); $table->timestamps();
        });
        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id')->nullable(); $table->foreignId('meli_account_id')->nullable();
            $table->string('sku')->nullable(); $table->string('mlm'); $table->json('raw')->nullable(); $table->timestamps();
        });
        $this->migration = require database_path('migrations/2026_08_29_000001_create_meli_claim_tables.php');
        $this->migration->up();
        $this->detailMigration = require database_path('migrations/2026_08_31_000001_add_detail_snapshots_to_meli_claims.php');
        $this->detailMigration->up();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->detailMigration->down();
        $this->migration->down();
        foreach (['meli_publications', 'meli_order_items', 'meli_orders', 'meli_accounts', 'users'] as $table) Schema::dropIfExists($table);
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
        $this->assertSame('Mensaje visible', $claim->messages[0]['message']);
        $this->assertArrayNotHasKey('user_id', $claim->messages[0]);
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
        $remoteStatus = 'open';
        $this->fakeClaimApi(function () use (&$remoteStatus): string { return $remoteStatus; });
        app(MeliClaimsService::class)->syncClaim($account, '123');
        $remoteStatus = 'closed';
        app(MeliClaimsService::class)->syncClaim($account, '123', true);
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
            ->assertInertia(fn (Assert $page) => $page->has('claims.data', 1)->where('claims.data.0.id', $own->id)
                ->where('claims.data.0.products.0.mlm', 'MLM123')->where('claims.data.0.products.0.sku', 'SKU-CLAIM')
                ->where('claims.data.0.products.0.title', 'Producto reclamado')->where('stats.open', 1));
        $this->get(route('meli.claims.show', $foreignClaim))->assertNotFound();
    }

    public function test_claim_ui_uses_local_multi_item_order_and_sanitizes_participant_ids(): void
    {
        $account = $this->account(['meli_user_id' => 'PRIMARY', 'nickname' => null, 'is_default' => true]);
        $secondary = $this->account(['meli_user_id' => 'SECONDARY', 'nickname' => null, 'is_default' => false]);
        $orderId = DB::table('meli_orders')->insertGetId(['meli_account_id' => $account->id, 'order_id' => 'ORDER-UI', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('meli_order_items')->insert([
            ['meli_order_id' => $orderId, 'item_id' => 'MLM-A', 'sku' => 'SKU-A', 'title' => 'Producto A', 'quantity' => 2, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['meli_order_id' => $orderId, 'item_id' => 'MLM-B', 'sku' => 'SKU-B', 'title' => 'Producto B', 'quantity' => 1, 'unit_price' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('meli_publications')->insert([
            'user_id' => $this->user->id, 'meli_account_id' => $account->id, 'mlm' => 'MLM-A', 'sku' => 'SKU-A',
            'raw' => json_encode(['thumbnail' => 'https://local.test/product-a.jpg']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('meli_claim_reasons')->insert(['reason_id' => 'missing_accessories', 'name' => 'Missing accessories', 'detail' => 'Faltan accesorios del producto', 'created_at' => now(), 'updated_at' => now()]);
        $claim = $this->claim($account, [
            'meli_order_id' => $orderId, 'order_id' => 'ORDER-UI', 'reason_id' => 'missing_accessories',
            'status' => 'open', 'stage' => 'claim', 'type' => 'mediations', 'action_responsible' => 'respondent',
            'available_actions' => [['action' => 'allow_return']],
            'actions_history' => ['data' => [['action_name' => 'open_claim', 'player_role' => 'complainant']]],
            'expected_resolutions' => ['data' => [['type' => 'return_product', 'player_role' => 'complainant', 'user_id' => 123, 'player' => ['user_id' => 456]]]],
        ]);

        $claim->load('order.items');
        $this->assertSame($orderId, $claim->order?->id);
        $this->assertSame('ORDER-UI', $claim->order?->order_id);
        $this->assertCount(2, $claim->order?->items ?? []);

        Http::fake();
        $this->get(route('meli.claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.reason', 'Faltan accesorios del producto')
            ->where('claim.reason_id', 'missing_accessories')
            ->where('claim.account.is_default', true)
            ->has('claim.products', 2)
            ->where('claim.products.0.mlm', 'MLM-A')->where('claim.products.0.sku', 'SKU-A')
            ->where('claim.products.0.title', 'Producto A')->where('claim.products.0.quantity', 2)
            ->where('claim.products.0.unit_price', 100)->where('claim.products.0.amount', 200)
            ->where('claim.products.0.thumbnail', 'https://local.test/product-a.jpg')
            ->where('claim.products.1.mlm', 'MLM-B')->where('claim.order_amount', 250)
            ->where('claim.expected_resolutions.data.0.type', 'return_product')
            ->missing('claim.expected_resolutions.data.0.user_id')
            ->missing('claim.expected_resolutions.data.0.player.user_id'));
        $this->assertCount(0, Http::recorded());

        $foreignOrderId = DB::table('meli_orders')->insertGetId(['meli_account_id' => $secondary->id, 'order_id' => 'ORDER-FOREIGN', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('meli_order_items')->insert(['meli_order_id' => $foreignOrderId, 'item_id' => 'MLM-FOREIGN', 'sku' => 'SECRET', 'title' => 'Producto ajeno', 'quantity' => 1, 'unit_price' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $crossAccountClaim = $this->claim($account, ['claim_id' => 'CROSS', 'meli_order_id' => $foreignOrderId, 'order_id' => 'ORDER-FOREIGN']);
        $this->get(route('meli.claims.show', $crossAccountClaim))->assertInertia(fn (Assert $page) => $page->has('claim.products', 0)->where('claim.order_amount', null));
        $this->get(route('meli.claims.index', ['account' => $account->id, 'search' => 'SECRET']))
            ->assertInertia(fn (Assert $page) => $page->has('claims.data', 0));
    }

    public function test_detail_requires_authentication_and_shows_safe_empty_states(): void
    {
        $claim = $this->claim($this->account(), ['status' => 'opened', 'stage' => 'claim', 'reason_id' => 'PDD']);
        auth()->logout();
        $this->get(route('meli.claims.show', $claim))->assertRedirect('/login');
        $this->actingAs($this->user)->get(route('meli.claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.claim_id', '123')->where('claim.status', 'opened')->where('claim.stage', 'claim')
            ->where('claim.reason_id', 'PDD')->where('claim.order', null)->has('claim.messages', 0)->has('claim.deadlines', 0));
    }

    public function test_detail_orders_timeline_and_exposes_messages_deadlines_and_variation(): void
    {
        $account = $this->account();
        $orderId = DB::table('meli_orders')->insertGetId([
            'meli_account_id' => $account->id, 'order_id' => 'ORDER-DETAIL', 'status' => 'paid',
            'raw' => json_encode(['date_created' => '2026-08-01T10:00:00Z', 'total_amount' => 500, 'currency_id' => 'MXN', 'order_items' => [['item' => ['id' => 'MLM1', 'seller_sku' => 'SKU1', 'variation_id' => 987]]]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('meli_order_items')->insert(['meli_order_id' => $orderId, 'item_id' => 'MLM1', 'sku' => 'SKU1', 'title' => 'Llanta', 'quantity' => 1, 'unit_price' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $claim = $this->claim($account, [
            'meli_order_id' => $orderId, 'order_id' => 'ORDER-DETAIL', 'status' => 'opened', 'stage' => 'dispute',
            'raw_claim' => ['players' => [['role' => 'respondent', 'type' => 'seller', 'available_actions' => [['action' => 'send_message_to_mediator', 'due_date' => '2026-09-01T12:00:00Z']]]]],
            'status_history' => [['status' => 'opened', 'date' => '2026-08-01T10:00:00Z']],
            'actions_history' => [['action_name' => 'open_dispute', 'date_created' => '2026-08-02T10:00:00Z']],
            'messages' => [['sender_role' => 'complainant', 'receiver_role' => 'respondent', 'message' => 'Producto dañado', 'date_created' => '2026-08-03T10:00:00Z']],
        ]);
        $this->get(route('meli.claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.products.0.variation_id', '987')->where('claim.order.status', 'paid')
            ->where('claim.deadlines.0.role', 'respondent')->where('claim.messages.0.message', 'Producto dañado')
            ->where('claim.timeline.0.source', 'action')->where('claim.timeline.1.source', 'status'));
    }

    public function test_individual_refresh_uses_claim_account_and_only_get_requests(): void
    {
        $first = $this->account(['access_token' => 'token-one']);
        $this->account(['meli_user_id' => 'OTHER', 'access_token' => 'token-two']);
        $claim = $this->claim($first);
        $this->fakeClaimApi('opened');

        $this->post(route('meli.claims.refresh', $claim))->assertRedirect(route('meli.claims.show', $claim));
        $requests = collect(Http::recorded())->pluck(0);
        $this->assertNotEmpty($requests);
        $this->assertTrue($requests->every(fn (Request $request): bool => $request->method() === 'GET'));
        $this->assertTrue($requests->every(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-one')));
        $this->assertDatabaseHas('meli_claims', ['id' => $claim->id, 'status' => 'opened']);
    }

    public function test_404_and_429_keep_local_claim_and_rate_limit_is_not_retried(): void
    {
        $account = $this->account();
        $claim404 = $this->claim($account, ['status' => 'opened']);
        Http::fake(fn () => Http::response(['message' => 'missing'], 404));
        $this->post(route('meli.claims.refresh', $claim404))->assertRedirect()->assertSessionHas('err');
        $this->assertDatabaseHas('meli_claims', ['id' => $claim404->id, 'status' => 'opened']);
        $this->assertNotNull($claim404->fresh()->sync_error);

        Http::fake(fn () => Http::response(['message' => 'rate limited'], 429));
        $this->post(route('meli.claims.refresh', $claim404))->assertRedirect()->assertSessionHas('err');
        $this->assertCount(1, Http::recorded());
        $this->assertDatabaseHas('meli_claims', ['id' => $claim404->id, 'status' => 'opened']);
    }

    public function test_individual_refresh_uses_claim_account_only_get_and_preserves_local_data_on_404(): void
    {
        $first = $this->account(['access_token' => 'first-token']);
        $second = $this->account(['meli_user_id' => 'SECOND', 'access_token' => 'second-token']);
        $claim = $this->claim($second, ['claim_id' => '456', 'status' => 'opened', 'actions_history' => [['action' => 'open_claim']]]);
        $beforeOrders = DB::table('meli_orders')->count();
        $beforePublications = DB::table('meli_publications')->count();
        $remoteStatus = 'closed';
        $return404 = false;
        $this->fakeClaimApi(
            function () use (&$remoteStatus): string { return $remoteStatus; },
            function () use (&$return404): bool { return $return404; },
        );

        $this->post(route('meli.claims.refresh', $claim))->assertRedirect(route('meli.claims.show', $claim));
        $claim->refresh();
        $this->assertSame('closed', $claim->status);
        $requests = collect(Http::recorded())->pluck(0);
        $this->assertTrue($requests->every(fn (Request $request): bool => $request->method() === 'GET'));
        $this->assertTrue($requests->every(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer second-token')));
        $this->assertFalse($requests->contains(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer first-token')));
        $this->assertSame($beforeOrders, DB::table('meli_orders')->count());
        $this->assertSame($beforePublications, DB::table('meli_publications')->count());

        $return404 = true;
        $this->post(route('meli.claims.refresh', $claim))->assertSessionHas('err');
        $claim->refresh();
        $this->assertSame('closed', $claim->status);
        $this->assertSame(['data' => [['action' => 'claim_opened']]], $claim->actions_history);
        $this->assertNotNull($claim->sync_error);
        $this->assertStringNotContainsString('second-token', (string) $claim->sync_error);
    }

    public function test_refresh_rejects_claim_from_another_user_without_http(): void
    {
        $other = User::factory()->create();
        $foreignAccount = $this->account(['user_id' => $other->id, 'meli_user_id' => 'FOREIGN']);
        $claim = $this->claim($foreignAccount);
        Http::fake();

        $this->post(route('meli.claims.refresh', $claim))->assertNotFound();
        $this->assertCount(0, Http::recorded());
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

    private function fakeClaimApi(string|callable $status, ?callable $shouldFail = null): void
    {
        Http::fake(function (Request $request) use ($status, $shouldFail) {
            if ($shouldFail !== null && $shouldFail()) {
                return Http::response(['message' => 'not found'], 404);
            }

            $resolvedStatus = is_callable($status) ? $status() : $status;
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/search')) return Http::response(['data' => [['id' => 123]], 'paging' => ['total' => 1]]);
            if (str_ends_with($path, '/detail')) return Http::response(['due_date' => now()->addHours(4)->toISOString(), 'action_responsible' => 'respondent', 'title' => 'Revisión requerida', 'description' => 'Detalle', 'problem' => 'Producto diferente']);
            if (str_contains($path, '/reasons/')) return Http::response(['id' => 'PDD', 'name' => 'No corresponde', 'detail' => 'Producto diferente', 'flow' => 'mediations']);
            if (str_ends_with($path, '/affects-reputation')) return Http::response(['affects_reputation' => 'affected', 'has_incentive' => false]);
            if (str_ends_with($path, '/status-history')) return Http::response(['data' => [['status' => $resolvedStatus, 'date' => now()->toISOString()]]]);
            if (str_ends_with($path, '/actions-history')) return Http::response(['data' => [['action' => 'claim_opened']]]);
            if (str_ends_with($path, '/expected-resolutions')) return Http::response(['data' => [['type' => 'return']]]);
            if (str_ends_with($path, '/changes')) return Http::response([]);
            if (str_ends_with($path, '/messages')) return Http::response([['sender_role' => 'complainant', 'receiver_role' => 'respondent', 'message' => 'Mensaje visible', 'user_id' => 123, 'attachments' => []]]);
            return Http::response(['id' => 123, 'resource' => 'order', 'resource_id' => 'ORDER-1', 'status' => $resolvedStatus, 'stage' => 'claim', 'type' => 'mediations', 'reason_id' => 'PDD', 'players' => [['role' => 'respondent', 'type' => 'seller', 'available_actions' => [['action' => 'allow_return', 'due_date' => now()->addHours(4)->toISOString()]]]], 'date_created' => now()->subDay()->toISOString(), 'last_updated' => now()->toISOString()]);
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
