<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryKitComponent;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Models\MeliFullStock;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\MeliSharedStockGroup;
use App\Models\MeliSharedStockMember;
use App\Models\User;
use App\Services\Inventory\Igora\IgoraGroupingAssistantService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryIgoraGroupingPreviewTest extends TestCase
{
    private User $user;

    private MeliAccount $accountOne;

    private MeliAccount $accountTwo;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        $this->createSchema();
        Http::fake();

        $this->user = User::create([
            'name' => 'Operaciones Test',
            'email' => 'ops@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->user->forceFill(['role' => User::ROLE_OPERATIONS])->save();

        $this->accountOne = MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => '1001001',
            'nickname' => 'CuentaPrincipal',
            'is_default' => true,
        ]);

        $this->accountTwo = MeliAccount::create([
            'user_id' => $this->user->id,
            'meli_user_id' => '2002002',
            'nickname' => 'CuentaSecundaria',
            'is_default' => false,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'E10-1',
            'name' => 'Anaquel Belleza',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_mandatory_case_igora_royal_0_22_groups_both_publications_without_activating_or_trusting_remote_stock(): void
    {
        $product022 = $this->createSimpleProduct(
            sku: '7702045539196',
            name: 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            brand: 'SCHWARZKOPF',
            stock: 6,
            barcode: '7702045539196',
        );

        $pubA = $this->createPublication($this->accountOne, 'MLM2597365385', '7702045539196', [
            'id' => 'MLM2597365385',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 Matizador Azul 60ml',
            'user_product_id' => 'MLMU3650099060',
            'available_quantity' => 1,
            'seller_custom_field' => '7702045539196',
            'status' => 'active',
        ]);
        $this->createPriceManagerItem($this->accountOne, 'MLM2597365385', [
            'sku' => '7702045539196',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 Matizador Azul 60ml',
            'user_product_id' => 'MLMU3650099060',
            'available_quantity' => 1,
            'meli_brand' => 'Schwarzkopf',
        ]);

        $pubB = $this->createPublication($this->accountOne, 'MLM2603503497', '7702045539196', [
            'id' => 'MLM2603503497',
            'title' => 'Igora Royal Tono 0-22 Corrector Cenizo Schwarzkopf',
            'user_product_id' => 'MLMU3659004632',
            'available_quantity' => 0,
            'seller_custom_field' => '7702045539196',
            'status' => 'active',
        ]);
        $this->createPriceManagerItem($this->accountOne, 'MLM2603503497', [
            'sku' => '7702045539196',
            'title' => 'Igora Royal Tono 0-22 Corrector Cenizo Schwarzkopf',
            'user_product_id' => 'MLMU3659004632',
            'available_quantity' => 0,
            'meli_brand' => 'Schwarzkopf',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();

        $this->assertTrue($preview['read_only']);
        $this->assertCount(2, $preview['rows']);
        $this->assertCount(1, $preview['groups']);

        $rowsByMlm = collect($preview['rows'])->keyBy('mlm');

        $rowA = $rowsByMlm['MLM2597365385'];
        $rowB = $rowsByMlm['MLM2603503497'];

        foreach ([$rowA, $rowB] as $row) {
            $this->assertSame(IgoraGroupingAssistantService::LINE_ROYAL, $row['line']);
            $this->assertSame('0-22', $row['normalized_tone']);
            $this->assertSame(IgoraGroupingAssistantService::PRES_SINGLE_DYE, $row['presentation_type']);
            $this->assertSame('IGORA_ROYAL|0-22|SIMPLE', $row['proposed_group_key']);
            $this->assertSame($product022->id, $row['proposed_inventory_product_id']);
            $this->assertSame(6, $row['local_physical_stock']);
            $this->assertSame(6, $row['local_available_stock']);
            $this->assertSame('inventory_ledger', $row['local_stock_source']);
            $this->assertNull($row['existing_channel_link_id']);
            $this->assertFalse($row['peroxide_info']['included']);
            $this->assertContains('INDEPENDENT_USER_PRODUCTS_IN_GROUP', $row['notes']);
            $this->assertSame('CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE', $row['recommended_action']);
        }

        // Las cantidades remotas se muestran separadas y nunca sustituyen el stock físico local.
        $this->assertSame(1, $rowA['remote_price_manager_stock']);
        $this->assertSame(0, $rowB['remote_price_manager_stock']);

        // No se activó sincronización ni se crearon vínculos ni kits.
        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_kit_components', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        Http::assertNothingSent();
    }

    public function test_same_tones_with_different_titles_and_slashes_normalize_without_confusing_ean_codes(): void
    {
        $this->createPublication($this->accountOne, 'MLM1001', '7702045539196', [
            'id' => 'MLM1001',
            'title' => 'Tinte Igora Royal 0/22 Matizador 60ml EAN 7702045539196',
            'available_quantity' => 3,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM1002', 'IGORA-951', [
            'id' => 'MLM1002',
            'title' => 'Schwarzkopf Igora Royal 9.5/1 Pastel Cenizo 60g',
            'available_quantity' => 5,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM1003', 'IGORA-10', [
            'id' => 'MLM1003',
            'title' => 'Tinte Permanente Igora Royal Tono 1-0 Negro Intenso',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertSame('0-22', $rows['MLM1001']['normalized_tone']);
        $this->assertSame('9.5-1', $rows['MLM1002']['normalized_tone']);
        $this->assertSame('1-0', $rows['MLM1003']['normalized_tone']);

        // Ordenamiento estricto por tono natural: 0-22 -> 1-0 -> 9.5-1
        $tonesOrdered = collect($preview['rows'])->pluck('normalized_tone')->all();
        $this->assertSame(['0-22', '1-0', '9.5-1'], $tonesOrdered);
    }

    public function test_variations_are_broken_down_into_individual_rows_with_distinct_skus_and_variation_ids(): void
    {
        $this->createSimpleProduct('SKU-777', 'Igora Royal 7-77', 'SCHWARZKOPF', 4);
        $this->createSimpleProduct('SKU-10', 'Igora Royal 1-0', 'SCHWARZKOPF', 9);

        $this->createPublication($this->accountOne, 'MLM2001', 'SKU-777', [
            'id' => 'MLM2001',
            'title' => 'Tinte Schwarzkopf Igora Royal Varios Tonos 60ml',
            'status' => 'active',
            'variations' => [
                [
                    'id' => 90001,
                    'available_quantity' => 2,
                    'seller_custom_field' => 'SKU-777',
                    'user_product_id' => 'MLMU-VAR-777',
                    'attribute_combinations' => [
                        ['id' => 'COLOR', 'name' => 'Tono', 'value_name' => '7-77'],
                    ],
                ],
                [
                    'id' => 90002,
                    'available_quantity' => 7,
                    'seller_custom_field' => 'SKU-10',
                    'user_product_id' => 'MLMU-VAR-10',
                    'attribute_combinations' => [
                        ['id' => 'COLOR', 'name' => 'Tono', 'value_name' => '1-0'],
                    ],
                ],
                [
                    'id' => 90003,
                    'available_quantity' => 1,
                    // Sin SKU propio: no debe heredar el SKU de la primera variante.
                    'attribute_combinations' => [
                        ['id' => 'COLOR', 'name' => 'Tono', 'value_name' => '0-22'],
                    ],
                ],
            ],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $this->assertCount(3, $preview['rows']);

        $byVar = collect($preview['rows'])->keyBy('variation_id');
        $this->assertSame('1-0', $byVar['90002']['normalized_tone']);
        $this->assertSame('SKU-10', $byVar['90002']['sku']);
        $this->assertSame('MLMU-VAR-10', $byVar['90002']['user_product_id']);
        $this->assertSame(9, $byVar['90002']['local_available_stock']);

        $this->assertSame('7-77', $byVar['90001']['normalized_tone']);
        $this->assertSame(4, $byVar['90001']['local_available_stock']);

        $this->assertSame('0-22', $byVar['90003']['normalized_tone']);
        $this->assertSame('', $byVar['90003']['sku']);
        $this->assertContains('MISSING_SKU', $byVar['90003']['conflicts']);
    }

    public function test_duplicate_sku_across_different_presentations_flags_conflict_and_does_not_infer_kit_by_sku(): void
    {
        $this->createSimpleProduct('7702045539196', 'Igora Royal 0-22 Tubo', 'SCHWARZKOPF', 5);

        $this->createPublication($this->accountOne, 'MLM3001', '7702045539196', [
            'id' => 'MLM3001',
            'title' => 'Tinte Igora Royal 0-22 Individual',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 5,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM3002', '7702045539196', [
            'id' => 'MLM3002',
            'title' => 'Kit Tinte Igora Royal 0-22 Con Peroxido 20 Vol 60ml',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 3,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertSame(IgoraGroupingAssistantService::PRES_SINGLE_DYE, $rows['MLM3001']['presentation_type']);
        $this->assertSame(IgoraGroupingAssistantService::PRES_DYE_WITH_PEROXIDE, $rows['MLM3002']['presentation_type']);
        $this->assertContains('DUPLICATE_SKU_CROSS_PRESENTATION', $rows['MLM3001']['conflicts']);
        $this->assertContains('DUPLICATE_SKU_CROSS_PRESENTATION', $rows['MLM3002']['conflicts']);
        $this->assertTrue($rows['MLM3002']['requires_human_review']);
        $this->assertSame('CONFIRMAR_COMPONENTES_KIT_CON_PEROXIDO', $rows['MLM3002']['recommended_action']);
    }

    public function test_shared_user_product_id_across_different_groups_is_flagged_as_conflict(): void
    {
        $this->createPublication($this->accountOne, 'MLM4001', 'SKU-A', [
            'id' => 'MLM4001',
            'title' => 'Igora Royal 0-22',
            'user_product_id' => 'MLMU-CONFLICT-1',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM4002', 'SKU-B', [
            'id' => 'MLM4002',
            'title' => 'Igora Royal 7-77',
            'user_product_id' => 'MLMU-CONFLICT-1',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertContains('USER_PRODUCT_SHARED_DIFFERENT_GROUPS', $rows['MLM4001']['conflicts']);
        $this->assertContains('USER_PRODUCT_SHARED_DIFFERENT_GROUPS', $rows['MLM4002']['conflicts']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $rows['MLM4001']['confidence']);
        $this->assertSame('RESOLVER_CONFLICTO_USER_PRODUCT', $rows['MLM4001']['recommended_action']);
    }

    public function test_paused_and_denounced_moderated_publications_are_discovered_and_flagged(): void
    {
        $this->createPublication($this->accountOne, 'MLM5001', 'SKU-PAUSED', [
            'id' => 'MLM5001',
            'title' => 'Igora Royal 1-0 Negro',
            'status' => 'paused',
            'sub_status' => ['out_of_stock'],
            'available_quantity' => 0,
        ], status: 'paused', subStatus: ['out_of_stock']);

        $this->createPublication($this->accountOne, 'MLM5002', 'SKU-DENOUNCED', [
            'id' => 'MLM5002',
            'title' => 'Igora Royal 7-77 Cobrizo',
            'status' => 'under_review',
            'sub_status' => ['forbidden'],
            'available_quantity' => 4,
        ], status: 'under_review', subStatus: ['forbidden'], extraRaw: ['moderations' => ['reason' => 'Denuncia de marca']]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertTrue($rows['MLM5001']['is_paused']);
        $this->assertContains('PUBLICATION_PAUSED', $rows['MLM5001']['conflicts']);

        $this->assertTrue($rows['MLM5002']['is_moderated_or_denounced']);
        $this->assertContains('PUBLICATION_MODERATED_OR_DENOUNCED', $rows['MLM5002']['conflicts']);
        $this->assertSame(2, $preview['counts']['total_rows']);
    }

    public function test_distinguishes_igora_royal_from_igora_vibrance_and_flags_mixed_titles(): void
    {
        $royal777 = $this->createSimpleProduct('ROY-777', 'Igora Royal 7-77 Cobrizo', 'SCHWARZKOPF', 8);
        $vibrance777 = $this->createSimpleProduct('VIB-777', 'Igora Vibrance 7-77 Demipermanente', 'SCHWARZKOPF', 3);

        $this->createPublication($this->accountOne, 'MLM6001', 'ROY-777', [
            'id' => 'MLM6001',
            'title' => 'Tinte Schwarzkopf Igora Royal 7-77 Cobrizo Intenso',
            'available_quantity' => 8,
            'status' => 'active',
        ]);

        // Caso de SKU de Royal puesto por error en publicación Vibrance, o línea Vibrance pura:
        $this->createPublication($this->accountOne, 'MLM6002', 'VIB-777', [
            'id' => 'MLM6002',
            'title' => 'Tinte Schwarzkopf Igora Vibrance 7-77 Sin Amoniaco',
            'available_quantity' => 3,
            'status' => 'active',
        ]);

        // Caso de confusión explícita: menciona Royal y Vibrance en el mismo título
        $this->createPublication($this->accountOne, 'MLM6003', 'MIX-777', [
            'id' => 'MLM6003',
            'title' => 'Tinte Igora Royal Vibrance 7-77 Schwarzkopf',
            'available_quantity' => 1,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertSame(IgoraGroupingAssistantService::LINE_ROYAL, $rows['MLM6001']['line']);
        $this->assertSame($royal777->id, $rows['MLM6001']['proposed_inventory_product_id']);
        $this->assertSame('IGORA_ROYAL|7-77|SIMPLE', $rows['MLM6001']['proposed_group_key']);

        $this->assertSame(IgoraGroupingAssistantService::LINE_VIBRANCE, $rows['MLM6002']['line']);
        $this->assertSame($vibrance777->id, $rows['MLM6002']['proposed_inventory_product_id']);
        $this->assertSame('IGORA_VIBRANCE|7-77|SIMPLE', $rows['MLM6002']['proposed_group_key']);

        $this->assertSame(IgoraGroupingAssistantService::LINE_OTHER, $rows['MLM6003']['line']);
        $this->assertContains('LINE_CONFLICT_ROYAL_VS_VIBRANCE', $rows['MLM6003']['conflicts']);
        $this->assertSame('REVISAR_LINEA_ROYAL_VS_VIBRANCE', $rows['MLM6003']['recommended_action']);
    }

    public function test_kit_with_unknown_peroxide_concentration_requires_human_review_and_never_creates_kit_components(): void
    {
        $this->createSimpleProduct('IG-022', 'Igora Royal 0-22', 'SCHWARZKOPF', 6);

        $this->createPublication($this->accountOne, 'MLM7001', 'KIT-UNK', [
            'id' => 'MLM7001',
            'title' => 'Tinte Igora Royal 0-22 con Peroxido Incluido',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $row = $preview['rows'][0];

        $this->assertSame(IgoraGroupingAssistantService::PRES_DYE_WITH_PEROXIDE, $row['presentation_type']);
        $this->assertTrue($row['peroxide_info']['included']);
        $this->assertNull($row['peroxide_info']['concentration_vol']);
        $this->assertNull($row['peroxide_info']['volume_ml']);
        $this->assertTrue($row['peroxide_info']['ambiguous_details']);
        $this->assertTrue($row['requires_human_review']);
        $this->assertContains('PEROXIDE_COMPOSITION_INCOMPLETE', $row['conflicts']);
        $this->assertSame('CONFIRMAR_COMPONENTES_KIT_CON_PEROXIDO', $row['recommended_action']);
        $this->assertDatabaseCount('inventory_kit_components', 0);
    }

    public function test_shared_peroxide_component_is_detected_across_multiple_kits(): void
    {
        $dye022 = $this->createSimpleProduct('IG-022', 'Igora Royal 0-22', 'SCHWARZKOPF', 6);
        $dye777 = $this->createSimpleProduct('IG-777', 'Igora Royal 7-77', 'SCHWARZKOPF', 4);
        $perox20 = $this->createSimpleProduct('PER-IG-20-60', 'Peroxido Locion Activadora Igora Royal 20 Vol 60ml', 'SCHWARZKOPF', 3);

        $this->createPublication($this->accountOne, 'MLM8001', 'KIT-022-20V', [
            'id' => 'MLM8001',
            'title' => 'Tinte Igora Royal 0-22 + Peroxido 20 Vol 60ml',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM8002', 'KIT-777-20V', [
            'id' => 'MLM8002',
            'title' => 'Tinte Igora Royal 7-77 + Peroxido 20 Vol 60ml',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $comp8001 = collect($rows['MLM8001']['candidate_components'])->keyBy('role');
        $comp8002 = collect($rows['MLM8002']['candidate_components'])->keyBy('role');

        $this->assertSame($dye022->id, $comp8001['tinte_componente']['inventory_product_id']);
        $this->assertSame($dye777->id, $comp8002['tinte_componente']['inventory_product_id']);
        $this->assertSame($perox20->id, $comp8001['peroxido_componente']['inventory_product_id']);
        $this->assertSame($perox20->id, $comp8002['peroxido_componente']['inventory_product_id']);
        $this->assertSame(3, $comp8001['peroxido_componente']['local_available_stock']);
        $this->assertSame(1, $comp8001['peroxido_componente']['quantity']);
        $this->assertTrue($rows['MLM8001']['requires_human_review']);
    }

    public function test_full_publications_and_multiple_accounts_are_identified_and_excluded_from_local_sync(): void
    {
        $this->createSimpleProduct('IG-022', 'Igora Royal 0-22', 'SCHWARZKOPF', 6);

        $fullPub = $this->createPublication($this->accountOne, 'MLM9001', 'IG-022', [
            'id' => 'MLM9001',
            'title' => 'Tinte Igora Royal 0-22 FULL',
            'available_quantity' => 15,
            'status' => 'active',
            'shipping' => ['logistic_type' => 'fulfillment', 'tags' => ['fulfillment']],
        ]);

        MeliFullStock::create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->accountOne->id,
            'meli_publication_id' => $fullPub->id,
            'stock_key' => 'MLM9001:main',
            'mlm' => 'MLM9001',
            'sku' => 'IG-022',
            'full_available_quantity' => 15,
            'synced_at' => now(),
        ]);

        $this->createPublication($this->accountTwo, 'MLM9002', 'IG-022', [
            'id' => 'MLM9002',
            'title' => 'Tinte Igora Royal 0-22 Cuenta Secundaria',
            'available_quantity' => 2,
            'status' => 'active',
            'shipping' => ['logistic_type' => 'cross_docking'],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertTrue($rows['MLM9001']['is_full']);
        $this->assertSame(15, $rows['MLM9001']['full_stock_available']);
        $this->assertContains('FULFILLMENT_MANAGED_BY_MELI', $rows['MLM9001']['conflicts']);
        $this->assertSame('EXCLUIR_FULL_CONTROLADO_POR_ML', $rows['MLM9001']['recommended_action']);

        $this->assertFalse($rows['MLM9002']['is_full']);
        $this->assertSame($this->accountTwo->id, $rows['MLM9002']['account_id']);
        $this->assertSame('CuentaSecundaria', $rows['MLM9002']['account_name']);

        // Filtro por cuenta secundaria únicamente
        $filteredAcc2 = app(IgoraGroupingAssistantService::class)->preview(['account_key' => (string) $this->accountTwo->id]);
        $this->assertCount(1, $filteredAcc2['rows']);
        $this->assertSame('MLM9002', $filteredAcc2['rows'][0]['mlm']);
    }

    public function test_stale_incomplete_snapshots_and_repeated_discovery_runs_are_safe_and_idempotent(): void
    {
        $this->createPublication($this->accountOne, 'MLM9501', '', [
            'id' => 'MLM9501',
            'title' => 'Igora Royal 0-22 Sin Stock ni SKU',
            'status' => 'active',
        ], lastSyncAt: now()->subDays(5));

        $service = app(IgoraGroupingAssistantService::class);
        $firstRun = $service->preview();
        $secondRun = $service->preview();

        $this->assertSame($firstRun['counts'], $secondRun['counts']);
        $this->assertSame($firstRun['rows'], $secondRun['rows']);

        $row = $firstRun['rows'][0];
        $this->assertTrue($row['snapshot_stale']);
        $this->assertTrue($row['data_incomplete']);
        $this->assertContains('SNAPSHOT_STALE', $row['conflicts']);
        $this->assertContains('DATA_INCOMPLETE', $row['conflicts']);
        $this->assertContains('MISSING_SKU', $row['conflicts']);
    }

    public function test_web_preview_csv_export_and_artisan_audit_command_work_in_read_only_mode(): void
    {
        $this->createSimpleProduct('7702045539196', 'Igora Royal 0-22', 'SCHWARZKOPF', 6);
        $this->createPublication($this->accountOne, 'MLM2597365385', '7702045539196', [
            'id' => 'MLM2597365385',
            'title' => 'Tinte Igora Royal 0-22',
            'user_product_id' => 'MLMU3650099060',
            'available_quantity' => 1,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM2597999999', 'KIT-AMBIG', [
            'id' => 'MLM2597999999',
            'title' => 'Kit Igora Royal 7-77 con Peroxido',
            'available_quantity' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal', ['tone' => '0-22']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Channels/IgoraRoyalPreview')
                ->where('preview.read_only', true)
                ->has('preview.rows', 1)
                ->where('preview.rows.0.mlm', 'MLM2597365385')
                ->where('preview.rows.0.local_available_stock', 6)
            );

        $csvResponse = $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal.export', ['ambiguous_only' => 1]));
        $csvResponse->assertOk();
        $csvContent = $csvResponse->streamedContent();
        $this->assertStringContainsString('MLM2597999999', $csvContent);
        $this->assertStringNotContainsString('MLM2597365385', $csvContent);

        $this->artisan('inventory:igora-audit', ['--tone' => '0-22', '--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('MLM2597365385');

        $this->assertDatabaseCount('inventory_channel_links', 0);
        Http::assertNothingSent();
    }

    public function test_publications_with_different_titles_and_same_ean_group_without_confusion(): void
    {
        $product = $this->createSimpleProduct('4045787429123', 'Tinte Igora Royal 7-77 Cobrizo 60ml', 'SCHWARZKOPF', 8, '4045787429123');

        $this->createPublication($this->accountOne, 'MLM-EAN-1', '4045787429123', [
            'id' => 'MLM-EAN-1',
            'title' => 'Tinte Permanente Schwarzkopf Igora Royal 7-77 Rubio Medio Cobrizo',
            'user_product_id' => 'MLMU-EAN-1',
            'available_quantity' => 2,
            'status' => 'active',
            'attributes' => [
                ['id' => 'GTIN', 'value_name' => '4045787429123'],
            ],
        ]);

        $this->createPublication($this->accountTwo, 'MLM-EAN-2', '4045787429123', [
            'id' => 'MLM-EAN-2',
            'title' => 'Coloración Profesional Igora Royal Tono 7/77 Tubo 60ml Oferta',
            'user_product_id' => 'MLMU-EAN-2',
            'available_quantity' => 19,
            'status' => 'active',
            'attributes' => [
                ['id' => 'EAN', 'value_name' => '4045787429123'],
            ],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertSame('IGORA_ROYAL', $rows['MLM-EAN-2']['line']);
        $this->assertSame('7-77', $rows['MLM-EAN-2']['normalized_tone']);
        $this->assertSame('SINGLE_DYE', $rows['MLM-EAN-2']['presentation_type']);
        $this->assertSame([], $rows['MLM-EAN-2']['conflicts']);
        $this->assertSame('IGORA_ROYAL|7-77|SIMPLE', $rows['MLM-EAN-1']['proposed_group_key']);
        $this->assertSame('IGORA_ROYAL|7-77|SIMPLE', $rows['MLM-EAN-2']['proposed_group_key']);
        $this->assertCount(1, $preview['groups']);
        $this->assertSame($product->id, $rows['MLM-EAN-1']['proposed_inventory_product_id']);
        $this->assertSame($product->id, $rows['MLM-EAN-2']['proposed_inventory_product_id']);
        $this->assertSame(8, $rows['MLM-EAN-1']['local_physical_stock']);
        $this->assertSame(8, $rows['MLM-EAN-2']['local_physical_stock']);
        $this->assertContains('INDEPENDENT_USER_PRODUCTS_IN_GROUP', $rows['MLM-EAN-1']['notes']);
    }

    public function test_single_dye_vs_kit_and_remote_vs_local_stock_discrepancy(): void
    {
        $dye777 = $this->createSimpleProduct('IG-777', 'Tinte Igora Royal 7-77', 'SCHWARZKOPF', 6);
        $perox20 = $this->createSimpleProduct('PER-IG-20-60', 'Peroxido Locion Activadora Igora Royal 20 Vol 60ml', 'SCHWARZKOPF', 4);

        $this->createPublication($this->accountOne, 'MLM-SINGLE-777', 'IG-777', [
            'id' => 'MLM-SINGLE-777',
            'title' => 'Tinte Schwarzkopf Igora Royal 7-77 60ml',
            'available_quantity' => 25,
            'status' => 'active',
        ]);
        $this->createPriceManagerItem($this->accountOne, 'MLM-SINGLE-777', [
            'sku' => 'IG-777',
            'title' => 'Tinte Schwarzkopf Igora Royal 7-77 60ml',
            'available_quantity' => 11,
        ]);

        $this->createPublication($this->accountOne, 'MLM-KIT-777', 'KIT-777-20V', [
            'id' => 'MLM-KIT-777',
            'title' => 'Kit Tinte Igora Royal 7-77 + Peroxido 20 Vol 60ml',
            'available_quantity' => 9,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertCount(2, $preview['groups']);
        $this->assertSame('IGORA_ROYAL|7-77|SIMPLE', $rows['MLM-SINGLE-777']['proposed_group_key']);
        $this->assertSame('IGORA_ROYAL|7-77|KIT|X1|PEROX-20V-60ML', $rows['MLM-KIT-777']['proposed_group_key']);
        $this->assertSame($dye777->id, $rows['MLM-SINGLE-777']['proposed_inventory_product_id']);
        $this->assertSame(6, $rows['MLM-SINGLE-777']['local_physical_stock']);
        $this->assertSame(25, $rows['MLM-SINGLE-777']['remote_publication_stock']);
        $this->assertSame(11, $rows['MLM-SINGLE-777']['remote_price_manager_stock']);
        $this->assertSame('inventory_ledger', $rows['MLM-SINGLE-777']['local_stock_source']);

        $kitComponents = collect($rows['MLM-KIT-777']['candidate_components'])->keyBy('role');
        $this->assertSame($perox20->id, $kitComponents['peroxido_componente']['inventory_product_id']);
        $this->assertTrue($rows['MLM-KIT-777']['requires_human_review']);
    }

    public function test_peroxides_with_different_volumes_and_concentrations_classify_separately(): void
    {
        $p10 = $this->createSimpleProduct('PER-10-60', 'Peroxido Igora Royal Locion Activadora 10 Vol 60ml', 'SCHWARZKOPF', 5);
        $p20L = $this->createSimpleProduct('PER-20-1000', 'Peroxido Igora Royal Locion Activadora 20 Vol 1000ml', 'SCHWARZKOPF', 2);
        $p30 = $this->createSimpleProduct('PER-30-90', 'Peroxido Igora Royal Locion Activadora 30 Vol 90ml', 'SCHWARZKOPF', 4);

        $this->createPublication($this->accountOne, 'MLM-P10', 'P10', [
            'id' => 'MLM-P10',
            'title' => 'Peroxido Schwarzkopf Igora Royal 10 Vol 3% 60ml',
            'available_quantity' => 5,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-P20L', 'P20L', [
            'id' => 'MLM-P20L',
            'title' => 'Locion Activadora Schwarzkopf Igora Royal 6% Oxidante 1 L',
            'available_quantity' => 2,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-P30', 'P30', [
            'id' => 'MLM-P30',
            'title' => 'Peroxido Igora Royal 30 Vol 90ml',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertCount(3, $preview['groups']);
        $this->assertSame('IGORA_PEROXIDE|10V|60ML', $rows['MLM-P10']['proposed_group_key']);
        $this->assertSame($p10->id, $rows['MLM-P10']['proposed_inventory_product_id']);
        $this->assertSame(10, $rows['MLM-P10']['peroxide_info']['concentration_vol']);
        $this->assertSame(60, $rows['MLM-P10']['peroxide_info']['volume_ml']);

        $this->assertSame('IGORA_PEROXIDE|20V|1000ML', $rows['MLM-P20L']['proposed_group_key']);
        $this->assertSame($p20L->id, $rows['MLM-P20L']['proposed_inventory_product_id']);
        $this->assertSame(20, $rows['MLM-P20L']['peroxide_info']['concentration_vol']);
        $this->assertSame(1000, $rows['MLM-P20L']['peroxide_info']['volume_ml']);

        $this->assertSame('IGORA_PEROXIDE|30V|90ML', $rows['MLM-P30']['proposed_group_key']);
        $this->assertSame($p30->id, $rows['MLM-P30']['proposed_inventory_product_id']);
        $this->assertSame(30, $rows['MLM-P30']['peroxide_info']['concentration_vol']);
        $this->assertSame(90, $rows['MLM-P30']['peroxide_info']['volume_ml']);
    }

    public function test_csv_export_neutralizes_formula_injection_payloads(): void
    {
        $this->createPublication($this->accountOne, 'MLM-CSV-INJ', '=CMD|calc', [
            'id' => 'MLM-CSV-INJ',
            'title' => '=HYPERLINK("http://evil.example","Igora Royal 0-22")',
            'seller_custom_field' => '+SUM(1,2)',
            'available_quantity' => 1,
            'status' => 'active',
            'variations' => [
                [
                    'id' => 77711,
                    'available_quantity' => 1,
                    'seller_custom_field' => '@SUM(1+1)',
                    'attribute_combinations' => [
                        ['id' => 'COLOR', 'name' => '-Tono', 'value_name' => '0-22'],
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal.export'));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK(", $csv);
        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertStringContainsString("'-Tono: 0-22", $csv);
    }

    public function test_web_preview_paginates_rows_while_preserving_global_counts(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $this->createPublication($this->accountOne, 'MLM-PAGE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'IG-'.$i, [
                'id' => 'MLM-PAGE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'title' => "Tinte Igora Royal {$i}-0 Tubo 60ml",
                'available_quantity' => 2,
                'status' => 'active',
            ]);
        }

        $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal', ['per_page' => 25, 'page' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Channels/IgoraRoyalPreview')
                ->has('preview.rows', 25)
                ->where('preview.counts.total_rows', 27)
                ->where('preview.pagination.current_page', 1)
                ->where('preview.pagination.last_page', 2)
                ->where('preview.pagination.total', 27)
            );

        $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal', ['per_page' => 25, 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('preview.rows', 2)
                ->where('preview.counts.total_rows', 27)
                ->where('preview.pagination.current_page', 2)
                ->where('preview.pagination.from', 26)
                ->where('preview.pagination.to', 27)
            );
    }

    public function test_permissions_allow_admin_and_operations_and_deny_warehouse_pos_and_guests(): void
    {
        $admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin-igora@example.com',
            'password' => bcrypt('secret'),
        ]);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $warehouse = User::create([
            'name' => 'Almacen Test',
            'email' => 'wh-igora@example.com',
            'password' => bcrypt('secret'),
        ]);
        $warehouse->forceFill(['role' => User::ROLE_WAREHOUSE])->save();

        $pos = User::create([
            'name' => 'POS Test',
            'email' => 'pos-igora@example.com',
            'password' => bcrypt('secret'),
        ]);
        $pos->forceFill(['role' => User::ROLE_POS])->save();

        $this->get(route('inventory.channels.mercado-libre.igora-royal'))->assertRedirect();
        $this->actingAs($warehouse)->get(route('inventory.channels.mercado-libre.igora-royal'))->assertForbidden();
        $this->actingAs($warehouse)->get(route('inventory.channels.mercado-libre.igora-royal.export'))->assertForbidden();
        $this->actingAs($pos)->get(route('inventory.channels.mercado-libre.igora-royal'))->assertForbidden();
        $this->actingAs($this->user)->get(route('inventory.channels.mercado-libre.igora-royal'))->assertOk();
        $this->actingAs($admin)->get(route('inventory.channels.mercado-libre.igora-royal'))->assertOk();
        $this->actingAs($admin)->get(route('inventory.channels.mercado-libre.igora-royal.export'))->assertOk();
    }

    public function test_ignores_unrelated_tire_and_syscom_publications(): void
    {
        $this->createPublication($this->accountOne, 'MLM-LLANTA-1', 'LL-2055516', [
            'id' => 'MLM-LLANTA-1',
            'title' => 'Llanta Michelin Primacy 4 205/55R16 91V',
            'available_quantity' => 8,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-SYSCOM-1', 'SYS-RTR-1', [
            'id' => 'MLM-SYSCOM-1',
            'title' => 'Router MikroTik hAP ac2 Syscom',
            'available_quantity' => 3,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-IGORA-ONLY', 'IG-022', [
            'id' => 'MLM-IGORA-ONLY',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();

        $this->assertCount(1, $preview['rows']);
        $this->assertSame('MLM-IGORA-ONLY', $preview['rows'][0]['mlm']);
    }

    private function createSimpleProduct(
        string $sku,
        string $name,
        string $brand,
        int $stock,
        ?string $barcode = null,
    ): InventoryProduct {
        $product = InventoryProduct::create([
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $name,
            'brand' => $brand,
            'product_type' => InventoryProduct::SIMPLE,
            'primary_location_id' => $this->location->id,
            'is_active' => true,
        ]);

        if ($stock > 0) {
            InventoryMovement::create([
                'inventory_product_id' => $product->id,
                'inventory_location_id' => $this->location->id,
                'type' => InventoryMovement::INITIAL,
                'quantity' => $stock,
                'reference' => 'Inventario inicial test',
                'occurred_at' => now(),
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $itemPayload
     * @param  list<string>  $subStatus
     * @param  array<string, mixed>  $extraRaw
     */
    private function createPublication(
        MeliAccount $account,
        string $mlm,
        string $sku,
        array $itemPayload,
        string $status = 'active',
        array $subStatus = [],
        array $extraRaw = [],
        mixed $lastSyncAt = null,
    ): MeliPublication {
        $publication = new MeliPublication();
        $publication->forceFill([
            'user_id' => $this->user->id,
            'meli_account_id' => $account->id,
            'sku' => $sku,
            'mlm' => $mlm,
            'status' => $status,
            'sub_status' => $subStatus,
            'permalink' => "https://articulo.mercadolibre.com.mx/{$mlm}",
            'is_current' => true,
            'last_sync_at' => $lastSyncAt ?? now(),
            'raw' => ['item' => $itemPayload, ...$extraRaw],
        ])->save();

        return $publication;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPriceManagerItem(MeliAccount $account, string $mlm, array $attributes): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::create([
            'meli_account_id' => $account->id,
            'meli_item_id' => $mlm,
            'sku' => $attributes['sku'] ?? null,
            'title' => $attributes['title'] ?? "Item {$mlm}",
            'user_product_id' => $attributes['user_product_id'] ?? null,
            'inventory_id' => $attributes['inventory_id'] ?? null,
            'meli_brand' => $attributes['meli_brand'] ?? 'Schwarzkopf',
            'current_price' => $attributes['current_price'] ?? 150,
            'available_quantity' => $attributes['available_quantity'] ?? 0,
            'status' => $attributes['status'] ?? 'active',
            'last_synced_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->text('two_factor_confirmed_at')->nullable();
            $table->string('role', 32)->default('operations');
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('meli_account_id')->nullable()->index();
            $table->string('sku')->index();
            $table->string('mlm')->index();
            $table->string('source_mlm')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->string('category_id')->nullable();
            $table->json('pictures')->nullable();
            $table->boolean('is_current')->default(true)->index();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_07_28_180000_create_meli_full_stocks_table.php'))->up();
        (require database_path('migrations/2026_07_29_130000_create_meli_shared_stock_tables.php'))->up();
        (require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php'))->up();
        (require database_path('migrations/2026_08_26_000002_add_brand_classification_audit_to_meli_price_manager_items.php'))->up();
        (require database_path('migrations/2026_08_29_000001_add_linked_publication_fields_to_meli_price_manager_items.php'))->up();

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_25_000001_create_inventory_channel_links_table.php'))->up();
        (require database_path('migrations/2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_10_05_160000_add_secondary_location_and_amazon_locations.php'))->up();
        (require database_path('migrations/2026_10_07_183419_add_barcode_secondary_to_inventory_products_table.php'))->up();
    }
}
