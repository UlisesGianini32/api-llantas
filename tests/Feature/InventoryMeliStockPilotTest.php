<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryChannelStockSync;
use App\Models\InventoryKitComponent;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\User;
use App\Services\InventoryChannelLinkService;
use App\Services\InventoryMeliRemoteStockService;
use App\Services\InventoryMeliStockOwnershipService;
use App\Services\InventoryMeliStockPilotService;
use App\Services\InventoryMeliStockSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InventoryMeliStockPilotTest extends TestCase
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
            $table->string('role')->default('operations');
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
        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('source_mlm')->nullable();
            $table->string('status')->nullable();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->index();
        });
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
    }

    protected function tearDown(): void
    {
        foreach (['inventory_channel_stock_syncs', 'inventory_channel_links', 'inventory_kit_reservations', 'inventory_kit_components', 'inventory_reservations', 'inventory_movements', 'inventory_products', 'inventory_locations', 'llantas', 'meli_accounts', 'meli_publications', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_command_requires_link(): void
    {
        Http::fake();
        $this->assertSame(1, Artisan::call('inventory:meli-stock-pilot'));
        Http::assertNothingSent();
    }

    public function test_preview_performs_get_but_no_put(): void
    {
        $link = $this->readyLink(7, 'MLM-PREVIEW');
        Http::fake(fn (HttpRequest $request) => $request->method() === 'GET'
            ? Http::response(['id' => 'MLM-PREVIEW', 'available_quantity' => 10], 200)
            : Http::response([], 200));

        $result = app(InventoryMeliStockPilotService::class)->preview($link);

        $this->assertSame(10, $result['remote_current_quantity']);
        $this->assertSame(-3, $result['delta']);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'PUT');
    }

    public function test_apply_requires_exact_confirmation_and_writes_only_after_match(): void
    {
        $link = $this->readyLink(7, 'MLM-CONFIRM');
        Http::fake(fn (HttpRequest $request) => match ($request->method()) {
            'GET' => Http::response(['id' => 'MLM-CONFIRM', 'available_quantity' => 10], 200),
            'PUT' => Http::response(['id' => 'MLM-CONFIRM'], 200),
            default => Http::response([], 200),
        });

        $this->assertSame(1, Artisan::call('inventory:meli-stock-pilot', [
            '--link' => $link->id,
            '--apply' => true,
            '--confirm' => 'WRONG',
        ]));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'PUT');

        $this->assertSame(0, Artisan::call('inventory:meli-stock-pilot', [
            '--link' => $link->id,
            '--apply' => true,
            '--confirm' => 'MLM-CONFIRM',
        ]));
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT');
    }

    public function test_wrong_confirmation_performs_no_put(): void
    {
        $link = $this->readyLink(2, 'MLM-WRONG');
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 5], 200)]);

        $result = app(InventoryMeliStockPilotService::class)->preview($link);
        $this->assertNotSame('WRONG', $result['identity']);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'PUT');
    }

    public function test_disabled_link_cannot_apply(): void
    {
        Http::fake();
        $link = $this->link($this->product('DISABLED'), ['external_listing_id' => 'MLM-DISABLED']);
        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockPilotService::SYNC_DISABLED, $result['write_status']);
        Http::assertNothingSent();
    }

    public function test_inactive_link_cannot_apply(): void
    {
        Http::fake();
        $link = $this->readyLink(2, 'MLM-INACTIVE-LINK');
        $link->update(['is_active' => false]);

        $result = app(InventoryMeliStockPilotService::class)->apply($link->fresh());

        $this->assertSame(InventoryMeliStockSyncService::SKIPPED_LINK_INACTIVE, $result['write_status']);
        Http::assertNothingSent();
    }

    public function test_inactive_product_cannot_apply(): void
    {
        Http::fake();
        $product = $this->product('INACTIVE-PRODUCT', ['is_active' => false]);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-INACTIVE-PRODUCT']));

        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockSyncService::SKIPPED_PRODUCT_INACTIVE, $result['write_status']);
        Http::assertNothingSent();
    }

    public function test_missing_account_aborts(): void
    {
        Http::fake();
        $product = $this->product('MISSING-ACCOUNT');
        $link = $this->enabled(app(InventoryChannelLinkService::class)->create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '999999',
            'external_listing_id' => 'MLM-MISSING',
        ]));

        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockSyncService::INVALID_ACCOUNT, $result['write_status']);
        Http::assertNothingSent();
    }

    public function test_simple_remote_stock_is_read_correctly(): void
    {
        $link = $this->readyLink(3, 'MLM-SIMPLE-READ');
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 9], 200)]);

        $result = app(InventoryMeliRemoteStockService::class)->read($link);

        $this->assertSame(InventoryMeliRemoteStockService::OK, $result['status']);
        $this->assertSame(9, $result['quantity']);
    }

    public function test_variation_remote_stock_is_read_correctly(): void
    {
        $link = $this->enabled($this->link($this->product('VAR-READ'), ['external_listing_id' => 'MLM-VAR-READ', 'external_variant_id' => '22']));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['variations' => [['id' => 11, 'available_quantity' => 4], ['id' => 22, 'available_quantity' => 8]]], 200)]);

        $result = app(InventoryMeliRemoteStockService::class)->read($link);

        $this->assertSame(8, $result['quantity']);
    }

    public function test_missing_variation_returns_explicit_status(): void
    {
        $link = $this->enabled($this->link($this->product('VAR-MISSING'), ['external_listing_id' => 'MLM-VAR-MISSING', 'external_variant_id' => '99']));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['variations' => [['id' => 11, 'available_quantity' => 4]]], 200)]);

        $result = app(InventoryMeliRemoteStockService::class)->read($link);

        $this->assertSame(InventoryMeliRemoteStockService::REMOTE_VARIATION_NOT_FOUND, $result['status']);
    }

    public function test_pre_read_404_prevents_put(): void
    {
        $link = $this->readyLink(4, 'MLM-404');
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['message' => 'not found'], 404)]);

        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockPilotService::PREFLIGHT_FAILED, $result['write_status']);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'PUT');
    }

    public function test_pre_read_timeout_prevents_put(): void
    {
        $link = $this->readyLink(4, 'MLM-TIMEOUT');
        Http::fake();
        $api = \Mockery::mock(\App\Services\MercadoLibre\MeliAccountApiClient::class);
        $api->shouldReceive('ensureFreshAccessToken')->once();
        $api->shouldReceive('request')->once()->andThrow(new \App\Services\MercadoLibre\MeliApiRequestException('timeout', 0));
        app()->instance(\App\Services\MercadoLibre\MeliAccountApiClient::class, $api);

        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockPilotService::PREFLIGHT_FAILED, $result['write_status']);
        Http::assertNothingSent();
    }

    public function test_simple_target_comes_from_current_inventory(): void
    {
        $product = $this->product('TARGET-SIMPLE');
        $this->movement($product, 12);
        $this->reserve($product, 5);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-TARGET-SIMPLE']));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 1], 200)]);

        $result = app(InventoryMeliStockPilotService::class)->preview($link);

        $this->assertSame(7, $result['target']);
    }

    public function test_kit_target_comes_from_current_components(): void
    {
        $kit = $this->product('TARGET-KIT', ['product_type' => InventoryProduct::KIT]);
        $component = $this->product('TARGET-COMPONENT');
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $component->id, 'quantity' => 2]);
        $this->movement($component, 10);
        $link = $this->enabled($this->link($kit, ['external_listing_id' => 'MLM-TARGET-KIT']));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 2], 200)]);

        $result = app(InventoryMeliStockPilotService::class)->preview($link);

        $this->assertSame(5, $result['target']);
    }

    public function test_remote_before_is_captured_immediately_before_put(): void
    {
        [$link] = $this->pilotFixture(7, 10, 7);
        app(InventoryMeliStockPilotService::class)->apply($link);
        $audit = InventoryChannelStockSync::query()->latest('id')->first();

        $this->assertSame(10, $audit->previous_known_quantity);
        $this->assertSame('pilot', $audit->triggered_by);
    }

    public function test_successful_put_is_followed_by_get_verification(): void
    {
        [$link] = $this->pilotFixture(7, 10, 7);
        app(InventoryMeliStockPilotService::class)->apply($link);

        $methods = collect(Http::recorded())
            ->map(fn (array $record): string => $record[0]->method())
            ->all();

        $this->assertSame(['GET', 'PUT', 'GET'], $methods);
    }

    public function test_matching_after_quantity_is_verified(): void
    {
        [$link] = $this->pilotFixture(7, 10, 7);
        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockPilotService::VERIFIED, $result['verification_status']);
        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['verification_status' => 'VERIFIED', 'verified_quantity' => 7]);
        $audit = InventoryChannelStockSync::query()->latest('id')->first();
        $this->assertNotNull($audit->verified_at);
        $serialized = json_encode([$audit->metadata, $audit->error_message, $audit->error_code]);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('refresh_token', $serialized);
        $this->assertStringNotContainsString('Authorization', $serialized);
        $this->assertStringNotContainsString('Bearer', $serialized);
    }

    public function test_different_after_quantity_is_mismatch(): void
    {
        [$link] = $this->pilotFixture(7, 10, 6);
        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockPilotService::MISMATCH, $result['verification_status']);
        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['verification_status' => 'MISMATCH', 'verified_quantity' => 6]);
    }

    public function test_verification_get_failure_is_unverified(): void
    {
        $link = $this->readyLink(7, 'MLM-UNVERIFIED');
        $phase = 0;
        Http::fake(function (HttpRequest $request) use (&$phase) {
            if ($request->method() === 'PUT') {
                return Http::response([], 200);
            }
            $phase++;

            return $phase === 1 ? Http::response(['available_quantity' => 10], 200) : Http::response([], 422);
        });

        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryMeliStockPilotService::UNVERIFIED, $result['verification_status']);
    }

    public function test_put_failure_remains_failed(): void
    {
        $link = $this->readyLink(7, 'MLM-PUT-FAIL');
        Http::fake(function (HttpRequest $request) {
            return $request->method() === 'PUT'
                ? Http::response(['message' => 'rejected'], 422)
                : Http::response(['available_quantity' => 10], 200);
        });

        $result = app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(InventoryChannelStockSync::FAILED, $result['write_status']);
        $this->assertNull($result['verification_status']);
    }

    public function test_pilot_does_not_create_movements(): void
    {
        $product = $this->product('NO-MOVEMENTS');
        $this->movement($product, 5);
        $before = InventoryMovement::count();
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-NO-MOVEMENTS']));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 5], 200)]);

        app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame($before, InventoryMovement::count());
    }

    public function test_pilot_does_not_create_reservations(): void
    {
        $product = $this->product('NO-RESERVATIONS');
        $this->movement($product, 5);
        $before = InventoryReservation::count();
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-NO-RESERVATIONS']));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 5], 200)]);

        app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame($before, InventoryReservation::count());
    }

    public function test_pilot_does_not_modify_legacy_publication(): void
    {
        $product = $this->product('NO-LEGACY-WRITE');
        $publication = MeliPublication::create(['mlm' => 'MLM-NO-LEGACY-WRITE', 'sku' => $product->sku, 'raw' => ['available_quantity' => 12]]);
        $link = $this->enabled($this->link($product, ['external_listing_id' => $publication->mlm]));
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 5], 200)]);

        app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(['available_quantity' => 12], $publication->fresh()->raw);
    }

    public function test_lock_prevents_concurrent_pilot(): void
    {
        $link = $this->readyLink(7, 'MLM-LOCK-PILOT');
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 10], 200)]);
        $lock = Cache::lock('inventory-meli-stock-sync:link:'.$link->id, 600);
        $this->assertTrue($lock->get());
        try {
            $result = app(InventoryMeliStockPilotService::class)->apply($link);
            $this->assertSame(InventoryMeliStockPilotService::LOCKED, $result['write_status']);
            Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'PUT');
        } finally {
            $lock->release();
        }
    }

    public function test_remote_drift_is_visible(): void
    {
        $link = $this->readyLink(7, 'MLM-DRIFT');
        InventoryChannelStockSync::create([
            'inventory_channel_link_id' => $link->id,
            'inventory_product_id' => $link->inventory_product_id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => $link->account_key,
            'external_listing_id' => $link->external_listing_id,
            'target_quantity' => 7,
            'previous_known_quantity' => 7,
            'status' => InventoryChannelStockSync::SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['available_quantity' => 12], 200)]);

        $result = app(InventoryMeliStockPilotService::class)->preview($link);

        $this->assertTrue($result['remote_drift']);
    }

    public function test_previous_known_quantity_is_pre_put_remote_quantity(): void
    {
        [$link] = $this->pilotFixture(4, 13, 4);
        app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(13, InventoryChannelStockSync::query()->latest('id')->value('previous_known_quantity'));
    }

    public function test_verified_quantity_is_post_put_quantity(): void
    {
        [$link] = $this->pilotFixture(4, 13, 4);
        app(InventoryMeliStockPilotService::class)->apply($link);

        $this->assertSame(4, InventoryChannelStockSync::query()->latest('id')->value('verified_quantity'));
    }

    public function test_operations_cannot_mutate_stock_ownership(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $link = $this->link($this->product('OPS-PILOT'));

        $this->actingAs($operator)
            ->patch(route('inventory.channels.stock-sync.toggle', $link), ['enabled' => true])
            ->assertForbidden();
    }

    public function test_pilot_cannot_process_multiple_links(): void
    {
        Http::fake();
        $first = $this->readyLink(1, 'MLM-FIRST');
        $second = $this->readyLink(1, 'MLM-SECOND');

        $this->assertSame(1, Artisan::call('inventory:meli-stock-pilot', ['--link' => $first->id.','.$second->id]));
        Http::assertNothingSent();
    }

    public function test_pilot_has_no_scheduler_registration(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('inventory:meli-stock-pilot', $console);
    }

    public function test_disabled_ownership_leaves_legacy_writer_allowed(): void
    {
        $account = $this->account();
        $link = $this->linkForAccount($this->product('OWNER-DISABLED'), $account, ['external_listing_id' => 'MLM-OWNER']);
        $this->assertFalse(app(InventoryMeliStockOwnershipService::class)->shouldSkipLegacyListing($account->id, 'MLM-OWNER'));
        $this->assertSame(InventoryMeliStockOwnershipService::LEGACY_ALLOWED, app(InventoryMeliStockOwnershipService::class)->inspect($link)['status']);
    }

    public function test_enabled_ownership_makes_legacy_writer_skip_identity(): void
    {
        $account = $this->account();
        $link = $this->enabled($this->linkForAccount($this->product('OWNER-ENABLED'), $account, ['external_listing_id' => 'MLM-OWNER-ENABLED']));
        $this->assertTrue(app(InventoryMeliStockOwnershipService::class)->shouldSkipLegacyListing($account->id, 'MLM-OWNER-ENABLED'));
        $this->assertSame(InventoryMeliStockOwnershipService::PROTECTED, app(InventoryMeliStockOwnershipService::class)->inspect($link)['status']);
    }

    public function test_unrelated_listing_remains_legacy_writable(): void
    {
        $account = $this->account();
        $link = $this->enabled($this->linkForAccount($this->product('OWNER-A'), $account, ['external_listing_id' => 'MLM-OWNER-A']));
        $this->assertFalse(app(InventoryMeliStockOwnershipService::class)->shouldSkipLegacyListing($account->id, 'MLM-OWNER-B'));
        $this->assertNotNull($link);
    }

    public function test_variation_with_legacy_source_is_blocked_conservatively(): void
    {
        DB::table('llantas')->insert(['sku' => 'LEGACY-VAR']);
        $account = $this->account();
        $link = $this->enabled($this->linkForAccount($this->product('LEGACY-VAR'), $account, ['external_listing_id' => 'MLM-LEGACY-VAR', 'external_variant_id' => '9']));

        $inspection = app(InventoryMeliStockOwnershipService::class)->inspect($link);

        $this->assertSame(InventoryMeliStockOwnershipService::BLOCKED_LEGACY_VARIATION_OWNERSHIP, $inspection['status']);
        $this->assertSame(['llantas'], $inspection['legacy_sources']);
    }

    private function pilotFixture(int $target, int $before, int $after): array
    {
        $link = $this->readyLink($target, 'MLM-PILOT');
        $getCount = 0;
        Http::fake(function (HttpRequest $request) use (&$getCount, $before, $after) {
            if ($request->method() === 'PUT') {
                return Http::response(['id' => 'MLM-PILOT'], 200);
            }
            $getCount++;

            return Http::response(['available_quantity' => $getCount === 1 ? $before : $after], 200);
        });

        return [$link];
    }

    private function product(string $sku, array $attributes = []): InventoryProduct
    {
        return InventoryProduct::create(array_merge(['sku' => $sku, 'name' => $sku], $attributes));
    }

    private function account(): MeliAccount
    {
        $user = User::query()->first() ?? User::factory()->create(['role' => User::ROLE_ADMIN]);

        return MeliAccount::create(['user_id' => $user->id, 'meli_user_id' => 'MELI-'.$user->id, 'nickname' => 'Cuenta', 'access_token' => 'token', 'expires_at' => now()->addHour()]);
    }

    private function link(InventoryProduct $product, array $attributes = []): InventoryChannelLink
    {
        return $this->linkForAccount($product, $this->account(), $attributes);
    }

    private function linkForAccount(InventoryProduct $product, MeliAccount $account, array $attributes = []): InventoryChannelLink
    {
        return app(InventoryChannelLinkService::class)->create(array_merge([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM-'.$product->id,
        ], $attributes));
    }

    private function enabled(InventoryChannelLink $link): InventoryChannelLink
    {
        $link->update(['stock_sync_enabled' => true]);

        return $link->fresh();
    }

    private function readyLink(int $quantity, string $listing): InventoryChannelLink
    {
        $product = $this->product($listing);
        $this->movement($product, $quantity);

        return $this->enabled($this->link($product, ['external_listing_id' => $listing]));
    }

    private function location(): InventoryLocation
    {
        return InventoryLocation::firstOrCreate(['code' => 'MAIN'], ['name' => 'Principal', 'is_active' => true]);
    }

    private function movement(InventoryProduct $product, int $quantity): void
    {
        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location()->id,
            'type' => InventoryMovement::INITIAL,
            'quantity' => $quantity,
            'occurred_at' => now(),
        ]);
    }

    private function reserve(InventoryProduct $product, int $quantity): void
    {
        InventoryReservation::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $this->location()->id,
            'quantity' => $quantity,
            'status' => InventoryReservation::ACTIVE,
        ]);
    }
}
