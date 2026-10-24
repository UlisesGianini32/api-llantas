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
        (require database_path('migrations/2026_10_07_193000_create_suppliers_and_supplier_brands_tables.php'))->up();
        (require database_path('migrations/2026_10_21_160000_create_supplier_products_table.php'))->up();

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

    public function test_can_create_order_without_explicit_location_and_routes_to_default(): void
    {
        $response = $this->actingAs($this->admin)->post('/compras/ordenes', [
            'supplier_name' => 'Proveedor Joico',
            'inventory_location_id' => null,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 150.0,
                ],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('purchase_orders', [
            'supplier_name' => 'Proveedor Joico',
            'inventory_location_id' => $this->location->id,
        ]);
    }

    public function test_receiving_items_automatically_routes_each_product_to_its_primary_catalog_location(): void
    {
        $locShelfA = InventoryLocation::create([
            'code' => 'G1-2',
            'name' => 'Estante Joico G1-2',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $locShelfB = InventoryLocation::create([
            'code' => 'I4-1',
            'name' => 'Estante Moroccanoil I4-1',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $this->productA->update(['primary_location_id' => $locShelfA->id]);
        $this->productB->update(['primary_location_id' => $locShelfB->id]);

        $order = $this->service->createOrder([
            'supplier_name' => 'Distribuidor Belleza',
            'inventory_location_id' => $this->location->id, // Respaldo general
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 12,
                    'unit_cost' => 200.0,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 6,
                    'unit_cost' => 300.0,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);

        $items = $order->fresh()->items;
        $itemA = $items->firstWhere('inventory_product_id', $this->productA->id);
        $itemB = $items->firstWhere('inventory_product_id', $this->productB->id);

        // Recepcionar sin especificar locationId (modo automático)
        $this->service->receiveItems($order, [
            [
                'item_id' => $itemA->id,
                'quantity_received' => 12,
            ],
            [
                'item_id' => $itemB->id,
                'quantity_received' => 6,
            ],
        ], $this->admin, null);

        // Verificar que producto A ingresó automáticamente al estante G1-2
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $locShelfA->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 12,
        ]);

        // Verificar que producto B ingresó automáticamente al estante I4-1
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productB->id,
            'inventory_location_id' => $locShelfB->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 6,
        ]);
    }

    public function test_prevents_duplicate_reception_when_item_already_fully_received(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Proveedor Belleza',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 6,
                    'unit_cost' => 100.0,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 12,
                    'unit_cost' => 100.0,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);
        $items = $order->fresh()->items;
        $itemA = $items->firstWhere('inventory_product_id', $this->productA->id);
        $itemB = $items->firstWhere('inventory_product_id', $this->productB->id);

        // 1ra recepción: se reciben las 6 piezas de itemA (100%) y 0 de itemB
        $this->service->receiveItems($order, [
            [
                'item_id' => $itemA->id,
                'quantity_received' => 6,
            ],
        ], $this->admin);

        $this->assertEquals(6, $itemA->fresh()->quantity_received);
        $this->assertEquals(0, $itemA->fresh()->quantityPending());
        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL, $order->fresh()->status);

        // Intento de re-recibir itemA en una orden parcial abierta debe fallar para evitar duplicados
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("ya fue recibido completamente");

        $this->service->receiveItems($order->fresh(), [
            [
                'item_id' => $itemA->id,
                'quantity_received' => 6,
            ],
        ], $this->admin);
    }

    public function test_can_close_partial_order_definitively_preventing_future_receipts(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Proveedor Belleza',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 100.0,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);
        $item = $order->fresh()->items->first();

        // Recepción parcial: solo llegaron 4 piezas
        $this->service->receiveItems($order, [
            [
                'item_id' => $item->id,
                'quantity_received' => 4,
            ],
        ], $this->admin);

        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL, $order->fresh()->status);
        $this->assertTrue($order->fresh()->canBeReceived());

        // Cierre definitivo de la orden parcial vía HTTP endpoint
        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/cerrar-parcial", [
            'reason' => 'Proveedor no cuenta con más piezas en stock.',
        ]);

        $response->assertSessionHasNoErrors();
        $freshOrder = $order->fresh();

        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL_CLOSED, $freshOrder->status);
        $this->assertTrue($freshOrder->isPartialClosed());
        $this->assertFalse($freshOrder->canBeReceived());
        $this->assertNotNull($freshOrder->received_at);
        $this->assertStringContainsString('Cierre Parcial Definitivo', $freshOrder->notes);

        // Si intentan recibirla nuevamente, el servicio debe bloquearlo
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("ya fue finalizada y cerrada como recepción parcial");

        $this->service->receiveItems($freshOrder, [
            [
                'item_id' => $item->id,
                'quantity_received' => 2,
            ],
        ], $this->admin);
    }

    public function test_can_receive_with_close_order_flag_setting_partial_closed(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Proveedor Belleza',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 12,
                    'unit_cost' => 100.0,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);
        $item = $order->fresh()->items->first();

        // Recepción con bandera close_order = true vía endpoint
        $response = $this->actingAs($this->admin)->post("/compras/ordenes/{$order->id}/recibir", [
            'close_order' => true,
            'items' => [
                [
                    'item_id' => $item->id,
                    'quantity_received' => 8,
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $freshOrder = $order->fresh();

        $this->assertEquals(PurchaseOrder::STATUS_PARTIAL_CLOSED, $freshOrder->status);
        $this->assertEquals(8, $freshOrder->total_units_received);
        $this->assertFalse($freshOrder->canBeReceived());
    }

    public function test_can_void_receipt_and_revert_physical_inventory_stock(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Proveedor Belleza',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 6,
                    'unit_cost' => 150.0,
                ],
            ],
        ], $this->admin);

        $this->service->markAsOrdered($order);
        $item = $order->fresh()->items->first();

        // Recepción original
        $receipt = $this->service->receiveItems($order, [
            [
                'item_id' => $item->id,
                'quantity_received' => 6,
            ],
        ], $this->admin);

        $this->assertEquals(6, $item->fresh()->quantity_received);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productA->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 6,
        ]);

        // Anular el comprobante vía endpoint
        $response = $this->actingAs($this->admin)->delete("/compras/ordenes/{$order->id}/recibos/{$receipt->id}", [
            'reason' => 'Recepción duplicada por error',
        ]);

        $response->assertSessionHasNoErrors();

        // Comprobante eliminado
        $this->assertDatabaseMissing('purchase_order_receipts', ['id' => $receipt->id]);

        // Unidades recibidas restadas del ítem
        $this->assertEquals(0, $item->fresh()->quantity_received);

        // Movimiento de reversión (ADJUSTMENT_OUT negativo) generado en inventario
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->productA->id,
            'type' => InventoryMovement::ADJUSTMENT_OUT,
            'quantity' => -6,
        ]);

        // Orden de compra recalculada a estado ORDERED
        $this->assertEquals(PurchaseOrder::STATUS_ORDERED, $order->fresh()->status);
        $this->assertEquals(0, $order->fresh()->total_units_received);
    }

    public function test_can_update_purchase_order_items(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Proveedor Belleza',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 5,
                    'unit_cost' => 100.0,
                ],
            ],
        ], $this->admin);

        $itemA = $order->fresh()->items->first();

        $response = $this->actingAs($this->admin)->put("/compras/ordenes/{$order->id}", [
            'notes' => 'Notas actualizadas',
            'items' => [
                [
                    'id' => $itemA->id,
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 10,
                    'unit_cost' => 110.0,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 3,
                    'unit_cost' => 200.0,
                ],
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $freshOrder = $order->fresh();

        $this->assertEquals('Notas actualizadas', $freshOrder->notes);
        $this->assertCount(2, $freshOrder->items);
        $this->assertEquals(13, $freshOrder->total_units_ordered);
        $this->assertEquals(1700.0, (float) $freshOrder->total_cost); // 10*110 + 3*200 = 1100 + 600 = 1700
    }

    public function test_can_destroy_single_item_from_draft_order(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Proveedor Belleza',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 5,
                    'unit_cost' => 100.0,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'quantity_ordered' => 2,
                    'unit_cost' => 200.0,
                ],
            ],
        ], $this->admin);

        $itemB = $order->fresh()->items->where('inventory_product_id', $this->productB->id)->first();

        $response = $this->actingAs($this->admin)->delete("/compras/ordenes/{$order->id}/partidas/{$itemB->id}");

        $response->assertSessionHasNoErrors();
        $freshOrder = $order->fresh();

        $this->assertCount(1, $freshOrder->items);
        $this->assertEquals(5, $freshOrder->total_units_ordered);
        $this->assertEquals(500.0, (float) $freshOrder->total_cost);
    }

    public function test_quick_create_product_endpoint(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/compras/ordenes/producto-rapido', [
            'sku' => '681131096034',
            'barcode' => '681131096034',
            'name' => 'Spring Valley Melatonin 5mg Gummies Strawberry 120 gomitas',
            'brand' => 'Spring Valley',
            'cost' => 180.50,
            'supplier_name' => 'Compras USA',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'product' => [
                'sku' => '681131096034',
                'name' => 'Spring Valley Melatonin 5mg Gummies Strawberry 120 gomitas',
                'brand' => 'Spring Valley',
            ],
        ]);

        $this->assertDatabaseHas('inventory_products', [
            'sku' => '681131096034',
            'brand' => 'Spring Valley',
        ]);
    }

    public function test_search_products_matches_barcode_and_normalized_code(): void
    {
        $prod = InventoryProduct::create([
            'sku' => 'SV-MELATONIN-01',
            'barcode' => '0681131096034',
            'name' => 'Spring Valley Melatonina 5mg',
            'brand' => 'Spring Valley',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
        ]);

        // Búsqueda sin el 0 inicial
        $res = $this->actingAs($this->admin)->getJson('/compras/ordenes/buscar-productos?q=681131096034');
        $res->assertOk();
        $data = $res->json('data');

        $this->assertNotEmpty($data);
        $this->assertEquals($prod->id, $data[0]['id']);
    }

    public function test_create_order_consolidates_duplicate_products_without_creating_duplicate_lines(): void
    {
        $order = $this->service->createOrder([
            'supplier_name' => 'Compras USA',
            'inventory_location_id' => $this->location->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'quantity_ordered' => 5,
                    'unit_cost' => 100.0,
                ],
                [
                    'inventory_product_id' => $this->productA->id, // DUPLICADO DEL MISMO PRODUCTO
                    'quantity_ordered' => 7,
                    'unit_cost' => 100.0,
                ],
            ],
        ], $this->admin);

        $freshOrder = $order->fresh(['items']);

        // Debe haber EXACTAMENTE 1 partida consolidada
        $this->assertCount(1, $freshOrder->items);
        $this->assertEquals(12, $freshOrder->items->first()->quantity_ordered);
        $this->assertEquals(12, $freshOrder->total_units_ordered);
    }

    public function test_purchasing_generate_wella_order_command(): void
    {
        $this->artisan('purchasing:generate-wella-order --level=balanced')
            ->assertSuccessful();

        $this->assertFileExists(base_path('database/data/pedido_wella_noviembre_diciembre_2026.xlsx'));
        $this->assertDatabaseHas('suppliers', [
            'name' => 'Beauty Art (Wella)',
        ]);
    }
}



