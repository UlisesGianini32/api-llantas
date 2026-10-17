<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryShelfAudit;
use App\Models\InventoryShelfAuditItem;
use App\Models\User;
use App\Services\InventoryMovementService;
use App\Services\Inventory\ShelfAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShelfAuditTest extends TestCase
{
    private User $admin;

    private InventoryLocation $locI4_1;

    private InventoryLocation $locI4_2;

    private InventoryLocation $locI4_3;

    private InventoryLocation $locI5_1;

    private InventoryProduct $productA;

    private InventoryProduct $productB;

    private InventoryProduct $productC;

    private ShelfAuditService $auditService;

    private InventoryMovementService $movementService;

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

        // Run inventory migrations
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_10_13_200000_create_inventory_shelf_audits_tables.php'))->up();
        (require database_path('migrations/2026_10_13_210000_add_shelf_code_to_inventory_shelf_audits_tables.php'))->up();
        (require database_path('migrations/2026_10_15_180000_add_brand_and_audit_type_to_inventory_shelf_audits.php'))->up();

        $this->admin = User::forceCreate([
            'name' => 'Auditor SBS',
            'email' => 'auditor@sbs.com',
            'password' => bcrypt('password123'),
            'role' => User::ROLE_ADMIN,
        ]);

        // Anaquel I4 con 3 casillas/niveles: I4-1, I4-2, I4-3
        $this->locI4_1 = InventoryLocation::create([
            'code' => 'I4-1',
            'name' => 'I - Tintes Kuul / Hidra',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $this->locI4_2 = InventoryLocation::create([
            'code' => 'I4-2',
            'name' => 'I - Tintes Kuul / Hidra',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $this->locI4_3 = InventoryLocation::create([
            'code' => 'I4-3',
            'name' => 'I - Tintes Kuul / Hidra',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        // Anaquel I5 con casilla I5-1
        $this->locI5_1 = InventoryLocation::create([
            'code' => 'I5-1',
            'name' => 'I - Shampoos y Tratamientos',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $this->productA = InventoryProduct::create([
            'sku' => 'TINTE-ROJO-01',
            'barcode' => '7501234567890',
            'name' => 'Tinte Kuul Rojo Intenso 90ml',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 45.00,
            'price' => 75.00,
            'brand' => 'KUUL',
            'primary_location_id' => $this->locI4_1->id,
        ]);

        $this->productB = InventoryProduct::create([
            'sku' => 'SHAMP-MATIZ-02',
            'barcode' => '7509876543210',
            'name' => 'Shampoo Matizador Morado 1L',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 120.00,
            'price' => 190.00,
            'brand' => 'HIDRA',
            'primary_location_id' => $this->locI4_2->id,
        ]);

        $this->productC = InventoryProduct::create([
            'sku' => 'CERA-MATE-03',
            'barcode' => '7501122334455',
            'name' => 'Cera Mate Fijación Fuerte 100g',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 60.00,
            'price' => 110.00,
            'brand' => 'BARBER STYLE',
            'primary_location_id' => $this->locI5_1->id,
        ]);

        $this->movementService = app(InventoryMovementService::class);
        $this->auditService = app(ShelfAuditService::class);
    }

    public function test_extracts_shelf_code_correctly(): void
    {
        $this->assertEquals('I4', ShelfAuditService::extractShelfCode('I4-1'));
        $this->assertEquals('I4', ShelfAuditService::extractShelfCode('I4-2'));
        $this->assertEquals('I4', ShelfAuditService::extractShelfCode('I4'));
        $this->assertEquals('F10', ShelfAuditService::extractShelfCode('F10-1'));
        $this->assertEquals('F10', ShelfAuditService::extractShelfCode('F10-9'));
        $this->assertEquals('A1', ShelfAuditService::extractShelfCode('A1-1'));
        $this->assertEquals('MOSTRADOR', ShelfAuditService::extractShelfCode('MOSTRADOR'));
        $this->assertEquals('AMZ-A', ShelfAuditService::extractShelfCode('AMZ-A'));
    }

    public function test_groups_locations_into_shelves(): void
    {
        $shelves = $this->auditService->getShelves();

        $this->assertCount(2, $shelves); // I4 and I5

        $shelfI4 = $shelves->firstWhere('shelf_code', 'I4');
        $this->assertNotNull($shelfI4);
        $this->assertEquals(3, $shelfI4['sub_locations_count']);
        $this->assertEquals(['I4-1', 'I4-2', 'I4-3'], $shelfI4['sub_locations']);

        $shelfI5 = $shelves->firstWhere('shelf_code', 'I5');
        $this->assertNotNull($shelfI5);
        $this->assertEquals(1, $shelfI5['sub_locations_count']);
    }

    public function test_can_get_expected_products_for_entire_shelf(): void
    {
        // Product A has 3 in I4-1 and 2 in I4-2 (total 5 in Anaquel I4)
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_1->id,
            'quantity' => 3,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_2->id,
            'quantity' => 2,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Product B has 0 stock in ledger, but its primary_location_id is I4-2
        // Product C belongs to I5

        $products = $this->auditService->getShelfExpectedProducts('I4');

        $this->assertCount(2, $products);
        $this->assertTrue($products->has($this->productA->id));
        $this->assertTrue($products->has($this->productB->id));
        $this->assertFalse($products->has($this->productC->id));

        $this->assertEquals(5, $products[$this->productA->id]['system_quantity']);
        $this->assertStringContainsString('3 en I4-1', $products[$this->productA->id]['location_breakdown']);
        $this->assertStringContainsString('2 en I4-2', $products[$this->productA->id]['location_breakdown']);
        $this->assertEquals(0, $products[$this->productB->id]['system_quantity']);
    }

    public function test_can_create_audit_draft_for_grouped_shelf(): void
    {
        $payload = [
            'shelf_code' => 'I4',
            'notes' => 'Conteo preliminar Anaquel I4',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 0,
                    'counted_quantity' => 5,
                    'location_breakdown' => 'Sin stock previo',
                    'notes' => '5 piezas encontradas',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('inventory_shelf_audits', [
            'shelf_code' => 'I4',
            'status' => InventoryShelfAudit::STATUS_IN_PROGRESS,
            'total_expected_products' => 1,
            'surplus_items_count' => 1,
            'total_difference_units' => 5,
        ]);
    }

    public function test_can_apply_audit_immediately_and_adjust_ledger_for_shelf(): void
    {
        // Initial stock in Anaquel I4:
        // Product A: 3 in I4-1, 2 in I4-2 -> Total 5
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_1->id,
            'quantity' => 3,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_2->id,
            'quantity' => 2,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Product B: 10 units in I4-2
        $this->movementService->record([
            'inventory_product_id' => $this->productB->id,
            'inventory_location_id' => $this->locI4_2->id,
            'quantity' => 10,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Counted in Anaquel I4:
        // Product A: counted 8 (sobrante +3)
        // Product B: counted 6 (faltante -4)
        $payload = [
            'shelf_code' => 'I4',
            'notes' => 'Auditoría completa Anaquel I4',
            'apply_immediately' => true,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 5,
                    'counted_quantity' => 8,
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 6,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit = InventoryShelfAudit::where('shelf_code', 'I4')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(InventoryShelfAudit::STATUS_APPLIED, $audit->status);
        $this->assertEquals('I4', $audit->shelf_code);
        $this->assertEquals($this->admin->id, $audit->applied_by);
        $this->assertEquals(1, $audit->surplus_items_count);
        $this->assertEquals(1, $audit->missing_items_count);
        $this->assertEquals(-1, $audit->total_difference_units);

        // Verify total resulting stock in Anaquel I4 matches counted quantity:
        $totalStockA = InventoryMovement::whereIn('inventory_location_id', [$this->locI4_1->id, $this->locI4_2->id, $this->locI4_3->id])
            ->where('inventory_product_id', $this->productA->id)
            ->sum('quantity');
        $this->assertEquals(8, $totalStockA);

        $totalStockB = InventoryMovement::whereIn('inventory_location_id', [$this->locI4_1->id, $this->locI4_2->id, $this->locI4_3->id])
            ->where('inventory_product_id', $this->productB->id)
            ->sum('quantity');
        $this->assertEquals(6, $totalStockB);

        // Verify no sub-location has negative stock!
        $stockB_loc1 = InventoryMovement::where('inventory_location_id', $this->locI4_1->id)
            ->where('inventory_product_id', $this->productB->id)->sum('quantity');
        $stockB_loc2 = InventoryMovement::where('inventory_location_id', $this->locI4_2->id)
            ->where('inventory_product_id', $this->productB->id)->sum('quantity');

        $this->assertGreaterThanOrEqual(0, $stockB_loc1);
        $this->assertGreaterThanOrEqual(0, $stockB_loc2);
    }

    public function test_can_get_shelf_products_endpoint(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('inventory.audits.shelf_products', [
            'shelfIdentifier' => 'I4',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('shelf.shelf_code', 'I4');
        $response->assertJsonPath('shelf.sub_locations_count', 3);
    }

    public function test_can_get_brands_list_and_expected_products_for_brand(): void
    {
        $brands = $this->auditService->getBrands();
        $this->assertCount(3, $brands);
        $this->assertTrue($brands->contains('brand', 'KUUL'));
        $this->assertTrue($brands->contains('brand', 'HIDRA'));
        $this->assertTrue($brands->contains('brand', 'BARBER STYLE'));

        $kuulProducts = $this->auditService->getBrandExpectedProducts('KUUL');
        $this->assertCount(1, $kuulProducts);
        $this->assertEquals($this->productA->id, $kuulProducts->first()['id']);
    }

    public function test_can_create_audit_draft_for_brand(): void
    {
        $payload = [
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_BRAND,
            'brand_name' => 'KUUL',
            'notes' => 'Auditoría borrador marca KUUL',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 0,
                    'counted_quantity' => 12,
                    'location_breakdown' => 'Bodega General',
                    'notes' => '12 unidades encontradas',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('inventory_shelf_audits', [
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_BRAND,
            'brand_name' => 'KUUL',
            'status' => InventoryShelfAudit::STATUS_IN_PROGRESS,
            'total_expected_products' => 1,
            'total_counted_products' => 1,
            'total_counted_units' => 12,
            'total_difference_units' => 12,
        ]);
    }

    public function test_can_apply_audit_immediately_and_adjust_ledger_for_brand(): void
    {
        // Initial stock for productA (KUUL) in locI4_1
        $this->movementService->recordManual([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_1->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'reference' => 'Stock inicial KUUL',
            'occurred_at' => now(),
        ], $this->admin);

        // Audit counts 15 (+5 surplus)
        $payload = [
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_BRAND,
            'brand_name' => 'KUUL',
            'notes' => 'Ajuste físico inmediato KUUL',
            'apply_immediately' => true,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 15,
                    'location_breakdown' => 'I4-1: 10 pzas',
                    'notes' => '+5 unidades sobrantes',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit = InventoryShelfAudit::where('brand_name', 'KUUL')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(InventoryShelfAudit::STATUS_APPLIED, $audit->status);
        $this->assertEquals(InventoryShelfAudit::AUDIT_TYPE_BRAND, $audit->audit_type);
        $this->assertEquals(5, $audit->total_difference_units);

        // Total stock must now be 15
        $totalStockA = InventoryMovement::where('inventory_product_id', $this->productA->id)->sum('quantity');
        $this->assertEquals(15, $totalStockA);
    }

    public function test_can_get_brand_products_endpoint(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('inventory.audits.brand_products', [
            'brandName' => 'KUUL',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('brand.name', 'KUUL');
        $this->assertCount(1, $response->json('products'));
    }

    public function test_can_apply_audit_immediately_with_negative_discrepancy_for_brand(): void
    {
        // 10 in locI4_1, 5 in locI4_2 (Total 15)
        $this->movementService->recordManual([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_1->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'reference' => 'Lote 1 KUUL',
            'occurred_at' => now(),
        ], $this->admin);

        $this->movementService->recordManual([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_2->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 5,
            'reference' => 'Lote 2 KUUL',
            'occurred_at' => now(),
        ], $this->admin);

        // Count 11 (-4 missing)
        $payload = [
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_BRAND,
            'brand_name' => 'KUUL',
            'notes' => 'Ajuste faltante KUUL',
            'apply_immediately' => true,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 15,
                    'counted_quantity' => 11,
                    'notes' => '-4 unidades faltantes',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit = InventoryShelfAudit::where('brand_name', 'KUUL')->latest()->first();
        $this->assertEquals(-4, $audit->total_difference_units);
        $this->assertEquals(1, $audit->missing_items_count);

        $totalStockA = InventoryMovement::where('inventory_product_id', $this->productA->id)->sum('quantity');
        $this->assertEquals(11, $totalStockA);

        $stockLoc1 = InventoryMovement::where('inventory_location_id', $this->locI4_1->id)->where('inventory_product_id', $this->productA->id)->sum('quantity');
        $stockLoc2 = InventoryMovement::where('inventory_location_id', $this->locI4_2->id)->where('inventory_product_id', $this->productA->id)->sum('quantity');
        $this->assertGreaterThanOrEqual(0, $stockLoc1);
        $this->assertGreaterThanOrEqual(0, $stockLoc2);
    }

    public function test_can_open_edit_page_for_in_progress_audit(): void
    {
        $audit = $this->auditService->createAudit([
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
            'shelf_code' => 'I4',
            'notes' => 'Borrador inicial',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 8,
                ],
            ],
        ], $this->admin, false);

        $response = $this->actingAs($this->admin)->get(route('inventory.audits.edit', $audit));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Audits/Create')
            ->has('resumeAudit')
            ->where('resumeAudit.id', $audit->id)
            ->where('resumeAudit.shelf_code', 'I4')
            ->has('initialProducts')
        );
    }

    public function test_cannot_edit_already_applied_audit(): void
    {
        $audit = $this->auditService->createAudit([
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
            'shelf_code' => 'I4',
            'notes' => 'Auditoría aplicada',
            'apply_immediately' => true,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 10,
                ],
            ],
        ], $this->admin, true);

        $response = $this->actingAs($this->admin)->get(route('inventory.audits.edit', $audit));
        $response->assertRedirect(route('inventory.audits.show', $audit));
    }

    public function test_can_update_in_progress_audit_draft_via_put(): void
    {
        $audit = $this->auditService->createAudit([
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
            'shelf_code' => 'I4',
            'notes' => 'Borrador inicial',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 8,
                ],
            ],
        ], $this->admin, false);

        $payload = [
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
            'shelf_code' => 'I4',
            'notes' => 'Borrador actualizado con nuevo conteo',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 12, // Cambió de 8 a 12
                    'location_breakdown' => 'I4-1: 10 pzas',
                    'notes' => 'Reconteo físico',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->putJson(route('inventory.audits.update', $audit), $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit->refresh();
        $this->assertEquals(InventoryShelfAudit::STATUS_IN_PROGRESS, $audit->status);
        $this->assertEquals('Borrador actualizado con nuevo conteo', $audit->notes);
        $this->assertEquals(2, $audit->total_difference_units); // 12 - 10 = +2
        $this->assertEquals(1, $audit->surplus_items_count);

        $item = $audit->items()->first();
        $this->assertEquals(12, $item->counted_quantity);
    }

    public function test_can_update_and_apply_in_progress_audit_via_put(): void
    {
        // 10 initial stock
        $this->movementService->recordManual([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->locI4_1->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => 10,
            'reference' => 'Lote Inicial',
            'occurred_at' => now(),
        ], $this->admin);

        $audit = $this->auditService->createAudit([
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
            'shelf_code' => 'I4',
            'notes' => 'Borrador para aplicar luego',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 10,
                ],
            ],
        ], $this->admin, false);

        $payload = [
            'audit_type' => InventoryShelfAudit::AUDIT_TYPE_SHELF,
            'shelf_code' => 'I4',
            'notes' => 'Aplicado final',
            'apply_immediately' => true,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 14, // +4 surplus
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->putJson(route('inventory.audits.update', $audit), $payload);
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit->refresh();
        $this->assertEquals(InventoryShelfAudit::STATUS_APPLIED, $audit->status);
        $this->assertEquals(4, $audit->total_difference_units);

        $stock = InventoryMovement::where('inventory_product_id', $this->productA->id)->sum('quantity');
        $this->assertEquals(14, $stock);
    }
}

