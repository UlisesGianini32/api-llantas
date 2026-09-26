<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_product_id')
                ->constrained('inventory_products')
                ->restrictOnDelete();
            $table->foreignId('inventory_location_id')
                ->constrained('inventory_locations')
                ->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->string('external_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(['inventory_product_id', 'inventory_location_id'], 'inv_mov_product_location_idx');
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
