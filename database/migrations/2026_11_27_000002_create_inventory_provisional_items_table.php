<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_provisional_items')) {
            return;
        }

        Schema::create('inventory_provisional_items', function (Blueprint $table): void {
            $table->id();
            $table->string('provisional_code', 50);
            $table->string('barcode', 100)->nullable();
            $table->string('known_sku', 100)->nullable();
            $table->string('name', 255);
            $table->string('brand', 100)->nullable();
            $table->string('line', 100)->nullable();
            $table->string('tone', 50)->nullable();
            $table->string('presentation', 100)->nullable();
            $table->string('unit_of_measure', 32)->default('UNIT');
            $table->unsignedInteger('default_units_per_container')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('PENDING_LINK');
            $table->foreignId('linked_inventory_product_id')
                ->nullable()
                ->constrained('inventory_products')
                ->restrictOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('link_notes')->nullable();
            $table->unsignedBigInteger('merged_into_provisional_id')->nullable();
            $table->unsignedBigInteger('canonical_provisional_id')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->foreignId('merged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('merge_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('provisional_code', 'inv_prov_items_code_uq');
            $table->index('barcode', 'inv_prov_items_barcode_idx');
            $table->index('known_sku', 'inv_prov_items_sku_idx');
            $table->index(['brand', 'tone'], 'inv_prov_items_brand_tone_idx');
            $table->index('status', 'inv_prov_items_status_idx');
            $table->index('linked_inventory_product_id', 'inv_prov_items_linked_prod_idx');
            $table->index('canonical_provisional_id', 'inv_prov_items_canonical_idx');

            $table->foreign('merged_into_provisional_id', 'inv_prov_items_merged_fk')
                ->references('id')
                ->on('inventory_provisional_items')
                ->restrictOnDelete();
            $table->foreign('canonical_provisional_id', 'inv_prov_items_canonical_fk')
                ->references('id')
                ->on('inventory_provisional_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_provisional_items');
    }
};
