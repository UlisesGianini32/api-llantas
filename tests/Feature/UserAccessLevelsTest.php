<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireRole;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserAccessLevelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_default_to_operations_and_role_helpers_are_explicit(): void
    {
        $operations = User::query()->create([
            'name' => 'Operaciones',
            'email' => 'operations-default@example.test',
            'password' => 'password',
        ]);
        $admin = User::factory()->create()->forceFill(['role' => User::ROLE_ADMIN]);

        $this->assertSame(User::ROLE_OPERATIONS, $operations->role);
        $this->assertTrue($operations->isOperations());
        $this->assertFalse($operations->isAdmin());
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasRole('admin'));

        $admin->fill(['role' => User::ROLE_OPERATIONS]);
        $this->assertTrue($admin->isAdmin(), 'El rol no debe poder asignarse mediante mass assignment común.');
    }

    public function test_admin_can_access_representative_routes_from_every_requested_area(): void
    {
        $admin = User::factory()->create()->forceFill(['role' => User::ROLE_ADMIN]);

        foreach (['dashboard', 'meli-price-manager.index', 'meli-price-manager.brands.index', 'system.logs.index', 'ams.pedidos.index', 'meli.claims.index'] as $routeName) {
            $this->assertSame(200, $this->middlewareStatus($admin, $routeName), $routeName);
        }

        $this->assertNamedRoutesOpenOverHttp($admin, [
            'dashboard',
            'meli-price-manager.index',
            'meli-price-manager.brands.index',
            'system.logs.index',
            'ams.pedidos.index',
            'meli.claims.index',
        ]);
    }

    public function test_operations_can_access_only_the_complete_allowed_route_groups(): void
    {
        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $allowed = [
            'dashboard',
            'dashboard.stock.zero',
            'meli.sync-manual',
            'meli.questions.index',
            'meli.questions.sync',
            'meli.questions.answer',
            'meli.messaging.index',
            'meli.messaging.messages',
            'meli.messaging.sale-details',
            'meli.messaging.reply',
            'meli.claims.index',
            'meli.claims.show',
            'meli.claims.refresh',
            'meli.claims.messages.store',
            'meli.claims.resolutions.refund',
            'meli.claims.resolutions.allow-return',
            'meli.claims.resolutions.partial-refund.offers',
            'meli.claims.resolutions.partial-refund',
            'meli.claims.attachments.download',
            'meli.publications.index',
            'meli.publications.edit',
            'meli.publications.update',
            'meli.publications.refresh',
            'meli.publications.status',
            'meli.publications.destroy',
            'meli.full.index',
            'meli.full.recommendations.export',
            'meli.full.sync',
            'meli.full.sync-one',
            'ams.pedidos.index',
            'ams.pedidos.procesar',
            'ams.pedidos.manana',
            'ams.secondary.procesar',
            'ams.secondary.sync',
            'ams.secondary.orders.cancel',
            'qz.certificate',
            'qz.sign',
            'settings.index',
            'profile.edit',
            'profile.update',
            'user-password.edit',
            'user-password.update',
            'appearance.edit',
            'two-factor.show',
            'two-factor.enable',
            'two-factor.confirm',
            'two-factor.disable',
            'two-factor.qr-code',
            'two-factor.secret-key',
            'two-factor.recovery-codes',
        ];

        foreach ($allowed as $routeName) {
            $this->assertTrue(UserAccess::canAccessRoute($operations, $routeName), $routeName);
            $this->assertSame(200, $this->middlewareStatus($operations, $routeName), $routeName);
        }


        $this->assertNamedRoutesOpenOverHttp($operations, [
            'dashboard',
            'meli.questions.index',
            'meli.messaging.index',
            'meli.claims.index',
            'meli.publications.index',
            'meli.full.index',
            'ams.pedidos.index',
            'ams.pedidos.procesar',
            'ams.secondary.procesar',
            'ams.pedidos.manana',
        ]);
    }

    public function test_operations_receive_403_for_admin_only_routes(): void
    {
        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $forbidden = [
            'meli-price-manager.index',
            'meli-price-manager.brands.index',
            'meli-price-manager.uncategorized.index',
            'producto.index',
            'llantas.index',
            'productos.index',
            'excel.vista',
            'syscom.meli.index',
            'system.health.index',
            'system.queues.index',
            'system.logs.index',
            'system.actions.index',
            'meli.redirect',
        ];

        foreach ($forbidden as $routeName) {
            $this->assertFalse(UserAccess::canAccessRoute($operations, $routeName), $routeName);
            $this->assertSame(403, $this->middlewareStatus($operations, $routeName), $routeName);
        }

        $this->assertSame(403, $this->middlewareStatus($operations, 'future.unclassified.route'));
    }

    public function test_operations_cannot_execute_price_manager_post_or_modify_data_or_call_http(): void
    {
        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        MeliPriceManagerItem::factory()->create();
        $before = MeliPriceManagerItem::query()->count();
        Http::fake();

        $this->actingAs($operations)
            ->post('/meli-price-manager/sync')
            ->assertForbidden();

        $this->assertSame($before, MeliPriceManagerItem::query()->count());
        $this->assertCount(0, Http::recorded());
    }

    public function test_operations_can_open_and_update_only_their_own_profile_without_changing_role(): void
    {
        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $other = User::factory()->create(['name' => 'Otro usuario']);

        $this->actingAs($operations)->get('/settings/profile')->assertOk();

        $this->patch('/settings/profile', [
            'name' => 'Operaciones actualizado',
            'email' => 'operations-updated@example.test',
            'role' => User::ROLE_ADMIN,
            'is_admin' => true,
            'permissions' => ['*'],
            'user_id' => $other->id,
        ])->assertRedirect();

        $operations->refresh();
        $this->assertSame('Operaciones actualizado', $operations->name);
        $this->assertSame('operations-updated@example.test', $operations->email);
        $this->assertTrue($operations->isOperations());
        $this->assertSame('Otro usuario', $other->fresh()->name);
        $this->assertTrue($other->fresh()->isAdmin());
    }

    public function test_operations_can_open_and_change_their_own_password(): void
    {
        $operations = User::factory()->create([
            'role' => User::ROLE_OPERATIONS,
            'password' => Hash::make('old-password'),
        ]);

        $this->actingAs($operations)->get('/settings/password')->assertOk();
        $this->patch('/settings/password', [
            'current_password' => 'old-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
            'role' => User::ROLE_ADMIN,
        ])->assertRedirect();

        $operations->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $operations->password));
        $this->assertTrue($operations->isOperations());
    }

    public function test_operations_can_open_personal_two_factor_and_appearance_routes(): void
    {
        $operations = User::factory()->withoutTwoFactor()->create(['role' => User::ROLE_OPERATIONS]);

        $this->actingAs($operations)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/two-factor')
            ->assertOk();

        $this->get('/settings/appearance')->assertOk();
    }

    public function test_operations_cannot_open_system_health_logs_or_queues_but_admin_can(): void
    {
        $operations = User::factory()->create(['role' => User::ROLE_OPERATIONS]);

        foreach (['/sistema/estado', '/sistema/logs', '/sistema/colas', '/sistema/acciones'] as $path) {
            $this->actingAs($operations)->get($path)->assertForbidden();
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        foreach (['system.health.index', 'system.logs.index', 'system.queues.index', 'system.actions.index'] as $routeName) {
            $this->assertSame(200, $this->middlewareStatus($admin, $routeName), $routeName);
        }
    }

    public function test_set_role_command_resolves_by_email_or_id_and_rejects_unknown_roles(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERATIONS]);

        $this->artisan('user:set-role', ['user' => $user->email, 'role' => 'admin'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->artisan('user:set-role', ['user' => (string) $user->id, 'role' => 'operations'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isOperations());

        $this->artisan('user:set-role', ['user' => (string) $user->id, 'role' => 'owner'])->assertExitCode(2);
        $this->assertTrue($user->fresh()->isOperations());
    }

    private function middlewareStatus(User $user, string $routeName): int
    {
        $request = Request::create('/_role-test');
        $route = (new Route(['GET'], '/_role-test', fn () => null))->name($routeName);
        $request->setRouteResolver(fn (): Route => $route);
        $request->setUserResolver(fn (): User => $user);

        try {
            return app(RequireRole::class)->handle($request, fn () => response('', 200))->getStatusCode();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return $exception->getStatusCode();
        }
    }

    /** @param list<string> $routeNames */
    private function assertNamedRoutesOpenOverHttp(User $user, array $routeNames): void
    {
        $this->actingAs($user);

        foreach ($routeNames as $index => $routeName) {
            $path = '/_role-access-test/'.$index;
            app('router')->get($path, fn () => response('', 200))
                ->middleware(['web', 'auth', 'role'])
                ->name($routeName);

            $this->get($path)->assertOk();
        }
    }
}
