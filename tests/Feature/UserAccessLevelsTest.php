<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireRole;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAccessLevelsTest extends TestCase
{
    private object $roleMigration;

    private bool $usersInitiallyHadRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        $this->usersInitiallyHadRole = Schema::hasColumn('users', 'role');
        $this->roleMigration = require database_path('migrations/2026_09_04_000001_add_role_to_users_table.php');
        $this->roleMigration->up();

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id');
            $table->string('meli_item_id', 64);
            $table->string('sku')->nullable();
            $table->string('title');
            $table->string('category_id', 64)->nullable();
            $table->string('listing_type_id', 64)->nullable();
            $table->string('catalog_product_id', 128)->nullable();
            $table->string('meli_brand')->nullable();
            $table->string('normalized_brand')->nullable();
            $table->unsignedBigInteger('brand_group_id')->nullable();
            $table->string('classification_status')->default('uncategorized');
            $table->string('classification_source', 64)->nullable();
            $table->decimal('classification_confidence', 5, 4)->nullable();
            $table->decimal('current_price', 15, 2);
            $table->decimal('original_price', 15, 2)->nullable();
            $table->integer('available_quantity')->nullable();
            $table->unsignedInteger('sold_quantity')->nullable();
            $table->string('currency_id', 8)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('meli_price_manager_items');
        Schema::dropIfExists('meli_accounts');
        $this->roleMigration->down();
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_users_default_to_operations_and_role_helpers_are_explicit(): void
    {
        $operations = User::query()->create([
            'name' => 'Operaciones',
            'email' => 'operations-default@example.test',
            'password' => 'password',
        ]);
        $operations->refresh();
        $admin = User::factory()->create()->forceFill(['role' => User::ROLE_ADMIN]);

        $this->assertSame(User::ROLE_OPERATIONS, $operations->role);
        $this->assertDatabaseHas('users', [
            'id' => $operations->id,
            'role' => User::ROLE_OPERATIONS,
        ]);
        $this->assertFalse($this->usersInitiallyHadRole);
        $this->assertTrue(Schema::hasColumn('users', 'role'));
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
            $this->assertRouteUsesAuthAndRole($routeName);
            $this->assertSame(200, $this->middlewareStatus($admin, $routeName), $routeName);
        }
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
        $fortifyTwoFactorRoutes = [
            'two-factor.enable',
            'two-factor.confirm',
            'two-factor.disable',
            'two-factor.qr-code',
            'two-factor.secret-key',
            'two-factor.recovery-codes',
        ];

        foreach ($allowed as $routeName) {
            $this->assertNotNull($this->routeByName($routeName), "La ruta {$routeName} debe existir en la aplicación.");
            if (! in_array($routeName, $fortifyTwoFactorRoutes, true)) {
                $this->assertRouteUsesAuthAndRole($routeName);
            }
            $this->assertTrue(UserAccess::canAccessRoute($operations, $routeName), $routeName);
            $this->assertSame(200, $this->middlewareStatus($operations, $routeName), $routeName);
        }
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
            $this->assertRouteUsesAuthAndRole($routeName);
            $this->assertFalse(UserAccess::canAccessRoute($operations, $routeName), $routeName);
            $this->assertSame(403, $this->middlewareStatus($operations, $routeName), $routeName);
        }

        $this->assertSame(403, $this->syntheticMiddlewareStatus($operations, 'future.unclassified.route'));
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
            $this->assertRouteUsesAuthAndRole($routeName);
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
        $route = $this->routeByName($routeName);
        $this->assertNotNull($route, "La ruta {$routeName} debe existir en la aplicación.");
        $request = Request::create('/'.$route->uri());
        $request->setRouteResolver(fn (): Route => $route);
        $request->setUserResolver(fn (): User => $user);

        try {
            return app(RequireRole::class)->handle($request, fn () => response('', 200))->getStatusCode();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return $exception->getStatusCode();
        }
    }

    private function syntheticMiddlewareStatus(User $user, string $routeName): int
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

    private function routeByName(string $routeName): ?Route
    {
        return app('router')->getRoutes()->getByName($routeName);
    }

    private function assertRouteUsesAuthAndRole(string $routeName): void
    {
        $route = $this->routeByName($routeName);
        $this->assertNotNull($route, "La ruta {$routeName} debe existir en la aplicación.");
        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth', $middleware, "La ruta {$routeName} debe usar auth.");
        $this->assertContains('role', $middleware, "La ruta {$routeName} debe usar role.");
    }
}
