<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliFullStock;
use App\Models\MeliPublication;
use App\Models\User;
use App\Services\MeliFullShipmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryProductMultiChannelLinkTest extends TestCase
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
            $table->string('role', 32)->default('admin');
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('meli_user_id')->nullable();
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

        if (! Schema::hasColumn('inventory_channel_links', 'stock_sync_enabled')) {
            Schema::table('inventory_channel_links', function (Blueprint $table) {
                $table->boolean('stock_sync_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('inventory_channel_links', 'order_reservation_enabled')) {
            Schema::table('inventory_channel_links', function (Blueprint $table) {
                $table->boolean('order_reservation_enabled')->default(true);
            });
        }

        if (! Schema::hasColumn('inventory_products', 'weight_kg')) {
            Schema::table('inventory_products', function (Blueprint $table) {
                $table->decimal('weight_kg', 8, 3)->default(1.000);
            });
        }

        Schema::create('meli_full_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('meli_publication_id')->nullable();
            $table->string('stock_key')->nullable();
            $table->string('mlm')->nullable();
            $table->string('variation_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('title')->nullable();
            $table->string('variation_label')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('permalink')->nullable();
            $table->string('inventory_id')->nullable();
            $table->string('user_product_id')->nullable();
            $table->string('stock_source')->nullable();
            $table->integer('full_available_quantity')->default(0);
            $table->integer('full_not_available_quantity')->default(0);
            $table->integer('full_total_quantity')->default(0);
            $table->text('not_available_detail')->nullable();
            $table->text('raw_stock')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('publication_status')->nullable();
            $table->text('publication_sub_status')->nullable();
            $table->text('publication_tags')->nullable();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->boolean('requires_meli_labeling')->default(false);
            $table->timestamps();
        });

        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('source_mlm')->nullable();
            $table->string('status')->nullable();
            $table->text('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('meli_order_id')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_logistic_type')->nullable();
            $table->string('shipping_mode')->nullable();
            $table->string('shipping_type')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id');
            $table->string('item_id')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->string('title')->nullable();
            $table->string('variation_text')->nullable();
            $table->string('variation_id')->nullable();
            $table->string('remote_line_key')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_full_shipments', function (Blueprint $table): void {
            $table->id();
            $table->string('shipment_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('meli_full_shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_full_shipment_id');
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->integer('quantity_sent')->default(0);
            $table->timestamps();
        });
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $user;
    }

    public function test_can_link_multiple_publications_to_same_product(): void
    {
        $user = $this->admin();
        $product = InventoryProduct::create(['sku' => 'LLANTA-185-65', 'name' => 'Llanta 185/65R15 Pirelli']);

        // 1. Vincular primera publicación (Envío propio)
        $resp1 = $this->actingAs($user)->postJson(route('inventory.products.link_publication', $product), [
            'mlm' => 'MLM111222333',
            'is_full' => false,
            'title' => 'Llanta 185/65R15 Pirelli P400 Envío Gratis',
            'remote_price' => 1450.00,
        ]);
        $resp1->assertOk();

        // 2. Vincular segunda publicación al MISMO producto (Publicación en Bodega FULL)
        $resp2 = $this->actingAs($user)->postJson(route('inventory.products.link_publication', $product), [
            'mlm' => 'MLM444555666',
            'is_full' => true,
            'title' => 'Llanta 185/65R15 Pirelli FULL En Bodega',
            'remote_price' => 1550.00,
        ]);
        $resp2->assertOk();

        // Verificar que el producto tenga ambas publicaciones
        $links = InventoryChannelLink::where('inventory_product_id', $product->id)->get();
        $this->assertCount(2, $links);

        $propio = $links->firstWhere('external_listing_id', 'MLM111222333');
        $this->assertNotNull($propio);
        $this->assertTrue((bool) $propio->stock_sync_enabled);
        $this->assertFalse((bool) ($propio->metadata['is_full'] ?? false));

        $full = $links->firstWhere('external_listing_id', 'MLM444555666');
        $this->assertNotNull($full);
        $this->assertFalse((bool) $full->stock_sync_enabled); // En Full no descuenta stock local
        $this->assertTrue((bool) ($full->metadata['is_full'] ?? false));
    }

    public function test_search_meli_publication_by_url_and_code(): void
    {
        $user = $this->admin();
        
        MeliFullStock::create([
            'mlm' => 'MLM99887766',
            'title' => 'Llanta Goodyear Assurance 185/65R15 FULL',
            'sku' => 'GY-185-65',
            'full_available_quantity' => 24,
            'permalink' => 'https://articulo.mercadolibre.com.mx/MLM-99887766-llanta-goodyear',
        ]);

        // Búsqueda por URL completa
        $resp = $this->actingAs($user)->getJson(route('inventory.channels.meli.search-publication', [
            'query' => 'https://articulo.mercadolibre.com.mx/MLM-99887766-llanta-goodyear-assurance-18565r15-_JM'
        ]));

        $resp->assertOk();
        $data = $resp->json();
        $this->assertTrue($data['success']);
        $this->assertSame('single', $data['type']);
        $this->assertSame('MLM99887766', $data['item']['mlm']);
        $this->assertTrue($data['item']['is_full']);
        $this->assertSame(24, $data['item']['full_stock_available']);
    }

    public function test_can_unlink_publication_from_product(): void
    {
        $user = $this->admin();
        $product = InventoryProduct::create(['sku' => 'MICHELIN-195-65', 'name' => 'Llanta Michelin 195/65R15']);

        $link = InventoryChannelLink::create([
            'channel' => 'mercado_libre',
            'inventory_product_id' => $product->id,
            'external_listing_id' => 'MLM777888999',
            'account_key' => 'default',
            'identity_key' => 'mercado_libre|account:default|listing:MLM777888999',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('inventory_channel_links', ['id' => $link->id]);

        $resp = $this->actingAs($user)->deleteJson(route('inventory.products.unlink_publication', [
            'inventoryProduct' => $product,
            'inventoryChannelLink' => $link,
        ]));

        $resp->assertOk();
        $this->assertDatabaseMissing('inventory_channel_links', ['id' => $link->id]);
    }

    public function test_restock_recommendations_uses_linked_mlms_for_full_stock_and_sales(): void
    {
        $user = $this->admin();
        $product = InventoryProduct::create([
            'sku' => 'LOCAL-SKU-100',
            'name' => 'Llanta Bridgestone Ecopia 185/65R15',
            'product_type' => 'SIMPLE',
            'is_active' => true,
            'weight_kg' => 7.5,
        ]);

        // Simular stock físico en almacén local
        $location = \App\Models\InventoryLocation::create(['code' => 'A-01', 'name' => 'Anaquel 1']);
        \App\Models\InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => 'ADJUSTMENT',
            'quantity' => 30,
            'occurred_at' => now(),
        ]);

        // Vincular publicación de FULL con un MLM específico
        InventoryChannelLink::create([
            'channel' => 'mercado_libre',
            'inventory_product_id' => $product->id,
            'external_listing_id' => 'MLM555000111',
            'account_key' => 'default',
            'identity_key' => 'mercado_libre|account:default|listing:MLM555000111',
            'is_active' => true,
            'metadata' => ['is_full' => true],
        ]);

        // El registro de MeliFullStock tiene el MLM vinculado pero un SKU diferente en MeLi
        MeliFullStock::create([
            'mlm' => 'MLM555000111',
            'sku' => 'MELI-DIFFERENT-SKU-99',
            'title' => 'Bridgestone Ecopia 185/65R15 FULL',
            'full_available_quantity' => 2, // Muy pocas unidades en Full
        ]);

        // Una orden de FULL vendida en los últimos 30 días con ese item_id (MLM)
        $order = \App\Models\MeliOrder::create([
            'meli_order_id' => 'ORD-1234',
            'status' => 'paid',
            'shipping_logistic_type' => 'fulfillment',
            'created_at' => now()->subDays(5),
        ]);

        DB::table('meli_order_items')->insert([
            'meli_order_id' => $order->id,
            'item_id' => 'MLM555000111',
            'sku' => 'MELI-DIFFERENT-SKU-99',
            'quantity' => 8,
            'unit_price' => 1600.00,
            'created_at' => now()->subDays(5),
        ]);

        $service = app(MeliFullShipmentService::class);
        $recs = $service->getRestockRecommendations(10);

        $found = collect($recs)->firstWhere('product_id', $product->id);
        $this->assertNotNull($found, 'El producto debe figurar en las recomendaciones de restock de FULL');
        $this->assertSame(2, $found['full_stock_available'], 'Debe detectar las 2 existencias en FULL gracias al MLM vinculado');
        $this->assertSame(8, $found['sales_full_30d'], 'Debe sumar las 8 ventas en FULL gracias al MLM vinculado');
        $this->assertContains('MLM555000111', $found['linked_mlms']);
    }
}
