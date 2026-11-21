<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meli_listing_sku_index')) {
            Schema::create('meli_listing_sku_index', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('generation')->default(1);
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

                $table->index(['generation', 'sku_normalized'], 'mlsi_gen_sku_idx');
                $table->index(['generation', 'source_table', 'source_row_id'], 'mlsi_gen_source_row_idx');
                $table->index(['generation', 'mlm'], 'mlsi_gen_mlm_idx');
                $table->unique(
                    ['generation', 'source_table', 'source_row_id', 'variation_key', 'sku_normalized'],
                    'mlsi_unique_gen_source_var_sku'
                );
            });
        }

        if (! Schema::hasTable('meli_listing_sku_index_state')) {
            Schema::create('meli_listing_sku_index_state', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->string('status', 32)->default('uninitialized'); // uninitialized | building | ready | failed
                $table->unsignedBigInteger('active_generation')->default(0);
                $table->unsignedBigInteger('building_generation')->nullable();
                $table->string('last_rebuild_status', 32)->nullable(); // running | succeeded | failed
                $table->timestamp('build_started_at')->nullable();
                $table->timestamp('build_heartbeat_at')->nullable();
                $table->timestamp('last_completed_at')->nullable();
                $table->timestamp('last_reconciled_at')->nullable();
                $table->timestamp('last_failed_at')->nullable();
                $table->unsignedBigInteger('active_rows_count')->default(0);
                $table->unsignedBigInteger('publications_scanned')->default(0);
                $table->unsignedBigInteger('price_manager_scanned')->default(0);
                $table->unsignedInteger('failed_rebuilds_count')->default(0);
                $table->text('last_error')->nullable();
                $table->unsignedInteger('incremental_sync_errors_count')->default(0);
                $table->text('last_incremental_error')->nullable();
                $table->timestamp('last_incremental_error_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_listing_sku_index_state');
        Schema::dropIfExists('meli_listing_sku_index');
    }
};
