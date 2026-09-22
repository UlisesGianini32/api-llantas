<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliAccountUserAccess;
use App\Models\MeliChatFlow;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use App\Services\MercadoLibre\Orders\MeliAgreedDeliveryMessagingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliAgreedDeliveryMessagingTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        DB::purge('sqlite');
        $this->createSchema();
        $this->user = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (['meli_chat_flows', 'meli_order_items', 'meli_orders', 'meli_account_user_accesses', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_primary_without_pack_uses_order_id_and_persists_successful_message(): void
    {
        $account = $this->account('100', 'primary-token', true);
        $order = $this->order($account, '10001');
        $this->fakeInitialSuccess('10001', 'primary-token', 'MESSAGE-1');

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('state.message_id', 'MESSAGE-1');

        $flow = MeliChatFlow::query()->sole();
        $this->assertSame('sent', data_get($flow->meta, 'delivery_details_request.status'));
        $this->assertSame('seller', data_get($flow->meta, 'conversation_started_by'));
        $this->assertTrue(data_get($flow->meta, 'automation_suppressed'));
        $this->assertSame('ams', data_get($flow->meta, 'human_started_source'));
        $this->assertSame('clean', data_get($flow->meta, 'delivery_details_request.moderation_status'));
        $this->assertNotNull(data_get($flow->meta, 'delivery_details_request.requested_at'));
        $this->assertSame('MESSAGE-1', data_get($flow->meta, 'delivery_details_request_history.0.message_id'));
        $this->assertTrue($this->requests()->contains(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/messages/action_guide/packs/10001/option'));
        $this->assertFalse($this->requests()->contains(fn (Request $request): bool => str_contains($request->url(), '/packs/null/')));
    }

    public function test_secondary_uses_its_token_and_pack_id_with_the_same_service(): void
    {
        $this->account('100', 'primary-token', true);
        $secondary = $this->account('200', 'secondary-token', false);
        $order = $this->order($secondary, '20001', ['pack_id' => 'PACK-200']);
        $this->fakeInitialSuccess('PACK-200', 'secondary-token', 'MESSAGE-2');

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))->assertOk();

        $this->assertTrue($this->requests()->every(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            return $authorization === 'Bearer secondary-token';
        }));
        $this->assertSame($secondary->id, MeliChatFlow::query()->sole()->meli_account_id);
    }

    public function test_authorized_delegated_operator_can_send_with_the_secondary_account_token(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $secondary = MeliAccount::factory()->create([
            'user_id' => $owner->id,
            'meli_user_id' => '201',
            'access_token' => 'delegated-secondary-token',
            'expires_at' => now()->addHour(),
            'is_default' => false,
        ]);
        MeliAccountUserAccess::query()->create([
            'meli_account_id' => $secondary->id,
            'user_id' => $this->user->id,
            'can_claim_actions' => true,
            'active' => true,
        ]);
        $order = $this->order($secondary, '20101', ['pack_id' => 'PACK-201']);
        $this->fakeInitialSuccess('PACK-201', 'delegated-secondary-token', 'MESSAGE-DELEGATED');

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->assertTrue($this->requests()->every(function (Request $request): bool {
            return ($request->header('Authorization')[0] ?? '') === 'Bearer delegated-secondary-token';
        }));
        $flow = MeliChatFlow::query()->sole();
        $this->assertSame($secondary->id, $flow->meli_account_id);
        $this->assertSame($owner->id, $flow->user_id);
    }

    public function test_non_agreed_cancelled_and_missing_orders_cannot_send(): void
    {
        $account = $this->account('300', 'token', true);
        $shipping = $this->order($account, '30001', ['delivery_type' => 'mercado_envios', 'shipping_id' => 'SHIP']);
        $cancelled = $this->order($account, '30002', ['status' => 'cancelled']);
        $fulfillment = $this->order($account, '30004', ['shipping_mode' => 'fulfillment']);

        $this->postJson(route('ams.pedidos.delivery_details.request', $shipping))->assertUnprocessable();
        $this->postJson(route('ams.pedidos.delivery_details.request', $cancelled))->assertUnprocessable();
        $this->postJson(route('ams.pedidos.delivery_details.request', $fulfillment))->assertUnprocessable();
        $this->postJson(route('ams.pedidos.delivery_details.request', 999999))->assertNotFound();

        $this->assertCount(0, $this->requests());
    }

    public function test_unauthorized_operator_receives_not_found_for_foreign_order(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $foreignAccount = MeliAccount::factory()->create([
            'user_id' => $other->id,
            'meli_user_id' => 'FOREIGN',
            'access_token' => 'foreign-token',
        ]);
        $foreign = $this->order($foreignAccount, '30003');

        $this->postJson(route('ams.pedidos.delivery_details.request', $foreign))->assertNotFound();
        $this->assertCount(0, $this->requests());
    }

    public function test_successive_or_concurrent_requests_do_not_duplicate_message(): void
    {
        $account = $this->account('400', 'token', true);
        $order = $this->order($account, '40001');
        $this->fakeInitialSuccess('40001', 'token', 'MESSAGE-4');

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))->assertOk();
        $this->postJson(route('ams.pedidos.delivery_details.request', $order))->assertConflict();
        $this->assertSame(1, $this->requests()->filter(fn (Request $request): bool => $request->method() === 'POST')->count());

        $otherOrder = $this->order($account, '40002');
        $service = app(MeliAgreedDeliveryMessagingService::class);
        $lock = Cache::lock($service->lockName($otherOrder), 60);
        $this->assertTrue($lock->get());
        try {
            $this->postJson(route('ams.pedidos.delivery_details.request', $otherOrder))->assertConflict();
        } finally {
            $lock->release();
        }
        $this->assertSame(1, $this->requests()->filter(fn (Request $request): bool => $request->method() === 'POST')->count());
    }

    public function test_other_without_cap_does_not_attempt_send(): void
    {
        $order = $this->order($this->account('500', 'token', true), '50001');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/messages/packs/')) {
                return Http::response(['messages' => []]);
            }

            return Http::response(['options' => [[
                'id' => 'OTHER', 'enabled' => true, 'actionable' => true, 'cap_available' => 0,
            ]]]);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertUnprocessable()
            ->assertJsonPath('status', 'unavailable');
        $this->assertSame(0, $this->requests()->filter(fn (Request $request): bool => $request->method() === 'POST')->count());
        $meta = (array) (MeliChatFlow::query()->sole()->meta ?? []);
        $this->assertArrayNotHasKey('conversation_started_by', $meta);
        $this->assertArrayNotHasKey('automation_suppressed', $meta);
        $this->assertArrayNotHasKey('human_start_attempt', $meta);
    }

    public function test_blocked_conversation_returns_controlled_error(): void
    {
        $order = $this->order($this->account('600', 'token', true), '60001');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/messages/packs/')) {
                return Http::response(['messages' => []]);
            }

            return Http::response([
                'cause' => 'blocked_by_cancelled_order',
                'message' => 'technical detail',
            ], 403);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertUnprocessable()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('message', 'La conversación está bloqueada por Mercado Libre.');
    }

    public function test_action_guide_blocked_messages_instruction_falls_back_to_normal_messages(): void
    {
        $order = $this->order($this->account('610', 'token', true), '61001');
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/messages/packs/61001/sellers/610') {
                return Http::response(['messages' => []]);
            }
            if ($request->method() === 'GET' && $path === '/messages/action_guide/packs/61001') {
                return Http::response([
                    'error' => 'forbidden',
                    'message' => 'This package has the conversation blocked, please check blocked messages',
                ], 403);
            }
            if ($request->method() === 'POST' && $path === '/messages/packs/61001/sellers/610') {
                return Http::response($this->messageResponse('NORMAL-610'), 201);
            }

            return Http::response([], 500);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertOk()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('state.message_id', 'NORMAL-610');
        $this->assertSame(1, $this->requests()->filter(fn (Request $request): bool => $request->method() === 'POST')->count());
    }

    public function test_literal_blocked_conversation_does_not_fall_back_to_normal_messages(): void
    {
        $order = $this->order($this->account('620', 'token', true), '62001');
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/messages/packs/62001/sellers/620') {
                return Http::response(['messages' => []]);
            }

            return Http::response([
                'error' => 'forbidden',
                'message' => 'The conversation is blocked',
            ], 403);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertUnprocessable()
            ->assertJsonPath('status', 'blocked');
        $this->assertSame(0, $this->requests()->filter(fn (Request $request): bool => $request->method() === 'POST')->count());
    }

    public function test_excepted_case_instruction_still_falls_back_to_normal_messages(): void
    {
        $order = $this->order($this->account('630', 'token', true), '63001');
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/messages/packs/63001/sellers/630') {
                return Http::response(['messages' => []]);
            }
            if ($request->method() === 'GET' && $path === '/messages/action_guide/packs/63001') {
                return Http::response([
                    'error' => 'forbidden',
                    'message' => 'This pack belongs to an excepted case, it is requested to use the messaging resource.',
                ], 403);
            }
            if ($request->method() === 'POST' && $path === '/messages/packs/63001/sellers/630') {
                return Http::response($this->messageResponse('NORMAL-630'), 201);
            }

            return Http::response([], 500);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertOk()
            ->assertJsonPath('status', 'sent');
        $this->assertSame(1, $this->requests()->filter(fn (Request $request): bool => $request->method() === 'POST')->count());
    }

    public function test_moderated_response_is_not_success_and_preserves_reason(): void
    {
        $order = $this->order($this->account('700', 'token', true), '70001');
        $this->fakeInitialSuccess('70001', 'token', 'MESSAGE-7', 'moderated', 'rejected', 'personal_data');

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('state.moderation_reason', 'personal_data');

        $this->assertNull(data_get(MeliChatFlow::query()->sole()->meta, 'delivery_details_request.requested_at'));
        $this->assertArrayNotHasKey('conversation_started_by', (array) MeliChatFlow::query()->sole()->meta);
    }

    public function test_pending_moderation_is_an_accepted_warning_without_technical_error(): void
    {
        $order = $this->order($this->account('710', 'token', true), '71001');
        $this->fakeInitialSuccess('71001', 'token', 'MESSAGE-710', 'pending_translation', 'pending');

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertAccepted()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'pending_moderation')
            ->assertJsonMissingPath('state.technical_error');
        $this->assertTrue((bool) data_get(MeliChatFlow::query()->sole()->meta, 'automation_suppressed'));
    }

    public function test_uncertain_delivery_is_an_accepted_warning_without_claiming_failure_or_success(): void
    {
        $order = $this->order($this->account('720', 'token', true), '72001');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertAccepted()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'uncertain')
            ->assertJsonMissingPath('state.technical_error');
        $this->assertTrue((bool) data_get(MeliChatFlow::query()->sole()->meta, 'automation_suppressed'));
    }

    public function test_existing_conversation_uses_normal_messages_resource(): void
    {
        $order = $this->order($this->account('800', 'token', true), '80001');
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_contains($path, '/messages/packs/')) {
                return Http::response(['messages' => [['id' => 'OLD']]]);
            }
            if ($request->method() === 'POST' && str_contains($path, '/messages/packs/')) {
                return Http::response($this->messageResponse('NORMAL-8'), 201);
            }

            return Http::response([], 500);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))->assertOk();
        $this->assertTrue($this->requests()->contains(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/messages/packs/80001/sellers/800'));
        $this->assertFalse($this->requests()->contains(fn (Request $request): bool => str_contains($request->url(), 'action_guide')));
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('operations');
            $table->string('meli_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('meli_account_user_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id');
            $table->foreignId('user_id');
            $table->boolean('can_claim_actions')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['meli_account_id', 'user_id']);
        });
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('order_id')->unique();
            $table->string('status')->nullable();
            $table->string('shipping_id')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('shipping_mode')->nullable();
            $table->string('shipping_type')->nullable();
            $table->string('shipping_logistic_type')->nullable();
            $table->string('delivery_type')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id');
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('meli_chat_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('conversation_id')->nullable();
            $table->string('message_id')->nullable();
            $table->string('last_inbound_message_id')->nullable();
            $table->string('last_message_role')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->text('last_message_text')->nullable();
            $table->timestamp('last_message_synced_at')->nullable();
            $table->string('buyer_id')->nullable();
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('menu_sent')->default(false);
            $table->timestamp('menu_sent_at')->nullable();
            $table->string('last_option_selected')->nullable();
            $table->timestamp('last_option_selected_at')->nullable();
            $table->boolean('requires_human')->default(false);
            $table->timestamp('requires_human_at')->nullable();
            $table->string('product_pdf_url')->nullable();
            $table->string('catalog_pdf_url')->nullable();
            $table->string('invoice_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['meli_account_id', 'order_id', 'buyer_id']);
        });
    }

    private function account(string $meliId, string $token, bool $default): MeliAccount
    {
        return MeliAccount::factory()->create([
            'user_id' => $this->user->id,
            'meli_user_id' => $meliId,
            'access_token' => $token,
            'expires_at' => now()->addHour(),
            'is_default' => $default,
        ]);
    }

    private function order(MeliAccount $account, string $orderId, array $overrides = []): MeliOrder
    {
        $order = MeliOrder::query()->create(array_merge([
            'meli_account_id' => $account->id,
            'order_id' => $orderId,
            'status' => 'paid',
            'delivery_type' => 'agreed_with_buyer',
            'raw' => ['buyer' => ['id' => 'BUYER-'.$orderId, 'nickname' => 'buyer'], 'site_id' => 'MLM'],
        ], $overrides));
        MeliOrderItem::query()->create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM-'.$orderId,
            'sku' => 'SKU-'.$orderId,
            'title' => 'Producto',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        return $order;
    }

    private function fakeInitialSuccess(
        string $resourceId,
        string $token,
        string $messageId,
        string $messageStatus = 'available',
        string $moderationStatus = 'clean',
        ?string $moderationReason = null
    ): void {
        Http::fake(function (Request $request) use ($resourceId, $token, $messageId, $messageStatus, $moderationStatus, $moderationReason) {
            $this->assertSame('Bearer '.$token, $request->header('Authorization')[0] ?? null);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_contains($path, '/messages/packs/')) {
                return Http::response(['messages' => []]);
            }
            if ($request->method() === 'GET' && $path === "/messages/action_guide/packs/{$resourceId}") {
                return Http::response(['options' => [[
                    'id' => 'OTHER', 'enabled' => true, 'actionable' => true, 'cap_available' => 1,
                ]]]);
            }
            if ($request->method() === 'POST' && $path === "/messages/action_guide/packs/{$resourceId}/option") {
                return Http::response($this->messageResponse($messageId, $messageStatus, $moderationStatus, $moderationReason), 201);
            }

            return Http::response([], 404);
        });
    }

    private function messageResponse(
        string $id,
        string $status = 'available',
        string $moderationStatus = 'clean',
        ?string $reason = null
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'message_moderation' => ['status' => $moderationStatus, 'reason' => $reason],
        ];
    }

    private function requests()
    {
        return collect(Http::recorded())->pluck(0);
    }
}
