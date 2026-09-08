<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
            $table->date('starts_on')->nullable()->after('discount_percentage');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        // Legacy rows have no explicit period or selected publications. They must
        // remain visible for migration purposes, but must never execute implicitly.
        DB::table('meli_beauty_scheduled_discounts')
            ->whereNull('starts_on')
            ->orWhereNull('ends_on')
            ->update(['active' => false]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX meli_beauty_discounts_account_brand_uq');
            DB::statement('CREATE INDEX mbsd_account_brand_idx ON meli_beauty_scheduled_discounts (meli_account_id, brand_group_id)');
        } else {
            Schema::table('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
                $table->dropUnique('meli_beauty_discounts_account_brand_uq');
                $table->index(['meli_account_id', 'brand_group_id'], 'mbsd_account_brand_idx');
            });
        }

        Schema::create('meli_beauty_scheduled_discount_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_beauty_scheduled_discount_id');
            $table->foreignId('price_manager_item_id');
            $table->decimal('discount_percentage', 5, 2);
            $table->timestamps();

            $table->foreign('meli_beauty_scheduled_discount_id', 'mbsdi_discount_fk')
                ->references('id')->on('meli_beauty_scheduled_discounts')->cascadeOnDelete();
            $table->foreign('price_manager_item_id', 'mbsdi_item_fk')
                ->references('id')->on('meli_price_manager_items')->restrictOnDelete();
            $table->unique(
                ['meli_beauty_scheduled_discount_id', 'price_manager_item_id'],
                'mbsdi_discount_item_uq'
            );
            $table->index('price_manager_item_id', 'mbsdi_item_idx');
        });
    }

    public function down(): void
    {
        $hasDuplicateAccountBrands = DB::table('meli_beauty_scheduled_discounts')
            ->select(['meli_account_id', 'brand_group_id'])
            ->groupBy('meli_account_id', 'brand_group_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateAccountBrands) {
            throw new RuntimeException(
                'No se puede revertir la migración de promociones Beauty: existen varias promociones para la misma cuenta y marca.'
            );
        }

        // Recreate the unique index before any destructive operation. Besides
        // failing safely, this closes the window for a concurrent duplicate.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX meli_beauty_discounts_account_brand_uq ON meli_beauty_scheduled_discounts (meli_account_id, brand_group_id)');
        } else {
            Schema::table('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
                $table->unique(['meli_account_id', 'brand_group_id'], 'meli_beauty_discounts_account_brand_uq');
            });
        }

        Schema::dropIfExists('meli_beauty_scheduled_discount_items');

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX mbsd_account_brand_idx');
            Schema::table('meli_beauty_scheduled_discounts', fn (Blueprint $table) => $table->dropColumn(['starts_on', 'ends_on']));
        } else {
            Schema::table('meli_beauty_scheduled_discounts', function (Blueprint $table): void {
                $table->dropIndex('mbsd_account_brand_idx');
                $table->dropColumn(['starts_on', 'ends_on']);
            });
        }

        // The previous active value of legacy rows was intentionally discarded
        // by up() and cannot be inferred safely during rollback.
    }
};
