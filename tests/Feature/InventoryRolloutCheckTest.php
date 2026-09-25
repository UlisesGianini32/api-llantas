<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryRolloutCheckTest extends TestCase
{
    /** @var list<string> */
    private array $migrations = [
        '2026_09_24_000001_create_inventory_products_table',
        '2026_09_24_000002_create_inventory_locations_table',
        '2026_09_24_000003_add_primary_location_id_to_inventory_products_table',
        '2026_09_24_000004_create_inventory_movements_table',
        '2026_09_24_000005_create_inventory_reservations_table',
        '2026_09_24_000006_add_product_type_to_inventory_products_table',
        '2026_09_24_000007_create_inventory_kit_components_table',
        '2026_09_24_000008_create_inventory_kit_reservations_table',
        '2026_09_25_000001_create_inventory_channel_links_table',
        '2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links',
        '2026_09_25_000003_create_inventory_channel_stock_syncs_table',
        '2026_09_25_000004_add_verification_to_inventory_channel_stock_syncs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->unsignedInteger('batch');
        });
        Schema::create('inventory_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku');
        });
        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('inventory_kit_components', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id');
            $table->string('channel');
            $table->string('account_key')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->string('identity_key');
            $table->boolean('is_active')->default(true);
            $table->boolean('stock_sync_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('inventory_channel_stock_syncs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_channel_link_id');
            $table->unsignedBigInteger('inventory_product_id');
            $table->unsignedInteger('verified_quantity')->nullable();
            $table->string('verification_status')->nullable();
            $table->timestamp('verified_at')->nullable();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('migrations')->insert(array_map(
            fn (string $migration): array => ['migration' => $migration, 'batch' => 1],
            $this->migrations,
        ));
    }

    protected function tearDown(): void
    {
        foreach (['meli_accounts', 'inventory_channel_stock_syncs', 'inventory_channel_links', 'inventory_kit_reservations', 'inventory_kit_components', 'inventory_reservations', 'inventory_movements', 'inventory_locations', 'inventory_products', 'migrations'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_partial_channel_link_schema_fails_without_crashing(): void
    {
        Schema::table('inventory_channel_links', function (Blueprint $table): void {
            $table->dropColumn('stock_sync_enabled');
        });

        $this->assertSame(1, Artisan::call('inventory:rollout-check'));
        $this->assertStringContainsString('channel-link-columns', Artisan::output());
    }

    public function test_scheduler_apply_is_rejected(): void
    {
        app(Schedule::class)
            ->command('inventory:meli-stock-sync --apply')
            ->hourly();

        $this->assertSame(1, Artisan::call('inventory:rollout-check'));
        $this->assertStringContainsString('scheduler peligroso', Artisan::output());
    }

    public function test_command_is_read_only(): void
    {
        Http::fake();
        $before = $this->snapshot();
        $this->assertSame(0, Artisan::call('inventory:rollout-check'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clean_setup_passes(): void
    {
        $this->assertSame(0, Artisan::call('inventory:rollout-check'));
        $this->assertStringContainsString('PASS', Artisan::output());
    }

    public function test_enabled_links_are_highlighted(): void
    {
        $this->link('1', true);
        $this->assertSame(1, Artisan::call('inventory:rollout-check'));
        $this->assertStringContainsString('habilitado', Artisan::output());
    }

    public function test_unexpected_enabled_links_fail_safe_rollout(): void
    {
        $this->link('1', true);
        $this->assertSame(1, Artisan::call('inventory:rollout-check'));
    }

    public function test_missing_ml_account_on_enabled_link_fails(): void
    {
        $this->link('999', true);
        $this->assertSame(1, Artisan::call('inventory:rollout-check'));
    }

    public function test_disabled_invalid_link_is_warning_only(): void
    {
        $this->link('999', false);
        $this->assertSame(0, Artisan::call('inventory:rollout-check'));
        $this->assertStringContainsString('WARN', Artisan::output());
    }

    public function test_command_does_not_create_movements(): void
    {
        $before = DB::table('inventory_movements')->count();
        Artisan::call('inventory:rollout-check');
        $this->assertSame($before, DB::table('inventory_movements')->count());
    }

    public function test_command_does_not_create_reservations(): void
    {
        $before = DB::table('inventory_reservations')->count();
        Artisan::call('inventory:rollout-check');
        $this->assertSame($before, DB::table('inventory_reservations')->count());
    }

    public function test_command_does_not_modify_channel_links(): void
    {
        $link = $this->link('999', false);
        Artisan::call('inventory:rollout-check');
        $this->assertSame($link->stock_sync_enabled, $link->fresh()->stock_sync_enabled);
    }

    public function test_command_makes_no_http_requests(): void
    {
        Http::fake();
        Artisan::call('inventory:rollout-check');
        Http::assertNothingSent();
    }

    public function test_exit_status_is_zero_for_warnings(): void
    {
        $this->link('999', false);
        $this->assertSame(0, Artisan::call('inventory:rollout-check'));
    }

    public function test_verbose_output_works(): void
    {
        $this->assertSame(0, Artisan::call('inventory:rollout-check', ['--verbose' => true]));
        $this->assertStringContainsString('Detalle de conteos', Artisan::output());
    }

    private function link(string $accountKey, bool $enabled): InventoryChannelLink
    {
        return InventoryChannelLink::query()->create([
            'inventory_product_id' => 1,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => $accountKey,
            'external_listing_id' => 'MLM-ROLLOUT',
            'identity_key' => 'mercado_libre|account:'.$accountKey.'|listing:MLM-ROLLOUT',
            'is_active' => true,
            'stock_sync_enabled' => $enabled,
        ]);
    }

    private function snapshot(): array
    {
        return [
            'products' => DB::table('inventory_products')->count(),
            'locations' => DB::table('inventory_locations')->count(),
            'movements' => DB::table('inventory_movements')->count(),
            'reservations' => DB::table('inventory_reservations')->count(),
            'links' => DB::table('inventory_channel_links')->get()->toArray(),
        ];
    }
}
