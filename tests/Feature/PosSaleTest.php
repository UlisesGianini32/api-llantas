<?php

namespace Tests\Feature;

use App\Models\InventoryKitComponent;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\PosSale;
use App\Models\User;
use App\Services\Pos\PosSaleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PosSaleTest extends TestCase
{
    private User $cashier;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->string('meli_id')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Run inventory migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000001_create_pos_sales_tables.php'))->up();

        $this->cashier = User::forceCreate([
            'name' => 'Cajero Mostrador',
            'email' => 'cajero@test.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::forceCreate([
            'code' => 'MOSTRADOR',
            'name' => 'Salón / Tienda Mostrador',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_pos_index_page_loads_with_locations_and_products(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'SHAMPOO-01',
            'barcode' => '7501234567890',
            'name' => 'Shampoo Hidratante 500ml',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 250.00,
            'price_stylist' => 200.00,
            'is_active' => true,
        ]);

        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'reference_type' => 'manual',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->cashier)->get('/pos');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Index')
            ->has('locations')
            ->has('initialProducts', 1)
            ->where('initialProducts.0.sku', 'SHAMPOO-01')
            ->where('initialProducts.0.available_stock', 10)
        );
    }

    public function test_pos_search_finds_products_by_sku_barcode_and_name(): void
    {
        $prod1 = InventoryProduct::forceCreate([
            'sku' => 'TINTE-ROJO',
            'barcode' => '888123',
            'name' => 'Tinte Capilar Rojo Pasión',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 120.00,
            'is_active' => true,
        ]);

        $prod2 = InventoryProduct::forceCreate([
            'sku' => 'TINTE-AZUL',
            'barcode' => '888456',
            'name' => 'Tinte Capilar Azul Fantasía',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 130.00,
            'is_active' => true,
        ]);

        // Search by barcode
        $resBarcode = $this->actingAs($this->cashier)->getJson('/pos/search?q=888123');
        $resBarcode->assertOk();
        $this->assertCount(1, $resBarcode->json('products'));
        $this->assertSame('TINTE-ROJO', $resBarcode->json('products.0.sku'));

        // Search by partial name
        $resName = $this->actingAs($this->cashier)->getJson('/pos/search?q=Tinte');
        $resName->assertOk();
        $this->assertCount(2, $resName->json('products'));
    }

    public function test_pos_sale_creates_sale_and_deducts_physical_stock_atomically(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'CERA-01',
            'barcode' => '11223344',
            'name' => 'Cera Moldeadora Mate',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 180.00,
            'price_stylist' => 150.00,
            'is_active' => true,
        ]);

        // Initial stock = 15
        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 15,
            'reference_type' => 'manual',
            'occurred_at' => now(),
        ]);

        $payload = [
            'inventory_location_id' => $this->location->id,
            'customer_name' => 'María López',
            'customer_phone' => '6621234567',
            'customer_type' => 'public',
            'payment_method' => 'cash',
            'amount_tendered' => 500.00,
            'items' => [
                [
                    'inventory_product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 180.00,
                ],
            ],
        ];

        $response = $this->actingAs($this->cashier)->postJson('/pos/sales', $payload);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
        ]);

        $saleId = $response->json('sale.id');
        $sale = PosSale::with('items')->find($saleId);

        $this->assertNotNull($sale);
        $this->assertSame('María López', $sale->customer_name);
        $this->assertEquals(360.00, $sale->total);
        $this->assertEquals(500.00, $sale->amount_tendered);
        $this->assertEquals(140.00, $sale->change_due);
        $this->assertCount(1, $sale->items);

        // Verify inventory deduction
        $remainingStock = app(PosSaleService::class)->getDefaultLocation();
        $movements = InventoryMovement::where('inventory_product_id', $product->id)
            ->where('reference_type', 'pos_sale')
            ->get();

        $this->assertCount(1, $movements);
        $this->assertSame(-2, $movements->first()->quantity);
        $this->assertSame(InventoryMovement::SALE, $movements->first()->type);

        // Net physical stock: 15 - 2 = 13
        $this->assertSame(13, $product->physicalStock());
    }

    public function test_pos_sale_blocks_overselling_when_stock_is_reserved_by_ecommerce(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'GEL-EXTRA',
            'barcode' => '998877',
            'name' => 'Gel Fijación Extrema',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 90.00,
            'is_active' => true,
        ]);

        // Physical stock = 10
        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'reference_type' => 'initial',
            'occurred_at' => now(),
        ]);

        // 8 units reserved by Mercado Libre / Shopify active orders
        InventoryReservation::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'reference' => 'MELI-ORDER-123',
            'quantity' => 8,
            'status' => InventoryReservation::ACTIVE,
        ]);

        // Physical: 10, Reserved: 8 => Available: 2
        // Cashier tries to sell 3 at counter
        $payload = [
            'inventory_location_id' => $this->location->id,
            'customer_name' => 'Cliente Mostrador',
            'payment_method' => 'cash',
            'items' => [
                [
                    'inventory_product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 90.00,
                ],
            ],
        ];

        $response = $this->actingAs($this->cashier)->postJson('/pos/sales', $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'ok' => false,
        ]);
        $this->assertStringContainsString('Stock insuficiente', $response->json('error'));
        $this->assertStringContainsString('Disponible: 2', $response->json('error'));

        // Assert no sale or movement was recorded
        $this->assertSame(0, PosSale::count());
        $this->assertSame(10, $product->physicalStock());
    }

    public function test_pos_sale_kit_product_deducts_components(): void
    {
        $comp = InventoryProduct::forceCreate([
            'sku' => 'AMPOLLA-01',
            'name' => 'Ampolla Reparadora',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 50.00,
            'is_active' => true,
        ]);

        // Add 20 units of component
        InventoryMovement::create([
            'inventory_product_id' => $comp->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 20,
            'reference_type' => 'initial',
            'occurred_at' => now(),
        ]);

        $kit = InventoryProduct::forceCreate([
            'sku' => 'KIT-TRATAMIENTO',
            'name' => 'Kit Tratamiento Intensivo (x3 Ampollas)',
            'product_type' => InventoryProduct::KIT,
            'price_public' => 130.00,
            'is_active' => true,
        ]);

        InventoryKitComponent::create([
            'kit_product_id' => $kit->id,
            'component_product_id' => $comp->id,
            'quantity' => 3,
        ]);

        // Sell 2 kits => 2 * 3 = 6 components needed
        $payload = [
            'inventory_location_id' => $this->location->id,
            'customer_name' => 'Estilista Pro',
            'payment_method' => 'card',
            'items' => [
                [
                    'inventory_product_id' => $kit->id,
                    'quantity' => 2,
                    'unit_price' => 130.00,
                ],
            ],
        ];

        $response = $this->actingAs($this->cashier)->postJson('/pos/sales', $payload);

        $response->assertOk();
        $this->assertTrue($response->json('ok'));

        // Component physical stock: 20 - 6 = 14
        $this->assertSame(14, $comp->physicalStock());
    }

    public function test_pos_sale_cancellation_reverts_inventory_movements(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'PEINE-CARBON',
            'name' => 'Peine de Carbón Antiestático',
            'product_type' => InventoryProduct::SIMPLE,
            'price_public' => 80.00,
            'is_active' => true,
        ]);

        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 10,
            'reference_type' => 'initial',
            'occurred_at' => now(),
        ]);

        $service = app(PosSaleService::class);
        $sale = $service->createSale([
            'inventory_location_id' => $this->location->id,
            'payment_method' => 'cash',
            'items' => [
                [
                    'inventory_product_id' => $product->id,
                    'quantity' => 4,
                    'unit_price' => 80.00,
                ],
            ],
        ], $this->cashier);

        $this->assertSame(6, $product->physicalStock());

        // Cancel sale
        $response = $this->actingAs($this->cashier)->postJson("/pos/sales/{$sale->id}/cancel", [
            'reason' => 'Cliente canceló compra en mostrador',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('ok'));

        $freshSale = $sale->fresh();
        $this->assertTrue($freshSale->isCancelled());
        $this->assertSame('Cliente canceló compra en mostrador', $freshSale->cancel_reason);

        // Inventory must be restored: 6 + 4 = 10
        $this->assertSame(10, $product->physicalStock());
    }
}
