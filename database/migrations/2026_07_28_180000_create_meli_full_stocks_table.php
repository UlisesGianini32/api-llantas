<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_full_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->cascadeOnDelete();
            $table->foreignId('meli_publication_id')
                ->nullable()
                ->constrained('meli_publications')
                ->nullOnDelete();

            $table->string('stock_key', 100);
            $table->string('mlm', 30);
            $table->string('variation_id', 40)->nullable();
            $table->string('sku', 190)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('variation_label', 255)->nullable();
            $table->text('thumbnail')->nullable();
            $table->text('permalink')->nullable();

            $table->string('inventory_id', 80)->nullable();
            $table->string('user_product_id', 80)->nullable();
            $table->string('stock_source', 30)->nullable();

            $table->unsignedInteger('full_available_quantity')->default(0);
            $table->unsignedInteger('full_not_available_quantity')->nullable();
            $table->unsignedInteger('full_total_quantity')->nullable();
            $table->json('not_available_detail')->nullable();
            $table->json('raw_stock')->nullable();

            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['meli_account_id', 'stock_key'], 'meli_full_account_stock_unique');
            $table->index(['user_id', 'meli_account_id'], 'meli_full_owner_account_idx');
            $table->index(['meli_account_id', 'mlm'], 'meli_full_account_mlm_idx');
            $table->index('full_available_quantity', 'meli_full_available_idx');
            $table->index('synced_at', 'meli_full_synced_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_full_stocks');
    }
};
