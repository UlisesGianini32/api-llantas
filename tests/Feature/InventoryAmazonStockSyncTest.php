<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;
use App\Models\InventoryKitComponent;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Services\Amazon\InventoryAmazonStockSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryAmazonStockSyncTest extends TestCase
{
    private InventoryLocation $location;

    private string $sellerId = 'SELLER_AMZ_TEST';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('services.amazon.lwa_client_id', 'client-id-123');
        config()->set('services.amazon.lwa_client_secret', 'secret-abc-456');
        config()->set('services.amazon.lwa_refresh_token', 'refresh-token-789');
        config()->set('services.amazon.seller_id', $this->sellerId);
        config()->set('services.amazon.marketplace_id', 'A1AM78C64UM0Y8');
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
            'name' => 'Almacén Central',
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
            'channel' => InventoryChannelLink::AMAZON,
            'account_key' => $this->sellerId,
            'external_listing_id' => 'AMZ-SKU-101',
            'external_product_id' => 'B08TEST001',
            'is_active' => true,
            'stock_sync_enabled' => false,
            'identity_key' => "amazon|account:{$this->sellerId}|listing:AMZ-SKU-101",
        ], $attributes));
    }

    public function test_new_links_with_sync_disabled_are_skipped(): void
    {
        $product = $this->product('AMZ-PROD-1');
        $this->movement($product, 10);
        $link = $this->link($product);

        $service = app(InventoryAmazonStockSyncService::class);
        $preview = $service->preview();

        $this->assertSame(1, $preview['counts'][InventoryAmazonStockSyncService::SKIPPED_SYNC_DISABLED]);
        $this->assertSame('SKIPPED_SYNC_DISABLED', $preview['rows'][0]['status']);
    }

    public function test_stock_sync_preview_calculates_target_correctly(): void
    {
        $product = $this->product('AMZ-PROD-2');
        $this->movement($product, 18);
        $this->reserve($product, 5);

        $link = $this->link($product, [
            'external_listing_id' => 'AMZ-SKU-102',
            'identity_key' => "amazon|account:{$this->sellerId}|listing:AMZ-SKU-102",
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryAmazonStockSyncService::class);
        $preview = $service->preview();

        $this->assertSame('READY', $preview['rows'][0]['status']);
        $this->assertSame(18, $preview['rows'][0]['physical']);
        $this->assertSame(5, $preview['rows'][0]['reserved']);
        $this->assertSame(13, $preview['rows'][0]['available']);
        $this->assertSame(13, $preview['rows'][0]['target']);
    }

    public function test_kit_stock_sync_calculates_availability_from_components(): void
    {
        $compA = $this->product('COMP-X');
        $compB = $this->product('COMP-Y');
        $this->movement($compA, 12); // 12 / 3 = 4 kits
        $this->movement($compB, 10); // 10 / 1 = 10 kits

        $kit = $this->product('KIT-AMZ', InventoryProduct::KIT);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compA->id, 'quantity' => 3]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $compB->id, 'quantity' => 1]);

        $link = $this->link($kit, [
            'external_listing_id' => 'AMZ-KIT-SKU',
            'identity_key' => "amazon|account:{$this->sellerId}|listing:AMZ-KIT-SKU",
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryAmazonStockSyncService::class);
        $preview = $service->preview();

        $this->assertSame('READY', $preview['rows'][0]['status']);
        $this->assertSame(4, $preview['rows'][0]['physical']);
        $this->assertSame(4, $preview['rows'][0]['target']);
    }

    public function test_sync_link_updates_quantity_via_listings_api_and_records_audit(): void
    {
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'mock-lwa-token'], 200),
            'https://sellingpartnerapi-na.amazon.com/listings/2021-08-01/items/*' => Http::response([
                'status' => 'ACCEPTED',
                'submissionId' => 'sub-12345',
            ], 200),
        ]);

        $product = $this->product('AMZ-PROD-SUCCESS');
        $this->movement($product, 25);

        $link = $this->link($product, [
            'external_listing_id' => 'AMZ-SKU-SUCCESS',
            'identity_key' => "amazon|account:{$this->sellerId}|listing:AMZ-SKU-SUCCESS",
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryAmazonStockSyncService::class);
        $result = $service->syncLink($link);

        $this->assertSame(InventoryChannelStockSync::SUCCESS, $result['status']);
        $this->assertNotNull($link->fresh()->last_synced_at);

        $audit = InventoryChannelStockSync::where('inventory_channel_link_id', $link->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(InventoryChannelStockSync::SUCCESS, $audit->status);
        $this->assertSame(25, $audit->target_quantity);
        $this->assertSame(25, $audit->verified_quantity);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'listings/2021-08-01/items')
                && $request->method() === 'PATCH'
                && data_get($request->data(), 'patches.0.value.0.quantity') === 25;
        });
    }

    public function test_sync_link_records_failure_on_api_error(): void
    {
        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'mock-lwa-token'], 200),
            'https://sellingpartnerapi-na.amazon.com/listings/2021-08-01/items/*' => Http::response([
                'errors' => [['code' => 'Unauthorized', 'message' => 'Access denied']],
            ], 403),
        ]);

        $product = $this->product('AMZ-PROD-FAIL');
        $this->movement($product, 5);

        $link = $this->link($product, [
            'external_listing_id' => 'AMZ-SKU-FAIL',
            'identity_key' => "amazon|account:{$this->sellerId}|listing:AMZ-SKU-FAIL",
            'stock_sync_enabled' => true,
        ]);

        $service = app(InventoryAmazonStockSyncService::class);
        $result = $service->syncLink($link);

        $this->assertSame(InventoryChannelStockSync::FAILED, $result['status']);

        $audit = InventoryChannelStockSync::where('inventory_channel_link_id', $link->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(InventoryChannelStockSync::FAILED, $audit->status);
        $this->assertStringContainsString('Amazon SP-API error [403]', $audit->error_message);
    }

    public function test_artisan_command_amazon_stock_sync_runs(): void
    {
        $product = $this->product('AMZ-CMD-SYNC');
        $this->movement($product, 30);
        $link = $this->link($product, [
            'external_listing_id' => 'AMZ-CMD-SKU',
            'identity_key' => "amazon|account:{$this->sellerId}|listing:AMZ-CMD-SKU",
            'stock_sync_enabled' => true,
        ]);

        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'mock-lwa-token'], 200),
            'https://sellingpartnerapi-na.amazon.com/listings/2021-08-01/items/*' => Http::response(['status' => 'ACCEPTED'], 200),
        ]);

        // Dry-run
        $this->artisan('inventory:amazon-stock-sync')
            ->expectsOutputToContain('Dry-run: no se modificó Amazon.')
            ->assertSuccessful();

        // Apply
        $this->artisan('inventory:amazon-stock-sync', ['--apply' => true])
            ->expectsOutputToContain('Sincronizaciones exitosas en Amazon: 1.')
            ->assertSuccessful();
    }
}
