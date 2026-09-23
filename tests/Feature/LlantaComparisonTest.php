<?php

namespace Tests\Feature;

use App\Models\Llanta;
use App\Models\LlantaComparisonDecision;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LlantaComparisonTest extends TestCase
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
            $table->timestamp('two_factor_confirmed_at')->nullable();
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
        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('marca');
            $table->string('medida');
            $table->text('descripcion');
            $table->decimal('costo', 10, 2);
            $table->decimal('precio_ML', 10, 2)->nullable();
            $table->string('title_familyname')->nullable();
            $table->string('MLM')->nullable();
            $table->integer('stock')->default(0);
            $table->timestamp('last_import_at')->nullable();
            $table->string('price_mode')->default('auto');
            $table->timestamp('price_locked_at')->nullable();
            $table->timestamps();
        });

        $migration = require database_path('migrations/2026_09_22_000001_create_llanta_comparison_decisions_table.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('llanta_comparison_decisions');
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_command_is_idempotent_and_preserves_inventory_and_decisions(): void
    {
        $first = $this->tire('SKU-ONE', 'MICHELIN PRIMACY 4 205/55R16 91V', 4);
        $second = $this->tire('SKU-TWO', 'Michelin Primacy4 205 55 R16 91 V', 8);
        $before = [$first->fresh()->getAttributes(), $second->fresh()->getAttributes()];

        $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();
        $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();

        $this->assertSame(1, LlantaComparisonDecision::query()->count());
        $decision = LlantaComparisonDecision::query()->sole();
        $this->assertSame(min($first->id, $second->id), (int) $decision->llanta_a_id);
        $this->assertSame(max($first->id, $second->id), (int) $decision->llanta_b_id);
        $this->assertSame('pending', $decision->status);
        $this->assertSame($before[0], $first->fresh()->getAttributes());
        $this->assertSame($before[1], $second->fresh()->getAttributes());

        $this->assertSame(1, LlantaComparisonDecision::query()
            ->where('llanta_a_id', min($first->id, $second->id))
            ->where('llanta_b_id', max($first->id, $second->id))
            ->count());
    }

    public function test_persisted_score_matches_the_public_comparison_service(): void
    {
        $first = $this->tire(
            'SUMAXX-A',
            '265/65R18 SUMAXX ALL-TERRAIN AT LETRA BLANCA',
            4,
            'SUMAXX',
            '265/65R18'
        );
        $second = $this->tire(
            'SUMAXX-B',
            '265/65R18 LT SUMAXX ALL-TERRAIN A/T LETRA BLANCA 10C',
            8,
            'SUMAXX',
            '265/65R18'
        );

        $comparison = app(\App\Services\Llantas\LlantaComparisonService::class)
            ->compare($first, $second);

        $this->artisan('llantas:comparar')->assertSuccessful();

        $decision = LlantaComparisonDecision::query()->sole();
        $this->assertSame((float) $comparison['score'], (float) $decision->score);
    }

    public function test_decision_status_is_persisted_and_not_reset_by_regeneration(): void
    {
        $first = $this->tire('SKU-A', 'MICHELIN PRIMACY 4 205/55R16', 1);
        $second = $this->tire('SKU-B', 'MICHELIN PRIMACY4 205 55 R16', 2);
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();
        $decision = LlantaComparisonDecision::query()->sole();
        $this->post(route('llantas.comparador.decision', $decision), ['status' => 'same'])
            ->assertRedirect();
        $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();

        $this->assertSame('same', $decision->fresh()->status);
        $this->assertNotNull($decision->fresh()->decided_at);
        $this->assertSame($admin->id, $decision->fresh()->decided_by);
    }

    public function test_same_different_and_ignored_decisions_are_all_preserved(): void
    {
        $first = $this->tire('SKU-STATUS-A', 'MICHELIN PRIMACY 4 205/55R16', 1);
        $second = $this->tire('SKU-STATUS-B', 'MICHELIN PRIMACY4 205 55 R16', 2);
        $this->actingAs(User::factory()->create());

        $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();
        $decision = LlantaComparisonDecision::query()->sole();

        foreach (['same', 'different', 'ignored'] as $status) {
            $this->post(route('llantas.comparador.decision', $decision), ['status' => $status])
                ->assertRedirect();
            $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();

            $this->assertSame($status, $decision->fresh()->status);
            $this->assertNotNull($decision->fresh()->decided_at);
        }
    }

    public function test_admin_can_list_comparisons_with_scores_reasons_and_both_tires(): void
    {
        $first = $this->tire('SKU-LIST-A', 'MICHELIN PRIMACY 4 205/55R16', 1);
        $second = $this->tire('SKU-LIST-B', 'MICHELIN PRIMACY4 205 55 R16', 2);
        $this->actingAs(User::factory()->create());
        $this->artisan('llantas:comparar', ['--min' => 86])->assertSuccessful();

        $this->get(route('llantas.comparador.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('stats.pending', 1)
                ->where('comparisons.data.0.a.sku', $first->sku)
                ->where('comparisons.data.0.b.sku', $second->sku)
                ->has('comparisons.data.0.reasons')
                ->has('comparisons.data.0.differences'));
    }

    public function test_operations_cannot_access_the_inventory_comparator(): void
    {
        $operations = User::factory()->create()->forceFill(['role' => User::ROLE_OPERATIONS]);
        $this->actingAs($operations);

        $this->get(route('llantas.comparador.index'))->assertForbidden();
    }

    public function test_orphaned_comparisons_are_excluded_without_errors(): void
    {
        LlantaComparisonDecision::query()->create([
            'llanta_a_id' => 9991,
            'llanta_b_id' => 9992,
            'score' => 90,
            'reasons' => ['histórico'],
            'differences' => [],
            'status' => 'pending',
            'last_detected_at' => now(),
        ]);

        $this->actingAs(User::factory()->create());
        $this->get(route('llantas.comparador.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page->where('stats.pending', 0));
        $this->artisan('llantas:comparar')->assertSuccessful();

        $this->assertDatabaseHas('llanta_comparison_decisions', [
            'llanta_a_id' => 9991,
            'llanta_b_id' => 9992,
            'status' => 'pending',
        ]);
    }

    private function tire(
        string $sku,
        string $description,
        int $stock,
        string $brand = 'MICHELIN',
        string $size = '205/55R16'
    ): Llanta {
        return Llanta::query()->create([
            'sku' => $sku,
            'marca' => $brand,
            'medida' => $size,
            'descripcion' => $description,
            'costo' => 100,
            'precio_ML' => 150,
            'stock' => $stock,
            'price_mode' => 'auto',
        ]);
    }
}
