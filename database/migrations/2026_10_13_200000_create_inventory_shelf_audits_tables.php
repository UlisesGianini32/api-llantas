<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_shelf_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('audit_number', 50)->unique();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations');
            $table->foreignId('user_id')->constrained('users');
            $table->string('status', 30)->default('IN_PROGRESS'); // IN_PROGRESS, APPLIED, CANCELLED
            $table->unsignedInteger('total_expected_products')->default(0);
            $table->unsignedInteger('total_counted_products')->default(0);
            $table->integer('total_system_units')->default(0);
            $table->integer('total_counted_units')->default(0);
            $table->integer('total_difference_units')->default(0);
            $table->unsignedInteger('matched_items_count')->default(0);
            $table->unsignedInteger('surplus_items_count')->default(0);
            $table->unsignedInteger('missing_items_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['inventory_location_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('inventory_shelf_audit_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_shelf_audit_id')->constrained('inventory_shelf_audits')->cascadeOnDelete();
            $table->foreignId('inventory_product_id')->constrained('inventory_products');
            $table->integer('system_quantity')->default(0);
            $table->integer('counted_quantity')->default(0);
            $table->integer('difference')->default(0);
            $table->boolean('is_assigned_location')->default(true);
            $table->string('notes')->nullable();
            $table->boolean('adjusted')->default(false);
            $table->timestamps();

            $table->index(['inventory_shelf_audit_id', 'inventory_product_id'], 'isa_item_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_shelf_audit_items');
        Schema::dropIfExists('inventory_shelf_audits');
    }
};
