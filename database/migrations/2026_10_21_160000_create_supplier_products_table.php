<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_products')) {
            Schema::create('supplier_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->string('supplier_name', 150);
                $table->foreignId('inventory_product_id')->constrained('inventory_products')->cascadeOnDelete();
                $table->string('supplier_sku', 100);
                $table->string('supplier_product_name', 255)->nullable();
                $table->decimal('supplier_cost', 12, 2)->nullable();
                $table->boolean('is_primary')->default(true);
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->unique(['supplier_name', 'inventory_product_id', 'supplier_sku'], 'sup_prod_sku_unique');
                $table->index(['supplier_name', 'supplier_sku'], 'sup_name_sku_idx');
                $table->index('inventory_product_id');
                $table->index('supplier_id');
            });
        }

        if (Schema::hasTable('purchase_order_items') && ! Schema::hasColumn('purchase_order_items', 'supplier_sku')) {
            Schema::table('purchase_order_items', function (Blueprint $table): void {
                $table->string('supplier_sku', 100)->nullable()->after('sku');
                $table->index('supplier_sku');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_order_items') && Schema::hasColumn('purchase_order_items', 'supplier_sku')) {
            Schema::table('purchase_order_items', function (Blueprint $table): void {
                $table->dropIndex(['supplier_sku']);
                $table->dropColumn('supplier_sku');
            });
        }

        Schema::dropIfExists('supplier_products');
    }
};
