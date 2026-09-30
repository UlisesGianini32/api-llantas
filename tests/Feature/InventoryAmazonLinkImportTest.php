<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Services\Amazon\InventoryAmazonLinkImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryAmazonLinkImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('services.amazon.lwa_client_id', 'amzn-client-id');
        config()->set('services.amazon.lwa_client_secret', 'amzn-client-secret');
        config()->set('services.amazon.lwa_refresh_token', 'amzn-refresh-token');
        config()->set('services.amazon.seller_id', 'SELLER_123');
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
            'product_type' => InventoryProduct::SIMPLE,
            'cost' => 100,
            'is_active' => true,
        ]);
    }

    public function test_preview_matches_by_exact_sku_and_barcode(): void
    {
        $p1 = $this->product('AMZ-SKU-1', null, 'Llantas R15');
        $p2 = $this->product('AMZ-SKU-2', '7509990001', 'Llantas R16');

        $mockItems = [
            [
                'seller_sku' => 'AMZ-SKU-1',
                'asin' => 'B08XYZ0001',
                'title' => 'Llantas R15 en Amazon',
                'price' => '1200.00',
            ],
            [
                'seller_sku' => 'AMZ-DIFF-SKU',
                'barcode' => '7509990001',
                'asin' => 'B08XYZ0002',
                'title' => 'Llantas R16 en Amazon',
                'price' => '1500.00',
            ],
        ];

        $service = app(InventoryAmazonLinkImportService::class);
        $preview = $service->preview([], $mockItems);

        $this->assertSame(2, $preview['counts'][InventoryAmazonLinkImportService::MATCHED]);
        $this->assertSame($p1->id, $preview['rows'][0]['inventory_product_id']);
        $this->assertSame($p2->id, $preview['rows'][1]['inventory_product_id']);
    }

    public function test_preview_matches_with_leading_zeros_stripped(): void
    {
        $p = $this->product('74469502214', null, 'Shampoo Joico');

        $mockItems = [
            [
                'seller_sku' => '074469502214', // con ceros
                'asin' => 'B08JOICO01',
                'title' => 'Shampoo Joico Amazon',
            ],
        ];

        $service = app(InventoryAmazonLinkImportService::class);
        $preview = $service->preview([], $mockItems);

        $this->assertSame(1, $preview['counts'][InventoryAmazonLinkImportService::MATCHED]);
        $this->assertSame($p->id, $preview['rows'][0]['inventory_product_id']);
    }

    public function test_preview_identifies_already_linked_and_not_found(): void
    {
        $p = $this->product('AMZ-LINKED-1');

        InventoryChannelLink::create([
            'channel' => InventoryChannelLink::AMAZON,
            'account_key' => 'SELLER_123',
            'inventory_product_id' => $p->id,
            'external_listing_id' => 'AMZ-LINKED-1',
            'external_product_id' => 'B08LINKED01',
            'identity_key' => 'amazon|account:SELLER_123|listing:AMZ-LINKED-1',
            'is_active' => true,
        ]);

        $mockItems = [
            [
                'seller_sku' => 'AMZ-LINKED-1',
                'asin' => 'B08LINKED01',
                'title' => 'Ya vinculado',
            ],
            [
                'seller_sku' => 'INEXISTENTE-AMZ',
                'asin' => 'B08NOTFOUND',
                'title' => 'No encontrado',
            ],
        ];

        $service = app(InventoryAmazonLinkImportService::class);
        $preview = $service->preview([], $mockItems);

        $this->assertSame(1, $preview['counts'][InventoryAmazonLinkImportService::ALREADY_LINKED]);
        $this->assertSame(1, $preview['counts'][InventoryAmazonLinkImportService::PRODUCT_NOT_FOUND]);
    }

    public function test_apply_creates_amazon_links_with_safe_defaults(): void
    {
        $p = $this->product('AMZ-APPLY-1', null, 'Producto Amazon Seguro');

        $mockItems = [
            [
                'seller_sku' => 'AMZ-APPLY-1',
                'asin' => 'B08APPLY01',
                'title' => 'Producto Amazon Seguro',
                'price' => '850.00',
            ],
        ];

        $service = app(InventoryAmazonLinkImportService::class);
        $result = $service->apply([], $mockItems);

        $this->assertSame(1, $result['imported']);
        $this->assertEmpty($result['errors']);

        $link = InventoryChannelLink::query()
            ->where('channel', InventoryChannelLink::AMAZON)
            ->where('external_listing_id', 'AMZ-APPLY-1')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($p->id, $link->inventory_product_id);
        $this->assertSame('SELLER_123', $link->account_key);
        $this->assertSame('AMZ-APPLY-1', $link->external_listing_id);
        $this->assertSame('B08APPLY01', $link->external_product_id);
        $this->assertFalse((bool) $link->stock_sync_enabled);
        $this->assertFalse((bool) $link->order_reservation_enabled);
        $this->assertTrue((bool) $link->is_active);
    }

    public function test_artisan_command_amazon_link_import_runs(): void
    {
        $this->product('AMZ-CMD-1');

        $mockService = $this->createMock(InventoryAmazonLinkImportService::class);
        $mockService->method('preview')->willReturn([
            'counts' => [InventoryAmazonLinkImportService::MATCHED => 1],
            'rows' => [
                [
                    'status' => 'MATCHED',
                    'seller_sku' => 'AMZ-CMD-1',
                    'asin' => 'B08CMD01',
                    'barcode' => '',
                    'inventory_product_id' => 1,
                    'inventory_product_sku' => 'AMZ-CMD-1',
                    'amazon_title' => 'Cmd Product',
                ],
            ],
        ]);
        $mockService->method('apply')->willReturn([
            'imported' => 1,
            'errors' => [],
        ]);

        $this->app->instance(InventoryAmazonLinkImportService::class, $mockService);

        // Dry run
        $this->artisan('inventory:amazon-link-import')
            ->expectsOutputToContain('Modo preview (dry-run)')
            ->assertSuccessful();

        // Apply
        $this->artisan('inventory:amazon-link-import', ['--apply' => true])
            ->expectsOutputToContain('Importación completada: 1 vínculo(s) creado(s).')
            ->assertSuccessful();
    }
}
