<?php

namespace Tests\Feature;

use App\Exceptions\WarehouseDispatchConflictException;
use App\Exceptions\WarehouseDispatchReadOnlyException;
use App\Exceptions\WarehouseDispatchesDisabledException;
use App\Models\InventoryDispatch;
use App\Models\InventoryDispatchItem;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\User;
use App\Services\Inventory\Dispatches\ProvisionalItemLinkService;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\InventoryMovementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\UsesWarehouseDispatchSchema;
use Tests\TestCase;

class WarehouseDispatchPhase16HardeningTest extends TestCase
{
    use UsesWarehouseDispatchSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapWarehouseDispatchSchema();
        config()->set('inventory.warehouse_dispatches.enabled', true);
        config()->set('inventory.warehouse_dispatches.read_only', false);
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);
    }

    public function test_record_for_dispatch_enforces_flags_permissions_and_db_locked_integrity(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'p16-admin');
        $pos = $this->makeUser(User::ROLE_POS, 'p16-pos');
        $dest = $this->makeDestination('SALON-P16', 'Salón P16');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id, 'is_active' => true]);

        $movementSvc = app(InventoryMovementService::class);
        $movementSvc->recordManual([
            'inventory_product_id' => $prod->id,
            'inventory_location_id' => $loc->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 20,
            'occurred_at' => now('UTC')->subDay(),
        ], $admin);

        $dispatchSvc = app(WarehouseDispatchService::class);
        $draft = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ], $admin);
        $item = $draft->items->first();

        // 1. Disabled module blocks direct invocation
        config()->set('inventory.warehouse_dispatches.enabled', false);
        try {
            $movementSvc->recordForDispatch($draft, $item, 2, $admin);
            $this->fail('Expected WarehouseDispatchesDisabledException.');
        } catch (WarehouseDispatchesDisabledException) {
            $this->assertTrue(true);
        }
        config()->set('inventory.warehouse_dispatches.enabled', true);

        // 2. Read-only blocks direct invocation
        config()->set('inventory.warehouse_dispatches.read_only', true);
        try {
            $movementSvc->recordForDispatch($draft, $item, 2, $admin);
            $this->fail('Expected WarehouseDispatchReadOnlyException.');
        } catch (WarehouseDispatchReadOnlyException) {
            $this->assertTrue(true);
        }
        config()->set('inventory.warehouse_dispatches.read_only', false);

        // 3. apply_ledger=false blocks direct invocation
        config()->set('inventory.warehouse_dispatches.apply_ledger', false);
        try {
            $movementSvc->recordForDispatch($draft, $item, 2, $admin);
            $this->fail('Expected InvalidArgumentException when apply_ledger=false.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        config()->set('inventory.warehouse_dispatches.apply_ledger', true);

        // 4. Null user and unauthorized role (pos) blocked
        foreach ([null, $pos] as $badUser) {
            try {
                $movementSvc->recordForDispatch($draft, $item, 2, $badUser);
                $this->fail('Expected AuthorizationException for unauthorized caller.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        // 5. In-memory status forgery on a CANCELLED dispatch is defeated by DB-locked reload
        $cancelled = $dispatchSvc->cancelDraft($draft, $admin, 'Cancelado');
        $forged = clone $cancelled;
        $forged->status = InventoryDispatch::STATUS_DRAFT;
        try {
            $movementSvc->recordForDispatch($forged, $item, 2, $admin);
            $this->fail('Expected InvalidArgumentException for forged cancelled dispatch.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // 6. Inactive product is blocked
        $inactiveProd = $this->makeProduct(['primary_location_id' => $loc->id, 'is_active' => true]);
        $draft2 = $dispatchSvc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $inactiveProd->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 1,
            ]],
        ], $admin);
        $inactiveProd->forceFill(['is_active' => false])->save();
        try {
            $movementSvc->recordForDispatch($draft2, $draft2->items->first(), 1, $admin);
            $this->fail('Expected InvalidArgumentException for inactive product.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, InventoryMovement::where('type', InventoryMovement::DISPATCH_OUT)->count());
    }

    public function test_cross_user_idempotency_key_reuse_is_rejected_on_create_and_confirm(): void
    {
        config()->set('inventory.warehouse_dispatches.apply_ledger', false);

        $whA = $this->makeUser(User::ROLE_WAREHOUSE, 'idem-wh-a');
        $whB = $this->makeUser(User::ROLE_WAREHOUSE, 'idem-wh-b');
        $dest = $this->makeDestination('SALON-IDEM', 'Salón Idem');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['primary_location_id' => $loc->id]);

        $svc = app(WarehouseDispatchService::class);
        $payload = [
            'create_idempotency_key' => 'shared-create-key-001',
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
            'effective_at' => now('UTC')->subMinutes(10)->toIso8601String(),
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ];

        $draft = $svc->createDraft($payload, $whA);

        // Same user replay succeeds idempotently
        $replay = $svc->createDraft($payload, $whA);
        $this->assertSame($draft->id, $replay->id);

        // Different user reusing same create key -> 409 conflict
        try {
            $svc->createDraft($payload, $whB);
            $this->fail('Expected WarehouseDispatchConflictException for cross-user create key.');
        } catch (WarehouseDispatchConflictException) {
            $this->assertTrue(true);
        }

        // Confirm by whA, replay by whA succeeds, replay by whB -> 409 conflict
        $confirmed = $svc->confirm($draft, $whA, 'shared-confirm-key-001', $draft->draft_version);
        $replayedConfirm = $svc->confirm($draft, $whA, 'shared-confirm-key-001', $draft->draft_version);
        $this->assertSame($confirmed->id, $replayedConfirm->id);

        $this->expectException(WarehouseDispatchConflictException::class);
        $svc->confirm($draft, $whB, 'shared-confirm-key-001', $draft->draft_version);
    }

    public function test_transitive_merge_a_to_b_to_c_and_merge_into_linked_canonical_never_duplicate_or_deduct(): void
    {
        config()->set('inventory.warehouse_dispatches.apply_ledger', false);

        $admin = $this->makeUser(User::ROLE_ADMIN, 'trans-admin');
        $dest = $this->makeDestination('SALON-TR', 'Salón Transitivo');
        $loc = $this->makeLocation('D3-7');
        $master = $this->makeProduct([
            'sku' => 'IGORA-9-1',
            'barcode' => '004045787429999',
            'name' => 'Igora Royal 9-1 Rubio Extra Claro Ceniza',
            'brand' => 'Schwarzkopf',
            'primary_location_id' => $loc->id,
        ]);

        $linkSvc = app(ProvisionalItemLinkService::class);
        $dispatchSvc = app(WarehouseDispatchService::class);

        $provA = $linkSvc->createProvisional(['name' => 'Igora 9-1 A', 'brand' => 'Schwarzkopf', 'tone' => '9-1', 'presentation' => '60ml'], $admin);
        $provB = $linkSvc->createProvisional(['name' => 'Igora 9-1 B', 'brand' => 'Schwarzkopf', 'tone' => '9/1', 'presentation' => '60ml'], $admin);
        $provC = $linkSvc->createProvisional(['name' => 'Igora 9-1 C', 'brand' => 'Schwarzkopf', 'tone' => '9-1', 'presentation' => '60ml'], $admin);
        $provD = $linkSvc->createProvisional(['name' => 'Igora 9-1 D', 'brand' => 'Schwarzkopf', 'tone' => '9-1', 'presentation' => '60ml'], $admin);

        foreach ([[$provA, 2, 'tr-1'], [$provB, 3, 'tr-2'], [$provC, 4, 'tr-3'], [$provD, 5, 'tr-4']] as [$prov, $qty, $key]) {
            $d = $dispatchSvc->createDraft([
                'destination_id' => $dest->id,
                'operation_type' => InventoryDispatch::OP_INTERNAL_CONSUMPTION,
                'items' => [[
                    'kind' => 'provisional',
                    'provisional_item_id' => $prov->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => $qty,
                    'captured_unit_of_measure' => 'TUBE',
                ]],
            ], $admin);
            $dispatchSvc->confirm($d, $admin, $key, $d->draft_version);
        }

        // Transitive merge: A -> B, then B -> C
        $linkSvc->mergeProvisionalItems($provA, $provB, $admin, 'Duplicado A->B');
        $linkSvc->mergeProvisionalItems($provB, $provC, $admin, 'Duplicado B->C');

        $provA->refresh();
        $provB->refresh();
        $provC->refresh();
        $this->assertSame($provC->id, $provA->canonical_provisional_id);
        $this->assertSame($provC->id, $provB->canonical_provisional_id);

        // Summary BEFORE linking: A+B+C grouped into 1 row = 9 units; D = 5 units
        $summaryBefore = $this->actingAs($admin)->getJson('/almacen/salidas/resumen')->assertOk()->json('rows');
        $this->assertCount(2, $summaryBefore);
        $rowC = collect($summaryBefore)->firstWhere('canonical_provisional_id', $provC->id);
        $this->assertSame(9, $rowC['total_physical_units']);

        // Link C -> Master product (propagates to A, B, C items with ZERO movements)
        $linkSvc->linkToMasterProduct($provC, $master, $admin, 'Vinculación canónica C');
        $this->assertSame(0, InventoryMovement::count());

        // Now merge unlinked D into ALREADY-LINKED C -> D's items also propagate to master!
        $linkSvc->mergeProvisionalItems($provD, $provC, $admin, 'Duplicado tardío D->C');
        $this->assertSame(0, InventoryMovement::count());

        // Original provisional_item_id is preserved on every single dispatch item (H08)
        $this->assertSame(1, InventoryDispatchItem::where('provisional_item_id', $provA->id)->where('inventory_product_id', $master->id)->count());
        $this->assertSame(1, InventoryDispatchItem::where('provisional_item_id', $provB->id)->where('inventory_product_id', $master->id)->count());
        $this->assertSame(1, InventoryDispatchItem::where('provisional_item_id', $provC->id)->where('inventory_product_id', $master->id)->count());
        $this->assertSame(1, InventoryDispatchItem::where('provisional_item_id', $provD->id)->where('inventory_product_id', $master->id)->count());

        // Summary AFTER linking & late merge: ALL 14 units grouped under 1 single master product row (zero duplication)
        $summaryAfter = $this->actingAs($admin)->getJson('/almacen/salidas/resumen')->assertOk()->json('rows');
        $this->assertCount(1, $summaryAfter);
        $this->assertSame('REGISTERED', $summaryAfter[0]['item_kind']);
        $this->assertSame($master->id, $summaryAfter[0]['inventory_product_id']);
        $this->assertSame(14, $summaryAfter[0]['total_physical_units']);
        $this->assertSame('004045787429999', $summaryAfter[0]['display_barcode']);
    }

    public function test_summary_and_csv_export_filters_leading_zeros_spanish_formula_injection_and_rbac(): void
    {
        config()->set('inventory.warehouse_dispatches.apply_ledger', false);

        $admin = $this->makeUser(User::ROLE_ADMIN, 'csv-admin');
        $ops = $this->makeUser(User::ROLE_OPERATIONS, 'csv-ops');
        $warehouse = $this->makeUser(User::ROLE_WAREHOUSE, 'csv-wh');

        $salonA = $this->makeDestination('SAL-PENA', 'Salón Peñafiel Ñuñoa');
        $salonB = $this->makeDestination('SAL-NORTE', 'Salón Norte');
        $loc = $this->makeLocation('D3-7');

        $prod = $this->makeProduct([
            'sku' => 'WELLA-007',
            'barcode' => '007702045538977',
            'name' => 'Baño de Crema Ácido Úrico 500ml',
            'brand' => 'Wella',
            'cost' => 112.50,
            'price_stylist' => 210.00,
            'primary_location_id' => $loc->id,
        ]);

        $svc = app(WarehouseDispatchService::class);

        // 1. Confirmed SALON_SALE with leading-zero barcode + formula-injection attempt in received_by_name
        $dConfirmed = $svc->createDraft([
            'destination_id' => $salonA->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'received_by_name' => '=HYPERLINK("http://evil","Click")',
            'items' => [
                [
                    'kind' => 'registered',
                    'inventory_product_id' => $prod->id,
                    'inventory_location_id' => $loc->id,
                    'captured_quantity' => 5,
                ],
                [
                    'kind' => 'provisional',
                    'inventory_location_id' => $loc->id,
                    'captured_barcode' => '0001234567890',
                    'captured_name' => '+Mascarilla Reparación Profunda',
                    'captured_brand' => 'Loreal',
                    'captured_tone' => 'N/A',
                    'captured_presentation' => 'Caja sin equivalencia',
                    'captured_quantity' => 2,
                    'captured_unit_of_measure' => 'BOX',
                    'unit_conversion_factor' => null,
                ],
            ],
        ], $admin);
        $svc->confirm($dConfirmed, $admin, 'csv-k1', $dConfirmed->draft_version);

        // 2. Confirmed TRANSFER (must remain separated by operation_type)
        $dTransfer = $svc->createDraft([
            'destination_id' => $salonB->id,
            'operation_type' => InventoryDispatch::OP_TRANSFER,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 3,
            ]],
        ], $admin);
        $svc->confirm($dTransfer, $admin, 'csv-k2', $dTransfer->draft_version);

        // 3. DRAFT (must NOT count in summary or default CSV)
        $svc->createDraft([
            'destination_id' => $salonA->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 99,
            ]],
        ], $admin);

        // 4. CANCELLED confirmed without ledger (must NOT count in summary or default CSV)
        $dCancel = $svc->createDraft([
            'destination_id' => $salonA->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 77,
            ]],
        ], $admin);
        $dCancel = $svc->confirm($dCancel, $admin, 'csv-k4', $dCancel->draft_version);
        $svc->cancelConfirmedWithoutLedger($dCancel, $admin, 'Error captura', InventoryDispatch::CANCEL_SITUATION_NEVER_DELIVERED);

        // Verify summary JSON
        $summaryRows = $this->actingAs($ops)->getJson('/almacen/salidas/resumen')->assertOk()->json('rows');
        $this->assertCount(3, $summaryRows);

        $saleRow = collect($summaryRows)->first(fn ($r) => $r['inventory_product_id'] === $prod->id && $r['operation_type'] === InventoryDispatch::OP_SALON_SALE);
        $transferRow = collect($summaryRows)->first(fn ($r) => $r['inventory_product_id'] === $prod->id && $r['operation_type'] === InventoryDispatch::OP_TRANSFER);
        $provBoxRow = collect($summaryRows)->firstWhere('item_kind', 'PROVISIONAL');

        $this->assertSame(5, $saleRow['total_physical_units']);
        $this->assertSame('007702045538977', $saleRow['display_barcode']);
        $this->assertSame('Salón Peñafiel Ñuñoa', $saleRow['destinations_summary']);
        $this->assertSame(3, $transferRow['total_physical_units']);
        $this->assertSame(0, $provBoxRow['total_physical_units']);
        $this->assertSame(2, $provBoxRow['unconverted_containers_qty']);
        $this->assertSame('0001234567890', $provBoxRow['display_barcode']);
        $this->assertSame('Caja sin equivalencia', $provBoxRow['display_presentation']);

        // RBAC: warehouse blocked from CSV export
        $this->actingAs($warehouse)->get('/almacen/salidas/exportar-csv')->assertForbidden();
        $this->actingAs($warehouse)->get('/almacen/salidas/exportar-csv?mode=summary')->assertForbidden();

        // Detailed CSV as operations: UTF-8 BOM, Spanish accents, leading zeros, formula neutralized, NO cost column, NO draft/cancelled
        $opsCsv = $this->actingAs($ops)->get('/almacen/salidas/exportar-csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $opsCsv);
        $this->assertStringContainsString('Salón Peñafiel Ñuñoa', $opsCsv);
        $this->assertStringContainsString('Baño de Crema Ácido Úrico 500ml', $opsCsv);
        $this->assertStringContainsString('007702045538977', $opsCsv);
        $this->assertStringContainsString('0001234567890', $opsCsv);
        $this->assertStringContainsString("'=HYPERLINK", $opsCsv);
        $this->assertStringContainsString("'+Mascarilla", $opsCsv);
        $this->assertStringNotContainsString('Costo Unitario Referencial', $opsCsv);
        $this->assertStringNotContainsString('112.50', $opsCsv);
        $this->assertStringContainsString('210.00', $opsCsv);
        $this->assertStringNotContainsString(',99,', $opsCsv);
        $this->assertStringNotContainsString(',77,', $opsCsv);

        // Summary CSV as admin
        $summaryCsv = $this->actingAs($admin)->get('/almacen/salidas/exportar-csv?mode=summary')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $summaryCsv);
        $this->assertStringContainsString('007702045538977', $summaryCsv);
        $this->assertStringContainsString('0001234567890', $summaryCsv);
        $this->assertStringContainsString("'+Mascarilla", $summaryCsv);
        $this->assertStringContainsString('Caja sin equivalencia', $summaryCsv);
    }

    public function test_eloquent_models_hide_financial_snapshots_by_default(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN, 'hide-admin');
        $dest = $this->makeDestination('SALON-HIDE', 'Salón Hide');
        $loc = $this->makeLocation('D3-7');
        $prod = $this->makeProduct(['cost' => 75.00, 'price_stylist' => 150.00, 'primary_location_id' => $loc->id]);

        config()->set('inventory.warehouse_dispatches.apply_ledger', false);
        $svc = app(WarehouseDispatchService::class);
        $draft = $svc->createDraft([
            'destination_id' => $dest->id,
            'operation_type' => InventoryDispatch::OP_SALON_SALE,
            'items' => [[
                'kind' => 'registered',
                'inventory_product_id' => $prod->id,
                'inventory_location_id' => $loc->id,
                'captured_quantity' => 2,
            ]],
        ], $admin);
        $confirmed = $svc->confirm($draft, $admin, 'hide-k1', $draft->draft_version);

        $dispatchArray = $confirmed->toArray();
        $this->assertArrayNotHasKey('reference_subtotal_cost', $dispatchArray);
        $this->assertArrayNotHasKey('reference_subtotal_stylist', $dispatchArray);

        $itemArray = $confirmed->items->first()->toArray();
        $this->assertArrayNotHasKey('unit_cost_snapshot', $itemArray);
        $this->assertArrayNotHasKey('unit_price_stylist_snapshot', $itemArray);
    }

    public function test_migrations_fail_closed_when_preexisting_table_has_partial_schema(): void
    {
        Schema::dropIfExists('inventory_dispatch_events');
        Schema::dropIfExists('inventory_dispatch_items');
        Schema::dropIfExists('inventory_dispatches');
        Schema::dropIfExists('inventory_provisional_items');
        Schema::dropIfExists('inventory_dispatch_destinations');

        // Create a deliberately partial/incompatible table missing required columns
        Schema::create('inventory_dispatch_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $migration = require database_path('migrations/2026_11_27_000001_create_inventory_dispatch_destinations_table.php');

        $this->expectException(RuntimeException::class);
        $migration->up();
    }
}
