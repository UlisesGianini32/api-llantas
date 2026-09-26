<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_product_id')
                ->constrained('inventory_products')
                ->restrictOnDelete();
            $table->foreignId('inventory_location_id')
                ->nullable()
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 32);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('external_key', 191)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['inventory_product_id', 'inventory_location_id', 'status'], 'inv_res_product_location_status_idx');
            $table->index('status');
            $table->index('expires_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
    }
};
