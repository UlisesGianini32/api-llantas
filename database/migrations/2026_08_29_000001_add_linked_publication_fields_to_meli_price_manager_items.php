<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table): void {
            $table->string('user_product_id', 128)->nullable()->after('catalog_product_id');
            $table->string('inventory_id', 128)->nullable()->after('user_product_id');
            $table->boolean('catalog_listing')->default(false)->after('inventory_id');
            $table->string('price_sync_status', 32)->nullable()->after('catalog_listing');
            $table->json('price_relation_ids')->nullable()->after('price_sync_status');
            $table->timestamp('linked_synced_at')->nullable()->after('price_relation_ids');

            $table->index(['meli_account_id', 'inventory_id'], 'meli_pm_account_inventory_idx');
            $table->index(['meli_account_id', 'user_product_id'], 'meli_pm_account_user_product_idx');
        });
    }

    public function down(): void
    {
        Schema::table('meli_price_manager_items', function (Blueprint $table): void {
            $table->dropIndex('meli_pm_account_inventory_idx');
            $table->dropIndex('meli_pm_account_user_product_idx');
            $table->dropColumn([
                'user_product_id', 'inventory_id', 'catalog_listing', 'price_sync_status',
                'price_relation_ids', 'linked_synced_at',
            ]);
        });
    }
};
