<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliBrandClassificationResult;
use App\Services\MercadoLibre\PriceManager\MeliBrandClassificationService;
use App\Services\MercadoLibre\PriceManager\MeliBrandNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeliBrandClassificationTest extends TestCase
{
    private object $foundationMigration;

    private object $classificationMigration;

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

        $this->foundationMigration = require database_path('migrations/2026_08_26_000001_create_meli_price_manager_tables.php');
        $this->foundationMigration->up();
        $this->classificationMigration = require database_path('migrations/2026_08_26_000002_add_brand_classification_audit_to_meli_price_manager_items.php');
        $this->classificationMigration->up();
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

    public function test_exact_brand_match_is_categorized_with_full_confidence(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $alias = $this->alias($group, 'ALFAPARF MILANO', 'exact');
        $item = $this->item($account, brand: 'Alfaparf Milano');

        $result = $this->service()->classifyItem($item);
        $item->refresh();

        $this->assertInstanceOf(MeliBrandClassificationResult::class, $result);
        $this->assertSame('categorized', $result->status);
        $this->assertSame($group->id, $result->brandGroupId);
        $this->assertSame('brand_exact', $item->classification_source);
        $this->assertSame('1.0000', $item->classification_confidence);
        $this->assertSame($alias->id, $item->matched_brand_alias_id);
    }

    public function test_accents_hyphens_case_and_repeated_spaces_normalize_to_the_same_brand(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'ALFAPARF MILANO', 'exact');

        foreach (['Álfaparf Milano', 'ALFAPARF-MILANO', 'alfaparf   milano'] as $index => $brand) {
            $item = $this->item($account, brand: $brand, overrides: ['meli_item_id' => 'MLM-NORM-'.$index]);
            $this->service()->classifyItem($item);
            $this->assertSame($group->id, $item->fresh()->brand_group_id);
        }
    }

    public function test_starts_with_alias_matches_brand_prefix(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'ALFAPARF', 'starts_with');
        $item = $this->item($account, brand: 'ALFAPARF MILANO PROFESSIONAL');

        $this->service()->classifyItem($item);
        $item->refresh();

        $this->assertSame($group->id, $item->brand_group_id);
        $this->assertSame('brand_starts_with', $item->classification_source);
        $this->assertSame('0.9500', $item->classification_confidence);
    }

    public function test_contains_alias_matches_a_complete_phrase_in_brand(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'SEMI DI LINO', 'contains');
        $item = $this->item($account, brand: 'ALFAPARF SEMI DI LINO PROFESSIONAL');

        $this->service()->classifyItem($item);
        $item->refresh();

        $this->assertSame($group->id, $item->brand_group_id);
        $this->assertSame('brand_contains', $item->classification_source);
        $this->assertSame('0.9000', $item->classification_confidence);
    }

    public function test_alias_in_title_is_used_when_brand_has_no_match(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'SEMI DI LINO', 'exact');
        $item = $this->item($account, title: 'Semi Di Lino Moisture Shampoo 250ml');

        $this->service()->classifyItem($item);
        $item->refresh();

        $this->assertSame($group->id, $item->brand_group_id);
        $this->assertSame('title_alias', $item->classification_source);
        $this->assertSame('0.8500', $item->classification_confidence);
    }

    public function test_short_alias_requires_a_complete_token(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'OI', 'contains');
        $falsePositive = $this->item($account, title: 'Moisture Shampoo', overrides: ['meli_item_id' => 'MLM-SHORT-1']);
        $realToken = $this->item($account, title: 'Shampoo OI 250 ml', overrides: ['meli_item_id' => 'MLM-SHORT-2']);

        $this->service()->classifyItem($falsePositive);
        $this->service()->classifyItem($realToken);

        $this->assertSame('uncategorized', $falsePositive->fresh()->classification_status);
        $this->assertNull($falsePositive->brand_group_id);
        $this->assertSame($group->id, $realToken->fresh()->brand_group_id);
    }

    public function test_alias_priority_then_specificity_and_length_choose_the_winner(): void
    {
        $account = $this->account();
        $generalGroup = MeliBrandGroup::factory()->create();
        $specificGroup = MeliBrandGroup::factory()->create();
        $general = $this->alias($generalGroup, 'ALFAPARF', 'starts_with', priority: 20);
        $this->alias($specificGroup, 'ALFAPARF MILANO', 'starts_with', priority: 10);
        $priorityWinner = $this->item($account, brand: 'ALFAPARF MILANO PROFESSIONAL', overrides: ['meli_item_id' => 'MLM-PRIORITY']);

        $this->service()->classifyItem($priorityWinner);
        $this->assertSame($generalGroup->id, $priorityWinner->fresh()->brand_group_id);

        $general->update(['priority' => 10]);
        $lengthWinner = $this->item($account, brand: 'ALFAPARF MILANO PROFESSIONAL', overrides: ['meli_item_id' => 'MLM-LENGTH']);
        $this->service()->classifyItem($lengthWinner);
        $this->assertSame($specificGroup->id, $lengthWinner->fresh()->brand_group_id);
    }

    public function test_equal_candidates_from_different_groups_create_an_auditable_suggestion(): void
    {
        $account = $this->account();
        $firstGroup = MeliBrandGroup::factory()->create();
        $secondGroup = MeliBrandGroup::factory()->create();
        $firstAlias = $this->alias($firstGroup, 'DUPLICATE BRAND', 'exact', priority: 10);
        $this->alias($secondGroup, 'DUPLICATE BRAND', 'exact', priority: 10);
        $item = $this->item($account, brand: 'Duplicate Brand');

        $result = $this->service()->classifyItem($item);
        $item->refresh();

        $this->assertSame('suggested', $item->classification_status);
        $this->assertNull($item->brand_group_id);
        $this->assertSame($firstGroup->id, $item->suggested_brand_group_id);
        $this->assertSame($firstAlias->id, $item->matched_brand_alias_id);
        $this->assertSame('ambiguous_brand_exact', $item->classification_source);
        $this->assertSame('multiple_brand_groups_tied', $item->classification_metadata['reason']);
        $this->assertCount(2, $result->candidates);
    }

    public function test_manual_classification_is_never_overwritten(): void
    {
        $account = $this->account();
        $manualGroup = MeliBrandGroup::factory()->create();
        $automaticGroup = MeliBrandGroup::factory()->create();
        $this->alias($automaticGroup, 'AUTOMATIC BRAND', 'exact');
        $item = $this->item($account, brand: 'Automatic Brand', overrides: [
            'brand_group_id' => $manualGroup->id,
            'classification_status' => 'categorized',
            'classification_source' => 'manual',
            'classification_confidence' => '1.0000',
        ]);

        $result = $this->service()->classifyItem($item);

        $this->assertSame('manual', $result->skippedReason);
        $this->assertSame($manualGroup->id, $item->fresh()->brand_group_id);
        $this->assertSame('manual', $item->classification_source);
    }

    public function test_ignored_item_is_never_reclassified(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'IGNORED BRAND', 'exact');
        $item = $this->item($account, brand: 'Ignored Brand', overrides: ['classification_status' => 'ignored']);

        $result = $this->service()->classifyItem($item);

        $this->assertSame('ignored', $result->skippedReason);
        $this->assertSame('ignored', $item->fresh()->classification_status);
        $this->assertNull($item->brand_group_id);
    }

    public function test_inactive_alias_is_not_used(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'INACTIVE ALIAS', 'exact', active: false);
        $item = $this->item($account, brand: 'Inactive Alias');

        $this->service()->classifyItem($item);

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
    }

    public function test_alias_from_inactive_brand_group_is_not_used(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create(['active' => false]);
        $this->alias($group, 'INACTIVE GROUP', 'exact');
        $item = $this->item($account, brand: 'Inactive Group');

        $this->service()->classifyItem($item);

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
    }

    public function test_manual_match_type_is_not_used_automatically(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'MANUAL ALIAS', 'manual');
        $item = $this->item($account, brand: 'Manual Alias');

        $this->service()->classifyItem($item);

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
    }

    public function test_item_without_any_match_remains_uncategorized_and_clears_automatic_audit_fields(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $alias = $this->alias($group, 'OLD BRAND', 'exact');
        $item = $this->item($account, brand: 'Unknown Brand', overrides: [
            'suggested_brand_group_id' => $group->id,
            'matched_brand_alias_id' => $alias->id,
            'classification_status' => 'suggested',
            'classification_source' => 'ambiguous_brand_exact',
            'classification_confidence' => '1.0000',
            'classification_metadata' => ['old' => true],
        ]);

        $this->service()->classifyItem($item);
        $item->refresh();

        $this->assertSame('uncategorized', $item->classification_status);
        $this->assertNull($item->brand_group_id);
        $this->assertNull($item->suggested_brand_group_id);
        $this->assertNull($item->matched_brand_alias_id);
        $this->assertNull($item->classification_source);
        $this->assertNull($item->classification_confidence);
        $this->assertNull($item->classification_metadata);
    }

    public function test_account_classification_returns_a_complete_summary(): void
    {
        $account = $this->account();
        $exactGroup = MeliBrandGroup::factory()->create();
        $conflictGroup = MeliBrandGroup::factory()->create();
        $this->alias($exactGroup, 'EXACT BRAND', 'exact');
        $this->alias($exactGroup, 'CONFLICT BRAND', 'exact');
        $this->alias($conflictGroup, 'CONFLICT BRAND', 'exact');
        $this->item($account, brand: 'Exact Brand', overrides: ['meli_item_id' => 'MLM-SUM-1']);
        $this->item($account, brand: 'Conflict Brand', overrides: ['meli_item_id' => 'MLM-SUM-2']);
        $this->item($account, brand: 'Unknown', overrides: ['meli_item_id' => 'MLM-SUM-3']);
        $this->item($account, overrides: ['meli_item_id' => 'MLM-SUM-4', 'classification_status' => 'ignored']);
        $this->item($account, overrides: [
            'meli_item_id' => 'MLM-SUM-5',
            'classification_status' => 'categorized',
            'classification_source' => 'manual_assignment',
            'brand_group_id' => $exactGroup->id,
        ]);

        $summary = $this->service()->classifyAccount($account);

        $this->assertSame(5, $summary['processed']);
        $this->assertSame(1, $summary['categorized']);
        $this->assertSame(1, $summary['suggested']);
        $this->assertSame(1, $summary['uncategorized']);
        $this->assertSame(1, $summary['ignored']);
        $this->assertSame(1, $summary['skipped_manual']);
    }

    public function test_dry_run_calculates_changes_without_modifying_database(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'DRY BRAND', 'exact');
        $item = $this->item($account, brand: 'Dry Brand');

        $summary = $this->service()->classifyAccount($account, dryRun: true);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(1, $summary['categorized']);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame('uncategorized', $item->fresh()->classification_status);
        $this->assertNull($item->brand_group_id);
    }

    public function test_automatic_classification_can_change_but_manual_classification_cannot(): void
    {
        $account = $this->account();
        $oldGroup = MeliBrandGroup::factory()->create();
        $newGroup = MeliBrandGroup::factory()->create();
        $oldAlias = $this->alias($oldGroup, 'MOVING BRAND', 'exact');
        $automatic = $this->item($account, brand: 'Moving Brand', overrides: ['meli_item_id' => 'MLM-RECLASS-AUTO']);
        $manual = $this->item($account, brand: 'Moving Brand', overrides: [
            'meli_item_id' => 'MLM-RECLASS-MANUAL',
            'brand_group_id' => $oldGroup->id,
            'classification_status' => 'categorized',
            'classification_source' => 'manual',
        ]);
        $this->service()->classifyItem($automatic);
        $oldAlias->update(['active' => false]);
        $this->alias($newGroup, 'MOVING BRAND', 'exact');

        $summary = $this->service()->classifyAccount($account, reclassifyAll: true);

        $this->assertSame($newGroup->id, $automatic->fresh()->brand_group_id);
        $this->assertSame($oldGroup->id, $manual->fresh()->brand_group_id);
        $this->assertSame('manual', $manual->classification_source);
        $this->assertSame(1, $summary['skipped_manual']);
    }

    public function test_command_dry_run_reports_results_without_writes(): void
    {
        $account = $this->account();
        $group = MeliBrandGroup::factory()->create();
        $this->alias($group, 'COMMAND BRAND', 'exact');
        $item = $this->item($account, brand: 'Command Brand');

        $this->artisan('meli:price-manager-classify', [
            '--account' => $account->id,
            '--dry-run' => true,
        ])->expectsOutput('DRY RUN: no se guardará ningún cambio.')
            ->assertSuccessful();

        $this->assertSame('uncategorized', $item->fresh()->classification_status);
        $this->assertNull($item->brand_group_id);
    }

    private function service(): MeliBrandClassificationService
    {
        return app(MeliBrandClassificationService::class);
    }

    private function account(): MeliAccount
    {
        return MeliAccount::factory()->create();
    }

    private function alias(
        MeliBrandGroup $group,
        string $alias,
        string $matchType,
        int $priority = 0,
        bool $active = true,
    ): MeliBrandAlias {
        return MeliBrandAlias::factory()->for($group, 'brandGroup')->create([
            'alias' => $alias,
            'normalized_alias' => app(MeliBrandNormalizer::class)->normalize($alias),
            'match_type' => $matchType,
            'priority' => $priority,
            'active' => $active,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function item(
        MeliAccount $account,
        ?string $brand = null,
        string $title = 'Generic Product',
        array $overrides = [],
    ): MeliPriceManagerItem {
        return MeliPriceManagerItem::factory()->for($account, 'meliAccount')->create([
            'meli_item_id' => 'MLM'.fake()->unique()->numberBetween(100000000, 999999999),
            'title' => $title,
            'meli_brand' => $brand,
            'normalized_brand' => $brand,
            'brand_group_id' => null,
            'classification_status' => 'uncategorized',
            'classification_source' => null,
            'classification_confidence' => null,
            ...$overrides,
        ]);
    }
}
