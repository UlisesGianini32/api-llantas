<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryMeliCheckFullCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('inventory_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('product_type')->default('simple');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_product_id');
            $table->string('channel', 32);
            $table->string('account_key')->nullable();
            $table->string('external_product_id')->nullable();
            $table->string('external_variant_id')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->text('external_url')->nullable();
            $table->string('remote_status')->nullable();
            $table->decimal('remote_price', 14, 2)->nullable();
            $table->string('remote_currency', 8)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('stock_sync_enabled')->default(true);
            $table->string('identity_key', 255)->unique();
            $table->string('remote_user_product_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_preview_mode_identifies_full_items_without_modifying_db(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
        $account = MeliAccount::query()->create([
            'user_id' => $user->id,
            'meli_user_id' => '123456',
            'nickname' => 'TIENDA_TEST',
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);

        $prod1 = InventoryProduct::query()->create(['sku' => 'SKU-FULL', 'name' => 'Producto Full']);
        $prod2 = InventoryProduct::query()->create(['sku' => 'SKU-NORMAL', 'name' => 'Producto Normal']);

        $linkFull = InventoryChannelLink::query()->create([
            'inventory_product_id' => $prod1->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM111',
            'identity_key' => 'meli:'.$account->id.':MLM111',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);

        $linkNormal = InventoryChannelLink::query()->create([
            'inventory_product_id' => $prod2->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM222',
            'identity_key' => 'meli:'.$account->id.':MLM222',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);

        Http::fake([
            '*items*' => Http::response([
                [
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM111',
                        'title' => 'Producto Full',
                        'status' => 'active',
                        'shipping' => ['logistic_type' => 'fulfillment'],
                    ],
                ],
                [
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM222',
                        'title' => 'Producto Normal',
                        'status' => 'active',
                        'shipping' => ['logistic_type' => 'drop_off'],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('inventory:meli-check-full')
            ->expectsOutputToContain('Vínculos detectados como Full: 1')
            ->expectsOutputToContain('Modo Dry-run (previsualización)')
            ->assertExitCode(0);

        $this->assertTrue($linkFull->fresh()->is_active);
        $this->assertTrue($linkFull->fresh()->stock_sync_enabled);
    }

    public function test_apply_mode_deactivates_full_items(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin2@example.com', 'password' => 'secret']);
        $account = MeliAccount::query()->create([
            'user_id' => $user->id,
            'meli_user_id' => '123456',
            'nickname' => 'TIENDA_TEST',
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);

        $prod1 = InventoryProduct::query()->create(['sku' => 'SKU-FULL', 'name' => 'Producto Full']);
        $prod2 = InventoryProduct::query()->create(['sku' => 'SKU-NORMAL', 'name' => 'Producto Normal']);

        $linkFull = InventoryChannelLink::query()->create([
            'inventory_product_id' => $prod1->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM111',
            'identity_key' => 'meli:'.$account->id.':MLM111',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);

        $linkNormal = InventoryChannelLink::query()->create([
            'inventory_product_id' => $prod2->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM222',
            'identity_key' => 'meli:'.$account->id.':MLM222',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);

        Http::fake([
            '*items*' => Http::response([
                [
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM111',
                        'title' => 'Producto Full',
                        'status' => 'active',
                        'shipping' => ['logistic_type' => 'fulfillment'],
                    ],
                ],
                [
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM222',
                        'title' => 'Producto Normal',
                        'status' => 'active',
                        'shipping' => ['logistic_type' => 'drop_off'],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('inventory:meli-check-full --apply')
            ->expectsOutputToContain('Bajas aplicadas correctamente: 1 vínculo(s) actualizado(s).')
            ->assertExitCode(0);

        $freshFull = $linkFull->fresh();
        $this->assertFalse($freshFull->is_active);
        $this->assertFalse($freshFull->stock_sync_enabled);
        $this->assertTrue($freshFull->metadata['deactivated_due_to_full']);

        $freshNormal = $linkNormal->fresh();
        $this->assertTrue($freshNormal->is_active);
        $this->assertTrue($freshNormal->stock_sync_enabled);
    }

    public function test_apply_sync_only_disables_sync_but_keeps_link_active(): void
    {
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin3@example.com', 'password' => 'secret']);
        $account = MeliAccount::query()->create([
            'user_id' => $user->id,
            'meli_user_id' => '123456',
            'nickname' => 'TIENDA_TEST',
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);

        $prod = InventoryProduct::query()->create(['sku' => 'SKU-FULL', 'name' => 'Producto Full']);

        $linkFull = InventoryChannelLink::query()->create([
            'inventory_product_id' => $prod->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM111',
            'identity_key' => 'meli:'.$account->id.':MLM111',
            'is_active' => true,
            'stock_sync_enabled' => true,
        ]);

        Http::fake([
            '*items*' => Http::response([
                [
                    'code' => 200,
                    'body' => [
                        'id' => 'MLM111',
                        'title' => 'Producto Full',
                        'status' => 'active',
                        'shipping' => ['tags' => ['fulfillment']],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('inventory:meli-check-full --apply --sync-only')
            ->expectsOutputToContain('Bajas aplicadas correctamente: 1 vínculo(s) actualizado(s).')
            ->assertExitCode(0);

        $freshFull = $linkFull->fresh();
        $this->assertTrue($freshFull->is_active);
        $this->assertFalse($freshFull->stock_sync_enabled);
    }
}
