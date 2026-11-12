<?php

namespace Tests\Feature;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveBranchCapability;
use App\Models\AutomotiveBranchMembership;
use App\Models\AutomotiveInventoryBalance;
use App\Models\AutomotiveInventoryMovement;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartBranchSetting;
use App\Models\AutomotiveReceipt;
use App\Models\AutomotiveReceiptItem;
use App\Models\User;
use App\Services\Autopartes\AutomotiveBarcodeLookupService;
use App\Services\Autopartes\AutomotiveReceiptService;
use App\Services\Autopartes\BranchCatalogAccess;
use App\Support\AutopartesConnectionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutopartesMobileAndGunScanningTest extends TestCase
{
    /** @var list<string> */
    private const CORE_ONLY_MIGRATIONS = [
        '0001_01_01_000000_create_users_table.php',
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
        '2025_09_02_075243_add_two_factor_columns_to_users_table.php',
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

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpIsolatedDualDatabases();

        config()->set('autopartes.enabled', true);
        config()->set('autopartes.controllers_enabled', true);
        config()->set('autopartes.max_receipt_item_quantity', 5000);

        $this->hmo = $this->hmoBranch();
        $this->hmoLocation = AutomotiveLocation::where('code', 'HMO-REC-01')->firstOrFail();

        // Usuario ID 4: Permisos de consulta y borrador de recepción, SIN confirmación de recepción ni ventas
        $this->user4 = User::factory()->create([
            'id' => 4,
            'name' => 'Operador Recepción HMO',
            'email' => 'operador4@autopartes.test',
            'role' => User::ROLE_ADMIN,
        ]);
        $this->grantAutomotiveCapabilities($this->user4, $this->hmo, [
            BranchCatalogAccess::CATALOG_VIEW,
            BranchCatalogAccess::STOCK_VIEW,
            BranchCatalogAccess::RECEIPT_DRAFT,
        ]);

        // Usuario Administrador: Con permiso explícito para confirmar recepciones
        $this->adminUser = User::factory()->create([
            'name' => 'Administrador HMO',
            'email' => 'admin@autopartes.test',
            'role' => User::ROLE_ADMIN,
        ]);
        $this->grantAutomotiveCapabilities($this->adminUser, $this->hmo, [
            BranchCatalogAccess::CATALOG_VIEW,
            BranchCatalogAccess::STOCK_VIEW,
            BranchCatalogAccess::RECEIPT_DRAFT,
            BranchCatalogAccess::RECEIPT_CONFIRM,
            BranchCatalogAccess::IDENTITY_RESOLVE,
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

        $this->assertTrue(Schema::connection('autopartes')->hasTable('automotive_parts'));
        $this->assertTrue(Schema::connection('autopartes')->hasTable('automotive_inventory_balances'));
        $this->assertFalse(Schema::connection('sqlite')->hasTable('automotive_parts'));
        $this->assertFalse(Schema::connection('autopartes')->hasTable('inventory_products'));
    }

    protected function tearDownIsolatedDualDatabases(): void
    {
        DB::purge('autopartes');
        DB::purge('sqlite');
    }

    protected function hmoBranch(): AutomotiveBranch
    {
        return AutomotiveBranch::query()->where('code', 'HMO')->firstOrFail();
    }

    /**
     * @param list<string> $capabilities
     */
    protected function grantAutomotiveCapabilities(
        User $user,
        AutomotiveBranch $branch,
        array $capabilities,
    ): AutomotiveBranchMembership {
        $membership = AutomotiveBranchMembership::query()->firstOrCreate(
            [
                'branch_id' => $branch->getKey(),
                'user_id' => $user->getKey(),
            ],
            [
                'is_active' => true,
            ]
        );

        foreach ($capabilities as $capability) {
            AutomotiveBranchCapability::query()->firstOrCreate([
                'branch_user_id' => $membership->getKey(),
                'capability' => $capability,
            ]);
        }

        return $membership;
    }

    public function test_lookup_preserves_leading_zeros_and_exact_barcode_matching(): void
    {
        $partLeadingZero = AutomotivePart::create([
            'code' => 'AP-029-0999',
            'item_number' => '029-0999',
            'manufacturer_part_number' => 'MPN-MONROE-01',
            'verified_barcode' => '029-0999',
            'description' => 'Amortiguador delantero gas con ceros iniciales',
            'vendor_name' => 'Monroe',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $partZeroBarcode = AutomotivePart::create([
            'code' => 'AP-UPC-00123',
            'item_number' => 'ITM-00123',
            'manufacturer_part_number' => 'MPN-00123',
            'verified_barcode' => '001234567890',
            'description' => 'Filtro de aire con UPC de 12 dígitos iniciando en doble cero',
            'vendor_name' => 'Fram',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        // Consulta 1: Código '029-0999' conservando el cero inicial
        $response1 = $this->actingAs($this->user4)
            ->postJson('/autopartes/HMO/entradas/buscar-codigo', ['code' => '029-0999']);

        $response1->assertOk()
            ->assertJson([
                'scanned_code' => '029-0999',
                'match_status' => AutomotiveBarcodeLookupService::MATCH_EXACT_VERIFIED,
                'requires_disambiguation' => false,
                'candidate' => [
                    'automotive_part_id' => $partLeadingZero->id,
                    'verified_barcode' => '029-0999',
                    'item_number' => '029-0999',
                    'is_receivable' => true,
                ],
            ]);

        // Consulta 2: Código '001234567890' conservando los dobles ceros
        $response2 = $this->actingAs($this->user4)
            ->postJson('/autopartes/HMO/entradas/buscar-codigo', ['code' => '001234567890']);

        $response2->assertOk()
            ->assertJson([
                'scanned_code' => '001234567890',
                'match_status' => AutomotiveBarcodeLookupService::MATCH_EXACT_VERIFIED,
                'requires_disambiguation' => false,
                'candidate' => [
                    'automotive_part_id' => $partZeroBarcode->id,
                    'verified_barcode' => '001234567890',
                ],
            ]);
    }

    public function test_lookup_with_ambiguous_matches_requires_disambiguation_and_never_auto_selects(): void
    {
        $partA = AutomotivePart::create([
            'code' => 'AP-COLLISION-A',
            'item_number' => 'SHARED-CODE-99',
            'manufacturer_part_number' => 'MPN-A',
            'description' => 'Balata delantera izquierda',
            'vendor_name' => 'Wagner',
            'identity_status' => AutomotivePart::STATUS_PROVISIONAL,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $partB = AutomotivePart::create([
            'code' => 'AP-COLLISION-B',
            'item_number' => 'SHARED-CODE-99',
            'manufacturer_part_number' => 'MPN-B',
            'description' => 'Balata trasera derecha',
            'vendor_name' => 'Wagner',
            'identity_status' => AutomotivePart::STATUS_PROVISIONAL,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user4)
            ->postJson('/autopartes/HMO/entradas/buscar-codigo', ['code' => 'SHARED-CODE-99']);

        $response->assertOk()
            ->assertJson([
                'scanned_code' => 'SHARED-CODE-99',
                'match_status' => AutomotiveBarcodeLookupService::MATCH_AMBIGUOUS,
                'requires_disambiguation' => true,
                'candidate' => null, // NUNCA selecciona automáticamente el primero
            ]);

        $data = $response->json();
        $this->assertCount(2, $data['candidates']);
    }

    public function test_lookup_with_ambiguous_identity_blocks_receiving_and_flags_restriction(): void
    {
        // Una de las 70 identidades ambiguas protegidas
        $ambiguousPart = AutomotivePart::create([
            'code' => 'AP-AMBIGUOUS-70',
            'item_number' => 'AMB-70-ITEM',
            'manufacturer_part_number' => 'AMB-70-MPN',
            'description' => 'Sensor de oxígeno con identidad no resuelta',
            'vendor_name' => 'Bosch',
            'identity_status' => AutomotivePart::STATUS_AMBIGUOUS,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user4)
            ->postJson('/autopartes/HMO/entradas/buscar-codigo', ['code' => 'AMB-70-ITEM']);

        $response->assertOk()
            ->assertJson([
                'scanned_code' => 'AMB-70-ITEM',
                'match_status' => AutomotiveBarcodeLookupService::MATCH_AMBIGUOUS,
                'requires_disambiguation' => true,
                'candidate' => null, // Bloqueado para recepción directa
            ]);

        $data = $response->json();
        $this->assertFalse($data['candidates'][0]['is_receivable']);
        $this->assertSame(AutomotivePart::STATUS_AMBIGUOUS, $data['candidates'][0]['identity_status']);
    }

    public function test_add_item_to_draft_via_modal_does_not_generate_inventory_movements_or_balances(): void
    {
        $part = AutomotivePart::create([
            'code' => 'AP-RECEIVABLE-01',
            'item_number' => 'RCV-01',
            'manufacturer_part_number' => 'MPN-RCV-01',
            'verified_barcode' => '750123456789',
            'description' => 'Bujía Iridium',
            'vendor_name' => 'NGK',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $receiptService = app(AutomotiveReceiptService::class);
        $receipt = $receiptService->createDraft($this->user4, $this->hmo, [
            'automotive_location_id' => $this->hmoLocation->id,
            'notes' => 'Recepción de prueba R6.57',
        ]);

        $response = $this->actingAs($this->user4)
            ->post("/autopartes/HMO/entradas/{$receipt->id}/partidas", [
                'automotive_part_id' => $part->id,
                'scan_code' => '750123456789',
                'quantity' => 24, // Incremento rápido e.g. +24
                'entry_mode' => 'scan',
                'notes' => 'Caja de 24 bujías contada con pistola HID',
            ]);

        $response->assertRedirect();

        // 1. Verificar que la partida se creó en borrador
        $item = AutomotiveReceiptItem::where('automotive_receipt_id', $receipt->id)->first();
        $this->assertNotNull($item);
        $this->assertSame($part->id, $item->automotive_part_id);
        $this->assertSame(24, $item->quantity);
        $this->assertSame('750123456789', $item->scanned_code);
        $this->assertSame('scan', $item->entry_mode);

        // 2. SEGURIDAD DE INVENTARIO: 0 movimientos y 0 balances físicos
        $this->assertSame(0, AutomotiveInventoryMovement::count(), 'Agregar al borrador NO debe crear movimientos físicos.');
        $this->assertSame(0, AutomotiveInventoryBalance::count(), 'Agregar al borrador NO debe alterar balances físicos.');

        // 3. Ningún inventario ajeno fue tocado
        $this->assertSame(0, DB::connection('sqlite')->table('inventory_movements')->count());
        $this->assertSame(0, DB::connection('sqlite')->table('pos_sales')->count());
    }

    public function test_direct_add_item_with_ambiguous_part_is_strictly_rejected_with_422(): void
    {
        $ambiguousPart = AutomotivePart::create([
            'code' => 'AP-AMBIGUOUS-DIRECT',
            'item_number' => 'AMB-DIR-01',
            'description' => 'Autoparte ambigua bloqueada en servidor',
            'identity_status' => AutomotivePart::STATUS_AMBIGUOUS,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $receiptService = app(AutomotiveReceiptService::class);
        $receipt = $receiptService->createDraft($this->user4, $this->hmo, [
            'automotive_location_id' => $this->hmoLocation->id,
        ]);

        // Intento directo por JSON
        $responseJson = $this->actingAs($this->user4)
            ->postJson("/autopartes/HMO/entradas/{$receipt->id}/partidas", [
                'automotive_part_id' => $ambiguousPart->id,
                'quantity' => 1,
            ]);

        $responseJson->assertStatus(422)
            ->assertJsonValidationErrors(['automotive_part_id']);

        $this->assertSame(0, AutomotiveReceiptItem::where('automotive_receipt_id', $receipt->id)->count());
    }

    public function test_add_item_rejects_zero_negative_float_and_overflow_quantities(): void
    {
        $part = AutomotivePart::create([
            'code' => 'AP-QTY-TEST',
            'description' => 'Prueba de validación de cantidades',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $receiptService = app(AutomotiveReceiptService::class);
        $receipt = $receiptService->createDraft($this->user4, $this->hmo, [
            'automotive_location_id' => $this->hmoLocation->id,
        ]);

        $invalidQuantities = [0, -1, 3.5, 99999];

        foreach ($invalidQuantities as $qty) {
            $response = $this->actingAs($this->user4)
                ->postJson("/autopartes/HMO/entradas/{$receipt->id}/partidas", [
                    'automotive_part_id' => $part->id,
                    'quantity' => $qty,
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);
        }

        $this->assertSame(0, AutomotiveReceiptItem::count());
    }

    public function test_user_id_4_is_prohibited_from_confirming_reception_without_permission(): void
    {
        $part = AutomotivePart::create([
            'code' => 'AP-CONF-TEST',
            'description' => 'Pieza para prueba de confirmación de recepción',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $receiptService = app(AutomotiveReceiptService::class);
        $receipt = $receiptService->createDraft($this->user4, $this->hmo, [
            'automotive_location_id' => $this->hmoLocation->id,
        ]);

        $receiptService->addItem($this->user4, $this->hmo, $receipt, [
            'automotive_part_id' => $part->id,
            'quantity' => 5,
        ]);

        // Usuario ID 4 intenta confirmar recepción
        $response = $this->actingAs($this->user4)
            ->post("/autopartes/HMO/entradas/{$receipt->id}/confirmar", [
                'confirmed' => true,
                'idempotency_key' => 'idemp-user4-test',
            ]);

        // Debe ser 403 Forbidden porque no tiene RECEIPT_CONFIRM
        $response->assertForbidden();

        // El borrador sigue en draft y no hay movimientos
        $this->assertSame(AutomotiveReceipt::STATUS_DRAFT, $receipt->fresh()->status);
        $this->assertSame(0, AutomotiveInventoryMovement::count());
    }

    public function test_authorized_user_can_confirm_reception_idempotently(): void
    {
        $part = AutomotivePart::create([
            'code' => 'AP-ADMIN-CONFIRM',
            'description' => 'Pieza confirmada por administrador',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $receiptService = app(AutomotiveReceiptService::class);
        $receipt = $receiptService->createDraft($this->user4, $this->hmo, [
            'automotive_location_id' => $this->hmoLocation->id,
        ]);

        $receiptService->addItem($this->user4, $this->hmo, $receipt, [
            'automotive_part_id' => $part->id,
            'quantity' => 10,
        ]);

        // Administrador confirma la recepción
        $response = $this->actingAs($this->adminUser)
            ->post("/autopartes/HMO/entradas/{$receipt->id}/confirmar", [
                'confirmed' => true,
                'idempotency_key' => 'idemp-admin-confirm-01',
            ]);

        $response->assertRedirect(route('autopartes.receipts.show', ['HMO', $receipt->id]));

        // Estado confirmado y 1 movimiento de entrada registrado
        $this->assertSame(AutomotiveReceipt::STATUS_CONFIRMED, $receipt->fresh()->status);
        $this->assertSame(1, AutomotiveInventoryMovement::count());
        $this->assertSame(10, (int) AutomotiveInventoryMovement::first()->quantity);

        // Reintento con la misma clave de idempotencia
        $retryResponse = $this->actingAs($this->adminUser)
            ->post("/autopartes/HMO/entradas/{$receipt->id}/confirmar", [
                'confirmed' => true,
                'idempotency_key' => 'idemp-admin-confirm-01',
            ]);

        $retryResponse->assertRedirect();
        $this->assertSame(1, AutomotiveInventoryMovement::count(), 'Idempotencia: no debe duplicar movimientos.');
    }

    public function test_commercial_sales_and_pos_remain_strictly_blocked(): void
    {
        // 1. Con controladores habilitados en test, el usuario de recepción no tiene permisos de venta (403 Forbidden)
        $responseForbidden = $this->actingAs($this->user4)
            ->get('/autopartes/HMO/pos');

        $responseForbidden->assertForbidden();

        // 2. Con política de producción (controllers_enabled = false), el acceso interactivo general queda en 503
        config()->set('autopartes.controllers_enabled', false);
        $response503 = $this->actingAs($this->adminUser)
            ->get('/autopartes/HMO/pos');

        $response503->assertStatus(503);
    }

    public function test_receipt_item_strictly_inherits_receipt_real_location_ignoring_any_external_input(): void
    {
        $part = AutomotivePart::create([
            'code' => 'AP-LOC-BIND-TEST',
            'description' => 'Prueba de enlace a ubicación real de recepción',
            'identity_status' => AutomotivePart::STATUS_VERIFIED,
            'allow_receipt' => true,
            'is_active' => true,
        ]);

        $receiptService = app(AutomotiveReceiptService::class);
        $receipt = $receiptService->createDraft($this->user4, $this->hmo, [
            'automotive_location_id' => $this->hmoLocation->id,
        ]);

        $this->assertSame('HMO-REC-01', $receipt->location->code);

        // Envío intentando inyectar una ubicación ajena no autorizada
        $response = $this->actingAs($this->user4)
            ->post("/autopartes/HMO/entradas/{$receipt->id}/partidas", [
                'automotive_part_id' => $part->id,
                'quantity' => 2,
                'automotive_location_id' => 99999, // Debe ser ignorado por el backend
            ]);

        $response->assertRedirect();

        // La configuración de la pieza y la recepción están firmemente ligadas a HMO-REC-01
        $setting = AutomotivePartBranchSetting::where('automotive_part_id', $part->id)
            ->where('branch_id', $this->hmo->id)
            ->first();

        $this->assertNotNull($setting);
        $this->assertSame($this->hmoLocation->id, $setting->primary_location_id);

        // La vista de la recepción renderiza exclusivamente la ubicación real HMO-REC-01
        $showResponse = $this->actingAs($this->user4)
            ->get("/autopartes/HMO/entradas/{$receipt->id}");

        $showResponse->assertOk()
            ->assertSee('HMO-REC-01');
        $this->assertSame('HMO-REC-01', $receipt->fresh()->location->code);
    }
}
