<?php

namespace Tests\Feature;

use App\Jobs\ProcessMeliOrderNotification;
use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use App\Services\MeliOrderSyncService;
use App\Services\MeliSharedStockOrderService;
use App\Services\StockService;
use App\Services\SyscomOrderFromMeliService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class MeliOrderDeliveryTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32)->default('operations');
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
            $table->string('topic')->nullable();
            $table->string('resource')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_id')->nullable();
            $table->string('pack_id')->nullable();
            $table->string('shipping_status')->nullable();
            $table->string('shipping_substatus')->nullable();
            $table->string('shipping_mode')->nullable();
            $table->string('shipping_type')->nullable();
            $table->string('shipping_logistic_type')->nullable();
            $table->date('shipping_process_date')->nullable();
            $table->json('shipping_raw')->nullable();
            $table->string('delivery_type')->nullable();
            $table->string('delivery_classification_reason')->nullable();
            $table->timestamp('delivery_classified_at')->nullable();
            $table->boolean('needs_shipping_review')->default(false);
            $table->string('display_id')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('stock_applied_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id');
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->string('variation_text')->nullable();
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
        });
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('ml')->nullable();
            $table->string('name')->nullable();
            $table->string('thumbnail')->nullable();
            $table->decimal('price', 14, 2)->default(0);
        });

        DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value, 1);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->mock(StockService::class)->shouldReceive('applyStockFromMeliOrderIfNeeded')->zeroOrMoreTimes();
        $this->mock(SyscomOrderFromMeliService::class)->shouldReceive('handleAfterMeliSync')->zeroOrMoreTimes();
        $this->mock(MeliSharedStockOrderService::class)->shouldReceive('reconcile')->zeroOrMoreTimes();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach (['products', 'meli_chat_flows', 'meli_order_items', 'meli_orders', 'meli_account_user_accesses', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_primary_and_secondary_detect_not_specified_without_duplicates_or_repeated_item_lookup(): void
    {
        $primary = $this->account('100', 'primary-token', true, 'Principal');
        $secondary = $this->account('200', 'secondary-token', false, 'Secundaria');
        $ordersByToken = [
            'primary-token' => [$this->remoteOrder('10001', 'MLM-PRIMARY')],
            'secondary-token' => [$this->remoteOrder('20001', 'MLM-SECONDARY')],
        ];
        $this->fakeOrdersApi($ordersByToken, ['MLM-PRIMARY' => 'not_specified', 'MLM-SECONDARY' => 'not_specified']);
        $service = app(MeliOrderSyncService::class);

        $service->syncDay($this->apiUser($primary), now()->toDateString());
        $service->syncDay($this->apiUser($secondary), now()->toDateString());
        $itemLookups = $this->itemLookupCount();
        $service->syncDay($this->apiUser($primary), now()->toDateString());

        $this->assertDatabaseHas('meli_orders', ['order_id' => '10001', 'meli_account_id' => $primary->id, 'delivery_type' => 'agreed_with_buyer', 'shipping_mode' => 'not_specified']);
        $this->assertDatabaseHas('meli_orders', ['order_id' => '20001', 'meli_account_id' => $secondary->id, 'delivery_type' => 'agreed_with_buyer', 'shipping_mode' => 'not_specified']);
        $this->assertSame(2, MeliOrder::query()->count());
        $this->assertSame(2, MeliOrderItem::query()->count());
        $this->assertSame($itemLookups, $this->itemLookupCount());
    }

    public function test_pending_order_becomes_mercado_envios_when_shipment_appears(): void
    {
        $account = $this->account('300', 'transition-token', true, 'Principal');
        $hasShipment = false;
        Http::fake(function (Request $request) use (&$hasShipment) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/users/me') {
                return Http::response(['id' => 300]);
            }
            if ($path === '/orders/search') {
                return Http::response(['results' => [$this->remoteOrder('30001', 'MLM-ME2', $hasShipment ? 'SHIP-300' : null)], 'paging' => ['total' => 1]]);
            }
            if ($path === '/items/MLM-ME2') {
                return Http::response(['shipping' => ['mode' => 'me2']]);
            }
            if ($path === '/shipments/SHIP-300') {
                return Http::response(['id' => 'SHIP-300', 'mode' => 'me2', 'status' => 'ready_to_ship']);
            }

            return Http::response([], 404);
        });
        $service = app(MeliOrderSyncService::class);

        $service->syncDay($this->apiUser($account), now()->toDateString());
        $this->assertDatabaseHas('meli_orders', ['order_id' => '30001', 'delivery_type' => 'pending_shipment', 'shipping_id' => null]);

        $hasShipment = true;
        $service->syncDay($this->apiUser($account), now()->toDateString());
        $this->assertDatabaseHas('meli_orders', ['order_id' => '30001', 'delivery_type' => 'mercado_envios', 'shipping_id' => 'SHIP-300']);
    }

    public function test_item_lookup_error_is_unknown_and_needs_review(): void
    {
        $account = $this->account('400', 'error-token', true, 'Principal');
        $this->fakeOrdersApi(['error-token' => [$this->remoteOrder('40001', 'MLM-ERROR')]], [], 503);

        app(MeliOrderSyncService::class)->syncDay($this->apiUser($account), now()->toDateString());

        $this->assertDatabaseHas('meli_orders', [
            'order_id' => '40001',
            'delivery_type' => 'unknown',
            'needs_shipping_review' => true,
        ]);
    }

    public function test_account_and_agreed_delivery_filters_are_scoped_and_all_accounts_do_not_duplicate(): void
    {
        $primary = $this->account('500', 'primary-filter', true, 'Principal');
        $secondary = $this->account('600', 'secondary-filter', false, 'Secundaria');
        $agreed = $this->localOrder($primary, '50001', 'agreed_with_buyer', null);
        $shipping = $this->localOrder($secondary, '60001', 'mercado_envios', 'SHIP-600');
        $this->localItem($agreed, 'MLM-AGREED');
        $this->localItem($shipping, 'MLM-SHIPPING');

        $this->get(route('ams.pedidos.index', ['fecha' => now()->toDateString(), 'account_id' => 'all', 'delivery_type' => 'agreed_with_buyer']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ams/PedidosIndex')
                ->where('totalPedidos', 1)
                ->where('pedidos.0.order_id', '50001')
                ->where('pedidos.0.meli_account_name', 'Principal')
                ->where('pedidos.0.can_print_shipping_label', false)
                ->where('pedidos.0.can_request_delivery_details', true));

        $this->get(route('ams.pedidos.index', ['fecha' => now()->toDateString(), 'account_id' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('totalPedidos', 2));

        $this->get(route('ams.pedidos.index', ['fecha' => now()->toDateString(), 'account_id' => $secondary->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalPedidos', 1)
                ->where('pedidos.0.order_id', '60001')
                ->where('pedidos.0.can_print_shipping_label', true));
    }

    public function test_backfill_all_accounts_uses_the_shared_sync_service(): void
    {
        $this->account('700', 'backfill-primary', true, 'Principal');
        $this->account('800', 'backfill-secondary', false, 'Secundaria');
        $sync = Mockery::mock(MeliOrderSyncService::class);
        $sync->shouldReceive('syncDay')->twice()
            ->withArgs(fn (User $user, string $date): bool => in_array($user->meli_id, ['700', '800'], true)
                && $date === now()->toDateString())
            ->andReturn(['orders' => 1, 'items' => 1, 'failed' => 0]);
        $this->app->instance(MeliOrderSyncService::class, $sync);

        $this->artisan('meli:backfill-order-deliveries', [
            '--all' => true,
            '--days' => 1,
            '--mode' => 'incremental',
        ])->assertSuccessful();
    }

    public function test_regular_sync_all_accounts_includes_primary_secondary_and_future_accounts(): void
    {
        foreach ([
            ['701', 'sync-primary', true, 'Principal'],
            ['801', 'sync-secondary', false, 'Secundaria'],
            ['901', 'sync-future', false, 'Tercera'],
        ] as [$meliUserId, $token, $default, $nickname]) {
            $this->account($meliUserId, $token, $default, $nickname);
        }
        $seen = [];
        $sync = Mockery::mock(MeliOrderSyncService::class);
        $sync->shouldReceive('syncDay')->times(3)
            ->andReturnUsing(function (User $user, string $date) use (&$seen): array {
                $seen[] = $user->meli_id;

                return [
                    'orders' => 1,
                    'items' => 1,
                    'failed' => 0,
                    'seller_id' => $user->meli_id,
                    'meli_account_id' => MeliAccount::query()->where('meli_user_id', $user->meli_id)->value('id'),
                    'date' => $date,
                ];
            });
        $this->app->instance(MeliOrderSyncService::class, $sync);

        $this->artisan('meli:sync-orders', ['--all-accounts' => true, '--today' => true])->assertSuccessful();

        $this->assertSame(['701', '801', '901'], $seen);
    }

    public function test_order_webhook_resolves_the_notified_account_and_reuses_shared_sync(): void
    {
        $secondary = $this->account('900', 'webhook-secondary', false, 'Secundaria');
        $sync = Mockery::mock(MeliOrderSyncService::class);
        $sync->shouldReceive('syncOrderById')->once()
            ->withArgs(fn (User $user, string $orderId): bool => $user->id === $this->user->id
                && $user->meli_id === $secondary->meli_user_id
                && $user->access_token === 'webhook-secondary'
                && $orderId === '90001')
            ->andReturn(['order_id' => '90001', 'items' => 1]);

        (new ProcessMeliOrderNotification([
            'resource' => '/orders/90001',
            'user_id' => '900',
        ]))->handle($sync);
    }

    private function account(string $meliUserId, string $token, bool $default, string $nickname): MeliAccount
    {
        return MeliAccount::factory()->create([
            'user_id' => $this->user->id,
            'meli_user_id' => $meliUserId,
            'access_token' => $token,
            'expires_at' => now()->addHour(),
            'is_default' => $default,
            'nickname' => $nickname,
        ]);
    }

    private function apiUser(MeliAccount $account): User
    {
        $user = $this->user->replicate();
        $user->forceFill(['id' => $this->user->id, 'meli_id' => $account->meli_user_id, 'access_token' => $account->access_token]);
        $user->exists = true;

        return $user;
    }

    private function remoteOrder(string $id, string $itemId, ?string $shippingId = null): array
    {
        return [
            'id' => $id,
            'status' => 'paid',
            'pack_id' => null,
            'shipping' => ['id' => $shippingId],
            'date_created' => now()->toISOString(),
            'last_updated' => '2026-09-21T12:00:00.000Z',
            'buyer' => ['nickname' => 'buyer-'.$id],
            'order_items' => [[
                'item' => ['id' => $itemId, 'seller_sku' => 'SKU-'.$id, 'title' => 'Producto '.$id],
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ];
    }

    private function fakeOrdersApi(array $ordersByToken, array $itemModes, int $missingItemStatus = 404): void
    {
        Http::fake(function (Request $request) use ($ordersByToken, $itemModes, $missingItemStatus) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            if ($path === '/users/me') {
                return Http::response(['id' => (int) collect($ordersByToken)->keys()->search($token) + 1]);
            }
            if ($path === '/orders/search') {
                $orders = $ordersByToken[$token] ?? [];

                return Http::response(['results' => $orders, 'paging' => ['total' => count($orders)]]);
            }
            if (preg_match('#^/items/([^/]+)$#', $path, $match)) {
                return array_key_exists($match[1], $itemModes)
                    ? Http::response(['shipping' => ['mode' => $itemModes[$match[1]]]])
                    : Http::response([], $missingItemStatus);
            }

            return Http::response([], 404);
        });
    }

    private function itemLookupCount(): int
    {
        return collect(Http::recorded())->pluck(0)
            ->filter(fn (Request $request): bool => str_contains($request->url(), '/items/'))
            ->count();
    }

    private function localOrder(MeliAccount $account, string $id, string $deliveryType, ?string $shippingId): MeliOrder
    {
        return MeliOrder::query()->create([
            'meli_account_id' => $account->id,
            'order_id' => $id,
            'status' => 'paid',
            'shipping_id' => $shippingId,
            'shipping_mode' => $shippingId ? 'me2' : 'not_specified',
            'delivery_type' => $deliveryType,
            'delivery_classification_reason' => 'test',
            'delivery_classified_at' => now(),
            'raw' => ['buyer' => ['nickname' => 'buyer-'.$id], 'total_amount' => 100, 'currency_id' => 'MXN'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function localItem(MeliOrder $order, string $itemId): void
    {
        MeliOrderItem::query()->create([
            'meli_order_id' => $order->id,
            'item_id' => $itemId,
            'sku' => 'SKU-'.$itemId,
            'title' => 'Producto '.$itemId,
            'quantity' => 1,
            'unit_price' => 100,
        ]);
    }
}
