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

        $this->assertSame('syscom', $result['channel']);
        $this->assertSame(1000.0, $result['gross_sale']);
        $this->assertSame(500.0, $result['product_cost']);
        $this->assertSame(150.0, $result['meli_fee']);
        $this->assertSame(80.0, $result['shipping_fee']);
        $this->assertSame(730.0, $result['total_deductions']);
        $this->assertSame(1000.0, $result['net_sale']);
        // 1000 - 150 - 80 - 500 = 270
        $this->assertSame(270.0, $result['net_profit']);
        $this->assertSame(27.0, $result['profit_margin_pct']);
        $this->assertFalse($result['is_cancelled_or_returned']);
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

        // Resumen con comisión del 10% sobre ganancia neta (Ganancia neta = 1000 - 150 - 50 - 600 = 200)
        $summary = $service->getSummary([
            'commission_pct' => 10.0,
            'commission_base' => 'profit',
            'period' => 'hoy',
        ]);

        $this->assertSame(200.0, $summary['metrics']['total_net_profit']);
        $this->assertSame(20.0, $summary['metrics']['commission']['user_amount']);
        $this->assertSame(180.0, $summary['metrics']['commission']['company_amount']);
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

        /** @var ProfitabilityService $service */
        $service = app(ProfitabilityService::class);

        // Verificar clasificación unitaria
        $this->assertSame('syscom', $service->resolveOrderChannel($orderSyscom));
        $this->assertSame('llantas', $service->resolveOrderChannel($orderLlanta));
        $this->assertSame('otro', $service->resolveOrderChannel($orderBeauty));

        // Consultar resumen con filtro por defecto ('all' = SYSCOM + Llantas)
        $summary = $service->getSummary(['period' => 'hoy']);

        // Debe contener únicamente las 2 órdenes de SYSCOM y Llantas, excluyendo la de belleza
        $this->assertSame(2, $summary['metrics']['total_orders_count']);
        $this->assertSame(2, $summary['metrics']['channel_counts']['all']);
        $this->assertSame(1, $summary['metrics']['channel_counts']['syscom']);
        $this->assertSame(1, $summary['metrics']['channel_counts']['llantas']);
        $this->assertSame(1, $summary['metrics']['channel_counts']['otro']);

        // Las órdenes paginadas no deben incluir el producto de belleza
        $orderSkus = collect($summary['orders']->items())->pluck('sku')->all();
        $this->assertContains('SYS-CAM-01', $orderSkus);
        $this->assertContains('LL-185-65-15', $orderSkus);
        $this->assertNotContains('WELLA-71-60ML', $orderSkus);
    }
}
