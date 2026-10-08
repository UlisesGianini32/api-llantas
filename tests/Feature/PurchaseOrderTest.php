<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    private User $admin;

    private InventoryLocation $location;

    private InventoryProduct $productA;

    private InventoryProduct $productB;

    private PurchaseOrderService $service;

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

        // Run inventory and purchase order migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();

        $this->admin = User::forceCreate([
            'name' => 'Admin Compras',
            'email' => 'compras@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'BOD-CENTRAL',
            'name' => 'Bodega Central SBS',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->productA = InventoryProduct::create([
            'sku' => 'MICH-205-55-16',
            'name' => 'Michelin Primacy 4 205/55R16',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 1500.00,
            'price' => 2200.00,
            'brand' => 'MICHELIN',
            'supplier' => 'Michelin Mexico',
        ]);

        $this->productB = InventoryProduct::create([
            'sku' => 'CONT-195-65-15',
            'name' => 'Continental ContiPremiumContact 195/65R15',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 1200.00,
            'price' => 1800.00,
            'brand' => 'CONTINENTAL',
            'supplier' => 'Continental Tire',
        ]);

        $this->service = app(PurchaseOrderService::class);
    }

    public function test_can_create_purchase_order_in_draft(): void
    {
        $response = $this->actingAs($this->admin)->post('/compras/ordenes', [
            'supplier_name' => 'Michelin Mexico SA',
            'brand' => 'MICHELIN',
            'inventory_location_id' => $this->location->id,
            'expected_delivery_date' => Carbon::now()->addDays(30)->toDateString(),
            'supplier_quote_reference' => 'COT-2026-99',
            'shipping_cost' => 500.00,
            'tax_amount' => 1200.00,
            'notes' => 'Entrega en anden 3',
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 20,
                    'unit_cost' => 1500.00,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 1200.00,
                ],
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('purchase_orders', [
            'supplier_name' => 'Michelin Mexico SA',
            'brand' => 'MICHELIN',
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_units_ordered' => 30,
            'total_units_received' => 0,
            'shipping_cost' => 500.00,
            'tax_amount' => 1200.00,
            'subtotal' => 42000.00, // (20*1500) + (10*1200) = 30000 + 12000 = 42000
            'total_cost' => 43700.00, // 42000 + 500 + 1200
        ]);

        $this->assertDatabaseCount('purchase_order_items', 2);
    }

    public function test_can_mark_purchase_order_as_ordered(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 1500.00,
                ],
            ],
        ], $this->admin);

        $this->assertEquals(PurchaseOrder::STATUS_DRAFT, $order->status);

        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/ordenar", [
            'supplier_quote_reference' => 'REF-CONFIRM-55',
            'expected_delivery_date' => Carbon::now()->addDays(15)->toDateString(),
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_ORDERED, $order->status);
        $this->assertNotNull($order->ordered_at);
        $this->assertEquals('REF-CONFIRM-55', $order->supplier_quote_reference);
    }

    public function test_can_receive_partial_purchase_order_into_inventory_ledger(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 20,
                    'unit_cost' => 1500.00,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 1200.00,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);

        $itemA = $order->items()->where('inventory_product_id', $this->productA->id)->firstOrFail();
        $itemB = $order->items()->where('inventory_product_id', $this->productB->id)->firstOrFail();

        // Receive partial: 10 units of A, 5 units of B
        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/recibir", [
            'carrier' => 'Transportes Castores',
            'tracking_number' => 'CAS-981723',
            'notes' => 'Tarimas selladas en buen estado',
            'items' => [
                [
                    'item_id' => $itemA->id,
                    'quantity_received' => 10,
                ],
                [
                    'item_id' => $itemB->id,
                    'quantity_received' => 5,
                ],
            ],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL, $order->status);
        $this->assertEquals(15, $order->total_units_received);
        $this->assertEquals(30, $order->total_units_ordered);

        // Check receipt record
        $this->assertDatabaseHas('purchase_order_receipts', [
            'purchase_order_id' => $order->id,
            'total_units_received' => 15,
            'carrier' => 'Transportes Castores',
            'tracking_number' => 'CAS-981723',
        ]);

        // Check Inventory Movements (Ledger entries)
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'reference_type' => 'purchase_order',
            'reference_id' => $order->id,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productB->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 5,
            'reference_type' => 'purchase_order',
            'reference_id' => $order->id,
        ]);
    }

    public function test_can_receive_full_purchase_order_and_transition_to_received(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 8,
                    'unit_cost' => 1500.00,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);

        $itemA = $order->items()->firstOrFail();

        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/recibir", [
            'items' => [
                [
                    'item_id' => $itemA->id,
                    'quantity_received' => 8,
                ],
            ],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertEquals(8, $order->total_units_received);
        $this->assertNotNull($order->received_at);
    }

    public function test_cannot_cancel_purchase_order_if_already_has_received_units(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 1500.00,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);
        $item = $order->items()->firstOrFail();

        $this->service->receiveItems($order, [
            ['item_id' => $item->id, 'quantity_received' => 4],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/cancelar", [
            'reason' => 'Cancelación solicitada por gerencia',
        ]);

        $order->refresh();
        // Status remains PARTIAL, cancellation rejected
        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL, $order->status);
        $this->assertNull($order->cancelled_at);
    }

    public function test_can_cancel_draft_or_ordered_po_without_receipts(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 1500.00,
                ],
            ],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/cancelar", [
            'reason' => 'Proveedor sin stock de la medida solicitada',
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertEquals(PurchaseOrder::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertEquals('Proveedor sin stock de la medida solicitada', $order->cancel_reason);
        $this->assertEquals($this->admin->id, $order->cancelled_by);
    }

    public function test_can_receive_extra_product_not_in_original_order(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 5,
                    'unit_cost' => 1500.00,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order, 'COT-1234', '2026-10-07', '2026-10-07');
        $item = $order->items()->firstOrFail();

        // Receive the 5 of product A, PLUS 2 extra of product B (which wasn't on the PO)
        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/recibir", [
            'inventory_location_id' => $this->location->id,
            'carrier' => 'Paquetexpress',
            'tracking_number' => 'PE-999888',
            'items' => [
                ['item_id' => $item->id, 'quantity_received' => 5],
                ['product_id' => $this->productB->id, 'quantity_received' => 2],
            ],
        ]);

        $response->assertRedirect();
        $order->refresh();

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(7, (int) $order->total_units_received);
        $this->assertSame(7, (int) $order->total_units_ordered);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);

        // Verify movement ledger for both products
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productA->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 5,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productB->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 2,
        ]);
    }

    public function test_can_search_products_for_purchase_orders(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/compras/ordenes/buscar-productos?q=Michelin');
        $response->assertOk();
        $response->assertJsonFragment(['sku' => 'MICH-205-55-16']);
    }

    public function test_can_view_purchase_order_pdf_view(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Michelin Mexico SA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 5,
                    'unit_cost' => 1500.00,
                ],
            ],
        ], $this->admin);

        $response = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}/pdf");
        $response->assertOk();
        $response->assertSee('T.O. THE BEAUTY SHOP');
        $response->assertSee($order->order_number);
        $response->assertSee('Michelin Mexico SA');

        // Public route for suppliers
        $publicResponse = $this->get("/orden-compra/{$order->id}/pdf");
        $publicResponse->assertOk();
        $publicResponse->assertSee('T.O. THE BEAUTY SHOP');
    }
}
