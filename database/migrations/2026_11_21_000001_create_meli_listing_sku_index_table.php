<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meli_listing_sku_index')) {
            return;
        }

        Schema::create('meli_listing_sku_index', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 32); // 'meli_publications' | 'meli_price_manager_items'
            $table->unsignedBigInteger('source_row_id');
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('mlm', 40);
            $table->string('variation_id', 64)->nullable();
            $table->string('variation_key', 64)->default('');
            $table->string('sku', 191);
            $table->string('sku_normalized', 191);
            $table->string('listing_status', 32)->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('sku_normalized', 'mlsi_sku_norm_idx');
            $table->index(['source_table', 'source_row_id'], 'mlsi_source_row_idx');
            $table->index('mlm', 'mlsi_mlm_idx');
            $table->unique(
                ['source_table', 'source_row_id', 'variation_key', 'sku_normalized'],
                'mlsi_unique_source_var_sku'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_listing_sku_index');
    }
};
