<?php

namespace Tests\Feature;

use App\Http\Controllers\AmsPedidosController;
use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliOrderActionLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliOrderCancellationTest extends TestCase
{
    private object $auditMigration;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password');
            $table->timestamp('email_verified_at')->nullable(); $table->rememberToken(); $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable(); $table->timestamp('two_factor_confirmed_at')->nullable(); $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id(); $table->foreignId('user_id'); $table->string('meli_user_id'); $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable(); $table->text('access_token')->nullable(); $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable(); $table->boolean('is_default')->default(false); $table->timestamps();
        });
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id(); $table->foreignId('meli_account_id')->nullable(); $table->string('order_id')->unique(); $table->string('status')->nullable();
            $table->string('display_id')->nullable(); $table->string('shipping_id')->nullable(); $table->string('shipping_status')->nullable();
            $table->string('shipping_substatus')->nullable(); $table->json('shipping_raw')->nullable(); $table->json('raw')->nullable(); $table->timestamps();
        });
        $this->auditMigration = require database_path('migrations/2026_09_03_000001_create_meli_order_action_logs_table.php');
        $this->auditMigration->up();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->auditMigration->down();
        foreach (['meli_orders', 'meli_accounts', 'users'] as $table) Schema::dropIfExists($table);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_route_requires_auth_and_foreign_order_is_404_before_validation_with_zero_http(): void
    {
        $order = $this->order($this->account());
        auth()->logout();
        $this->post(route('ams.secondary.orders.cancel', $order))->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        Http::fake();
        $this->post(route('ams.secondary.orders.cancel', $order), [])->assertNotFound();
        $this->assertCount(0, Http::recorded());
    }

    public function test_single_real_order_uses_correct_account_exact_endpoint_and_controlled_payload(): void
    {
        $account = $this->account(['access_token' => 'secondary-token', 'meli_user_id' => '3546871162']);
        $order = $this->order($account, ['order_id' => '2000014797513923', 'raw' => ['pack_id' => 'PACK-2000014797513923']]);
        $postSeen = false;
        Http::fake(function (Request $request) use (&$postSeen) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') { $postSeen = true; return Http::response(['id' => 'feedback-1'], 201); }
            if (str_ends_with($path, '/feedback')) return Http::response(['sale' => null, 'purchase' => null]);
            if (str_contains($path, '/shipments/')) return Http::response(['id' => 'SHIP-1', 'status' => 'ready_to_ship', 'substatus' => 'ready_to_print']);
            return Http::response(['id' => 2000014797513923, 'status' => 'paid', 'shipping' => ['id' => 'SHIP-1'], 'tags' => $postSeen ? ['unfulfilled'] : []]);
        });

        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'OUT_OF_STOCK', 'confirmed' => true])->assertSessionHas('success');

        $requests = collect(Http::recorded())->pluck(0);
        $post = $requests->filter(fn (Request $request): bool => $request->method() === 'POST')->sole();
        $this->assertStringEndsWith('/orders/2000014797513923/feedback', parse_url($post->url(), PHP_URL_PATH));
        $this->assertStringNotContainsString('PACK-', $post->url());
        $this->assertSame(['fulfilled' => false, 'rating' => 'neutral', 'message' => 'No podemos completar la venta porque el producto no está disponible.', 'reason' => 'OUT_OF_STOCK', 'restock_item' => false], $post->data());
        $this->assertLessThan(160, strlen($post->data()['message']));
        $this->assertTrue($post->hasHeader('Authorization', 'Bearer secondary-token'));
        $prePost = $requests->takeUntil(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame(['GET', 'GET', 'GET'], $prePost->map(fn (Request $request): string => $request->method())->values()->all());
        $this->assertStringEndsWith('/orders/2000014797513923', parse_url($prePost->values()[0]->url(), PHP_URL_PATH));
        $this->assertStringEndsWith('/orders/2000014797513923/feedback', parse_url($prePost->values()[1]->url(), PHP_URL_PATH));
        $this->assertStringEndsWith('/shipments/SHIP-1', parse_url($prePost->values()[2]->url(), PHP_URL_PATH));
        $this->assertTrue($requests->skipUntil(fn (Request $request): bool => $request->method() === 'POST')->skip(1)->contains(fn (Request $request): bool => $request->method() === 'GET'));
        $this->assertDatabaseHas('meli_order_action_logs', ['meli_order_id' => $order->id, 'remote_order_id' => '2000014797513923', 'action' => 'cancel_sale', 'success' => true]);
        $this->assertDatabaseHas('meli_orders', ['id' => $order->id]);
        $this->assertStringNotContainsString('secondary-token', MeliOrderActionLog::query()->sole()->toJson());
        $this->assertFalse($requests->contains(fn (Request $request): bool => $request->method() === 'DELETE' || str_contains($request->url(), 'mercadopago') || str_contains($request->url(), '/claims')));
    }

    public function test_invalid_reason_and_frontend_payload_fields_make_zero_http_posts(): void
    {
        $order = $this->order($this->account());
        Http::fake();
        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'ARBITRARY', 'confirmed' => true])->assertSessionHasErrors('reason');
        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'OUT_OF_STOCK'])->assertSessionHasErrors('confirmed');
        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'OUT_OF_STOCK', 'confirmed' => true, 'restock_item' => true])->assertSessionHasErrors('restock_item');
        $this->assertCount(0, Http::recorded());
    }

    public function test_multi_order_pack_cancels_only_the_explicit_real_order(): void
    {
        $account = $this->account();
        $first = $this->order($account, ['order_id' => '2000010000000001', 'raw' => ['pack_id' => 'PACK-ONE']]);
        $second = $this->order($account, ['order_id' => '2000010000000002', 'raw' => ['pack_id' => 'PACK-ONE']]);
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') return Http::response(['id' => 'feedback-one'], 201);
            if (str_ends_with($path, '/feedback')) return Http::response(['sale' => null, 'purchase' => null]);
            preg_match('#/orders/([^/]+)#', $path, $match);
            return Http::response(['id' => $match[1] ?? '', 'status' => 'paid', 'tags' => []]);
        });

        $this->post(route('ams.secondary.orders.cancel', $first), ['reason' => 'BUYER_REGRETS', 'confirmed' => true])->assertSessionHas('success');

        $posts = collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertCount(1, $posts);
        $this->assertStringContainsString('/orders/2000010000000001/feedback', $posts->sole()->url());
        $this->assertStringNotContainsString('2000010000000002', $posts->sole()->url());
        $this->assertDatabaseMissing('meli_order_action_logs', ['meli_order_id' => $second->id]);
        $this->assertDatabaseHas('meli_orders', ['id' => $second->id, 'status' => 'paid']);
    }

    public function test_multi_order_pack_presentation_keeps_each_orders_items_and_total_separate(): void
    {
        $controller = new class extends AmsPedidosController
        {
            public function present(array $rows): array
            {
                $grouped = $this->computePedidosGrouped(collect($rows), true);
                return $this->pedidosToInertiaArray($grouped['pedidos']);
            }
        };
        $row = fn (int $localId, string $orderId, int $itemRowId, string $itemId, string $title, string $sku, int $quantity, float $unitPrice, float $total) => (object) [
            'id_local' => $localId, 'order_id' => $orderId, 'ml_display_id' => $orderId, 'order_status' => 'paid',
            'fecha_pedido' => now(), 'shipping_process_date' => null, 'shipping_id' => null, 'shipping_status' => null,
            'shipping_substatus' => null, 'shipping_mode' => null, 'shipping_type' => null, 'shipping_logistic_type' => null,
            'order_shipping_raw' => null, 'raw_pack_id' => 'PACK-X', 'item_row_id' => $itemRowId, 'item_id' => $itemId,
            'sku' => $sku, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'item_title' => $title, 'item_variation_text' => '',
            'sku_product_name' => null, 'sku_product_thumbnail' => null, 'sku_product_price' => null,
            'ml_product_name' => null, 'ml_product_thumbnail' => null, 'ml_product_price' => null,
            'raw_order' => json_encode(['id' => $orderId, 'pack_id' => 'PACK-X', 'total_amount' => $total, 'currency_id' => 'MXN', 'order_items' => [['item' => ['id' => $itemId, 'seller_sku' => $sku, 'title' => $title]]]], JSON_THROW_ON_ERROR),
        ];

        $pack = $controller->present([
            $row(1, '111', 10, 'MLM-A', 'Producto A', 'SKU-A', 1, 500, 500),
            $row(2, '222', 20, 'MLM-B', 'Producto B', 'SKU-B', 2, 150, 300),
        ])[0];

        $this->assertSame('PACK-X', $pack['pack_id']);
        $this->assertCount(2, $pack['orders']);
        $this->assertSame(['titulo' => 'Producto A', 'sku' => 'SKU-A', 'cantidad' => 1], $pack['orders'][0]['items'][0]);
        $this->assertSame(500.0, $pack['orders'][0]['total_amount']);
        $this->assertSame('MXN', $pack['orders'][0]['currency_id']);
        $this->assertSame(['titulo' => 'Producto B', 'sku' => 'SKU-B', 'cantidad' => 2], $pack['orders'][1]['items'][0]);
        $this->assertSame(300.0, $pack['orders'][1]['total_amount']);
        $this->assertSame('MXN', $pack['orders'][1]['currency_id']);
    }

    public function test_cancel_detail_unfulfilled_and_advanced_shipments_block_post(): void
    {
        $cases = [
            '2000000000102' => ['cancel_detail' => ['reason' => 'cancelled']],
            '2000000000103' => ['tags' => ['unfulfilled']],
            '2000000000104' => ['shipping' => ['id' => 'SHIP-SHIPPED']],
            '2000000000105' => ['shipping' => ['id' => 'SHIP-DELIVERED']],
        ];
        Http::fake(function (Request $request) use ($cases) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/feedback')) return Http::response(['sale' => null, 'purchase' => null]);
            if (str_contains($path, '/shipments/')) return Http::response(['status' => str_contains($path, 'DELIVERED') ? 'delivered' : 'shipped']);
            foreach ($cases as $id => $data) if (str_contains($path, $id)) return Http::response(['id' => $id, 'status' => 'paid', ...$data]);
            return Http::response([]);
        });
        foreach ($cases as $id => $_) {
            $order = $this->order($this->account(), ['order_id' => $id]);
            $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'BUYER_REGRETS', 'confirmed' => true])->assertSessionHas('error');
        }
        $this->assertCount(0, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_explicit_feedback_preflight_blocks_existing_sale_and_fails_closed_on_unverifiable_feedback(): void
    {
        $ids = [
            '2000000000201' => 'sale',
            '2000000000202' => 'timeout',
            '2000000000401' => 401,
            '2000000000429' => 429,
            '2000000000500' => 500,
            '2000000000203' => 'invalid',
        ];
        Http::fake(function (Request $request) use ($ids) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            preg_match('#/orders/([^/]+)#', $path, $match);
            $id = $match[1] ?? '';
            if (str_ends_with($path, '/feedback')) {
                $mode = $ids[$id] ?? null;
                if ($mode === 'sale') return Http::response(['sale' => ['id' => 123], 'purchase' => null]);
                if ($mode === 'timeout') throw new ConnectionException('feedback timeout');
                if (is_int($mode)) return Http::response([], $mode);
                return Http::response(['unexpected' => true]);
            }
            return Http::response(['id' => $id, 'status' => 'paid', 'tags' => []], $id === '2000000000201' ? 206 : 200, ['X-Content-Missing' => 'feedback']);
        });

        foreach ($ids as $id => $mode) {
            $order = $this->order($this->account(), ['order_id' => $id]);
            $response = $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'OTHER_THEIR_RESPONSIBILITY', 'confirmed' => true]);
            $mode === 'sale'
                ? $response->assertSessionHas('error', 'Esta operación ya tiene un feedback de venta registrado en Mercado Libre.')
                : $response->assertSessionHas('error', 'No fue posible verificar si esta venta ya tiene feedback. No se realizó la cancelación.');
        }

        $this->assertCount(0, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
        $this->assertSame(0, MeliOrderActionLog::query()->count());
    }

    public function test_http_errors_and_timeout_are_attempted_once_and_timeout_is_uncertain(): void
    {
        $timeoutAttempts = 0;
        Http::fake(function (Request $request) use (&$timeoutAttempts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST') {
                if (str_contains($path, '2999999999999')) { $timeoutAttempts++; throw new ConnectionException('timeout'); }
                foreach ([401, 429, 500] as $status) if (str_contains($path, (string) $status)) return Http::response([], $status);
            }
            if (str_ends_with($path, '/feedback')) return Http::response(['sale' => null, 'purchase' => null]);
            preg_match('#/orders/([^/]+)#', $path, $match);
            return Http::response(['id' => $match[1] ?? '', 'status' => 'paid', 'tags' => []]);
        });
        foreach ([401, 429, 500] as $status) {
            $order = $this->order($this->account(), ['order_id' => '200000000000'.$status]);
            $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'SELLER_REGRETS', 'confirmed' => true])->assertSessionHas('error');
            $this->assertCount(1, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), (string) $status)));
        }
        $order = $this->order($this->account(), ['order_id' => '2999999999999']);
        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'SELLER_REGRETS', 'confirmed' => true])->assertSessionHas('error');
        $this->assertSame(1, $timeoutAttempts);
        $this->assertDatabaseHas('meli_order_action_logs', ['meli_order_id' => $order->id, 'success' => null, 'error_code' => 'uncertain_delivery']);
        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'SELLER_REGRETS', 'confirmed' => true])->assertSessionHas('error');
        $this->assertSame(1, $timeoutAttempts);
    }

    public function test_refresh_failure_after_confirmed_post_keeps_successful_audit(): void
    {
        $order = $this->order($this->account(), ['order_id' => '2888888888888']);
        $posted = false;
        Http::fake(function (Request $request) use (&$posted) {
            if ($request->method() === 'POST') { $posted = true; return Http::response(['id' => 'feedback-ok'], 201); }
            if ($posted) throw new ConnectionException('refresh timeout');
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/feedback')) return Http::response(['sale' => null, 'purchase' => null]);
            return Http::response(['id' => 2888888888888, 'status' => 'paid', 'tags' => []]);
        });

        $this->post(route('ams.secondary.orders.cancel', $order), ['reason' => 'OTHER_MY_RESPONSIBILITY', 'confirmed' => true])->assertSessionHas('error');

        $this->assertDatabaseHas('meli_order_action_logs', ['meli_order_id' => $order->id, 'success' => true, 'remote_status' => 201]);
        $this->assertCount(1, collect(Http::recorded())->pluck(0)->filter(fn (Request $request): bool => $request->method() === 'POST'));
    }

    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->create(['user_id' => $this->user->id, 'access_token' => 'token', 'expires_at' => now()->addHour(), 'is_default' => false, ...$overrides]);
    }

    private function order(MeliAccount $account, array $overrides = []): MeliOrder
    {
        return MeliOrder::query()->create(['meli_account_id' => $account->id, 'order_id' => '2000011111111111', 'status' => 'paid', 'raw' => [], ...$overrides]);
    }
}
