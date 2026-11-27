<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_dispatch_destinations')) {
            return;
        }

        Schema::create('inventory_dispatch_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('destination_type', 32)->default('SALON');
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('default_operation_type', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('code', 'inv_disp_dest_code_uq');
            $table->index(['is_active', 'destination_type'], 'inv_disp_dest_active_type_idx');
            $table->index('customer_id', 'inv_disp_dest_customer_idx');

            if (Schema::hasTable('customers')) {
                $table->foreign('customer_id', 'inv_disp_dest_customer_fk')
                    ->references('id')
                    ->on('customers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_dispatch_destinations');
    }
};
