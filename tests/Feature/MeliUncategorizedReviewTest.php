<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliBrandClassificationService;
use App\Services\MercadoLibre\PriceManager\MeliBrandNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeliUncategorizedReviewTest extends TestCase
{
    private object $foundationMigration;

    private object $classificationMigration;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
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
        $this->classificationMigration = require database_path('migrations/2026_08_26_000002_add_brand_classification_audit_to_meli_price_manager_items.php');
        $this->classificationMigration->up();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $this->classificationMigration->down();
        $this->foundationMigration->down();
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_authenticated_user_can_view_only_pending_items_for_selected_account(): void
    {
        $account = $this->account(['is_default' => true]);
        $pending = $this->item($account, ['title' => 'Pendiente visible']);
        $this->item($account, ['title' => 'Ya categorizado', 'classification_status' => 'categorized']);
        $foreign = MeliAccount::factory()->create();
        $this->item($foreign, ['title' => 'Otra cuenta']);

        $this->get(route('meli-price-manager.uncategorized.index', ['account' => $account->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MeliPriceManager/Uncategorized')
                ->where('selectedAccountId', $account->id)
                ->has('items.data', 1)
                ->where('items.data.0.id', $pending->id)
                ->where('counts.pending', 1));
    }

    public function test_unauthenticated_user_cannot_view_or_operate_the_review_inbox(): void
    {
        $account = $this->account();
        $item = $this->item($account);
        auth()->logout();

        $this->get(route('meli-price-manager.uncategorized.index'))->assertRedirect(route('login'));
        $this->post(route('meli-price-manager.items.assign', $item), [])->assertRedirect(route('login'));
        $this->post(route('meli-price-manager.uncategorized.bulk'), [])->assertRedirect(route('login'));
    }

    public function test_uncategorized_filter_excludes_suggested_items(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['classification_status' => 'uncategorized']);
        $this->item($account, ['classification_status' => 'suggested']);

        $this->get(route('meli-price-manager.uncategorized.index', ['account' => $account->id, 'classification_status' => 'uncategorized']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $item->id));
    }

    public function test_suggested_filter_excludes_uncategorized_items(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $item = $this->item($account, ['classification_status' => 'suggested', 'suggested_brand_group_id' => $brand->id]);
        $this->item($account, ['classification_status' => 'uncategorized']);

        $this->get(route('meli-price-manager.uncategorized.index', ['account' => $account->id, 'classification_status' => 'suggested']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $item->id));
    }

    public function test_free_search_finds_item_by_title(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['title' => 'Shampoo Semi Di Lino']);
        $this->item($account, ['title' => 'Producto distinto']);

        $this->get(route('meli-price-manager.uncategorized.index', ['account' => $account->id, 'search' => 'Semi Di']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $item->id));
    }

    public function test_free_search_finds_item_by_sku(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['sku' => 'SKU-ESPECIAL-99']);
        $this->item($account, ['sku' => 'OTRO-SKU']);

        $this->get(route('meli-price-manager.uncategorized.index', ['account' => $account->id, 'search' => 'ESPECIAL-99']))
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.id', $item->id));
    }

    public function test_suggestion_can_be_accepted_with_human_confidence_and_audit_history(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $item = $this->item($account, [
            'classification_status' => 'suggested',
            'suggested_brand_group_id' => $brand->id,
            'classification_source' => 'ambiguous_title_alias',
            'classification_confidence' => '0.8500',
            'classification_metadata' => ['reason' => 'tie'],
        ]);

        $this->post(route('meli-price-manager.items.suggestion.accept', $item), ['meli_account_id' => $account->id])
            ->assertRedirect()->assertSessionHas('success');

        $item->refresh();
        $this->assertSame($brand->id, $item->brand_group_id);
        $this->assertNull($item->suggested_brand_group_id);
        $this->assertSame('categorized', $item->classification_status);
        $this->assertSame('manual_suggestion', $item->classification_source);
        $this->assertSame('1.0000', $item->classification_confidence);
        $this->assertSame('0.8500', $item->classification_metadata['manual_decisions'][0]['previous_classification_confidence']);
        $this->assertSame('tie', $item->classification_metadata['reason']);
    }

    public function test_accepting_item_without_valid_suggestion_fails(): void
    {
        $account = $this->account();
        $item = $this->item($account);

        $this->post(route('meli-price-manager.items.suggestion.accept', $item), ['meli_account_id' => $account->id])
            ->assertSessionHasErrors('item');

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
    }

    public function test_item_can_be_assigned_manually_to_an_active_brand(): void
    {
        $account = $this->account();
        $suggested = MeliBrandGroup::factory()->create();
        $selected = MeliBrandGroup::factory()->create();
        $item = $this->item($account, ['classification_status' => 'suggested', 'suggested_brand_group_id' => $suggested->id]);

        $this->post(route('meli-price-manager.items.assign', $item), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $selected->id,
        ])->assertRedirect()->assertSessionHas('success');

        $item->refresh();
        $this->assertSame($selected->id, $item->brand_group_id);
        $this->assertNull($item->suggested_brand_group_id);
        $this->assertSame('manual_assignment', $item->classification_source);
        $this->assertSame('1.0000', $item->classification_confidence);
    }

    public function test_manual_assignment_is_protected_from_automatic_classifier(): void
    {
        $account = $this->account();
        $manualBrand = MeliBrandGroup::factory()->create();
        $automaticBrand = MeliBrandGroup::factory()->create();
        $this->alias($automaticBrand, 'PROTECTED BRAND');
        $item = $this->item($account, ['meli_brand' => 'Protected Brand', 'normalized_brand' => 'PROTECTED BRAND']);

        $this->post(route('meli-price-manager.items.assign', $item), [
            'meli_account_id' => $account->id,
            'brand_group_id' => $manualBrand->id,
        ]);
        app(MeliBrandClassificationService::class)->classifyAccount($account, reclassifyAll: true);

        $item->refresh();
        $this->assertSame($manualBrand->id, $item->brand_group_id);
        $this->assertSame('manual_assignment', $item->classification_source);
    }

    public function test_pending_item_can_be_ignored(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $item = $this->item($account, ['classification_status' => 'suggested', 'suggested_brand_group_id' => $brand->id]);

        $this->post(route('meli-price-manager.items.ignore', $item), [
            'meli_account_id' => $account->id,
            'confirm' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('ignored', $item->classification_status);
        $this->assertSame('manual_ignored', $item->classification_source);
        $this->assertNull($item->brand_group_id);
        $this->assertNull($item->suggested_brand_group_id);
    }

    public function test_ignored_item_remains_protected_from_classifier(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $this->alias($brand, 'IGNORED BRAND');
        $item = $this->item($account, ['meli_brand' => 'Ignored Brand', 'normalized_brand' => 'IGNORED BRAND']);
        $this->post(route('meli-price-manager.items.ignore', $item), ['meli_account_id' => $account->id, 'confirm' => true]);

        app(MeliBrandClassificationService::class)->classifyAccount($account, reclassifyAll: true);

        $this->assertSame('ignored', $item->fresh()->classification_status);
        $this->assertNull($item->brand_group_id);
    }

    public function test_ignored_item_can_be_restored_to_pending(): void
    {
        $account = $this->account();
        $item = $this->item($account, [
            'classification_status' => 'ignored',
            'classification_source' => 'manual_ignored',
            'classification_confidence' => '1.0000',
        ]);

        $this->post(route('meli-price-manager.items.restore', $item), ['meli_account_id' => $account->id])
            ->assertRedirect()->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('uncategorized', $item->classification_status);
        $this->assertNull($item->brand_group_id);
        $this->assertNull($item->suggested_brand_group_id);
        $this->assertNull($item->classification_source);
        $this->assertNull($item->classification_confidence);
    }

    public function test_alias_can_be_created_and_current_item_is_assigned_transactionally(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $item = $this->item($account, ['meli_brand' => 'Alfaparf Milano']);

        $this->post(route('meli-price-manager.items.alias-and-assign', $item), $this->aliasPayload($account, $brand, 'Alfaparf Milano'))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('meli_brand_aliases', ['brand_group_id' => $brand->id, 'alias' => 'Alfaparf Milano']);
        $item->refresh();
        $this->assertSame($brand->id, $item->brand_group_id);
        $this->assertSame('manual_assignment', $item->classification_source);
    }

    public function test_alias_normalization_is_always_calculated_in_backend(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $item = $this->item($account);
        $payload = $this->aliasPayload($account, $brand, '  Semí-Di Lino ');
        $payload['normalized_alias'] = 'INJECTED';

        $this->post(route('meli-price-manager.items.alias-and-assign', $item), $payload)->assertRedirect();

        $this->assertDatabaseHas('meli_brand_aliases', ['brand_group_id' => $brand->id, 'normalized_alias' => 'SEMI DI LINO']);
        $this->assertDatabaseMissing('meli_brand_aliases', ['normalized_alias' => 'INJECTED']);
    }

    public function test_existing_equivalent_alias_is_reused_without_duplicate(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $existing = $this->alias($brand, 'ALFAPARF');
        $item = $this->item($account);

        $this->post(route('meli-price-manager.items.alias-and-assign', $item), $this->aliasPayload($account, $brand, 'álfaparf'))
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'ya existía'));

        $this->assertSame(1, $brand->aliases()->count());
        $this->assertDatabaseHas('meli_brand_aliases', ['id' => $existing->id]);
        $this->assertSame($brand->id, $item->fresh()->brand_group_id);
    }

    public function test_alias_conflict_in_another_brand_requires_explicit_confirmation(): void
    {
        $account = $this->account();
        $first = MeliBrandGroup::factory()->create(['name' => 'DAVINES']);
        $destination = MeliBrandGroup::factory()->create(['name' => 'ALFAPARF']);
        $this->alias($first, 'OI');
        $item = $this->item($account);
        $payload = $this->aliasPayload($account, $destination, 'oi');

        $this->post(route('meli-price-manager.items.alias-and-assign', $item), $payload)
            ->assertSessionHasErrors('confirm_conflict');
        $this->assertSame(1, MeliBrandAlias::query()->where('normalized_alias', 'OI')->count());
        $this->assertNull($item->fresh()->brand_group_id);

        $payload['confirm_conflict'] = true;
        $this->post(route('meli-price-manager.items.alias-and-assign', $item), $payload)->assertRedirect();
        $this->assertSame(2, MeliBrandAlias::query()->where('normalized_alias', 'OI')->count());
        $this->assertSame($destination->id, $item->fresh()->brand_group_id);
    }

    public function test_new_brand_and_optional_alias_can_be_created_from_item(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['meli_brand' => 'Nueva Marca']);

        $this->post(route('meli-price-manager.items.brand-and-assign', $item), [
            'meli_account_id' => $account->id,
            'name' => '  NUEVA MARCA  ',
            'description' => 'Creada desde bandeja',
            'active' => true,
            'sort_order' => 5,
            'create_alias' => true,
            'alias' => 'Nueva Marca',
            'match_type' => 'exact',
            'alias_priority' => 10,
            'alias_active' => true,
            'confirm_conflict' => false,
        ])->assertRedirect()->assertSessionHas('success');

        $brand = MeliBrandGroup::query()->where('slug', 'nueva-marca')->firstOrFail();
        $this->assertSame('NUEVA MARCA', $brand->name);
        $this->assertDatabaseHas('meli_brand_aliases', ['brand_group_id' => $brand->id, 'normalized_alias' => 'NUEVA MARCA']);
        $this->assertSame($brand->id, $item->fresh()->brand_group_id);
    }

    public function test_bulk_assignment_assigns_every_item_to_selected_brand(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $first = $this->item($account, ['meli_item_id' => 'MLM-BULK-1']);
        $second = $this->item($account, ['meli_item_id' => 'MLM-BULK-2', 'classification_status' => 'suggested']);

        $this->post(route('meli-price-manager.uncategorized.bulk'), [
            'meli_account_id' => $account->id,
            'item_ids' => [$first->id, $second->id],
            'action' => 'assign',
            'brand_group_id' => $brand->id,
            'confirm' => true,
        ])->assertRedirect()->assertSessionHas('success');

        foreach ([$first, $second] as $item) {
            $item->refresh();
            $this->assertSame($brand->id, $item->brand_group_id);
            $this->assertSame('manual_bulk_assignment', $item->classification_source);
            $this->assertSame('1.0000', $item->classification_confidence);
        }
    }

    public function test_bulk_suggestion_acceptance_uses_each_items_own_suggestion(): void
    {
        $account = $this->account();
        $firstBrand = MeliBrandGroup::factory()->create();
        $secondBrand = MeliBrandGroup::factory()->create();
        $first = $this->item($account, ['meli_item_id' => 'MLM-SUG-1', 'classification_status' => 'suggested', 'suggested_brand_group_id' => $firstBrand->id]);
        $second = $this->item($account, ['meli_item_id' => 'MLM-SUG-2', 'classification_status' => 'suggested', 'suggested_brand_group_id' => $secondBrand->id]);

        $this->post(route('meli-price-manager.uncategorized.bulk'), [
            'meli_account_id' => $account->id,
            'item_ids' => [$first->id, $second->id],
            'action' => 'accept_suggestions',
            'confirm' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $first->refresh();
        $second->refresh();
        $this->assertSame($firstBrand->id, $first->brand_group_id);
        $this->assertSame($secondBrand->id, $second->brand_group_id);
        $this->assertSame('manual_bulk_suggestion', $first->classification_source);
    }

    public function test_bulk_operation_rejects_selection_containing_another_account(): void
    {
        $account = $this->account();
        $own = $this->item($account);
        $foreign = $this->item(MeliAccount::factory()->create());
        $brand = MeliBrandGroup::factory()->create();

        $this->post(route('meli-price-manager.uncategorized.bulk'), [
            'meli_account_id' => $account->id,
            'item_ids' => [$own->id, $foreign->id],
            'action' => 'assign',
            'brand_group_id' => $brand->id,
            'confirm' => true,
        ])->assertSessionHasErrors('item_ids');

        $this->assertNull($own->fresh()->brand_group_id);
        $this->assertNull($foreign->fresh()->brand_group_id);
    }

    public function test_single_item_from_another_account_cannot_be_modified(): void
    {
        $selectedAccount = $this->account();
        $foreignItem = $this->item(MeliAccount::factory()->create());
        $brand = MeliBrandGroup::factory()->create();

        $this->post(route('meli-price-manager.items.assign', $foreignItem), [
            'meli_account_id' => $selectedAccount->id,
            'brand_group_id' => $brand->id,
        ])->assertSessionHasErrors('item');

        $this->assertNull($foreignItem->fresh()->brand_group_id);
    }

    public function test_manual_action_never_changes_current_price(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['current_price' => '1234.56']);
        $brand = MeliBrandGroup::factory()->create();

        $this->post(route('meli-price-manager.items.assign', $item), ['meli_account_id' => $account->id, 'brand_group_id' => $brand->id]);

        $this->assertSame('1234.56', $item->fresh()->current_price);
    }

    public function test_manual_action_never_changes_available_quantity(): void
    {
        $account = $this->account();
        $item = $this->item($account, ['available_quantity' => 47]);

        $this->post(route('meli-price-manager.items.ignore', $item), ['meli_account_id' => $account->id, 'confirm' => true]);

        $this->assertSame(47, $item->fresh()->available_quantity);
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->for($this->user)->create($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function item(MeliAccount $account, array $overrides = []): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create([
            'meli_item_id' => 'MLM'.fake()->unique()->numberBetween(100000000, 999999999),
            'classification_status' => 'uncategorized',
            'brand_group_id' => null,
            'suggested_brand_group_id' => null,
            ...$overrides,
        ]);
    }

    private function alias(MeliBrandGroup $brand, string $value): MeliBrandAlias
    {
        return MeliBrandAlias::factory()->for($brand, 'brandGroup')->create([
            'alias' => $value,
            'normalized_alias' => app(MeliBrandNormalizer::class)->normalize($value),
            'match_type' => 'exact',
            'priority' => 0,
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function aliasPayload(MeliAccount $account, MeliBrandGroup $brand, string $alias): array
    {
        return [
            'meli_account_id' => $account->id,
            'brand_group_id' => $brand->id,
            'alias' => $alias,
            'match_type' => 'exact',
            'priority' => 0,
            'active' => true,
            'confirm_conflict' => false,
        ];
    }
}
