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
use Illuminate\Support\Facades\Artisan;
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

    public function test_duplicate_sku_across_different_tones_in_same_line_flags_conflict_and_blocks_safe_recommendation(): void
    {
        $this->createPublication($this->accountOne, 'MLM-CT-1', '123456', [
            'id' => 'MLM-CT-1',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 Matizador 60ml',
            'seller_custom_field' => '123456',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM-CT-2', '123456', [
            'id' => 'MLM-CT-2',
            'title' => 'Tinte Schwarzkopf Igora Royal 7-77 Cobrizo Intenso 60ml',
            'seller_custom_field' => '123456',
            'available_quantity' => 5,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertCount(2, $preview['groups']);
        $this->assertSame('IGORA_ROYAL|0-22|SIMPLE', $rows['MLM-CT-1']['proposed_group_key']);
        $this->assertSame('IGORA_ROYAL|7-77|SIMPLE', $rows['MLM-CT-2']['proposed_group_key']);

        foreach ([$rows['MLM-CT-1'], $rows['MLM-CT-2']] as $row) {
            $this->assertContains('DUPLICATE_SKU_CROSS_TONE', $row['conflicts']);
            $this->assertTrue($row['requires_human_review']);
            $this->assertTrue($row['is_ambiguous_or_conflicted']);
            $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $row['confidence']);
            $this->assertSame('REVISAR_CONFLICTO_SKU_TONO', $row['recommended_action']);
        }

        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        Http::assertNothingSent();
    }

    public function test_sku_matching_inventory_product_of_different_tone_flags_sku_tone_mismatch_and_rejects_match(): void
    {
        $wrongToneProduct = $this->createSimpleProduct(
            sku: '7702045539196',
            name: 'Tinte Schwarzkopf Igora Royal 7-77 Cobrizo 60ml',
            brand: 'SCHWARZKOPF',
            stock: 9,
            barcode: '7702045539196',
        );

        $this->createPublication($this->accountOne, 'MLM-MISMATCH-022', '7702045539196', [
            'id' => 'MLM-MISMATCH-022',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 Matizador 60ml',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 3,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $row = $preview['rows'][0];

        $this->assertSame('0-22', $row['normalized_tone']);
        $this->assertNull($row['proposed_inventory_product_id']);
        $this->assertNotSame($wrongToneProduct->id, $row['proposed_inventory_product_id']);
        $this->assertNull($row['local_physical_stock']);
        $this->assertContains('SKU_TONE_MISMATCH', $row['conflicts']);
        $this->assertTrue($row['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $row['confidence']);
        $this->assertSame('REVISAR_CONFLICTO_SKU_TONO', $row['recommended_action']);
        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        Http::assertNothingSent();
    }

    public function test_same_ean_and_matching_tone_preserves_valid_inventory_match(): void
    {
        $product022 = $this->createSimpleProduct(
            sku: 'IG-ROYAL-022',
            name: 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            brand: 'SCHWARZKOPF',
            stock: 6,
            barcode: '7702045539196',
        );

        $this->createPublication($this->accountOne, 'MLM-EAN-VALID', 'ML-CUSTOM-SKU-022', [
            'id' => 'MLM-EAN-VALID',
            'title' => 'Tinte Permanente Schwarzkopf Igora Royal 0-22 60ml',
            'seller_custom_field' => 'ML-CUSTOM-SKU-022',
            'available_quantity' => 2,
            'status' => 'active',
            'attributes' => [
                ['id' => 'GTIN', 'value_name' => '7702045539196'],
            ],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $row = $preview['rows'][0];

        $this->assertSame('0-22', $row['normalized_tone']);
        $this->assertSame($product022->id, $row['proposed_inventory_product_id']);
        $this->assertSame(6, $row['local_physical_stock']);
        $this->assertFalse($row['requires_human_review']);
        $this->assertSame([], $row['conflicts']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_HIGH, $row['confidence']);
        $this->assertSame('CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE', $row['recommended_action']);
    }

    public function test_unknown_tone_in_publication_or_inventory_product_prevents_safe_automatic_match(): void
    {
        $this->createSimpleProduct(
            sku: '7702045539196',
            name: 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            brand: 'SCHWARZKOPF',
            stock: 6,
        );
        $this->createSimpleProduct(
            sku: 'SKU-INV-SIN-TONO',
            name: 'Tinte Schwarzkopf Igora Royal Tubo 60ml',
            brand: 'SCHWARZKOPF',
            stock: 5,
        );

        // Publicación sin tono en título/atributos aunque el SKU coincida con 0-22
        $this->createPublication($this->accountOne, 'MLM-NO-TONE-PUB', '7702045539196', [
            'id' => 'MLM-NO-TONE-PUB',
            'title' => 'Tinte Schwarzkopf Igora Royal Tubo 60ml',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        // Publicación con tono 7-77 pero InventoryProduct sin tono identificable
        $this->createPublication($this->accountOne, 'MLM-NO-TONE-INV', 'SKU-INV-SIN-TONO', [
            'id' => 'MLM-NO-TONE-INV',
            'title' => 'Tinte Schwarzkopf Igora Royal 7-77 60ml',
            'seller_custom_field' => 'SKU-INV-SIN-TONO',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $pubNoTone = $rows['MLM-NO-TONE-PUB'];
        $this->assertNull($pubNoTone['normalized_tone']);
        $this->assertNull($pubNoTone['proposed_inventory_product_id']);
        $this->assertContains('TONE_NOT_DETECTED', $pubNoTone['conflicts']);
        $this->assertContains('INVENTORY_TONE_UNVERIFIED', $pubNoTone['conflicts']);
        $this->assertTrue($pubNoTone['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $pubNoTone['confidence']);
        $this->assertSame('REVISION_HUMANA_CLASIFICACION', $pubNoTone['recommended_action']);

        $invNoTone = $rows['MLM-NO-TONE-INV'];
        $this->assertSame('7-77', $invNoTone['normalized_tone']);
        $this->assertNull($invNoTone['proposed_inventory_product_id']);
        $this->assertContains('INVENTORY_TONE_UNVERIFIED', $invNoTone['conflicts']);
        $this->assertTrue($invNoTone['requires_human_review']);
        $this->assertNotSame(IgoraGroupingAssistantService::CONFIDENCE_HIGH, $invNoTone['confidence']);
        $this->assertSame('REVISION_HUMANA_CLASIFICACION', $invNoTone['recommended_action']);
    }

    public function test_igora_royal_and_vibrance_never_mix_inventory_even_with_shared_sku(): void
    {
        $vibranceProduct = $this->createSimpleProduct(
            sku: 'SHARED-RV-022',
            name: 'Tinte Schwarzkopf Igora Vibrance 0-22 60ml',
            brand: 'SCHWARZKOPF',
            stock: 10,
        );

        $this->createPublication($this->accountOne, 'MLM-RV-ROYAL', 'SHARED-RV-022', [
            'id' => 'MLM-RV-ROYAL',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            'seller_custom_field' => 'SHARED-RV-022',
            'available_quantity' => 5,
            'status' => 'active',
        ]);

        $this->createPublication($this->accountOne, 'MLM-RV-VIB', 'SHARED-RV-022', [
            'id' => 'MLM-RV-VIB',
            'title' => 'Tinte Schwarzkopf Igora Vibrance 0-22 60ml',
            'seller_custom_field' => 'SHARED-RV-022',
            'available_quantity' => 5,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertNull($rows['MLM-RV-ROYAL']['proposed_inventory_product_id']);
        $this->assertNull($rows['MLM-RV-ROYAL']['local_physical_stock']);
        $this->assertContains('INVENTORY_LINE_MISMATCH', $rows['MLM-RV-ROYAL']['conflicts']);
        $this->assertContains('DUPLICATE_SKU_CROSS_LINE', $rows['MLM-RV-ROYAL']['conflicts']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $rows['MLM-RV-ROYAL']['confidence']);
        $this->assertSame('REVISAR_LINEA_ROYAL_VS_VIBRANCE', $rows['MLM-RV-ROYAL']['recommended_action']);

        $this->assertSame($vibranceProduct->id, $rows['MLM-RV-VIB']['proposed_inventory_product_id']);
        $this->assertContains('DUPLICATE_SKU_CROSS_LINE', $rows['MLM-RV-VIB']['conflicts']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $rows['MLM-RV-VIB']['confidence']);
        $this->assertSame('REVISAR_LINEA_ROYAL_VS_VIBRANCE', $rows['MLM-RV-VIB']['recommended_action']);
    }

    public function test_single_dye_and_kit_do_not_share_finished_product_identity(): void
    {
        $dye022 = $this->createSimpleProduct('7702045539196', 'Igora Royal 0-22 60ml', 'SCHWARZKOPF', 6);
        $perox20 = $this->createSimpleProduct('PER-20-60', 'Peroxido Igora Royal 20 Vol 60ml', 'SCHWARZKOPF', 4);
        $kitProduct = InventoryProduct::create([
            'sku' => 'KIT-IG-022-20V',
            'name' => 'Kit Igora Royal 0-22 + Peroxido 20 Vol 60ml',
            'brand' => 'SCHWARZKOPF',
            'product_type' => InventoryProduct::KIT,
            'primary_location_id' => $this->location->id,
            'is_active' => true,
        ]);
        InventoryKitComponent::create([
            'kit_product_id' => $kitProduct->id,
            'component_product_id' => $dye022->id,
            'quantity' => 1,
        ]);
        InventoryKitComponent::create([
            'kit_product_id' => $kitProduct->id,
            'component_product_id' => $perox20->id,
            'quantity' => 1,
        ]);

        $this->createPublication($this->accountOne, 'MLM-ID-SINGLE', '7702045539196', [
            'id' => 'MLM-ID-SINGLE',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 6,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-ID-KIT', '7702045539196', [
            'id' => 'MLM-ID-KIT',
            'title' => 'Kit Tinte Igora Royal 0-22 + Peroxido 20 Vol 60ml',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 4,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertCount(2, $preview['groups']);
        $this->assertSame('IGORA_ROYAL|0-22|SIMPLE', $rows['MLM-ID-SINGLE']['proposed_group_key']);
        $this->assertSame('IGORA_ROYAL|0-22|KIT|X1|PEROX-20V-60ML', $rows['MLM-ID-KIT']['proposed_group_key']);
        $this->assertSame($dye022->id, $rows['MLM-ID-SINGLE']['proposed_inventory_product_id']);
        $this->assertSame($kitProduct->id, $rows['MLM-ID-KIT']['proposed_inventory_product_id']);
        $this->assertNotSame($rows['MLM-ID-SINGLE']['proposed_inventory_product_id'], $rows['MLM-ID-KIT']['proposed_inventory_product_id']);
        $this->assertSame(4, $rows['MLM-ID-KIT']['local_available_stock']);
        $this->assertContains('DUPLICATE_SKU_CROSS_PRESENTATION', $rows['MLM-ID-SINGLE']['conflicts']);
        $this->assertContains('DUPLICATE_SKU_CROSS_PRESENTATION', $rows['MLM-ID-KIT']['conflicts']);
        $this->assertTrue($rows['MLM-ID-KIT']['requires_human_review']);
        $this->assertSame('CONFIRMAR_COMPONENTES_KIT_CON_PEROXIDO', $rows['MLM-ID-KIT']['recommended_action']);
    }

    public function test_cross_tone_conflicts_appear_in_web_panel_csv_export_and_audit_command_without_side_effects(): void
    {
        $this->createSimpleProduct('7702045539196', 'Igora Royal 7-77 Cobrizo 60ml', 'SCHWARZKOPF', 7);

        $this->createPublication($this->accountOne, 'MLM-VIS-MISMATCH', '7702045539196', [
            'id' => 'MLM-VIS-MISMATCH',
            'title' => 'Tinte Igora Royal 0-22 Matizador 60ml',
            'seller_custom_field' => '7702045539196',
            'available_quantity' => 2,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-VIS-CROSS-1', '123456', [
            'id' => 'MLM-VIS-CROSS-1',
            'title' => 'Tinte Igora Royal 0-22 60ml',
            'seller_custom_field' => '123456',
            'available_quantity' => 2,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM-VIS-CROSS-2', '123456', [
            'id' => 'MLM-VIS-CROSS-2',
            'title' => 'Tinte Igora Royal 7-77 60ml',
            'seller_custom_field' => '123456',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        // Panel Inertia
        $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal', ['ambiguous_only' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Channels/IgoraRoyalPreview')
                ->where('preview.counts.total_rows', 3)
                ->where('preview.counts.ambiguous_or_conflicted_rows', 3)
            );

        // Exportación CSV
        $csvResponse = $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal.export', ['ambiguous_only' => 1]));
        $csvResponse->assertOk();
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('SKU_TONE_MISMATCH', $csv);
        $this->assertStringContainsString('DUPLICATE_SKU_CROSS_TONE', $csv);
        $this->assertStringContainsString('REVISAR_CONFLICTO_SKU_TONO', $csv);

        // Comando Artisan de auditoría (salida JSON completa)
        $exitCode = Artisan::call('inventory:igora-audit', ['--ambiguous' => true, '--json' => true]);
        $this->assertSame(0, $exitCode);
        $auditOutput = Artisan::output();
        $this->assertStringContainsString('SKU_TONE_MISMATCH', $auditOutput);
        $this->assertStringContainsString('DUPLICATE_SKU_CROSS_TONE', $auditOutput);
        $this->assertStringContainsString('REVISAR_CONFLICTO_SKU_TONO', $auditOutput);

        $decoded = json_decode($auditOutput, true);
        $this->assertIsArray($decoded);
        $this->assertSame(3, $decoded['counts']['ambiguous_or_conflicted_rows']);

        // Cero efectos secundarios en BD o Mercado Libre
        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_kit_components', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        Http::assertNothingSent();
    }

    public function test_detects_fashion_lights_l89_tone_from_hair_tone_attribute_and_title_without_false_positives(): void
    {
        $productL89 = $this->createSimpleProduct(
            sku: '7702045556940',
            name: 'Tinte Schwarzkopf Igora Royal Fashion Lights L-89 60ml',
            brand: 'SCHWARZKOPF',
            stock: 5,
            barcode: '7702045556940',
        );

        // Publicación limpia con atributo HAIR_TONE y tono L-89
        $this->createPublication($this->accountOne, 'MLM-FL-L89', '7702045556940', [
            'id' => 'MLM-FL-L89',
            'title' => 'Tinte Schwarzkopf Igora Royal Fashion Lights Mechas Rojo Violeta 60ml',
            'seller_custom_field' => '7702045556940',
            'available_quantity' => 3,
            'status' => 'active',
            'attributes' => [
                ['id' => 'HAIR_TONE', 'value_name' => 'L-89 mechas rojo violeta'],
                ['id' => 'GTIN', 'value_name' => '7702045556940'],
            ],
        ]);

        // Publicación con L-44 en título y sin falsos positivos por EAN/ml
        $this->createPublication($this->accountOne, 'MLM-FL-L44', '7702045556100', [
            'id' => 'MLM-FL-L44',
            'title' => 'Schwarzkopf Igora Royal Fashion Lights Tono L/44 60ml EAN 7702045556100',
            'seller_custom_field' => '7702045556100',
            'available_quantity' => 2,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertSame('L-89', $rows['MLM-FL-L89']['normalized_tone']);
        $this->assertSame('attribute', $rows['MLM-FL-L89']['tone_source']);
        $this->assertSame(IgoraGroupingAssistantService::LINE_ROYAL, $rows['MLM-FL-L89']['line']);
        $this->assertSame(IgoraGroupingAssistantService::PRES_SINGLE_DYE, $rows['MLM-FL-L89']['presentation_type']);
        $this->assertSame($productL89->id, $rows['MLM-FL-L89']['proposed_inventory_product_id']);
        $this->assertSame('IGORA_ROYAL|L-89|SIMPLE', $rows['MLM-FL-L89']['proposed_group_key']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_HIGH, $rows['MLM-FL-L89']['confidence']);
        $this->assertSame('CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE', $rows['MLM-FL-L89']['recommended_action']);

        $this->assertSame('L-44', $rows['MLM-FL-L44']['normalized_tone']);
        $this->assertSame('IGORA_ROYAL|L-44|SIMPLE', $rows['MLM-FL-L44']['proposed_group_key']);
    }

    public function test_contradictory_kit_and_activator_attributes_on_mlm3302781080_flag_conflict_and_avoid_bom_or_single_dye(): void
    {
        $this->createSimpleProduct('7702045556940', 'Tinte Igora Royal Fashion Lights L-89 60ml', 'SCHWARZKOPF', 4, '7702045556940');

        $this->createPublication($this->accountOne, 'MLM3302781080', '7704045556940', [
            'id' => 'MLM3302781080',
            'title' => 'Tinte Schwarzkopf Professional Igora Royal Specialities Fashion Lights Tono L-89 Mechas Rojo Violeta 20vol. Para Cabello',
            'seller_custom_field' => '7704045556940',
            'available_quantity' => 2,
            'status' => 'active',
            'attributes' => [
                ['id' => 'HAIR_TONE', 'value_name' => 'L-89 mechas rojo violeta'],
                ['id' => 'IS_KIT', 'value_name' => 'Sí'],
                ['id' => 'KIT_CONTENT', 'value_name' => 'coloración + oxidante'],
                ['id' => 'INCLUDES_ACTIVATOR', 'value_name' => 'No'],
                ['id' => 'OXIDANT_VOLUMES', 'value_name' => '20'],
                ['id' => 'GTIN', 'value_name' => '7702045556940'],
            ],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $row = $preview['rows'][0];

        $this->assertSame(IgoraGroupingAssistantService::LINE_ROYAL, $row['line']);
        $this->assertSame('L-89', $row['normalized_tone']);
        $this->assertSame(IgoraGroupingAssistantService::PRES_UNKNOWN, $row['presentation_type']);
        $this->assertSame([], $row['candidate_components']);
        $this->assertNull($row['proposed_inventory_product_id']);
        $this->assertContains('KIT_ATTRIBUTES_CONTRADICTORY', $row['conflicts']);
        $this->assertContains('SKU_GTIN_MISMATCH_SUSPECT', $row['conflicts']);
        $this->assertTrue($row['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $row['confidence']);
        $this->assertSame('REVISAR_ATRIBUTOS_KIT_CONTRADICTORIOS', $row['recommended_action']);

        // Verificar que panel, CSV y comando de auditoría reflejen los conflictos
        $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal', ['ambiguous_only' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Channels/IgoraRoyalPreview')
                ->where('preview.rows.0.mlm', 'MLM3302781080')
                ->where('preview.rows.0.normalized_tone', 'L-89')
                ->where('preview.rows.0.presentation_type', IgoraGroupingAssistantService::PRES_UNKNOWN)
            );

        $csv = $this->actingAs($this->user)
            ->get(route('inventory.channels.mercado-libre.igora-royal.export', ['ambiguous_only' => 1]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('KIT_ATTRIBUTES_CONTRADICTORY', $csv);
        $this->assertStringContainsString('SKU_GTIN_MISMATCH_SUSPECT', $csv);
        $this->assertStringContainsString('L-89', $csv);

        $exitCode = Artisan::call('inventory:igora-audit', ['--ambiguous' => true, '--json' => true]);
        $this->assertSame(0, $exitCode);
        $auditOutput = Artisan::output();
        $this->assertStringContainsString('KIT_ATTRIBUTES_CONTRADICTORY', $auditOutput);
        $this->assertStringContainsString('SKU_GTIN_MISMATCH_SUSPECT', $auditOutput);

        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_kit_components', 0);
        Http::assertNothingSent();
    }

    public function test_bare_oxidant_volume_mention_without_peroxide_inclusion_never_builds_kit_bom(): void
    {
        $this->createPublication($this->accountOne, 'MLM-20VOL-ONLY', '7702045556940', [
            'id' => 'MLM-20VOL-ONLY',
            'title' => 'Tinte Schwarzkopf Igora Royal Fashion Lights L-89 20vol. 60ml',
            'seller_custom_field' => '7702045556940',
            'available_quantity' => 3,
            'status' => 'active',
            'attributes' => [
                ['id' => 'HAIR_TONE', 'value_name' => 'L-89'],
                ['id' => 'OXIDANT_VOLUMES', 'value_name' => '20'],
            ],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $row = $preview['rows'][0];

        $this->assertSame('L-89', $row['normalized_tone']);
        $this->assertSame(IgoraGroupingAssistantService::PRES_UNKNOWN, $row['presentation_type']);
        $this->assertSame([], $row['candidate_components']);
        $this->assertContains('AMBIGUOUS_KIT_PRESENTATION', $row['conflicts']);
        $this->assertTrue($row['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $row['confidence']);
        $this->assertSame('REVISION_HUMANA_CLASIFICACION', $row['recommended_action']);
    }

    public function test_suspicious_13_digit_sku_vs_gtin_blocks_safe_match_while_preserving_internal_sku_compatibility(): void
    {
        // Producto físico con SKU sospechoso o GTIN real
        $this->createSimpleProduct('7704045556940', 'Tinte Igora Royal L-89 60ml', 'SCHWARZKOPF', 5, '7702045556940');
        // Producto físico con SKU interno distinto de su EAN-13
        $internalProduct = $this->createSimpleProduct('IGORA-INT-022', 'Tinte Igora Royal 0-22 60ml', 'SCHWARZKOPF', 6, '7702045539196');

        // Caso A: Dos códigos de 13 dígitos con posible error de captura (7704045556940 vs 7702045556940)
        $this->createPublication($this->accountOne, 'MLM-SKU-TYPO', '7704045556940', [
            'id' => 'MLM-SKU-TYPO',
            'title' => 'Tinte Schwarzkopf Igora Royal Fashion Lights L-89 60ml',
            'seller_custom_field' => '7704045556940',
            'available_quantity' => 2,
            'status' => 'active',
            'attributes' => [
                ['id' => 'HAIR_TONE', 'value_name' => 'L-89'],
                ['id' => 'GTIN', 'value_name' => '7702045556940'],
            ],
        ]);

        // Caso B: SKU interno alfanumérico diferente del GTIN-13 válido -> totalmente compatible
        $this->createPublication($this->accountOne, 'MLM-SKU-INTERNAL', 'IGORA-INT-022', [
            'id' => 'MLM-SKU-INTERNAL',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            'seller_custom_field' => 'IGORA-INT-022',
            'available_quantity' => 2,
            'status' => 'active',
            'attributes' => [
                ['id' => 'GTIN', 'value_name' => '7702045539196'],
            ],
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $typoRow = $rows['MLM-SKU-TYPO'];
        $this->assertContains('SKU_GTIN_MISMATCH_SUSPECT', $typoRow['conflicts']);
        $this->assertNull($typoRow['proposed_inventory_product_id']);
        $this->assertTrue($typoRow['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $typoRow['confidence']);
        $this->assertSame('REVISAR_CONFLICTO_SKU_GTIN', $typoRow['recommended_action']);

        $internalRow = $rows['MLM-SKU-INTERNAL'];
        $this->assertNotContains('SKU_GTIN_MISMATCH_SUSPECT', $internalRow['conflicts']);
        $this->assertSame($internalProduct->id, $internalRow['proposed_inventory_product_id']);
        $this->assertFalse($internalRow['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_HIGH, $internalRow['confidence']);
        $this->assertSame('CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE', $internalRow['recommended_action']);
    }

    public function test_real_production_duplicate_sku_7702045539127_across_tones_1_0_and_1_1_never_groups_together(): void
    {
        $product10 = $this->createSimpleProduct('7702045539127', 'Tinte Schwarzkopf Igora Royal 1-0 Negro 60ml', 'SCHWARZKOPF', 8, '7702045539127');

        $this->createPublication($this->accountOne, 'MLM4448275366', '7702045539127', [
            'id' => 'MLM4448275366',
            'title' => 'Tinte Schwarzkopf Igora Royal 1-0 Negro Natural 60ml',
            'seller_custom_field' => '7702045539127',
            'available_quantity' => 3,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM4465586278', '7702045539127', [
            'id' => 'MLM4465586278',
            'title' => 'Igora Royal Tono 1-0 Negro Permanente 60ml',
            'seller_custom_field' => '7702045539127',
            'available_quantity' => 2,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM2597401685', '7702045539127', [
            'id' => 'MLM2597401685',
            'title' => 'Tinte Schwarzkopf Igora Royal 1-1 Negro Azulado 60ml',
            'seller_custom_field' => '7702045539127',
            'available_quantity' => 4,
            'status' => 'active',
        ]);
        $this->createPublication($this->accountOne, 'MLM4465586276', '7702045539127', [
            'id' => 'MLM4465586276',
            'title' => 'Igora Royal Tono 1-1 Negro Cenizo 60ml',
            'seller_custom_field' => '7702045539127',
            'available_quantity' => 1,
            'status' => 'active',
        ]);

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        $this->assertCount(4, $preview['rows']);
        $this->assertCount(2, $preview['groups']);

        $this->assertSame('1-0', $rows['MLM4448275366']['normalized_tone']);
        $this->assertSame('1-0', $rows['MLM4465586278']['normalized_tone']);
        $this->assertSame('IGORA_ROYAL|1-0|SIMPLE', $rows['MLM4448275366']['proposed_group_key']);
        $this->assertSame('IGORA_ROYAL|1-0|SIMPLE', $rows['MLM4465586278']['proposed_group_key']);

        $this->assertSame('1-1', $rows['MLM2597401685']['normalized_tone']);
        $this->assertSame('1-1', $rows['MLM4465586276']['normalized_tone']);
        $this->assertSame('IGORA_ROYAL|1-1|SIMPLE', $rows['MLM2597401685']['proposed_group_key']);
        $this->assertSame('IGORA_ROYAL|1-1|SIMPLE', $rows['MLM4465586276']['proposed_group_key']);

        // Los registros 1-1 jamás coinciden con el producto físico 1-0
        foreach (['MLM2597401685', 'MLM4465586276'] as $mlm11) {
            $this->assertNull($rows[$mlm11]['proposed_inventory_product_id']);
            $this->assertNotSame($product10->id, $rows[$mlm11]['proposed_inventory_product_id']);
            $this->assertContains('SKU_TONE_MISMATCH', $rows[$mlm11]['conflicts']);
        }

        // Los 4 registros quedan bloqueados por DUPLICATE_SKU_CROSS_TONE y nunca se recomiendan como agrupación segura
        foreach (['MLM4448275366', 'MLM4465586278', 'MLM2597401685', 'MLM4465586276'] as $mlm) {
            $this->assertContains('DUPLICATE_SKU_CROSS_TONE', $rows[$mlm]['conflicts']);
            $this->assertTrue($rows[$mlm]['requires_human_review']);
            $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $rows[$mlm]['confidence']);
            $this->assertSame('REVISAR_CONFLICTO_SKU_TONO', $rows[$mlm]['recommended_action']);
        }

        $this->assertDatabaseCount('inventory_channel_links', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        Http::assertNothingSent();
    }

    public function test_unsafe_recommendations_blocked_for_missing_sku_incomplete_moderated_and_stale_snapshots(): void
    {
        // 1. MISSING_SKU
        $this->createPublication($this->accountOne, 'MLM-UNSAFE-SKU', '', [
            'id' => 'MLM-UNSAFE-SKU',
            'title' => 'Tinte Schwarzkopf Igora Royal 0-22 60ml',
            'available_quantity' => 3,
            'status' => 'active',
        ]);

        // 2. DATA_INCOMPLETE (sin available_quantity en raw)
        $this->createPublication($this->accountOne, 'MLM-UNSAFE-INC', 'IG-INC-777', [
            'id' => 'MLM-UNSAFE-INC',
            'title' => 'Tinte Schwarzkopf Igora Royal 7-77 60ml',
            'seller_custom_field' => 'IG-INC-777',
            'status' => 'active',
        ]);

        // 3. PUBLICATION_MODERATED_OR_DENOUNCED
        $this->createPublication($this->accountOne, 'MLM-UNSAFE-MOD', 'IG-MOD-10', [
            'id' => 'MLM-UNSAFE-MOD',
            'title' => 'Tinte Schwarzkopf Igora Royal 1-0 60ml',
            'seller_custom_field' => 'IG-MOD-10',
            'available_quantity' => 4,
            'status' => 'under_review',
            'sub_status' => ['forbidden'],
        ], status: 'under_review', subStatus: ['forbidden']);

        // 4. SNAPSHOT_STALE únicamente (identidad completa, sin conflictos de identidad)
        $this->createPublication($this->accountOne, 'MLM-STALE-ONLY', 'IG-STALE-951', [
            'id' => 'MLM-STALE-ONLY',
            'title' => 'Tinte Schwarzkopf Igora Royal 9.5-1 60ml',
            'seller_custom_field' => 'IG-STALE-951',
            'available_quantity' => 5,
            'status' => 'active',
        ], lastSyncAt: now()->subDays(3));

        $preview = app(IgoraGroupingAssistantService::class)->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');

        foreach (['MLM-UNSAFE-SKU', 'MLM-UNSAFE-INC', 'MLM-UNSAFE-MOD', 'MLM-STALE-ONLY'] as $mlm) {
            $this->assertNotSame('CANDIDATO_A_CREAR_Y_AGRUPAR_EN_INVENTORY', $rows[$mlm]['recommended_action']);
            $this->assertNotSame('CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE', $rows[$mlm]['recommended_action']);
        }

        $this->assertTrue($rows['MLM-UNSAFE-SKU']['requires_human_review']);
        $this->assertSame('VALIDAR_IDENTIDAD_Y_DATOS_INCOMPLETOS', $rows['MLM-UNSAFE-SKU']['recommended_action']);

        $this->assertTrue($rows['MLM-UNSAFE-INC']['requires_human_review']);
        $this->assertSame('VALIDAR_IDENTIDAD_Y_DATOS_INCOMPLETOS', $rows['MLM-UNSAFE-INC']['recommended_action']);

        $this->assertTrue($rows['MLM-UNSAFE-MOD']['requires_human_review']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_LOW, $rows['MLM-UNSAFE-MOD']['confidence']);
        $this->assertSame('REVISAR_PUBLICACION_MODERADA', $rows['MLM-UNSAFE-MOD']['recommended_action']);

        // SNAPSHOT_STALE se distingue de un conflicto de identidad y no usa stock remoto como físico
        $staleRow = $rows['MLM-STALE-ONLY'];
        $this->assertSame(['SNAPSHOT_STALE'], $staleRow['conflicts']);
        $this->assertFalse($staleRow['requires_human_review']);
        $this->assertFalse($staleRow['is_ambiguous_or_conflicted']);
        $this->assertSame(IgoraGroupingAssistantService::CONFIDENCE_MEDIUM, $staleRow['confidence']);
        $this->assertSame('REFRESCAR_SNAPSHOT_ANTES_DE_VINCULAR', $staleRow['recommended_action']);
        $this->assertNull($staleRow['local_physical_stock']);
        $this->assertSame(5, $staleRow['remote_publication_stock']);
        $this->assertSame('sin_producto_en_inventory', $staleRow['local_stock_source']);
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
