<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiDeviceSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_user_can_be_authenticated_with_remember_enabled(): void
    {
        $user = User::factory()->withoutTwoFactor()->create([
            'email' => 'oscar@test.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $response = $this->post('/login', [
            'email' => 'oscar@test.com',
            'password' => 'password123',
            'remember' => true,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_logging_out_from_one_device_preserves_remember_token_for_other_devices(): void
    {
        $user = User::factory()->withoutTwoFactor()->create([
            'email' => 'oscar@test.com',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->post('/login', [
            'email' => 'oscar@test.com',
            'password' => 'password123',
            'remember' => true,
        ]);

        $originalToken = $user->fresh()->remember_token;
        $this->assertNotNull($originalToken);

        $this->post('/logout');

        $tokenAfterLogout = $user->fresh()->remember_token;
        $this->assertEquals($originalToken, $tokenAfterLogout);
    }
}
