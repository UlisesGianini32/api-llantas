<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Services\Inventory\Igora\IgoraMasterBootstrapPreviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryIgoraMasterBootstrapPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
        Http::fake();
    }

    public function test_validates_all_six_references_exact_89_units_and_four_confirmed_locations(): void
    {
        $this->seedConfirmedLocations();

        $report = app(IgoraMasterBootstrapPreviewService::class)->preview();

        $this->assertSame('READ_ONLY_PREVIEW', $report['mode']);
        $this->assertSame('IGORA-ROYAL-MASTER-BOOTSTRAP-2026-11-19', $report['proposal']['proposal_id']);
        $this->assertSame('2026-11-19', $report['proposal']['physical_count_date']);
        $this->assertSame('2026-10-27', $report['proposal']['meli_snapshot_reference_date']);
        $this->assertFalse($report['proposal']['writes_executed']);
        $this->assertFalse($report['proposal']['external_http_calls']);
        $this->assertFalse($report['proposal']['uses_remote_meli_stock']);
        $this->assertFalse($report['proposal']['invents_commercial_fields']);

        $this->assertSame(6, $report['summary']['total_products']);
        $this->assertSame(89, $report['summary']['total_physical_units']);
        $this->assertTrue($report['summary']['totals_match_expected']);
        $this->assertSame(4, $report['summary']['locations_expected']);
        $this->assertSame(4, $report['summary']['locations_valid']);
        $this->assertSame(0, $report['summary']['locations_invalid']);
        $this->assertSame(6, $report['summary']['ready_for_manual_approval']);
        $this->assertSame(0, $report['summary']['duplicate_or_existing_product']);
        $this->assertSame(0, $report['summary']['location_error']);
        $this->assertSame(0, $report['summary']['manual_review_required']);
        $this->assertTrue($report['summary']['all_ready_for_manual_approval']);

        $expected = [
            '4-13' => ['sku' => '7702045538977', 'qty' => 11, 'loc_id' => 342, 'loc_code' => 'D3-7', 'mlm' => 'MLM2603516345'],
            '4-46' => ['sku' => '7702045552393', 'qty' => 12, 'loc_id' => 342, 'loc_code' => 'D3-7', 'mlm' => 'MLM2603567103'],
            '4-63' => ['sku' => '7702045538946', 'qty' => 14, 'loc_id' => 351, 'loc_code' => 'D4-7', 'mlm' => 'MLM4465266682'],
            '7-24' => ['sku' => '7702045119718', 'qty' => 10, 'loc_id' => 343, 'loc_code' => 'D3-8', 'mlm' => 'MLM2603541111'],
            '7-50' => ['sku' => '7702045548860', 'qty' => 28, 'loc_id' => 343, 'loc_code' => 'D3-8', 'mlm' => 'MLM2603554113'],
            '8-50' => ['sku' => '7702045548877', 'qty' => 14, 'loc_id' => 361, 'loc_code' => 'D5-8', 'mlm' => 'MLM2603516353'],
        ];

        $itemsByTone = collect($report['items'])->keyBy('tone');
        $this->assertCount(6, $itemsByTone);
        $this->assertSame(89, (int) $itemsByTone->sum('physical_count'));

        foreach ($expected as $tone => $spec) {
            $item = $itemsByTone->get($tone);
            $this->assertNotNull($item, "Falta tono {$tone}");
            $this->assertSame($spec['sku'], $item['sku']);
            $this->assertSame($spec['sku'], $item['barcode']);
            $this->assertSame($spec['qty'], $item['physical_count']);
            $this->assertSame($spec['loc_id'], $item['location_id']);
            $this->assertSame($spec['loc_code'], $item['location_code']);
            $this->assertSame($spec['mlm'], $item['audit_mlm']);
            $this->assertSame(InventoryProduct::SIMPLE, $item['product_type']);
            $this->assertSame('Schwarzkopf', $item['brand']);
            $this->assertSame('Igora Royal', $item['line']);
            $this->assertFalse($item['includes_oxidant']);
            $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_READY, $item['status']);
            $this->assertSame([], $item['reasons']);

            // Confirmar que no se inventan costos, precios ni proveedores
            $payload = $item['proposed_product_payload'];
            $this->assertNull($payload['cost']);
            $this->assertNull($payload['supplier']);
            $this->assertNull($payload['price_mercado_libre']);
            $this->assertNull($payload['price_amazon']);
            $this->assertNull($payload['price_stylist']);
            $this->assertNull($payload['price_public']);
        }

        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_channel_links', 0);
        Http::assertNothingSent();
    }

    public function test_blocks_with_location_error_when_location_is_missing_inactive_or_id_code_mismatch(): void
    {
        // 342 existe con código D3-7 pero INACTIVA -> bloquea 4-13 y 4-46
        $this->insertLocation(342, 'D3-7', 'Estante D3-7', isActive: false);
        // 343 existe, código D3-8 y ACTIVA -> permite 7-24 y 7-50
        $this->insertLocation(343, 'D3-8', 'Estante D3-8', isActive: true);
        // 351 existe pero con código distinto (Z9-9 != D4-7) -> bloquea 4-63
        $this->insertLocation(351, 'Z9-9', 'Estante equivocado', isActive: true);
        // 361 NO existe, pero D5-8 existe en otro ID (999) -> bloquea 8-50
        $this->insertLocation(999, 'D5-8', 'Estante D5-8 con otro ID', isActive: true);

        $report = app(IgoraMasterBootstrapPreviewService::class)->preview();
        $items = collect($report['items'])->keyBy('tone');
        $locations = collect($report['locations'])->keyBy('expected_id');

        $this->assertFalse($report['summary']['all_ready_for_manual_approval']);
        $this->assertSame(1, $report['summary']['locations_valid']);
        $this->assertSame(3, $report['summary']['locations_invalid']);
        $this->assertSame(4, $report['summary']['location_error']);
        $this->assertSame(2, $report['summary']['ready_for_manual_approval']);

        // Ubicación 342 inactiva
        $this->assertFalse($locations[342]['is_valid']);
        $this->assertContains('LOCATION_INACTIVE', $locations[342]['errors']);
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_LOCATION_ERROR, $items['4-13']['status']);
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_LOCATION_ERROR, $items['4-46']['status']);

        // Ubicación 351 con código distinto
        $this->assertFalse($locations[351]['is_valid']);
        $this->assertContains('LOCATION_CODE_MISMATCH:expected=D4-7,actual=Z9-9', $locations[351]['errors']);
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_LOCATION_ERROR, $items['4-63']['status']);

        // Ubicación 361 faltante (y D5-8 encontrada en ID 999)
        $this->assertFalse($locations[361]['is_valid']);
        $this->assertContains('LOCATION_NOT_FOUND', $locations[361]['errors']);
        $this->assertContains('LOCATION_CODE_EXISTS_WITH_DIFFERENT_ID:D5-8->#999', $locations[361]['errors']);
        $this->assertSame(999, $locations[361]['code_found_at_other_id']);
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_LOCATION_ERROR, $items['8-50']['status']);

        // Ubicación 343 válida
        $this->assertTrue($locations[343]['is_valid']);
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_READY, $items['7-24']['status']);
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_READY, $items['7-50']['status']);

        Http::assertNothingSent();
    }

    public function test_detects_duplicates_by_sku_primary_barcode_and_secondary_barcode_including_inactive_products(): void
    {
        $this->seedConfirmedLocations();

        // Duplicado por SKU (4-13)
        $dupSku = InventoryProduct::create([
            'sku' => '7702045538977',
            'name' => 'Tinte Schwarzkopf Igora Royal 4-13',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 342,
            'is_active' => true,
        ]);

        // Duplicado por barcode principal en producto INACTIVO (4-46)
        $dupBarcode = InventoryProduct::create([
            'sku' => 'INT-IGORA-446',
            'barcode' => '7702045552393',
            'name' => 'Tinte Igora Royal 4-46 (inactivo)',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 342,
            'is_active' => false,
        ]);

        // Duplicado por barcode_secondary (4-63)
        $dupSecondary = InventoryProduct::create([
            'sku' => 'INT-IGORA-463',
            'barcode' => '9999999999999',
            'barcode_secondary' => '7702045538946',
            'name' => 'Tinte Igora Royal 4-63',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 351,
            'is_active' => true,
        ]);

        $report = app(IgoraMasterBootstrapPreviewService::class)->preview();
        $items = collect($report['items'])->keyBy('tone');

        $this->assertSame(3, $report['summary']['duplicate_or_existing_product']);
        $this->assertSame(3, $report['summary']['ready_for_manual_approval']);
        $this->assertFalse($report['summary']['all_ready_for_manual_approval']);

        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_DUPLICATE, $items['4-13']['status']);
        $this->assertSame($dupSku->id, $items['4-13']['exact_code_matches'][0]['id']);
        $this->assertContains('sku', $items['4-13']['exact_code_matches'][0]['matched_by']);

        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_DUPLICATE, $items['4-46']['status']);
        $this->assertSame($dupBarcode->id, $items['4-46']['exact_code_matches'][0]['id']);
        $this->assertContains('barcode', $items['4-46']['exact_code_matches'][0]['matched_by']);
        $this->assertFalse($items['4-46']['exact_code_matches'][0]['is_active']);

        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_DUPLICATE, $items['4-63']['status']);
        $this->assertSame($dupSecondary->id, $items['4-63']['exact_code_matches'][0]['id']);
        $this->assertContains('barcode_secondary', $items['4-63']['exact_code_matches'][0]['matched_by']);

        foreach (['7-24', '7-50', '8-50'] as $readyTone) {
            $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_READY, $items[$readyTone]['status']);
        }

        Http::assertNothingSent();
    }

    public function test_flags_manual_review_for_name_line_tone_identity_match_without_false_positive_on_vibrance(): void
    {
        $this->seedConfirmedLocations();

        // Posible duplicado de identidad por nombre/línea/tono con SKU interno diferente (7-24)
        $possibleMatch = InventoryProduct::create([
            'sku' => 'IG-ROYAL-INT-724',
            'name' => 'Tinte Schwarzkopf Igora Royal 7/24 Rubio Medio 60ml',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 343,
            'is_active' => true,
        ]);

        // Otra línea (Igora Vibrance) con tono 7-50: NO debe bloquear el alta de Igora Royal 7-50
        InventoryProduct::create([
            'sku' => 'IG-VIB-750',
            'name' => 'Tinte Schwarzkopf Igora Vibrance 7-50 60ml',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 343,
            'is_active' => true,
        ]);

        $report = app(IgoraMasterBootstrapPreviewService::class)->preview();
        $items = collect($report['items'])->keyBy('tone');

        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_MANUAL_REVIEW, $items['7-24']['status']);
        $this->assertContains('POSSIBLE_IDENTITY_MATCH_BY_NAME_LINE_TONE', $items['7-24']['reasons']);
        $this->assertCount(1, $items['7-24']['identity_review_matches']);
        $this->assertSame($possibleMatch->id, $items['7-24']['identity_review_matches'][0]['id']);

        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_READY, $items['7-50']['status']);
        $this->assertSame([], $items['7-50']['identity_review_matches']);

        Http::assertNothingSent();
    }

    public function test_detects_existing_inventory_channel_links_for_audit_mlms_without_modifying_them(): void
    {
        $this->seedConfirmedLocations();

        $otherProduct = InventoryProduct::create([
            'sku' => 'OTHER-SKU-001',
            'name' => 'Producto previo genérico',
            'brand' => 'OTRA',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 361,
            'is_active' => true,
        ]);

        $link = InventoryChannelLink::create([
            'inventory_product_id' => $otherProduct->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '1',
            'external_product_id' => 'MLM2603516353',
            'external_listing_id' => 'MLM2603516353',
            'identity_key' => 'mercado_libre|1|MLM2603516353|',
            'is_active' => true,
            'stock_sync_enabled' => false,
            'order_reservation_enabled' => false,
        ]);

        $report = app(IgoraMasterBootstrapPreviewService::class)->preview();
        $items = collect($report['items'])->keyBy('tone');

        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_MANUAL_REVIEW, $items['8-50']['status']);
        $this->assertContains('EXISTING_CHANNEL_LINK_FOR_MLM', $items['8-50']['reasons']);
        $this->assertCount(1, $items['8-50']['existing_channel_links']);
        $this->assertSame($link->id, $items['8-50']['existing_channel_links'][0]['id']);

        // El vínculo no fue alterado ni activado
        $link->refresh();
        $this->assertFalse($link->stock_sync_enabled);
        $this->assertFalse($link->order_reservation_enabled);
        $this->assertDatabaseCount('inventory_channel_links', 1);
        Http::assertNothingSent();
    }

    public function test_detects_previously_registered_product_and_existing_initial_movement_without_assuming_zero_stock(): void
    {
        $this->seedConfirmedLocations();

        // 1) Producto previamente registrado (7-50) con movimiento INITIAL y venta posterior (stock en ledger = 13)
        $existing750 = InventoryProduct::create([
            'sku' => '7702045548860',
            'barcode' => '7702045548860',
            'name' => 'Tinte Schwarzkopf Igora Royal 7-50 60 ml',
            'brand' => 'Schwarzkopf',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 343,
            'is_active' => true,
        ]);

        $initialMov = InventoryMovement::create([
            'inventory_product_id' => $existing750->id,
            'inventory_location_id' => 343,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 15,
            'reference' => 'CONTEO-PREVIO-7-50',
            'occurred_at' => now()->subDays(5),
        ]);

        InventoryMovement::create([
            'inventory_product_id' => $existing750->id,
            'inventory_location_id' => 343,
            'type' => InventoryMovement::SALE,
            'quantity' => -2,
            'reference' => 'VENTA-TEST-1',
            'occurred_at' => now()->subDays(2),
        ]);

        // 2) Movimiento INITIAL en otro producto pero referenciando el SKU de 4-13
        $unrelated = InventoryProduct::create([
            'sku' => 'TMP-MANUAL-001',
            'name' => 'Registro temporal almacén',
            'brand' => 'VARIOS',
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => 342,
            'is_active' => true,
        ]);

        $refInitialMov = InventoryMovement::create([
            'inventory_product_id' => $unrelated->id,
            'inventory_location_id' => 342,
            'type' => InventoryMovement::INITIAL,
            'quantity' => 11,
            'reference' => 'CARGA-INICIAL-7702045538977',
            'occurred_at' => now()->subDay(),
        ]);

        $report = app(IgoraMasterBootstrapPreviewService::class)->preview();
        $items = collect($report['items'])->keyBy('tone');

        // 7-50: duplicado + movimiento INITIAL detectado + stock físico real (13), nunca asumido 0
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_DUPLICATE, $items['7-50']['status']);
        $this->assertContains('DUPLICATE_SKU_OR_BARCODE_IN_INVENTORY', $items['7-50']['reasons']);
        $this->assertContains('EXISTING_INITIAL_MOVEMENT_DETECTED', $items['7-50']['reasons']);
        $this->assertCount(1, $items['7-50']['existing_initial_movements']);
        $this->assertSame($initialMov->id, $items['7-50']['existing_initial_movements'][0]['id']);
        $this->assertSame(15, $items['7-50']['existing_initial_movements'][0]['quantity']);
        $this->assertSame(2, $items['7-50']['existing_movements_count']);
        $this->assertSame(13, $items['7-50']['exact_code_matches'][0]['ledger_physical_stock']);

        // 4-13: movimiento INITIAL relacionado por referencia -> MANUAL_REVIEW_REQUIRED
        $this->assertSame(IgoraMasterBootstrapPreviewService::STATUS_MANUAL_REVIEW, $items['4-13']['status']);
        $this->assertContains('EXISTING_INITIAL_MOVEMENT_DETECTED', $items['4-13']['reasons']);
        $this->assertCount(1, $items['4-13']['existing_initial_movements']);
        $this->assertSame($refInitialMov->id, $items['4-13']['existing_initial_movements'][0]['id']);

        // Ninguna escritura adicional
        $this->assertDatabaseCount('inventory_products', 2);
        $this->assertDatabaseCount('inventory_movements', 3);
        Http::assertNothingSent();
    }

    public function test_repeated_artisan_command_runs_are_strictly_read_only_without_apply_option_or_http_calls(): void
    {
        $this->seedConfirmedLocations();

        // Confirmar que el comando NO expone opción --apply en este PR
        $commands = Artisan::all();
        $this->assertArrayHasKey('inventory:igora-master-preview', $commands);
        $definition = $commands['inventory:igora-master-preview']->getDefinition();
        $this->assertFalse($definition->hasOption('apply'));

        $locationsBefore = DB::table('inventory_locations')->count();

        // Ejecución 1: tabla legible
        $exitTable = Artisan::call('inventory:igora-master-preview');
        $this->assertSame(0, $exitTable);
        $tableOutput = Artisan::output();

        $this->assertStringContainsString('SOLO LECTURA', $tableOutput);
        $this->assertStringContainsString('2026-11-19', $tableOutput);
        $this->assertStringContainsString('89', $tableOutput);
        $this->assertStringContainsString('READY_FOR_MANUAL_APPROVAL', $tableOutput);
        $this->assertStringContainsString('D3-7', $tableOutput);
        $this->assertStringContainsString('D3-8', $tableOutput);
        $this->assertStringContainsString('D4-7', $tableOutput);
        $this->assertStringContainsString('D5-8', $tableOutput);
        $this->assertStringContainsString('reconfirmar los conteos físicos del 2026-11-19', mb_strtolower($tableOutput, 'UTF-8'));

        // Ejecución 2 y 3 repetidas en JSON: salida determinista e idempotente sin escrituras
        $exitJson1 = Artisan::call('inventory:igora-master-preview', ['--json' => true]);
        $this->assertSame(0, $exitJson1);
        $decoded1 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $exitJson2 = Artisan::call('inventory:igora-master-preview', ['--json' => true]);
        $this->assertSame(0, $exitJson2);
        $decoded2 = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($decoded1, $decoded2);
        $this->assertSame(6, $decoded1['summary']['total_products']);
        $this->assertSame(89, $decoded1['summary']['total_physical_units']);
        $this->assertTrue($decoded1['summary']['all_ready_for_manual_approval']);

        // Cero escrituras en tablas de producción y cero llamadas HTTP
        $this->assertDatabaseCount('inventory_locations', $locationsBefore);
        $this->assertDatabaseCount('inventory_products', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_kit_components', 0);
        Http::assertNothingSent();
    }

    private function seedConfirmedLocations(): void
    {
        $this->insertLocation(342, 'D3-7', 'Estante D3-7', true);
        $this->insertLocation(343, 'D3-8', 'Estante D3-8', true);
        $this->insertLocation(351, 'D4-7', 'Estante D4-7', true);
        $this->insertLocation(361, 'D5-8', 'Estante D5-8', true);
    }

    private function insertLocation(int $id, string $code, string $name, bool $isActive): void
    {
        DB::table('inventory_locations')->insert([
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'is_active' => $isActive,
            'sort_order' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 32)->default('operations');
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_25_000001_create_inventory_channel_links_table.php'))->up();
        (require database_path('migrations/2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        if (! Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled')) {
            Schema::table('inventory_channel_links', function (Blueprint $table): void {
                $table->boolean('order_reservation_enabled')->default(false);
            });
        }
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_10_05_160000_add_secondary_location_and_amazon_locations.php'))->up();
        (require database_path('migrations/2026_10_07_183419_add_barcode_secondary_to_inventory_products_table.php'))->up();
    }
}
