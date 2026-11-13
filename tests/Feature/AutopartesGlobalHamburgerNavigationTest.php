<?php

namespace Tests\Feature;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveBranchCapability;
use App\Models\AutomotiveBranchCommercialPolicy;
use App\Models\AutomotiveBranchMembership;
use App\Models\AutomotiveInventoryMovement;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePosSale;
use App\Models\AutomotiveReceipt;
use App\Models\User;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesConnectionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AutopartesGlobalHamburgerNavigationTest extends TestCase
{
    /** @var list<string> */
    private const CORE_ONLY_MIGRATIONS = [
        '0001_01_01_000000_create_users_table.php',
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
        '2025_09_02_075243_add_two_factor_columns_to_users_table.php',
        '2026_04_09_234121_create_meli_accounts_table.php',
        '2026_09_04_000001_add_role_to_users_table.php',
        '2026_09_24_000001_create_inventory_products_table.php',
        '2026_09_24_000002_create_inventory_locations_table.php',
        '2026_09_24_000003_add_primary_location_id_to_inventory_products_table.php',
        '2026_09_24_000004_create_inventory_movements_table.php',
        '2026_09_24_000005_create_inventory_reservations_table.php',
        '2026_09_24_000006_add_product_type_to_inventory_products_table.php',
        '2026_09_24_000007_create_inventory_kit_components_table.php',
        '2026_09_24_000008_create_inventory_kit_reservations_table.php',
        '2026_09_25_000001_create_inventory_channel_links_table.php',
        '2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php',
        '2026_09_25_000003_create_inventory_channel_stock_syncs_table.php',
        '2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php',
        '2026_09_30_000001_create_pos_sales_tables.php',
        '2026_09_30_000002_create_pos_shifts_tables.php',
    ];

    /** @var list<string> */
    private const AUTOPARTES_ONLY_MIGRATIONS = [
        '2026_11_11_000001_create_automotive_branches_and_permissions_tables.php',
        '2026_11_11_000002_create_automotive_catalog_and_source_tables.php',
        '2026_11_11_000003_create_automotive_inventory_ledger_tables.php',
        '2026_11_11_000004_create_automotive_receipts_and_adjustments_tables.php',
        '2026_11_11_000005_create_automotive_pos_tables.php',
        '2026_11_12_000006_create_automotive_queue_tables.php',
    ];

    private AutomotiveBranch $hmo;

    private AutomotiveLocation $hmoLocation;

    private User $user4;

    private User $cashierUser;

    private User $coreAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpIsolatedDualDatabases();

        config()->set('autopartes.enabled', true);
        config()->set('autopartes.controllers_enabled', true);
        config()->set('autopartes.pos.sales_enabled_by_default', false);
        config()->set('autopartes.pos.fiscal_policy_validated_by_default', false);

        $this->hmo = AutomotiveBranch::where('code', 'HMO')->firstOrFail();
        $this->hmoLocation = AutomotiveLocation::where('code', 'HMO-REC-01')->firstOrFail();

        // Usuario ID 4: permisos de consulta de catálogo, inventario y borrador de recepción
        $this->user4 = User::factory()->withoutTwoFactor()->create([
            'id' => 4,
            'name' => 'Operador HMO 4',
            'email' => 'operador4@hmo.test',
            'role' => User::ROLE_BRANCH_RESTRICTED,
        ]);
        $this->grantAutomotiveCapabilities($this->user4, $this->hmo, [
            BranchCatalogAccess::CATALOG_VIEW,
            BranchCatalogAccess::STOCK_VIEW,
            BranchCatalogAccess::RECEIPT_DRAFT,
        ]);

        // Cajero con permisos POS explícitos (para validar desbloqueo dinámico cuando POS esté habilitado)
        $this->cashierUser = User::factory()->withoutTwoFactor()->create([
            'name' => 'Cajero POS HMO',
            'email' => 'cajero@hmo.test',
            'role' => User::ROLE_BRANCH_RESTRICTED,
        ]);
        $this->grantAutomotiveCapabilities($this->cashierUser, $this->hmo, [
            BranchCatalogAccess::CATALOG_VIEW,
            BranchCatalogAccess::STOCK_VIEW,
            BranchCatalogAccess::POS_VIEW,
            BranchCatalogAccess::POS_SELL,
            BranchCatalogAccess::POS_SHIFT_MANAGE,
        ]);

        // Administrador de módulos core (belleza/llantas/MeLi) sin acceso a Autopartes
        $this->coreAdmin = User::factory()->withoutTwoFactor()->create([
            'name' => 'Admin Core',
            'email' => 'core@admin.test',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDualDatabases();
        parent::tearDown();
    }

    protected function setUpIsolatedDualDatabases(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        config()->set('autopartes.database_connection', 'autopartes');
        config()->set('database.connections.autopartes', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::purge('autopartes');

        foreach (self::CORE_ONLY_MIGRATIONS as $file) {
            (require database_path('migrations/'.$file))->up();
        }

        AutopartesConnectionGuard::assertSafeForSchemaChange('autopartes');
        foreach (self::AUTOPARTES_ONLY_MIGRATIONS as $file) {
            (require database_path('migrations/autopartes/'.$file))->up();
        }
    }

    protected function tearDownIsolatedDualDatabases(): void
    {
        DB::disconnect('autopartes');
        DB::disconnect('sqlite');
        DB::purge('autopartes');
        DB::purge('sqlite');
    }

    /** @param list<string> $capabilities */
    private function grantAutomotiveCapabilities(User $user, AutomotiveBranch $branch, array $capabilities): void
    {
        $membership = AutomotiveBranchMembership::create([
            'branch_id' => $branch->getKey(),
            'user_id' => $user->getKey(),
            'is_active' => true,
        ]);

        foreach ($capabilities as $capability) {
            AutomotiveBranchCapability::create([
                'branch_user_id' => $membership->getKey(),
                'capability' => $capability,
            ]);
        }
    }

    public function test_user_id_4_navigates_catalog_inventory_and_receipts_preserving_hmo_context_and_locked_pos(): void
    {
        $receipt = AutomotiveReceipt::create([
            'branch_id' => $this->hmo->getKey(),
            'automotive_location_id' => $this->hmoLocation->getKey(),
            'folio' => 'REC-HMO-20261113-0001',
            'status' => AutomotiveReceipt::STATUS_DRAFT,
            'created_by' => $this->user4->getKey(),
        ]);

        $pages = [
            ['url' => route('autopartes.index', 'HMO', false), 'component' => 'Autopartes/Index'],
            ['url' => route('autopartes.inventory.index', 'HMO', false), 'component' => 'Autopartes/Inventory'],
            ['url' => route('autopartes.receipts.index', 'HMO', false), 'component' => 'Autopartes/Receipts'],
            ['url' => route('autopartes.receipts.show', ['branch' => 'HMO', 'receipt' => $receipt->id], false), 'component' => 'Autopartes/ReceiptShow'],
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->user4)
                ->get($page['url'])
                ->assertOk()
                ->assertInertia(fn (Assert $assert) => $assert
                    ->component($page['component'])
                    ->where('branch.code', 'HMO')
                    ->where('channels', [])
                    ->where('autopartes_nav.branch.code', 'HMO')
                    ->where('autopartes_nav.routes.catalog', '/autopartes/HMO')
                    ->where('autopartes_nav.routes.inventory', '/autopartes/HMO/inventario')
                    ->where('autopartes_nav.routes.receipts', '/autopartes/HMO/entradas')
                    ->where('autopartes_nav.routes.pos', '/autopartes/HMO/pos')
                    ->where('autopartes_nav.pos_policy.pos_sales_enabled', false)
                    ->where('autopartes_nav.pos_policy.fiscal_policy_validated', false)
                    ->where('autopartes_nav.pos_policy.ready_for_real_sales', false)
                    ->where('autopartes_nav.capabilities', function ($caps): bool {
                        $actual = collect($caps)->sort()->values()->all();
                        $expected = [
                            BranchCatalogAccess::CATALOG_VIEW,
                            BranchCatalogAccess::RECEIPT_DRAFT,
                            BranchCatalogAccess::STOCK_VIEW,
                        ];
                        sort($expected);

                        return $actual === $expected;
                    })
                );
        }
    }

    public function test_user_id_4_cannot_access_pos_movements_sales_or_confirm_receipts(): void
    {
        $receipt = AutomotiveReceipt::create([
            'branch_id' => $this->hmo->getKey(),
            'automotive_location_id' => $this->hmoLocation->getKey(),
            'folio' => 'REC-HMO-20261113-0002',
            'status' => AutomotiveReceipt::STATUS_DRAFT,
            'created_by' => $this->user4->getKey(),
        ]);

        $this->actingAs($this->user4)->get(route('autopartes.pos.index', 'HMO', false))->assertForbidden();
        $this->actingAs($this->user4)->get(route('autopartes.movements.index', 'HMO', false))->assertForbidden();
        $this->actingAs($this->user4)->get(route('autopartes.sales.index', 'HMO', false))->assertForbidden();
        $this->actingAs($this->user4)->get(route('autopartes.imports.index', 'HMO', false))->assertForbidden();

        $this->actingAs($this->user4)
            ->post(route('autopartes.receipts.confirm', ['branch' => 'HMO', 'receipt' => $receipt->id], false), [
                'confirmed' => true,
                'idempotency_key' => 'nav-deny-confirm-1',
            ])
            ->assertForbidden();

        $this->actingAs($this->user4)
            ->post(route('autopartes.pos.sales.store', 'HMO', false), [
                'items' => [],
            ])
            ->assertForbidden();

        $this->assertSame(0, AutomotiveInventoryMovement::count());
        $this->assertSame(0, AutomotivePosSale::count());
        $this->assertSame(AutomotiveReceipt::STATUS_DRAFT, $receipt->fresh()->status);
    }

    public function test_navigation_unlocks_pos_automatically_when_user_has_permission_and_policy_is_enabled(): void
    {
        $this->hmo->ensureDefaultCommercialPolicy()->update([
            'pos_sales_enabled' => true,
            'fiscal_policy_validated' => true,
        ]);

        $this->actingAs($this->cashierUser)
            ->get(route('autopartes.pos.index', 'HMO', false))
            ->assertOk()
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('Autopartes/Pos')
                ->where('branch.code', 'HMO')
                ->where('autopartes_nav.pos_policy.pos_sales_enabled', true)
                ->where('autopartes_nav.pos_policy.fiscal_policy_validated', true)
                ->where('autopartes_nav.pos_policy.ready_for_real_sales', true)
                ->where('autopartes_nav.routes.pos', '/autopartes/HMO/pos')
            );
    }

    public function test_core_modules_do_not_receive_autopartes_navigation_and_remain_isolated(): void
    {
        $this->actingAs($this->coreAdmin)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $assert) => $assert
                ->component('Settings/Profile')
                ->missing('autopartes_nav')
            );
    }
}
