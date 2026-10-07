<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_new_user_with_command(): void
    {
        $this->artisan('user:create', [
            'email' => 'oscarjn2802@gmail.com',
            '--name' => 'Oscar Juarez',
            '--role' => 'admin',
            '--password' => 'SBS2026*Oscar',
        ])
            ->expectsOutputToContain('Usuario creado exitosamente: oscarjn2802@gmail.com')
            ->assertSuccessful();

        $user = User::query()->where('email', 'oscarjn2802@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Oscar Juarez', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('SBS2026*Oscar', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_can_update_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'fernandorascon92@gmail.com',
            'name' => 'Fernando R',
            'role' => 'operations',
        ]);

        $this->artisan('user:create', [
            'email' => 'fernandorascon92@gmail.com',
            '--name' => 'Fernando Rascon',
            '--role' => 'admin',
            '--password' => 'SBS2026*Fernando',
        ])
            ->expectsOutputToContain('Usuario existente actualizado: fernandorascon92@gmail.com')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame('Fernando Rascon', $user->name);
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('SBS2026*Fernando', $user->password));
    }

    public function test_rejects_invalid_role(): void
    {
        $this->artisan('user:create', [
            'email' => 'test@example.com',
            '--role' => 'invalid_role',
        ])
            ->expectsOutputToContain('Rol inválido')
            ->assertExitCode(2);
    }
}
