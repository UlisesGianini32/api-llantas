<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meli_full_stocks')) {
            Schema::table('meli_full_stocks', function (Blueprint $table): void {
                if (! Schema::hasColumn('meli_full_stocks', 'weight_kg')) {
                    $table->decimal('weight_kg', 8, 3)->nullable()->after('full_total_quantity');
                }
                if (! Schema::hasColumn('meli_full_stocks', 'requires_meli_labeling')) {
                    $table->boolean('requires_meli_labeling')->default(false)->after('weight_kg');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meli_full_stocks')) {
            Schema::table('meli_full_stocks', function (Blueprint $table): void {
                if (Schema::hasColumn('meli_full_stocks', 'requires_meli_labeling')) {
                    $table->dropColumn('requires_meli_labeling');
                }
                if (Schema::hasColumn('meli_full_stocks', 'weight_kg')) {
                    $table->dropColumn('weight_kg');
                }
            });
        }
    }
};
