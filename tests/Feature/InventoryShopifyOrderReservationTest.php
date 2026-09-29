<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelOrderAllocation;
use App\Models\InventoryKitComponent;
use App\Models\InventoryKitReservation;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryShopifyOrderReservationTest extends TestCase
{
    private InventoryLocation $location;

    private string $clientSecret = 'secret-test-789';

    private string $storeDomain = 'mrpoolhmo.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('services.shopify.store_domain', $this->storeDomain);
        config()->set('services.shopify.client_id', 'client-id-123');
        config()->set('services.shopify.client_secret', $this->clientSecret);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('status')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id', 30);
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['meli_order_id', 'item_id']);
        });
        (require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php'))->up();

        $this->location = InventoryLocation::create([
            'name' => 'Almacén Principal',
            'code' => 'ALM-1',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_channel_order_allocations');
        Schema::dropIfExists('meli_order_items');
        Schema::dropIfExists('meli_orders');
        Schema::dropIfExists('inventory_channel_stock_syncs');
        Schema::dropIfExists('inventory_channel_links');
        Schema::dropIfExists('inventory_kit_reservations');
        Schema::dropIfExists('inventory_kit_components');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function product(string $sku, string $type = InventoryProduct::SIMPLE): InventoryProduct
    {
        return InventoryProduct::create([
            'sku' => $sku,
            'name' => 'Prod '.$sku,
            'product_type' => $type,
            'primary_location_id' => $this->location->id,
            'cost' => 100,
            'is_active' => true,
        ]);
    }

    private function movement(InventoryProduct $product, int $quantity): InventoryMovement
    {
        return InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => $quantity,
            'occurred_at' => now(),
        ]);
    }

    private function link(InventoryProduct $product, string $variantId, array $attributes = []): InventoryChannelLink
    {
        return InventoryChannelLink::create(array_merge([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::SHOPIFY,
            'account_key' => $this->storeDomain,
            'external_listing_id' => '10001',
            'external_variant_id' => $variantId,
            'external_product_id' => '30001',
            'is_active' => true,
            'stock_sync_enabled' => false,
            'order_reservation_enabled' => true,
            'identity_key' => "shopify|account:{$this->storeDomain}|variant:{$variantId}",
        ], $attributes));
    }

    private function sendShopifyWebhook(string $topic, array $payload, ?string $hmac = null)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $hmac ??= base64_encode(hash_hmac('sha256', $raw, $this->clientSecret, true));

        return $this->call(
            'POST',
            '/api/shopify/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_TOPIC' => $topic,
                'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->storeDomain,
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            ],
            $raw
        );
    }

    public function test_webhook_rejects_invalid_hmac(): void
    {
        $payload = ['id' => 12345, 'line_items' => []];
        $response = $this->sendShopifyWebhook('orders/create', $payload, 'invalid-hmac-hash');

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Firma HMAC inválida.']);
    }

    public function test_webhook_reserves_stock_on_orders_create(): void
    {
        $product = $this->product('SHOPIFY-RES-1');
        $this->movement($product, 10);
        $link = $this->link($product, '555001');

        $orderPayload = [
            'id' => 99901,
            'name' => '#1001',
            'financial_status' => 'paid',
            'cancelled_at' => null,
            'line_items' => [
                [
                    'id' => 88801,
                    'product_id' => 10001,
                    'variant_id' => '555001',
                    'quantity' => 2,
                    'sku' => 'SHOPIFY-RES-1',
                ],
            ],
        ];

        $response = $this->sendShopifyWebhook('orders/create', $orderPayload);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true, 'topic' => 'orders/create']);

        // Verificar reserva creada
        $reservation = InventoryReservation::query()->where('inventory_product_id', $product->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame(2, $reservation->quantity);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);

        // Verificar registro en allocations
        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '99901')->first();
        $this->assertNotNull($allocation);
        $this->assertSame(InventoryChannelLink::SHOPIFY, $allocation->channel);
        $this->assertSame('88801', $allocation->remote_line_key);
        $this->assertSame('ACTIVE', $allocation->status);
        $this->assertSame(2, $allocation->quantity);
        $this->assertSame($reservation->id, $allocation->reservation_id);
    }

    public function test_webhook_idempotency_prevents_duplicate_reservations(): void
    {
        $product = $this->product('SHOPIFY-RES-IDEMPOTENT');
        $this->movement($product, 10);
        $this->link($product, '555002');

        $orderPayload = [
            'id' => 99902,
            'name' => '#1002',
            'financial_status' => 'paid',
            'cancelled_at' => null,
            'line_items' => [
                [
                    'id' => 88802,
                    'product_id' => 10001,
                    'variant_id' => '555002',
                    'quantity' => 3,
                    'sku' => 'SHOPIFY-RES-IDEMPOTENT',
                ],
            ],
        ];

        // Primer envío
        $response1 = $this->sendShopifyWebhook('orders/create', $orderPayload);
        $response1->assertStatus(200);

        // Segundo envío idéntico
        $response2 = $this->sendShopifyWebhook('orders/create', $orderPayload);
        $response2->assertStatus(200);

        // Debe seguir existiendo exactamente una reserva por 3 unidades
        $this->assertSame(1, InventoryReservation::query()->where('inventory_product_id', $product->id)->count());
        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '99902')->first();
        $this->assertSame(3, $allocation->quantity);
    }

    public function test_webhook_cancels_and_releases_reservations_on_orders_cancelled(): void
    {
        $product = $this->product('SHOPIFY-RES-CANCEL');
        $this->movement($product, 10);
        $this->link($product, '555003');

        $orderPayload = [
            'id' => 99903,
            'name' => '#1003',
            'financial_status' => 'paid',
            'cancelled_at' => null,
            'line_items' => [
                [
                    'id' => 88803,
                    'product_id' => 10001,
                    'variant_id' => '555003',
                    'quantity' => 4,
                    'sku' => 'SHOPIFY-RES-CANCEL',
                ],
            ],
        ];

        // 1. Crear reserva
        $this->sendShopifyWebhook('orders/create', $orderPayload)->assertStatus(200);
        $reservation = InventoryReservation::query()->where('inventory_product_id', $product->id)->first();
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);

        // 2. Cancelar orden
        $cancelPayload = array_merge($orderPayload, [
            'cancelled_at' => now()->toIso8601String(),
        ]);
        $this->sendShopifyWebhook('orders/cancelled', $cancelPayload)->assertStatus(200);

        // 3. Reserva debe quedar liberada
        $this->assertSame(InventoryReservation::RELEASED, $reservation->fresh()->status);
        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '99903')->first();
        $this->assertSame('RELEASED', $allocation->status);
        $this->assertSame(0, $allocation->quantity);
    }

    public function test_insufficient_inventory_saves_diagnostic_without_crashing(): void
    {
        $product = $this->product('SHOPIFY-OUT-OF-STOCK');
        $this->movement($product, 1); // Solo 1 en inventario
        $this->link($product, '555004');

        $orderPayload = [
            'id' => 99904,
            'name' => '#1004',
            'financial_status' => 'paid',
            'cancelled_at' => null,
            'line_items' => [
                [
                    'id' => 88804,
                    'product_id' => 10001,
                    'variant_id' => '555004',
                    'quantity' => 5, // Pide 5
                    'sku' => 'SHOPIFY-OUT-OF-STOCK',
                ],
            ],
        ];

        $response = $this->sendShopifyWebhook('orders/create', $orderPayload);
        $response->assertStatus(200);

        // No se creó reserva activa
        $this->assertEmpty(InventoryReservation::query()->where('inventory_product_id', $product->id)->get());

        // Se guardó diagnóstico en allocation
        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '99904')->first();
        $this->assertNotNull($allocation);
        $this->assertSame('DIAGNOSTIC', $allocation->status);
        $this->assertSame('INSUFFICIENT_INVENTORY', $allocation->diagnostic_code);
    }

    public function test_kit_product_order_reserves_kit_components(): void
    {
        $compA = $this->product('KIT-COMP-A');
        $compB = $this->product('KIT-COMP-B');
        $this->movement($compA, 20);
        $this->movement($compB, 10);

        $kit = $this->product('SHOPIFY-KIT-PROD', InventoryProduct::KIT);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compA->id, 'quantity' => 2]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compB->id, 'quantity' => 1]);

        $this->link($kit, '555005');

        $orderPayload = [
            'id' => 99905,
            'name' => '#1005',
            'financial_status' => 'paid',
            'cancelled_at' => null,
            'line_items' => [
                [
                    'id' => 88805,
                    'product_id' => 10001,
                    'variant_id' => '555005',
                    'quantity' => 3, // 3 kits = 6 de A y 3 de B
                    'sku' => 'SHOPIFY-KIT-PROD',
                ],
            ],
        ];

        $response = $this->sendShopifyWebhook('orders/create', $orderPayload);
        $response->assertStatus(200);

        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '99905')->first();
        $this->assertNotNull($allocation);
        $this->assertSame('KIT', $allocation->reservation_kind);
        $this->assertSame('ACTIVE', $allocation->status);

        $kitRes = InventoryKitReservation::query()->find($allocation->reservation_id);
        $this->assertNotNull($kitRes);
        $this->assertSame(3, $kitRes->quantity);
    }
}
