<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_channel_stock_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_channel_link_id')->constrained('inventory_channel_links')->restrictOnDelete();
            $table->foreignId('inventory_product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->string('channel', 32);
            $table->string('account_key')->nullable();
            $table->string('external_listing_id');
            $table->string('external_variant_id')->nullable();
            $table->unsignedInteger('target_quantity');
            $table->unsignedInteger('previous_known_quantity')->nullable();
            $table->string('status', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('triggered_by', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['inventory_channel_link_id', 'status', 'created_at'], 'inventory_channel_stock_sync_history_idx');
            $table->index(['channel', 'account_key', 'external_listing_id'], 'inventory_channel_stock_sync_remote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_channel_stock_syncs');
    }
};
