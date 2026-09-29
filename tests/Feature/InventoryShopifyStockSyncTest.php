<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;
use App\Models\InventoryKitComponent;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Services\Shopify\InventoryShopifyStockSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryShopifyStockSyncTest extends TestCase
{
    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('services.shopify.store_domain', 'test-store.myshopify.com');
        config()->set('services.shopify.client_id', 'client-id-123');
        config()->set('services.shopify.client_secret', 'secret-abc-456');
        config()->set('services.shopify.api_version', '2025-01');
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

        $this->location = InventoryLocation::create([
            'name' => 'Almacén Principal',
            'code' => 'ALM-1',
            'is_active' => true,
        ]);
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

    private function product(string $sku, string $type = InventoryProduct::SIMPLE): InventoryProduct
    {
        return InventoryProduct::create([
            'sku' => $sku,
            'name' => 'Prod '.$sku,
            'product_type' => $type,
            'primary_location_id' => $this->location->id,
            'cost' => 100,
            'is_active' => true,
        ]);
    }

    private function movement(InventoryProduct $product, int $quantity): InventoryMovement
    {
        return InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location->id,
            'type' => InventoryMovement::RECEIPT,
            'quantity' => $quantity,
            'occurred_at' => now(),
        ]);
    }

    private function reserve(InventoryProduct $product, int $quantity): InventoryReservation
    {
        return InventoryReservation::create([
            'inventory_product_id' => $product->id,
            'quantity' => $quantity,
            'status' => InventoryReservation::ACTIVE,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function link(InventoryProduct $product, array $attributes = []): InventoryChannelLink
    {
        return InventoryChannelLink::create(array_merge([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::SHOPIFY,
            'account_key' => 'test-store.myshopify.com',
            'external_listing_id' => '10001',
            'external_variant_id' => '20001',
            'external_product_id' => '30001', // inventory_item_id
            'is_active' => true,
            'stock_sync_enabled' => false,
            'identity_key' => 'shopify|account:test-store.myshopify.com|variant:20001',
        ], $attributes));
    }

    public function test_new_links_with_sync_disabled_have_status_skipped(): void
    {
        $product = $this->product('SHOPIFY-SKU-1');
        $this->movement($product, 10);
        $link = $this->link($product);

        $service = app(InventoryShopifyStockSyncService::class);
        $preview = $service->preview();

        $this->assertSame(1, $preview['counts'][InventoryShopifyStockSyncService::SKIPPED_SYNC_DISABLED]);
        $this->assertSame('SKIPPED_SYNC_DISABLED', $preview['rows'][0]['status']);
    }

    public function test_stock_sync_preview_calculates_available_stock_correctly(): void
    {
        $product = $this->product('SHOPIFY-SKU-2');
        $this->movement($product, 15);
        $this->reserve($product, 4);

        $link = $this->link($product, [
            'external_variant_id' => '20002',
            'identity_key' => 'shopify|account:test-store.myshopify.com|variant:20002',
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryShopifyStockSyncService::class);
        $preview = $service->preview();

        $this->assertSame('READY', $preview['rows'][0]['status']);
        $this->assertSame(15, $preview['rows'][0]['physical']);
        $this->assertSame(4, $preview['rows'][0]['reserved']);
        $this->assertSame(11, $preview['rows'][0]['available']);
        $this->assertSame(11, $preview['rows'][0]['target']);
    }

    public function test_kit_stock_sync_preview_calculates_available_based_on_components(): void
    {
        $compA = $this->product('COMP-A');
        $compB = $this->product('COMP-B');
        $this->movement($compA, 10); // 10 / 2 = 5 kits
        $this->movement($compB, 8);  // 8 / 1 = 8 kits

        $kit = $this->product('KIT-SHOPIFY', InventoryProduct::KIT);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compA->id, 'quantity' => 2]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compB->id, 'quantity' => 1]);

        $link = $this->link($kit, [
            'external_variant_id' => '20003',
            'identity_key' => 'shopify|account:test-store.myshopify.com|variant:20003',
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryShopifyStockSyncService::class);
        $preview = $service->preview();

        $this->assertSame('READY', $preview['rows'][0]['status']);
        $this->assertSame(5, $preview['rows'][0]['physical']);
        $this->assertSame(5, $preview['rows'][0]['target']);
    }

    public function test_sync_link_updates_inventory_level_in_shopify_and_records_success_audit(): void
    {
        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'dummy-token'], 200),
            'https://test-store.myshopify.com/admin/api/2025-01/locations.json' => Http::response([
                'locations' => [
                    ['id' => 9999, 'name' => 'Hermosillo Warehouse', 'active' => true],
                ],
            ], 200),
            'https://test-store.myshopify.com/admin/api/2025-01/inventory_levels/set.json' => Http::response([
                'inventory_level' => [
                    'inventory_item_id' => 30004,
                    'location_id' => 9999,
                    'available' => 14,
                ],
            ], 200),
        ]);

        $product = $this->product('SHOPIFY-SKU-4');
        $this->movement($product, 14);

        $link = $this->link($product, [
            'external_variant_id' => '20004',
            'external_product_id' => '30004',
            'identity_key' => 'shopify|account:test-store.myshopify.com|variant:20004',
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryShopifyStockSyncService::class);
        $result = $service->syncLink($link);

        $this->assertSame(InventoryChannelStockSync::SUCCESS, $result['status']);
        $this->assertNotNull($link->fresh()->last_synced_at);

        $audit = InventoryChannelStockSync::where('inventory_channel_link_id', $link->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(InventoryChannelStockSync::SUCCESS, $audit->status);
        $this->assertSame(14, $audit->target_quantity);
        $this->assertSame(14, $audit->verified_quantity);
        $this->assertSame(InventoryChannelStockSync::VERIFIED, $audit->verification_status);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://test-store.myshopify.com/admin/api/2025-01/inventory_levels/set.json'
                && $request['location_id'] === '9999'
                && $request['inventory_item_id'] === '30004'
                && $request['available'] === 14;
        });
    }

    public function test_sync_link_handles_failure_and_records_failed_audit(): void
    {
        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'dummy-token'], 200),
            'https://test-store.myshopify.com/admin/api/2025-01/locations.json' => Http::response([
                'locations' => [
                    ['id' => 9999, 'name' => 'Hermosillo Warehouse', 'active' => true],
                ],
            ], 200),
            'https://test-store.myshopify.com/admin/api/2025-01/inventory_levels/set.json' => Http::response([
                'errors' => 'Rate limit exceeded',
            ], 429),
        ]);

        $product = $this->product('SHOPIFY-SKU-FAIL');
        $this->movement($product, 10);

        $link = $this->link($product, [
            'external_variant_id' => '20005',
            'external_product_id' => '30005',
            'identity_key' => 'shopify|account:test-store.myshopify.com|variant:20005',
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryShopifyStockSyncService::class);
        $result = $service->syncLink($link);

        $this->assertSame(InventoryChannelStockSync::FAILED, $result['status']);

        $audit = InventoryChannelStockSync::where('inventory_channel_link_id', $link->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(InventoryChannelStockSync::FAILED, $audit->status);
        $this->assertStringContainsString('Shopify API error [429]', $audit->error_message);
    }

    public function test_artisan_command_shopify_stock_sync_runs(): void
    {
        $product = $this->product('SHOPIFY-CMD-SYNC');
        $this->movement($product, 20);
        $link = $this->link($product, [
            'external_variant_id' => '20006',
            'external_product_id' => '30006',
            'identity_key' => 'shopify|account:test-store.myshopify.com|variant:20006',
            'stock_sync_enabled' => true,
        ]);

        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'dummy-token'], 200),
            'https://test-store.myshopify.com/admin/api/2025-01/locations.json' => Http::response([
                'locations' => [
                    ['id' => 8888, 'name' => 'Main', 'active' => true],
                ],
            ], 200),
            'https://test-store.myshopify.com/admin/api/2025-01/inventory_levels/set.json' => Http::response([
                'inventory_level' => ['available' => 20],
            ], 200),
        ]);

        // Dry-run
        $this->artisan('inventory:shopify-stock-sync')
            ->expectsOutputToContain('Dry-run: no se modificó Shopify.')
            ->assertSuccessful();

        // Apply
        $this->artisan('inventory:shopify-stock-sync', ['--apply' => true])
            ->expectsOutputToContain('Sincronizaciones exitosas en Shopify: 1.')
            ->assertSuccessful();
    }
}
