<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryChannelLink;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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
    }
}
