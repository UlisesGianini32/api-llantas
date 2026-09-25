<?php

namespace Tests\Feature;

use App\Models\InventoryChannelLink;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliPublication;
use App\Models\User;
use App\Services\InventoryMeliLinkImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryMeliLinkImportTest extends TestCase
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
        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('meli_account_id')->nullable()->index();
            $table->string('sku')->index();
            $table->string('mlm')->index();
            $table->string('source_mlm')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->string('category_id')->nullable();
            $table->json('pictures')->nullable();
            $table->boolean('is_current')->default(true)->index();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_25_000001_create_inventory_channel_links_table.php'))->up();
        (require database_path('migrations/2026_09_25_000002_add_stock_sync_enabled_to_inventory_channel_links.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('inventory_channel_stock_syncs');
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
        Schema::dropIfExists('meli_publications');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_exact_sku_is_matched_and_preview_is_read_only(): void
    {
        [$account] = $this->fixture();
        $product = $this->product('SKU-EXACT');
        $this->publication($account, 'MLM-EXACT', 'SKU-EXACT');
        $preview = $this->service()->preview();
        $this->assertSame(1, $preview['counts'][InventoryMeliLinkImportService::MATCHED]);
        $this->assertSame($product->id, $preview['rows'][0]['inventory_product_id']);
        $this->assertDatabaseCount('inventory_channel_links', 0);
    }

    public function test_sku_trim_is_supported_but_unknown_and_missing_are_reported(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-TRIM');
        $this->publication($account, 'MLM-TRIM', ' SKU-TRIM ');
        $this->publication($account, 'MLM-MISSING', '');
        $this->publication($account, 'MLM-UNKNOWN', 'NO-SUCH-SKU');
        $preview = $this->service()->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');
        $this->assertSame(InventoryMeliLinkImportService::MATCHED, $rows['MLM-TRIM']['status']);
        $this->assertSame(InventoryMeliLinkImportService::MISSING_SKU, $rows['MLM-MISSING']['status']);
        $this->assertSame(InventoryMeliLinkImportService::PRODUCT_NOT_FOUND, $rows['MLM-UNKNOWN']['status']);
    }

    public function test_apply_creates_the_correct_channel_link_with_account_and_remote_data(): void
    {
        [$account] = $this->fixture();
        $product = $this->product('SKU-APPLY');
        $publication = $this->publication($account, 'MLM-APPLY', 'SKU-APPLY', ['price' => 125.5, 'currency_id' => 'MXN']);
        $result = $this->service()->apply();
        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseHas('inventory_channel_links', [
            'inventory_product_id' => $product->id,
            'channel' => InventoryChannelLink::MERCADO_LIBRE,
            'account_key' => (string) $account->id,
            'external_listing_id' => 'MLM-APPLY',
            'remote_price' => '125.50',
            'remote_currency' => 'MXN',
        ]);
        $this->assertSame(MeliPublication::class, data_get(InventoryChannelLink::query()->first()->metadata, 'legacy_model'));
        $this->assertSame($publication->id, data_get(InventoryChannelLink::query()->first()->metadata, 'legacy_id'));
    }

    public function test_import_is_idempotent_and_existing_correct_link_is_already_linked(): void
    {
        [$account] = $this->fixture();
        $product = $this->product('SKU-IDEMPOTENT');
        $this->publication($account, 'MLM-IDEMPOTENT', 'SKU-IDEMPOTENT');
        $this->assertSame(1, $this->service()->apply()['imported']);
        $second = $this->service()->preview();
        $this->assertSame(1, $second['counts'][InventoryMeliLinkImportService::ALREADY_LINKED]);
        $this->assertSame($product->id, $second['rows'][0]['inventory_product_id']);
        $this->assertDatabaseCount('inventory_channel_links', 1);
    }

    public function test_existing_link_to_other_product_is_conflict_and_does_not_block_other_match(): void
    {
        [$account] = $this->fixture();
        $first = $this->product('SKU-CONFLICT');
        $second = $this->product('SKU-OK');
        $this->publication($account, 'MLM-CONFLICT', 'SKU-CONFLICT');
        $this->publication($account, 'MLM-OK', 'SKU-OK');
        app(\App\Services\InventoryChannelLinkService::class)->create(['inventory_product_id' => $second->id, 'channel' => InventoryChannelLink::MERCADO_LIBRE, 'account_key' => (string) $account->id, 'external_listing_id' => 'MLM-CONFLICT']);
        $preview = $this->service()->preview();
        $rows = collect($preview['rows'])->keyBy('mlm');
        $this->assertSame(InventoryMeliLinkImportService::CONFLICT, $rows['MLM-CONFLICT']['status']);
        $this->assertSame(InventoryMeliLinkImportService::MATCHED, $rows['MLM-OK']['status']);
        $result = $this->service()->apply();
        $this->assertSame(1, $result['imported']);
        $this->assertSame($second->id, InventoryChannelLink::query()->where('external_listing_id', 'MLM-OK')->value('inventory_product_id'));
        $this->assertNotSame($first->id, InventoryChannelLink::query()->where('external_listing_id', 'MLM-CONFLICT')->value('inventory_product_id'));
    }

    public function test_variations_with_distinct_skus_create_distinct_links(): void
    {
        [$account] = $this->fixture();
        $first = $this->product('SKU-VAR-A');
        $second = $this->product('SKU-VAR-B');
        $this->publication($account, 'MLM-VARS', null, ['variations' => [
            ['id' => 111, 'seller_custom_field' => 'SKU-VAR-A', 'price' => 10],
            ['id' => 222, 'seller_custom_field' => 'SKU-VAR-B', 'price' => 11],
        ]]);
        $result = $this->service()->apply();
        $this->assertSame(2, $result['imported']);
        $this->assertDatabaseHas('inventory_channel_links', ['external_listing_id' => 'MLM-VARS', 'external_variant_id' => '111', 'inventory_product_id' => $first->id]);
        $this->assertDatabaseHas('inventory_channel_links', ['external_listing_id' => 'MLM-VARS', 'external_variant_id' => '222', 'inventory_product_id' => $second->id]);
        $this->assertSame(2, $this->service()->preview()['counts'][InventoryMeliLinkImportService::ALREADY_LINKED]);
    }

    public function test_same_external_variation_cannot_be_assigned_to_two_products(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-VAR-ONE');
        $this->product('SKU-VAR-TWO');
        $this->publication($account, 'MLM-VAR-CONFLICT', null, ['variations' => [
            ['id' => 7, 'seller_custom_field' => 'SKU-VAR-ONE'],
        ]]);
        $this->service()->apply();
        DB::table('meli_publications')->update(['raw' => json_encode(['item' => ['id' => 'MLM-VAR-CONFLICT', 'variations' => [['id' => 7, 'seller_custom_field' => 'SKU-VAR-TWO']]]]), 'sku' => '']);
        $preview = $this->service()->preview();
        $this->assertSame(InventoryMeliLinkImportService::CONFLICT, $preview['rows'][0]['status']);
    }

    public function test_operations_can_preview_but_cannot_apply(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-OPS');
        $this->publication($account, 'MLM-OPS', 'SKU-OPS');
        $this->actingAs($this->operations());
        $this->get(route('inventory.channels.mercado-libre.import', ['analyze' => 1]))->assertInertia(fn (Assert $page) => $page->component('Inventory/Channels/MercadoLibreImport'));
        $this->post(route('inventory.channels.mercado-libre.apply'), ['analyze' => 1])->assertForbidden();
        $this->assertDatabaseCount('inventory_channel_links', 0);
    }

    public function test_admin_can_apply_and_no_stock_or_legacy_write_is_performed(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-ADMIN');
        $publication = $this->publication($account, 'MLM-ADMIN', 'SKU-ADMIN');
        $before = $publication->fresh()->toArray();
        $this->actingAs($this->admin());
        $this->post(route('inventory.channels.mercado-libre.apply'), ['analyze' => 1])->assertRedirect();
        $this->assertSame($before, $publication->fresh()->toArray());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_preview_filters_by_account_result_and_searches_sku_or_mlm(): void
    {
        [$first] = $this->fixture('ONE');
        [$second] = $this->fixture('TWO');
        $this->product('SKU-FILTER');
        $this->publication($first, 'MLM-FILTER', 'SKU-FILTER');
        $this->publication($second, 'MLM-OTHER', 'NO-MATCH');
        $service = $this->service();
        $this->assertCount(1, $service->preview(['account_key' => $first->id])['rows']);
        $this->assertSame('MLM-FILTER', $service->preview(['search' => 'MLM-FILTER'])['rows'][0]['mlm']);
        $this->assertSame('SKU-FILTER', $service->preview(['search' => 'SKU-FILTER'])['rows'][0]['sku']);
        $this->assertCount(1, $service->preview(['result' => InventoryMeliLinkImportService::PRODUCT_NOT_FOUND])['rows']);
    }

    public function test_importer_never_makes_http_requests(): void
    {
        Http::fake();
        [$account] = $this->fixture();
        $this->product('SKU-HTTP');
        $this->publication($account, 'MLM-HTTP', 'SKU-HTTP');
        $this->service()->preview();
        Http::assertNothingSent();
    }

    public function test_missing_account_is_unsupported(): void
    {
        $this->product('SKU-NO-ACCOUNT');
        $this->publication(null, 'MLM-NO-ACCOUNT', 'SKU-NO-ACCOUNT');
        $preview = $this->service()->preview();
        $this->assertSame(InventoryMeliLinkImportService::UNSUPPORTED, $preview['rows'][0]['status']);
    }

    public function test_variation_seller_sku_attribute_is_supported(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-ATTRIBUTE');
        $this->publication($account, 'MLM-ATTRIBUTE', null, ['variations' => [['id' => 8, 'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'SKU-ATTRIBUTE']]]]]);
        $this->assertSame(InventoryMeliLinkImportService::MATCHED, $this->service()->preview()['rows'][0]['status']);
    }

    public function test_single_listing_uses_publication_sku_fallback(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-FALLBACK');
        $this->publication($account, 'MLM-FALLBACK', 'SKU-FALLBACK', ['seller_custom_field' => null]);
        $this->assertSame(InventoryMeliLinkImportService::MATCHED, $this->service()->preview()['rows'][0]['status']);
    }

    public function test_malformed_variation_is_unsupported_instead_of_guessed(): void
    {
        [$account] = $this->fixture();
        $this->publication($account, 'MLM-UNSUPPORTED', null, ['variations' => [['seller_custom_field' => 'SKU-GUESS']]]);
        $this->assertSame(InventoryMeliLinkImportService::UNSUPPORTED, $this->service()->preview()['rows'][0]['status']);
    }

    public function test_only_matched_rows_are_applied(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-MATCHED');
        $this->publication($account, 'MLM-MATCHED', 'SKU-MATCHED');
        $this->publication($account, 'MLM-MISSING', 'SKU-NOT-IN-INVENTORY');
        $this->assertSame(1, $this->service()->apply()['imported']);
        $this->assertDatabaseCount('inventory_channel_links', 1);
    }

    public function test_remote_status_url_currency_and_price_are_informational_only(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-INFORMATIVE');
        $publication = $this->publication($account, 'MLM-INFORMATIVE', 'SKU-INFORMATIVE', ['price' => 42.75, 'currency_id' => 'USD', 'permalink' => 'https://example.test/informative']);
        $this->service()->apply();
        $link = InventoryChannelLink::query()->first();
        $this->assertSame('active', $link->remote_status);
        $this->assertSame('https://example.test/informative', $link->external_url);
        $this->assertSame('USD', $link->remote_currency);
        $this->assertSame($publication->last_sync_at->toDateTimeString(), $link->last_synced_at->toDateTimeString());
    }

    public function test_main_listing_and_variation_have_distinct_external_identities(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-MAIN');
        $this->product('SKU-VARIATION');
        $this->publication($account, 'MLM-IDENTITY', 'SKU-MAIN');
        $this->publication($account, 'MLM-IDENTITY-VAR', null, ['variations' => [['id' => 1, 'seller_custom_field' => 'SKU-VARIATION']]]);
        $this->assertSame(2, $this->service()->apply()['imported']);
        $this->assertNotSame(InventoryChannelLink::query()->first()->identity_key, InventoryChannelLink::query()->latest('id')->first()->identity_key);
    }

    public function test_reimporting_two_variations_is_idempotent(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-REIMPORT-A');
        $this->product('SKU-REIMPORT-B');
        $this->publication($account, 'MLM-REIMPORT', null, ['variations' => [['id' => 1, 'seller_custom_field' => 'SKU-REIMPORT-A'], ['id' => 2, 'seller_custom_field' => 'SKU-REIMPORT-B']]]);
        $this->assertSame(2, $this->service()->apply()['imported']);
        $this->assertSame(2, $this->service()->preview()['counts'][InventoryMeliLinkImportService::ALREADY_LINKED]);
    }

    public function test_account_key_comes_from_the_real_meli_account_id(): void
    {
        [$account] = $this->fixture('REAL-ID');
        $this->product('SKU-ACCOUNT');
        $this->publication($account, 'MLM-ACCOUNT', 'SKU-ACCOUNT');
        $row = $this->service()->preview()['rows'][0];
        $this->assertSame((string) $account->id, $row['account_key']);
    }

    public function test_title_is_never_used_as_automatic_matching(): void
    {
        [$account] = $this->fixture();
        $this->product('TITLE-ONLY');
        $this->publication($account, 'MLM-TITLE', '', ['title' => 'TITLE-ONLY']);
        $this->assertSame(InventoryMeliLinkImportService::MISSING_SKU, $this->service()->preview()['rows'][0]['status']);
    }

    public function test_import_does_not_create_inventory_stock_records(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-NO-STOCK');
        $this->publication($account, 'MLM-NO-STOCK', 'SKU-NO-STOCK');
        $this->service()->apply();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('inventory_channel_links', 1);
    }

    public function test_preview_returns_all_required_result_categories(): void
    {
        [$account] = $this->fixture();
        $this->product('SKU-CATEGORIES');
        $this->publication($account, 'MLM-CATEGORY-MATCH', 'SKU-CATEGORIES');
        $this->publication($account, 'MLM-CATEGORY-MISSING', '');
        $this->publication($account, 'MLM-CATEGORY-NOTFOUND', 'NO-CATEGORY');
        $counts = $this->service()->preview()['counts'];
        $this->assertSame(1, $counts[InventoryMeliLinkImportService::MATCHED]);
        $this->assertSame(1, $counts[InventoryMeliLinkImportService::MISSING_SKU]);
        $this->assertSame(1, $counts[InventoryMeliLinkImportService::PRODUCT_NOT_FOUND]);
        $this->assertArrayHasKey(InventoryMeliLinkImportService::UNSUPPORTED, $counts);
    }

    private function service(): InventoryMeliLinkImportService
    {
        return app(InventoryMeliLinkImportService::class);
    }

    private function fixture(string $suffix = 'ONE'): array
    {
        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $account = MeliAccount::create(['user_id' => $user->id, 'meli_user_id' => 'ML-USER-'.$suffix, 'nickname' => 'Cuenta '.$suffix, 'is_default' => true]);

        return [$account, $user];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function operations(): User
    {
        return User::factory()->create(['role' => User::ROLE_OPERATIONS]);
    }

    private function product(string $sku): InventoryProduct
    {
        return InventoryProduct::create(['sku' => $sku, 'name' => $sku]);
    }

    private function publication(?MeliAccount $account, string $mlm, ?string $sku, array $item = []): MeliPublication
    {
        $base = ['id' => $mlm, 'seller_custom_field' => $sku, 'price' => 99, 'currency_id' => 'MXN', 'permalink' => 'https://example.test/'.$mlm];
        $item = array_replace($base, $item);

        return MeliPublication::create([
            'user_id' => $account?->user_id,
            'meli_account_id' => $account?->id,
            'sku' => $sku ?? '',
            'mlm' => $mlm,
            'status' => 'active',
            'permalink' => $item['permalink'],
            'last_sync_at' => now(),
            'raw' => ['item' => $item],
            'is_current' => true,
        ]);
    }
}
