<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryChannelLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

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
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('llantas', fn (Blueprint $table) => $table->id());
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            $migration = require $path;
            $migration->up();
        }
        $migration = require database_path('migrations/2026_09_25_000001_create_inventory_channel_links_table.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_channel_links');
        Schema::dropIfExists('inventory_kit_reservations');
        Schema::dropIfExists('inventory_kit_components');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('inventory_movements');
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropForeign(['primary_location_id']);
            $table->dropIndex(['product_type']);
        });
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_admin_can_create_valid_links_for_each_channel(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('LINK-001');
        foreach ([
            ['channel' => 'mercado_libre', 'external_listing_id' => 'MLM-1'],
            ['channel' => 'amazon', 'external_product_id' => 'ASIN-1'],
            ['channel' => 'shopify', 'external_variant_id' => 'VAR-1'],
        ] as $data) {
            $this->post(route('inventory.channels.store'), ['inventory_product_id' => $product->id, ...$data])->assertRedirect();
        }
        $this->assertDatabaseCount('inventory_channel_links', 3);
    }

    public function test_channel_specific_identifiers_are_required(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('LINK-002');
        foreach (['mercado_libre', 'amazon', 'shopify'] as $channel) {
            $this->from(route('inventory.channels.create'))
                ->post(route('inventory.channels.store'), ['inventory_product_id' => $product->id, 'channel' => $channel])
                ->assertRedirect(route('inventory.channels.create'))
                ->assertSessionHasErrors();
        }
    }

    public function test_duplicate_external_identity_is_rejected_but_accounts_and_channels_are_scoped(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('LINK-003');
        $payload = ['inventory_product_id' => $product->id, 'channel' => 'mercado_libre', 'account_key' => 'one', 'external_listing_id' => 'MLM-3'];
        $this->post(route('inventory.channels.store'), $payload)->assertRedirect();
        $this->post(route('inventory.channels.store'), $payload)->assertSessionHasErrors();
        $this->post(route('inventory.channels.store'), [...$payload, 'account_key' => 'two'])->assertRedirect();
        $this->post(route('inventory.channels.store'), [...$payload, 'channel' => 'amazon', 'external_listing_id' => 'MLM-3', 'external_product_id' => null])->assertRedirect();
        $this->assertDatabaseCount('inventory_channel_links', 3);
    }

    public function test_operations_can_list_and_view_but_cannot_manage_links(): void
    {
        $product = $this->product('OPS-LINK');
        $link = InventoryChannelLink::create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'VAR-OPS', 'identity_key' => 'shopify|account:-|variant:VAR-OPS']);
        $this->actingAs($this->operations());
        $this->get(route('inventory.channels.index'))->assertInertia(fn (Assert $page) => $page->component('Inventory/Channels/Index'));
        $this->get(route('inventory.channels.show', $link))->assertInertia(fn (Assert $page) => $page->component('Inventory/Channels/Show'));
        $this->get(route('inventory.channels.create'))->assertForbidden();
        $this->get(route('inventory.channels.edit', $link))->assertForbidden();
        $this->patch(route('inventory.channels.toggle', $link))->assertForbidden();
    }

    public function test_index_searches_product_and_external_identifiers_and_filters_channel(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('SEARCH-SKU', 'Código buscable');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'amazon', 'external_product_id' => 'ASIN-SEARCH', 'metadata' => ['source' => 'manual']]);
        $this->get(route('inventory.channels.index', ['search' => 'SEARCH-SKU', 'channel' => 'amazon']))
            ->assertInertia(fn (Assert $page) => $page->component('Inventory/Channels/Index')->where('links.data.0.id', $link->id));
    }

    public function test_prices_metadata_toggle_and_product_detail_are_supported_without_stock_fields(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('DETAIL-LINK');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'amazon', 'external_product_id' => 'ASIN-D', 'remote_price' => '12.50', 'remote_currency' => 'mxn', 'metadata' => ['manual' => true]]);
        $this->assertSame('12.50', (string) $link->remote_price);
        $this->assertSame(['manual' => true], $link->metadata);
        $this->patch(route('inventory.channels.toggle', $link))->assertRedirect();
        $this->assertFalse($link->fresh()->is_active);
        $this->get(route('inventory.products.show', $product))->assertInertia(fn (Assert $page) => $page->where('channelLinks.0.id', $link->id));
        $columns = Schema::getColumnListing('inventory_channel_links');
        $this->assertNotContains('stock', $columns);
        $this->assertNotContains('remote_stock', $columns);
        $this->assertNotContains('available_stock', $columns);
    }

    public function test_negative_remote_price_and_invalid_channel_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('INVALID-LINK');
        $this->from(route('inventory.channels.create'))->post(route('inventory.channels.store'), ['inventory_product_id' => $product->id, 'channel' => 'other', 'remote_price' => '-1'])->assertSessionHasErrors();
        $this->assertDatabaseCount('inventory_channel_links', 0);
    }

    public function test_nonexistent_product_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('inventory.channels.store'), ['inventory_product_id' => 999, 'channel' => 'shopify', 'external_variant_id' => 'V'])->assertSessionHasErrors('inventory_product_id');
    }

    public function test_same_product_can_have_multiple_listings_on_one_channel(): void
    {
        $product = $this->product('MULTI-LISTING');
        $service = app(\App\Services\InventoryChannelLinkService::class);
        $service->create(['inventory_product_id' => $product->id, 'channel' => 'mercado_libre', 'external_listing_id' => 'MLM-A']);
        $service->create(['inventory_product_id' => $product->id, 'channel' => 'mercado_libre', 'external_listing_id' => 'MLM-B']);
        $this->assertDatabaseCount('inventory_channel_links', 2);
    }

    public function test_zero_and_null_remote_price_are_allowed(): void
    {
        $product = $this->product('PRICE-LINK');
        $service = app(\App\Services\InventoryChannelLinkService::class);
        $zero = $service->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-ZERO', 'remote_price' => 0]);
        $null = $service->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-NULL']);
        $this->assertSame('0.00', (string) $zero->remote_price);
        $this->assertNull($null->remote_price);
    }

    public function test_https_url_is_stored_and_channel_is_normalized(): void
    {
        $product = $this->product('URL-LINK');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => ' AMAZON ', 'external_product_id' => 'ASIN-URL', 'external_url' => 'https://example.test/item']);
        $this->assertSame('amazon', $link->channel);
        $this->assertSame('https://example.test/item', $link->external_url);
    }

    public function test_update_recomputes_identity_without_losing_metadata(): void
    {
        $product = $this->product('UPDATE-LINK');
        $service = app(\App\Services\InventoryChannelLinkService::class);
        $link = $service->create(['inventory_product_id' => $product->id, 'channel' => 'amazon', 'external_product_id' => 'ASIN-OLD', 'metadata' => ['keep' => true]]);
        $updated = $service->update($link, ['external_product_id' => 'ASIN-NEW']);
        $this->assertSame('ASIN-NEW', $updated->external_product_id);
        $this->assertSame(['keep' => true], $updated->metadata);
    }

    public function test_same_external_identifier_is_allowed_for_different_accounts(): void
    {
        $product = $this->product('ACCOUNT-LINK');
        $service = app(\App\Services\InventoryChannelLinkService::class);
        $service->create(['inventory_product_id' => $product->id, 'channel' => 'amazon', 'account_key' => 'A', 'external_product_id' => 'ASIN-SHARED']);
        $service->create(['inventory_product_id' => $product->id, 'channel' => 'amazon', 'account_key' => 'B', 'external_product_id' => 'ASIN-SHARED']);
        $this->assertDatabaseCount('inventory_channel_links', 2);
    }

    public function test_operations_cannot_store_update_or_toggle(): void
    {
        $product = $this->product('OPS-WRITE');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-WRITE']);
        $this->actingAs($this->operations());
        $this->post(route('inventory.channels.store'), ['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-2'])->assertForbidden();
        $this->patch(route('inventory.channels.update', $link), ['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-3'])->assertForbidden();
        $this->patch(route('inventory.channels.toggle', $link))->assertForbidden();
    }

    public function test_channel_routes_have_no_delete_endpoint(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory.channels.destroy'));
    }

    public function test_update_rejects_an_identity_owned_by_another_link(): void
    {
        $product = $this->product('RESTRICT-LINK');
        $service = app(\App\Services\InventoryChannelLinkService::class);
        $service->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-RESTRICT']);
        $other = $service->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-OTHER']);
        $this->expectException(\InvalidArgumentException::class);
        $service->update($other, ['external_variant_id' => 'V-RESTRICT']);
    }

    public function test_index_searches_barcode_and_external_id(): void
    {
        $this->actingAs($this->admin());
        $product = InventoryProduct::create(['sku' => 'BARCODE-LINK', 'barcode' => '7501234567890', 'name' => 'Barcode link']);
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'VAR-BARCODE']);
        foreach (['7501234567890', 'VAR-BARCODE'] as $search) {
            $this->get(route('inventory.channels.index', compact('search')))->assertInertia(fn (Assert $page) => $page->where('links.data.0.id', $link->id));
        }
    }

    public function test_index_filters_inactive_links(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('FILTER-LINK');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-FILTER']);
        $link->update(['is_active' => false]);
        $this->get(route('inventory.channels.index', ['active' => '0']))->assertInertia(fn (Assert $page) => $page->where('links.data.0.id', $link->id));
        $this->get(route('inventory.channels.index', ['active' => '1']))->assertInertia(fn (Assert $page) => $page->where('links.data', []));
    }

    public function test_index_filters_each_supported_channel(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('CHANNEL-FILTER');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'amazon', 'external_product_id' => 'ASIN-FILTER']);
        $this->get(route('inventory.channels.index', ['channel' => 'amazon']))->assertInertia(fn (Assert $page) => $page->where('links.data.0.id', $link->id));
    }

    public function test_invalid_external_url_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $product = $this->product('BAD-URL');
        $this->post(route('inventory.channels.store'), ['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-BAD', 'external_url' => 'javascript:alert(1)'])->assertSessionHasErrors('external_url');
    }

    public function test_channel_model_helpers_and_labels_are_stable(): void
    {
        $product = $this->product('HELPER-LINK');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'mercado_libre', 'external_listing_id' => 'MLM-HELPER']);
        $this->assertTrue($link->isMercadoLibre());
        $this->assertFalse($link->isAmazon());
        $this->assertSame('Mercado Libre', InventoryChannelLink::channelLabel($link->channel));
        $this->assertSame('MLM-HELPER', $link->externalIdentifier());
    }

    public function test_account_key_is_optional_for_legacy_links(): void
    {
        $product = $this->product('LEGACY-LINK');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'mercado_libre', 'external_listing_id' => 'MLM-LEGACY']);
        $this->assertNull($link->account_key);
    }

    public function test_metadata_and_last_synced_at_are_cast(): void
    {
        $product = $this->product('CAST-LINK');
        $link = app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $product->id, 'channel' => 'shopify', 'external_variant_id' => 'V-CAST', 'last_synced_at' => '2026-09-25 12:00:00', 'metadata' => ['kind' => 'manual']]);
        $this->assertIsArray($link->metadata);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $link->last_synced_at);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function operations(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATIONS]);
    }

    private function product(string $sku, string $name = 'Producto de enlace'): InventoryProduct
    {
        return InventoryProduct::create(['sku' => $sku, 'name' => $name]);
    }
}
