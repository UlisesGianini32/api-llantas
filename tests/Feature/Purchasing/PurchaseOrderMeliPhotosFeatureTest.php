<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PurchaseOrderMeliPhotosFeatureTest extends TestCase
{
    private User $admin;

    private User $warehouse;

    private User $pos;

    private InventoryLocation $location;

    private MeliAccount $account;

    private string $tinyPngBytes;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Storage::fake('local');

        // Valid 1x1 PNG image bytes
        $this->tinyPngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ) ?: '';

        $this->createTestSchema();

        $this->admin = User::forceCreate([
            'name' => 'Admin SBS',
            'email' => 'admin-photos@sbs.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->warehouse = User::forceCreate([
            'name' => 'Almacén SBS',
            'email' => 'warehouse-photos@sbs.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_WAREHOUSE,
        ]);

        $this->pos = User::forceCreate([
            'name' => 'Cajero POS',
            'email' => 'pos-photos@sbs.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_POS,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'BOD-CENTRAL',
            'name' => 'Bodega Central SBS',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->account = MeliAccount::create([
            'user_id' => $this->admin->id,
            'meli_user_id' => '99887766',
            'nickname' => 'SBS_CHINA',
            'is_default' => true,
        ]);
    }

    public function test_show_and_pdf_views_render_same_origin_photo_urls_and_fallback_sin_foto(): void
    {
        [$order, $itemWithPhoto, $itemWithoutPhoto] = $this->seedChinaOrderScenario();

        $this->withoutVite();

        // Normal Inertia view exposes same-origin photo URL only for matched item
        $showResponse = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Show')
            ->where("imageUrlByItemId.{$itemWithPhoto->id}", "/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto")
            ->missing("imageUrlByItemId.{$itemWithoutPhoto->id}")
        );

        // Internal printable PDF view renders Foto header, same-origin image src, and Sin foto fallback
        $pdfResponse = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}/pdf");
        $pdfResponse->assertOk();
        $pdfResponse->assertSee('>Foto<', false);
        $pdfResponse->assertSee("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto", false);
        $pdfResponse->assertSee('Sin foto');
        $pdfResponse->assertSee('ATOMIZADORBR');
        $pdfResponse->assertSee('NO-ML-SKU');

        // Signed public PDF for external supplier renders relative signed photo URLs that work without auth
        $signedPdfUrl = URL::temporarySignedRoute(
            'purchasing.orders.public-pdf',
            now()->addDays(7),
            ['purchaseOrder' => $order->id],
            absolute: false
        );

        $this->app['auth']->forgetGuards();
        $publicPdfResponse = $this->get($signedPdfUrl);
        $publicPdfResponse->assertOk();
        $publicPdfResponse->assertSee("/orden-compra/{$order->id}/partidas/{$itemWithPhoto->id}/foto?", false);
        $publicPdfResponse->assertSee('Sin foto');

        // Extract the signed public photo URL from the rendered HTML and verify it serves the cached image
        preg_match('/src="([^"]*\/orden-compra\/'.$order->id.'\/partidas\/'.$itemWithPhoto->id.'\/foto\?[^"]+)"/', $publicPdfResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? '', 'Expected signed public photo URL in shared supplier PDF.');

        Http::fake([
            'https://http2.mlstatic.com/pink-variant.png' => Http::response($this->tinyPngBytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $publicPhotoUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $publicPhotoResponse = $this->get($publicPhotoUrl);
        $publicPhotoResponse->assertOk();
        $publicPhotoResponse->assertHeader('Content-Type', 'image/png');
        $publicPhotoResponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($this->tinyPngBytes, $publicPhotoResponse->getContent());
    }

    public function test_item_photo_proxy_caches_locally_handles_stale_fallback_and_enforces_security(): void
    {
        [$order, $itemWithPhoto, $itemWithoutPhoto] = $this->seedChinaOrderScenario();

        $otherOrder = PurchaseOrder::create([
            'order_number' => 'OC-20261113-9999',
            'supplier_name' => 'Otro Proveedor',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_items_count' => 0,
            'total_units_ordered' => 0,
            'total_units_received' => 0,
            'subtotal' => 0,
            'total_cost' => 0,
        ]);

        Http::fake([
            'https://http2.mlstatic.com/pink-variant.png' => Http::sequence()
                ->push($this->tinyPngBytes, 200, ['Content-Type' => 'image/png'])
                ->push('', 404),
        ]);

        // Warehouse role can view order and fetch same-origin product photo
        $firstResponse = $this->actingAs($this->warehouse)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto");
        $firstResponse->assertOk();
        $firstResponse->assertHeader('Content-Type', 'image/png');

        // Second request is served from local cache without another outbound HTTP call
        $secondResponse = $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto");
        $secondResponse->assertOk();
        Http::assertSentCount(1);

        // Even after cache TTL expires and remote ML URL returns 404, stale cached photo is still served!
        $hash = hash('sha256', 'https://http2.mlstatic.com/pink-variant.png');
        $metaPath = "purchasing/meli-photos/{$hash}.json";
        $meta = json_decode((string) Storage::disk('local')->get($metaPath), true);
        $meta['cached_at'] = time() - (8 * 86400);
        Storage::disk('local')->put($metaPath, (string) json_encode($meta));

        $staleResponse = $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto");
        $staleResponse->assertOk();
        $this->assertSame($this->tinyPngBytes, $staleResponse->getContent());
        Http::assertSentCount(2);

        // Item without photo returns 404
        $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithoutPhoto->id}/foto")
            ->assertNotFound();

        // Cross-order item access returns 404
        $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$otherOrder->id}/partidas/{$itemWithPhoto->id}/foto")
            ->assertNotFound();

        // Unauthorized role (POS) is denied
        $this->actingAs($this->pos)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto")
            ->assertForbidden();

        // Unsigned public photo request is denied
        $this->app['auth']->forgetGuards();
        $this->get("/orden-compra/{$order->id}/partidas/{$itemWithPhoto->id}/foto")
            ->assertForbidden();
    }

    public function test_item_photo_proxy_rejects_untrusted_redirects_and_non_image_payloads(): void
    {
        [$order, $itemWithPhoto] = $this->seedChinaOrderScenario();

        // Redirect to untrusted host must be blocked
        Http::fake([
            'https://http2.mlstatic.com/pink-variant.png' => Http::response('', 302, [
                'Location' => 'https://evil.example/ssrf.png',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto")
            ->assertNotFound();

        // Non-image HTML payload on mlstatic URL must be rejected
        Http::fake([
            'https://http2.mlstatic.com/pink-variant.png' => Http::response('<html>not an image</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$order->id}/partidas/{$itemWithPhoto->id}/foto")
            ->assertNotFound();
    }

    public function test_read_only_audit_command_reports_exact_sku_matches_and_missing_items(): void
    {
        [$order] = $this->seedChinaOrderScenario();

        $exitCode = Artisan::call('purchasing:audit-order-photos', [
            'order' => 'OC-20261113-0001',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report);
        $this->assertSame('OC-20261113-0001', $report['order']['order_number']);
        $this->assertSame(2, $report['summary']['total_items']);
        $this->assertSame(1, $report['summary']['with_photo']);
        $this->assertSame(1, $report['summary']['without_photo']);
        $this->assertSame('NO-ML-SKU', $report['missing_skus'][0]['sku']);
        $this->assertSame('MLM300100', $report['items'][0]['mlm']);
        $this->assertSame('502', $report['items'][0]['variation_id']);
        $this->assertSame('channel_link_publication', $report['items'][0]['source']);
    }

    public function test_ambiguous_shared_sku_renders_needs_review_in_show_sin_foto_in_pdf_and_404_on_proxy(): void
    {
        $secondAccount = MeliAccount::create([
            'user_id' => $this->admin->id,
            'meli_user_id' => '55443322',
            'nickname' => 'SBS_SECUNDARIA',
            'is_default' => false,
        ]);

        $product = InventoryProduct::create([
            'sku' => 'SKU-AMBIG-1',
            'name' => 'Producto Compartido Multi-MLM',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 40,
            'brand' => 'CHINA',
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'SKU-AMBIG-1',
            'mlm' => 'MLM-AMB-1',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-AMBIG-1',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/ambig-1.png']],
                ],
            ],
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $secondAccount->id,
            'sku' => 'SKU-AMBIG-1',
            'mlm' => 'MLM-AMB-2',
            'status' => 'active',
            'last_sync_at' => now()->subMinute(),
            'raw' => [
                'item' => [
                    'seller_custom_field' => 'SKU-AMBIG-1',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/ambig-2.png']],
                ],
            ],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261119-0099',
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'total_items_count' => 1,
            'total_units_ordered' => 50,
            'total_units_received' => 0,
            'subtotal' => 2000,
            'total_cost' => 2000,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 40,
            'subtotal' => 2000,
            'status' => 'PENDING',
        ]);

        $this->withoutVite();

        $showResponse = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn (Assert $page) => $page
            ->component('Purchasing/Show')
            ->missing("imageUrlByItemId.{$item->id}")
            ->where("photoStatusByItemId.{$item->id}.status", 'needs_review')
            ->where("photoStatusByItemId.{$item->id}.reason", 'multiple_publications_conflicting_photos')
            ->where("photoStatusByItemId.{$item->id}.candidates_count", 2)
        );

        $pdfResponse = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}/pdf");
        $pdfResponse->assertOk();
        $pdfResponse->assertSee('Sin foto');
        $pdfResponse->assertDontSee("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto", false);

        $this->actingAs($this->admin)
            ->get("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto")
            ->assertNotFound();

        $exitCode = Artisan::call('purchasing:audit-order-photos', [
            'order' => 'OC-20261119-0099',
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $report = json_decode(Artisan::output(), true);
        $this->assertSame(0, $report['summary']['with_photo']);
        $this->assertSame(1, $report['summary']['without_photo']);
        $this->assertSame(1, $report['summary']['ambiguous_count']);
        $this->assertSame('multiple_publications_conflicting_photos', $report['ambiguous_matches'][0]['reason']);
    }

    public function test_admin_can_search_candidates_and_approve_ambiguous_publication_or_variation_and_render_in_show_and_pdf(): void
    {
        $prodPinzas = InventoryProduct::create([
            'sku' => 'BSHC003',
            'name' => 'Pinzas mariposa 12 piezas negro',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 30,
            'brand' => 'CHINA',
        ]);

        $prodAtom = InventoryProduct::create([
            'sku' => 'CHN-TMP-001',
            'name' => 'Atomizador Blanco Azul',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 25,
            'brand' => 'CHINA',
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'BSHC003',
            'mlm' => 'MLM1964933341',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'title' => 'Pinzas Mariposa 12 Piezas Negro Salon',
                    'seller_custom_field' => 'BSHC003',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/pinzas-1964.png']],
                ],
            ],
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'BSHC003',
            'mlm' => 'MLM3049813228',
            'status' => 'paused',
            'last_sync_at' => now()->subDay(),
            'raw' => [
                'item' => [
                    'title' => 'Pinzas Mariposa Negro Paquete 12',
                    'seller_custom_field' => 'BSHC003',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/pinzas-3049.png']],
                ],
            ],
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'ATOMIZADORBCOAF',
            'mlm' => 'MLM3373409704',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'title' => 'Atomizador Spray 300ml Colores',
                    'pictures' => [
                        ['id' => 'cover', 'secure_url' => 'https://http2.mlstatic.com/atom-cover.png'],
                        ['id' => 'p-blue', 'secure_url' => 'https://http2.mlstatic.com/atom-blanco-azul.png'],
                    ],
                    'variations' => [
                        [
                            'id' => 185902990943,
                            'seller_custom_field' => 'OTHER-ATOM-SKU',
                            'picture_ids' => ['p-blue'],
                            'attribute_combinations' => [
                                ['id' => 'COLOR', 'name' => 'Color', 'value_name' => 'Blanco Azul'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261113-0001',
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_items_count' => 2,
            'total_units_ordered' => 100,
            'total_units_received' => 0,
            'subtotal' => 2750,
            'total_cost' => 2750,
        ]);

        $itemPinzas = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $prodPinzas->id,
            'sku' => $prodPinzas->sku,
            'product_name' => $prodPinzas->name,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 30,
            'subtotal' => 1500,
            'status' => 'PENDING',
        ]);

        $itemAtom = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $prodAtom->id,
            'sku' => $prodAtom->sku,
            'product_name' => $prodAtom->name,
            'quantity_ordered' => 50,
            'quantity_received' => 0,
            'unit_cost' => 25,
            'subtotal' => 1250,
            'status' => 'PENDING',
        ]);

        // Admin searches candidates for Pinzas
        $candResp = $this->actingAs($this->admin)
            ->getJson("/compras/ordenes/{$order->id}/partidas/{$itemPinzas->id}/foto-candidatos");
        $candResp->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'candidates');

        // Admin approves MLM1964933341 for BSHC003
        $approvePinzas = $this->actingAs($this->admin)
            ->postJson("/compras/ordenes/{$order->id}/partidas/{$itemPinzas->id}/foto-aprobar", [
                'mlm' => 'MLM1964933341',
                'notes' => 'Publicación validada visualmente',
            ]);
        $approvePinzas->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resolution.mlm', 'MLM1964933341')
            ->assertJsonPath('resolution.source', 'approved_publication');

        // Admin searches Atomizador by title and approves variation 185902990943
        $atomCandResp = $this->actingAs($this->admin)
            ->getJson("/compras/ordenes/{$order->id}/partidas/{$itemAtom->id}/foto-candidatos?q=Atomizador");
        $atomCandResp->assertOk()
            ->assertJsonPath('candidates.0.mlm', 'MLM3373409704')
            ->assertJsonPath('candidates.0.variation_id', '185902990943')
            ->assertJsonPath('candidates.0.variation_label', 'Color: Blanco Azul');

        $approveAtom = $this->actingAs($this->admin)
            ->postJson("/compras/ordenes/{$order->id}/partidas/{$itemAtom->id}/foto-aprobar", [
                'mlm' => 'MLM3373409704',
                'variation_id' => '185902990943',
                'notes' => 'Color Blanco Azul',
            ]);
        $approveAtom->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resolution.source', 'approved_variation')
            ->assertJsonPath('resolution.url', 'https://http2.mlstatic.com/atom-blanco-azul.png');

        $this->withoutVite();
        $showResp = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}");
        $showResp->assertOk();
        $showResp->assertInertia(fn (Assert $page) => $page
            ->where('canManagePhotos', true)
            ->has("imageUrlByItemId.{$itemPinzas->id}")
            ->has("imageUrlByItemId.{$itemAtom->id}")
            ->where("photoStatusByItemId.{$itemAtom->id}.variation_label", 'Color: Blanco Azul')
        );

        Http::fake([
            'https://http2.mlstatic.com/atom-blanco-azul.png' => Http::response($this->tinyPngBytes, 200, ['Content-Type' => 'image/png']),
        ]);

        $pdfResp = $this->actingAs($this->admin)->get("/compras/ordenes/{$order->id}/pdf");
        $pdfResp->assertOk();
        $pdfResp->assertSee("/compras/ordenes/{$order->id}/partidas/{$itemAtom->id}/foto", false);

        $itemAtom->refresh();
        $photoUrl = "/compras/ordenes/{$order->id}/partidas/{$itemAtom->id}/foto?v={$itemAtom->photo_updated_at->timestamp}";
        $this->actingAs($this->admin)->get($photoUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_admin_can_upload_manual_photo_for_bubble_mailers_reject_mlm_and_enforce_rbac(): void
    {
        $prodSobre = InventoryProduct::create([
            'sku' => 'SOBRE-26X36X4',
            'name' => 'Sobre tipo burbuja blanco 26 x 36 x 4',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 5,
            'brand' => 'CHINA',
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'SOBRE-26X36X4',
            'mlm' => 'MLM3446472074',
            'status' => 'active',
            'raw' => [
                'item' => [
                    'title' => 'Sobre burbuja blanco 28 x 18 cm',
                    'seller_custom_field' => 'SOBRE-26X36X4',
                    'pictures' => [['secure_url' => 'https://http2.mlstatic.com/sobre-28x18-wrong.png']],
                ],
            ],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261113-0001',
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_items_count' => 1,
            'total_units_ordered' => 2000,
            'total_units_received' => 0,
            'subtotal' => 10000,
            'total_cost' => 10000,
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $prodSobre->id,
            'sku' => $prodSobre->sku,
            'product_name' => $prodSobre->name,
            'quantity_ordered' => 2000,
            'quantity_received' => 0,
            'unit_cost' => 5,
            'subtotal' => 10000,
            'status' => 'PENDING',
        ]);

        // Warehouse & POS cannot manage/upload item photos
        $this->actingAs($this->warehouse)
            ->postJson("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto-rechazar", ['mlm' => 'MLM3446472074'])
            ->assertForbidden();

        // Reject MLM3446472074
        $this->actingAs($this->admin)
            ->postJson("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto-rechazar", [
                'mlm' => 'MLM3446472074',
                'mark_no_photo' => true,
            ])
            ->assertOk()
            ->assertJsonPath('resolution.url', null)
            ->assertJsonPath('resolution.reason', 'manual_rejected_no_photo');

        // Upload invalid non-image payload must fail validation (422)
        $fakeTxt = UploadedFile::fake()->create('evil.png', 1, 'image/png');
        $this->actingAs($this->admin)
            ->post("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto-manual", ['image' => $fakeTxt], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // Upload valid PNG image for bubble mailer
        $validPng = UploadedFile::fake()->createWithContent('sobre-26x36.png', $this->tinyPngBytes);
        $uploadResp = $this->actingAs($this->admin)
            ->post("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto-manual", [
                'image' => $validPng,
                'notes' => 'Foto referencia sobre 26x36x4',
            ], ['Accept' => 'application/json']);

        $uploadResp->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resolution.source', 'manual_upload')
            ->assertJsonPath('resolution.audit_category', 'manual_override');

        // Served through internal itemPhoto and signed supplier PDF
        $item->refresh();
        $photoPath = "/compras/ordenes/{$order->id}/partidas/{$item->id}/foto?v={$item->photo_updated_at->timestamp}";
        $this->actingAs($this->admin)->get($photoPath)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertSee($this->tinyPngBytes, false);

        $signedPdfUrl = URL::temporarySignedRoute('purchasing.orders.public-pdf', now()->addDays(7), ['purchaseOrder' => $order->id], absolute: false);
        $this->app['auth']->forgetGuards();
        $publicPdf = $this->get($signedPdfUrl);
        $publicPdf->assertOk();
        preg_match('/src="([^"]*\/orden-compra\/'.$order->id.'\/partidas\/'.$item->id.'\/foto\?[^"]+)"/', $publicPdf->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? '');

        $publicPhotoUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        $this->get($publicPhotoUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // Reset restores automatic resolution
        $this->actingAs($this->admin)
            ->deleteJson("/compras/ordenes/{$order->id}/partidas/{$item->id}/foto-seleccion?clear_rejected=1")
            ->assertOk()
            ->assertJsonPath('resolution.mlm', 'MLM3446472074');
    }

    public function test_audit_command_distinguishes_all_five_photo_categories(): void
    {
        $pSku = InventoryProduct::create(['sku' => 'SIMPLE-OK', 'name' => 'Producto Simple', 'product_type' => InventoryProduct::SIMPLE, 'is_active' => true, 'cost' => 10, 'brand' => 'CHINA']);
        $pVar = InventoryProduct::create(['sku' => 'VAR-OK', 'name' => 'Atomizador Negro', 'product_type' => InventoryProduct::SIMPLE, 'is_active' => true, 'cost' => 10, 'brand' => 'CHINA']);
        $pManual = InventoryProduct::create(['sku' => 'SOBRE-MAN', 'name' => 'Sobre Burbuja Manual', 'product_type' => InventoryProduct::SIMPLE, 'is_active' => true, 'cost' => 5, 'brand' => 'CHINA']);
        $pPendingTitle = InventoryProduct::create(['sku' => 'TMP-VAPOR', 'name' => 'Vaporizador facial con ozono', 'product_type' => InventoryProduct::SIMPLE, 'is_active' => true, 'cost' => 100, 'brand' => 'CHINA']);
        $pNoCand = InventoryProduct::create(['sku' => 'SIN-NADA-XYZ', 'name' => 'Artículo Sin Coincidencias', 'product_type' => InventoryProduct::SIMPLE, 'is_active' => true, 'cost' => 20, 'brand' => 'CHINA']);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'SIMPLE-OK',
            'mlm' => 'MLM-SIMPLE-1',
            'status' => 'active',
            'raw' => ['item' => ['seller_custom_field' => 'SIMPLE-OK', 'pictures' => [['secure_url' => 'https://http2.mlstatic.com/simple.png']]]],
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'VAR-OK',
            'mlm' => 'MLM-VAR-1',
            'status' => 'active',
            'raw' => [
                'item' => [
                    'pictures' => [['id' => 'v1', 'secure_url' => 'https://http2.mlstatic.com/var-negro.png']],
                    'variations' => [['id' => 901, 'seller_custom_field' => 'VAR-OK', 'picture_ids' => ['v1']]],
                ],
            ],
        ]);

        MeliPriceManagerItem::create([
            'meli_account_id' => $this->account->id,
            'meli_item_id' => 'MLM2611925824',
            'sku' => 'OTRO-SKU',
            'title' => 'Vaporizador Facial Con Ozono',
            'status' => 'active',
            'raw_item' => [
                'title' => 'Vaporizador Facial Con Ozono',
                'pictures' => [['secure_url' => 'https://http2.mlstatic.com/vapor.png']],
            ],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261113-0001',
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_items_count' => 5,
            'total_units_ordered' => 50,
            'total_units_received' => 0,
            'subtotal' => 500,
            'total_cost' => 500,
        ]);

        foreach ([$pSku, $pVar, $pManual, $pPendingTitle, $pNoCand] as $prod) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'inventory_product_id' => $prod->id,
                'sku' => $prod->sku,
                'product_name' => $prod->name,
                'quantity_ordered' => 10,
                'quantity_received' => 0,
                'unit_cost' => $prod->cost,
                'subtotal' => $prod->cost * 10,
                'status' => 'PENDING',
            ]);
        }

        $manualItem = $order->items()->where('sku', 'SOBRE-MAN')->firstOrFail();
        $manualPath = "purchasing/manual-photos/po-{$order->id}/item-{$manualItem->id}.png";
        Storage::disk('local')->put($manualPath, $this->tinyPngBytes);
        $manualItem->update([
            'photo_source_mode' => PurchaseOrderItem::PHOTO_MODE_MANUAL,
            'manual_photo_path' => $manualPath,
            'manual_photo_mime' => 'image/png',
        ]);

        $exitCode = Artisan::call('purchasing:audit-order-photos', [
            'order' => 'OC-20261113-0001',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(5, $report['summary']['total_items']);
        $this->assertSame(3, $report['summary']['with_photo']);
        $this->assertSame(2, $report['summary']['without_photo']);
        $this->assertSame(1, $report['summary']['resolved_by_sku_or_link']);
        $this->assertSame(1, $report['summary']['resolved_by_variation']);
        $this->assertSame(1, $report['summary']['manual_override']);
        $this->assertSame(1, $report['summary']['pending_manual_approval']);
        $this->assertSame(1, $report['summary']['pending_without_candidate']);
    }

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderItem, 2: PurchaseOrderItem} */
    private function seedChinaOrderScenario(): array
    {
        $prodPink = InventoryProduct::create([
            'sku' => 'ATOMIZADORBR',
            'name' => 'Atomizador Botella Rosa 300ml',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 25,
            'brand' => 'CHINA',
        ]);

        $prodMissing = InventoryProduct::create([
            'sku' => 'NO-ML-SKU',
            'name' => 'Accesorio Sin Publicación ML',
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 15,
            'brand' => 'CHINA',
        ]);

        InventoryChannelLink::create([
            'inventory_product_id' => $prodPink->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $this->account->id,
            'external_listing_id' => 'MLM300100',
            'external_variant_id' => '502',
            'is_active' => true,
            'identity_key' => "ml:{$this->account->id}:MLM300100:502",
        ]);

        MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'ATOMIZADORBCOAF',
            'mlm' => 'MLM300100',
            'status' => 'active',
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'pictures' => [
                        ['id' => 'pic-blue', 'secure_url' => 'https://http2.mlstatic.com/blue-variant.png'],
                        ['id' => 'pic-pink', 'secure_url' => 'https://http2.mlstatic.com/pink-variant.png'],
                    ],
                    'variations' => [
                        ['id' => 501, 'seller_custom_field' => 'ATOMIZADORBCOAF', 'picture_ids' => ['pic-blue']],
                        ['id' => 502, 'seller_custom_field' => 'ATOMIZADORBR', 'picture_ids' => ['pic-pink']],
                    ],
                ],
            ],
        ]);

        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261113-0001',
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'total_items_count' => 2,
            'total_units_ordered' => 300,
            'total_units_received' => 0,
            'subtotal' => 6500,
            'total_cost' => 6500,
        ]);

        $itemWithPhoto = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $prodPink->id,
            'sku' => $prodPink->sku,
            'supplier_sku' => 'CN-ATOM-PK',
            'product_name' => $prodPink->name,
            'quantity_ordered' => 200,
            'quantity_received' => 0,
            'unit_cost' => 25,
            'subtotal' => 5000,
            'status' => 'PENDING',
        ]);

        $itemWithoutPhoto = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'inventory_product_id' => $prodMissing->id,
            'sku' => $prodMissing->sku,
            'supplier_sku' => 'CN-ACC-01',
            'product_name' => $prodMissing->name,
            'quantity_ordered' => 100,
            'quantity_received' => 0,
            'unit_cost' => 15,
            'subtotal' => 1500,
            'status' => 'PENDING',
        ]);

        return [$order->fresh(['items.product']), $itemWithPhoto, $itemWithoutPhoto];
    }

    private function createTestSchema(): void
    {
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

        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('source_mlm')->nullable();
            $table->string('status')->nullable();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('pictures')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });

        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id');
            $table->string('meli_item_id', 64);
            $table->string('sku', 191)->nullable();
            $table->string('title');
            $table->decimal('current_price', 15, 2)->default(0);
            $table->string('status', 64)->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
        (require database_path('migrations/2026_10_07_193000_create_suppliers_and_supplier_brands_tables.php'))->up();
        (require database_path('migrations/2026_10_21_160000_create_supplier_products_table.php'))->up();
        (require database_path('migrations/2026_11_20_000001_add_photo_selection_fields_to_purchase_order_items_table.php'))->up();
    }
}
