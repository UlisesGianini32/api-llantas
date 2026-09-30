<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->string('supplier_name', 100);
            $table->string('brand', 100)->nullable();
            $table->string('status', 30)->default('DRAFT'); // DRAFT, ORDERED, PARTIAL, RECEIVED, CANCELLED
            $table->foreignId('inventory_location_id')->constrained('inventory_locations');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedInteger('total_items_count')->default(0);
            $table->unsignedInteger('total_units_ordered')->default(0);
            $table->unsignedInteger('total_units_received')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->string('supplier_quote_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('supplier_name');
            $table->index('brand');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('inventory_product_id')->constrained('inventory_products');
            $table->string('sku', 100);
            $table->string('product_name');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->string('status', 30)->default('PENDING'); // PENDING, PARTIAL, RECEIVED, CANCELLED
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
            $table->index('inventory_product_id');
        });

        Schema::create('purchase_order_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('inventory_location_id')->constrained('inventory_locations');
            $table->string('receipt_number', 50)->unique();
            $table->timestamp('received_at');
            $table->unsignedInteger('total_units_received')->default(0);
            $table->string('carrier', 100)->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
        });

        Schema::create('purchase_order_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_receipt_id')->constrained('purchase_order_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->foreignId('inventory_product_id')->constrained('inventory_products');
            $table->unsignedInteger('quantity_received');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receipt_items');
        Schema::dropIfExists('purchase_order_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
