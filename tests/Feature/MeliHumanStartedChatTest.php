<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliChatFlow;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use App\Services\MeliApi;
use App\Services\MeliMenuAutomationService;
use App\Services\MeliMessageService;
use App\Services\TelegramAlertService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class MeliHumanStartedChatTest extends TestCase
{
    private User $user;

    private MeliAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        $this->createSchema();
        $this->user = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $this->account = MeliAccount::factory()->create([
            'user_id' => $this->user->id,
            'meli_user_id' => '900',
            'access_token' => 'meli-token',
            'expires_at' => now()->addHour(),
            'is_default' => true,
        ]);
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (['meli_chat_flows', 'meli_order_items', 'meli_orders', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_buyer_started_conversation_keeps_menu_and_marks_buyer_origin(): void
    {
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldReceive('sendMessage')->once()->andReturnTrue();
        $service = $this->automation($sender);

        $service->handleIncomingEvent($this->buyerEvent('MSG-1', 'Hola'), $this->user->id);

        $flow = MeliChatFlow::query()->sole();
        $this->assertTrue($flow->menu_sent);
        $this->assertSame('buyer', data_get($flow->meta, 'conversation_started_by'));
        $this->assertFalse((bool) data_get($flow->meta, 'automation_suppressed', false));
    }

    public function test_seller_started_conversation_suppresses_all_automation_but_persists_buyer_message(): void
    {
        $flow = $this->flow('ORDER-SELLER');
        $flow->forceFill(['meta' => [
            'conversation_started_by' => 'seller',
            'automation_suppressed' => true,
            'human_started_at' => now()->toIso8601String(),
            'human_started_by' => $this->user->id,
        ]])->save();
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldNotReceive('sendMessage');
        $service = $this->automation($sender);

        $service->handleIncomingEvent($this->buyerEvent('MSG-2', "Juan Perez\n6620000000\nCalle 1", 'ORDER-SELLER'), $this->user->id);

        $saved = $flow->fresh();
        $this->assertSame('customer', $saved->last_message_role);
        $this->assertStringContainsString('Juan Perez', $saved->last_message_text);
        $this->assertFalse($saved->menu_sent);
        $this->assertTrue(data_get($saved->meta, 'automation_suppressed'));
    }

    public function test_seller_started_conversation_ignores_numeric_and_arbitrary_replies_without_reactivation(): void
    {
        $flow = $this->flow('ORDER-NO-BOT')->forceFill([
            'menu_sent' => false,
            'meta' => ['conversation_started_by' => 'seller', 'automation_suppressed' => true],
        ]);
        $flow->save();
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldNotReceive('sendMessage');
        $service = $this->automation($sender);

        $service->handleIncomingEvent($this->buyerEvent('MSG-3', '1', 'ORDER-NO-BOT'), $this->user->id);
        $service->handleIncomingEvent($this->buyerEvent('MSG-4', 'texto cualquiera', 'ORDER-NO-BOT'), $this->user->id);
        $this->assertFalse($service->sendMenuIfNeeded($flow->fresh()));
        $this->assertFalse($flow->fresh()->menu_sent);
    }

    public function test_buyer_started_conversation_still_processes_option_one(): void
    {
        $flow = $this->flow('ORDER-BUYER')->forceFill([
            'menu_sent' => true,
            'product_pdf_url' => 'https://example.test/product.pdf',
            'meta' => ['conversation_started_by' => 'buyer'],
        ]);
        $flow->save();
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldReceive('sendMessage')->once()->withArgs(
            fn (MeliChatFlow $target, string $text): bool => $target->is($flow) && str_contains($text, 'product.pdf')
        )->andReturnTrue();
        $service = $this->automation($sender);

        $service->handleIncomingEvent($this->buyerEvent('MSG-5', '1', 'ORDER-BUYER'), $this->user->id);

        $this->assertSame('1', $flow->fresh()->last_option_selected);
    }

    public function test_manual_messaging_reply_marks_seller_started(): void
    {
        $flow = $this->flow('ORDER-MANUAL');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.mercadolibre.com')) {
                return Http::response(['id' => 'MANUAL-1'], 201);
            }

            return Http::response(['ok' => true]);
        });

        $this->post(route('meli.messaging.reply', ['flow' => $flow->id]), [
            'text' => 'Buen día, ¿en qué podemos ayudarte?',
        ])->assertRedirect();

        $saved = $flow->fresh();
        $this->assertSame('seller', data_get($saved->meta, 'conversation_started_by'));
        $this->assertTrue(data_get($saved->meta, 'automation_suppressed'));
        $this->assertSame('messaging', data_get($saved->meta, 'human_started_source'));
        $this->assertArrayNotHasKey('human_start_attempt', $saved->meta);
    }

    public function test_definitive_manual_messaging_failure_rolls_back_only_new_suppression(): void
    {
        $flow = $this->flow('ORDER-MANUAL-FAIL');
        Http::fake(fn () => Http::response(['message' => 'rejected'], 422));

        $this->post(route('meli.messaging.reply', ['flow' => $flow->id]), [
            'text' => 'Mensaje rechazado',
        ])->assertRedirect();

        $meta = (array) ($flow->fresh()->meta ?? []);
        $this->assertArrayNotHasKey('conversation_started_by', $meta);
        $this->assertArrayNotHasKey('automation_suppressed', $meta);
        $this->assertArrayNotHasKey('human_start_attempt', $meta);
    }

    public function test_ambiguous_manual_messaging_failure_keeps_suppression(): void
    {
        $flow = $this->flow('ORDER-MANUAL-UNCERTAIN');
        Http::fake(fn () => Http::response(['message' => 'temporary failure'], 500));

        $this->post(route('meli.messaging.reply', ['flow' => $flow->id]), [
            'text' => 'Mensaje incierto',
        ])->assertRedirect();

        $saved = $flow->fresh();
        $this->assertSame('seller', data_get($saved->meta, 'conversation_started_by'));
        $this->assertTrue((bool) data_get($saved->meta, 'automation_suppressed'));
        $this->assertArrayNotHasKey('human_start_attempt', $saved->meta);
    }

    public function test_uncertain_manual_messaging_failure_keeps_suppression(): void
    {
        $flow = $this->flow('ORDER-MANUAL-TIMEOUT');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->post(route('meli.messaging.reply', ['flow' => $flow->id]), [
            'text' => 'Mensaje sin confirmación',
        ])->assertRedirect();

        $saved = $flow->fresh();
        $this->assertSame('seller', data_get($saved->meta, 'conversation_started_by'));
        $this->assertTrue((bool) data_get($saved->meta, 'automation_suppressed'));
        $this->assertArrayNotHasKey('human_start_attempt', $saved->meta);
    }

    public function test_rate_limited_manual_messaging_failure_keeps_suppression(): void
    {
        $flow = $this->flow('ORDER-MANUAL-RATE-LIMIT');
        Http::fake(fn () => Http::response(['message' => 'rate limited'], 429));

        $this->post(route('meli.messaging.reply', ['flow' => $flow->id]), [
            'text' => 'Mensaje limitado',
        ])->assertRedirect();

        $saved = $flow->fresh();
        $this->assertSame('seller', data_get($saved->meta, 'conversation_started_by'));
        $this->assertTrue((bool) data_get($saved->meta, 'automation_suppressed'));
        $this->assertArrayNotHasKey('human_start_attempt', $saved->meta);
    }

    public function test_preexisting_suppression_survives_definitive_manual_failure(): void
    {
        $flow = $this->flow('ORDER-MANUAL-PREEXISTING')->forceFill([
            'meta' => [
                'conversation_started_by' => 'seller',
                'automation_suppressed' => true,
                'human_started_by' => $this->user->id,
            ],
        ]);
        $flow->save();
        Http::fake(fn () => Http::response(['message' => 'rejected'], 422));

        $this->post(route('meli.messaging.reply', ['flow' => $flow->id]), [
            'text' => 'Segundo intento',
        ])->assertRedirect();

        $meta = (array) ($flow->fresh()->meta ?? []);
        $this->assertSame('seller', $meta['conversation_started_by']);
        $this->assertTrue($meta['automation_suppressed']);
        $this->assertSame($this->user->id, $meta['human_started_by']);
    }

    public function test_webhook_during_manual_attempt_does_not_reactivate_automation(): void
    {
        $flow = $this->flow('ORDER-MANUAL-WEBHOOK');
        $messages = app(MeliMessageService::class);
        $prepared = $messages->prepareHumanStarted($flow, $this->user->id, 'messaging');
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldNotReceive('sendMessage');
        $this->automation($sender)->handleIncomingEvent(
            $this->buyerEvent('MSG-DURING-MANUAL', 'Datos del comprador', 'ORDER-MANUAL-WEBHOOK'),
            $this->user->id
        );

        $saved = $flow->fresh();
        $this->assertFalse($saved->menu_sent);
        $this->assertSame('customer', $saved->last_message_role);
        $this->assertTrue((bool) data_get($saved->meta, 'automation_suppressed'));
        $messages->rollbackHumanStarted($prepared['flow'], $prepared['token']);
        $this->assertFalse($flow->fresh()->menu_sent);
    }

    public function test_stale_webhook_merge_cannot_remove_human_suppression(): void
    {
        $flow = $this->flow('ORDER-MANUAL-STALE');
        $stale = $flow->fresh();
        $messages = app(MeliMessageService::class);
        $messages->prepareHumanStarted($flow->fresh(), $this->user->id, 'messaging');

        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldNotReceive('sendMessage');
        $service = $this->automation($sender);
        $event = $this->buyerEvent('MSG-STALE', 'Datos del comprador', 'ORDER-MANUAL-STALE');
        $method = new \ReflectionMethod($service, 'syncFlowContext');
        $method->setAccessible(true);
        $method->invoke($service, $stale, $event);

        $saved = $flow->fresh();
        $this->assertSame('seller', data_get($saved->meta, 'conversation_started_by'));
        $this->assertTrue((bool) data_get($saved->meta, 'automation_suppressed'));
        $this->assertFalse($saved->menu_sent);
        $this->assertSame('customer', $saved->last_message_role);

        $service->handleIncomingEvent($this->buyerEvent('MSG-STALE-2', 'Otro mensaje', 'ORDER-MANUAL-STALE'), $this->user->id);
        $this->assertFalse($flow->fresh()->menu_sent);
    }

    public function test_seller_started_flow_remains_visible_in_post_sale_messaging(): void
    {
        $flow = $this->flow('ORDER-VISIBLE')->forceFill([
            'meta' => ['conversation_started_by' => 'seller', 'automation_suppressed' => true],
        ]);
        $flow->save();

        $this->get(route('meli.messaging.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('flows.0.id', $flow->id)
                ->where('flows.0.meli_account_id', $this->account->id));
    }

    public function test_buyer_started_flow_remains_account_scoped_for_a_secondary_meli_account(): void
    {
        $secondary = MeliAccount::factory()->create([
            'user_id' => $this->user->id,
            'meli_user_id' => '901',
            'access_token' => 'secondary-token',
            'expires_at' => now()->addHour(),
            'is_default' => false,
        ]);
        $sender = Mockery::mock(MeliMessageService::class);
        $sender->shouldReceive('sendMessage')->once()->withArgs(
            fn (MeliChatFlow $flow, string $text): bool => (int) $flow->meli_account_id === (int) $secondary->id
        )->andReturnTrue();
        $service = $this->automation($sender);
        $event = $this->buyerEvent('MSG-SECONDARY', 'Hola', 'ORDER-SECONDARY');
        $event['meli_account_id'] = $secondary->id;

        $service->handleIncomingEvent($event, $this->user->id);

        $this->assertSame($secondary->id, MeliChatFlow::query()->sole()->meli_account_id);
        $this->assertSame('buyer', data_get(MeliChatFlow::query()->sole()->meta, 'conversation_started_by'));
    }

    public function test_ams_delivery_request_marks_seller_started(): void
    {
        $order = MeliOrder::query()->create([
            'meli_account_id' => $this->account->id,
            'order_id' => 'ORDER-AMS',
            'status' => 'paid',
            'delivery_type' => 'agreed_with_buyer',
            'raw' => ['buyer' => ['id' => 'BUYER-AMS'], 'site_id' => 'MLM'],
        ]);
        MeliOrderItem::query()->create([
            'meli_order_id' => $order->id,
            'item_id' => 'ITEM-AMS',
            'sku' => 'SKU-AMS',
            'title' => 'Producto',
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($path, '/messages/packs/')) {
                return Http::response(['messages' => []]);
            }
            if (str_contains($path, '/messages/action_guide/packs/') && $request->method() === 'GET') {
                return Http::response(['options' => [[
                    'id' => 'OTHER', 'enabled' => true, 'actionable' => true, 'cap_available' => 1,
                ]]]);
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'AMS-1', 'status' => 'available', 'message_moderation' => ['status' => 'clean']], 201);
            }

            return Http::response([], 404);
        });

        $this->postJson(route('ams.pedidos.delivery_details.request', $order))
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->assertTrue((bool) data_get(MeliChatFlow::query()->sole()->meta, 'automation_suppressed'));
        $this->assertSame('ams', data_get(MeliChatFlow::query()->sole()->meta, 'human_started_source'));
    }

    private function automation(MeliMessageService $sender): MeliMenuAutomationService
    {
        return new MeliMenuAutomationService(
            $sender,
            Mockery::mock(MeliApi::class),
            Mockery::mock(TelegramAlertService::class)
        );
    }

    /** @return array<string, mixed> */
    private function buyerEvent(string $messageId, string $text, string $order = 'ORDER-BUYER-EVENT'): array
    {
        return [
            'event_type' => 'buyer_message',
            'order_id' => $order,
            'buyer_id' => 'BUYER-EVENT',
            'pack_id' => 'PACK-EVENT',
            'message_id' => $messageId,
            'message_text' => $text,
            'meli_account_id' => $this->account->id,
            'site_id' => 'MLM',
        ];
    }

    private function flow(string $order): MeliChatFlow
    {
        return MeliChatFlow::query()->create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->account->id,
            'order_id' => $order,
            'pack_id' => $order,
            'buyer_id' => 'BUYER-EVENT',
        ]);
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
}
