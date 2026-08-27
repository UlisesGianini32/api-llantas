<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliAccountTaxProfile;
use App\Models\User;
use App\Services\MercadoLibre\PriceManager\MeliSellerTaxSimulationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use UnexpectedValueException;

class MeliSellerTaxSimulationTest extends TestCase
{
    private object $taxProfileMigration;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', true);
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
            $table->timestamps();
        });
        Schema::create('meli_accounts', function (Blueprint $table): void {
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

        $this->taxProfileMigration = require database_path('migrations/2026_08_27_000002_create_meli_account_tax_profiles_table.php');
        $this->taxProfileMigration->up();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->taxProfileMigration->down();
        Schema::dropIfExists('meli_accounts');
        Schema::dropIfExists('users');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_enabled_account_profile_calculates_each_withholding_before_adding_the_total(): void
    {
        $account = $this->account();
        $profile = $this->profile($account);

        $result = app(MeliSellerTaxSimulationService::class)->simulate($account, 699);

        $this->assertTrue($result['available']);
        $this->assertSame('account_tax_profile', $result['source']);
        $this->assertSame(602.59, $result['taxable_base']);
        $this->assertSame(48.21, data_get($result, 'vat.amount'));
        $this->assertSame(15.06, data_get($result, 'income_tax.amount'));
        $this->assertSame(63.27, $result['amount']);
        $this->assertSame($profile->id, data_get($result, 'profile.id'));
        $this->assertSame(16.0, data_get($result, 'profile.vat_included_rate'));
        Http::assertNothingSent();
    }

    public function test_account_without_profile_and_disabled_profile_do_not_report_zero_taxes(): void
    {
        $accountWithoutProfile = $this->account();
        $withoutProfile = app(MeliSellerTaxSimulationService::class)->simulate($accountWithoutProfile, 699);
        $this->assertFalse($withoutProfile['available']);
        $this->assertNull($withoutProfile['source']);
        $this->assertNull($withoutProfile['amount']);

        $disabledAccount = $this->account();
        $this->profile($disabledAccount, ['enabled' => false]);
        $disabled = app(MeliSellerTaxSimulationService::class)->simulate($disabledAccount, 699);
        $this->assertFalse($disabled['available']);
        $this->assertSame('account_tax_profile', $disabled['source']);
        $this->assertNull($disabled['amount']);
        $this->assertStringContainsString('desactivada', $disabled['message']);
        Http::assertNothingSent();
    }

    public function test_invalid_persisted_rate_fails_securely(): void
    {
        $account = $this->account();
        $this->profile($account, ['vat_withholding_rate' => 100.0001]);

        $this->expectException(UnexpectedValueException::class);

        try {
            app(MeliSellerTaxSimulationService::class)->simulate($account, 699);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_user_can_save_only_their_own_valid_account_profile(): void
    {
        $account = $this->account();

        $this->putJson(route('meli-price-manager.tax-profile.update'), [
            'meli_account_id' => $account->id,
            'enabled' => true,
            'vat_included_rate' => 16,
            'vat_withholding_rate' => 8,
            'income_tax_withholding_rate' => 2.5,
            'effective_from' => '2026-08-27',
            'notes' => 'Régimen confirmado por el vendedor',
        ])->assertRedirect();

        $profile = MeliAccountTaxProfile::query()->sole();
        $this->assertSame($account->id, $profile->meli_account_id);
        $this->assertTrue($profile->enabled);
        $this->assertSame('16.0000', $profile->vat_included_rate);
        $this->assertSame('8.0000', $profile->vat_withholding_rate);
        $this->assertSame('2.5000', $profile->income_tax_withholding_rate);

        $foreignAccount = MeliAccount::factory()->for(User::factory()->create())->create();
        $this->put(route('meli-price-manager.tax-profile.update'), [
            'meli_account_id' => $foreignAccount->id,
            'enabled' => true,
            'vat_included_rate' => 16,
            'vat_withholding_rate' => 8,
            'income_tax_withholding_rate' => 2.5,
        ])->assertNotFound();

        $this->assertDatabaseMissing('meli_account_tax_profiles', ['meli_account_id' => $foreignAccount->id]);
        Http::assertNothingSent();
    }

    public function test_enabled_profile_rejects_missing_or_out_of_range_rates(): void
    {
        $account = $this->account();

        $this->putJson(route('meli-price-manager.tax-profile.update'), [
            'meli_account_id' => $account->id,
            'enabled' => true,
            'vat_included_rate' => 16,
            'vat_withholding_rate' => 101,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['vat_withholding_rate', 'income_tax_withholding_rate']);

        $this->assertDatabaseCount('meli_account_tax_profiles', 0);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): MeliAccount
    {
        return MeliAccount::factory()->for($this->user)->create([
            'meli_user_id' => fake()->unique()->numerify('########'),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function profile(MeliAccount $account, array $overrides = []): MeliAccountTaxProfile
    {
        return MeliAccountTaxProfile::query()->create([
            'meli_account_id' => $account->id,
            'enabled' => true,
            'vat_included_rate' => 16,
            'vat_withholding_rate' => 8,
            'income_tax_withholding_rate' => 2.5,
            ...$overrides,
        ]);
    }
}
