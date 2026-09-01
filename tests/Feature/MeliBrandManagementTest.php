<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliBrandNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeliBrandManagementTest extends TestCase
{
    private object $foundationMigration;

    private object $classificationMigration;

    private object $titleContainsMigration;

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
        $this->classificationMigration = require database_path('migrations/2026_08_26_000002_add_brand_classification_audit_to_meli_price_manager_items.php');
        $this->classificationMigration->up();
        $this->titleContainsMigration = require database_path('migrations/2026_08_27_000001_add_title_contains_to_meli_brand_aliases.php');
        $this->titleContainsMigration->up();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $this->titleContainsMigration->down();
        $this->classificationMigration->down();
        $this->foundationMigration->down();
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_brand_can_be_created_with_trimmed_name_and_generated_slug(): void
    {
        $this->post(route('meli-price-manager.brands.store'), [
            'name' => '  ALFAPARF  ',
            'description' => 'Marca profesional',
            'active' => true,
            'sort_order' => 20,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('meli_brand_groups', [
            'name' => 'ALFAPARF',
            'slug' => 'alfaparf',
            'active' => true,
            'sort_order' => 20,
        ]);
    }

    public function test_editing_name_keeps_the_submitted_stable_slug(): void
    {
        $brand = MeliBrandGroup::factory()->create(['name' => 'Anterior', 'slug' => 'slug-estable']);

        $this->put(route('meli-price-manager.brands.update', $brand), [
            'name' => 'Nombre nuevo',
            'slug' => 'slug-estable',
            'description' => null,
            'active' => true,
            'sort_order' => 3,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('meli_brand_groups', [
            'id' => $brand->id,
            'name' => 'Nombre nuevo',
            'slug' => 'slug-estable',
            'sort_order' => 3,
        ]);
    }

    public function test_brand_slug_must_be_unique(): void
    {
        MeliBrandGroup::factory()->create(['slug' => 'repetida']);

        $this->from(route('meli-price-manager.brands.index'))
            ->post(route('meli-price-manager.brands.store'), [
                'name' => 'Otra marca',
                'slug' => 'repetida',
                'active' => true,
                'sort_order' => 0,
            ])->assertRedirect(route('meli-price-manager.brands.index'))
            ->assertSessionHasErrors('slug');
    }

    public function test_brand_status_changes_without_losing_aliases_or_assignments(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create(['active' => true]);
        $alias = $this->alias($brand, 'ALFAPARF');
        $item = $this->item($account, ['brand_group_id' => $brand->id, 'classification_status' => 'categorized']);

        $this->patch(route('meli-price-manager.brands.status', $brand), ['active' => false])
            ->assertRedirect()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, '1 publicaciones'));

        $this->assertFalse($brand->fresh()->active);
        $this->assertTrue($alias->fresh()->exists);
        $this->assertSame($brand->id, $item->fresh()->brand_group_id);
    }

    public function test_alias_is_created_with_backend_normalization_and_explicit_fields(): void
    {
        $brand = MeliBrandGroup::factory()->create();

        $this->post(route('meli-price-manager.aliases.store', $brand), [
            'alias' => '  Álfaparf-Milano  ',
            'normalized_alias' => 'VALOR INYECTADO',
            'match_type' => 'starts_with',
            'priority' => 80,
            'active' => true,
            'brand_group_id' => 999999,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('meli_brand_aliases', [
            'brand_group_id' => $brand->id,
            'alias' => 'Álfaparf-Milano',
            'normalized_alias' => 'ALFAPARF MILANO',
            'match_type' => 'starts_with',
            'priority' => 80,
        ]);
    }

    public function test_equivalent_alias_is_rejected_inside_the_same_brand_before_sql_error(): void
    {
        $brand = MeliBrandGroup::factory()->create();
        $this->alias($brand, 'ALFAPARF MILANO');

        $this->post(route('meli-price-manager.aliases.store', $brand), [
            'alias' => 'álfaparf-milano',
            'match_type' => 'exact',
            'priority' => 0,
            'active' => true,
        ])->assertSessionHasErrors([
            'normalized_alias' => 'Ya existe un alias equivalente en esta marca.',
        ]);

        $this->assertSame(1, $brand->aliases()->count());
    }

    public function test_equivalent_alias_in_another_brand_is_allowed_with_conflict_warning(): void
    {
        $first = MeliBrandGroup::factory()->create(['name' => 'DAVINES']);
        $second = MeliBrandGroup::factory()->create(['name' => 'ALFAPARF']);
        $this->alias($first, 'OI');

        $this->post(route('meli-price-manager.aliases.store', $second), [
            'alias' => 'oi',
            'match_type' => 'exact',
            'priority' => 0,
            'active' => true,
        ])->assertRedirect()->assertSessionHas('success', fn (string $message) => str_contains($message, 'DAVINES') && str_contains($message, 'ambiguas'));

        $this->assertSame(2, MeliBrandAlias::query()->where('normalized_alias', 'OI')->count());
    }

    public function test_editing_alias_recalculates_normalized_alias(): void
    {
        $brand = MeliBrandGroup::factory()->create();
        $alias = $this->alias($brand, 'ANTERIOR');

        $this->put(route('meli-price-manager.aliases.update', $alias), [
            'alias' => '  Semí-Di Lino ',
            'normalized_alias' => 'NO CONFIAR',
            'match_type' => 'contains',
            'priority' => 90,
            'active' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('meli_brand_aliases', [
            'id' => $alias->id,
            'alias' => 'Semí-Di Lino',
            'normalized_alias' => 'SEMI DI LINO',
            'match_type' => 'contains',
            'priority' => 90,
        ]);
    }

    public function test_alias_can_be_activated_and_deactivated_without_deleting_it(): void
    {
        $alias = $this->alias(MeliBrandGroup::factory()->create(), 'DAVINES');

        $this->patch(route('meli-price-manager.aliases.status', $alias), ['active' => false])
            ->assertRedirect()->assertSessionHas('success', 'Alias desactivado.');

        $this->assertFalse($alias->fresh()->active);
        $this->assertDatabaseHas('meli_brand_aliases', ['id' => $alias->id]);
    }

    public function test_alias_match_type_must_be_supported(): void
    {
        $brand = MeliBrandGroup::factory()->create();

        $this->post(route('meli-price-manager.aliases.store', $brand), [
            'alias' => 'Marca',
            'match_type' => 'regex',
            'priority' => 0,
            'active' => true,
        ])->assertSessionHasErrors('match_type');

        $this->assertDatabaseCount('meli_brand_aliases', 0);
    }

    public function test_explicit_title_contains_match_type_can_be_created(): void
    {
        $brand = MeliBrandGroup::factory()->create();

        $this->post(route('meli-price-manager.aliases.store', $brand), [
            'alias' => 'Semi Di Lino',
            'match_type' => 'title_contains',
            'priority' => 25,
            'active' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('meli_brand_aliases', [
            'brand_group_id' => $brand->id,
            'normalized_alias' => 'SEMI DI LINO',
            'match_type' => 'title_contains',
        ]);
    }

    public function test_alias_priority_must_be_an_integer_between_zero_and_one_thousand(): void
    {
        $brand = MeliBrandGroup::factory()->create();

        foreach ([-1, 1001, 'alta'] as $priority) {
            $this->post(route('meli-price-manager.aliases.store', $brand), [
                'alias' => 'Marca '.$priority,
                'match_type' => 'exact',
                'priority' => $priority,
                'active' => true,
            ])->assertSessionHasErrors('priority');
        }

        $this->assertDatabaseCount('meli_brand_aliases', 0);
    }

    public function test_unauthenticated_user_cannot_access_brand_management_actions(): void
    {
        auth()->logout();
        $brand = MeliBrandGroup::factory()->create();
        $alias = $this->alias($brand, 'PRIVADA');

        $this->get(route('meli-price-manager.brands.index'))->assertRedirect(route('login'));
        $this->post(route('meli-price-manager.brands.store'), [])->assertRedirect(route('login'));
        $this->patch(route('meli-price-manager.brands.status', $brand), [])->assertRedirect(route('login'));
        $this->put(route('meli-price-manager.aliases.update', $alias), [])->assertRedirect(route('login'));
        $this->post(route('meli-price-manager.reclassification.preview'), [])->assertRedirect(route('login'));
    }

    public function test_index_orders_brands_and_scopes_publication_counts_to_selected_account(): void
    {
        $selected = $this->account(['nickname' => 'Principal', 'is_default' => true]);
        $other = $this->account(['nickname' => 'Secundaria']);
        $brandB = MeliBrandGroup::factory()->create(['name' => 'BETA', 'sort_order' => 10]);
        $brandA = MeliBrandGroup::factory()->create(['name' => 'ALFA', 'sort_order' => 10]);
        $this->alias($brandA, 'ALFA');
        $this->item($selected, ['brand_group_id' => $brandA->id, 'classification_status' => 'categorized']);
        $this->item($other, ['brand_group_id' => $brandA->id, 'classification_status' => 'categorized']);
        $this->item($selected, ['suggested_brand_group_id' => $brandB->id, 'classification_status' => 'suggested']);

        $this->get(route('meli-price-manager.brands.index', ['account' => $selected->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MeliPriceManager/Brands')
                ->where('selectedAccountId', $selected->id)
                ->where('brands.0.name', 'ALFA')
                ->where('brands.0.aliases_count', 1)
                ->where('brands.0.categorized_items_count', 1)
                ->where('brands.1.name', 'BETA')
                ->where('brands.1.suggested_items_count', 1));
    }

    public function test_user_cannot_select_or_reclassify_another_users_account(): void
    {
        $foreignAccount = MeliAccount::factory()->create();

        $this->get(route('meli-price-manager.brands.index', ['account' => $foreignAccount->id]))->assertNotFound();
        $this->post(route('meli-price-manager.reclassification.preview'), [
            'meli_account_id' => $foreignAccount->id,
            'reclassify_all' => true,
        ])->assertSessionHasErrors('meli_account_id');
    }

    public function test_reclassification_preview_is_a_dry_run_and_writes_summary_to_session(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $this->alias($brand, 'PREVIEW BRAND');
        $item = $this->item($account, ['meli_brand' => 'Preview Brand', 'normalized_brand' => 'PREVIEW BRAND']);

        $this->post(route('meli-price-manager.brands.reclassification.preview', $brand), [
            'meli_account_id' => $account->id,
            'reclassify_all' => true,
        ])->assertRedirect()
            ->assertSessionHas('meli_price_manager_reclassification_preview', fn (array $preview) => $preview['brand_group_id'] === $brand->id
                && $preview['summary']['dry_run'] === true
                && $preview['summary']['categorized'] === 1);

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
        $this->assertNull($item->brand_group_id);
    }

    public function test_real_reclassification_uses_the_existing_classifier(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $alias = $this->alias($brand, 'REAL BRAND');
        $item = $this->item($account, ['meli_brand' => 'Real Brand', 'normalized_brand' => 'REAL BRAND']);

        $this->post(route('meli-price-manager.reclassification.apply'), [
            'meli_account_id' => $account->id,
            'reclassify_all' => true,
            'confirm' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('categorized', $item->classification_status);
        $this->assertSame($brand->id, $item->brand_group_id);
        $this->assertSame($alias->id, $item->matched_brand_alias_id);
        $this->assertSame('brand_exact', $item->classification_source);
    }

    public function test_real_reclassification_preserves_manual_and_ignored_items(): void
    {
        $account = $this->account();
        $manualBrand = MeliBrandGroup::factory()->create();
        $automaticBrand = MeliBrandGroup::factory()->create();
        $this->alias($automaticBrand, 'PROTECTED BRAND');
        $manual = $this->item($account, [
            'meli_item_id' => 'MLM-MANUAL',
            'meli_brand' => 'Protected Brand',
            'normalized_brand' => 'PROTECTED BRAND',
            'brand_group_id' => $manualBrand->id,
            'classification_status' => 'categorized',
            'classification_source' => 'manual_assignment',
        ]);
        $ignored = $this->item($account, [
            'meli_item_id' => 'MLM-IGNORED',
            'meli_brand' => 'Protected Brand',
            'normalized_brand' => 'PROTECTED BRAND',
            'classification_status' => 'ignored',
        ]);

        $this->post(route('meli-price-manager.reclassification.apply'), [
            'meli_account_id' => $account->id,
            'reclassify_all' => true,
            'confirm' => true,
        ])->assertRedirect();

        $this->assertSame($manualBrand->id, $manual->fresh()->brand_group_id);
        $this->assertSame('manual_assignment', $manual->classification_source);
        $this->assertSame('ignored', $ignored->fresh()->classification_status);
        $this->assertNull($ignored->brand_group_id);
    }

    public function test_saving_alias_does_not_automatically_reclassify_items(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $item = $this->item($account, ['meli_brand' => 'New Alias', 'normalized_brand' => 'NEW ALIAS']);

        $this->post(route('meli-price-manager.aliases.store', $brand), [
            'alias' => 'New Alias',
            'match_type' => 'exact',
            'priority' => 100,
            'active' => true,
        ])->assertRedirect();

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
        $this->assertNull($item->brand_group_id);
    }

    public function test_alias_delete_requires_confirmation_and_keeps_publication_history(): void
    {
        $account = $this->account();
        $brand = MeliBrandGroup::factory()->create();
        $alias = $this->alias($brand, 'HISTORICAL');
        $item = $this->item($account, [
            'brand_group_id' => $brand->id,
            'matched_brand_alias_id' => $alias->id,
            'classification_status' => 'categorized',
            'classification_source' => 'brand_exact',
            'classification_metadata' => ['matched_alias' => ['alias_id' => $alias->id]],
        ]);

        $this->delete(route('meli-price-manager.aliases.destroy', $alias))->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('meli_brand_aliases', ['id' => $alias->id]);

        $this->delete(route('meli-price-manager.aliases.destroy', $alias), ['confirm' => true])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('meli_brand_aliases', ['id' => $alias->id]);
        $this->assertDatabaseHas('meli_price_manager_items', ['id' => $item->id]);
        $this->assertNull($item->fresh()->matched_brand_alias_id);
        $this->assertSame($alias->id, $item->classification_metadata['matched_alias']['alias_id']);
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->for($this->user)->create($overrides);
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

    /** @param array<string, mixed> $overrides */
    private function item(MeliAccount $account, array $overrides = []): MeliPriceManagerItem
    {
        return MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create([
            'meli_item_id' => 'MLM'.fake()->unique()->numberBetween(100000000, 999999999),
            ...$overrides,
        ]);
    }
}
