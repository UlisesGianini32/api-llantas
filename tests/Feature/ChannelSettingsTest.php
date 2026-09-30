<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Shopify\InventoryShopifyClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChannelSettingsTest extends TestCase
{
    private User $admin;

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
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->string('meli_id')->nullable();
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

        $this->admin = User::forceCreate([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_profile_and_channels_include_channel_status_props(): void
    {
        Config::set('services.shopify.store_domain', 'test-store.myshopify.com');
        Config::set('services.shopify.client_id', 'shpat_1234567890abcdef');
        Config::set('services.shopify.client_secret', 'secret123');

        Config::set('services.amazon.seller_id', 'SELLER123');
        Config::set('services.amazon.marketplace_id', 'A1AM78C64UM0Y8');
        Config::set('services.amazon.lwa_client_id', 'amzn1.application-oa2-client.123');
        Config::set('services.amazon.lwa_client_secret', 'lwa_secret');
        Config::set('services.amazon.lwa_refresh_token', 'Atzr|test_token');

        $response = $this->actingAs($this->admin)->get('/settings/profile');
        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Profile')
            ->has('channels.shopify', fn (Assert $sh) => $sh
                ->where('store_domain', 'test-store.myshopify.com')
                ->where('is_configured', true)
                ->where('has_client_id', true)
                ->where('has_client_secret', true)
                ->etc()
            )
            ->has('channels.amazon', fn (Assert $az) => $az
                ->where('seller_id', 'SELLER123')
                ->where('marketplace_id', 'A1AM78C64UM0Y8')
                ->where('is_configured', true)
                ->where('has_client_id', true)
                ->where('has_client_secret', true)
                ->where('has_refresh_token', true)
                ->etc()
            )
        );

        $channelsResponse = $this->actingAs($this->admin)->get('/settings/channels');
        $channelsResponse->assertOk();
        $channelsResponse->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Channels')
            ->has('channels.shopify')
            ->has('channels.amazon')
        );
    }

    public function test_channel_test_shopify_returns_success_with_locations(): void
    {
        Config::set('services.shopify.store_domain', 'test-store.myshopify.com');
        Config::set('services.shopify.client_id', 'shpat_test');
        Config::set('services.shopify.client_secret', 'secret');

        $mockClient = $this->mock(InventoryShopifyClient::class);
        $mockClient->shouldReceive('getLocations')
            ->once()
            ->andReturn([
                'locations' => [
                    ['id' => '1001', 'name' => 'Almacén Principal', 'active' => true],
                    ['id' => '1002', 'name' => 'Tienda Física', 'active' => true],
                ],
            ]);
        $mockClient->shouldReceive('getStoreDomain')
            ->andReturn('test-store.myshopify.com');

        $response = $this->actingAs($this->admin)->postJson('/settings/channels/shopify/test');

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'store' => 'test-store.myshopify.com',
        ]);
        $this->assertCount(2, $response->json('locations'));
    }

    public function test_channel_test_shopify_returns_error_on_failure(): void
    {
        Config::set('services.shopify.store_domain', 'test-store.myshopify.com');

        $mockClient = $this->mock(InventoryShopifyClient::class);
        $mockClient->shouldReceive('getLocations')
            ->once()
            ->andThrow(new \RuntimeException('401 Unauthorized'));

        $response = $this->actingAs($this->admin)->postJson('/settings/channels/shopify/test');

        $response->assertStatus(422);
        $response->assertJson([
            'ok' => false,
        ]);
        $this->assertStringContainsString('401 Unauthorized', $response->json('message'));
    }

    public function test_channel_test_amazon_returns_success_with_lwa_token(): void
    {
        Config::set('services.amazon.lwa_client_id', 'amzn1.application-oa2-client.123');
        Config::set('services.amazon.lwa_client_secret', 'lwa_secret');
        Config::set('services.amazon.lwa_refresh_token', 'Atzr|refresh');
        Config::set('services.amazon.seller_id', 'SELLER_MX');
        Config::set('services.amazon.marketplace_id', 'A1AM78C64UM0Y8');

        Http::fake([
            'https://api.amazon.com/auth/o2/token' => Http::response([
                'access_token' => 'Atza|mocked_lwa_access_token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/settings/channels/amazon/test');

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'seller_id' => 'SELLER_MX',
            'marketplace_id' => 'A1AM78C64UM0Y8',
        ]);
    }

    public function test_channel_test_amazon_returns_error_when_unconfigured_or_invalid(): void
    {
        Config::set('services.amazon.lwa_client_id', '');
        Config::set('services.amazon.lwa_refresh_token', '');

        $response = $this->actingAs($this->admin)->postJson('/settings/channels/amazon/test');

        $response->assertStatus(422);
        $response->assertJson([
            'ok' => false,
        ]);
        $this->assertStringContainsString('Faltan credenciales', $response->json('message'));
    }

    public function test_save_channel_credentials_for_shopify(): void
    {
        $response = $this->actingAs($this->admin)->post('/settings/channels/save', [
            'channel' => 'shopify',
            'store_domain' => 'https://mrpoolhmo.myshopify.com/',
            'client_id' => 'shpat_new_access_token',
            'client_secret' => 'new_secret',
            'api_version' => '2025-01',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_save_channel_credentials_for_amazon(): void
    {
        $response = $this->actingAs($this->admin)->post('/settings/channels/save', [
            'channel' => 'amazon',
            'seller_id' => 'A29BVEND',
            'marketplace_id' => 'A1AM78C64UM0Y8',
            'lwa_client_id' => 'amzn1.client.test',
            'lwa_client_secret' => 'secret_test',
            'lwa_refresh_token' => 'Atzr|test_refresh',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }
}
