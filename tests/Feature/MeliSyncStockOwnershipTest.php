<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\User;
use App\Services\MeliSyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliSyncStockOwnershipTest extends TestCase
{
    private User $user;

    private MeliAccount $account;

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
            $table->string('meli_id')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
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
            $table->string('sku');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id');
            $table->string('channel');
            $table->string('account_key')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->string('identity_key');
            $table->boolean('is_active')->default(true);
            $table->boolean('stock_sync_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('status')->nullable();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        $this->user = User::query()->create([
            'name' => 'Legacy writer',
            'email' => 'legacy@example.test',
            'password' => 'password',
            'meli_id' => 'MELI-LEGACY',
            'access_token' => 'token',
        ]);
        $this->account = MeliAccount::query()->create([
            'user_id' => $this->user->id,
            'meli_user_id' => 'MELI-LEGACY',
            'nickname' => 'Legacy',
            'access_token' => 'token',
            'expires_at' => now()->addHour(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['meli_publications', 'inventory_channel_links', 'inventory_products', 'meli_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_disabled_inventory_ownership_keeps_legacy_stock_price_and_status(): void
    {
        $body = $this->runLegacyWriter('MLM-DISABLED', false);

        $this->assertSame(3, $body['available_quantity']);
        $this->assertSame(22.5, $body['price']);
        $this->assertSame('active', $body['status']);
    }

    public function test_enabled_inventory_ownership_omits_only_legacy_stock(): void
    {
        $body = $this->runLegacyWriter('MLM-OWNED', true);

        $this->assertArrayNotHasKey('available_quantity', $body);
        $this->assertSame(22.5, $body['price']);
        $this->assertSame('active', $body['status']);
    }

    public function test_unrelated_publication_keeps_legacy_stock_price_and_status(): void
    {
        $this->link('MLM-OWNED', true);
        $this->publication('MLM-OTHER');
        $history = [];
        $this->invokeWriter([
            ['sku' => 'SKU-OTHER', 'mlm' => 'MLM-OTHER', 'stock' => 4, 'price' => 19.75, 'status' => 'active'],
        ], $history);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(4, $body['available_quantity']);
        $this->assertSame(19.75, $body['price']);
        $this->assertSame('active', $body['status']);
    }

    private function runLegacyWriter(string $mlm, bool $stockOwned): array
    {
        $this->link($mlm, $stockOwned);
        $this->publication($mlm);
        $history = [];
        $this->invokeWriter([
            ['sku' => 'SKU-LEGACY', 'mlm' => $mlm, 'stock' => 3, 'price' => 22.5, 'status' => 'active'],
        ], $history);

        return json_decode((string) $history[0]['request']->getBody(), true);
    }

    private function invokeWriter(array $rows, array &$history): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['id' => $rows[0]['mlm'], 'price' => $rows[0]['price'], 'status' => $rows[0]['status']]))]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'http_errors' => false]);
        $service = app(MeliSyncService::class);
        $property = new \ReflectionProperty(MeliSyncService::class, 'client');
        $property->setAccessible(true);
        $property->setValue($service, $client);
        $method = new \ReflectionMethod(MeliSyncService::class, 'applyItemUpdatesConcurrent');
        $method->setAccessible(true);
        $method->invoke($service, $rows);
    }

    private function link(string $mlm, bool $stockOwned): InventoryChannelLink
    {
        $product = DB::table('inventory_products')->insertGetId([
            'sku' => 'SKU-'.str_replace('-', '', $mlm),
            'name' => $mlm,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryChannelLink::query()->create([
            'inventory_product_id' => $product,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $this->account->id,
            'external_listing_id' => $mlm,
            'identity_key' => 'mercado_libre|account:'.$this->account->id.'|listing:'.$mlm,
            'is_active' => true,
            'stock_sync_enabled' => $stockOwned,
        ]);
    }

    private function publication(string $mlm): void
    {
        MeliPublication::query()->create([
            'user_id' => $this->user->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'SKU-'.str_replace('-', '', $mlm),
            'mlm' => $mlm,
            'status' => 'paused',
            'raw' => ['available_quantity' => 99, 'price' => 1],
        ]);
    }
}
