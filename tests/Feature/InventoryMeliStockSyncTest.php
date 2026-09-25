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
use App\Services\InventoryMeliStockSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryMeliStockSyncTest extends TestCase
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
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
    }

    protected function tearDown(): void
    {
        foreach (['inventory_channel_stock_syncs', 'inventory_channel_links', 'inventory_kit_reservations', 'inventory_kit_components', 'inventory_reservations', 'inventory_movements'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('inventory_products');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('meli_publications');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_new_links_are_opted_out_and_disabled_links_are_not_ready(): void
    {
        $link = $this->link($this->product('STOCK-1'));
        $this->assertFalse((bool) $link->stock_sync_enabled);
        $preview = app(InventoryMeliStockSyncService::class)->preview()['rows'][0];
        $this->assertSame(InventoryMeliStockSyncService::SKIPPED_SYNC_DISABLED, $preview['status']);
    }

    public function test_simple_stock_uses_global_available_and_zero_is_sent(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response(['id' => 'MLM-1'], 200)]);
        $product = $this->product('SIMPLE-STOCK');
        $this->movement($product, 10);
        $this->reserve($product, 3);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-1']));
        $service = app(InventoryMeliStockSyncService::class);
        $this->assertSame(7, $service->preview()['rows'][0]['target']);
        $result = $service->syncLink($link);
        $this->assertSame(InventoryChannelStockSync::SUCCESS, $result['status']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->data()['available_quantity'] === 7);
        $this->movement($product, -7, InventoryMovement::ADJUSTMENT_OUT);
        $result = $service->syncLink($link->fresh());
        $this->assertSame(InventoryChannelStockSync::SUCCESS, $result['status']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->data()['available_quantity'] === 0);
    }

    public function test_inactive_product_is_skipped_without_sending_zero(): void
    {
        Http::fake();
        $product = $this->product('INACTIVE', ['is_active' => false]);
        $link = $this->enabled($this->link($product));
        $row = app(InventoryMeliStockSyncService::class)->preview()['rows'][0];
        $this->assertSame(InventoryMeliStockSyncService::SKIPPED_PRODUCT_INACTIVE, $row['status']);
        app(InventoryMeliStockSyncService::class)->syncLink($link);
        Http::assertNothingSent();
    }

    public function test_variation_updates_only_the_selected_variation(): void
    {
        Http::fake([
            'https://api.mercadolibre.com/items/MLM-VAR' => Http::sequence()->push(['variations' => [['id' => 111], ['id' => 222]]], 200)->push(['id' => 'MLM-VAR'], 200),
        ]);
        $product = $this->product('VAR-STOCK');
        $this->movement($product, 8);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-VAR', 'external_variant_id' => '111']));
        $result = app(InventoryMeliStockSyncService::class)->syncLink($link);
        $this->assertSame(InventoryChannelStockSync::SUCCESS, $result['status']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->data()['variations'][0]['available_quantity'] === 8 && ! isset($request->data()['variations'][1]['available_quantity']));
    }

    public function test_kit_uses_kit_availability_without_creating_movements(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $kit = $this->product('KIT', ['product_type' => InventoryProduct::KIT]);
        $a = $this->product('KIT-A');
        $b = $this->product('KIT-B');
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $a->id, 'quantity' => 2]);
        InventoryKitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $b->id, 'quantity' => 1]);
        $this->movement($a, 10);
        $this->movement($b, 4);
        $link = $this->enabled($this->link($kit, ['external_listing_id' => 'MLM-KIT']));
        $before = InventoryMovement::count();
        $this->assertSame(4, app(InventoryMeliStockSyncService::class)->preview()['rows'][0]['target']);
        app(InventoryMeliStockSyncService::class)->syncLink($link);
        $this->assertSame($before, InventoryMovement::count());
    }

    public function test_preview_is_read_only_and_apply_records_success_or_failure_audit(): void
    {
        $failMutatingRequest = false;
        Http::fake(function (HttpRequest $request) use (&$failMutatingRequest) {
            return $failMutatingRequest && $request->method() === 'PUT'
                ? Http::response(['message' => 'bad'], 422)
                : Http::response(['id' => 'MLM-A'], 200);
        });
        $link = $this->enabled($this->link($this->product('AUDIT'), ['external_listing_id' => 'MLM-A']));
        $sync = app(InventoryMeliStockSyncService::class);
        $sync->preview();
        $this->assertDatabaseCount('inventory_channel_stock_syncs', 0);
        $sync->syncLink($link);
        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['status' => InventoryChannelStockSync::SUCCESS, 'target_quantity' => 0]);
        $failMutatingRequest = true;
        $sync->syncLink($link->fresh());
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.mercadolibre.com/items/MLM-A'
            && $request->data()['available_quantity'] === 0);
        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['status' => InventoryChannelStockSync::FAILED, 'http_status' => 422]);
    }

    public function test_operations_can_preview_but_cannot_enable_or_apply(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERATIONS]);
        $link = $this->link($this->product('OPS-STOCK'));
        $this->actingAs($user)->get(route('inventory.channels.mercado-libre.stock'))->assertInertia(fn (Assert $page) => $page->component('Inventory/Channels/MercadoLibreStock'));
        $this->actingAs($user)->patch(route('inventory.channels.stock-sync.toggle', $link), ['enabled' => true])->assertForbidden();
        $this->actingAs($user)->post(route('inventory.channels.mercado-libre.stock.sync'), ['link_id' => $link->id])->assertForbidden();
    }

    public function test_admin_can_enable_link_without_enabling_it_during_import_or_creation(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $link = $this->link($this->product('ADMIN-STOCK'));
        $this->actingAs($admin)->patch(route('inventory.channels.stock-sync.toggle', $link), ['enabled' => true])->assertRedirect();
        $this->assertTrue((bool) $link->fresh()->stock_sync_enabled);
    }

    public function test_inactive_link_is_skipped_without_mutating_http(): void
    {
        Http::fake();
        $link = $this->enabled($this->link($this->product('INACTIVE-LINK')));
        $link->update(['is_active' => false]);

        $result = app(InventoryMeliStockSyncService::class)->syncLink($link->fresh());

        $this->assertSame(InventoryMeliStockSyncService::SKIPPED_LINK_INACTIVE, $result['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('inventory_channel_stock_syncs', 0);
    }

    public function test_missing_account_is_skipped_without_success_audit_or_http(): void
    {
        Http::fake();
        $product = $this->product('MISSING-ACCOUNT');
        $link = app(InventoryChannelLinkService::class)->create([
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => '999999',
            'external_listing_id' => 'MLM-MISSING',
        ]);
        $link->update(['stock_sync_enabled' => true]);

        $result = app(InventoryMeliStockSyncService::class)->syncLink($link->fresh());

        $this->assertSame(InventoryMeliStockSyncService::INVALID_ACCOUNT, $result['status']);
        $this->assertDatabaseCount('inventory_channel_stock_syncs', 0);
        Http::assertNothingSent();
    }

    public function test_two_variations_of_one_listing_receive_their_own_targets(): void
    {
        $requests = [];
        Http::fake(function (HttpRequest $request) use (&$requests) {
            $requests[] = $request;

            return $request->method() === 'GET'
                ? Http::response(['variations' => [['id' => 111], ['id' => 222]]], 200)
                : Http::response(['id' => 'MLM-TWO-VAR'], 200);
        });
        $account = $this->account();
        $first = $this->product('VAR-A');
        $second = $this->product('VAR-B');
        $this->movement($first, 8);
        $this->movement($second, 3);
        $linkA = $this->enabled($this->linkForAccount($first, $account, ['external_listing_id' => 'MLM-TWO-VAR', 'external_variant_id' => '111']));
        $linkB = $this->enabled($this->linkForAccount($second, $account, ['external_listing_id' => 'MLM-TWO-VAR', 'external_variant_id' => '222']));

        app(InventoryMeliStockSyncService::class)->syncLink($linkA);
        app(InventoryMeliStockSyncService::class)->syncLink($linkB);

        $puts = collect($requests)->filter(fn (HttpRequest $request): bool => $request->method() === 'PUT')->values();
        $this->assertCount(2, $puts);
        $this->assertSame(8, $puts[0]->data()['variations'][0]['available_quantity']);
        $this->assertArrayNotHasKey('available_quantity', $puts[0]->data()['variations'][1]);
        $this->assertSame(3, $puts[1]->data()['variations'][1]['available_quantity']);
        $this->assertArrayNotHasKey('available_quantity', $puts[1]->data()['variations'][0]);
    }

    public function test_zero_available_is_sent_as_literal_zero(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $product = $this->product('ZERO-STOCK');
        $this->movement($product, 5);
        $this->reserve($product, 5);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-ZERO']));

        app(InventoryMeliStockSyncService::class)->syncLink($link);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT'
            && array_key_exists('available_quantity', $request->data())
            && $request->data()['available_quantity'] === 0);
    }

    public function test_apply_skips_disabled_link_without_mutating_http(): void
    {
        Http::fake();
        $link = $this->link($this->product('APPLY-DISABLED'));

        $result = app(InventoryMeliStockSyncService::class)->apply(['link' => $link->id]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame([], $result['results']);
        Http::assertNothingSent();
    }

    public function test_success_and_failure_audits_do_not_store_tokens(): void
    {
        $fail = false;
        Http::fake(function (HttpRequest $request) use (&$fail) {
            return $fail ? Http::response(['error' => 'Authorization: Bearer secret'], 500) : Http::response([], 200);
        });
        $link = $this->enabled($this->link($this->product('AUDIT-TOKENS'), ['external_listing_id' => 'MLM-TOKENS']));
        $service = app(InventoryMeliStockSyncService::class);
        $service->syncLink($link);
        $fail = true;
        $service->syncLink($link->fresh());

        $this->assertDatabaseHas('inventory_channel_stock_syncs', [
            'status' => InventoryChannelStockSync::FAILED,
            'http_status' => 500,
        ]);

        foreach (InventoryChannelStockSync::query()->get() as $audit) {
            $serialized = json_encode([$audit->metadata, $audit->error_message, $audit->error_code]);
            $this->assertStringNotContainsString('access_token', $serialized);
            $this->assertStringNotContainsString('refresh_token', $serialized);
            $this->assertStringNotContainsString('Authorization', $serialized);
            $this->assertStringNotContainsString('Bearer', $serialized);
        }
    }

    public function test_batch_continues_after_one_remote_failure(): void
    {
        Http::fake(function (HttpRequest $request) {
            if ($request->method() !== 'PUT') {
                return Http::response([], 200);
            }

            return str_ends_with($request->url(), 'MLM-BATCH-A')
                ? Http::response(['message' => 'bad'], 422)
                : Http::response([], 200);
        });
        $account = $this->account();
        $a = $this->enabled($this->linkForAccount($this->product('BATCH-A'), $account, ['external_listing_id' => 'MLM-BATCH-A']));
        $b = $this->enabled($this->linkForAccount($this->product('BATCH-B'), $account, ['external_listing_id' => 'MLM-BATCH-B']));

        $result = app(InventoryMeliStockSyncService::class)->apply(['links' => [$a->id, $b->id]]);

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['inventory_channel_link_id' => $a->id, 'status' => InventoryChannelStockSync::FAILED, 'http_status' => 422]);
        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['inventory_channel_link_id' => $b->id, 'status' => InventoryChannelStockSync::SUCCESS]);
    }

    public function test_command_is_dry_run_by_default_and_apply_writes_only_enabled_link(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $enabled = $this->enabled($this->link($this->product('COMMAND-ENABLED'), ['external_listing_id' => 'MLM-CMD-A']));
        $disabled = $this->link($this->product('COMMAND-DISABLED'), ['external_listing_id' => 'MLM-CMD-B']);

        Artisan::call('inventory:meli-stock-sync');
        $this->assertSame(0, InventoryChannelStockSync::count());
        Http::assertNothingSent();
        $this->assertStringContainsString('Dry-run', Artisan::output());
        Artisan::call('inventory:meli-stock-sync', ['--apply' => true]);

        $this->assertDatabaseHas('inventory_channel_stock_syncs', ['inventory_channel_link_id' => $enabled->id, 'status' => InventoryChannelStockSync::SUCCESS]);
        $this->assertDatabaseMissing('inventory_channel_stock_syncs', ['inventory_channel_link_id' => $disabled->id]);
    }

    public function test_command_filters_link_account_and_sku(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $firstAccount = $this->account();
        $secondAccount = $this->account();
        $first = $this->enabled($this->linkForAccount($this->product('FILTER-A'), $firstAccount, ['external_listing_id' => 'MLM-FILTER-A']));
        $this->enabled($this->linkForAccount($this->product('FILTER-B'), $secondAccount, ['external_listing_id' => 'MLM-FILTER-B']));

        Artisan::call('inventory:meli-stock-sync', ['--apply' => true, '--link' => $first->id]);
        $this->assertDatabaseCount('inventory_channel_stock_syncs', 1);
        Artisan::call('inventory:meli-stock-sync', ['--apply' => true, '--account' => (string) $secondAccount->id]);
        $this->assertDatabaseCount('inventory_channel_stock_syncs', 2);
        Artisan::call('inventory:meli-stock-sync', ['--apply' => true, '--sku' => 'FILTER-A']);
        $this->assertDatabaseCount('inventory_channel_stock_syncs', 3);
    }

    public function test_job_recalculates_stock_and_respects_late_disable(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $product = $this->product('JOB-STOCK');
        $this->movement($product, 2);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-JOB']));
        $job = new \App\Jobs\SyncInventoryMeliStockLinkJob($link->id);
        $this->movement($product, 3);
        $job->handle(app(InventoryMeliStockSyncService::class));
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PUT' && $request->data()['available_quantity'] === 5);
        $this->assertSame('meli', $job->queue);
        $link->update(['stock_sync_enabled' => false]);
        $job->handle(app(InventoryMeliStockSyncService::class));
        Http::assertSentCount(1);
    }

    public function test_sync_does_not_create_movements_reservations_or_modify_legacy_publication(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $product = $this->product('NO-SIDE-EFFECTS');
        $this->movement($product, 4);
        $publication = MeliPublication::create(['mlm' => 'MLM-SIDE', 'sku' => $product->sku, 'status' => 'active', 'raw' => ['item' => ['available_quantity' => 12]]]);
        $link = $this->enabled($this->link($product, ['external_listing_id' => 'MLM-SIDE']));
        $movements = InventoryMovement::count();
        $reservations = InventoryReservation::count();
        app(InventoryMeliStockSyncService::class)->syncLink($link);

        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame($reservations, InventoryReservation::count());
        $this->assertSame($publication->fresh()->raw, ['item' => ['available_quantity' => 12]]);
    }

    public function test_empty_kit_targets_zero_and_primary_location_does_not_limit_global_stock(): void
    {
        Http::fake(['https://api.mercadolibre.com/items/*' => Http::response([], 200)]);
        $emptyKit = $this->product('EMPTY-KIT', ['product_type' => InventoryProduct::KIT]);
        $emptyLink = $this->enabled($this->link($emptyKit, ['external_listing_id' => 'MLM-EMPTY-KIT']));
        $global = $this->product('GLOBAL-STOCK');
        $firstLocation = $this->location('PRIMARY');
        $secondLocation = $this->location('SECONDARY');
        $global->update(['primary_location_id' => $firstLocation->id]);
        $this->movementAt($global, $firstLocation, 2);
        $this->movementAt($global, $secondLocation, 3);
        $globalLink = $this->enabled($this->link($global, ['external_listing_id' => 'MLM-GLOBAL']));

        $service = app(InventoryMeliStockSyncService::class);
        $this->assertSame(0, collect($service->preview(['link' => $emptyLink->id])['rows'])->first()['target']);
        $this->assertSame(5, collect($service->preview(['link' => $globalLink->id])['rows'])->first()['target']);
    }

    public function test_existing_lock_prevents_second_sync_for_same_link(): void
    {
        Http::fake();
        $link = $this->enabled($this->link($this->product('LOCKED'), ['external_listing_id' => 'MLM-LOCKED']));
        $lock = Cache::lock('inventory-meli-stock-sync:link:'.$link->id, 600);
        $this->assertTrue($lock->get());
        try {
            $result = app(InventoryMeliStockSyncService::class)->syncLink($link);
            $this->assertSame(InventoryMeliStockSyncService::LOCKED, $result['status']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    private function product(string $sku, array $attributes = []): InventoryProduct
    {
        return InventoryProduct::create(array_merge(['sku' => $sku, 'name' => $sku], $attributes));
    }

    private function link(InventoryProduct $product, array $attributes = []): InventoryChannelLink
    {
        $account = $this->account();

        return app(InventoryChannelLinkService::class)->create(array_merge(['inventory_product_id' => $product->id, 'channel' => 'mercado_libre', 'account_key' => (string) $account->id, 'external_listing_id' => 'MLM-'.$product->id], $attributes));
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

    private function account(): MeliAccount
    {
        $user = User::query()->first() ?? User::factory()->create(['role' => User::ROLE_ADMIN]);

        return MeliAccount::create(['user_id' => $user->id, 'meli_user_id' => 'MELI-'.$user->id, 'nickname' => 'Cuenta', 'access_token' => 'token', 'expires_at' => now()->addHour()]);
    }

    private function movement(InventoryProduct $product, int $quantity, string $type = InventoryMovement::INITIAL): void
    {
        $location = DB::table('inventory_locations')->first() ?? tap(DB::table('inventory_locations')->insertGetId(['code' => 'MAIN', 'name' => 'Principal', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]), fn () => null);
        $locationId = is_object($location) ? $location->id : $location;
        InventoryMovement::create(['inventory_product_id' => $product->id, 'inventory_location_id' => $locationId, 'type' => $type, 'quantity' => $quantity, 'occurred_at' => now()]);
    }

    private function location(string $code): InventoryLocation
    {
        return InventoryLocation::firstOrCreate([
            'code' => $code,
        ], [
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function movementAt(InventoryProduct $product, InventoryLocation $location, int $quantity, string $type = InventoryMovement::INITIAL): void
    {
        InventoryMovement::create([
            'inventory_product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'type' => $type,
            'quantity' => $quantity,
            'occurred_at' => now(),
        ]);
    }

    private function reserve(InventoryProduct $product, int $quantity): void
    {
        $location = DB::table('inventory_locations')->first();
        InventoryReservation::create(['inventory_product_id' => $product->id, 'inventory_location_id' => $location->id, 'quantity' => $quantity, 'status' => InventoryReservation::ACTIVE]);
    }
}
