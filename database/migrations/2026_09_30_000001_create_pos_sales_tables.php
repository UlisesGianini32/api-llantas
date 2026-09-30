<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('sale_number', 50)->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('inventory_location_id')->constrained('inventory_locations');
            $table->string('customer_name')->default('Público en general');
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_type', 30)->default('public'); // public, stylist
            $table->string('payment_method', 30)->default('cash'); // cash, card, transfer, mixed
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->default(0);
            $table->string('status', 30)->default('completed'); // completed, cancelled
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
            $table->index('inventory_location_id');
        });

        Schema::create('pos_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('inventory_product_id')->constrained('inventory_products');
            $table->string('product_type', 20)->default('SIMPLE'); // SIMPLE, KIT
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('product_name');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            $table->index('inventory_product_id');
            $table->index('sku');
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
    }
};
