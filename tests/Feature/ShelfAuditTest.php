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

    private InventoryLocation $shelfI4;

    private InventoryLocation $shelfI5;

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

        $this->admin = User::forceCreate([
            'name' => 'Auditor SBS',
            'email' => 'auditor@sbs.com',
            'password' => bcrypt('password123'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->shelfI4 = InventoryLocation::create([
            'code' => 'I4',
            'name' => 'Anaquel I4 - Pasillo Tintes',
            'type' => 'shelf',
            'is_active' => true,
        ]);

        $this->shelfI5 = InventoryLocation::create([
            'code' => 'I5',
            'name' => 'Anaquel I5 - Pasillo Shampoos',
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
            'primary_location_id' => $this->shelfI4->id,
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
            'primary_location_id' => $this->shelfI4->id,
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
            'primary_location_id' => $this->shelfI5->id,
        ]);

        $this->movementService = app(InventoryMovementService::class);
        $this->auditService = app(ShelfAuditService::class);
    }

    public function test_can_get_expected_products_for_shelf(): void
    {
        // Add 5 units of Product A to shelf I4
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->shelfI4->id,
            'quantity' => 5,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Product B has 0 stock in ledger, but its primary_location_id is shelf I4
        // Product C has 0 stock in shelf I4 and belongs to shelf I5

        $products = $this->auditService->getShelfExpectedProducts($this->shelfI4->id);

        $this->assertCount(2, $products);
        $this->assertTrue($products->has($this->productA->id));
        $this->assertTrue($products->has($this->productB->id));
        $this->assertFalse($products->has($this->productC->id));

        $this->assertEquals(5, $products[$this->productA->id]['system_quantity']);
        $this->assertEquals(0, $products[$this->productB->id]['system_quantity']);
    }

    public function test_can_create_audit_draft_without_applying_movements(): void
    {
        // Initial stock in shelf I4: 5 units of Product A
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->shelfI4->id,
            'quantity' => 5,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        $payload = [
            'inventory_location_id' => $this->shelfI4->id,
            'notes' => 'Conteo preliminar borrador',
            'apply_immediately' => false,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 5,
                    'counted_quantity' => 7, // +2 sobrante
                    'notes' => 'Se encontraron 2 piezas extra',
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'system_quantity' => 0,
                    'counted_quantity' => 0, // exacto
                    'notes' => null,
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $this->assertDatabaseHas('inventory_shelf_audits', [
            'inventory_location_id' => $this->shelfI4->id,
            'status' => InventoryShelfAudit::STATUS_IN_PROGRESS,
            'total_expected_products' => 2,
            'matched_items_count' => 1,
            'surplus_items_count' => 1,
            'missing_items_count' => 0,
            'total_difference_units' => 2,
        ]);

        // Stock in ledger should NOT have changed yet
        $currentStockA = InventoryMovement::where('inventory_location_id', $this->shelfI4->id)
            ->where('inventory_product_id', $this->productA->id)
            ->sum('quantity');

        $this->assertEquals(5, $currentStockA);
    }

    public function test_can_apply_audit_immediately_and_adjust_ledger(): void
    {
        // Setup initial stock in shelf I4:
        // Product A: 5 units
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->shelfI4->id,
            'quantity' => 5,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Product B: 10 units
        $this->movementService->record([
            'inventory_product_id' => $this->productB->id,
            'inventory_location_id' => $this->shelfI4->id,
            'quantity' => 10,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        // Audit Count:
        // Product A: counted 8 (sobrante +3 -> should generate ADJUSTMENT_IN +3)
        // Product B: counted 6 (faltante -4 -> should generate ADJUSTMENT_OUT -4)
        $payload = [
            'inventory_location_id' => $this->shelfI4->id,
            'notes' => 'Auditoría con ajuste inmediato',
            'apply_immediately' => true,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 5,
                    'counted_quantity' => 8,
                    'notes' => 'Sobrante de 3 piezas',
                ],
                [
                    'inventory_product_id' => $this->productB->id,
                    'system_quantity' => 10,
                    'counted_quantity' => 6,
                    'notes' => 'Faltante de 4 piezas',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.store'), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit = InventoryShelfAudit::where('inventory_location_id', $this->shelfI4->id)->first();
        $this->assertNotNull($audit);
        $this->assertEquals(InventoryShelfAudit::STATUS_APPLIED, $audit->status);
        $this->assertEquals($this->admin->id, $audit->applied_by);
        $this->assertNotNull($audit->applied_at);
        $this->assertEquals(1, $audit->surplus_items_count);
        $this->assertEquals(1, $audit->missing_items_count);
        $this->assertEquals(0, $audit->matched_items_count);
        $this->assertEquals(-1, $audit->total_difference_units);

        // Verify ledger movements were created:
        $movements = InventoryMovement::where('inventory_location_id', $this->shelfI4->id)
            ->where('reference', 'like', "%{$audit->audit_number}%")
            ->get();

        $this->assertCount(2, $movements);

        $movA = $movements->firstWhere('inventory_product_id', $this->productA->id);
        $this->assertNotNull($movA);
        $this->assertEquals(InventoryMovement::ADJUSTMENT_IN, $movA->type);
        $this->assertEquals(3, $movA->quantity);

        $movB = $movements->firstWhere('inventory_product_id', $this->productB->id);
        $this->assertNotNull($movB);
        $this->assertEquals(InventoryMovement::ADJUSTMENT_OUT, $movB->type);
        $this->assertEquals(-4, $movB->quantity);

        // Verify resulting stock in ledger exactly equals counted physical stock!
        $finalStockA = InventoryMovement::where('inventory_location_id', $this->shelfI4->id)
            ->where('inventory_product_id', $this->productA->id)
            ->sum('quantity');
        $this->assertEquals(8, $finalStockA);

        $finalStockB = InventoryMovement::where('inventory_location_id', $this->shelfI4->id)
            ->where('inventory_product_id', $this->productB->id)
            ->sum('quantity');
        $this->assertEquals(6, $finalStockB);
    }

    public function test_can_apply_draft_audit_later(): void
    {
        // 5 units of Product A
        $this->movementService->record([
            'inventory_product_id' => $this->productA->id,
            'inventory_location_id' => $this->shelfI4->id,
            'quantity' => 5,
            'type' => InventoryMovement::RECEIPT,
            'created_by_user_id' => $this->admin->id,
        ]);

        $audit = $this->auditService->createAudit([
            'inventory_location_id' => $this->shelfI4->id,
            'notes' => 'Guardado primero como borrador',
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 5,
                    'counted_quantity' => 9, // +4 sobrante
                ],
            ],
        ], $this->admin, false);

        $this->assertEquals(InventoryShelfAudit::STATUS_IN_PROGRESS, $audit->status);

        // Now apply via endpoint
        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.apply', $audit), [
            'notes' => 'Aprobado por jefe de almacén',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        $audit->refresh();
        $this->assertEquals(InventoryShelfAudit::STATUS_APPLIED, $audit->status);

        $finalStock = InventoryMovement::where('inventory_location_id', $this->shelfI4->id)
            ->where('inventory_product_id', $this->productA->id)
            ->sum('quantity');

        $this->assertEquals(9, $finalStock);
    }

    public function test_cannot_reapply_already_applied_audit(): void
    {
        $audit = $this->auditService->createAudit([
            'inventory_location_id' => $this->shelfI4->id,
            'items' => [
                [
                    'inventory_product_id' => $this->productA->id,
                    'system_quantity' => 0,
                    'counted_quantity' => 2,
                ],
            ],
        ], $this->admin, true);

        $this->assertEquals(InventoryShelfAudit::STATUS_APPLIED, $audit->status);

        // Attempting to apply again should fail
        $response = $this->actingAs($this->admin)->postJson(route('inventory.audits.apply', $audit));

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
    }

    public function test_search_catalog_endpoint_returns_matches(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('inventory.audits.search_catalog', [
            'q' => '7501234567890',
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.sku', 'TINTE-ROJO-01');
    }
}
