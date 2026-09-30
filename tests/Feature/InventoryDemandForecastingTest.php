<?php

namespace Tests\Feature;

use App\Models\BrandRestockConfiguration;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Restock\InventoryDemandForecastingService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryDemandForecastingTest extends TestCase
{
    private User $admin;

    private InventoryLocation $location;

    private InventoryDemandForecastingService $service;

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
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Run inventory and restock migrations
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

        $this->admin = User::forceCreate([
            'name' => 'Ulises Admin',
            'email' => 'admin@barbersupply.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::forceCreate([
            'code' => 'ALMACEN_CENTRAL',
            'name' => 'Almacén Central',
            'is_active' => true,
        ]);

        $this->service = app(InventoryDemandForecastingService::class);
    }

    public function test_weighted_velocity_calculation_from_historical_sales(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'WAHL-MAGIC-01',
            'name' => 'Wahl Magic Clip',
            'product_type' => 'SIMPLE',
            'cost' => 1200.00,
            'is_active' => true,
        ]);

        $now = Carbon::parse('2026-10-01 12:00:00');

        // Initial inventory
        InventoryMovement::forceCreate([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 100,
            'occurred_at' => $now->copy()->subDays(200),
        ]);

        // Ventas:
        // 30 uds en los últimos 30 días (Tasa = 30 / 30 = 1.0 ud/día)
        InventoryMovement::forceCreate([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -30,
            'occurred_at' => $now->copy()->subDays(15),
        ]);

        // 30 uds adicionales entre día 31 y día 90 (Total 90 días = 60 uds, Tasa = 60 / 90 = 0.6667 ud/día)
        InventoryMovement::forceCreate([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -30,
            'occurred_at' => $now->copy()->subDays(50),
        ]);

        // 30 uds adicionales entre día 91 y día 180 (Total 180 días = 90 uds, Tasa = 90 / 180 = 0.5 ud/día)
        InventoryMovement::forceCreate([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -30,
            'occurred_at' => $now->copy()->subDays(120),
        ]);

        $forecast = $this->service->getProductForecast($product, ['as_of' => $now]);

        $this->assertEquals(30, $forecast['sales_30d']);
        $this->assertEquals(60, $forecast['sales_90d']);
        $this->assertEquals(90, $forecast['sales_180d']);

        // Velocidad esperada: (1.0 * 0.5) + ((60/90) * 0.3) + ((90/180) * 0.2) = 0.5 + 0.2 + 0.1 = 0.8
        $this->assertEquals(0.8, $forecast['daily_velocity']);
    }

    public function test_import_long_term_cadence_90_to_120_days(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'BABYLISS-GOLD-FX',
            'name' => 'BaBylissPRO GoldFX Trimmer',
            'brand' => 'BaBylissPRO',
            'supplier' => 'BaByliss USA Import',
            'product_type' => 'SIMPLE',
            'cost' => 2500.00,
            'is_active' => true,
        ]);

        $now = Carbon::parse('2026-10-01 12:00:00');

        // Configuración de marca para Importación / Largo Plazo (90 - 120 días)
        BrandRestockConfiguration::create([
            'brand' => 'BaBylissPRO',
            'supplier' => 'BaByliss USA Import',
            'cadence_preset' => BrandRestockConfiguration::PRESET_IMPORT_90_120,
            'lead_time_days' => 30, // 30 días de flete marítimo y aduana
            'target_coverage_days' => 120, // 120 días de demanda (4 meses)
            'safety_stock_days' => 20, // 20 días de colchón para contingencias aduanales
        ]);

        // Simular stock físico actual de 50 piezas
        InventoryMovement::forceCreate([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 110,
            'occurred_at' => $now->copy()->subDays(100),
        ]);

        // 60 piezas vendidas en los últimos 30 días (2 unidades al día)
        InventoryMovement::forceCreate([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::SALE,
            'quantity' => -60,
            'occurred_at' => $now->copy()->subDays(10),
        ]);

        // Stock disponible actual: 110 - 60 = 50 unidades
        // Demanda diaria = ~2.0 uds/día (o ~1.73 ponderada con 0 en histórico previo)
        $forecast = $this->service->getProductForecast($product, ['as_of' => $now]);

        $this->assertEquals(50, $forecast['available_stock']);
        $this->assertEquals(30, $forecast['lead_time_days']);
        $this->assertEquals(120, $forecast['target_coverage_days']);
        $this->assertEquals(20, $forecast['safety_stock_days']);

        // Días de stock restantes con 50 unidades:
        // Si demanda diaria = 1.0 ud/día => 50 días de cobertura.
        // Como el lead time es 30 días y la cobertura es 120 días, 50 días <= 150 días => estado WARNING / REORDEN
        $this->assertContains($forecast['status'], [
            InventoryDemandForecastingService::STATUS_WARNING,
            InventoryDemandForecastingService::STATUS_CRITICAL,
        ]);

        // La cantidad sugerida a comprar debe cubrir los 120 días de demanda más el lead time de 30 días menos el stock actual
        $this->assertGreaterThan(50, $forecast['suggested_quantity']);
        $this->assertGreaterThan(0, $forecast['estimated_investment']);
    }

    public function test_inventory_health_traffic_light_classifications(): void
    {
        $now = Carbon::parse('2026-10-01 12:00:00');

        // 1. Producto Agotado -> CRÍTICO
        $criticalProd = InventoryProduct::forceCreate([
            'sku' => 'CRITICAL-01',
            'name' => 'Navaja Barbera Clásica',
            'product_type' => 'SIMPLE',
            'cost' => 150.00,
            'is_active' => true,
        ]);
        $critForecast = $this->service->getProductForecast($criticalProd, ['as_of' => $now]);
        $this->assertEquals(InventoryDemandForecastingService::STATUS_CRITICAL, $critForecast['status']);
        $this->assertEquals(0, $critForecast['available_stock']);

        // 2. Producto con stock alto pero venta nula -> ÓPTIMO (sin quiebre)
        $stockProd = InventoryProduct::forceCreate([
            'sku' => 'SAFE-01',
            'name' => 'Capa de Barbero Negra',
            'product_type' => 'SIMPLE',
            'cost' => 100.00,
            'is_active' => true,
        ]);
        InventoryMovement::forceCreate([
            'inventory_product_id' => $stockProd->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 100,
            'occurred_at' => $now->copy()->subDays(50),
        ]);
        $safeForecast = $this->service->getProductForecast($stockProd, ['as_of' => $now]);
        $this->assertEquals(InventoryDemandForecastingService::STATUS_OPTIMAL, $safeForecast['status']);
        $this->assertEquals(0, $safeForecast['suggested_quantity']);
    }

    public function test_brand_configuration_endpoint_saves_successfully(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('restock.configurations.save'), [
            'brand' => 'Andis',
            'supplier' => 'Distribuidora Andis México',
            'cadence_preset' => BrandRestockConfiguration::PRESET_IMPORT_90_120,
            'lead_time_days' => 45,
            'target_coverage_days' => 90,
            'safety_stock_days' => 15,
            'notes' => 'Pedido trimestral con 45 días de entrega',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('config.brand', 'Andis')
            ->assertJsonPath('config.lead_time_days', 45)
            ->assertJsonPath('config.target_coverage_days', 90);

        $this->assertDatabaseHas('brand_restock_configurations', [
            'brand' => 'Andis',
            'cadence_preset' => 'IMPORT_90_120',
            'lead_time_days' => 45,
            'target_coverage_days' => 90,
        ]);
    }

    public function test_forecast_page_and_export_csv_endpoints(): void
    {
        $product = InventoryProduct::forceCreate([
            'sku' => 'GEL-AFEITAR-01',
            'name' => 'Gel para Afeitar 1L',
            'brand' => 'Elegance',
            'product_type' => 'SIMPLE',
            'cost' => 180.00,
            'is_active' => true,
        ]);

        // 1. Vista web Inertia
        $pageResponse = $this->actingAs($this->admin)->get(route('restock.forecast.index'));
        $pageResponse->assertOk();

        // 2. Exportación a CSV
        $exportResponse = $this->actingAs($this->admin)->get(route('restock.forecast.export'));
        $exportResponse->assertOk();
        $this->assertEquals('text/csv; charset=UTF-8', $exportResponse->headers->get('content-type'));

        $content = $exportResponse->streamedContent();
        $this->assertStringContainsString('GEL-AFEITAR-01', $content);
        $this->assertStringContainsString('Elegance', $content);
        $this->assertStringContainsString('Sugerencia de Compra', $content);
    }
}
