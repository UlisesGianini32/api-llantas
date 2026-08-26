<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table) {
            $table->foreignId('suggested_brand_group_id')
                ->nullable()
                ->after('brand_group_id')
                ->constrained('meli_brand_groups')
                ->nullOnDelete();
            $table->foreignId('matched_brand_alias_id')
                ->nullable()
                ->after('suggested_brand_group_id')
                ->constrained('meli_brand_aliases')
                ->nullOnDelete();
            $table->json('classification_metadata')->nullable()->after('classification_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table) {
            $table->dropForeign(['matched_brand_alias_id']);
            $table->dropForeign(['suggested_brand_group_id']);
            $table->dropColumn([
                'matched_brand_alias_id',
                'suggested_brand_group_id',
                'classification_metadata',
            ]);
        });
    }
};
