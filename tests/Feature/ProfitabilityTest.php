<?php

namespace Tests\Feature;

use App\Models\MeliOrder;
use App\Models\MeliOrderItem;
use App\Models\User;
use App\Services\Profitability\ProfitabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_page(): void
    {
        $response = $this->get('/rentabilidad-comisiones');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_profitability_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/rentabilidad-comisiones');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Profitability/Index'));
    }

    public function test_calculates_profitability_for_delivered_order(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Crear una orden con venta de $1000, comision ML de $150, envío de $80 y costo syscom de $500
        $order = MeliOrder::create([
            'order_id' => '200009998881',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'syscom_order_folio' => 'SYS-99881',
            'syscom_order_synced_at' => now(),
            'syscom_order_raw' => [
                'resumen' => [
                    'total' => 500.0,
                ],
            ],
            'raw' => [
                'total_amount' => 1000.0,
                'order_items' => [
                    [
                        'sale_fee' => 150.0,
                    ],
                ],
                'shipping' => [
                    'cost' => 80.0,
                ],
            ],
            'created_at' => now(),
        ]);

        MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM99881',
            'title' => 'Cámara de Seguridad SYSCOM',
            'sku' => 'DS-2CD2043G2-I',
            'quantity' => 1,
            'unit_price' => 1000.0,
        ]);

        /** @var ProfitabilityService $service */
        $service = app(ProfitabilityService::class);
        $result = $service->calculateOrderProfitability($order);

        // Impuestos retenidos ML sobre $1000.00 (base sin IVA = 862.068965):
        // ISR (2.5%) = 21.55, IVA (8.0%) = 68.97, Total impuestos = 90.52
        $this->assertSame('syscom', $result['channel']);
        $this->assertSame(1000.0, $result['gross_sale']);
        $this->assertSame(500.0, $result['product_cost']);
        $this->assertSame(150.0, $result['meli_fee']);
        $this->assertSame(80.0, $result['shipping_fee']);
        $this->assertSame(21.55, $result['isr_fee']);
        $this->assertSame(68.97, $result['iva_fee']);
        $this->assertSame(90.52, $result['taxes_fee']);
        // Total a recibir en ML: 1000 - 150 - 80 - 90.52 = 679.48
        $this->assertSame(679.48, $result['meli_net_received']);
        // Total deducciones: 500 + 150 + 80 + 90.52 = 820.52
        $this->assertSame(820.52, $result['total_deductions']);
        $this->assertSame(1000.0, $result['net_sale']);
        // Ganancia neta real: 679.48 - 500 = 179.48
        $this->assertSame(179.48, $result['net_profit']);
        $this->assertSame(17.95, $result['profit_margin_pct']);
        $this->assertFalse($result['is_cancelled_or_returned']);
    }

    public function test_calculates_exact_mercado_libre_breakdown_for_reference_order_2000015642440995(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Orden real #2000019104452344 / Venta Pack #2000015642440995 (Viakon 92-62 Cable Para Control De Acceso)
        $order = MeliOrder::create([
            'order_id' => '2000019104452344',
            'pack_id' => '2000015642440995',
            'display_id' => '2000015642440995',
            'shipping_id' => '48316981887',
            'shipping_mode' => 'me2',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'syscom_order_folio' => '576-6402844/26-API',
            'syscom_order_synced_at' => now(),
            'syscom_order_raw' => [
                'resumen' => [
                    'total' => 4491.86,
                ],
            ],
            'shipping_raw' => [
                'id' => 48316981887,
                'shipping_option' => [
                    'cost' => 0.0, // Lo que pagó el comprador (envío gratis)
                    'list_cost' => 95.0, // Costo absorbido por el vendedor en ML
                ],
            ],
            'raw' => [
                'total_amount' => 8141.27,
                'pack_id' => 2000015642440995,
                'order_items' => [
                    [
                        'sale_fee' => 1139.78,
                    ],
                ],
                'shipping' => [
                    'id' => 48316981887,
                ],
            ],
            'created_at' => now(),
        ]);

        MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM85793',
            'title' => 'Viakon 92-62 Cable Para Control De Acceso / 6x20 Awg / 152 M',
            'sku' => 'SYSCOM-85793',
            'quantity' => 1,
            'unit_price' => 8141.27,
        ]);

        /** @var ProfitabilityService $service */
        $service = app(ProfitabilityService::class);
        $result = $service->calculateOrderProfitability($order);

        $this->assertSame('syscom', $result['channel']);
        $this->assertSame('2000015642440995', $result['pack_id']);
        $this->assertSame(8141.27, $result['gross_sale']);
        $this->assertSame(1139.78, $result['meli_fee']);
        $this->assertSame(95.0, $result['shipping_fee']);
        $this->assertSame(175.46, $result['isr_fee']);
        $this->assertSame(561.47, $result['iva_fee']);
        $this->assertSame(736.93, $result['taxes_fee']);
        $this->assertSame(6169.56, $result['meli_net_received']);
        $this->assertSame(4491.86, $result['product_cost']);
        $this->assertSame(1677.70, $result['net_profit']);
        $this->assertSame(20.61, $result['profit_margin_pct']);

        // Verificar que la búsqueda por pack_id (2000015642440995) encuentra el pedido
        $summaryByPack = $service->getSummary([
            'period' => 'hoy',
            'search' => '2000015642440995',
        ]);
        $this->assertSame(1, $summaryByPack['metrics']['total_orders_count']);
        $this->assertSame(6169.56, $summaryByPack['metrics']['total_meli_net_received']);
        $this->assertSame(1677.70, $summaryByPack['metrics']['total_net_profit']);
    }

    public function test_deducts_cancelled_and_returned_orders(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Orden que fue devuelta
        $order = MeliOrder::create([
            'order_id' => '200009998882',
            'status' => 'paid',
            'shipping_status' => 'returned',
            'created_at' => now(),
            'raw' => [
                'total_amount' => 1200.0,
            ],
        ]);

        MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM99882',
            'title' => 'Llanta 205/55R16',
            'sku' => 'LLANTA-16',
            'quantity' => 1,
            'unit_price' => 1200.0,
        ]);

        /** @var ProfitabilityService $service */
        $service = app(ProfitabilityService::class);
        $result = $service->calculateOrderProfitability($order);

        $this->assertTrue($result['is_cancelled_or_returned']);
        $this->assertSame(0.0, $result['net_sale']);
        $this->assertSame(0.0, $result['net_profit']);
    }

    public function test_simulates_commission_calculation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Crear una orden efectiva
        $order = MeliOrder::create([
            'order_id' => '200009998883',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'syscom_order_folio' => 'SYS-99883',
            'syscom_order_synced_at' => now(),
            'syscom_order_raw' => [
                'resumen' => ['total' => 600.0],
            ],
            'raw' => [
                'total_amount' => 1000.0,
                'order_items' => [['sale_fee' => 150.0]],
                'shipping' => ['cost' => 50.0],
            ],
            'created_at' => now(),
        ]);

        MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM99883',
            'title' => 'Switch SYSCOM',
            'sku' => 'SW-SYSCOM',
            'quantity' => 1,
            'unit_price' => 1000.0,
        ]);

        /** @var ProfitabilityService $service */
        $service = app(ProfitabilityService::class);

        // Resumen con comisión del 10% sobre ganancia neta
        // (1000 - 150 fee - 50 envío - 90.52 impuestos - 600 costo = 109.48 ganancia neta)
        $summary = $service->getSummary([
            'commission_pct' => 10.0,
            'commission_base' => 'profit',
            'period' => 'hoy',
        ]);

        $this->assertSame(109.48, $summary['metrics']['total_net_profit']);
        $this->assertSame(10.95, $summary['metrics']['commission']['user_amount']);
        $this->assertSame(98.53, $summary['metrics']['commission']['company_amount']);
        $this->assertSame(90.0, $summary['metrics']['commission']['company_share_pct']);
    }

    public function test_can_export_csv(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = MeliOrder::create([
            'order_id' => '200009998884',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'created_at' => now(),
            'raw' => [
                'total_amount' => 500.0,
            ],
        ]);

        MeliOrderItem::create([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM99884',
            'title' => 'Llanta 205/55R16 Michelin',
            'sku' => 'LLANTA-MICH-16',
            'quantity' => 1,
            'unit_price' => 500.0,
        ]);

        $response = $this->get('/rentabilidad-comisiones/export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="rentabilidad_comisiones_', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_beauty_shop_orders_are_classified_as_other_and_excluded_by_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // 1. Orden SYSCOM
        $orderSyscom = MeliOrder::create([
            'order_id' => '200009998885',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'syscom_order_folio' => 'SYS-101',
            'syscom_order_synced_at' => now(),
            'syscom_order_raw' => ['resumen' => ['total' => 400.0]],
            'raw' => ['total_amount' => 800.0],
            'created_at' => now(),
        ]);
        MeliOrderItem::create([
            'meli_order_id' => $orderSyscom->id,
            'item_id' => 'MLM99885',
            'title' => 'Cámara de Seguridad SYSCOM',
            'sku' => 'SYS-CAM-01',
            'quantity' => 1,
            'unit_price' => 800.0,
        ]);

        // 2. Orden Llanta
        $orderLlanta = MeliOrder::create([
            'order_id' => '200009998886',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'raw' => ['total_amount' => 1200.0],
            'created_at' => now(),
        ]);
        MeliOrderItem::create([
            'meli_order_id' => $orderLlanta->id,
            'item_id' => 'MLM99886',
            'title' => 'Llanta 185/65R15 Firestone',
            'sku' => 'LL-185-65-15',
            'quantity' => 1,
            'unit_price' => 1200.0,
        ]);

        // 3. Orden Belleza / SBS (Tinte de pelo)
        $orderBeauty = MeliOrder::create([
            'order_id' => '200009998887',
            'status' => 'paid',
            'shipping_status' => 'delivered',
            'raw' => ['total_amount' => 250.0],
            'created_at' => now(),
        ]);
        MeliOrderItem::create([
            'meli_order_id' => $orderBeauty->id,
            'item_id' => 'MLM99887',
            'title' => 'Tinte Wella Koleston Perfect 60ml Tono 7/1',
            'sku' => 'WELLA-71-60ML',
            'quantity' => 1,
            'unit_price' => 250.0,
        ]);

        // 4. Orden Belleza / SBS con palabra "Neumático" en título (Cepillo Termix Raqueta Neumático)
        $orderBrush = MeliOrder::create([
            'order_id' => '2000019164959234',
            'status' => 'paid',
            'shipping_status' => 'ready_to_ship',
            'raw' => ['total_amount' => 469.0],
            'created_at' => now(),
        ]);
        MeliOrderItem::create([
            'meli_order_id' => $orderBrush->id,
            'item_id' => 'MLM99888',
            'title' => 'Cepillo Termix Raqueta Neumático Gomoso Profesional',
            'sku' => 'TXPRAQU',
            'quantity' => 1,
            'unit_price' => 469.0,
        ]);

        /** @var ProfitabilityService $service */
        $service = app(ProfitabilityService::class);

        // Verificar clasificación unitaria
        $this->assertSame('syscom', $service->resolveOrderChannel($orderSyscom));
        $this->assertSame('llantas', $service->resolveOrderChannel($orderLlanta));
        $this->assertSame('otro', $service->resolveOrderChannel($orderBeauty));
        $this->assertSame('otro', $service->resolveOrderChannel($orderBrush));

        // Consultar resumen con filtro por defecto ('all' = SYSCOM + Llantas)
        $summary = $service->getSummary(['period' => 'hoy']);

        // Debe contener únicamente las 2 órdenes de SYSCOM y Llantas, excluyendo las 2 de belleza
        $this->assertSame(2, $summary['metrics']['total_orders_count']);
        $this->assertSame(2, $summary['metrics']['channel_counts']['all']);
        $this->assertSame(1, $summary['metrics']['channel_counts']['syscom']);
        $this->assertSame(1, $summary['metrics']['channel_counts']['llantas']);
        $this->assertSame(2, $summary['metrics']['channel_counts']['otro']);

        // Las órdenes paginadas no deben incluir productos de belleza
        $orderSkus = collect($summary['orders']->items())->pluck('sku')->all();
        $this->assertContains('SYS-CAM-01', $orderSkus);
        $this->assertContains('LL-185-65-15', $orderSkus);
        $this->assertNotContains('WELLA-71-60ML', $orderSkus);
        $this->assertNotContains('TXPRAQU', $orderSkus);
    }
}
