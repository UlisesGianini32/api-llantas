<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table) {
            $table->index(
                ['meli_account_id', 'classification_status', 'brand_group_id'],
                'meli_pm_items_account_class_brand_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table) {
            $table->dropIndex('meli_pm_items_account_class_brand_idx');
        });
    }
};
