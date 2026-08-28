<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meli_brand_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('meli_brand_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_group_id')->constrained('meli_brand_groups')->restrictOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->enum('match_type', ['exact', 'contains', 'starts_with', 'manual']);
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->unique(['brand_group_id', 'normalized_alias'], 'meli_brand_aliases_group_normalized_uq');
            $table->index('normalized_alias', 'meli_brand_aliases_normalized_idx');
        });

        Schema::create('meli_price_manager_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meli_account_id')->constrained('meli_accounts')->restrictOnDelete();
            $table->string('meli_item_id', 64);
            $table->string('sku', 191)->nullable()->index();
            $table->string('title');
            $table->string('category_id', 64)->nullable();
            $table->string('listing_type_id', 64)->nullable();
            $table->string('catalog_product_id', 128)->nullable();
            $table->string('meli_brand')->nullable();
            $table->string('normalized_brand')->nullable()->index();
            $table->foreignId('brand_group_id')->nullable()->index()->constrained('meli_brand_groups')->nullOnDelete();
            $table->enum('classification_status', ['categorized', 'suggested', 'uncategorized', 'ignored'])
                ->default('uncategorized')->index();
            $table->string('classification_source', 64)->nullable();
            $table->decimal('classification_confidence', 5, 4)->nullable();
            $table->decimal('current_price', 15, 2)->index();
            $table->decimal('original_price', 15, 2)->nullable();
            $table->integer('available_quantity')->nullable();
            $table->unsignedInteger('sold_quantity')->nullable();
            $table->string('currency_id', 8)->nullable();
            $table->string('status', 64)->nullable()->index();
            $table->string('permalink', 2048)->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['meli_account_id', 'meli_item_id'], 'meli_pm_items_account_item_uq');
            $table->index('meli_item_id', 'meli_pm_items_item_id_idx');
        });

        Schema::create('meli_price_change_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meli_account_id')->index()->constrained('meli_accounts')->restrictOnDelete();
            $table->foreignId('brand_group_id')->nullable()->index()->constrained('meli_brand_groups')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->enum('type', ['individual', 'percentage', 'fixed', 'excel'])->index();
            $table->enum('status', ['draft', 'preview', 'processing', 'completed', 'partial', 'failed', 'cancelled'])
                ->default('draft')->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('successful_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->timestamps();

            $table->index('created_at', 'meli_price_batches_created_at_idx');
        });

        Schema::create('meli_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->index()->constrained('meli_price_change_batches')->nullOnDelete();
            $table->foreignId('price_manager_item_id')->index()->constrained('meli_price_manager_items')->restrictOnDelete();
            $table->string('meli_item_id', 64)->index();
            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->decimal('selling_fee', 15, 2)->nullable();
            $table->decimal('shipping_cost', 15, 2)->nullable();
            $table->decimal('tax_withholding', 15, 2)->nullable();
            $table->decimal('other_charges', 15, 2)->nullable();
            $table->decimal('estimated_net', 15, 2)->nullable();
            $table->enum('status', ['pending', 'processing', 'success', 'failed', 'cancelled'])
                ->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->foreignId('changed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meli_price_changes');
        Schema::dropIfExists('meli_price_change_batches');
        Schema::dropIfExists('meli_price_manager_items');
        Schema::dropIfExists('meli_brand_aliases');
        Schema::dropIfExists('meli_brand_groups');
    }
};
