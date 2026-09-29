<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Services\Shopify\InventoryShopifyClient;
use App\Services\Shopify\InventoryShopifyLinkImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryShopifyLinkImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('services.shopify.store_domain', 'test-store.myshopify.com');
        config()->set('services.shopify.client_id', 'client-id-123');
        config()->set('services.shopify.client_secret', 'secret-abc-456');
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('status')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id', 30);
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['meli_order_id', 'item_id']);
        });
        (require database_path('migrations/2026_09_27_000001_add_order_reservation_identity.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_channel_order_allocations');
        Schema::dropIfExists('meli_order_items');
        Schema::dropIfExists('meli_orders');
        Schema::dropIfExists('inventory_channel_stock_syncs');
        Schema::dropIfExists('inventory_channel_links');
        Schema::dropIfExists('inventory_kit_reservations');
        Schema::dropIfExists('inventory_kit_components');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function product(string $sku, ?string $barcode = null, string $name = 'Producto Prueba'): InventoryProduct
    {
        return InventoryProduct::create([
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $name,
            'product_type' => 'simple',
            'cost' => 100,
            'is_active' => true,
        ]);
    }

    public function test_preview_matches_by_exact_sku_and_exact_barcode(): void
    {
        $p1 = $this->product('SKU-100', '7501000001', 'Shampoo 100');
        $p2 = $this->product('SKU-200', '7501000002', 'Acondicionador 200');

        $mockProducts = [
            [
                'id' => 1001,
                'title' => 'Shampoo 100 Shopify',
                'variants' => [
                    [
                        'id' => 9001,
                        'product_id' => 1001,
                        'inventory_item_id' => 8001,
                        'sku' => 'SKU-100',
                        'barcode' => '',
                        'price' => '350.00',
                    ],
                    [
                        'id' => 9002,
                        'product_id' => 1001,
                        'inventory_item_id' => 8002,
                        'sku' => '',
                        'barcode' => '7501000002',
                        'price' => '390.00',
                    ],
                ],
            ],
        ];

        $service = app(InventoryShopifyLinkImportService::class);
        $preview = $service->preview([], $mockProducts);

        $this->assertSame(2, $preview['counts'][InventoryShopifyLinkImportService::MATCHED]);
        $this->assertSame($p1->id, $preview['rows'][0]['inventory_product_id']);
        $this->assertSame('MATCHED', $preview['rows'][0]['status']);
        $this->assertSame($p2->id, $preview['rows'][1]['inventory_product_id']);
        $this->assertSame('MATCHED', $preview['rows'][1]['status']);
    }

    public function test_preview_matches_with_leading_zeros_stripped(): void
    {
        $p = $this->product('74469517355', null, 'Joico K-Pak');

        $mockProducts = [
            [
                'id' => 2001,
                'title' => 'Joico K-Pak',
                'variants' => [
                    [
                        'id' => 9101,
                        'product_id' => 2001,
                        'inventory_item_id' => 8101,
                        'sku' => '074469517355',
                        'barcode' => '',
                        'price' => '450.00',
                    ],
                ],
            ],
        ];

        $service = app(InventoryShopifyLinkImportService::class);
        $preview = $service->preview([], $mockProducts);

        $this->assertSame(1, $preview['counts'][InventoryShopifyLinkImportService::MATCHED]);
        $this->assertSame($p->id, $preview['rows'][0]['inventory_product_id']);
    }

    public function test_preview_flags_missing_sku_and_product_not_found(): void
    {
        $mockProducts = [
            [
                'id' => 3001,
                'title' => 'Producto Desconocido',
                'variants' => [
                    [
                        'id' => 9201,
                        'product_id' => 3001,
                        'inventory_item_id' => 8201,
                        'sku' => '',
                        'barcode' => '',
                        'price' => '100.00',
                    ],
                    [
                        'id' => 9202,
                        'product_id' => 3001,
                        'inventory_item_id' => 8202,
                        'sku' => 'INEXISTENTE-999',
                        'barcode' => '',
                        'price' => '200.00',
                    ],
                ],
            ],
        ];

        $service = app(InventoryShopifyLinkImportService::class);
        $preview = $service->preview([], $mockProducts);

        $this->assertSame(1, $preview['counts'][InventoryShopifyLinkImportService::MISSING_SKU]);
        $this->assertSame(1, $preview['counts'][InventoryShopifyLinkImportService::PRODUCT_NOT_FOUND]);
    }

    public function test_apply_creates_links_with_safe_defaults(): void
    {
        $p = $this->product('SKU-SAFE-1', null, 'Producto Seguro');

        $mockProducts = [
            [
                'id' => 4001,
                'title' => 'Producto Seguro Shopify',
                'variants' => [
                    [
                        'id' => 9301,
                        'product_id' => 4001,
                        'inventory_item_id' => 8301,
                        'sku' => 'SKU-SAFE-1',
                        'price' => '500.00',
                    ],
                ],
            ],
        ];

        $service = app(InventoryShopifyLinkImportService::class);
        $result = $service->apply([], $mockProducts);

        $this->assertSame(1, $result['imported']);
        $this->assertEmpty($result['errors']);

        $link = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::SHOPIFY)
            ->where('external_variant_id', '9301')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($p->id, $link->inventory_product_id);
        $this->assertSame('test-store.myshopify.com', $link->account_key);
        $this->assertSame('4001', $link->external_listing_id);
        $this->assertSame('9301', $link->external_variant_id);
        $this->assertSame('8301', $link->external_product_id);
        $this->assertFalse((bool) $link->stock_sync_enabled);
        $this->assertFalse((bool) $link->order_reservation_enabled);
        $this->assertTrue((bool) $link->is_active);

        // Si se vuelve a ejecutar preview, debe ser ALREADY_LINKED
        $preview2 = $service->preview([], $mockProducts);
        $this->assertSame(1, $preview2['counts'][InventoryShopifyLinkImportService::ALREADY_LINKED]);
    }

    public function test_artisan_command_shopify_link_import_runs(): void
    {
        $this->product('SKU-CMD-1');

        $mockClient = $this->createMock(InventoryShopifyClient::class);
        $mockClient->method('getStoreDomain')->willReturn('test-store.myshopify.com');
        $mockClient->method('getProducts')->willReturn([
            'products' => [
                [
                    'id' => 5001,
                    'title' => 'Cmd Product',
                    'variants' => [
                        [
                            'id' => 9401,
                            'product_id' => 5001,
                            'inventory_item_id' => 8401,
                            'sku' => 'SKU-CMD-1',
                            'price' => '250.00',
                        ],
                    ],
                ],
            ],
        ]);

        $this->app->instance(InventoryShopifyClient::class, $mockClient);

        // Dry run
        $this->artisan('inventory:shopify-link-import')
            ->expectsOutputToContain('Modo preview (dry-run)')
            ->assertSuccessful();

        // Apply
        $this->artisan('inventory:shopify-link-import', ['--apply' => true])
            ->expectsOutputToContain('Importación completada: 1 vínculo(s) creado(s).')
            ->assertSuccessful();
    }
}
