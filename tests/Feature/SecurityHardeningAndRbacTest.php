<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SecurityHardeningAndRbacTest extends TestCase
{
    private User $admin;

    private User $operations;

    private User $posCashier;

    private User $warehouseOperator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_OPERATIONS);
            $table->string('meli_id')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Run inventory and purchase order migrations for route tests
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000001_create_pos_sales_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000002_create_pos_shifts_tables.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();

        $this->admin = User::forceCreate([
            'name' => 'Admin User',
            'email' => 'admin@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->operations = User::forceCreate([
            'name' => 'Ops User',
            'email' => 'ops@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_OPERATIONS,
        ]);

        $this->posCashier = User::forceCreate([
            'name' => 'Cashier User',
            'email' => 'cashier@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_POS,
        ]);

        $this->warehouseOperator = User::forceCreate([
            'name' => 'Warehouse Operator',
            'email' => 'warehouse@sbs.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_WAREHOUSE,
        ]);
    }

    public function test_admin_has_unrestricted_access(): void
    {
        $this->assertTrue($this->admin->isAdmin());
        $this->assertTrue(UserAccess::canAccessRoute($this->admin, 'system.health.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->admin, 'system.queues.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->admin, 'inventory.products.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->admin, 'pos.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->admin, 'purchasing.orders.index'));
    }

    public function test_operations_role_access_boundaries(): void
    {
        $this->assertTrue($this->operations->isOperations());

        // Operations CAN access daily operations routes
        $this->assertTrue(UserAccess::canAccessRoute($this->operations, 'pos.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->operations, 'restock.forecast.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->operations, 'purchasing.orders.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->operations, 'ams.pedidos.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->operations, 'meli.claims.index'));

        // Operations CANNOT access core system health/queues
        $this->assertFalse(UserAccess::canAccessRoute($this->operations, 'system.health.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->operations, 'system.queues.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->operations, 'system.actions.run'));
    }

    public function test_pos_role_access_boundaries(): void
    {
        $this->assertTrue($this->posCashier->isPos());

        // POS CAN access point of sale and cash drawer
        $this->assertTrue(UserAccess::canAccessRoute($this->posCashier, 'pos.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->posCashier, 'pos.search'));
        $this->assertTrue(UserAccess::canAccessRoute($this->posCashier, 'pos.shifts.current'));
        $this->assertTrue(UserAccess::canAccessRoute($this->posCashier, 'dashboard'));

        // POS CANNOT access warehouse catalog, purchasing, or system
        $this->assertFalse(UserAccess::canAccessRoute($this->posCashier, 'inventory.products.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->posCashier, 'purchasing.orders.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->posCashier, 'restock.forecast.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->posCashier, 'system.health.index'));
    }

    public function test_warehouse_role_access_boundaries(): void
    {
        $this->assertTrue($this->warehouseOperator->isWarehouse());

        // Warehouse CAN access physical inventory and purchasing receipts
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'inventory.products.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'inventory.locations.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'inventory.movements.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'inventory.reservations.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'purchasing.orders.index'));
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'purchasing.orders.show'));
        $this->assertTrue(UserAccess::canAccessRoute($this->warehouseOperator, 'purchasing.orders.receive'));

        // Warehouse CANNOT access system admin or channel settings
        $this->assertFalse(UserAccess::canAccessRoute($this->warehouseOperator, 'system.health.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->warehouseOperator, 'system.queues.index'));
        $this->assertFalse(UserAccess::canAccessRoute($this->warehouseOperator, 'meli.claims.index'));
    }

    public function test_token_encryption_at_rest_and_legacy_plaintext_compatibility(): void
    {
        // 1. Create with Eloquent: verify it gets encrypted at rest
        $account = MeliAccount::create([
            'user_id' => $this->admin->id,
            'meli_user_id' => '123456789',
            'nickname' => 'TIENDA_OFICIAL_SBS',
            'access_token' => 'APP_USR-secret-access-token-9988',
            'refresh_token' => 'TG-secret-refresh-token-1122',
        ]);

        // Fetch raw database values directly without Eloquent casting
        $rawRow = DB::table('meli_accounts')->where('id', $account->id)->first();

        // The raw string in database MUST be encrypted (not containing the plain token)
        $this->assertStringNotContainsString('APP_USR-secret-access-token-9988', $rawRow->access_token);
        $this->assertStringNotContainsString('TG-secret-refresh-token-1122', $rawRow->refresh_token);

        // Through Eloquent, it should decrypt smoothly to original plain token
        $account->refresh();
        $this->assertEquals('APP_USR-secret-access-token-9988', $account->access_token);
        $this->assertEquals('TG-secret-refresh-token-1122', $account->refresh_token);

        // 2. Legacy fallback test: simulate older record inserted directly as plaintext
        $legacyId = DB::table('meli_accounts')->insertGetId([
            'user_id' => $this->admin->id,
            'meli_user_id' => '987654321',
            'nickname' => 'LEGACY_TIENDA',
            'access_token' => 'APP_USR-plain-legacy-token',
            'refresh_token' => 'TG-plain-legacy-refresh',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Eloquent reading the unencrypted legacy token MUST NOT fail with DecryptException
        $legacyAccount = MeliAccount::findOrFail($legacyId);
        $this->assertEquals('APP_USR-plain-legacy-token', $legacyAccount->access_token);
        $this->assertEquals('TG-plain-legacy-refresh', $legacyAccount->refresh_token);
    }

    public function test_amazon_webhook_secret_protection(): void
    {
        config()->set('services.amazon.webhook_secret', 'ultra-secret-key-2026');

        // Without header -> 401
        $responseNoHeader = $this->postJson('/api/amazon/webhook', [
            'AmazonOrderId' => '111-222-333',
            'OrderStatus' => 'Unshipped',
        ]);
        $responseNoHeader->assertStatus(401);

        // With wrong header -> 401
        $responseWrongHeader = $this->withHeaders([
            'X-Amazon-Webhook-Secret' => 'wrong-key',
        ])->postJson('/api/amazon/webhook', [
            'AmazonOrderId' => '111-222-333',
            'OrderStatus' => 'Unshipped',
        ]);
        $responseWrongHeader->assertStatus(401);

        // With valid header -> 200
        $responseValidHeader = $this->withHeaders([
            'X-Amazon-Webhook-Secret' => 'ultra-secret-key-2026',
        ])->postJson('/api/amazon/webhook', [
            'AmazonOrderId' => '111-222-333',
            'OrderStatus' => 'Unshipped',
            'order_items' => [],
        ]);
        $responseValidHeader->assertStatus(200);
    }
}
