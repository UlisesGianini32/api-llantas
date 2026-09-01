<?php

namespace Tests\Feature;

use App\Jobs\SyncMeliPriceManagerItemsJob;
use App\Models\MeliAccount;
use App\Models\MeliBrandGroup;
use App\Models\MeliCategory;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeliPriceManagerDashboardTest extends TestCase
{
    private const FOCUSED_CATEGORY_ID = 'MLM438195';

    private object $foundationMigration;

    private object $classificationMigration;

    private object $dashboardIndexMigration;

    private object $linkedPublicationsMigration;

    private object $receivableSnapshotMigration;

    private object $categoriesMigration;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('cache.default', 'array');
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', []);
        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', []);
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');
        Cache::flush();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 32)->default('operations');
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->unsignedBigInteger('official_store_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'meli_user_id']);
        });

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();
        $this->categoriesMigration = require database_path('migrations/2026_08_28_000002_create_meli_categories_table.php');
        $this->categoriesMigration->up();
        $this->classificationMigration = require database_path('migrations/2026_08_26_000002_add_brand_classification_audit_to_meli_price_manager_items.php');
        $this->classificationMigration->up();
        $this->dashboardIndexMigration = require database_path('migrations/2026_08_26_000003_add_dashboard_index_to_meli_price_manager_items.php');
        $this->dashboardIndexMigration->up();
        $this->linkedPublicationsMigration = require database_path('migrations/2026_08_29_000001_add_linked_publication_fields_to_meli_price_manager_items.php');
        $this->linkedPublicationsMigration->up();
        $this->receivableSnapshotMigration = require database_path('migrations/2026_08_29_000002_add_estimated_receivable_snapshot_to_meli_price_manager_items.php');
        $this->receivableSnapshotMigration->up();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        $this->receivableSnapshotMigration->down();
        $this->linkedPublicationsMigration->down();
        $this->categoriesMigration->down();
        $this->dashboardIndexMigration->down();
        $this->classificationMigration->down();
        $this->foundationMigration->down();
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_dashboard_requires_authentication(): void
    {
        auth()->logout();

        $this->get(route('meli-price-manager.index'))->assertRedirect(route('login'));
        $this->post(route('meli-price-manager.sync'), [])->assertRedirect(route('login'));
    }

    public function test_dashboard_exposes_only_receivable_snapshots_matching_current_price_without_http(): void
    {
        Http::fake();
        $account = $this->account();
        $current = $this->categorized($account, [
            'current_price' => 200,
            'estimated_receivable' => 113.90,
            'estimated_receivable_price' => 200,
        ]);
        $stale = $this->categorized($account, [
            'current_price' => 220,
            'estimated_receivable' => 113.90,
            'estimated_receivable_price' => 200,
        ]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data', fn ($items): bool => collect($items)->firstWhere('id', $current->id)['current_estimated_receivable'] === 113.9
                    && collect($items)->firstWhere('id', $stale->id)['current_estimated_receivable'] === null));

        Http::assertNothingSent();
    }

    public function test_brand_cards_and_active_brand_options_have_one_global_case_insensitive_order(): void
    {
        $account = $this->account();
        foreach ([['Zep', 0], ['amazon Usa', 0], ['BaBylissPRO', 0], ['ALFAPARF', 50], ['AccessPRO', 50], ['Agate', 50]] as [$name, $sortOrder]) {
            $brand = MeliBrandGroup::factory()->create(['name' => $name, 'sort_order' => $sortOrder]);
            $this->categorized($account, ['brand_group_id' => $brand->id]);
        }
        MeliBrandGroup::factory()->create(['name' => 'Aardvark inactiva', 'active' => false]);
        $expected = ['AccessPRO', 'Agate', 'ALFAPARF', 'amazon Usa', 'BaBylissPRO', 'Zep'];

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('brands', fn ($brands): bool => collect($brands)->pluck('name')->all() === $expected)
                ->where('brandOptions', fn ($brands): bool => collect($brands)->pluck('name')->all() === $expected));
    }

    public function test_categorized_item_brand_can_be_changed_with_audited_internal_only_assignment(): void
    {
        Http::fake();
        $account = $this->account();
        $oldBrand = MeliBrandGroup::factory()->create(['name' => 'BaBylissPRO']);
        $newBrand = MeliBrandGroup::factory()->create(['name' => 'ALFAPARF']);
        $suggested = MeliBrandGroup::factory()->create();
        $item = $this->categorized($account, [
            'brand_group_id' => $oldBrand->id,
            'suggested_brand_group_id' => $suggested->id,
            'current_price' => 200,
            'available_quantity' => 7,
        ]);

        $this->post(route('meli-price-manager.items.brand.update', $item), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $newBrand->id,
        ])->assertRedirect()->assertSessionHas('success');

        $item->refresh();
        $this->assertSame($newBrand->id, $item->brand_group_id);
        $this->assertNull($item->suggested_brand_group_id);
        $this->assertSame('categorized', $item->classification_status);
        $this->assertSame('manual_assignment', $item->classification_source);
        $this->assertSame('200.00', $item->current_price);
        $this->assertSame(7, $item->available_quantity);
        $decision = collect($item->classification_metadata['manual_decisions'])->last();
        $this->assertSame('assign_brand', $decision['action']);
        $this->assertSame($oldBrand->id, $decision['previous_brand_group_id']);
        $this->assertSame($newBrand->id, $decision['selected_brand_group_id']);
        $this->assertSame($this->user->id, $decision['user_id']);
        Http::assertNothingSent();

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'brand' => $newBrand->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 1)
                ->where('items.data.0.id', $item->id)
                ->where('brands', fn ($brands): bool => collect($brands)->firstWhere('id', $newBrand->id)['categorized_items_count'] === 1
                    && collect($brands)->firstWhere('id', $oldBrand->id) === null));
    }

    public function test_same_brand_is_a_no_op_and_inactive_foreign_or_unfocused_assignments_are_rejected(): void
    {
        Http::fake();
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $inactive = MeliBrandGroup::factory()->create(['active' => false]);
        $item = $this->categorized($account, ['brand_group_id' => $brand->id]);
        $metadata = $item->classification_metadata;
        $updatedAt = $item->updated_at;

        $this->post(route('meli-price-manager.items.brand.update', $item), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $brand->id,
        ])->assertRedirect()->assertSessionHas('info');
        $this->assertSame($metadata, $item->fresh()->classification_metadata);
        $this->assertTrue($updatedAt->equalTo($item->fresh()->updated_at));

        $this->post(route('meli-price-manager.items.brand.update', $item), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $inactive->id,
        ])->assertSessionHasErrors('brand_group_id');

        $foreignAccount = MeliAccount::factory()->create();
        $foreignItem = $this->categorized($foreignAccount, ['brand_group_id' => $brand->id]);
        $this->post(route('meli-price-manager.items.brand.update', $foreignItem), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $brand->id,
        ])->assertSessionHasErrors('item');

        config()->set('meli_price_manager.focused_catalog.allowed_category_ids', ['MLM-OTHER']);
        $this->post(route('meli-price-manager.items.brand.update', $item), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $brand->id,
        ])->assertSessionHasErrors('item');
        Http::assertNothingSent();
    }

    public function test_dashboard_exposes_only_authenticated_users_accounts(): void
    {
        $own = $this->account(['nickname' => 'Propia']);
        MeliAccount::factory()->create(['nickname' => 'Ajena']);

        $this->get(route('meli-price-manager.index', ['account' => $own->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.id', $own->id)
                ->where('accounts.0.nickname', 'Propia'));
    }

    public function test_dashboard_rejects_foreign_account_in_query_string(): void
    {
        $foreign = MeliAccount::factory()->create();

        $this->get(route('meli-price-manager.index', ['account' => $foreign->id]))->assertNotFound();
    }

    public function test_summary_statistics_match_selected_account(): void
    {
        $account = $this->account();
        $this->item($account, ['classification_status' => 'categorized']);
        $this->item($account, ['classification_status' => 'suggested']);
        $this->item($account, ['classification_status' => 'uncategorized']);
        $this->item($account, ['classification_status' => 'ignored']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 4)
                ->where('summary.categorized', 1)
                ->where('summary.suggested', 1)
                ->where('summary.uncategorized', 1)
                ->where('summary.ignored', 1)
                ->where('summary.pending', 2));
    }

    public function test_dashboard_counts_only_items_from_the_focused_catalog_and_exposes_category_labels(): void
    {
        $account = $this->account();
        $migration = require database_path('migrations/2026_08_28_000002_create_meli_categories_table.php');
        $migration->up();
        config()->set('meli_price_manager.focused_catalog.allowed_root_category_ids', ['MLM-TEST-BEAUTY-ROOT']);
        MeliCategory::query()->create([
            'category_id' => 'MLM-TEST-SHAMPOO',
            'name' => 'Shampoo y acondicionadores',
            'root_category_id' => 'MLM-TEST-BEAUTY-ROOT',
        ]);
        MeliCategory::query()->create([
            'category_id' => 'MLM-TEST-BEER',
            'name' => 'Cervezas',
            'root_category_id' => 'MLM-TEST-DRINKS-ROOT',
        ]);
        $included = $this->item($account, ['classification_status' => 'categorized', 'category_id' => 'MLM-TEST-SHAMPOO']);
        $this->item($account, ['classification_status' => 'categorized', 'category_id' => 'MLM-TEST-BEER']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 1)
                ->has('items.data', 1)
                ->where('items.data.0.id', $included->id)
                ->where('items.data.0.category.name', 'Shampoo y acondicionadores')
                ->where('availableCategories.0.category_id', 'MLM-TEST-SHAMPOO')
                ->where('availableCategories.0.name', 'Shampoo y acondicionadores'));
    }

    public function test_counts_from_another_account_are_never_mixed(): void
    {
        $selected = $this->account();
        $foreign = MeliAccount::factory()->create();
        $this->item($selected, ['classification_status' => 'categorized']);
        $this->item($foreign, ['classification_status' => 'categorized']);
        $this->item($foreign, ['classification_status' => 'suggested']);

        $this->get(route('meli-price-manager.index', ['account' => $selected->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total', 1)
                ->where('summary.categorized', 1)
                ->where('summary.suggested', 0));
    }

    public function test_brand_summary_uses_selected_account_and_excludes_empty_active_brands(): void
    {
        $account = $this->account();
        $foreign = MeliAccount::factory()->create();
        $brand = MeliBrandGroup::factory()->create(['name' => 'ALFAPARF', 'sort_order' => 1]);
        MeliBrandGroup::factory()->create(['name' => 'VACÍA', 'sort_order' => 2]);
        $this->item($account, ['brand_group_id' => $brand->id, 'classification_status' => 'categorized', 'current_price' => 100, 'available_quantity' => 3]);
        $this->item($account, ['brand_group_id' => $brand->id, 'classification_status' => 'categorized', 'current_price' => 250, 'available_quantity' => 7]);
        $this->item($account, ['suggested_brand_group_id' => $brand->id, 'classification_status' => 'suggested']);
        $this->item($foreign, ['brand_group_id' => $brand->id, 'classification_status' => 'categorized', 'current_price' => 999]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('brands', 1)
                ->where('brands.0.name', 'ALFAPARF')
                ->where('brands.0.categorized_items_count', 2)
                ->where('brands.0.suggested_items_count', 1)
                ->where('brands.0.min_price', 100)
                ->where('brands.0.max_price', 250)
                ->where('brands.0.total_stock', 10));
    }

    public function test_selected_brand_filters_categorized_items(): void
    {
        $account = $this->account();
        $selectedBrand = MeliBrandGroup::factory()->create();
        $otherBrand = MeliBrandGroup::factory()->create();
        $selected = $this->item($account, ['brand_group_id' => $selectedBrand->id, 'classification_status' => 'categorized']);
        $this->item($account, ['brand_group_id' => $otherBrand->id, 'classification_status' => 'categorized']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'brand' => $selectedBrand->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedBrandId', $selectedBrand->id)
                ->has('items.data', 1)
                ->where('items.data.0.id', $selected->id));
    }

    public function test_all_brands_view_only_contains_categorized_items(): void
    {
        $account = $this->account();
        $categorized = $this->item($account, ['classification_status' => 'categorized']);
        $this->item($account, ['classification_status' => 'uncategorized']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $categorized->id));
    }

    public function test_suggested_items_do_not_appear_in_publication_table(): void
    {
        $account = $this->account();
        $this->item($account, ['classification_status' => 'suggested']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0)->where('summary.suggested', 1));
    }

    public function test_ignored_items_do_not_appear_in_publication_table(): void
    {
        $account = $this->account();
        $this->item($account, ['classification_status' => 'ignored']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0)->where('summary.ignored', 1));
    }

    public function test_search_finds_categorized_item_by_title(): void
    {
        $account = $this->account();
        $match = $this->categorized($account, ['title' => 'Shampoo Semi Di Lino']);
        $this->categorized($account, ['title' => 'Producto distinto']);

        $this->assertSingleSearchResult($account, 'Semi Di', $match);
    }

    public function test_search_finds_categorized_item_by_sku(): void
    {
        $account = $this->account();
        $match = $this->categorized($account, ['sku' => 'SKU-ESPECIAL-42']);
        $this->categorized($account, ['sku' => 'OTRO-SKU']);

        $this->assertSingleSearchResult($account, 'ESPECIAL-42', $match);
    }

    public function test_search_finds_categorized_item_by_mlm(): void
    {
        $account = $this->account();
        $match = $this->categorized($account, ['meli_item_id' => 'MLM-SEARCH-99']);
        $this->categorized($account, ['meli_item_id' => 'MLM-OTHER']);

        $this->assertSingleSearchResult($account, 'SEARCH-99', $match);
    }

    public function test_status_filter_uses_values_stored_for_account(): void
    {
        $account = $this->account();
        $match = $this->categorized($account, ['status' => 'under_review']);
        $this->categorized($account, ['status' => 'active']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'status' => 'under_review']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('availableStatuses', fn ($statuses) => collect($statuses)->contains('under_review'))
                ->has('items.data', 1)
                ->where('items.data.0.id', $match->id));
    }

    public function test_category_filter_matches_exact_category_id(): void
    {
        $account = $this->account();
        $match = $this->categorized($account, ['category_id' => self::FOCUSED_CATEGORY_ID]);
        $this->categorized($account, ['category_id' => 'MLM167994']);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'category_id' => self::FOCUSED_CATEGORY_ID]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $match->id));
    }

    public function test_stock_filter_supports_in_stock_and_out_of_stock(): void
    {
        $account = $this->account();
        $inStock = $this->categorized($account, ['available_quantity' => 4]);
        $outOfStock = $this->categorized($account, ['available_quantity' => 0]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'stock' => 'in_stock']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $inStock->id));
        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'stock' => 'out_of_stock']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $outOfStock->id));
    }

    public function test_price_range_filter_is_applied_server_side(): void
    {
        $account = $this->account();
        $match = $this->categorized($account, ['current_price' => 500]);
        $this->categorized($account, ['current_price' => 100]);
        $this->categorized($account, ['current_price' => 900]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'min_price' => 400, 'max_price' => 600]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $match->id));
    }

    public function test_items_can_be_sorted_by_price(): void
    {
        $account = $this->account();
        $expensive = $this->categorized($account, ['current_price' => 900]);
        $cheap = $this->categorized($account, ['current_price' => 100]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'sort' => 'price', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.id', $cheap->id)
                ->where('items.data.1.id', $expensive->id));
    }

    public function test_items_can_be_sorted_by_stock(): void
    {
        $account = $this->account();
        $low = $this->categorized($account, ['available_quantity' => 1]);
        $high = $this->categorized($account, ['available_quantity' => 20]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'sort' => 'stock', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.id', $high->id)
                ->where('items.data.1.id', $low->id));
    }

    public function test_dashboard_uses_server_side_pagination_with_default_fifty(): void
    {
        $account = $this->account();
        MeliPriceManagerItem::factory()->count(51)->for($account, 'meliAccount')->create([
            'category_id' => self::FOCUSED_CATEGORY_ID,
            'classification_status' => 'categorized',
        ]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('items.data', 50)
                ->where('items.per_page', 50)
                ->where('items.last_page', 2));
    }

    public function test_per_page_cannot_exceed_allowed_values(): void
    {
        $account = $this->account();
        MeliPriceManagerItem::factory()->count(51)->for($account, 'meliAccount')->create([
            'category_id' => self::FOCUSED_CATEGORY_ID,
            'classification_status' => 'categorized',
        ]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'per_page' => 10000]))
            ->assertInertia(fn (Assert $page) => $page->where('items.per_page', 50)->has('items.data', 50));
    }

    public function test_sync_endpoint_dispatches_unique_job_to_meli_queue(): void
    {
        Bus::fake();
        $account = $this->account();

        $this->post(route('meli-price-manager.sync'), ['meli_account_id' => $account->id])
            ->assertRedirect()->assertSessionHas('success');

        Bus::assertDispatched(SyncMeliPriceManagerItemsJob::class, fn (SyncMeliPriceManagerItemsJob $job) => $job->meliAccountId === $account->id && $job->queue === 'meli');
    }

    public function test_sync_endpoint_reports_existing_sync_without_dispatching_duplicate(): void
    {
        Bus::fake();
        $account = $this->account();

        $this->post(route('meli-price-manager.sync'), ['meli_account_id' => $account->id]);
        $this->post(route('meli-price-manager.sync'), ['meli_account_id' => $account->id])
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'Ya existe'));

        Bus::assertDispatchedTimes(SyncMeliPriceManagerItemsJob::class, 1);
    }

    public function test_sync_endpoint_rejects_foreign_account(): void
    {
        Bus::fake();
        $foreign = MeliAccount::factory()->create();

        $this->post(route('meli-price-manager.sync'), ['meli_account_id' => $foreign->id])
            ->assertSessionHasErrors('meli_account_id');

        Bus::assertNotDispatched(SyncMeliPriceManagerItemsJob::class);
    }

    public function test_dispatching_sync_does_not_modify_price_before_job_runs(): void
    {
        Bus::fake();
        $account = $this->account();
        $item = $this->categorized($account, ['current_price' => '1234.56']);

        $this->post(route('meli-price-manager.sync'), ['meli_account_id' => $account->id]);

        $this->assertSame('1234.56', $item->fresh()->current_price);
    }

    public function test_dispatching_sync_does_not_modify_stock_before_job_runs(): void
    {
        Bus::fake();
        $account = $this->account();
        $item = $this->categorized($account, ['available_quantity' => 37]);

        $this->post(route('meli-price-manager.sync'), ['meli_account_id' => $account->id]);

        $this->assertSame(37, $item->fresh()->available_quantity);
    }

    public function test_pagination_links_preserve_selected_account_and_filters(): void
    {
        $account = $this->account();
        MeliPriceManagerItem::factory()->count(26)->for($account, 'meliAccount')->create([
            'category_id' => self::FOCUSED_CATEGORY_ID,
            'classification_status' => 'categorized',
            'status' => 'active',
        ]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'status' => 'active', 'per_page' => 25]))
            ->assertInertia(fn (Assert $page) => $page->where('items.links', fn ($links) => collect($links)
                ->pluck('url')->filter()->every(fn (string $url) => str_contains($url, 'account='.$account->id) && str_contains($url, 'status=active'))));
    }

    public function test_stale_statistics_use_configured_threshold_and_include_never_synced(): void
    {
        config()->set('meli_price_manager.stale_after_hours', 24);
        $account = $this->account();
        $this->categorized($account, ['last_synced_at' => now()->subHours(2)]);
        $this->categorized($account, ['last_synced_at' => now()->subHours(30)]);
        $this->categorized($account, ['last_synced_at' => null]);

        $this->get(route('meli-price-manager.index', ['account' => $account->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.recently_synced', 1)
                ->where('summary.never_synced', 1)
                ->where('summary.stale', 2)
                ->where('syncStatus.stale_after_hours', 24));
    }

    private function assertSingleSearchResult(MeliAccount $account, string $search, MeliPriceManagerItem $expected): void
    {
        $this->get(route('meli-price-manager.index', ['account' => $account->id, 'search' => $search]))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $expected->id));
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->for($this->user)->create($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function categorized(MeliAccount $account, array $overrides = []): MeliPriceManagerItem
    {
        return $this->item($account, ['classification_status' => 'categorized', ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function item(MeliAccount $account, array $overrides = []): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create([
            'meli_item_id' => 'MLM'.fake()->unique()->numberBetween(100000000, 999999999),
            'category_id' => self::FOCUSED_CATEGORY_ID,
            ...$overrides,
        ]);
    }
}
