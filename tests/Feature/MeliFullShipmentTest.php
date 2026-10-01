<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\MeliFullShipment;
use App\Models\MeliFullShipmentBox;
use App\Models\MeliFullShipmentItem;
use App\Models\MeliFullStock;
use App\Models\User;
use App\Services\MeliFullShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeliFullShipmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private InventoryLocation $location;
    private InventoryProduct $product1;
    private InventoryProduct $product2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'admin@gianini.com',
        ]);

        $this->location = InventoryLocation::create([
            'name' => 'Almacén Principal',
            'code' => 'ALM-01',
            'is_active' => true,
        ]);

        $this->product1 = InventoryProduct::create([
            'sku' => 'LLANTA-TEST-01',
            'barcode' => '7501234567890',
            'name' => 'Llanta Michelin 205/55R16',
            'brand' => 'Michelin',
            'supplier' => 'Distribuidora Llantas',
            'cost' => 1200.00,
            'price_public' => 1850.00,
            'is_active' => true,
            'product_type' => InventoryProduct::SIMPLE,
        ]);

        $this->product2 = InventoryProduct::create([
            'sku' => 'LLANTA-TEST-02',
            'barcode' => '7501234567891',
            'name' => 'Llanta Bridgestone 185/65R15',
            'brand' => 'Bridgestone',
            'supplier' => 'Distribuidora Llantas',
            'cost' => 950.00,
            'price_public' => 1450.00,
            'is_active' => true,
            'product_type' => InventoryProduct::SIMPLE,
        ]);

        // Stock inicial en almacén
        InventoryMovement::create([
            'inventory_product_id' => $this->product1->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 100,
            'occurred_at' => now(),
        ]);

        InventoryMovement::create([
            'inventory_product_id' => $this->product2->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 100,
            'occurred_at' => now(),
        ]);
    }

    public function test_can_create_shipment_with_standard_30_unit_boxes(): void
    {
        $payload = [
            'shipment_code' => 'FULL-ENV-2026-0001',
            'meli_warehouse_code' => 'MXCD01',
            'meli_warehouse_name' => 'CEDIS Cuautitlán Izcalli I',
            'meli_shipment_id' => 'MELI-998877',
            'envia_carrier' => 'Paquetexpress',
            'envia_tracking_number' => 'PE-987654321',
            'envia_cost' => 450.50,
            'notes' => 'Primer envío de prueba con cajas de 30',
            'boxes' => [
                [
                    'box_number' => 1,
                    'capacity' => 30,
                    'dimensions' => '40x30x30',
                    'weight_kg' => 15.5,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'sku' => $this->product1->sku,
                            'product_name' => $this->product1->name,
                            'quantity_sent' => 20,
                        ],
                        [
                            'inventory_product_id' => $this->product2->id,
                            'sku' => $this->product2->sku,
                            'product_name' => $this->product2->name,
                            'quantity_sent' => 10,
                        ],
                    ],
                ],
                [
                    'box_number' => 2,
                    'capacity' => 30,
                    'dimensions' => '40x30x30',
                    'weight_kg' => 18.0,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'sku' => $this->product1->sku,
                            'product_name' => $this->product1->name,
                            'quantity_sent' => 30,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->post(route('meli-full-shipments.store'), $payload);

        $response->assertRedirect();

        $this->assertDatabaseHas('meli_full_shipments', [
            'shipment_code' => 'FULL-ENV-2026-0001',
            'meli_warehouse_code' => 'MXCD01',
            'envia_carrier' => 'Paquetexpress',
            'envia_tracking_number' => 'PE-987654321',
            'total_boxes' => 2,
            'total_units' => 60,
            'status' => MeliFullShipment::STATUS_DRAFT,
        ]);

        $this->assertDatabaseHas('meli_full_shipment_boxes', [
            'box_number' => 1,
            'capacity' => 30,
            'units_count' => 30,
        ]);

        $this->assertDatabaseHas('meli_full_shipment_boxes', [
            'box_number' => 2,
            'capacity' => 30,
            'units_count' => 30,
        ]);
    }

    public function test_dispatching_shipment_records_transfer_out_movements_in_warehouse(): void
    {
        $service = app(MeliFullShipmentService::class);

        $shipment = $service->createShipment([
            'shipment_code' => 'FULL-ENV-2026-0002',
            'meli_warehouse_code' => 'MXRC01',
            'envia_carrier' => 'FedEx',
            'envia_tracking_number' => 'FX-11223344',
            'boxes' => [
                [
                    'box_number' => 1,
                    'capacity' => 30,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'quantity_sent' => 30,
                        ],
                    ],
                ],
            ],
        ], $this->user);

        // Despachar el envío
        $response = $this->actingAs($this->user)->post(route('meli-full-shipments.dispatch', $shipment->id));
        $response->assertRedirect();

        $shipment->refresh();
        $this->assertEquals(MeliFullShipment::STATUS_IN_TRANSIT, $shipment->status);
        $this->assertNotNull($shipment->shipped_at);

        // Verificar que se haya registrado el movimiento TRANSFER_OUT
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->product1->id,
            'type' => InventoryMovement::TRANSFER_OUT,
            'quantity' => -30,
            'reference_type' => 'meli_full_shipment',
            'reference_id' => $shipment->id,
        ]);
    }

    public function test_reverting_dispatch_records_transfer_in_reversal(): void
    {
        $service = app(MeliFullShipmentService::class);

        $shipment = $service->createShipment([
            'shipment_code' => 'FULL-ENV-2026-0003',
            'meli_warehouse_code' => 'MXNL01',
            'boxes' => [
                [
                    'box_number' => 1,
                    'capacity' => 30,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product2->id,
                            'quantity_sent' => 30,
                        ],
                    ],
                ],
            ],
        ], $this->user);

        // Despachar
        $service->dispatchShipment($shipment, $this->user);
        $this->assertEquals(MeliFullShipment::STATUS_IN_TRANSIT, $shipment->fresh()->status);

        // Revertir
        $response = $this->actingAs($this->user)->post(route('meli-full-shipments.revert', $shipment->id));
        $response->assertRedirect();

        $shipment->refresh();
        $this->assertEquals(MeliFullShipment::STATUS_DRAFT, $shipment->status);
        $this->assertNull($shipment->shipped_at);

        // Verificar que se haya registrado el movimiento TRANSFER_IN revertiendo
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_product_id' => $this->product2->id,
            'type' => InventoryMovement::TRANSFER_IN,
            'quantity' => 30,
            'reference_type' => 'meli_full_shipment',
            'reference_id' => $shipment->id,
        ]);
    }

    public function test_receiving_shipment_with_inspection_reports_discrepancies(): void
    {
        $service = app(MeliFullShipmentService::class);

        $shipment = $service->createShipment([
            'shipment_code' => 'FULL-ENV-2026-0004',
            'meli_warehouse_code' => 'MXJA01',
            'boxes' => [
                [
                    'box_number' => 1,
                    'capacity' => 30,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'quantity_sent' => 30,
                        ],
                    ],
                ],
            ],
        ], $this->user);

        $service->dispatchShipment($shipment, $this->user);

        $item = $shipment->items()->first();

        // MeLi recibe: 27 conformes, 2 dañadas por paquetería, 1 faltante
        $payload = [
            'receptions' => [
                [
                    'item_id' => $item->id,
                    'quantity_received' => 27,
                    'quantity_damaged' => 2,
                    'quantity_missing' => 1,
                    'notes' => '2 piezas con empaque roto',
                ],
            ],
            'notes' => 'Recepción con merma en CEDIS El Salto',
        ];

        $response = $this->actingAs($this->user)->post(route('meli-full-shipments.receive', $shipment->id), $payload);
        $response->assertRedirect();

        $shipment->refresh();
        $this->assertEquals(MeliFullShipment::STATUS_DISCREPANCY, $shipment->status);
        $this->assertEquals(27, $shipment->total_units_received);
        $this->assertEquals(2, $shipment->total_units_damaged);
        $this->assertEquals(1, $shipment->total_units_missing);
    }

    public function test_kpi_summary_calculates_in_transit_warehouse_damaged_and_review(): void
    {
        $service = app(MeliFullShipmentService::class);

        // Envío en tránsito
        $shipment = $service->createShipment([
            'shipment_code' => 'FULL-ENV-2026-0005',
            'meli_warehouse_code' => 'MXCD01',
            'boxes' => [
                [
                    'box_number' => 1,
                    'capacity' => 30,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'quantity_sent' => 30,
                        ],
                    ],
                ],
            ],
        ], $this->user);
        $service->dispatchShipment($shipment, $this->user);

        // Registro de stock FULL en bodega MeLi
        $account = \App\Models\MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => '123456789',
            'nickname' => 'GianiniStore',
            'is_default' => true,
        ]);

        MeliFullStock::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $account->id,
            'stock_key' => 'MLM12345-DEF',
            'mlm' => 'MLM12345',
            'sku' => $this->product1->sku,
            'title' => $this->product1->name,
            'full_available_quantity' => 45,
            'full_not_available_quantity' => 3,
            'not_available_detail' => [
                ['status' => 'damaged', 'quantity' => 2],
                ['status' => 'under_review', 'quantity' => 1],
            ],
        ]);

        $kpis = $service->getKpiSummary();

        $this->assertEquals(1, $kpis['in_transit']['shipments_count']);
        $this->assertEquals(30, $kpis['in_transit']['units']);
        $this->assertEquals(45, $kpis['in_warehouse']['units']);
        $this->assertGreaterThanOrEqual(2, $kpis['damaged']['total_units']);
        $this->assertGreaterThanOrEqual(1, $kpis['under_review']['total_units']);
    }

    public function test_can_render_box_labels_view(): void
    {
        $service = app(MeliFullShipmentService::class);

        $shipment = $service->createShipment([
            'shipment_code' => 'FULL-ENV-2026-0006',
            'meli_warehouse_code' => 'MXCD01',
            'envia_carrier' => 'Paquetexpress',
            'envia_tracking_number' => 'PE-555666777',
            'boxes' => [
                [
                    'box_number' => 1,
                    'capacity' => 30,
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'quantity_sent' => 30,
                        ],
                    ],
                ],
            ],
        ], $this->user);

        $response = $this->actingAs($this->user)->get(route('meli-full-shipments.labels', $shipment->id));

        $response->assertOk();
        $response->assertSee('MERCADO LIBRE FULL');
        $response->assertSee('CAJA 1 DE 1');
        $response->assertSee('MXCD01');
        $response->assertSee('PE-555666777');
        $response->assertSee('30');
    }

    public function test_forecasting_includes_meli_full_sales_and_stock(): void
    {
        $account = \App\Models\MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => '99887766',
            'nickname' => 'GianiniStore',
            'is_default' => true,
        ]);

        // Simular orden pagada con envío FULL
        $order = \App\Models\MeliOrder::create([
            'meli_account_id' => $account->id,
            'order_id' => 9876543210,
            'status' => 'paid',
            'shipping_logistic_type' => 'fulfillment',
            'created_at' => now()->subDays(5),
        ]);

        \App\Models\MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM11223344',
            'sku' => $this->product1->sku,
            'title' => $this->product1->name,
            'quantity' => 12,
            'unit_price' => 1850.00,
            'created_at' => now()->subDays(5),
        ]);

        // Simular stock disponible en CEDIS MeLi
        \App\Models\MeliFullStock::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $account->id,
            'stock_key' => 'MLM11223344-DEF',
            'mlm' => 'MLM11223344',
            'sku' => $this->product1->sku,
            'title' => $this->product1->name,
            'full_available_quantity' => 25,
            'full_not_available_quantity' => 0,
        ]);

        $forecastingService = app(\App\Services\Restock\InventoryDemandForecastingService::class);
        $forecast = $forecastingService->getProductForecast($this->product1);

        // Verificar que las ventas FULL se sumaron al cálculo de demanda
        $this->assertEquals(12, $forecast['sales_full_30d']);
        $this->assertEquals(12, $forecast['sales_30d']);
        $this->assertGreaterThan(0, $forecast['expected_daily_demand']);

        // Verificar que el stock FULL disponible se refleja
        $this->assertEquals(25, $forecast['full_available_stock']);
    }

    public function test_can_create_shipment_with_30kg_boxes_and_multi_box_in_same_bulto(): void
    {
        // Asignar peso real a productos
        $this->product1->update(['weight_kg' => 2.500]);
        $this->product2->update(['weight_kg' => 1.500]);

        $payload = [
            'shipment_code' => 'FULL-ENV-2026-0002',
            'meli_warehouse_code' => 'MXCD01',
            'meli_warehouse_name' => 'CEDIS Cuautitlán Izcalli I',
            'envia_carrier' => 'Paquetexpress',
            'envia_tracking_number' => 'PE-MULTI-001',
            'boxes' => [
                // Bulto #1 con 2 cajas de 30 kg flejadas juntas (Capacidad: 60 kg)
                [
                    'box_number' => 1,
                    'bulto_number' => 1,
                    'boxes_in_bulto' => 2,
                    'capacity_kg' => 60.00,
                    'dimensions' => '60x40x40',
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'sku' => $this->product1->sku,
                            'product_name' => $this->product1->name,
                            'quantity_sent' => 16, // 16 * 2.5kg = 40kg
                            'unit_weight_kg' => 2.500,
                        ],
                        [
                            'inventory_product_id' => $this->product2->id,
                            'sku' => $this->product2->sku,
                            'product_name' => $this->product2->name,
                            'quantity_sent' => 10, // 10 * 1.5kg = 15kg
                            'unit_weight_kg' => 1.500,
                        ],
                    ],
                ],
                // Bulto #2 con 1 caja estándar de 30 kg (Capacidad: 30 kg)
                [
                    'box_number' => 2,
                    'bulto_number' => 2,
                    'boxes_in_bulto' => 1,
                    'capacity_kg' => 30.00,
                    'dimensions' => '40x30x30',
                    'items' => [
                        [
                            'inventory_product_id' => $this->product1->id,
                            'sku' => $this->product1->sku,
                            'product_name' => $this->product1->name,
                            'quantity_sent' => 10, // 10 * 2.5kg = 25kg
                            'unit_weight_kg' => 2.500,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->post(route('meli-full-shipments.store'), $payload);
        $response->assertRedirect();

        // 2 bultos, 3 cajas de 30 kg en total (2 en Bulto 1 + 1 en Bulto 2)
        // Peso total = 40kg + 15kg + 25kg = 80kg
        // Total unidades = 16 + 10 + 10 = 36 unidades
        $this->assertDatabaseHas('meli_full_shipments', [
            'shipment_code' => 'FULL-ENV-2026-0002',
            'total_bultos' => 2,
            'total_boxes' => 3,
            'total_units' => 36,
        ]);

        $shipment = MeliFullShipment::where('shipment_code', 'FULL-ENV-2026-0002')->first();
        $this->assertEquals(80.00, (float) $shipment->total_weight_kg);

        // Verificar Bulto 1 con multi-caja
        $box1 = $shipment->boxes()->where('box_number', 1)->first();
        $this->assertEquals(1, $box1->bulto_number);
        $this->assertEquals(2, $box1->boxes_in_bulto);
        $this->assertEquals(60.00, (float) $box1->capacity_kg);
        $this->assertEquals(55.00, (float) $box1->weight_kg);

        // Verificar Bulto 2
        $box2 = $shipment->boxes()->where('box_number', 2)->first();
        $this->assertEquals(2, $box2->bulto_number);
        $this->assertEquals(1, $box2->boxes_in_bulto);
        $this->assertEquals(30.00, (float) $box2->capacity_kg);
        $this->assertEquals(25.00, (float) $box2->weight_kg);
    }

    public function test_service_provides_intelligent_restock_recommendations(): void
    {
        $account = \App\Models\MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => '88776655',
            'nickname' => 'GianiniStore2',
            'is_default' => true,
        ]);

        // Simular ventas en FULL para product1
        $order = \App\Models\MeliOrder::create([
            'meli_account_id' => $account->id,
            'order_id' => 9991112223,
            'status' => 'paid',
            'shipping_logistic_type' => 'fulfillment',
            'created_at' => now()->subDays(2),
        ]);

        \App\Models\MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM-REC-01',
            'sku' => $this->product1->sku,
            'title' => $this->product1->name,
            'quantity' => 15,
            'unit_price' => 1850.00,
            'created_at' => now()->subDays(2),
        ]);

        // Simular que el stock en CEDIS MeLi está AGOTADO (0 unidades)
        \App\Models\MeliFullStock::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $account->id,
            'stock_key' => 'MLM-REC-01-DEF',
            'mlm' => 'MLM-REC-01',
            'sku' => $this->product1->sku,
            'title' => $this->product1->name,
            'full_available_quantity' => 0,
            'full_not_available_quantity' => 0,
        ]);

        $service = app(MeliFullShipmentService::class);
        $recommendations = $service->getRestockRecommendations(20);

        $this->assertNotEmpty($recommendations);
        $rec1 = collect($recommendations)->firstWhere('sku', $this->product1->sku);

        $this->assertNotNull($rec1);
        $this->assertEquals('CRITICAL', $rec1['priority']); // Agotado en FULL con ventas
        $this->assertEquals(15, $rec1['sales_full_30d']);
        $this->assertEquals(0, $rec1['full_stock_available']);
        $this->assertGreaterThan(0, $rec1['available_stock']);
        $this->assertGreaterThan(0, $rec1['suggested_quantity']);
    }
}
