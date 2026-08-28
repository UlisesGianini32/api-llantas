<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceChange;
use App\Models\MeliPriceChangeBatch;
use App\Models\MeliPriceManagerItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliPriceManagerFoundationTest extends TestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->migration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_brand_group_slug_is_unique_and_relations_include_aliases_items_and_batches(): void
    {
        $group = MeliBrandGroup::factory()->create(['name' => 'Marca Principal', 'slug' => 'marca-principal', 'sort_order' => 7]);
        MeliBrandAlias::factory()->count(2)->for($group, 'brandGroup')->create();
        $item = MeliPriceManagerItem::factory()->for($group, 'brandGroup')->create();
        $batch = MeliPriceChangeBatch::factory()->for($group, 'brandGroup')->create();

        $this->assertTrue($group->active);
        $this->assertSame(7, $group->sort_order);
        $this->assertCount(2, $group->aliases);
        $this->assertTrue($group->items->contains($item));
        $this->assertTrue($group->priceChangeBatches->contains($batch));

        $this->expectException(QueryException::class);
        MeliBrandGroup::factory()->create(['slug' => 'marca-principal']);
    }

    public function test_alias_belongs_to_group_persists_normalized_value_and_enforces_match_types(): void
    {
        $group = MeliBrandGroup::factory()->create();
        $alias = MeliBrandAlias::factory()->for($group, 'brandGroup')->create([
            'alias' => 'Línea Ácido Hialurónico',
            'normalized_alias' => 'linea acido hialuronico',
            'match_type' => 'starts_with',
            'priority' => 20,
        ]);

        $this->assertTrue($alias->brandGroup->is($group));
        $this->assertSame('linea acido hialuronico', $alias->normalized_alias);
        $this->assertSame(20, $alias->priority);
        $this->assertContains($alias->match_type, MeliBrandAlias::MATCH_TYPES);

        $this->expectException(QueryException::class);
        MeliBrandAlias::factory()->for($group, 'brandGroup')->create(['match_type' => 'regex']);
    }

    public function test_item_reuses_meli_account_casts_json_and_decimals_and_is_unique_per_account(): void
    {
        $account = MeliAccount::factory()->create();
        $otherAccount = MeliAccount::factory()->create();
        $group = MeliBrandGroup::factory()->create();
        $item = MeliPriceManagerItem::factory()->for($account, 'meliAccount')->for($group, 'brandGroup')->create([
            'meli_item_id' => 'MLM123456789',
            'current_price' => '12345.67',
            'original_price' => '12000.10',
            'classification_status' => 'categorized',
            'classification_source' => 'manual',
            'classification_confidence' => '0.9500',
            'raw_attributes' => [['id' => 'BRAND', 'value_name' => 'Marca real']],
            'raw_item' => ['id' => 'MLM123456789', 'status' => 'active'],
            'last_synced_at' => '2026-08-26 12:00:00',
        ]);

        $this->assertTrue($item->meliAccount->is($account));
        $this->assertTrue($item->brandGroup->is($group));
        $this->assertSame('12345.67', $item->current_price);
        $this->assertSame('12000.10', $item->original_price);
        $this->assertSame('0.9500', $item->classification_confidence);
        $this->assertSame('Marca real', $item->raw_attributes[0]['value_name']);
        $this->assertSame('active', $item->raw_item['status']);
        $this->assertNotNull($item->last_synced_at);
        $this->assertContains($item->classification_status, MeliPriceManagerItem::CLASSIFICATION_STATUSES);

        MeliPriceManagerItem::factory()->for($otherAccount, 'meliAccount')->create(['meli_item_id' => 'MLM123456789']);
        $this->assertSame(2, MeliPriceManagerItem::query()->where('meli_item_id', 'MLM123456789')->count());

        try {
            MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create(['meli_item_id' => 'MLM123456789']);
            $this->fail('La misma cuenta no debe admitir el mismo meli_item_id dos veces.');
        } catch (QueryException) {
            $this->assertSame(1, $account->priceManagerItems()->where('meli_item_id', 'MLM123456789')->count());
        }
    }

    public function test_batch_and_price_change_relations_casts_charges_and_statuses(): void
    {
        $account = MeliAccount::factory()->create();
        $group = MeliBrandGroup::factory()->create();
        $creator = User::factory()->create();
        $item = MeliPriceManagerItem::factory()->for($account, 'meliAccount')->for($group, 'brandGroup')->create([
            'meli_item_id' => 'MLM987654321',
        ]);
        $batch = MeliPriceChangeBatch::factory()->for($account, 'meliAccount')->for($group, 'brandGroup')
            ->for($creator, 'creator')->create(['type' => 'percentage', 'status' => 'preview', 'total_items' => 1]);
        $change = MeliPriceChange::factory()->for($batch, 'batch')->for($item, 'item')->for($creator, 'changedBy')->create([
            'meli_item_id' => $item->meli_item_id,
            'old_price' => '1000.00',
            'new_price' => '1150.00',
            'selling_fee' => '172.50',
            'shipping_cost' => '99.99',
            'tax_withholding' => '18.25',
            'other_charges' => '7.50',
            'estimated_net' => '851.76',
            'status' => 'success',
            'changed_at' => now(),
        ]);

        $this->assertTrue($batch->meliAccount->is($account));
        $this->assertTrue($batch->brandGroup->is($group));
        $this->assertTrue($batch->creator->is($creator));
        $this->assertTrue($batch->changes->contains($change));
        $this->assertContains($batch->type, MeliPriceChangeBatch::TYPES);
        $this->assertContains($batch->status, MeliPriceChangeBatch::STATUSES);
        $this->assertTrue($change->batch->is($batch));
        $this->assertTrue($change->item->is($item));
        $this->assertTrue($change->changedBy->is($creator));
        $this->assertSame('1000.00', $change->old_price);
        $this->assertSame('1150.00', $change->new_price);
        $this->assertSame('172.50', $change->selling_fee);
        $this->assertSame('99.99', $change->shipping_cost);
        $this->assertSame('18.25', $change->tax_withholding);
        $this->assertSame('7.50', $change->other_charges);
        $this->assertSame('851.76', $change->estimated_net);
        $this->assertContains($change->status, MeliPriceChange::STATUSES);
        $this->assertNotNull($change->changed_at);
    }

    public function test_factories_create_complete_independent_records_and_important_indexes_exist(): void
    {
        $alias = MeliBrandAlias::factory()->create();
        $item = MeliPriceManagerItem::factory()->create();
        $batch = MeliPriceChangeBatch::factory()->create();
        $change = MeliPriceChange::factory()->create();

        $this->assertTrue($alias->exists && $item->exists && $batch->exists && $change->exists);
        $this->assertSame($change->item->meli_item_id, $change->meli_item_id);

        $itemIndexes = collect(Schema::getIndexes('meli_price_manager_items'))->pluck('name');
        $aliasIndexes = collect(Schema::getIndexes('meli_brand_aliases'))->pluck('name');
        $this->assertContains('meli_pm_items_account_item_uq', $itemIndexes);
        $this->assertContains('meli_pm_items_item_id_idx', $itemIndexes);
        $this->assertContains('meli_brand_aliases_group_normalized_uq', $aliasIndexes);
        $this->assertContains('meli_brand_aliases_normalized_idx', $aliasIndexes);
    }
}
