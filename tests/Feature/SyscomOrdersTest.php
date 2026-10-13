<?php

namespace Tests\Feature;

use App\Models\MeliOrder;
use App\Models\User;
use App\Services\SyscomOrderFromMeliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyscomOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('syscom.meli.pedidos'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_syscom_orders_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('syscom.meli.pedidos'));
        $response->assertStatus(200);
    }

    public function test_user_can_manually_update_syscom_order_folio(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = MeliOrder::create([
            'order_id' => '2000018943863596',
            'status' => 'paid',
            'syscom_order_synced_at' => now(),
            'syscom_order_folio' => null,
            'syscom_order_error' => 'SYSCOM respondió pero no devolvió folio',
        ]);

        $response = $this->putJson(route('syscom.meli.pedidos.update_folio', $order), [
            'syscom_order_folio' => '576-5934968/26-API',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'syscom_order_folio' => '576-5934968/26-API',
            ]);

        $this->assertDatabaseHas('meli_orders', [
            'id' => $order->id,
            'syscom_order_folio' => '576-5934968/26-API',
            'syscom_order_error' => null,
        ]);
    }

    public function test_user_can_extract_folio_from_raw_response(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = MeliOrder::create([
            'order_id' => '2000018943863596',
            'status' => 'paid',
            'syscom_order_synced_at' => now(),
            'syscom_order_folio' => null,
            'syscom_order_raw' => [
                'resultado' => [
                    'folio_pedido' => '576-5929910/26-API',
                ],
            ],
        ]);

        $response = $this->postJson(route('syscom.meli.pedidos.extract_folio', $order));

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'syscom_order_folio' => '576-5929910/26-API',
            ]);

        $order->refresh();
        $this->assertEquals('576-5929910/26-API', $order->syscom_order_folio);
    }

    public function test_index_page_auto_repairs_missing_folio_from_raw_response(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $order = MeliOrder::create([
            'order_id' => '2000018943863596',
            'status' => 'paid',
            'syscom_order_synced_at' => now(),
            'syscom_order_folio' => null,
            'syscom_order_raw' => [
                'folio' => '576-5934968/26-API',
            ],
        ]);

        $response = $this->get(route('syscom.meli.pedidos'));
        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals('576-5934968/26-API', $order->syscom_order_folio);
    }

    public function test_extract_folio_helper_handles_various_structures(): void
    {
        // 1. Resumen
        $this->assertEquals(
            '576-1111111/26-API',
            SyscomOrderFromMeliService::extractFolioFromSyscomResponse(['resumen' => ['folio_pedido' => '576-1111111/26-API']])
        );

        // 2. Raíz folio
        $this->assertEquals(
            '576-2222222/26-API',
            SyscomOrderFromMeliService::extractFolioFromSyscomResponse(['folio' => '576-2222222/26-API'])
        );

        // 3. Raíz folio_pedido
        $this->assertEquals(
            '576-3333333/26-API',
            SyscomOrderFromMeliService::extractFolioFromSyscomResponse(['folio_pedido' => '576-3333333/26-API'])
        );

        // 4. Datos anidados
        $this->assertEquals(
            '576-4444444/26-API',
            SyscomOrderFromMeliService::extractFolioFromSyscomResponse(['datos' => ['folio' => '576-4444444/26-API']])
        );

        // 5. Regex dentro de mensaje de confirmación
        $this->assertEquals(
            '576-5555555/26-API',
            SyscomOrderFromMeliService::extractFolioFromSyscomResponse(['mensaje' => 'Pedido generado con folio 576-5555555/26-API exitosamente'])
        );

        // 6. Sin folio detectable
        $this->assertNull(
            SyscomOrderFromMeliService::extractFolioFromSyscomResponse(['status' => 'ok', 'mensaje' => 'Guardado'])
        );
    }
}

