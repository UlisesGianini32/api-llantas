<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryKitComponent;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryKitStockService;
use App\Services\InventoryMovementService;
use App\Services\InventoryStockService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchIgoraAndMeliIsolationTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    public function test_igora_royal_6_tubes_deduct_exactly_6_once_across_shared_ml_links_and_derived_kits_with_zero_ml_calls(): void
    {
        Queue::fake();
        Http::fake();

        $admin = $this->makeUser(User::ROLE_ADMIN, 'igora-adm');
        $dest = $this->makeDestination('SALON-IGORA', 'Salón Igora');
        $loc = $this->makeLocation('D3-7');

        $dye = $this->makeProduct([
            'sku' => 'IGORA-ROYAL-0-22',
            'barcode' => '7702045538977',
            'name' => 'Schwarzkopf Igora Royal 0-22 60ml',
            'brand' => 'Schwarzkopf',
            'primary_location_id' => $loc->id,
        ]);
        $peroxide = $this->makeProduct([
            'sku' => 'IGORA-PEROX-20V-60ML',
            'name' => 'Peróxido Igora 20 Vol 60ml',
            'brand' => 'Schwarzkopf',
            'primary_location_id' => $loc->id,
        ]);
        $kit = $this->makeProduct([
            'sku' => 'KIT-IGORA-0-22-PEROX',
            'name' => 'Kit Igora 0-22 + Peróxido 20 Vol',
            'product_type' => InventoryProduct::KIT,
        ]);

        InventoryKitComponent::create([
            'kit_product_id' => $kit->id,
            'component_product_id' => $dye->id,
            'quantity' => 1,
        ]);
        InventoryKitComponent::create([
            'kit_product_id' => $kit->id,
            'component_product_id' => $peroxide->id,
            'quantity' => 1,
        ]);

        // Two different ML publications linked to the same physical dye + one linked to the kit (even with stock_sync_enabled=true)
        InventoryChannelLink::create([
            'inventory_product_id' => $dye->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '1',
            'external_listing_id' => 'MLM1111111111',
            'identity_key' => 'mercado_libre|1|MLM1111111111',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);
        InventoryChannelLink::create([
            'inventory_product_id' => $dye->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '2',
            'external_listing_id' => 'MLM2222222222',
            'identity_key' => 'mercado_libre|2|MLM2222222222',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);
        InventoryChannelLink::create([
            'inventory_product_id' => $kit->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '1',
            'external_listing_id' => 'MLM3333333333',
            'identity_key' => 'mercado_libre|1|MLM3333333333',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);

        $movementSvc = app(InventoryMovementService::class);
        $movementSvc->recordManual([
            'inventory_product_id' => $dye->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 15,
            'occurred_at' => now('UTC')->subDays(2),
        ], $admin);
        $movementSvc->recordManual([
            'inventory_product_id' => $peroxide->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 12,
            'occurred_at' => now('UTC')->subDays(2),
        ], $admin);

        // Before dispatch: dye=15, peroxide=12, derived kit capacity=12
        $this->assertSame(12, app(InventoryKitStockService::class)->availableStock($kit));

        // Deliver 6 individual Igora 0-22 tubes
        $dispatchSvc = app(WarehouseDispatchService::class);
        $draft = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $dye->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 6,
                'captured_unit_of_measure' => 'TUBE',
            ]],
        ], $admin);

        $confirmed = $dispatchSvc->confirm($draft, $admin, 'confirm-igora-6', $draft->draft_version);

        // Deducted ONCE (-6), NOT multiplied by ML listings!
        $this->assertSame(9, app(InventoryStockService::class)->availableStock($dye));
        $this->assertSame(12, app(InventoryStockService::class)->availableStock($peroxide));
        // Kit capacity automatically updates to min(9, 12) = 9
        $this->assertSame(9, app(InventoryKitStockService::class)->availableStock($kit));

        // Phase 1 guarantees ZERO direct ML jobs and ZERO HTTP calls
        $this->assertSame(InventoryDispatchItem::MELI_NOT_AUTHORIZED, $confirmed->items->first()->meli_sync_status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_inactive_controlled_bootstrap_igora_product_is_never_auto_deducted(): void
    {
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'igora-inact');
        $dest = $this->makeDestination();
        $loc = $this->makeLocation('D3-7');
        $inactiveDye = $this->makeProduct([
            'sku' => '7702045538977',
            'barcode' => '7702045538977',
            'name' => 'Igora Royal 4-13 (Alta controlada)',
            'is_active' => false,
            'primary_location_id' => $loc->id,
        ]);

        app(InventoryMovementService::class)->recordManual([
            'inventory_product_id' => $inactiveDye->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 11,
            'occurred_at' => now('UTC')->subDays(2),
        ], $ops);

        $dispatchSvc = app(WarehouseDispatchService::class);
        $draft = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $inactiveDye->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 3,
            ]],
        ], $ops);

        $confirmed = $dispatchSvc->confirm($draft, $ops, 'confirm-igora-inact', $draft->draft_version);
        $this->assertSame(InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION, $confirmed->items->first()->inventory_apply_status);
        $this->assertFalse($inactiveDye->fresh()->is_active);
        $this->assertSame(11, app(InventoryStockService::class)->physicalStock($inactiveDye));
    }
}
