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

    public function test_can_manage_product_labeling_and_print_thermal_labels(): void
    {
        $payload = [
            'shipment_code' => 'FULL-ENV-2026-0003',
            'meli_warehouse_code' => 'MXCD01',
            'boxes' => [
                [
                    'box_number' => 1,
                    'bulto_number' => 1,
                    'boxes_in_bulto' => 1,
                    'capacity_kg' => 30.00,
                    'items' => [
                        // Producto 1 REQUIERE etiquetado individual
                        [
                            'inventory_product_id' => $this->product1->id,
                            'sku' => $this->product1->sku,
                            'product_name' => $this->product1->name,
                            'quantity_sent' => 12,
                            'requires_labeling' => true,
                            'unit_weight_kg' => 1.5,
                        ],
                        // Producto 2 NO requiere etiquetado (ya tiene código de fábrica)
                        [
                            'inventory_product_id' => $this->product2->id,
                            'sku' => $this->product2->sku,
                            'product_name' => $this->product2->name,
                            'quantity_sent' => 8,
                            'requires_labeling' => false,
                            'unit_weight_kg' => 1.2,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->post(route('meli-full-shipments.store'), $payload);
        $response->assertRedirect();

        $shipment = MeliFullShipment::where('shipment_code', 'FULL-ENV-2026-0003')->first();
        $this->assertNotNull($shipment);
        $this->assertEquals(20, $shipment->total_units);
        $this->assertEquals(12, $shipment->total_labeled_units);

        // Verificar que la vista de etiquetas de producto renderiza las 12 etiquetas
        $labelResponse = $this->actingAs($this->user)->get(route('meli-full-shipments.product-labels', $shipment->id));
        $labelResponse->assertStatus(200);
        $labelResponse->assertSee($this->product1->sku);
        $labelResponse->assertSee('Etiquetas de Producto para Mercado Libre FULL');
    }

    public function test_can_pack_box_one_by_one_and_deduct_inventory_stock_immediately(): void
    {
        $shipment = MeliFullShipment::create([
            'user_id' => $this->user->id,
            'shipment_code' => 'FULL-ENV-2026-0004',
            'status' => MeliFullShipment::STATUS_DRAFT,
            'meli_warehouse_code' => 'MXCD01',
            'meli_warehouse_name' => 'CEDIS Cuautitlán Izcalli I',
        ]);

        $initialProduct1Stock = (int) InventoryMovement::where('inventory_product_id', $this->product1->id)->sum('quantity');
        $this->assertEquals(100, $initialProduct1Stock);

        $boxPayload = [
            'box_number' => 1,
            'capacity_kg' => 30.00,
            'items' => [
                [
                    'inventory_product_id' => $this->product1->id,
                    'sku' => $this->product1->sku,
                    'product_name' => $this->product1->name,
                    'quantity_sent' => 15,
                    'unit_weight_kg' => 1.200,
                    'requires_labeling' => true,
                ],
            ],
        ];

        $response = $this->actingAs($this->user)->postJson(
            route('meli-full-shipments.store-box', $shipment->id),
            $boxPayload
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Verificar que la caja se creó correctamente
        $box = MeliFullShipmentBox::where('meli_full_shipment_id', $shipment->id)->first();
        $this->assertNotNull($box);
        $this->assertEquals(1, $box->box_number);
        $this->assertEquals(15, $box->units_count);
        $this->assertEquals(18.00, (float) $box->weight_kg);

        // Verificar que se descontaron las 15 piezas inmediatamente con un movimiento TRANSFER_OUT
        $movement = InventoryMovement::where('inventory_product_id', $this->product1->id)
            ->where('reference_type', 'meli_full_box')
            ->where('reference_id', $box->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(InventoryMovement::TRANSFER_OUT, $movement->type);
        $this->assertEquals(-15, $movement->quantity);

        $stockAfter = (int) InventoryMovement::where('inventory_product_id', $this->product1->id)->sum('quantity');
        $this->assertEquals(85, $stockAfter);
    }

    public function test_can_unpack_box_and_reintegrate_stock_to_warehouse(): void
    {
        $shipment = MeliFullShipment::create([
            'user_id' => $this->user->id,
            'shipment_code' => 'FULL-ENV-2026-0005',
            'status' => MeliFullShipment::STATUS_DRAFT,
            'meli_warehouse_code' => 'MXCD01',
        ]);

        $service = app(MeliFullShipmentService::class);
        $box = $service->packBox($shipment, [
            'box_number' => 1,
            'items' => [
                [
                    'inventory_product_id' => $this->product2->id,
                    'sku' => $this->product2->sku,
                    'product_name' => $this->product2->name,
                    'quantity_sent' => 20,
                    'unit_weight_kg' => 1.0,
                ],
            ],
        ], $this->user);

        // Stock después de empacar debe ser 80 (100 - 20)
        $stockPacked = (int) InventoryMovement::where('inventory_product_id', $this->product2->id)->sum('quantity');
        $this->assertEquals(80, $stockPacked);

        // Desempacar la caja mediante la ruta DELETE
        $response = $this->actingAs($this->user)->deleteJson(
            route('meli-full-shipments.destroy-box', [$shipment->id, $box->id])
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Verificar que la caja fue eliminada
        $this->assertDatabaseMissing('meli_full_shipment_boxes', ['id' => $box->id]);

        // Verificar que se registró el reintegro (TRANSFER_IN de +20)
        $stockReintegrated = (int) InventoryMovement::where('inventory_product_id', $this->product2->id)->sum('quantity');
        $this->assertEquals(100, $stockReintegrated);
    }

    public function test_can_link_meli_publication_to_local_product(): void
    {
        $payload = [
            'inventory_product_id' => $this->product1->id,
            'mlm' => 'MLM99887766',
            'variation_id' => '17283948',
            'meli_sku' => 'JOICO-KPAK-FULL',
        ];

        $response = $this->actingAs($this->user)->postJson(
            route('meli-full-shipments.link-product'),
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseHas('inventory_channel_links', [
            'inventory_product_id' => $this->product1->id,
            'external_listing_id' => 'MLM99887766',
            'external_variant_id' => '17283948',
        ]);
    }

    public function test_can_quick_create_inventory_product_from_pack_station(): void
    {
        $payload = [
            'name' => 'Acondicionador Joico K-Pak Color Therapy 1000ml',
            'sku' => 'JOI-KPAK-COND-1L',
            'barcode' => '074469477999',
            'brand' => 'Joico',
            'weight_kg' => 1.100,
            'initial_stock' => 25,
            'mlm' => 'MLM55443322',
        ];

        $response = $this->actingAs($this->user)->postJson(
            route('meli-full-shipments.quick-product'),
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Verificar que el producto fue creado
        $newProduct = InventoryProduct::where('sku', 'JOI-KPAK-COND-1L')->first();
        $this->assertNotNull($newProduct);
        $this->assertEquals('Joico', $newProduct->brand);

        // Verificar que se registró el stock inicial de 25
        $stock = (int) InventoryMovement::where('inventory_product_id', $newProduct->id)->sum('quantity');
        $this->assertEquals(25, $stock);

        // Verificar que quedó vinculado a MLM55443322
        $this->assertDatabaseHas('inventory_channel_links', [
            'inventory_product_id' => $newProduct->id,
            'external_listing_id' => 'MLM55443322',
        ]);
    }

    public function test_pack_station_view_renders_successfully(): void
    {
        $response = $this->actingAs($this->user)->get(route('meli-full-shipments.pack-station'));
        $response->assertStatus(200);
        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('MeliFullShipments/PackStation')
            ->has('recommendations')
            ->has('shipment')
            ->has('products')
            ->has('meliFullStocks')
        );
    }

    public function test_service_generates_pack_station_recommendations_for_out_of_stock_items(): void
    {
        $stockService = app(\App\Services\MeliFullShipmentService::class);

        $mappedStocks = collect([
            (object) [
                'id' => 101,
                'mlm' => 'MLM101',
                'variation_id' => null,
                'sku' => $this->product1->sku,
                'title' => 'Producto Agotado en FULL',
                'thumbnail' => null,
                'full_available_quantity' => 0,
                'linked_product_id' => $this->product1->id,
            ],
            (object) [
                'id' => 102,
                'mlm' => 'MLM102',
                'variation_id' => null,
                'sku' => 'UNKNOWN-SKU',
                'title' => 'Producto Agotado Sin Local',
                'thumbnail' => null,
                'full_available_quantity' => 0,
                'linked_product_id' => null,
            ],
            (object) [
                'id' => 103,
                'mlm' => 'MLM103',
                'variation_id' => null,
                'sku' => $this->product2->sku,
                'title' => 'Producto Por Agotarse',
                'thumbnail' => null,
                'full_available_quantity' => 3,
                'linked_product_id' => $this->product2->id,
            ],
            (object) [
                'id' => 104,
                'mlm' => 'MLM104',
                'variation_id' => null,
                'sku' => 'PLENTY-SKU',
                'title' => 'Producto Con Buen Stock',
                'thumbnail' => null,
                'full_available_quantity' => 45,
                'linked_product_id' => null,
            ],
        ]);

        $products = collect([$this->product1, $this->product2]);

        $recommendations = $stockService->getPackStationRecommendations($mappedStocks, $products);

        // Debería incluir los 3 en riesgo/agotados y excluir el que tiene 45 unidades
        $this->assertCount(3, $recommendations);

        // El primer elemento debe ser CRITICAL (agotado con stock local disponible)
        $this->assertEquals('CRITICAL', $recommendations[0]['priority']);
        $this->assertEquals('MLM101', $recommendations[0]['mlm']);
        $this->assertEquals(0, $recommendations[0]['full_available']);
        $this->assertGreaterThan(0, $recommendations[0]['local_stock_available']);
        $this->assertGreaterThan(0, $recommendations[0]['suggested_quantity']);

        // El segundo elemento debe ser HIGH (stock <= 5 con stock local disponible)
        $this->assertEquals('HIGH', $recommendations[1]['priority']);
        $this->assertEquals('MLM103', $recommendations[1]['mlm']);
        $this->assertEquals(3, $recommendations[1]['full_available']);

        // El tercer elemento debe ser OUT_OF_STOCK_NO_LOCAL
        $this->assertEquals('OUT_OF_STOCK_NO_LOCAL', $recommendations[2]['priority']);
        $this->assertEquals('MLM102', $recommendations[2]['mlm']);
    }
}
