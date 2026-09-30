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
use App\Services\Amazon\InventoryAmazonOrderReservationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryAmazonOrderReservationTest extends TestCase
{
    private InventoryLocation $location;

    private string $sellerId = 'AMZ_SELLER_TEST';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('services.amazon.seller_id', $this->sellerId);
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

    private function link(InventoryProduct $product, string $sellerSku, array $attributes = []): InventoryChannelLink
    {
        return InventoryChannelLink::create(array_merge([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::AMAZON,
            'account_key' => $this->sellerId,
            'external_listing_id' => $sellerSku,
            'external_product_id' => 'B08SAMPLE',
            'is_active' => true,
            'stock_sync_enabled' => false,
            'order_reservation_enabled' => true,
            'identity_key' => "amazon|account:{$this->sellerId}|listing:{$sellerSku}",
        ], $attributes));
    }

    public function test_fba_orders_are_skipped_and_isolate_warehouse_inventory(): void
    {
        $product = $this->product('AMZ-FBA-SKU');
        $this->movement($product, 10);
        $this->link($product, 'AMZ-FBA-SKU');

        $orderPayload = [
            'AmazonOrderId' => '111-1234567-0000001',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'AFN', // AFN = Amazon Fulfilled Network (FBA)
            'OrderItems' => [
                [
                    'OrderItemId' => '9990001',
                    'SellerSKU' => 'AMZ-FBA-SKU',
                    'QuantityOrdered' => 3,
                ],
            ],
        ];

        $service = app(InventoryAmazonOrderReservationService::class);
        $results = $service->processOrderPayload($orderPayload);

        $this->assertSame(InventoryAmazonOrderReservationService::SKIPPED_FBA, $results[0]['action']);
        // No debe haberse creado ninguna reserva en el almacén local
        $this->assertSame(0, InventoryReservation::query()->where('inventory_product_id', $product->id)->count());
    }

    public function test_fbm_orders_reserve_stock_and_record_allocation(): void
    {
        $product = $this->product('AMZ-FBM-SKU');
        $this->movement($product, 15);
        $this->link($product, 'AMZ-FBM-SKU');

        $orderPayload = [
            'AmazonOrderId' => '111-1234567-0000002',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'MFN', // MFN = Merchant Fulfilled Network (FBM)
            'OrderItems' => [
                [
                    'OrderItemId' => '9990002',
                    'SellerSKU' => 'AMZ-FBM-SKU',
                    'QuantityOrdered' => 2,
                ],
            ],
        ];

        $service = app(InventoryAmazonOrderReservationService::class);
        $results = $service->processOrderPayload($orderPayload);

        $this->assertSame(InventoryAmazonOrderReservationService::RESERVE, $results[0]['action']);
        $this->assertTrue($results[0]['applied']);

        // Verificar reserva activa
        $reservation = InventoryReservation::query()->where('inventory_product_id', $product->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame(2, $reservation->quantity);
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);

        // Verificar asignación
        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '111-1234567-0000002')->first();
        $this->assertNotNull($allocation);
        $this->assertSame(InventoryChannelLink::AMAZON, $allocation->channel);
        $this->assertSame('9990002', $allocation->remote_line_key);
        $this->assertSame('ACTIVE', $allocation->status);
        $this->assertSame(2, $allocation->quantity);
    }

    public function test_idempotency_prevents_duplicate_reservations(): void
    {
        $product = $this->product('AMZ-IDEM-SKU');
        $this->movement($product, 10);
        $this->link($product, 'AMZ-IDEM-SKU');

        $orderPayload = [
            'AmazonOrderId' => '111-1234567-0000003',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'MFN',
            'OrderItems' => [
                [
                    'OrderItemId' => '9990003',
                    'SellerSKU' => 'AMZ-IDEM-SKU',
                    'QuantityOrdered' => 4,
                ],
            ],
        ];

        $service = app(InventoryAmazonOrderReservationService::class);
        $res1 = $service->processOrderPayload($orderPayload);
        $this->assertSame(InventoryAmazonOrderReservationService::RESERVE, $res1[0]['action']);

        // Segundo intento idéntico
        $res2 = $service->processOrderPayload($orderPayload);
        $this->assertSame('NO_CHANGE', $res2[0]['action']);

        // Cantidad de reservas sigue siendo 1 por 4 unidades
        $this->assertSame(1, InventoryReservation::query()->where('inventory_product_id', $product->id)->count());
        $this->assertSame(4, InventoryReservation::query()->where('inventory_product_id', $product->id)->first()->quantity);
    }

    public function test_canceled_orders_release_reservation(): void
    {
        $product = $this->product('AMZ-CANCEL-SKU');
        $this->movement($product, 10);
        $this->link($product, 'AMZ-CANCEL-SKU');

        $orderPayload = [
            'AmazonOrderId' => '111-1234567-0000004',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'MFN',
            'OrderItems' => [
                [
                    'OrderItemId' => '9990004',
                    'SellerSKU' => 'AMZ-CANCEL-SKU',
                    'QuantityOrdered' => 3,
                ],
            ],
        ];

        $service = app(InventoryAmazonOrderReservationService::class);
        $service->processOrderPayload($orderPayload);

        $reservation = InventoryReservation::query()->where('inventory_product_id', $product->id)->first();
        $this->assertSame(InventoryReservation::ACTIVE, $reservation->status);

        // Cancelar orden
        $cancelPayload = array_merge($orderPayload, ['OrderStatus' => 'Canceled']);
        $results = $service->cancelOrderPayload($cancelPayload);

        $this->assertSame(InventoryAmazonOrderReservationService::RELEASE, $results[0]['action']);
        $this->assertSame(InventoryReservation::RELEASED, $reservation->fresh()->status);

        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '111-1234567-0000004')->first();
        $this->assertSame('RELEASED', $allocation->status);
        $this->assertSame(0, $allocation->quantity);
    }

    public function test_insufficient_stock_records_diagnostic(): void
    {
        $product = $this->product('AMZ-OOS-SKU');
        $this->movement($product, 1); // Solo 1 disponible
        $this->link($product, 'AMZ-OOS-SKU');

        $orderPayload = [
            'AmazonOrderId' => '111-1234567-0000005',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'MFN',
            'OrderItems' => [
                [
                    'OrderItemId' => '9990005',
                    'SellerSKU' => 'AMZ-OOS-SKU',
                    'QuantityOrdered' => 5, // Pide 5
                ],
            ],
        ];

        $service = app(InventoryAmazonOrderReservationService::class);
        $results = $service->processOrderPayload($orderPayload);

        $this->assertSame('INSUFFICIENT_INVENTORY', $results[0]['action']);

        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '111-1234567-0000005')->first();
        $this->assertNotNull($allocation);
        $this->assertSame('DIAGNOSTIC', $allocation->status);
        $this->assertSame('INSUFFICIENT_INVENTORY', $allocation->diagnostic_code);
    }

    public function test_kit_order_reserves_kit_components(): void
    {
        $compA = $this->product('AMZ-KIT-A');
        $compB = $this->product('AMZ-KIT-B');
        $this->movement($compA, 10);
        $this->movement($compB, 10);

        $kit = $this->product('AMZ-KIT-PRODUCT', InventoryProduct::KIT);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compA->id, 'quantity' => 2]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compB->id, 'quantity' => 1]);

        $this->link($kit, 'AMZ-KIT-SKU');

        $orderPayload = [
            'AmazonOrderId' => '111-1234567-0000006',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'MFN',
            'OrderItems' => [
                [
                    'OrderItemId' => '9990006',
                    'SellerSKU' => 'AMZ-KIT-SKU',
                    'QuantityOrdered' => 2, // 2 kits = 4 de A y 2 de B
                ],
            ],
        ];

        $service = app(InventoryAmazonOrderReservationService::class);
        $results = $service->processOrderPayload($orderPayload);

        $this->assertSame(InventoryAmazonOrderReservationService::RESERVE, $results[0]['action']);

        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '111-1234567-0000006')->first();
        $this->assertSame('KIT', $allocation->reservation_kind);
        $this->assertSame('ACTIVE', $allocation->status);

        $kitRes = InventoryKitReservation::query()->find($allocation->reservation_id);
        $this->assertNotNull($kitRes);
        $this->assertSame(2, $kitRes->quantity);
    }

    public function test_webhook_endpoint_processes_payload(): void
    {
        $product = $this->product('AMZ-WEBHOOK-SKU');
        $this->movement($product, 10);
        $this->link($product, 'AMZ-WEBHOOK-SKU');

        $payload = [
            'AmazonOrderId' => '111-1234567-0000007',
            'OrderStatus' => 'Unshipped',
            'FulfillmentChannel' => 'MFN',
            'OrderItems' => [
                [
                    'OrderItemId' => '9990007',
                    'SellerSKU' => 'AMZ-WEBHOOK-SKU',
                    'QuantityOrdered' => 1,
                ],
            ],
        ];

        $response = $this->postJson('/api/amazon/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['ok' => true, 'order_id' => '111-1234567-0000007']);

        $allocation = InventoryChannelOrderAllocation::query()->where('remote_order_id', '111-1234567-0000007')->first();
        $this->assertNotNull($allocation);
        $this->assertSame('ACTIVE', $allocation->status);
    }
}
