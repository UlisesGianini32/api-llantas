<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kit_product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 32);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('external_key', 191)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['kit_product_id', 'status']);
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_kit_reservations');
    }
};
