<?php

namespace Tests\Feature;

use App\Models\InventoryDispatch;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use App\Services\Restock\InventoryDemandForecastingService;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchDemandIsolationTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    public function test_dispatch_out_does_not_alter_commercial_demand_while_legitimately_reducing_available_stock(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'demand-iso');
        $dest = $this->makeDestination('SALON-DEM', 'Salón Demanda');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct([
            'sku' => 'WELLA-7-0',
            'name' => 'Koleston 7/0',
            'brand' => 'Wella',
            'primary_location_id' => $loc->id,
            'min_stock' => 5,
        ]);

        $movementSvc = app(InventoryMovementService::class);
        $movementSvc->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 50,
            'occurred_at' => now('UTC')->subDays(10),
        ], $admin);

        // Record a real commercial SALE of 10 units
        $movementSvc->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::SALE,
            'quantity' => 10,
            'occurred_at' => now('UTC')->subDays(5),
        ], $admin);

        $forecastSvc = app(InventoryDemandForecastingService::class);
        $beforeSingle = $forecastSvc->getProductForecast($prod);
        $beforeReport = $forecastSvc->generateForecastReport(['search' => 'WELLA-7-0']);
        $beforeRow = $beforeReport['items'][0];

        $this->assertSame(10, $beforeSingle['sales_local_30d']);
        $this->assertSame(10, $beforeRow['sales_local_30d']);
        $this->assertSame(40, $beforeSingle['available_stock']);

        // Confirm warehouse dispatch (internal consumption + transfer + provisional)
        $dispatchSvc = app(WarehouseDispatchService::class);
        $draft = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_TRANSFER,
            'items' => [
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 15,
                ],
                [
                    'kind' => 'provisional',
                    'captured_name' => 'Wella 8/1 sin alta',
                    'captured_brand' => 'Wella',
                    'captured_quantity' => 8,
                ],
            ],
        ], $admin);

        $dispatchSvc->confirm($draft, $admin, 'confirm-demand-iso-1', $draft->draft_version);

        $afterSingle = $forecastSvc->getProductForecast($prod);
        $afterReport = $forecastSvc->generateForecastReport(['search' => 'WELLA-7-0']);
        $afterRow = $afterReport['items'][0];

        // Commercial demand metrics MUST remain 100% unchanged (H02)
        $this->assertSame($beforeSingle['sales_local_30d'], $afterSingle['sales_local_30d']);
        $this->assertSame($beforeSingle['sales_30d'], $afterSingle['sales_30d']);
        $this->assertSame($beforeSingle['expected_daily_demand'], $afterSingle['expected_daily_demand']);

        $this->assertSame($beforeRow['sales_local_30d'], $afterRow['sales_local_30d']);
        $this->assertSame($beforeRow['sales_30d'], $afterRow['sales_30d']);
        $this->assertSame($beforeRow['expected_daily_demand'], $afterRow['expected_daily_demand']);

        // Real physical/available stock MUST legitimately reflect the -15 deduction
        $this->assertSame(25, $afterSingle['available_stock']);
        $this->assertSame(25, $afterRow['available_stock']);
    }

    public function test_informative_summary_and_csv_export_work_without_touching_restock(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'sum-ops');
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'sum-wh');
        $dest = $this->makeDestination('SALON-SUM', 'Salón Resumen');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['sku' => 'SKU-SUM-1', 'name' => 'Tratamiento 500ml', 'primary_location_id' => $loc->id]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 20,
            'occurred_at' => now('UTC')->subDays(2),
        ], $ops);

        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 4,
            ]],
        ], $ops);
        $svc->confirm($draft, $ops, 'confirm-summary-1', $draft->draft_version);

        $this->actingAs($ops)
            ->getJson('/almacen/salidas/resumen')
            ->assertOk()
            ->assertJsonPath('informative_only', true)
            ->assertJsonPath('rows.0.total_physical_units', 4);

        // Warehouse cannot export CSV
        $this->actingAs($warehouse)->get('/almacen/salidas/exportar-csv')->assertForbidden();

        // Operations can export CSV (without costs column)
        $csvResp = $this->actingAs($ops)->get('/almacen/salidas/exportar-csv')->assertOk();
        $csvContent = $csvResp->streamedContent();
        $this->assertStringContainsString('SKU-SUM-1', $csvContent);
        $this->assertStringNotContainsString('Costo Unitario Referencial', $csvContent);
    }
}
