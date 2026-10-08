<?php

namespace Tests\Feature;

use App\Models\User;
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
}
