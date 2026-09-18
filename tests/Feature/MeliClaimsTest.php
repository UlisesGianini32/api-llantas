<?php

namespace Tests\Feature;

use App\Jobs\SyncMeliClaimJob;
use App\Models\MeliAccount;
use App\Models\MeliClaim;
use App\Models\MeliClaimActionLog;
use App\Models\User;
use App\Services\MercadoLibre\Claims\MeliClaimsService;
use App\Services\TelegramAlertService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
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
    private object $actionMigration;
    private object $actionSourceMigration;
    private object $attachmentMigration;
    private object $telegramMigration;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Sleep::fake();
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->string('role', 32)->default('operations');
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
        $this->actionMigration = require database_path('migrations/2026_09_01_000001_create_meli_claim_action_logs_table.php');
        $this->actionMigration->up();
        $this->actionSourceMigration = require database_path('migrations/2026_09_18_000001_add_source_to_meli_claim_action_logs_table.php');
        $this->actionSourceMigration->up();
        $this->attachmentMigration = require database_path('migrations/2026_09_02_000001_create_meli_claim_attachment_uploads_table.php');
        $this->attachmentMigration->up();
        $this->telegramMigration = require database_path('migrations/2026_09_15_000001_add_telegram_notified_at_to_meli_claims.php');
        $this->telegramMigration->up();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->telegramMigration->down();
        $this->attachmentMigration->down();
        $this->actionSourceMigration->down();
        $this->actionMigration->down();
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

    public function test_general_sync_searches_only_opened_claims_without_date_range(): void
    {
        $account = $this->account();
        $this->fakeClaimApi('open');

        app(MeliClaimsService::class)->syncAccount($account);

        $searchRequests = collect(Http::recorded())
            ->pluck(0)
            ->filter(function (Request $request): bool {
                return str_ends_with(
                    (string) parse_url($request->url(), PHP_URL_PATH),
                    '/post-purchase/v1/claims/search'
                );
            })
            ->values();

        $this->assertCount(1, $searchRequests);

        $statuses = $searchRequests
            ->map(fn (Request $request): ?string => $request->data()['status'] ?? null)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['opened'], $statuses);

        $this->assertTrue($searchRequests->every(
            fn (Request $request): bool =>
                ($request->data()['players.user_id'] ?? null) === (string) $account->meli_user_id
                && ($request->data()['players.role'] ?? null) === 'respondent'
                && filled($request->data()['status'] ?? null)
                && ! isset($request->data()['range'])
                && ($request->data()['offset'] ?? null) === 0
                && ($request->data()['limit'] ?? null) === 50
        ));
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

    public function test_message_recipient_comes_only_from_available_actions(): void
    {
        $account = $this->account();
        $mediator = $this->claim($account, ['claim_id' => 'MED', 'stage' => 'claim', 'available_actions' => [['action' => 'send_message_to_mediator']]]);
        $buyer = $this->claim($account, ['claim_id' => 'BUY', 'stage' => 'dispute', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $seller = $this->claim($account, ['claim_id' => 'SELLER', 'available_actions' => [['action' => 'send_message_to_respondent']]]);
        $none = $this->claim($account, ['claim_id' => 'NONE', 'available_actions' => [['action' => 'allow_return']]]);

        $this->get(route('meli.claims.show', $mediator))->assertInertia(fn (Assert $page) => $page->where('claim.message_recipient', 'mediator'));
        $this->get(route('meli.claims.show', $buyer))->assertInertia(fn (Assert $page) => $page->where('claim.message_recipient', 'complainant'));
        $this->get(route('meli.claims.show', $seller))->assertInertia(fn (Assert $page) => $page->where('claim.message_recipient', 'respondent'));
        $this->get(route('meli.claims.show', $none))->assertInertia(fn (Assert $page) => $page->where('claim.message_recipient', null));
    }

    public function test_message_route_requires_auth_and_rejects_foreign_empty_closed_or_disallowed_claims(): void
    {
        $account = $this->account();
        $allowed = $this->claim($account, ['claim_id' => 'ALLOWED', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        auth()->logout();
        $this->post(route('meli.claims.messages.store', $allowed), ['message' => 'Hola'])->assertRedirect('/login');
        $this->actingAs($this->user)->post(route('meli.claims.messages.store', $allowed), ['message' => '   '])->assertSessionHasErrors('message');

        $closed = $this->claim($account, ['claim_id' => 'CLOSED', 'status' => 'closed', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $none = $this->claim($account, ['claim_id' => 'NOACTION', 'status' => 'opened', 'available_actions' => []]);
        $this->post(route('meli.claims.messages.store', $closed), ['message' => 'Hola'])->assertSessionHasErrors('message');
        $this->post(route('meli.claims.messages.store', $none), ['message' => 'Hola'])->assertSessionHasErrors('message');

        $otherUser = User::factory()->create();
        $foreignAccount = $this->account(['user_id' => $otherUser->id, 'meli_user_id' => 'FOREIGN-MSG']);
        $foreign = $this->claim($foreignAccount, ['claim_id' => 'FOREIGN-MSG', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        Http::fake();
        $this->post(route('meli.claims.messages.store', $foreign), ['message' => 'Hola'])->assertNotFound();
        $this->assertCount(0, Http::recorded());
    }

    public function test_message_uses_correct_account_trims_payload_audits_and_refreshes_with_get(): void
    {
        $first = $this->account(['access_token' => 'first-token']);
        $second = $this->account(['meli_user_id' => 'SECOND-MSG', 'access_token' => 'second-token']);
        $claim = $this->claim($second, ['claim_id' => '456', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_mediator']]]);
        $orders = DB::table('meli_orders')->count();
        $publications = DB::table('meli_publications')->count();
        $this->fakeMessageApi();

        $this->post(route('meli.claims.messages.store', $claim), [
            'message' => '  Mensaje confirmado  ', 'receiver_role' => 'respondent',
            'meli_account_id' => $first->id, 'claim_id' => 'OTHER', 'access_token' => 'evil',
        ])->assertRedirect(route('meli.claims.show', $claim))->assertSessionHas('ok');

        $requests = collect(Http::recorded())->pluck(0);
        $posts = $requests->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $post = $posts->sole();
        $this->assertStringEndsWith('/post-purchase/v1/claims/456/actions/send-message', parse_url($post->url(), PHP_URL_PATH));
        $this->assertFalse(str_ends_with(parse_url($post->url(), PHP_URL_PATH), '/claims/456/messages'));
        $this->assertSame(['receiver_role' => 'mediator', 'message' => 'Mensaje confirmado'], $post->data());
        $this->assertTrue($post->hasHeader('Authorization', 'Bearer second-token'));
        $this->assertFalse($post->hasHeader('Authorization', 'Bearer first-token'));
        $this->assertTrue($requests->skipUntil(fn (Request $request): bool => $request->method() === 'POST')->skip(1)->every(fn (Request $request): bool => $request->method() === 'GET'));
        $this->assertSame('Mensaje confirmado', $claim->fresh()->messages[0]['message']);
        $this->assertSame($orders, DB::table('meli_orders')->count());
        $this->assertSame($publications, DB::table('meli_publications')->count());
        $audit = MeliClaimActionLog::query()->sole();
        $this->assertTrue($audit->success);
        $this->assertSame('mediator', $audit->receiver_role);
        $this->assertSame('Mensaje confirmado', $audit->request_payload_sanitized['message']);
        $this->assertStringNotContainsString('second-token', json_encode($audit->toArray()));
    }

    public function test_message_has_one_post_and_no_retry_for_401_429_or_500(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['claim_id' => 'ERRORS', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $remoteStatus = 401;
        Http::fake(function () use (&$remoteStatus) { return Http::response(['message' => 'authorization=APP_USR-secret'], $remoteStatus); });

        foreach ([401, 429, 500] as $status) {
            $remoteStatus = $status;
            $before = count(Http::recorded());

            $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Falla '.$status])->assertSessionHas('err');
            $this->assertCount($before + 1, Http::recorded());
            $this->assertSame('POST', Http::recorded()[$before][0]->method());
            $this->assertDatabaseHas('meli_claim_action_logs', ['meli_claim_id' => $claim->id, 'remote_status' => $status, 'success' => false]);
        }
        $this->assertStringNotContainsString('APP_USR-secret', MeliClaimActionLog::query()->get()->toJson());
    }

    public function test_connection_uncertainty_is_not_retried_or_added_to_messages(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['claim_id' => 'TIMEOUT', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']], 'messages' => [['message' => 'Anterior']]]);
        $attempts = 0;
        $attemptedMethods = [];
        Http::fake(function (Request $request) use (&$attempts, &$attemptedMethods) {
            $attempts++;
            $attemptedMethods[] = $request->method();
            throw new ConnectionException('timeout');
        });

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Mensaje incierto'])->assertSessionHas('err');

        $this->assertSame(1, $attempts);
        $this->assertSame(['POST'], $attemptedMethods);
        $this->assertSame([['message' => 'Anterior']], $claim->fresh()->messages);
        $this->assertDatabaseHas('meli_claim_action_logs', ['meli_claim_id' => $claim->id, 'error_code' => 'uncertain_delivery', 'success' => false]);
    }

    public function test_immediate_duplicate_message_is_blocked_after_one_remote_post(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['claim_id' => 'DUP', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeMessageApi();

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Una sola vez'])->assertSessionHas('ok');
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Una sola vez'])->assertSessionHasErrors('message');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertSame(1, MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->count());
    }

    public function test_same_message_can_be_sent_after_cooldown_expires(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['claim_id' => 'LATER', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeMessageApi();

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Repetible después'])->assertSessionHas('ok');
        $this->travel(16)->seconds();
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Repetible después'])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(2, $posts);
    }

    public function test_different_messages_and_claims_do_not_share_cooldown(): void
    {
        $account = $this->account();
        $first = $this->claim($account, ['claim_id' => 'CLAIM-A', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $second = $this->claim($account, ['claim_id' => 'CLAIM-B', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeMessageApi();

        $this->post(route('meli.claims.messages.store', $first), ['message' => 'Texto A'])->assertSessionHas('ok');
        $this->post(route('meli.claims.messages.store', $first), ['message' => 'Texto B'])->assertSessionHas('ok');
        $this->post(route('meli.claims.messages.store', $second), ['message' => 'Texto A'])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(3, $posts);
    }

    public function test_users_do_not_share_message_cooldown(): void
    {
        $firstAccount = $this->account();
        $firstClaim = $this->claim($firstAccount, ['claim_id' => 'USER-A', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $otherUser = User::factory()->create();
        $secondAccount = $this->account(['user_id' => $otherUser->id, 'meli_user_id' => 'OTHER-COOLDOWN']);
        $secondClaim = $this->claim($secondAccount, ['claim_id' => 'USER-B', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeMessageApi();

        $this->post(route('meli.claims.messages.store', $firstClaim), ['message' => 'Mismo texto'])->assertSessionHas('ok');
        $this->actingAs($otherUser)->post(route('meli.claims.messages.store', $secondClaim), ['message' => 'Mismo texto'])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(2, $posts);
    }

    public function test_connection_uncertainty_keeps_immediate_cooldown(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['claim_id' => 'TIMEOUT-DEDUPE', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('timeout');
        });

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Entrega incierta'])->assertSessionHas('err');
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Entrega incierta'])->assertSessionHasErrors('message');

        $this->assertSame(1, $attempts);
    }

    public function test_attachment_validation_rejects_size_type_and_more_than_five_before_http(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'VALIDATE-FILES', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        Http::fake();
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Grande', 'attachments' => [UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')]])->assertSessionHasErrors('attachments.0');
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Texto', 'attachments' => [UploadedFile::fake()->create('notes.txt', 1, 'text/plain')]])->assertSessionHasErrors('attachments.0');
        $files = collect(range(1, 6))->map(fn (int $index) => UploadedFile::fake()->create("file{$index}.pdf", 1, 'application/pdf'))->all();
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Muchos', 'attachments' => $files])->assertSessionHasErrors('attachments');
        $this->assertCount(0, Http::recorded());
    }

    public function test_valid_attachment_is_uploaded_once_then_sent_with_remote_filename(): void
    {
        $account = $this->account(['access_token' => 'attachment-token']);
        $claim = $this->claim($account, ['claim_id' => 'FILES', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeAttachmentMessageApi();
        $file = UploadedFile::fake()->createWithContent('evidencia á (1).pdf', "%PDF-1.4\narchivo");

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Con evidencia', 'attachments' => [$file]])->assertSessionHas('ok');

        $requests = collect(Http::recorded())->pluck(0);
        $uploads = $requests->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/attachments'));
        $messages = $requests->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/actions/send-message'));
        $this->assertCount(1, $uploads); $this->assertCount(1, $messages);
        $this->assertTrue($uploads->sole()->hasHeader('Authorization', 'Bearer attachment-token'));
        $this->assertStringContainsString('multipart/form-data', $uploads->sole()->header('Content-Type')[0]);
        $this->assertSame(['receiver_role' => 'complainant', 'message' => 'Con evidencia', 'attachments' => ['REMOTE-safe.pdf']], $messages->sole()->data());
        $this->assertDatabaseHas('meli_claim_attachment_uploads', ['meli_claim_id' => $claim->id, 'remote_filename' => 'REMOTE-safe.pdf', 'success' => true]);
        $upload = DB::table('meli_claim_attachment_uploads')->first();
        $this->assertLessThanOrEqual(125, strlen($upload->safe_filename));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9._-]+\z/', $upload->safe_filename);
    }

    public function test_partial_upload_failure_does_not_send_message_and_is_audited(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'PARTIAL', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $uploadNumber = 0;
        Http::fake(function (Request $request) use (&$uploadNumber) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/attachments')) {
                $uploadNumber++;
                return $uploadNumber === 1 ? Http::response(['filename' => 'FIRST.pdf'], 201) : Http::response(['message' => 'failed'], 500);
            }
            return Http::response([], 200);
        });
        $files = [UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\none"), UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\ntwo")];

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Dos archivos', 'attachments' => $files])->assertSessionHas('err');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(2, $posts);
        $this->assertFalse($posts->contains(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/actions/send-message')));
        $this->assertDatabaseHas('meli_claim_attachment_uploads', ['remote_filename' => 'FIRST.pdf', 'success' => true]);
        $this->assertDatabaseHas('meli_claim_attachment_uploads', ['remote_status' => 500, 'success' => false]);
    }

    public function test_attachment_upload_401_429_and_500_are_never_retried(): void
    {
        $account = $this->account();
        $remoteStatus = 401;
        Http::fake(function () use (&$remoteStatus) { return Http::response(['message' => 'upload rejected'], $remoteStatus); });
        foreach ([401, 429, 500] as $status) {
            $remoteStatus = $status;
            $claim = $this->claim($account, ['claim_id' => 'UPLOAD-'.$status, 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
            $before = count(Http::recorded());
            $file = UploadedFile::fake()->createWithContent("evidence-{$status}.pdf", "%PDF-1.4\n{$status}");
            $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Upload '.$status, 'attachments' => [$file]])->assertSessionHas('err');
            $this->assertCount($before + 1, Http::recorded());
            $request = Http::recorded()[$before][0];
            $this->assertSame('POST', $request->method());
            $this->assertStringEndsWith('/attachments', parse_url($request->url(), PHP_URL_PATH));
        }
    }

    public function test_uncertain_attachment_upload_is_attempted_once_and_keeps_cooldown(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'UPLOAD-TIMEOUT', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) { $attempts++; throw new ConnectionException('timeout'); });
        $file = UploadedFile::fake()->createWithContent('timeout.pdf', "%PDF-1.4\ntimeout");

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Incierto', 'attachments' => [$file]])->assertSessionHas('err');
        $secondFile = UploadedFile::fake()->createWithContent('timeout.pdf', "%PDF-1.4\ntimeout");
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Incierto', 'attachments' => [$secondFile]])->assertSessionHasErrors('message');

        $this->assertSame(1, $attempts);
        $this->assertDatabaseHas('meli_claim_attachment_uploads', ['meli_claim_id' => $claim->id, 'error_code' => 'uncertain_upload', 'success' => false]);
    }

    public function test_successful_response_without_remote_filename_is_invalid_and_never_sends_message(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'NO-FILENAME', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        Http::fake(fn () => Http::response(['user_id' => 123], 201));
        $file = UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nmissing name");

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Sin filename', 'attachments' => [$file]])->assertSessionHas('err');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertStringEndsWith('/attachments', parse_url($posts->sole()->url(), PHP_URL_PATH));
        $this->assertDatabaseHas('meli_claim_attachment_uploads', [
            'meli_claim_id' => $claim->id, 'remote_status' => 201,
            'success' => false, 'error_code' => 'invalid_remote_response',
        ]);
    }

    public function test_two_files_upload_twice_and_send_one_message_with_both_remote_names(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'TWO-FILES', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $upload = 0;
        Http::fake(function (Request $request) use (&$upload) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && str_ends_with($path, '/attachments')) return Http::response(['filename' => 'REMOTE-'.(++$upload).'.pdf'], 201);
            if ($request->method() === 'POST') return Http::response(['id' => 'MSG-TWO'], 201);
            if (str_ends_with($path, '/messages')) return Http::response([]);
            if (preg_match('#/(detail|affects-reputation|status-history|actions-history|expected-resolutions|changes)$#', $path)) return Http::response([]);
            return Http::response(['id' => 'TWO-FILES', 'status' => 'opened', 'players' => [['role' => 'respondent', 'available_actions' => [['action' => 'send_message_to_complainant']]]]]);
        });
        $files = [UploadedFile::fake()->createWithContent('one.pdf', "%PDF-1.4\none"), UploadedFile::fake()->createWithContent('two.pdf', "%PDF-1.4\ntwo")];

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Ambos', 'attachments' => $files])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame(2, $posts->filter(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/attachments'))->count());
        $messagePost = $posts->filter(fn (Request $request): bool => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/actions/send-message'));
        $this->assertCount(1, $messagePost);
        $this->assertSame(['REMOTE-1.pdf', 'REMOTE-2.pdf'], $messagePost->sole()->data()['attachments']);
    }

    public function test_same_message_with_different_file_hash_does_not_share_cooldown(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'DIFFERENT-HASH', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeAttachmentMessageApi();

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Mismo texto', 'attachments' => [UploadedFile::fake()->createWithContent('same.pdf', "%PDF-1.4\nA")]])->assertSessionHas('ok');
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Mismo texto', 'attachments' => [UploadedFile::fake()->createWithContent('same.pdf', "%PDF-1.4\nB")]])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(4, $posts);
    }

    public function test_same_message_and_file_can_be_processed_after_attachment_cooldown(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'FILE-LATER', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeAttachmentMessageApi();
        $content = "%PDF-1.4\nrepeat";

        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Después', 'attachments' => [UploadedFile::fake()->createWithContent('repeat.pdf', $content)]])->assertSessionHas('ok');
        $this->travel(16)->seconds();
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Después', 'attachments' => [UploadedFile::fake()->createWithContent('repeat.pdf', $content)]])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(4, $posts);
    }

    public function test_reversing_same_files_keeps_same_intention_hash_and_is_blocked(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'ORDER-INDEPENDENT', 'status' => 'opened', 'available_actions' => [['action' => 'send_message_to_complainant']]]);
        $this->fakeAttachmentMessageApi();
        $one = "%PDF-1.4\none"; $two = "%PDF-1.4\ntwo";
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Orden', 'attachments' => [UploadedFile::fake()->createWithContent('one.pdf', $one), UploadedFile::fake()->createWithContent('two.pdf', $two)]])->assertSessionHas('ok');
        $this->post(route('meli.claims.messages.store', $claim), ['message' => 'Orden', 'attachments' => [UploadedFile::fake()->createWithContent('two.pdf', $two), UploadedFile::fake()->createWithContent('one.pdf', $one)]])->assertSessionHasErrors('message');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(3, $posts);
    }

    public function test_attachment_download_requires_snapshot_and_uses_safe_get_proxy(): void
    {
        $account = $this->account(['access_token' => 'download-token']);
        $claim = $this->claim($account, ['claim_id' => 'DOWNLOAD', 'messages' => [['attachments' => [['filename' => 'remote-file.pdf', 'original_filename' => "factura\r\nmaliciosa.pdf"]]]]]);
        Http::fake(fn () => Http::response('PDF', 200, ['Content-Type' => 'application/pdf']));

        auth()->logout();
        $this->get(route('meli.claims.attachments.download', [$claim, 'remote-file.pdf']))->assertRedirect('/login');
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)->get(route('meli.claims.attachments.download', [$claim, 'remote-file.pdf']))->assertNotFound();
        $this->actingAs($this->user);
        $this->get(route('meli.claims.attachments.download', [$claim, 'missing.pdf']))->assertNotFound();
        $response = $this->get(route('meli.claims.attachments.download', [$claim, 'remote-file.pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringNotContainsString("\r", $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString("\n", $response->headers->get('Content-Disposition'));
        $requests = collect(Http::recorded())->pluck(0);
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests->sole()->method());
        $this->assertTrue($requests->sole()->hasHeader('Authorization', 'Bearer download-token'));
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

    public function test_economic_resolution_routes_require_auth_and_foreign_claim_makes_no_http_request(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'FOREIGN-RESOLUTION']);
        auth()->logout();
        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertRedirect('/login');
        $this->get(route('meli.claims.resolutions.partial-refund.offers', $claim))->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->post(route('meli.claims.resolutions.refund', $claim), [])->assertNotFound();
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 'invalid'])->assertNotFound();
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 40, 'amount' => 1])->assertNotFound();
        $this->get(route('meli.claims.resolutions.partial-refund.offers', $claim))->assertNotFound();
        $this->assertCount(0, Http::recorded());
    }

    public function test_refund_uses_fresh_seller_action_exact_endpoint_empty_body_and_one_post(): void
    {
        $account = $this->account(['access_token' => 'economic-token']);
        $claim = $this->claim($account, ['claim_id' => 'REFUND', 'stage' => 'claim', 'reason_id' => 'PDD', 'raw_claim' => ['players' => [['role' => 'respondent', 'available_actions' => [['action' => 'refund']]]]]]);
        $this->fakeEconomicApi('REFUND', ['refund']);

        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('ok');

        $requests = collect(Http::recorded())->pluck(0);
        $posts = $requests->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertStringEndsWith('/post-purchase/v1/claims/REFUND/expected-resolutions/refund', parse_url($posts->sole()->url(), PHP_URL_PATH));
        $this->assertFalse($posts->contains(fn (Request $request): bool => str_contains($request->url(), '/messages') || str_contains($request->url(), '/attachments') || str_contains($request->url(), '/returns')));
        $this->assertSame([], $posts->sole()->data());
        $this->assertTrue($posts->sole()->hasHeader('Authorization', 'Bearer economic-token'));
        $this->assertSame('GET', $requests->first()->method());
        $this->assertDatabaseHas('meli_claim_action_logs', ['meli_claim_id' => $claim->id, 'source' => 'web', 'action' => 'refund', 'success' => true, 'remote_status' => 201]);
        $this->assertStringNotContainsString('economic-token', MeliClaimActionLog::query()->latest('id')->first()->toJson());
    }

    public function test_stale_local_action_stage_and_reason_never_authorize_when_fresh_seller_action_is_missing(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'STALE', 'stage' => 'claim', 'reason_id' => 'PDD', 'raw_claim' => ['players' => [['role' => 'respondent', 'available_actions' => [['action' => 'refund']]]]]]);
        $this->fakeEconomicApi('STALE', []);

        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])
            ->assertSessionHas('err', 'Mercado Libre ya no permite realizar esta acción. El reclamo fue actualizado.');

        $this->assertCount(0, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
        $this->assertSame([], $claim->fresh()->available_actions);
    }

    public function test_preflight_error_makes_zero_economic_posts(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'PREFLIGHT-ERROR']);
        Http::fake(fn () => throw new ConnectionException('preflight timeout'));

        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('err');

        $this->assertCount(0, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
        $this->assertDatabaseMissing('meli_claim_action_logs', ['meli_claim_id' => $claim->id]);
    }

    public function test_failed_refresh_after_success_keeps_economic_audit_successful(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'REFRESH-FAILS']);
        $posted = false;
        Http::fake(function (Request $request) use (&$posted) {
            if ($request->method() === 'POST') {
                $posted = true;
                return Http::response(['id' => 'REMOTE-OK'], 201);
            }
            if ($posted) throw new ConnectionException('refresh timeout');
            return Http::response(['id' => 'REFRESH-FAILS', 'status' => 'opened', 'players' => [['role' => 'respondent', 'available_actions' => [['action' => 'refund']]]]]);
        });

        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])
            ->assertSessionHas('err', 'La resolución fue enviada correctamente, pero no fue posible actualizar el reclamo. Actualízalo manualmente.');

        $this->assertDatabaseHas('meli_claim_action_logs', ['meli_claim_id' => $claim->id, 'action' => 'refund', 'success' => true, 'remote_status' => 201]);
        $this->assertCount(1, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_allow_return_never_retries_errors_and_timeout_is_uncertain(): void
    {
        $timeoutPostAttempts = 0;
        Http::fake(function (Request $request) use (&$timeoutPostAttempts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                if (str_contains($path, 'RETURN-TIMEOUT')) {
                    $timeoutPostAttempts++;
                    throw new ConnectionException('timeout');
                }
                foreach ([401, 429, 500] as $status) if (str_contains($path, 'RETURN-'.$status)) return Http::response([], $status);
            }
            return Http::response(['id' => 'RETURN', 'status' => 'opened', 'players' => [['role' => 'respondent', 'available_actions' => [['action' => 'allow_return']]]]]);
        });
        foreach ([401, 429, 500] as $status) {
            $claim = $this->claim($this->account(), ['claim_id' => 'RETURN-'.$status]);
            $this->post(route('meli.claims.resolutions.allow-return', $claim), ['confirmed' => true])->assertSessionHas('err');
            $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'RETURN-'.$status));
            $this->assertCount(1, $posts);
            $this->assertStringEndsWith('/expected-resolutions/allow-return', parse_url($posts->sole()->url(), PHP_URL_PATH));
        }

        $claim = $this->claim($this->account(), ['claim_id' => 'RETURN-TIMEOUT']);
        $this->post(route('meli.claims.resolutions.allow-return', $claim), ['confirmed' => true])->assertSessionHas('err');
        $this->assertDatabaseHas('meli_claim_action_logs', ['meli_claim_id' => $claim->id, 'action' => 'allow_return', 'success' => null, 'error_code' => 'uncertain_delivery']);
        $this->assertSame(1, $timeoutPostAttempts);
    }

    public function test_refund_never_retries_errors_and_timeout_is_uncertain(): void
    {
        $timeoutPostAttempts = 0;
        Http::fake(function (Request $request) use (&$timeoutPostAttempts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                if (str_contains($path, 'REFUND-TIMEOUT')) {
                    $timeoutPostAttempts++;
                    throw new ConnectionException('timeout');
                }
                foreach ([401, 429, 500] as $status) if (str_contains($path, 'REFUND-'.$status)) return Http::response([], $status);
            }
            return Http::response(['id' => 'REFUND', 'status' => 'opened', 'players' => [['role' => 'respondent', 'available_actions' => [['action' => 'refund']]]]]);
        });

        foreach ([401, 429, 500] as $status) {
            $claim = $this->claim($this->account(), ['claim_id' => 'REFUND-'.$status]);
            $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('err');
            $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), 'REFUND-'.$status));
            $this->assertCount(1, $posts);
            $this->assertStringEndsWith('/expected-resolutions/refund', parse_url($posts->sole()->url(), PHP_URL_PATH));
        }

        $claim = $this->claim($this->account(), ['claim_id' => 'REFUND-TIMEOUT']);
        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('err');
        $this->assertSame(1, $timeoutPostAttempts);
        $this->assertDatabaseHas('meli_claim_action_logs', [
            'meli_claim_id' => $claim->id,
            'action' => 'refund',
            'success' => null,
            'error_code' => 'uncertain_delivery',
        ]);
    }

    public function test_partial_refund_uses_remote_offer_and_posts_only_percentage(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'PARTIAL-ECONOMIC']);
        $this->fakeEconomicApi('PARTIAL-ECONOMIC', ['allow_partial_refund'], 201, [['amount' => 259.60, 'percentage' => 40]], 'MXN');

        $this->getJson(route('meli.claims.resolutions.partial-refund.offers', $claim))
            ->assertOk()->assertJsonPath('offers.0.percentage', 40)->assertJsonPath('offers.0.amount', 259.6)->assertJsonPath('offers.0.currency_id', 'MXN');
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 40])->assertSessionHas('ok');

        $requests = collect(Http::recorded())->pluck(0);
        $this->assertTrue($requests->contains(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/partial-refund/available-offers')));
        $posts = $requests->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertStringEndsWith('/expected-resolutions/partial-refund', parse_url($posts->sole()->url(), PHP_URL_PATH));
        $this->assertSame(['percentage' => 40.0], $posts->sole()->data());
        $audit = MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->sole();
        $this->assertSame(['percentage' => 40, 'amount' => 259.6, 'currency_id' => 'MXN'], $audit->request_payload_sanitized);
    }

    public function test_partial_offers_use_global_currency_and_filter_one_hundred_percent(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'PARTIAL-FILTER']);
        $this->fakeEconomicApi('PARTIAL-FILTER', ['allow_partial_refund'], 201, [
            ['amount' => 259.60, 'percentage' => 40],
            ['amount' => 649.00, 'percentage' => 100],
        ], 'MXN');

        $this->getJson(route('meli.claims.resolutions.partial-refund.offers', $claim))
            ->assertOk()->assertJsonCount(1, 'offers')
            ->assertJsonPath('offers.0.percentage', 40)
            ->assertJsonPath('offers.0.amount', 259.6)
            ->assertJsonPath('offers.0.currency_id', 'MXN');
    }

    public function test_preflight_persists_sanitized_raw_claim_without_participant_or_address_data(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'SANITIZED-PREFLIGHT']);
        Http::fake(fn () => Http::response([
            'id' => 'SANITIZED-PREFLIGHT',
            'buyer' => ['id' => 1],
            'complainant' => ['email' => 'buyer@example.test'],
            'shipping_address' => ['street_name' => 'Privada'],
            'receiver_address' => ['zip_code' => '00000'],
            'players' => [[
                'role' => 'respondent', 'type' => 'seller', 'user_id' => 10, 'id' => 11,
                'email' => 'seller@example.test', 'nickname' => 'private', 'available_actions' => [],
            ]],
        ]));

        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('err');

        $raw = $claim->fresh()->raw_claim;
        foreach (['buyer', 'complainant', 'shipping_address', 'receiver_address'] as $key) $this->assertArrayNotHasKey($key, $raw);
        foreach (['user_id', 'id', 'email', 'nickname'] as $key) $this->assertArrayNotHasKey($key, $raw['players'][0]);
        $this->assertCount(0, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_partial_refund_rejects_manual_amount_invalid_and_full_percentage_without_post(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'PARTIAL-INVALID']);
        $this->fakeEconomicApi('PARTIAL-INVALID', ['allow_partial_refund'], 201, [['percentage' => 40, 'amount' => 100, 'currency_id' => 'MXN']]);
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 40, 'amount' => 1])->assertSessionHasErrors('amount');
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 30])->assertSessionHasErrors('percentage');
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 100])->assertSessionHasErrors('percentage');
        $this->assertCount(0, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_economic_double_submit_has_one_post(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'DEDUPE-ECONOMIC']);
        $this->fakeEconomicApi('DEDUPE-ECONOMIC', ['refund']);
        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('ok');
        $this->post(route('meli.claims.resolutions.refund', $claim), ['confirmed' => true])->assertSessionHas('err');
        $this->assertCount(1, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_different_partial_percentage_has_a_distinct_intention(): void
    {
        $claim = $this->claim($this->account(), ['claim_id' => 'PARTIAL-INTENT']);
        $offers = [
            ['percentage' => 20, 'amount' => 20, 'currency_id' => 'MXN'],
            ['percentage' => 40, 'amount' => 40, 'currency_id' => 'MXN'],
        ];
        $this->fakeEconomicApi('PARTIAL-INTENT', ['allow_partial_refund'], 201, $offers);

        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 20])->assertSessionHas('ok');
        $this->post(route('meli.claims.resolutions.partial-refund', $claim), ['confirmed' => true, 'percentage' => 40])->assertSessionHas('ok');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(2, $posts);
        $this->assertSame([["percentage" => 20.0], ["percentage" => 40.0]], $posts->map(fn (Request $request): array => $request->data())->values()->all());
        $this->assertSame(2, MeliClaimActionLog::query()->where('meli_claim_id', $claim->id)->distinct()->count('message_hash'));
    }

    private function fakeEconomicApi(string $claimId, array $actions, int|string $postResult = 201, array $offers = [], ?string $currencyId = null): void
    {
        Http::fake(function (Request $request) use ($claimId, $actions, $postResult, $offers, $currencyId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                if ($postResult === 'timeout') throw new ConnectionException('timeout');
                return Http::response(['id' => 'RESOLUTION-1'], $postResult);
            }
            if (str_ends_with($path, '/partial-refund/available-offers')) return Http::response(array_filter([
                'currency_id' => $currencyId,
                'available_offers' => $offers,
            ], fn (mixed $value): bool => $value !== null));
            if (preg_match('#/(detail|affects-reputation|status-history|actions-history|expected-resolutions|messages|changes)$#', $path)) return Http::response([]);
            return Http::response(['id' => $claimId, 'status' => 'opened', 'stage' => 'claim', 'players' => [['role' => 'respondent', 'type' => 'seller', 'available_actions' => array_map(fn (string $action): array => ['action' => $action], $actions)]]]);
        });
    }

    private function fakeClaimApi(string|callable $status, ?callable $shouldFail = null): void
    {
        Http::fake(function (Request $request) use ($status, $shouldFail) {
            if (str_contains($request->url(), 'api.telegram.org')) return null;
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

    private function fakeMessageApi(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'MSG-1'], 201);
            }
            if (str_ends_with($path, '/messages')) {
                return Http::response([['sender_role' => 'respondent', 'receiver_role' => 'mediator', 'message' => 'Mensaje confirmado']]);
            }
            if (str_ends_with($path, '/detail')) return Http::response([]);
            if (str_ends_with($path, '/affects-reputation')) return Http::response([]);
            if (str_ends_with($path, '/status-history')) return Http::response([]);
            if (str_ends_with($path, '/actions-history')) return Http::response([]);
            if (str_ends_with($path, '/expected-resolutions')) return Http::response([]);
            if (str_ends_with($path, '/changes')) return Http::response([]);

            return Http::response([
                'id' => 456, 'resource' => 'order', 'resource_id' => 'ORDER-1',
                'status' => 'opened', 'stage' => 'dispute', 'type' => 'mediations',
                'players' => [['role' => 'respondent', 'type' => 'seller', 'available_actions' => [['action' => 'send_message_to_mediator']]]],
            ]);
        });
    }

    private function fakeAttachmentMessageApi(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && str_ends_with($path, '/attachments')) return Http::response(['file_name' => 'REMOTE-safe.pdf'], 201);
            if ($request->method() === 'POST') return Http::response(['id' => 'MSG-FILE'], 201);
            if (str_ends_with($path, '/messages')) return Http::response([['message' => 'Con evidencia', 'attachments' => [['filename' => 'REMOTE-safe.pdf']]]]);
            if (str_ends_with($path, '/detail') || str_ends_with($path, '/affects-reputation') || str_ends_with($path, '/status-history') || str_ends_with($path, '/actions-history') || str_ends_with($path, '/expected-resolutions') || str_ends_with($path, '/changes')) return Http::response([]);
            return Http::response(['id' => 'FILES', 'status' => 'opened', 'players' => [['role' => 'respondent', 'available_actions' => [['action' => 'send_message_to_complainant']]]]]);
        });
    }

    public function test_incremental_sync_saves_new_skips_same_timestamp_and_refreshes_changes(): void
    {
        $account = $this->account();
        $version = '2026-09-15T10:00:00.123Z';
        Http::fake(function (Request $request) use (&$version) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $raw = ['id' => 123, 'status' => 'opened', 'last_updated' => $version];
            if (str_ends_with($path, '/search')) return Http::response(['data' => [$raw], 'paging' => ['total' => 1]]);
            return Http::response(str_ends_with($path, '/123') ? $raw : []);
        });
        $this->mock(TelegramAlertService::class)->shouldReceive('notifyMeliNewClaim')->once()
            ->andReturnUsing(fn (MeliClaim $claim) => $claim->forceFill(['telegram_notified_at' => now()])->save());
        $service = app(MeliClaimsService::class);
        $first = $service->syncAccount($account, 'opened', 0);
        $this->assertSame(1, $first['saved']);
        $this->assertDatabaseCount('meli_claims', 1);
        $requests = count(Http::recorded());
        $second = $service->syncAccount($account, 'opened', 0);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['saved']);
        $this->assertCount($requests + 1, Http::recorded());
        $version = '2026-09-15T10:00:00.456Z';
        $third = $service->syncAccount($account, 'opened', 0);
        $this->assertSame(1, $third['saved']);
        $this->assertSame($version, MeliClaim::query()->sole()->raw_claim['last_updated']);
        $this->assertCount($requests + 10, Http::recorded());
        $this->assertSame(1, $service->syncAccount($account, 'opened', 0, true)['saved']);
    }

    public function test_reconciliation_queries_only_missing_active_claims_of_this_account(): void
    {
        $account = $this->account();
        $missing = $this->claim($account, ['claim_id' => '456', 'status' => 'opened']);
        $this->claim($account, ['claim_id' => '789', 'status' => 'closed']);
        $this->claim($account, ['claim_id' => '790', 'status' => 'resolved']);
        $this->claim($this->account(['meli_user_id' => 'other']), ['claim_id' => '999', 'status' => 'opened']);
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/search')) return Http::response(['data' => [], 'paging' => ['total' => 0]]);
            return Http::response(str_ends_with($path, '/456') ? ['id' => 456, 'status' => 'closed'] : []);
        });
        $this->mock(TelegramAlertService::class)->shouldNotReceive('notifyMeliNewClaim');
        $result = app(MeliClaimsService::class)->syncAccount($account);
        $this->assertSame(1, $result['reconciled']);
        $this->assertSame('closed', $missing->fresh()->status);
        Http::assertNotSent(fn (Request $request) => preg_match('#/(789|790|999)(/|$)#', $request->url()));
        $this->assertDatabaseCount('meli_claims', 4);
    }

    public function test_incomplete_search_never_reconciles_local_claims(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['status' => 'opened']);
        Http::fake(fn () => Http::response(['data' => [], 'paging' => ['total' => 2]]));
        try {
            app(MeliClaimsService::class)->syncAccount($account);
            $this->fail('Incomplete search must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incompleta', $e->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame('opened', $claim->fresh()->status);
    }

    public function test_bounded_search_does_not_reconcile_and_closed_remains_explicitly_available(): void
    {
        $account = $this->account();
        $this->claim($account, ['status' => 'opened']);
        Http::fake(fn () => Http::response(['data' => [], 'paging' => ['total' => 0]]));
        $service = app(MeliClaimsService::class);
        $this->assertSame(0, $service->syncAccount($account, 'opened', 30)['reconciled']);
        $this->assertSame(0, $service->syncAccount($account, 'closed', 0)['reconciled']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['status'] === 'closed');
    }

    public function test_missing_search_total_fails_without_querying_local_history(): void
    {
        $account = $this->account();
        $this->claim($account, ['status' => 'opened']);
        Http::fake(fn () => Http::response(['data' => []]));
        try {
            app(MeliClaimsService::class)->syncAccount($account);
            $this->fail('Missing total must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('total válido', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_missing_timestamp_refreshes_instead_of_skipping(): void
    {
        $account = $this->account();
        $this->claim($account, ['status' => 'opened', 'last_updated' => now(), 'telegram_notified_at' => now()]);
        $this->fakeClaimApi('opened');
        $this->mock(TelegramAlertService::class)->shouldNotReceive('notifyMeliNewClaim');
        $result = app(MeliClaimsService::class)->syncAccount($account);
        $this->assertSame(1, $result['saved']);
        $this->assertSame(0, $result['skipped']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/detail'));
    }

    public function test_failed_search_cannot_reconcile_or_delete_claims(): void
    {
        $account = $this->account();
        $claim = $this->claim($account, ['status' => 'opened']);
        Http::fake(fn () => Http::response(['message' => 'temporary'], 500));
        try {
            app(MeliClaimsService::class)->syncAccount($account);
            $this->fail('Search must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/claims/123'));
        $this->assertSame('opened', $claim->fresh()->status);
    }

    public function test_pagination_finishes_before_reconciling(): void
    {
        $account = $this->account();
        $version = '2026-09-15T10:00:00Z';
        foreach (['123', '456'] as $id) $this->claim($account, ['claim_id' => $id, 'status' => 'opened', 'last_updated' => $version]);
        Http::fake(fn (Request $request) => Http::response([
            'data' => [['id' => $request['offset'] === 0 ? 123 : 456, 'last_updated' => $version]],
            'paging' => ['total' => 2],
        ]));
        $result = app(MeliClaimsService::class)->syncAccount($account);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, $result['reconciled']);
        Http::assertSentCount(2);
    }

    public function test_unchanged_pending_claim_notifies_without_downloading_details(): void
    {
        $this->withClaimTelegram(function (): void {
            $account = $this->account();
            $version = '2026-09-15T10:00:00Z';
            $claim = $this->claim($account, ['status' => 'opened', 'last_updated' => $version]);
            Http::fake([
                'api.mercadolibre.com/post-purchase/v1/claims/search*' => Http::response([
                    'data' => [['id' => 123, 'last_updated' => $version]], 'paging' => ['total' => 1],
                ]),
                'api.telegram.org/*' => Http::response(['ok' => true]),
            ]);

            $service = app(MeliClaimsService::class);
            $first = $service->syncAccount($account);
            $second = $service->syncAccount($account);

            $this->assertSame(1, $first['skipped']);
            $this->assertSame(0, $first['saved']);
            $this->assertSame(1, $second['skipped']);
            $this->assertNotNull($claim->fresh()->telegram_notified_at);
            Http::assertSentCount(3); // Two searches and one Telegram delivery, no claim resources.
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/claims/123'));
        });
    }

    public function test_notified_open_claim_never_invokes_notifier_on_skip_or_refresh(): void
    {
        $account = $this->account();
        $version = '2026-09-15T10:00:00Z';
        $this->claim($account, ['status' => 'opened', 'last_updated' => $version, 'telegram_notified_at' => now()]);
        Http::fake(function (Request $request) use ($version) {
            $raw = ['id' => 123, 'status' => 'opened', 'last_updated' => $version];
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/search')) return Http::response(['data' => [$raw], 'paging' => ['total' => 1]]);
            return Http::response(str_ends_with($path, '/123') ? $raw : []);
        });
        $this->mock(TelegramAlertService::class)->shouldNotReceive('notifyMeliNewClaim');

        $service = app(MeliClaimsService::class);
        $this->assertSame(1, $service->syncAccount($account)['skipped']);
        Http::assertSentCount(1);
        $service->syncClaim($account, '123');
    }

    public function test_existing_open_claim_recovers_after_telegram_configuration_was_missing(): void
    {
        $this->withClaimTelegram(function (): void {
            $account = $this->account();
            $this->fakeClaimApi('open');
            Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
            $_ENV['TELEGRAM_BOT_TOKEN'] = $_SERVER['TELEGRAM_BOT_TOKEN'] = '';
            putenv('TELEGRAM_BOT_TOKEN=');
            $service = app(MeliClaimsService::class);
            $claim = $service->syncClaim($account, '123');
            $this->assertNull($claim->fresh()->telegram_notified_at);
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'));

            $_ENV['TELEGRAM_BOT_TOKEN'] = $_SERVER['TELEGRAM_BOT_TOKEN'] = 'test-bot';
            putenv('TELEGRAM_BOT_TOKEN=test-bot');
            $service->syncClaim($account, '123');
            $service->syncClaim($account, '123');

            $this->assertNotNull($claim->fresh()->telegram_notified_at);
            $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org')));
        });
    }

    public function test_notifier_failure_before_reservation_keeps_skipped_claim_retryable(): void
    {
        $account = $this->account();
        $version = '2026-09-15T10:00:00Z';
        $claim = $this->claim($account, ['status' => 'opened', 'last_updated' => $version]);
        Http::fake(['api.mercadolibre.com/post-purchase/v1/claims/search*' => Http::response([
            'data' => [['id' => 123, 'last_updated' => $version]], 'paging' => ['total' => 1],
        ])]);
        $this->mock(TelegramAlertService::class)->shouldReceive('notifyMeliNewClaim')->twice()
            ->andThrow(new \RuntimeException('Before reservation'));

        $service = app(MeliClaimsService::class);
        foreach (range(1, 2) as $_) {
            $result = $service->syncAccount($account);
            $this->assertSame(1, $result['skipped']);
            $this->assertSame(0, $result['failed']);
        }
        $this->assertNull($claim->fresh()->telegram_notified_at);
        Http::assertSentCount(2);
    }

    public function test_atomic_reservation_blocks_another_notifier_while_delivery_is_in_flight(): void
    {
        $this->withClaimTelegram(function (): void {
            $claim = $this->claim($this->account(), ['status' => 'opened']);
            $stale = $claim->fresh();
            $attempts = 0;
            Http::fake(function () use ($stale, &$attempts) {
                $attempts++;
                if ($attempts === 1) app(TelegramAlertService::class)->notifyMeliNewClaim($stale);
                return Http::response(['ok' => true]);
            });

            app(TelegramAlertService::class)->notifyMeliNewClaim($claim);
            app(TelegramAlertService::class)->notifyMeliNewClaim($stale);

            $this->assertSame(1, $attempts);
            $this->assertNotNull($claim->fresh()->telegram_notified_at);
            Http::assertSentCount(1);
        });
    }

    public function test_telegram_is_attempted_once_even_with_stale_models_and_later_updates(): void
    {
        $this->withClaimTelegram(function (): void {
            $account = $this->account();
            $this->fakeClaimApi('opened');
            Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
            $service = app(MeliClaimsService::class);
            $claim = $service->syncClaim($account, '123');
            $stale = $claim->fresh();
            $stale->telegram_notified_at = null;
            app(TelegramAlertService::class)->notifyMeliNewClaim($stale);
            $service->syncAccount($account);
            $service->syncClaim($account, '123', true);
            $this->assertNotNull($claim->fresh()->telegram_notified_at);
            $sent = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'));
            $this->assertCount(1, $sent);
            $message = $sent->first()[0]['text'];
            $this->assertStringContainsString('NUEVO RECLAMO MERCADO LIBRE', $message);
            $this->assertStringContainsString(route('meli.claims.show', $claim), $message);
        });
    }

    public function test_telegram_failure_keeps_saved_claim_and_does_not_repeat_attempt(): void
    {
        $this->withClaimTelegram(function (): void {
            $account = $this->account();
            $this->fakeClaimApi('opened');
            Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
            $result = app(MeliClaimsService::class)->syncAccount($account);
            $this->assertSame(1, $result['saved']);
            $this->assertSame(0, $result['failed']);
            app(MeliClaimsService::class)->syncAccount($account);
            $this->assertNotNull(MeliClaim::query()->sole()->telegram_notified_at);
            $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org')));
        });
    }

    public function test_throwing_telegram_service_cannot_fail_claim_sync(): void
    {
        $account = $this->account();
        $this->fakeClaimApi('opened');
        $this->mock(TelegramAlertService::class)->shouldReceive('notifyMeliNewClaim')->once()->andThrow(new \RuntimeException('delivery failed'));
        $result = app(MeliClaimsService::class)->syncAccount($account);
        $this->assertSame(1, $result['saved']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_telegram_timeout_does_not_leak_exception_message_or_retry(): void
    {
        $this->withClaimTelegram(function (): void {
            $claim = $this->claim($this->account(), ['status' => 'opened']);
            \Illuminate\Support\Facades\Log::spy();
            $attempts = 0;
            Http::fake(function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('private-token-in-url');
            });
            app(TelegramAlertService::class)->notifyMeliNewClaim($claim);
            app(TelegramAlertService::class)->notifyMeliNewClaim($claim);
            $this->assertSame(1, $attempts);
            $this->assertNotNull($claim->fresh()->telegram_notified_at);
            \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with(
                'TelegramAlertService: envío de reclamo no confirmado',
                ['claim_id' => $claim->claim_id, 'exception' => ConnectionException::class],
            );
        });
    }

    public function test_telegram_message_is_bounded_and_includes_product_and_direct_url(): void
    {
        $this->withClaimTelegram(function (): void {
            $account = $this->account(['nickname' => 'Tienda']);
            $orderId = DB::table('meli_orders')->insertGetId(['meli_account_id' => $account->id, 'order_id' => 'ORDER-TG']);
            for ($i = 0; $i < 10; $i++) {
                DB::table('meli_order_items')->insert(['meli_order_id' => $orderId, 'title' => str_repeat('🚨', 5000), 'sku' => 'SKU-TG', 'quantity' => 2]);
            }
            $claim = $this->claim($account, ['status' => 'opened', 'meli_order_id' => $orderId, 'order_id' => 'ORDER-TG']);
            Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
            app(TelegramAlertService::class)->notifyMeliNewClaim($claim);
            Http::assertSent(function (Request $request) use ($claim): bool {
                $text = $request['text'];
                return str_contains($text, 'SKU-TG') && str_contains($text, 'Cantidad: 2')
                    && str_contains($text, 'Fecha límite:') && str_contains($text, 'Afecta reputación:')
                    && str_contains($text, 'Cuenta: Tienda') && str_contains($text, route('meli.claims.show', $claim))
                    && strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) <= 8192;
            });
        });
    }

    public function test_baseline_marks_existing_records_and_new_records_default_to_null(): void
    {
        $this->telegramMigration->down();
        $account = $this->account();
        $existing = $this->claim($account, ['status' => 'opened']);
        $this->telegramMigration->up();
        $this->assertNotNull($existing->fresh()->telegram_notified_at);
        $new = $this->claim($account, ['claim_id' => '456', 'status' => 'opened']);
        $this->assertNull($new->fresh()->telegram_notified_at);
        $this->withClaimTelegram(function () use ($existing, $account): void {
            Http::fake();
            app(TelegramAlertService::class)->notifyMeliNewClaim($existing->fresh());
            Http::assertNothingSent();
            $this->fakeClaimApi('opened');
            $this->mock(TelegramAlertService::class)->shouldNotReceive('notifyMeliNewClaim');
            app(MeliClaimsService::class)->syncClaim($account, '123');
        });
    }

    public function test_baseline_excludes_claim_inserted_after_max_id_was_captured(): void
    {
        $this->telegramMigration->down();
        $account = $this->account();
        $historical = $this->claim($account, ['status' => 'opened']);
        $historicalClosed = $this->claim($account, ['claim_id' => '456', 'status' => 'closed']);
        $insertedId = null;
        DB::listen(function ($query) use ($account, &$insertedId): void {
            if ($insertedId === null && str_contains(strtolower($query->sql), 'max(') && str_contains($query->sql, 'meli_claims')) {
                // The SELECT result is already captured; insert before the schema change/baseline UPDATE.
                $insertedId = DB::table('meli_claims')->insertGetId([
                    'meli_account_id' => $account->id, 'claim_id' => '789', 'status' => 'opened',
                ]);
            }
        });

        $this->telegramMigration->up();

        $this->assertNotNull($insertedId);
        $this->assertGreaterThan($historicalClosed->id, $insertedId);
        $this->assertNotNull($historical->fresh()->telegram_notified_at);
        $this->assertNotNull($historicalClosed->fresh()->telegram_notified_at);
        $this->assertNull(MeliClaim::query()->findOrFail($insertedId)->telegram_notified_at);
    }

    public function test_empty_baseline_does_not_mark_first_claim_inserted_during_migration(): void
    {
        $this->telegramMigration->down();
        $account = $this->account();
        $insertedId = null;
        DB::listen(function ($query) use ($account, &$insertedId): void {
            if ($insertedId === null && str_contains(strtolower($query->sql), 'max(') && str_contains($query->sql, 'meli_claims')) {
                $insertedId = DB::table('meli_claims')->insertGetId([
                    'meli_account_id' => $account->id, 'claim_id' => '123', 'status' => 'opened',
                ]);
            }
        });

        $this->telegramMigration->up();

        $this->assertNotNull($insertedId);
        $this->assertNull(MeliClaim::query()->findOrFail($insertedId)->telegram_notified_at);
    }

    public function test_new_closed_claim_does_not_notify(): void
    {
        $this->fakeClaimApi('closed');
        $this->mock(TelegramAlertService::class)->shouldNotReceive('notifyMeliNewClaim');
        app(MeliClaimsService::class)->syncClaim($this->account(), '123');
    }

    public function test_sync_button_and_command_use_incremental_arguments(): void
    {
        $account = $this->account();
        $this->mock(MeliClaimsService::class)->shouldReceive('syncAccount')->twice()
            ->withArgs(fn ($actual, $status, $days, $force) => $actual->id === $account->id && $status === 'opened' && $days === 0 && $force === false)
            ->andReturn(['received' => 16, 'saved' => 1, 'skipped' => 15, 'reconciled' => 0, 'failed' => 0]);
        $this->post(route('meli.claims.sync'), ['account_id' => $account->id])->assertRedirect()
            ->assertSessionHas('ok', fn ($message) => str_contains($message, 'Sin cambios: 15'));
        $this->artisan('meli:sync-claims', ['--account' => $account->id])->assertSuccessful();
    }

    public function test_scheduler_keeps_incremental_five_minute_job_with_protections(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'meli:sync-claims'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('meli:sync-claims --status=opened --days=0', $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->shouldAppendOutput);
        $this->assertSame(storage_path('logs/meli-claims-sync.log'), $event->output);
    }

    public function test_claim_topics_still_dispatch_to_meli_queue(): void
    {
        Bus::fake();
        $account = $this->account(['meli_user_id' => '12345']);
        foreach (['claims', 'claims_actions', 'post_purchase'] as $topic) {
            $this->postJson('/api/meli/webhook', ['topic' => $topic, 'actions' => ['claims'], 'resource' => '/post-purchase/v1/claims/777', 'user_id' => '12345'])->assertOk();
        }
        Bus::assertDispatchedTimes(SyncMeliClaimJob::class, 3);
        Bus::assertDispatched(SyncMeliClaimJob::class, fn ($job) => $job->queue === 'meli' && $job->meliAccountId === $account->id && $job->claimId === '777');
    }

    private function withClaimTelegram(callable $test): void
    {
        $original = [];
        foreach (['TELEGRAM_BOT_TOKEN' => 'test-bot', 'TELEGRAM_ALERT_CHAT_IDS' => 'test-chat,test-chat'] as $key => $value) {
            $original[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
        try { $test(); }
        finally {
            foreach ($original as $key => [$env, $server, $process]) {
                if ($env === null) unset($_ENV[$key]); else $_ENV[$key] = $env;
                if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server;
                putenv($process === false ? $key : $key.'='.$process);
            }
        }
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
